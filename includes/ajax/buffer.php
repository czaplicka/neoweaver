<?php
/**
 * NeoWeave - AJAX & RPC Buffer Handlers
 *
 * Uses Supabase helpers from includes/supabase-config.php and
 * includes/supabase-helpers.php.
 *
 * Character resolution:
 *   get_cyber_active_session_character_id() — used here, requires active game session
 *   get_cyber_character_id_by_wp_id()       — for character lists/selection, NOT for in-game actions
 * Both are defined in supabase-helpers.php. Do NOT redefine them here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * Sentinel value returned by cyber_call_rpc() to distinguish a hard error
 * (network failure, HTTP 4xx/5xx, JSON parse error) from a legitimate empty
 * array result (e.g. no cards left after reshuffle).
 *
 * Callers must check:  if ( $result === NW_RPC_ERROR ) { ... }
 */
define( 'NW_RPC_ERROR', 'NW_RPC_ERROR_SENTINEL' );

/**
 * Helper: call a Supabase RPC function via wp_remote_post().
 *
 * Returns:
 *   array       — on success (may be empty [])
 *   NW_RPC_ERROR — on any hard error (network, HTTP error, JSON parse fail)
 *
 * BUG 4 FIX: previously returned null on error AND null on JSON-decode of an
 * empty body, making it impossible for callers to distinguish. Now uses the
 * NW_RPC_ERROR sentinel for all error paths and reserves [] for a real empty
 * result from Supabase.
 *
 * Uses SERVICE KEY — mutating RPCs run server-side and must bypass RLS.
 * Caller is responsible for ownership/auth checks before invoking.
 */
if ( ! function_exists( 'cyber_call_rpc' ) ) {
	/**
	 * @return array|string array on success, NW_RPC_ERROR on failure.
	 */
	function cyber_call_rpc( string $function_name, array $params = [] ) {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			error_log( 'TW cyber_call_rpc: tw_supabase_url() not available.' );
			return NW_RPC_ERROR;
		}

		$supabase_url = tw_supabase_url();
		if ( empty( $supabase_url ) ) {
			error_log( 'TW cyber_call_rpc: Missing Supabase URL.' );
			return NW_RPC_ERROR;
		}

		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW cyber_call_rpc: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			error_log( 'TW cyber_call_rpc: No Supabase key available.' );
			return NW_RPC_ERROR;
		}

		if ( empty( $key ) ) {
			error_log( 'TW cyber_call_rpc: Empty Supabase key.' );
			return NW_RPC_ERROR;
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
			return NW_RPC_ERROR;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW cyber_call_rpc HTTP ' . $code . ' [' . $function_name . ']: ' . $body );
			return NW_RPC_ERROR;
		}

		if ( '' === $body ) {
			return [];
		}

		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			error_log( 'TW cyber_call_rpc JSON error [' . $function_name . ']: ' . json_last_error_msg() );
			return NW_RPC_ERROR;
		}

		return is_array( $data ) ? $data : [];
	}
}

/**
 * Helper: PATCH a card location in cyber_character_buffer.
 *
 * BUG 5 FIX: UUID hyphens must NOT be percent-encoded. rawurlencode() turns
 * "3f2504e0-..." into "3f2504e0%2D..." which PostgREST never matches.
 * UUIDs are already URL-safe — pass them verbatim in the query string.
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

		// BUG 5 FIX: UUID is already URL-safe (hex + hyphens). Do NOT rawurlencode()
		// as it converts hyphens to %2D, producing a filter PostgREST never matches.
		$endpoint = trailingslashit( $supabase_url ) . 'rest/v1/cyber_character_buffer?id=eq.' . $instance_id;

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
 * BUG 3 FIX: Localize use_card_nonce and foundry_nonce to JS.
 *
 * Both nonces are added to the existing twGameConfig object used by the
 * adventure/game scripts. Hooked on wp_enqueue_scripts at priority 20
 * (after the main script is registered) so wp_localize_script() finds it.
 *
 * JS usage:
 *   twGameConfig.use_card_nonce   // for use_buffer_card AJAX action
 *   twGameConfig.foundry_nonce    // for foundry_upgrade AJAX action
 */
if ( ! function_exists( 'nw_localize_buffer_nonces' ) ) {
	function nw_localize_buffer_nonces(): void {
		// Only needed on front-end pages where the game scripts load.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Piggyback on the adventure-js handle (registered by deck-scenarios.php).
		// If it hasn't been enqueued yet on this page, fall back to nw-game-ui
		// or any other game script handle present.
		$handles = [ 'adventure-js', 'nw-game-ui', 'nw-buffer' ];
		$registered = false;
		foreach ( $handles as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'registered' ) ) {
				wp_localize_script(
					$handle,
					'twGameConfig',
					[
						'use_card_nonce' => wp_create_nonce( 'use_card_nonce' ),
						'foundry_nonce'  => wp_create_nonce( 'foundry_nonce' ),
					]
				);
				$registered = true;
				break;
			}
		}

		if ( ! $registered ) {
			// No known handle found — inline the nonces so the page always has them.
			wp_add_inline_script(
				'jquery-core',
				'window.twGameConfig = window.twGameConfig || {}; ' .
				'twGameConfig.use_card_nonce = ' . wp_json_encode( wp_create_nonce( 'use_card_nonce' ) ) . '; ' .
				'twGameConfig.foundry_nonce  = ' . wp_json_encode( wp_create_nonce( 'foundry_nonce' ) ) . ';'
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'nw_localize_buffer_nonces', 20 );

/**
 * AJAX: save active deck via RPC.
 *
 * BUG-10 FIX: was 'cyber_deck_nonce' — unified to 'tw_deck_nonce'
 * to match twGameConfig.nonce localized by tw_localize_deck_vars().
 */
add_action( 'wp_ajax_save_cyber_deck_rpc', 'handle_save_cyber_deck_rpc' );

if ( ! function_exists( 'handle_save_cyber_deck_rpc' ) ) {
	function handle_save_cyber_deck_rpc(): void {
		check_ajax_referer( 'tw_deck_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
			return;
		}

		$character_id = get_cyber_active_session_character_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'No active game session found.' ], 400 );
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

		if ( NW_RPC_ERROR === $result ) {
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

		$character_id = get_cyber_active_session_character_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'No active game session found.' ], 400 );
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

		// BUG 4 FIX: NW_RPC_ERROR means the RPC itself failed (network/HTTP error).
		// An empty [] means a legitimate empty draw (deck exhausted after reshuffle).
		if ( NW_RPC_ERROR === $new_card_data ) {
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

		$character_id = get_cyber_active_session_character_id( $user_id );
		if ( ! cyber_is_valid_uuid( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'No active game session found.' ], 400 );
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

		if ( NW_RPC_ERROR === $data ) {
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
