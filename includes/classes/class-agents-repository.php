<?php
/**
 * Neoweaver_Agents_Repository
 *
 * Low-level data-access layer for Field Agents stored in Supabase.
 * All reads go through the Supabase REST API using the helpers
 * tw_supabase_url() and tw_supabase_service_key() defined in wp-config.
 *
 * WHY SERVICE KEY:
 * cyber_characters RLS policies require `authenticated` role — the anon key
 * without a JWT is blocked by every SELECT policy. Server-side PHP reads
 * (security guards, session lookups, roster fetches) must use the service key
 * to bypass RLS. The service key is never sent to the browser.
 *
 * ARCHITECTURAL RULES (do not violate):
 *  - This class NEVER mutates game state. Pure read queries only.
 *  - Never resurrect an Agent whose status is STATUS_DEAD.
 *  - Never allow an Agent to be bound to a Node it was not created in.
 *  - All table names use the cyber_ prefix (e.g. cyber_characters).
 *
 * COLUMN NAMES in cyber_characters (FK columns use underscores):
 *  - world_id    (FK → cyber_worlds)      — NOT worldid
 *  - locationid  (FK → cyber_world_map)   — NOT location_id
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
	 * Build the standard Supabase REST request headers using SERVICE KEY.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW Agents_Repository: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key. Server-side reads may be blocked by RLS.' );
			$key = tw_supabase_anon_key();
		} else {
			error_log( 'TW Agents_Repository: No Supabase key available.' );
			$key = '';
		}

		return [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Build a full Supabase REST endpoint URL for a given table + query args.
	 *
	 * @param string               $table
	 * @param array<string,string> $args
	 * @return string
	 */
	private function table_url( string $table, array $args = [] ): string {
		$base = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$url  = trailingslashit( $base ) . 'rest/v1/' . $table;
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Execute a GET request and return the decoded JSON body as an array.
	 *
	 * BUG 21 FIX: The original check used strict !== 200, which treated any
	 * other 2xx code (e.g. 201 Created from a PostgREST upsert read-back)
	 * as an error and returned []. Supabase/PostgREST can return 201 on
	 * certain read patterns. Accept any 2xx (200–299) as success.
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

		$code = (int) wp_remote_retrieve_response_code( $response );

		// Accept any 2xx status code (200 OK, 201 Created, 206 Partial, etc.).
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW Supabase HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $response ) );
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Build a Supabase `in.(...)` filter value safe for both integer and UUID keys.
	 *
	 * Returns an empty string when the resulting ID list would be empty —
	 * callers must guard against an empty return before using this value
	 * in a query (PostgREST rejects `in.()` with a syntax error).
	 *
	 * @param  array $ids
	 * @return string  e.g. "in.(uuid1,uuid2)" or "" when no valid IDs remain.
	 */
	private function in_filter( array $ids ): string {
		$safe = array_filter(
			array_map(
				function ( $id ) {
					return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
				},
				$ids
			)
		);

		if ( empty( $safe ) ) {
			return '';
		}

		return 'in.(' . implode( ',', $safe ) . ')';
	}

	/**
	 * Sanitize a single ID (UUID or integer) for safe use in a Supabase filter.
	 *
	 * @param  mixed $id
	 * @return string
	 */
	private function sanitize_id( $id ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
	}

	// -------------------------------------------------------------------------
	// Primary roster query
	// -------------------------------------------------------------------------

	/**
	 * Fetch all Field Agents for a WordPress user, with tags and inventory.
	 *
	 * @param int $wp_user_id
	 * @return array  Enriched character rows; each has 'tags' and 'inventory'.
	 */
	public function get_for_wp_user( int $wp_user_id ): array {

		$chars_url = $this->table_url( 'cyber_characters', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'select'     => '*,cyber_classes(name),cyber_races(name),cyber_campaign_characters(cyber_campaign(name,cyber_campaign_worlds(cyber_worlds(name))))',
			'order'      => 'created_at.desc',
		] );

		$characters = $this->get_json( $chars_url );

		if ( empty( $characters ) ) {
			return [];
		}

		$char_ids  = wp_list_pluck( $characters, 'id' );
		$ids_query = $this->in_filter( $char_ids );

		// Guard: all IDs sanitized to empty strings (malformed UUIDs from DB).
		// in_filter() returns '' in this case — PostgREST would reject in.().
		if ( '' === $ids_query ) {
			error_log( 'TW Repository: get_for_wp_user — all character IDs failed sanitization for wp_user_id=' . $wp_user_id );
			foreach ( $characters as &$c ) {
				$c['tags']      = [];
				$c['inventory'] = [];
			}
			unset( $c );
			return $characters;
		}

		$tags_url = $this->table_url( 'cyber_character_complete_tags', [
			'character_id' => $ids_query,
		] );
		$all_tags = $this->get_json( $tags_url );

		$inv_url   = $this->table_url( 'v_cyber_character_items', [
			'character_id' => $ids_query,
		] );
		$all_items = $this->get_json( $inv_url );

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
	 * Fetch a single Field Agent by primary key.
	 *
	 * @param string|int $character_id
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
	 * @param int $wp_user_id
	 * @return array|null
	 */
	public function get_active_for_wp_user( int $wp_user_id ): ?array {
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

		$character_id = $sessions[0]['character_id'] ?? null;
		if ( empty( $character_id ) ) {
			return null;
		}

		$character = $this->get_by_id( $character_id );
		if ( ! $character ) {
			return null;
		}

		if ( ( $character['status'] ?? '' ) === 'STATUS_DEAD' ) {
			error_log( 'TW Repository: get_active_for_wp_user — session references a STATUS_DEAD agent. wp_user_id=' . $wp_user_id . ' character_id=' . $character_id );
			return null;
		}

		return $character;
	}

	/**
	 * Fetch all Field Agents owned by a WordPress user (living + dead).
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
	 * NOTE: the FK column in cyber_characters is `world_id` (with underscore).
	 *
	 * @param string|int $node_id  UUID of the cyber_worlds row.
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
	 * @param string|int $character_id
	 * @return array
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
	 * BUG 22 FIX: The previous query filtered only by character_id with limit=1,
	 * returning the most-recently-inserted row regardless of which campaign is
	 * active. A character can participate in multiple campaigns (multiple
	 * Deployments in the same Node), so the wrong campaign's state could be
	 * returned silently. $campaign_id is now a required parameter; callers
	 * must pass the active campaign UUID from the current session context.
	 *
	 * Returns null when no matching row exists for the character+campaign pair.
	 *
	 * @param string|int $character_id
	 * @param string|int $campaign_id   UUID of the active cyber_campaigns row.
	 * @return array|null
	 */
	public function get_hud_state( $character_id, $campaign_id ): ?array {
		$safe_char     = $this->sanitize_id( $character_id );
		$safe_campaign = $this->sanitize_id( $campaign_id );

		if ( '' === $safe_char || '' === $safe_campaign ) {
			error_log( 'TW Repository: get_hud_state — missing character_id or campaign_id.' );
			return null;
		}

		$url  = $this->table_url( 'cyber_state_of_the_campaign', [
			'character_id' => 'eq.' . $safe_char,
			'campaign_id'  => 'eq.' . $safe_campaign,
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
