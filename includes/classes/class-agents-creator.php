<?php
/**
 * class-agents-creator.php
 * Handles creation of new Field Agents (characters) in Supabase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_Creator {

	/**
	 * Create a new character row in cyber_characters.
	 *
	 * Accepts keys from both the REST endpoint caller (character_name, race,
	 * class, node_id) and the internal canonical form (name, race_id, class_id,
	 * world_id) so either call style works.
	 *
	 * @param array $data  Character data (see key aliases below).
	 * @param int   $wp_user_id  WP user ID (0 = current user).
	 * @return string|WP_Error  UUID of the created row, or WP_Error on failure.
	 */
	public function create( array $data, int $wp_user_id = 0 ) {

		// ------------------------------------------------------------------
		// BUG 2 FIX: normalise caller keys → internal keys
		// api-endpoints.php passes: character_name, race, class, node_id
		// Internal form expects:    name,           race_id, class_id, world_id
		// ------------------------------------------------------------------
		if ( empty( $data['name'] ) && ! empty( $data['character_name'] ) ) {
			$data['name'] = $data['character_name'];
		}
		if ( empty( $data['race_id'] ) && ! empty( $data['race'] ) ) {
			$data['race_id'] = $data['race'];
		}
		if ( empty( $data['class_id'] ) && ! empty( $data['class'] ) ) {
			$data['class_id'] = $data['class'];
		}
		// node_id maps to world_id (the caller uses node_id for the world UUID)
		if ( empty( $data['world_id'] ) && ! empty( $data['node_id'] ) ) {
			$data['world_id'] = $data['node_id'];
		}

		if ( empty( $data['name'] ) || empty( $data['world_id'] ) ) {
			return new WP_Error( 'missing_fields', 'name and world_id are required.' );
		}

		if ( ! function_exists( 'tw_supabase_request' ) || ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			return new WP_Error( 'supabase_unavailable', 'Supabase service key not configured.' );
		}

		if ( ! $wp_user_id ) {
			$wp_user_id = get_current_user_id();
		}

		$world_id = nw_sanitize_uuid( (string) $data['world_id'] );

		// ------------------------------------------------------------------
		// BUG 3 FIX: enforce "1 Agent per World" before inserting.
		// tw_supabase_first() never existed — use tw_supabase_get_admin() directly.
		// ------------------------------------------------------------------
		if ( $wp_user_id && $world_id && function_exists( 'tw_supabase_get_admin' ) ) {
			$existing = tw_supabase_get_admin( 'cyber_characters', [
				'world_id'   => 'eq.' . $world_id,
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			] );

			if ( is_array( $existing ) && ! empty( $existing ) ) {
				return new WP_Error(
					'duplicate_character',
					'You already have a character in this world.',
					[ 'status' => 409 ]
				);
			}
		}

		$payload = [
			'name'       => sanitize_text_field( $data['name'] ),
			'world_id'   => $world_id,
			'wp_user_id' => $wp_user_id,
			'bio'        => sanitize_textarea_field( $data['bio'] ?? $data['backstory'] ?? '' ),
			'avatar'     => esc_url_raw( $data['avatar'] ?? '' ),
			'lvl'        => 1,
			'gold'       => 0,
			'body'       => isset( $data['attr_body'] )   ? (int) $data['attr_body']   : 0,
			'mind'       => isset( $data['attr_mind'] )   ? (int) $data['attr_mind']   : 0,
			'reflex'     => isset( $data['attr_reflex'] ) ? (int) $data['attr_reflex'] : 0,
			'spirit'     => isset( $data['attr_spirit'] ) ? (int) $data['attr_spirit'] : 0,
			'hp'         => 10,
			'mp'         => 10,
		];

		if ( ! empty( $data['pronouns'] ) ) {
			$payload['pronouns'] = sanitize_text_field( $data['pronouns'] );
		}

		if ( ! empty( $data['race_id'] ) ) {
			$payload['race_id'] = nw_sanitize_uuid( (string) $data['race_id'] );
		}

		if ( ! empty( $data['class_id'] ) ) {
			$payload['class_id'] = nw_sanitize_uuid( (string) $data['class_id'] );
		}

		$result = tw_supabase_request(
			'POST',
			'cyber_characters',
			[],
			$payload,
			[
				'headers' => [
					'apikey'        => TW_SUPABASE_SERVICE_KEY,
					'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
					'Prefer'        => 'return=representation',
				],
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'NW Agents_Creator::create error: ' . $result->get_error_message() );
			return $result;
		}

		// PostgREST returns an array with the inserted row.
		if ( is_array( $result ) && isset( $result[0]['id'] ) ) {
			return (string) $result[0]['id'];
		}

		return new WP_Error( 'unexpected_response', 'Unexpected response from Supabase.' );
	}

	/**
	 * Check whether the given user already owns a character in the given world.
	 *
	 * BUG 3 FIX: tw_supabase_first() never existed; replaced with
	 * tw_supabase_get_admin() (limit=1) which is actually available.
	 *
	 * @param string $world_id   UUID of the world.
	 * @param int    $wp_user_id WP user ID (0 = current user).
	 * @return bool
	 */
	public function user_has_character_in_world( string $world_id, int $wp_user_id = 0 ): bool {
		if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
			return false;
		}

		if ( ! $wp_user_id ) {
			$wp_user_id = get_current_user_id();
		}

		if ( ! $wp_user_id || ! $world_id ) {
			return false;
		}

		$row = tw_supabase_get_admin(
			'cyber_characters',
			[
				'world_id'   => 'eq.' . nw_sanitize_uuid( $world_id ),
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		return is_array( $row ) && ! empty( $row );
	}
}
