<?php
/**
 * Neoweaver_Deployments_Creator
 *
 * Data / logic layer for creating Deployments (campaigns) in Supabase.
 * A Deployment is a campaign or operation owned by a WordPress user that
 * runs inside a Node (world) and may involve one or more Field Agents.
 *
 * This class is intentionally RENDER-FREE. It never touches ob_start(),
 * never outputs HTML, and knows nothing about the multi-step wizard UI.
 * Rendering lives in Neoweaver_Public::shortcode_campaign_creator().
 *
 * ARCHITECTURAL RULES (do not violate):\n *  - Never modify Node Entropy or any Agent Echo tag from here.\n *    All world-state changes must go through the Make.com tag pipeline.
 *  - Table names are fixed: cyber_campaign, cyber_campaign_worlds,
 *    cyber_campaign_characters. Do not alias or rename them.
 *  - Column names sent to Supabase must exactly match the existing schema
 *    (same as the JS payload in the original shortcode).
 *  - After a successful INSERT, fire the 'neoweaver_campaign_created'
 *    action hook so Make.com webhook dispatchers and other listeners can react.
 *
 * HTTP pattern: identical to Neoweaver_Agents_Repository and
 * Neoweaver_Agents_Creator — same headers(), table_url(), get_json(),
 * post_json() helpers.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Deployments_Creator {

	// -------------------------------------------------------------------------
	// Internal HTTP helpers  (mirror of the Repository / Creator pattern)
	// -------------------------------------------------------------------------

	/**
	 * Standard Supabase REST request headers.
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
	 * Build a full Supabase REST table URL with optional query-string args.
	 *
	 * @param string               $table  Full table name, e.g. 'cyber_campaign'.
	 * @param array<string,string> $args   Query-string parameters.
	 * @return string
	 */
	private function table_url( string $table, array $args = [] ): string {
		$base = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$url  = trailingslashit( $base ) . 'rest/v1/' . $table;
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Execute a GET and return the decoded JSON body as an array.
	 * Returns [] and logs on any error.
	 *
	 * @param string $url
	 * @return array
	 */
	private function get_json( string $url ): array {
		$res = wp_remote_get( $url, [ 'headers' => $this->headers(), 'timeout' => 15 ] );
		if ( is_wp_error( $res ) ) {
			error_log( 'TW Deployments GET error [' . $url . ']: ' . $res->get_error_message() );
			return [];
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			error_log( 'TW Deployments GET HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $res ) );
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Execute a POST with a JSON body and return the first row of the response.
	 * Uses `Prefer: return=representation` so Supabase echoes the new row back.
	 * Returns null on any failure.
	 *
	 * @param string $url
	 * @param array  $body  Data to JSON-encode and POST.
	 * @return array|null   First row from the Supabase response, or null.
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
			error_log( 'TW Deployments POST error [' . $url . ']: ' . $res->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $res );
		// Supabase returns 201 for a successful INSERT with return=representation.
		if ( $code !== 201 ) {
			error_log( 'TW Deployments POST HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $res ) );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data[0]; // Supabase wraps single rows in an array.
		}
		return null;
	}

	/**
	 * Sanitize a UUID or integer ID for safe use in a Supabase REST payload.
	 *
	 * cyber_worlds.id and cyber_characters.id are UUID strings. Passing them
	 * as PHP int (or casting with intval()) collapses every UUID to 0, breaking
	 * FK constraints on insert. Strip everything except alphanumerics and
	 * hyphens — safe for both UUID v4 and legacy integer IDs.
	 *
	 * @param  mixed $raw_id
	 * @return string  Sanitized ID, or '' if nothing valid remains.
	 */
	private function sanitize_id( $raw_id ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw_id );
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Pre-flight checks before any database write.
	 *
	 * Checks:
	 *  1. Required fields are present and non-empty:
	 *     wp_user_id, name, game_mode, gm_style, game_length, priority.
	 *     (world_type is intentionally excluded — it is set during world linkage,
	 *     not at campaign creation time, and is nullable in cyber_campaign.)
	 *  2. Deployment name is a non-empty string.
	 *  3. Numeric fields (game_mode, game_length, priority) are > 0.
	 *
	 * Returns true on success, WP_Error with a descriptive code on failure.
	 *
	 * @param array $data  Sanitised deployment data, keyed as per cyber_campaign columns.
	 * @return true|WP_Error
	 */
	public function validate( array $data ) {
		// Required field keys (world_type excluded — nullable, set during linkage).
		$required = [ 'wp_user_id', 'name', 'game_mode', 'gm_style', 'game_length', 'priority' ];
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' ) {
				return new WP_Error(
					'missing_field',
					sprintf( 'Required deployment field missing: %s', $field )
				);
			}
		}

		// Name must not be blank after trimming.
		if ( ! is_string( $data['name'] ) || trim( $data['name'] ) === '' ) {
			return new WP_Error( 'invalid_name', 'Deployment name cannot be empty.' );
		}

		// Numeric range guards for selectable fields (1–5 per UI).
		$numeric_fields = [ 'game_mode', 'game_length', 'priority' ];
		foreach ( $numeric_fields as $field ) {
			if ( (int) $data[ $field ] < 1 ) {
				return new WP_Error(
					'invalid_field_value',
					sprintf( 'Field %s must be a positive integer.', $field )
				);
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Creation pipeline steps
	// -------------------------------------------------------------------------

	/**
	 * Insert the base cyber_campaign row.
	 *
	 * Column names and values exactly mirror the JS payload from the original
	 * shortcode — do not change them without updating the DB schema first:
	 *   wp_user_id, name, game_mode, world_type, gm_style, customize,
	 *   is_active, game_length, priority.
	 *
	 * Returns the newly created campaign UUID string on success, or null.
	 *
	 * @param array $data  Sanitised deployment data.
	 * @return string|null  New campaign ID (UUID), or null on failure.
	 */
	public function insert_campaign_row( array $data ): ?string {
		$payload = [
			'wp_user_id'  => intval( $data['wp_user_id'] ),
			'name'        => sanitize_text_field( $data['name'] ),
			'game_mode'   => intval( $data['game_mode'] ),
			'world_type'  => isset( $data['world_type'] ) ? intval( $data['world_type'] ) : null,
			'gm_style'    => sanitize_text_field( $data['gm_style'] ),
			'customize'   => sanitize_textarea_field( $data['customize'] ?? '' ),
			'is_active'   => true,
			'game_length' => intval( $data['game_length'] ),
			'priority'    => intval( $data['priority'] ),
		];

		$url = $this->table_url( 'cyber_campaign' );
		$row = $this->post_json( $url, $payload );

		if ( ! $row || empty( $row['id'] ) ) {
			error_log( 'TW Deployments: insert_campaign_row failed for wp_user_id=' . $data['wp_user_id'] );
			return null;
		}

		return (string) $row['id'];
	}

	/**
	 * Link a world (Node) to the deployment in cyber_campaign_worlds.
	 *
	 * BUG-FIX: $world_id was typed as int and passed directly to Supabase.
	 * cyber_worlds.id is a UUID string — intval() on a UUID collapses it to 0,
	 * causing the FK constraint to reject the insert or store a corrupt value.
	 * The parameter is now typed as string|int and run through sanitize_id()
	 * before use. Callers in create() are updated accordingly.
	 *
	 * BUG-FIX 2: payload key was 'creator_wp_id' but the table column is
	 * 'wp_user_id'. Changed to match the actual schema.
	 *
	 * @param string      $campaign_id    UUID of the newly created campaign.
	 * @param string|int  $world_id       Supabase primary key (UUID) of cyber_worlds.
	 * @param int         $creator_wp_id  WordPress user ID of the campaign creator.
	 * @return bool  true on success, false on failure.
	 */
	public function link_world( string $campaign_id, $world_id, int $creator_wp_id ): bool {
		$safe_world_id = $this->sanitize_id( $world_id );

		if ( empty( $safe_world_id ) ) {
			error_log( 'TW Deployments: link_world — invalid world_id: ' . $world_id );
			return false;
		}

		$payload = [
			'campaign_id' => $campaign_id,
			'world_id'    => $safe_world_id,
			'wp_user_id'  => $creator_wp_id,
		];

		$url = $this->table_url( 'cyber_campaign_worlds' );
		$row = $this->post_json( $url, $payload );

		if ( ! $row ) {
			error_log( 'TW Deployments: link_world failed — campaign=' . $campaign_id . ' world=' . $safe_world_id );
			return false;
		}

		return true;
	}

	/**
	 * Link a Field Agent to the deployment in cyber_campaign_characters.
	 *
	 * BUG-FIX: $character_id was typed as int and passed directly to Supabase.
	 * cyber_characters.id is a UUID string — intval() on a UUID collapses it
	 * to 0, causing the FK constraint to reject the insert or store a corrupt
	 * value. The parameter is now typed as string|int and run through
	 * sanitize_id() before use. Callers in create() are updated accordingly.
	 *
	 * BUG-FIX 2: payload key was 'creator_wp_id' but the table column is
	 * 'wp_user_id'. Changed to match the actual schema.
	 *
	 * @param string      $campaign_id    UUID of the newly created campaign.
	 * @param string|int  $character_id   Supabase primary key (UUID) of cyber_characters.
	 * @param int         $creator_wp_id  WordPress user ID of the campaign creator.
	 * @return bool  true on success, false on failure.
	 */
	public function link_character( string $campaign_id, $character_id, int $creator_wp_id ): bool {
		$safe_character_id = $this->sanitize_id( $character_id );

		if ( empty( $safe_character_id ) ) {
			error_log( 'TW Deployments: link_character — invalid character_id: ' . $character_id );
			return false;
		}

		$payload = [
			'campaign_id'  => $campaign_id,
			'character_id' => $safe_character_id,
			'wp_user_id'   => $creator_wp_id,
		];

		$url = $this->table_url( 'cyber_campaign_characters' );
		$row = $this->post_json( $url, $payload );

		if ( ! $row ) {
			error_log( 'TW Deployments: link_character failed — campaign=' . $campaign_id . ' character=' . $safe_character_id );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Orchestration
	// -------------------------------------------------------------------------

	/**
	 * Run the full deployment creation pipeline.
	 *
	 * Steps (in order):
	 *  1. validate() — returns WP_Error on failure (bubbled up as null here).
	 *  2. insert_campaign_row() — abort on null.
	 *  3. link_world()     — only when $world_id is not null; non-fatal.
	 *  4. link_character() — only when $character_id is not null; non-fatal.
	 *  5. Fire 'neoweaver_campaign_created' action hook.
	 *  6. Return $campaign_id.
	 *
	 * link_world and link_character failures are logged but do not abort
	 * the pipeline — the deployment row itself already exists by that point
	 * and unlinking is recoverable from the admin side.
	 *
	 * BUG-FIX: $world_id and $character_id are now string|null (UUID) instead
	 * of int|null, matching the actual Supabase column types. Callers that
	 * previously cast these to int before passing them here must stop doing so.
	 *
	 * @param array            $data          Sanitised deployment data (all required fields).
	 * @param string|int|null  $world_id      Optional: link this Node to the deployment.
	 * @param string|int|null  $character_id  Optional: link this Field Agent to the deployment.
	 * @return string|null  New campaign UUID, or null on hard failure.
	 */
	public function create( array $data, $world_id = null, $character_id = null ): ?string {
		// 1. Validate.
		$valid = $this->validate( $data );
		if ( is_wp_error( $valid ) ) {
			error_log( 'TW Deployments: validate() failed — ' . $valid->get_error_message() );
			return null;
		}

		// 2. Insert campaign row.
		$campaign_id = $this->insert_campaign_row( $data );
		if ( ! $campaign_id ) {
			return null;
		}

		$creator_wp_id = intval( $data['wp_user_id'] );

		// 3. Optionally link a world / Node.
		if ( $world_id !== null ) {
			$this->link_world( $campaign_id, $world_id, $creator_wp_id );
		}

		// 4. Optionally link a Field Agent.
		if ( $character_id !== null ) {
			$this->link_character( $campaign_id, $character_id, $creator_wp_id );
		}

		// 5. Fire action hook for Make.com dispatcher and other listeners.
		do_action( 'neoweaver_campaign_created', $campaign_id, $data );

		return $campaign_id;
	}
}
