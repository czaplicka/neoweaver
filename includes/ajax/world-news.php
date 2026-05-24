<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_get_cyber_world_news_ajax' ) ) {
	function tw_get_cyber_world_news_ajax(): void {
		check_ajax_referer( 'tw_world_news_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => 'Unauthorized.',
				),
				401
			);
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Supabase config missing.',
				),
				500
			);
			return;
		}

		$world_id     = sanitize_text_field( (string) ( $_POST['world_id'] ?? '' ) );
		$character_id = sanitize_text_field( (string) ( $_POST['character_id'] ?? '' ) );
		$current_day  = intval( $_POST['current_day'] ?? 0 );
		$current_hour = intval( $_POST['current_hour'] ?? 0 );
		$clearance    = isset( $_POST['clearance'] ) ? intval( $_POST['clearance'] ) : 0;

		if ( '' === $world_id || '' === $character_id ) {
			wp_send_json_error(
				array(
					'message' => 'Missing required fields.',
				),
				400
			);
			return;
		}

		$supa_url = trailingslashit( tw_supabase_url() );
		$supa_key = tw_supabase_anon_key();

		if ( empty( $supa_url ) || empty( $supa_key ) ) {
			wp_send_json_error(
				array(
					'message' => 'Supabase config missing.',
				),
				500
			);
			return;
		}

		$url = add_query_arg(
			array(
				'world_id'        => 'eq.' . rawurlencode( $world_id ),
				'is_active'       => 'eq.true',
				'clearance_level' => 'lte.' . $clearance,
				'or'              => '(game_day.lt.' . $current_day . ',and(game_day.eq.' . $current_day . ',game_hour.lte.' . $current_hour . '))',
				'order'           => 'game_day.desc,game_hour.desc',
			),
			$supa_url . 'rest/v1/cyber_world_news'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => 'Connection error.',
				),
				500
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => 'API error.',
					'status'  => $status_code,
				),
				$status_code
			);
			return;
		}

		$news = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $news ) ) {
			wp_send_json_success(
				array(
					'news'         => array(),
					'unread_count' => 0,
				)
			);
			return;
		}

		$unread_count = 0;

		foreach ( $news as &$item ) {
			$read_by = $item['read_by'] ?? array();

			if ( is_string( $read_by ) ) {
				$read_by = json_decode( $read_by, true );
			}

			$read_by        = is_array( $read_by ) ? $read_by : array();
			$item['is_new'] = ! in_array( $character_id, $read_by, true );

			if ( $item['is_new'] ) {
				$unread_count++;
			}
		}
		unset( $item );

		wp_send_json_success(
			array(
				'news'         => $news,
				'unread_count' => $unread_count,
			)
		);
	}

	add_action( 'wp_ajax_get_cyber_news', 'tw_get_cyber_world_news_ajax' );
}
