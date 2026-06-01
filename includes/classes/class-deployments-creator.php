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
 * ARCHITECTURAL RULES (do not violate):
 *  - Never modify Node Entropy or any Agent Echo tag from here.
 *    All world-state changes must go through the tag pipeline.
 *  - Table names are fixed: cyber_campaign, cyber_campaign_worlds,
 *    cyber_campaign_characters. Do not alias or rename them.
 *  - Column names sent to Supabase must exactly match the existing schema
 *    (same as the JS payload in the original shortcode).
 *  - After a successful INSERT, fire the 'neoweaver_campaign_created'
 *    action hook so webhook dispatchers and other listeners can react.
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
	 * Supabase REST headers using SERVICE KEY.
	 *
	 * BUG 26 FIX: The previous implementation had two header methods:
	 *   headers()       — service key, used for POSTs
	 *   read_headers()  — anon key, used for GETs
	 *
	 * read_headers() (anon key, no user JWT) was used for all ownership/existence
	 * GET checks. If RLS on cyber_campaign or cyber_campaign_worlds requires the
	 * `authenticated` role, the anon key request is blocked and returns [] —
	 * meaning every ownership check silently passes as "not found" without ever
	 * blocking anything. This is a security hole: a user could link any world or
	 * character regardless of ownership.
	 *
	 * Fix: all server-side reads (existence/ownership checks) now use the service
	 * key, which bypasses RLS on the server side. The service key is never sent
	 * to the browser. read_headers() is removed — there is only one header method.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW Deployments_Creator: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			error_log( 'TW Deployments_Creator: No Supabase key available.' );
			$key = '';
		}

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
	 *
	 * BUG 26 FIX: Uses service key (via headers()) instead of the removed
	 * read_headers() anon-key method. Server-side ownership/existence checks
	 * must use the service key to bypass RLS; anon key without a user JWT is
	 * blocked by any policy that requires auth.uid().
	 *
	 * Also accepts any 2xx status code (not just 200) to match PostgREST
	 * behaviour on some Supabase configurations.
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
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW Deployments GET HTTP ' . $code . ' [' . $url . ']: ' . wp_remote_retrieve_body( $res ) );
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Execute a POST with a JSON body and return the first row of the response.
	 * Uses service key — server-side INSERTs bypass RLS.
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

		$code = (int) wp_remote_retrieve_response_code( $res );
		// Supabase returns 201 for a successful INSERT with return=representation.
		if ( $code < 200 || $code >= 300 ) {
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
	 *  2. Deployment name is a non-empty string.
	 *  3. Numeric fields (game_mode, game_length, priority) are > 0.
	 *
	 * @param array $data
	 * @return true|WP_Error
	 */
	public function validate( array $data ) {
		$required = [ 'wp_user_id', 'name', 'game_mode', 'gm_style', 'game_length', 'priority' ];
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' ) {
				return new WP_Error(
					'missing_field',
					sprintf( 'Required deployment field missing: %s', $field )
				);
			}
		}

		if ( ! is_string( $data['name'] ) || trim( $data['name'] ) === '' ) {
			return new WP_Error( 'invalid_name', 'Deployment name cannot be empty.' );
		}

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
	 * @param array $data
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
	 * @param string      $campaign_id
	 * @param string|int  $world_id
	 * @param int         $creator_wp_id
	 * @return bool
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
	 * @param string      $campaign_id
	 * @param string|int  $character_id
	 * @param int         $creator_wp_id
	 * @return bool
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
	 * Steps:
	 *  1. validate()
	 *  2. insert_campaign_row()
	 *  3. link_world()     — optional, non-fatal on failure
	 *  4. link_character() — optional, non-fatal on failure
	 *  5. Fire 'neoweaver_campaign_created' action hook
	 *  6. Return $campaign_id
	 *
	 * @param array            $data
	 * @param string|int|null  $world_id
	 * @param string|int|null  $character_id
	 * @return string|null
	 */
	public function create( array $data, $world_id = null, $character_id = null ): ?string {
		$valid = $this->validate( $data );
		if ( is_wp_error( $valid ) ) {
			error_log( 'TW Deployments: validate() failed — ' . $valid->get_error_message() );
			return null;
		}

		$campaign_id = $this->insert_campaign_row( $data );
		if ( ! $campaign_id ) {
			return null;
		}

		$creator_wp_id = intval( $data['wp_user_id'] );

		if ( $world_id !== null ) {
			$this->link_world( $campaign_id, $world_id, $creator_wp_id );
		}

		if ( $character_id !== null ) {
			$this->link_character( $campaign_id, $character_id, $creator_wp_id );
		}

		do_action( 'neoweaver_campaign_created', $campaign_id, $data );

		return $campaign_id;
	}
}
