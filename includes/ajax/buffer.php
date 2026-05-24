<?php
/**
 * NeoWeave - AJAX & RPC Buffer Handlers
 *
 * Uses Supabase helpers from includes/supabase-config.php and
 * includes/supabase-helpers.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper: resolve active character_id from WP user.
 */
if ( ! function_exists( 'get_cyber_character_id_by_wp_id' ) ) {
	function get_cyber_character_id_by_wp_id( int $wp_user_id ): string {
		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			return '';
		}

		$game_data = get_user_game_data_from_supabase( $wp_user_id );

		return (string) ( $game_data['active_character_id'] ?? '' );
	}
}

/**
 * Helper: strict UUID validator.
 */
if ( ! function_exists( 'cyber_is_valid_uuid' ) ) {
	function cyber_is_valid_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}

/**
 * Helper: call a Supabase RPC function via wp_remote_post().
 *
 * Uses SERVICE KEY — mutating RPCs (cyber_sync_deck, cyber_sync_draw,
 * cyber_upgrade_buffer_card) run server-side and must bypass RLS.
 * Caller is responsible for ownership/auth checks before invoking.
 */
if ( ! function_exists( 'cyber_call_rpc' ) ) {
	function cyber_call_rpc( string $function_name, array $params = [] ): ?array {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			error_log( 'TW cyber_call_rpc: tw_supabase_url() not available.' );
			return null;
		}

		$supabase_url = tw_supabase_url();
		if ( empty( $supabase_url ) ) {
			error_log( 'TW cyber_call_rpc: Missing Supabase URL.' );
			return null;
		}

		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW cyber_call_rpc: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			error_log( 'TW cyber_call_rpc: No Supabase key available.' );
			return null;
		}

		if ( empty( $key ) ) {
			error_log( 'TW cyber_call_rpc: Empty Supabase key.' );
			return null;
		}

		$endpoint = trailingslashit( $supabase_url ) . 'rest/v1/rpc/' . rawurlencode( $function_name );

		$response = wp_remote_post(
			$endpoint,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
				],
				'body'    => wp_json_encode( $params ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'TW cyber_call_rpc error [' . $function_name . ']: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW cyber_call_rpc HTTP ' . $code . ' [' . $function_name . ']: ' . $body );
			return null;
		}

		if ( '' === $body ) {
			return [];
		}

		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			error_log( 'TW cyber_call_rpc JSON error [' . $function_name . ']: ' . json_last_error_msg() );
			return null;
		}

		return is_array( $data ) ? $data : [];
	}
}

/**
 * Helper: PATCH a card location in cyber_character_buffer.
 *
 * Uses SERVICE KEY — server-side write; ownership is verified by
 * handle_use_buffer_card() before this is called.
 */
if ( ! function_exists( 'cyber_update_supabase_location' ) ) {
	function cyber_update_supabase_location( string $instance_id, string $location ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			error_log( 'TW cyber_update_supabase_location: tw_supabase_url() not available.' );
			return false;
		}

		if ( ! cyber_is_valid_uuid( $instance_id ) ) {
			error_log( 'TW cyber_update_supabase_location: Invalid UUID.' );
			return false;
		}

		$supabase_url = tw_supabase_url();
		if ( empty( $supabase_url ) ) {
			error_log( 'TW cyber_update_supabase_location: Missing Supabase URL.' );
			return false;
		}

		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW cyber_update_supabase_location: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			error_log( 'TW cyber_update_supabase_location: No Supabase key available.' );
			return false;
		}

		if ( empty( $key ) ) {
			error_log( 'TW cyber_update_supabase_location: Empty Supabase key.' );
			return false;
		}

		$endpoint = trailingslashit( $supabase_url ) . 'rest/v1/cyber_character_buffer?id=eq.' . rawurlencode( $instance_id );

		$response = wp_remote_request(
			$endpoint,
			[
				'method'  => 'PATCH',
				'headers' => [
					'Content-Type'  => 'application/json',
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
					'Prefer'        => 'return=minimal',
				],
				'body'    => wp_json_encode(
					[
						'location' => sanitize_text_field( $location ),
					]
				),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'TW cyber_update_supabase_location error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW cyber_update_supabase_location HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		return true;
	}
}

/**
 * AJAX: save active deck via RPC.
 */
add_action( 'wp_ajax_save_cyber_deck_rpc', 'handle_save_cyber_deck_rpc' );

