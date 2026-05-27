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
	 * @param array $data {
	 *   @type string $name        Character name (required).
	 *   @type string $race_id     UUID of the race.
	 *   @type string $class_id    UUID of the class.
	 *   @type string $world_id    UUID of the world (required).
	 *   @type int    $wp_user_id  WordPress user ID.
	 *   @type string $bio         Optional bio.
	 *   @type string $avatar      Optional avatar URL.
	 * }
	 * @return array|WP_Error Created row (with id) or WP_Error on failure.
	 */
	public function create( array $data ) {
		if ( empty( $data['name'] ) || empty( $data['world_id'] ) ) {
			return new WP_Error( 'missing_fields', 'name and world_id are required.' );
		}

		if ( ! function_exists( 'tw_supabase_request' ) || ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			return new WP_Error( 'supabase_unavailable', 'Supabase service key not configured.' );
		}

		$payload = [
			'name'       => sanitize_text_field( $data['name'] ),
			'world_id'   => nw_sanitize_uuid( (string) $data['world_id'] ),
			'wp_user_id' => ! empty( $data['wp_user_id'] ) ? (int) $data['wp_user_id'] : get_current_user_id(),
			'bio'        => sanitize_textarea_field( $data['bio'] ?? '' ),
			'avatar'     => esc_url_raw( $data['avatar'] ?? '' ),
			'lvl'        => 1,
			'gold'       => 0,
			'body'       => 0,
			'mind'       => 0,
			'reflex'     => 0,
			'spirit'     => 0,
			'hp'         => 10,
			'mp'         => 10,
		];

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
		if ( is_array( $result ) && isset( $result[0] ) && is_array( $result[0] ) ) {
			return $result[0];
		}

		return new WP_Error( 'unexpected_response', 'Unexpected response from Supabase.' );
	}

	/**
	 * Check whether the current user already owns a character in the given world.
	 *
	 * @param string $world_id UUID of the world.
	 * @param int    $wp_user_id WP user ID (0 = current user).
	 * @return bool
	 */
	public function user_has_character_in_world( string $world_id, int $wp_user_id = 0 ): bool {
		if ( ! function_exists( 'tw_supabase_first' ) ) {
			return false;
		}

		if ( ! $wp_user_id ) {
			$wp_user_id = get_current_user_id();
		}

		if ( ! $wp_user_id || ! $world_id ) {
			return false;
		}

		$row = tw_supabase_first(
			'cyber_characters',
			[
				'world_id'   => 'eq.' . nw_sanitize_uuid( $world_id ),
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		return ! empty( $row );
	}
}
