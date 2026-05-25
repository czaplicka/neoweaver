<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_get_cyber_world_news_ajax' ) ) {
	function tw_get_cyber_world_news_ajax(): void {
		check_ajax_referer( 'tw_world_news_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_get' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase config missing.' ], 500 );
			return;
		}

		$world_id     = sanitize_text_field( (string) ( $_POST['world_id']     ?? '' ) );
		$character_id = sanitize_text_field( (string) ( $_POST['character_id'] ?? '' ) );
		$current_day  = intval( $_POST['current_day']  ?? 0 );
		$current_hour = intval( $_POST['current_hour'] ?? 0 );
		$clearance    = isset( $_POST['clearance'] ) ? intval( $_POST['clearance'] ) : 0;

		if ( '' === $world_id || '' === $character_id ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
			return;
		}

		// Use service key — server-side read, user is already authenticated via WP session.
		// Anon key would silently return empty results if RLS requires authenticated access.
		$news = tw_supabase_get(
			'cyber_world_news',
			[
				'world_id'        => 'eq.' . $world_id,
				'is_active'       => 'eq.true',
				'clearance_level' => 'lte.' . $clearance,
				'or'              => '(game_day.lt.' . $current_day . ',and(game_day.eq.' . $current_day . ',game_hour.lte.' . $current_hour . '))',
				'order'           => 'game_day.desc,game_hour.desc',
			],
			'service' // pass key type so tw_supabase_get uses tw_supabase_service_key() internally
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

	add_action( 'wp_ajax_get_cyber_news', 'tw_get_cyber_world_news_ajax' );
}
