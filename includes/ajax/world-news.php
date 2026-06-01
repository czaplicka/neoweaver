<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_get_cyber_world_news_ajax' ) ) {
	function tw_get_cyber_world_news_ajax(): void {
		// Login check first — prevents WordPress from dying with -1 on nonce failure
		// for unauthenticated requests, ensuring a clean JSON error response.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
			return;
		}

		check_ajax_referer( 'tw_world_news_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase config missing.' ], 500 );
			return;
		}

		$world_id     = nw_sanitize_uuid( (string) ( $_POST['world_id']     ?? '' ) );
		$character_id = nw_sanitize_uuid( (string) ( $_POST['character_id'] ?? '' ) );

		// Clamp to realistic in-game ranges to prevent timeline-bypass attacks.
		$current_day  = max( 1,  min( 9999, intval( $_POST['current_day']  ?? 1 ) ) );
		$current_hour = max( 0,  min( 23,   intval( $_POST['current_hour'] ?? 0 ) ) );
		$clearance    = max( 0,  min( 10,   intval( $_POST['clearance']    ?? 0 ) ) );

		if ( '' === $world_id || '' === $character_id ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
			return;
		}

		// ── BUG 17 FIX: verify character belongs to the calling user ──────────
		// $character_id was taken from $_POST without confirming ownership.
		// Any logged-in user could pass any UUID and read news gated by that
		// character's clearance_level. Verify via Supabase before proceeding.
		$current_user_id = get_current_user_id();

		$char_row = tw_supabase_get_admin(
			'cyber_characters',
			[
				'id'         => 'eq.' . $character_id,
				'wp_user_id' => 'eq.' . $current_user_id,
				'select'     => 'id,clearance_level',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $char_row ) ) {
			wp_send_json_error( [ 'message' => 'Character lookup failed.' ], 500 );
			return;
		}

		if ( ! is_array( $char_row ) || empty( $char_row[0]['id'] ) ) {
			wp_send_json_error( [ 'message' => 'Access denied.' ], 403 );
			return;
		}

		// Use the clearance_level stored server-side — never trust the client value.
		$clearance = max( 0, min( 10, intval( $char_row[0]['clearance_level'] ?? 0 ) ) );

		// tw_supabase_get_admin() uses service key — server-side read bypassing RLS.
		$news = tw_supabase_get_admin(
			'cyber_world_news',
			[
				'world_id'        => 'eq.' . $world_id,
				'is_active'       => 'eq.true',
				'clearance_level' => 'lte.' . $clearance,
				'or'              => '(game_day.lt.' . $current_day . ',and(game_day.eq.' . $current_day . ',game_hour.lte.' . $current_hour . '))',
				'order'           => 'game_day.desc,game_hour.desc',
			]
		);

		if ( is_wp_error( $news ) ) {
			wp_send_json_error( [ 'message' => 'Connection error.', 'error' => $news->get_error_message() ], 500 );
			return;
		}

		if ( ! is_array( $news ) ) {
			wp_send_json_success( [ 'news' => [], 'unread_count' => 0 ] );
			return;
		}

		$unread_count = 0;

		foreach ( $news as &$item ) {
			$read_by = $item['read_by'] ?? [];

			if ( is_string( $read_by ) ) {
				$read_by = json_decode( $read_by, true );
			}

			$read_by        = is_array( $read_by ) ? $read_by : [];
			$item['is_new'] = ! in_array( $character_id, $read_by, true );

			if ( $item['is_new'] ) {
				$unread_count++;
			}

			// ── BUG 18 FIX: strip read_by before sending to client ───────────
			// The full read_by array contains character UUIDs of every player
			// who read this item — leaking other players' character IDs.
			// The client only needs is_new (bool); raw UUIDs must stay server-side.
			unset( $item['read_by'] );
		}
		unset( $item );

		wp_send_json_success( [ 'news' => $news, 'unread_count' => $unread_count ] );
	}

	add_action( 'wp_ajax_get_cyber_news',        'tw_get_cyber_world_news_ajax' );
	// nopriv — zwraca 401 dzięki is_user_logged_in() na początku funkcji
	add_action( 'wp_ajax_nopriv_get_cyber_news', 'tw_get_cyber_world_news_ajax' );
}