if ( ! function_exists( 'handle_save_cyber_deck_rpc' ) ) {
	function handle_save_cyber_deck_rpc(): void {
		check_ajax_referer( 'cyber_deck_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
			return;
		}

		$character_id = get_cyber_character_id_by_wp_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid or missing active character.' ], 400 );
			return;
		}

		$raw_active_ids = wp_unslash( $_POST['active_ids'] ?? '[]' );
		$active_ids     = json_decode( $raw_active_ids, true );

		if ( ! is_array( $active_ids ) ) {
			wp_send_json_error( [ 'message' => 'Invalid deck payload.' ], 400 );
			return;
		}

		$sanitized_ids = array_values(
			array_filter(
				array_map(
					static function ( $id ): string {
						return sanitize_text_field( (string) $id );
					},
					$active_ids
				),
				'cyber_is_valid_uuid'
			)
		);

		if ( empty( $sanitized_ids ) && ! empty( $active_ids ) ) {
			wp_send_json_error( [ 'message' => 'Invalid card IDs.' ], 400 );
			return;
		}

		if ( ! empty( $sanitized_ids ) ) {
			$owned_cards = tw_supabase_get(
				'cyber_character_deck_cards',
				[
					'character_id' => 'eq.' . $character_id,
					'id'           => 'in.(' . implode( ',', $sanitized_ids ) . ')',
					'select'       => 'id',
				]
			);

			if ( is_wp_error( $owned_cards ) ) {
				wp_send_json_error( [ 'message' => 'Unable to verify deck ownership.' ], 502 );
				return;
			}

			$owned_ids = array_column( is_array( $owned_cards ) ? $owned_cards : [], 'id' );

			$verified_ids = array_values(
				array_filter(
					$sanitized_ids,
					static function ( string $id ) use ( $owned_ids ): bool {
						return in_array( $id, $owned_ids, true );
					}
				)
			);

			if ( count( $verified_ids ) !== count( $sanitized_ids ) ) {
				error_log(
					sprintf(
						'NeoWeaver security: user %d tried to sync unowned card IDs for character %s.',
						$user_id,
						$character_id
					)
				);

				wp_send_json_error( [ 'message' => 'One or more cards do not belong to this character.' ], 403 );
				return;
			}
		} else {
			$verified_ids = [];
		}

		$result = cyber_call_rpc(
			'cyber_sync_deck',
			[
				'p_character_id' => $character_id,
				'p_active_ids'   => $verified_ids,
			]
		);

		if ( null === $result ) {
			wp_send_json_error( [ 'message' => 'Deck sync failed.' ], 502 );
			return;
		}

		wp_send_json_success( $result );
	}
}

/**
 * AJAX: use a buffer card and draw a new one.
 */
add_action( 'wp_ajax_use_buffer_card', 'handle_use_buffer_card' );

if ( ! function_exists( 'handle_use_buffer_card' ) ) {
	function handle_use_buffer_card(): void {
		check_ajax_referer( 'use_card_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
			return;
		}

		$character_id = get_cyber_character_id_by_wp_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'No active character found.' ], 400 );
			return;
		}

		$instance_id = sanitize_text_field( (string) ( $_POST['instance_id'] ?? '' ) );
		if ( ! cyber_is_valid_uuid( $instance_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid instance_id.' ], 400 );
			return;
		}

		$ownership = tw_supabase_get(
			'cyber_character_buffer',
			[
				'id'           => 'eq.' . $instance_id,
				'character_id' => 'eq.' . $character_id,
				'select'       => 'id',
				'limit'        => 1,
			]
		);

		if ( is_wp_error( $ownership ) ) {
			wp_send_json_error( [ 'message' => 'Unable to verify card ownership.' ], 502 );
			return;
		}

		if ( empty( $ownership ) ) {
			wp_send_json_error( [ 'message' => 'Card not found or not owned by current character.' ], 403 );
			return;
		}

		$updated = cyber_update_supabase_location( $instance_id, 'discard' );
		if ( ! $updated ) {
			wp_send_json_error( [ 'message' => 'Failed to discard the card.' ], 502 );
			return;
		}

		$new_card_data = cyber_call_rpc(
			'cyber_sync_draw',
			[
				'p_character_id' => $character_id,
			]
		);

		if ( null === $new_card_data ) {
			wp_send_json_error( [ 'message' => 'Draw RPC failed.' ], 502 );
			return;
		}

		if ( ! empty( $new_card_data[0] ) ) {
			wp_send_json_success( $new_card_data[0] );
			return;
		}

		wp_send_json_error( [ 'message' => 'No cards left to draw even after reshuffle.' ], 404 );
	}
}

/**
 * AJAX: foundry upgrade.
 */
add_action( 'wp_ajax_foundry_upgrade', 'handle_foundry_upgrade' );

if ( ! function_exists( 'handle_foundry_upgrade' ) ) {
	function handle_foundry_upgrade(): void {
		check_ajax_referer( 'foundry_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
			return;
		}

		$character_id = get_cyber_character_id_by_wp_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'Character not found.' ], 400 );
			return;
		}

		$instance_id = sanitize_text_field( (string) ( $_POST['instance_id'] ?? '' ) );
		if ( ! cyber_is_valid_uuid( $instance_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid instance_id.' ], 400 );
			return;
		}

		$data = cyber_call_rpc(
			'cyber_upgrade_buffer_card',
			[
				'p_character_id' => $character_id,
				'p_instance_id'  => $instance_id,
			]
		);

		if ( null === $data ) {
			wp_send_json_error( [ 'message' => 'RPC call failed. Please try again.' ], 502 );
			return;
		}

		if ( isset( $data['status'] ) && 'success' === $data['status'] ) {
			wp_send_json_success(
				[
					'message'   => $data['message'] ?? 'Upgrade successful.',
					'new_level' => $data['new_level'] ?? null,
				]
			);
			return;
		}

		wp_send_json_error(
			[
				'message' => $data['message'] ?? 'Upgrade failed.',
			],
			400
		);
	}
}
