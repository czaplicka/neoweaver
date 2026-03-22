<?php
/**
 * Neoweaver_Agents_Creator
 *
 * Orchestrates the full character-creation pipeline for a new Field Agent.
 * Covers the path from validated POST data → Supabase rows → action hook.
 *
 * ARCHITECTURAL RULES (do not violate):
 *  - One Agent is permanently bound to exactly one Node at creation time.
 *    This binding CANNOT be changed afterwards (1 Agent = 1 World).
 *  - Starting packages (deck + equipment) must be applied exactly once;
 *    an is_applied / claimed_at guard prevents double-application.
 *  - Entropy for the target Node must NOT be modified during character
 *    creation; new agents enter the world as-is.
 *  - Never insert STATUS_DEAD or any negative Echo tags at creation.
 *  - All table names use the cyber_ prefix.
 *  - After a successful DB write the 'neoweaver_agent_created' action
 *    hook is fired so Make.com webhook dispatchers can react.
 *
 * HTTP layer: identical helper pattern to Neoweaver_Agents_Repository
 * (tw_supabase_url(), tw_supabase_anon_key(), wp_remote_request()).
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_Creator {

	// -------------------------------------------------------------------------
	// Internal HTTP helpers  (mirror of Neoweaver_Agents_Repository)
	// -------------------------------------------------------------------------

	/**
	 * Standard Supabase REST headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		$key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
		return [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Build a full Supabase REST table URL with optional query args.
	 *
	 * @param string $table  Full table name (e.g. 'cyber_characters').
	 * @param array  $args   Query-string parameters.
	 * @return string
	 */
	private function table_url( string $table, array $args = [] ): string {
		$base = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$url  = trailingslashit( $base ) . 'rest/v1/' . $table;
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Execute a GET and return decoded JSON, or an empty array on failure.
	 *
	 * @param string $url
	 * @return array
	 */
	private function get_json( string $url ): array {
		$res = wp_remote_get( $url, [ 'headers' => $this->headers(), 'timeout' => 15 ] );
		if ( is_wp_error( $res ) ) {
			error_log( 'TW Creator GET error [' . $url . ']: ' . $res->get_error_message() );
			return [];
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			error_log( 'TW Creator GET HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $res ) );
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Execute a POST with a JSON body and return the decoded response.
	 * Uses `Prefer: return=representation` so Supabase echoes the new row.
	 *
	 * Returns the first row of the response array on success, or null on failure.
	 *
	 * @param string $url
	 * @param array  $body  Data to JSON-encode and send.
	 * @return array|null
	 */
	private function post_json( string $url, array $body ): ?array {
		$headers           = $this->headers();
		$headers['Prefer'] = 'return=representation';

		$res = wp_remote_post( $url, [
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'TW Creator POST error [' . $url . ']: ' . $res->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $res );
		// Supabase returns 201 for successful INSERT with return=representation.
		if ( $code !== 201 ) {
			error_log( 'TW Creator POST HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $res ) );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data[0]; // Supabase wraps even single rows in an array.
		}
		return null;
	}

	/**
	 * Sanitize a Node / World ID for safe use in a Supabase REST filter.
	 *
	 * cyber_worlds.id is a UUID string. Using intval() on a UUID collapses it
	 * to 0, making every Node-existence and Entropy query return empty results
	 * (the guards become dead code) and writing 0 as a FK breaks the insert.
	 *
	 * This helper strips everything except alphanumerics and hyphens, which is
	 * safe for both UUID v4 strings and any legacy integer IDs.
	 *
	 * @param  mixed $raw_id  Raw ID value from form data.
	 * @return string         Sanitized ID string, or '' if nothing valid remains.
	 */
	private function sanitize_id( $raw_id ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw_id );
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Pre-flight checks before any DB write.
	 *
	 * Verifies:
	 *  1. Required fields present (character_name, race, class).
	 *  2. Target Node exists in cyber_worlds (currently the system uses a
	 *     single active Node; node_id is optional and defaults to the
	 *     first available world when omitted).
	 *  3. Node Entropy < 100 (Hard Reset guard).
	 *  4. The WP user has no living agent in the target Node (1 Agent = 1 Node).
	 *
	 * @param array $data        Sanitised creation data.
	 * @param int   $wp_user_id  Current WordPress user ID.
	 * @return true|WP_Error
	 */
	public function validate( array $data, int $wp_user_id ) {
		// 1. Required fields.
		if ( empty( $data['character_name'] ) ) {
			return new WP_Error( 'missing_name', 'Character name is required.' );
		}
		if ( empty( $data['race'] ) ) {
			return new WP_Error( 'missing_race', 'Race selection is required.' );
		}
		if ( empty( $data['class'] ) ) {
			return new WP_Error( 'missing_class', 'Class selection is required.' );
		}

		// 2 & 3. Node existence + Entropy guard (only when a node_id is provided).
		if ( ! empty( $data['node_id'] ) ) {
			// BUG-FIX: cyber_worlds.id is a UUID. The previous code used
			// intval( $data['node_id'] ) which collapses any UUID string to 0,
			// making the Supabase query always return empty — the Node-existence
			// check and the Entropy guard were therefore never enforced.
			// Use sanitize_id() instead: strips non-alphanumeric/hyphen chars
			// while preserving the UUID string intact.
			$safe_node_id = $this->sanitize_id( $data['node_id'] );

			$node_url = $this->table_url( 'cyber_worlds', [
				'id'    => 'eq.' . $safe_node_id,
				'limit' => '1',
			] );
			$nodes = $this->get_json( $node_url );
			if ( empty( $nodes ) ) {
				return new WP_Error( 'node_not_found', 'Target Node does not exist.' );
			}
			$entropy = (int) ( $nodes[0]['entropy'] ?? 0 );
			if ( $entropy >= 100 ) {
				return new WP_Error( 'node_hard_reset', 'Target Node has undergone Hard Reset. Choose a different world.' );
			}

			// 4. Duplicate living agent guard.
			// BUG-FIX: same UUID-vs-intval issue applies to the world_id filter
			// here — fixed by reusing $safe_node_id from above.
			$dup_url = $this->table_url( 'cyber_characters', [
				'wp_user_id' => 'eq.' . $wp_user_id,
				'world_id'   => 'eq.' . $safe_node_id,
				'status'     => 'neq.STATUS_DEAD',
				'limit'      => '1',
			] );
			$existing = $this->get_json( $dup_url );
			if ( ! empty( $existing ) ) {
				return new WP_Error( 'duplicate_agent', 'You already have a living Field Agent in this Node.' );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Creation pipeline steps
	// -------------------------------------------------------------------------

	/**
	 * Insert the base cyber_characters row and return the new agent ID.
	 *
	 * Writes: character_name, pronouns, race_id (from 'race' key),
	 * class_id (from 'class' key), world_id, wp_user_id, backstory,
	 * and the four base attributes (body, reflex, mind, spirit).
	 * avatar_url is written separately after upload; leave null here.
	 *
	 * Returns the new character's UUID/ID string on success, or null on failure.
	 *
	 * @param array $data        Sanitised creation data.
	 * @param int   $wp_user_id
	 * @return string|null  New character ID, or null on failure.
	 */
	public function insert_character_row( array $data, int $wp_user_id ): ?string {
		// BUG-FIX: cyber_characters.world_id is a UUID FK referencing
		// cyber_worlds.id. The previous code used intval( $data['node_id'] )
		// which converts any UUID string to 0, causing the FK constraint to
		// reject the insert or silently write a wrong value.
		// Use sanitize_id() to preserve the UUID string as-is.
		$world_id = null;
		if ( ! empty( $data['node_id'] ) ) {
			$sanitized = $this->sanitize_id( $data['node_id'] );
			// If sanitization produces a non-empty result it's a valid ID.
			$world_id = ( $sanitized !== '' ) ? $sanitized : null;
		}

		$payload = [
			'wp_user_id'     => $wp_user_id,
			'name'           => sanitize_text_field( $data['character_name'] ),
			'pronouns'       => sanitize_text_field( $data['pronouns'] ?? '' ),
			'race_id'        => sanitize_text_field( $data['race'] ),
			'class_id'       => sanitize_text_field( $data['class'] ),
			'world_id'       => $world_id,
			'backstory'      => sanitize_textarea_field( $data['backstory'] ?? '' ),
			'attr_body'      => intval( $data['attr_body']   ?? 3 ),
			'attr_reflex'    => intval( $data['attr_reflex'] ?? 3 ),
			'attr_mind'      => intval( $data['attr_mind']   ?? 3 ),
			'attr_spirit'    => intval( $data['attr_spirit'] ?? 3 ),
			'avatar_url'     => null,
			'status'         => 'ACTIVE',
		];

		$url = $this->table_url( 'cyber_characters' );
		$row = $this->post_json( $url, $payload );

		if ( ! $row || empty( $row['id'] ) ) {
			error_log( 'TW Creator: insert_character_row failed for wp_user_id=' . $wp_user_id );
			return null;
		}

		return (string) $row['id'];
	}

	/**
	 * Create the biometric / HUD state row in cyber_state_of_the_campaign.
	 *
	 * All biometrics start at protocol-mandated values:
	 * Satiety = 100, Hydration = 100, Rest = 100, Sync_rate = 100.
	 * HP and MP are left at defaults (to be overridden by class triggers
	 * in Supabase if DB-level triggers exist).
	 *
	 * @param string $agent_id  Newly created character ID (UUID or int string).
	 * @return void
	 */
	public function initialise_hud_state( string $agent_id ): void {
		$payload = [
			'character_id' => $agent_id,
			'hp'           => 10,    // Default; class DB triggers may override.
			'mp'           => 10,    // Default; class DB triggers may override.
			'xp'           => 0,
			'satiety'      => 100,
			'hydration'    => 100,
			'rest'         => 100,
			'sync_rate'    => 100,
			'time_of_day'  => 'morning',
		];

		$url = $this->table_url( 'cyber_state_of_the_campaign' );
		$row = $this->post_json( $url, $payload );

		if ( ! $row ) {
			error_log( 'TW Creator: initialise_hud_state failed for agent_id=' . $agent_id );
		}
	}

	/**
	 * Apply the starting deck and equipment package.
	 *
	 * Looks up cyber_starting_packages for the agent's equipment_pack_id
	 * (passed in $data from the form's 'equipment' field), then calls the
	 * Supabase RPC `apply_starting_package` to let the DB handle the bulk
	 * insert atomically (cards → cyber_inventory_cards, gear → cyber_inventory).
	 *
	 * The RPC is idempotent: Supabase marks the package claimed on first call
	 * and no-ops on subsequent calls for the same character.
	 *
	 * @param string $agent_id
	 * @return void
	 */
	public function apply_starting_package( string $agent_id ): void {
		// TODO: call Supabase RPC apply_starting_package( character_id, package_id )
		//       when the RPC is defined on the DB side.
		//       For now, log a placeholder so the pipeline doesn't silent-fail.
		error_log( 'TW Creator: apply_starting_package — RPC not yet implemented for agent_id=' . $agent_id );
	}

	/**
	 * Seed the initial Echo tags for the new agent from race + class defaults.
	 *
	 * Reads default_tags (JSON array) from cyber_races and cyber_classes and
	 * inserts one row per tag into cyber_character_complete_tags.
	 * Tags are stored WITHOUT the leading '#' (protocol convention).
	 * STATUS_DEAD and any negative-status tags are never inserted here.
	 *
	 * @param string $agent_id
	 * @return void
	 */
	public function seed_echo_tags( string $agent_id ): void {
		// TODO: implement once cyber_races.default_tags and
		//       cyber_classes.default_tags columns are confirmed in Supabase.
		//       Pattern: GET race row → decode JSON tags → loop POST to
		//       cyber_character_complete_tags with character_id + tag_name.
		error_log( 'TW Creator: seed_echo_tags — pending column confirmation for agent_id=' . $agent_id );
	}

	// -------------------------------------------------------------------------
	// Orchestration
	// -------------------------------------------------------------------------

	/**
	 * Run the full character creation pipeline.
	 *
	 * Steps (in order):
	 *  1. Sanitise raw data.
	 *  2. validate()            — bail on WP_Error.
	 *  3. insert_character_row() — bail on null, capture $agent_id.
	 *  4. initialise_hud_state()
	 *  5. apply_starting_package()
	 *  6. seed_echo_tags()
	 *  7. Fire 'neoweaver_agent_created' action hook.
	 *  8. Return $agent_id.
	 *
	 * Returns the new agent ID string on success, or null on failure.
	 * Callers should treat null as a hard failure and surface a user-facing error.
	 *
	 * @param array $data        Raw POST data from the creation form.
	 * @param int   $wp_user_id  Current WordPress user ID.
	 * @return string|null  New agent ID, or null.
	 */
	public function create( array $data, int $wp_user_id ): ?string {
		// 1. Validate (returns true or WP_Error).
		$valid = $this->validate( $data, $wp_user_id );
		if ( is_wp_error( $valid ) ) {
			error_log( 'TW Creator: validate() failed — ' . $valid->get_error_message() );
			return null;
		}

		// 2. Insert base row.
		$agent_id = $this->insert_character_row( $data, $wp_user_id );
		if ( ! $agent_id ) {
			return null;
		}

		// 3. Biometrics (non-fatal: log but continue if HUD row fails).
		$this->initialise_hud_state( $agent_id );

		// 4. Starting package (non-fatal for now; RPC not yet implemented).
		$this->apply_starting_package( $agent_id );

		// 5. Echo tags (non-fatal; column confirmation pending).
		$this->seed_echo_tags( $agent_id );

		// 6. Fire action hook for Make.com webhook dispatcher and any
		//    other listeners (e.g. avatar upload handler).
		do_action( 'neoweaver_agent_created', $agent_id, $wp_user_id, $data['node_id'] ?? null );

		return $agent_id;
	}

	// -------------------------------------------------------------------------
	// Data Ghost flow (second agent after death)
	// -------------------------------------------------------------------------

	/**
	 * Create a new Field Agent in the same Node as a dead predecessor,
	 * then link the predecessor's logs as a Data Ghost record.
	 *
	 * The new agent starts completely fresh (no inherited gear, Echo tags,
	 * wanted status or faction standing). The predecessor MUST be STATUS_DEAD;
	 * the method verifies this and refuses to proceed otherwise.
	 *
	 * @param array  $data            Raw POST creation data for the new agent.
	 * @param int    $wp_user_id      Current WordPress user ID.
	 * @param string $predecessor_id  Character ID of the dead agent.
	 * @return string|null  New agent ID, or null on failure.
	 */
	public function create_with_data_ghost( array $data, int $wp_user_id, string $predecessor_id ): ?string {
		// 1. Verify predecessor is actually dead.
		$pred_url  = $this->table_url( 'cyber_characters', [
			'id'    => 'eq.' . $predecessor_id,
			'limit' => '1',
		] );
		$pred_rows = $this->get_json( $pred_url );

		if ( empty( $pred_rows ) ) {
			error_log( 'TW Creator: Data Ghost — predecessor not found: ' . $predecessor_id );
			return null;
		}

		$predecessor = $pred_rows[0];
		if ( ( $predecessor['status'] ?? '' ) !== 'STATUS_DEAD' ) {
			error_log( 'TW Creator: Data Ghost — predecessor is not STATUS_DEAD: ' . $predecessor_id );
			return null;
		}

		// 2. Create the new agent in the same Node.
		if ( empty( $data['node_id'] ) && ! empty( $predecessor['world_id'] ) ) {
			$data['node_id'] = $predecessor['world_id'];
		}

		$new_agent_id = $this->create( $data, $wp_user_id );
		if ( ! $new_agent_id ) {
			return null;
		}

		// 3. Link predecessor logs as a Data Ghost record.
		$ghost_payload = [
			'new_character_id'  => $new_agent_id,
			'dead_character_id' => $predecessor_id,
			'wp_user_id'        => $wp_user_id,
			'node_id'           => $predecessor['world_id'] ?? null,
		];

		$ghost_url = $this->table_url( 'cyber_data_ghosts' );
		$ghost_row = $this->post_json( $ghost_url, $ghost_payload );

		if ( ! $ghost_row ) {
			// Non-fatal: the new agent exists, but the ghost link failed.
			// Log for Make.com / manual repair, but don't roll back the agent.
			error_log( 'TW Creator: Data Ghost link insert failed for new_agent_id=' . $new_agent_id );
		}

		return $new_agent_id;
	}
}
