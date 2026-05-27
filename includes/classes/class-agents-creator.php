<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Neoweaver_Agents_Creator
 *
 * Handles inserting a new character (agent) into Supabase.
 * Called from neoweaver_create_character() in api-endpoints.php.
 */
class Neoweaver_Agents_Creator {

	/**
	 * Create a new agent in cyber_characters and run seeding RPCs.
	 *
	 * @param array $data       Sanitised character fields (character_name, race, class, pronouns,
	 *                          backstory, node_id, attr_body, attr_reflex, attr_mind, attr_spirit).
	 * @param int   $wp_user_id WordPress user ID of the owner.
	 *
	 * @return string|null  UUID of the new character, or null on failure.
	 */
	public function create( array $data, int $wp_user_id ): ?string {
		$base = function_exists( 'nw_supabase_base' ) ? nw_supabase_base() : '';
		if ( ! $base ) {
			error_log( 'Neoweaver_Agents_Creator::create — supabase base missing' );
			return null;
		}

		$payload = [
			'wp_user_id'    => $wp_user_id,
			'name'          => $data['character_name'] ?? '',
			'pronouns'      => $data['pronouns']       ?? '',
			'race_id'       => $data['race']           ?? '',
			'class_id'      => $data['class']          ?? '',
			'node_id'       => $data['node_id']        ?? null,
			'backstory'     => $data['backstory']      ?? '',
			'attr_body'     => (int) ( $data['attr_body']    ?? 3 ),
			'attr_reflex'   => (int) ( $data['attr_reflex']  ?? 3 ),
			'attr_mind'     => (int) ( $data['attr_mind']    ?? 3 ),
			'attr_spirit'   => (int) ( $data['attr_spirit']  ?? 3 ),
			'status'        => 'active',
		];

		// Remove null node_id so Supabase doesn't reject with a type error.
		if ( null === $payload['node_id'] ) {
			unset( $payload['node_id'] );
		}

		$response = wp_remote_post( $base . 'cyber_characters', [
			'headers' => function_exists( 'nw_supabase_service_headers' )
				? nw_supabase_service_headers( true )
				: [],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'Neoweaver_Agents_Creator::create — wp_remote_post error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'Neoweaver_Agents_Creator::create — Supabase HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		$agent_id = $body[0]['id'] ?? null;
		if ( ! $agent_id ) {
			error_log( 'Neoweaver_Agents_Creator::create — no ID returned in response' );
			return null;
		}

		$safe_id  = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $agent_id );
		$rpc_base = $base . 'rpc/';
		$rpc_args = wp_json_encode( [ 'p_character_id' => $safe_id ] );

		// Seed starting stats, inventory, and abilities via RPCs.
		foreach ( [ 'fn_seed_character_stats', 'fn_seed_character_inventory', 'fn_seed_character_abilities' ] as $rpc ) {
			$rpc_res = wp_remote_post( $rpc_base . $rpc, [
				'headers' => function_exists( 'nw_supabase_service_headers' ) ? nw_supabase_service_headers() : [],
				'body'    => $rpc_args,
				'timeout' => 30,
			] );
			if ( is_wp_error( $rpc_res ) ) {
				error_log( 'Neoweaver_Agents_Creator::create — RPC ' . $rpc . ' error: ' . $rpc_res->get_error_message() );
			} else {
				$rpc_code = wp_remote_retrieve_response_code( $rpc_res );
				if ( $rpc_code < 200 || $rpc_code >= 300 ) {
					error_log( 'Neoweaver_Agents_Creator::create — RPC ' . $rpc . ' HTTP ' . $rpc_code . ': ' . wp_remote_retrieve_body( $rpc_res ) );
				}
			}
		}

		return $safe_id;
	}
}
