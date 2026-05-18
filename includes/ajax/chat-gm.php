<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — AJAX HANDLER: tw_ajax_chat_gm
 *
 * Przyjmuje wiadomość gracza z czatu, przepuszcza przez cały pipeline:
 *   1. Walidacja / auth
 *   2. Router (tw_ai_router) → protokół
 *   3. Jeśli META → zwróć dane bez wywołania GM
 *   4. Context builder (tw_ai_build_context) → dane z Supabase
 *   5. Historia ostatnich N wiadomości z cyber_chat_messages
 *   6. GM call (tw_ai_gm) → narracja + tagi
 *   7. Zapis odpowiedzi GM do cyber_chat_messages
 *   8. Zwrot JSON z tekstem i tagami do JS
 *
 * Akcja WordPress AJAX: tw_chat_gm
 * Nonce name: tw_chat_nonce (lokaliz. jako twChatData.nonce w chat-realtime.php)
 */

add_action( 'wp_ajax_tw_chat_gm', 'tw_ajax_chat_gm' );

if ( ! function_exists( 'tw_ajax_chat_gm' ) ) {
	function tw_ajax_chat_gm(): void {

		// --- 1. Auth ---
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			return;
		}

		check_ajax_referer( 'tw_chat_nonce', 'nonce' );

		$user_message = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
		$char_id      = sanitize_text_field( wp_unslash( $_POST['char_id'] ?? '' ) );
		$channel_id   = sanitize_text_field( wp_unslash( $_POST['channel_id'] ?? '' ) );
		$session_id   = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$campaign_id  = sanitize_text_field( wp_unslash( $_POST['campaign_id'] ?? '' ) );

		if ( empty( $user_message ) || empty( $char_id ) || empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing required fields' ], 400 );
			return;
		}

		// Weryfikacja UUID (podstawowa)
		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		if ( ! preg_match( $uuid_pattern, $char_id ) || ! preg_match( $uuid_pattern, $channel_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid ID format' ], 400 );
			return;
		}

		// Weryfikacja czy postać należy do zalogowanego usera
		$owner_check = tw_supabase_get(
			'cyber_characters',
			[ 'id' => 'eq.' . $char_id, 'wp_user_id' => 'eq.' . get_current_user_id(), 'select' => 'id', 'limit' => 1 ]
		);
		if ( is_wp_error( $owner_check ) || empty( $owner_check ) ) {
			wp_send_json_error( [ 'message' => 'Character not found or access denied' ], 403 );
			return;
		}

		// --- 2. Router ---
		$protocol = 'UNKNOWN';
		if ( function_exists( 'tw_ai_router' ) ) {
			$protocol = tw_ai_router( $user_message );
		}

		// --- 3. META → brak GM, dane bezpośrednio ---
		if ( $protocol === 'META' ) {
			$meta_ctx = function_exists( 'tw_ai_build_context' )
				? tw_ai_build_context( $char_id, 'META' )
				: [];
			$char = $meta_ctx['char'] ?? [];
			$meta_text = sprintf(
				'HP: %d/%d | MP: %d | Gold: %d | Satiety: %d | Hydration: %d',
				(int)( $char['currenthp'] ?? 0 ),
				(int)( $char['maxhp'] ?? 0 ),
				(int)( $char['mp'] ?? 0 ),
				(int)( $char['gold'] ?? 0 ),
				(int)( $char['satiety'] ?? 0 ),
				(int)( $char['hydration'] ?? 0 )
			);
			// Zapis do czatu
			tw_chat_save_message( $channel_id, $char_id, 'gm', $meta_text );
			wp_send_json_success( [ 'text' => $meta_text, 'tags' => [], 'protocol' => 'META' ] );
			return;
		}

		// --- 4. Context builder ---
		$context = [];
		if ( function_exists( 'tw_ai_build_context' ) ) {
			$context = tw_ai_build_context( $char_id, $protocol );
		}

		// --- 5. Historia konwersacji (ostatnie 14 wiadomości) ---
		$history      = [];
		$history_rows = tw_supabase_get(
			'cyber_chat_messages',
			[
				'channel_id'  => 'eq.' . $channel_id,
				'select'      => 'content,message_type',
				'order'       => 'created_at.desc',
				'limit'       => 14,
			]
		);
		if ( ! is_wp_error( $history_rows ) && ! empty( $history_rows ) ) {
			// Odwracamy — Supabase zwrócił desc, OpenAI potrzebuje asc
			$history_rows = array_reverse( $history_rows );
			foreach ( $history_rows as $row ) {
				$role      = ( $row['message_type'] === 'player' ) ? 'user' : 'assistant';
				$history[] = [ 'role' => $role, 'content' => $row['content'] ];
			}
		}

		// --- 6. GM Call ---
		if ( ! function_exists( 'tw_ai_gm' ) ) {
			wp_send_json_error( [ 'message' => 'AI engine unavailable' ], 503 );
			return;
		}

		$ids = [
			'char_id'     => $char_id,
			'session_id'  => $session_id  ?: null,
			'campaign_id' => $campaign_id ?: null,
			'channel_id'  => $channel_id,
		];

		$gm_result = tw_ai_gm( $context, $history, $user_message, $ids );

		if ( is_wp_error( $gm_result ) ) {
			error_log( 'TW tw_ajax_chat_gm GM error: ' . $gm_result->get_error_message() );
			wp_send_json_error( [ 'message' => 'GM unavailable, try again' ], 503 );
			return;
		}

		$gm_text = $gm_result['text'];
		$gm_tags = $gm_result['tags'];

		// --- 7. Zapis odpowiedzi GM do Supabase ---
		tw_chat_save_message( $channel_id, $char_id, 'gm', $gm_text );

		// --- 8. Zwrot JSON ---
		wp_send_json_success( [
			'text'     => $gm_text,
			'tags'     => $gm_tags,
			'protocol' => $protocol,
		] );
	}
}

/**
 * Helper: zapisuje wiadomość GM do cyber_chat_messages przez Supabase.
 * Używa tw_supabase_request() z supabase-helpers.php.
 */
if ( ! function_exists( 'tw_chat_save_message' ) ) {
	function tw_chat_save_message( string $channel_id, string $char_id, string $type, string $content ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			error_log( 'TW tw_chat_save_message: tw_supabase_request() niedostępne.' );
			return;
		}

		$result = tw_supabase_request(
			'POST',
			'cyber_chat_messages',
			[],
			[
				'channel_id'   => $channel_id,
				'player_id'    => $char_id,
				'message_type' => $type,
				'content'      => $content,
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_chat_save_message Supabase error: ' . $result->get_error_message() );
		}
	}
}
