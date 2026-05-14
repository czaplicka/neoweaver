<?php
/**
 * Neoweaver_Agents_Repository
 *
 * Low-level data-access layer for Field Agents stored in Supabase.
 * All reads go through the Supabase REST API using the helpers
 * tw_supabase_url() and tw_supabase_anon_key() defined in wp-config.
 *
 * ARCHITECTURAL RULES (do not violate):
 *  - This class NEVER mutates game state. Pure read queries only.
 *    Entropy, Echo, HP, STATUS flags etc. must be changed via the
 *    Make.com tag-driven pipeline, never by direct REST writes here.
 *  - Never resurrect an Agent whose status is STATUS_DEAD.
 *  - Never allow an Agent to be bound to a Node it was not created in
 *    (1 Agent = 1 Node invariant).
 *  - All table names use the cyber_ prefix (e.g. cyber_characters).
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_Repository {

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the standard Supabase REST request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		$anon_key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
		return [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Build a full Supabase REST endpoint URL for a given table + query args.
	 *
	 * @param string               $table  Full table name (e.g. 'cyber_characters').
	 * @param array<string,string> $args   Query-string parameters.
	 * @return string
	 */
	private function table_url( string $table, array $args = [] ): string {
		$base = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$url  = trailingslashit( $base ) . 'rest/v1/' . $table;
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Execute a GET request and return the decoded JSON body as an array.
	 * Returns an empty array and logs on any error or non-200 response.
	 *
	 * @param string $url
	 * @return array
	 */
	private function get_json( string $url ): array {
		$response = wp_remote_get( $url, [ 'headers' => $this->headers(), 'timeout' => 15 ] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW Supabase error [' . $url . ']: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			error_log( 'TW Supabase HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $response ) );
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Build a Supabase `in.(...)` filter value that is safe for both integer
	 * and UUID primary keys.
	 *
	 * BUG-FIX 10: The original code ran every ID through intval(), which
	 * converts UUID strings (e.g. "3f2504e0-4f89-...") to 0, collapsing the
	 * entire filter to in.(0,0,0,...) and returning wrong results.
	 * We now keep IDs as strings and only sanitize them — no intval().
	 *
	 * @param  array $ids  Raw ID values from Supabase rows (int or UUID string).
	 * @return string      e.g. "in.(1,2,3)" or "in.(uuid1,uuid2)"
	 */
	private function in_filter( array $ids ): string {
		$safe = array_map(
			function ( $id ) {
				// Strip everything except alphanumeric characters and hyphens
				// (hyphens are part of UUID v4 format).
				return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
			},
			$ids
		);
		return 'in.(' . implode( ',', array_filter( $safe ) ) . ')';
	}

	/**
	 * Sanitize a single ID (UUID or integer) for safe use in a Supabase filter.
	 * Strips everything except alphanumerics and hyphens.
	 *
	 * @param  mixed $id
	 * @return string
	 */
	private function sanitize_id( $id ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
	}

	// -------------------------------------------------------------------------
	// Primary roster query (used by the characters list shortcode)
	// -------------------------------------------------------------------------

	/**
	 * Fetch all Field Agents for a WordPress user, with tags and inventory
	 * already attached to each character row.
	 *
	 * This is the single method the character-list shortcode needs. It fires
	 * three Supabase queries:
	 *   1. cyber_characters (with class, race and campaign joins)
	 *   2. cyber_character_complete_tags  (all tags for the returned agent IDs)
	 *   3. v_cyber_character_items        (inventory view for those agent IDs)
	 *
	 * Tags and inventory are keyed by character_id and merged into each
	 * character array under the keys 'tags' and 'inventory' respectively.
	 *
	 * Returns an empty array when the user has no agents or any query fails.
	 *
	 * @param int $wp_user_id  WordPress user ID (get_current_user_id()).
	 * @return array           Enriched character rows; each has 'tags' and 'inventory'.
	 */
	public function get_for_wp_user( int $wp_user_id ): array {

		// ------------------------------------------------------------------
		// 1. Fetch characters
		// ------------------------------------------------------------------
		$chars_url = $this->table_url( 'cyber_characters', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'select'     => '*,cyber_classes(name),cyber_races(name),cyber_campaign_characters(cyber_campaign(name,cyber_campaign_worlds(cyber_worlds(name))))',
			'order' => 'created_at.desc',
		] );

		$characters = $this->get_json( $chars_url );

		if ( empty( $characters ) ) {
			return [];
		}

		// ------------------------------------------------------------------
		// 2. Batch-fetch tags for all returned character IDs
		//    BUG-FIX 10: use in_filter() instead of array_map('intval', ...)
		//    so UUID primary keys are not coerced to 0.
		// ------------------------------------------------------------------
		$char_ids  = wp_list_pluck( $characters, 'id' );
		$ids_query = $this->in_filter( $char_ids );

		$tags_url = $this->table_url( 'cyber_character_complete_tags', [
			'character_id' => $ids_query,
		] );
		$all_tags = $this->get_json( $tags_url );

		// ------------------------------------------------------------------
		// 3. Batch-fetch inventory for all returned character IDs
		// ------------------------------------------------------------------
		$inv_url   = $this->table_url( 'v_cyber_character_items', [
			'character_id' => $ids_query,
		] );
		$all_items = $this->get_json( $inv_url );

		// ------------------------------------------------------------------
		// 4. Attach tags and inventory to each character row
		//    Compare as strings so both int and UUID keys match correctly.
		// ------------------------------------------------------------------
		foreach ( $characters as &$c ) {
			$cid = (string) $c['id'];

			$c['tags'] = array_values( array_filter(
				$all_tags,
				function ( $t ) use ( $cid ) {
					return isset( $t['character_id'] ) && (string) $t['character_id'] === $cid;
				}
			) );

			$c['inventory'] = array_values( array_filter(
				$all_items,
				function ( $i ) use ( $cid ) {
					return isset( $i['character_id'] ) && (string) $i['character_id'] === $cid;
				}
			) );
		}
		unset( $c );

		return $characters;
	}

	// -------------------------------------------------------------------------
	// Single-agent queries
	// -------------------------------------------------------------------------

	/**
	 * Fetch a single Field Agent record from cyber_characters by its primary key.
	 *
	 * Accepts both integer and UUID string IDs; passes the value through
	 * in_filter()-style sanitization (strip non-alphanumeric/hyphen chars).
	 *
	 * Returns the full row as an associative array, or null when not found.
	 *
	 * @param string|int $character_id  Supabase primary key of the cyber_characters row.
	 * @return array|null
	 */
	public function get_by_id( $character_id ): ?array {
		$safe_id = $this->sanitize_id( $character_id );
		$url     = $this->table_url( 'cyber_characters', [
			'id'    => 'eq.' . $safe_id,
			'limit' => '1',
		] );
		$rows = $this->get_json( $url );
		return ! empty( $rows ) ? $rows[0] : null;
	}

	/**
	 * Fetch the Field Agent currently active for a given WordPress user.
	 *
	 * "Active" = the character linked to the user's most recent open session
	 * in cyber_game_sessions (status = 'active', ordered by created_at desc).
	 *
	 * Returns null when:
	 *  - No active session exists for the user.
	 *  - The linked agent is STATUS_DEAD.
	 *  - Any Supabase query fails.
	 *
	 * BUG-FIX 8: was a stub that always returned null. Now queries
	 * cyber_game_sessions to find the active session and then fetches
	 * the linked character, refusing to return STATUS_DEAD agents.
	 *
	 * @param int $wp_user_id
	 * @return array|null
	 */
	public function get_active_for_wp_user( int $wp_user_id ): ?array {
		// Step 1: find the most recent active session for this WP user.
		$session_url = $this->table_url( 'cyber_game_sessions', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'status'     => 'eq.active',
			'order'      => 'created_at.desc',
			'limit'      => '1',
		] );
		$sessions = $this->get_json( $session_url );

		if ( empty( $sessions ) ) {
			return null;
		}

		$session      = $sessions[0];
		$character_id = $session['character_id'] ?? null;

		if ( empty( $character_id ) ) {
			return null;
		}

		// Step 2: fetch the character row, guarding against STATUS_DEAD.
		$character = $this->get_by_id( $character_id );

		if ( ! $character ) {
			return null;
		}

		// Never surface a dead agent as "active" — protocol rule.
		if ( ( $character['status'] ?? '' ) === 'STATUS_DEAD' ) {
			error_log( 'TW Repository: get_active_for_wp_user — session references a STATUS_DEAD agent. wp_user_id=' . $wp_user_id . ' character_id=' . $character_id );
			return null;
		}

		return $character;
	}

	/**
	 * Fetch all Field Agents owned by a WordPress user, across all Nodes.
	 *
	 * Includes both living and dead agents. Dead agents are marked STATUS_DEAD.
	 * Does NOT attach tags or inventory — use get_for_wp_user() for that.
	 *
	 * @param int $wp_user_id
	 * @return array
	 */
	public function get_all_for_wp_user( int $wp_user_id ): array {
		$url = $this->table_url( 'cyber_characters', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'order'      => 'id.desc',
		] );
		return $this->get_json( $url );
	}

	/**
	 * Fetch all living (non-STATUS_DEAD) Field Agents for a WordPress user.
	 *
	 * @param int $wp_user_id
	 * @return array
	 */
	public function get_living_for_wp_user( int $wp_user_id ): array {
		$url = $this->table_url( 'cyber_characters', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'status'     => 'neq.STATUS_DEAD',
			'order'      => 'id.desc',
		] );
		return $this->get_json( $url );
	}

	// -------------------------------------------------------------------------
	// Node-scoped queries
	// -------------------------------------------------------------------------

	/**
	 * Fetch all Field Agents currently bound to a specific Node (world).
	 *
	 * BUG-FIX: The previous signature typed $node_id as int and passed it
	 * directly into the Supabase filter. cyber_worlds.id is a UUID string —
	 * any integer cast collapses it to 0, so the query always returned empty.
	 * The parameter is now string|int and is run through sanitize_id() so
	 * UUID values are preserved intact.
	 *
	 * @param string|int $node_id  Supabase primary key (UUID) of the cyber_worlds row.
	 * @return array
	 */
	public function get_by_node( $node_id ): array {
		$safe_id = $this->sanitize_id( $node_id );

		if ( empty( $safe_id ) ) {
			error_log( 'TW Repository: get_by_node — invalid node_id: ' . $node_id );
			return [];
		}

		$url = $this->table_url( 'cyber_characters', [
			'world_id' => 'eq.' . $safe_id,
		] );
		return $this->get_json( $url );
	}

	// -------------------------------------------------------------------------
	// Echo / tag helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch the current Echo tag collection for a Field Agent.
	 *
	 * Tags are stored in cyber_character_complete_tags WITHOUT the leading '#'.
	 *
	 * BUG-FIX 9: The original code called get_character_tags(), a global
	 * helper that does not exist in this plugin. The fallback Supabase query
	 * was never reached. The guard is removed; we always go directly to
	 * Supabase, which is the single source of truth for Echo tags.
	 *
	 * @param string|int $character_id
	 * @return array  Array of tag row arrays (may be empty).
	 */
	public function get_echo_tags( $character_id ): array {
		$safe_id = $this->sanitize_id( $character_id );
		$url     = $this->table_url( 'cyber_character_complete_tags', [
			'character_id' => 'eq.' . $safe_id,
		] );
		return $this->get_json( $url );
	}

	/**
	 * Check whether a Field Agent has a specific Echo tag.
	 *
	 * @param string|int $character_id
	 * @param string     $tag  Tag name WITHOUT leading '#'.
	 * @return bool
	 */
	public function has_echo_tag( $character_id, string $tag ): bool {
		$tags = $this->get_echo_tags( $character_id );
		foreach ( $tags as $row ) {
			// Tags may be stored in a 'tag' or 'tag_name' column — check both.
			$value = $row['tag'] ?? $row['tag_name'] ?? '';
			if ( $value === $tag ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Biometrics / HUD state
	// -------------------------------------------------------------------------

	/**
	 * Fetch the live HUD/biometric state for a Field Agent from
	 * cyber_state_of_the_campaign.
	 *
	 * Returns the full row: HP, MP, XP, Satiety, Hydration, Rest,
	 * Sync_rate (Entropy), time_of_day, current_location_id, etc.
	 * Returns null when no active campaign row exists.
	 *
	 * @param string|int $character_id
	 * @return array|null
	 */
	public function get_hud_state( $character_id ): ?array {
		$safe_id = $this->sanitize_id( $character_id );
		$url     = $this->table_url( 'cyber_state_of_the_campaign', [
			'character_id' => 'eq.' . $safe_id,
			'limit'        => '1',
		] );
		$rows = $this->get_json( $url );
		return ! empty( $rows ) ? $rows[0] : null;
	}

	// -------------------------------------------------------------------------
	// Ownership / security helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify that a given WordPress user owns a specific Field Agent.
	 *
	 * Must be called before any AJAX action that mutates agent data, to prevent
	 * one Operator from modifying another's character.
	 *
	 * @param int        $wp_user_id
	 * @param string|int $character_id
	 * @return bool
	 */
	public function user_owns_agent( int $wp_user_id, $character_id ): bool {
		$safe_id = $this->sanitize_id( $character_id );
		$url     = $this->table_url( 'cyber_characters', [
			'id'         => 'eq.' . $safe_id,
			'wp_user_id' => 'eq.' . $wp_user_id,
			'limit'      => '1',
		] );
		$rows = $this->get_json( $url );
		return ! empty( $rows );
	}
}
