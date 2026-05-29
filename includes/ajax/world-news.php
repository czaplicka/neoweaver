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
		// current_day: 1–9999 (game days); current_hour: 0–23 (hours in a day).
		$current_day  = max( 1,  min( 9999, intval( $_POST['current_day']  ?? 1 ) ) );
		$current_hour = max( 0,  min( 23,   intval( $_POST['current_hour'] ?? 0 ) ) );
		$clearance    = max( 0,  min( 10,   intval( $_POST['clearance']    ?? 0 ) ) );

		if ( '' === $world_id || '' === $character_id ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
			return;
		}

		// tw_supabase_get_admin() uses service key — server-side read bypassing RLS.
		// tw_supabase_get() (anon key) would silently return empty results if RLS
		// requires authenticated access and no JWT is forwarded.
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
		}
		unset( $item );

		wp_send_json_success( [ 'news' => $news, 'unread_count' => $unread_count ] );
	}

	add_action( 'wp_ajax_get_cyber_news',        'tw_get_cyber_world_news_ajax' );
	// nopriv — zwraca 401 dzięki is_user_logged_in() na początku funkcji
	add_action( 'wp_ajax_nopriv_get_cyber_news', 'tw_get_cyber_world_news_ajax' );
}
