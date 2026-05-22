<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'shortcode_neoweave_my_world_archive' ) ) {
	function shortcode_neoweave_my_world_archive( $atts ): string {
		$current_user_id = get_current_user_id();

		if ( ! $current_user_id ) {
			return '[ACCESS_DENIED]: No Operator signature detected.';
		}

		$atts = shortcode_atts(
			array(
				'world' => '',
			),
			$atts,
			'neoweave_my_world_archive'
		);

		$world_id = ! empty( $atts['world'] )
			? sanitize_text_field( (string) $atts['world'] )
			: sanitize_text_field( (string) ( $_GET['world_id'] ?? '' ) );

		if ( ! $world_id ) {
			return '[DATA_ERR]: World Node ID is missing.';
		}

		if ( ! preg_match( '/^[0-9a-f\\-]{1,64}$/i', $world_id ) ) {
			return '[DATA_ERR]: World Node ID format is invalid.';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '[CONFIG_ERR]: Supabase credentials are not configured.';
		}

		$supa_url = tw_supabase_url();
		$supa_key = tw_supabase_anon_key();

		if ( empty( $supa_url ) || empty( $supa_key ) ) {
			return '[CONFIG_ERR]: Supabase credentials are not configured.';
		}

		if ( function_exists( 'tw_enqueue_world_archive_assets' ) ) {
			tw_enqueue_world_archive_assets();
		}

		$cache_key   = 'nw_archive_' . $current_user_id . '_' . md5( $world_id );
		$cache_group = 'neoweave';

		$my_dead_agents = wp_cache_get( $cache_key, $cache_group );

		if ( false === $my_dead_agents ) {
			$query_params = http_build_query(
				array(
					'select'     => 'name,notes,created_at,lvl',
					'wp_user_id' => 'eq.' . $current_user_id,
					'world_id'   => 'eq.' . $world_id,
					'status'     => 'eq.DEAD',
				)
			);

			$query_url = trailingslashit( $supa_url ) . 'rest/v1/cyber_characters?' . $query_params;

			$response = wp_remote_get(
				$query_url,
				array(
					'headers' => array(
						'apikey'        => $supa_key,
						'Authorization' => 'Bearer ' . $supa_key,
					),
					'timeout' => 8,
				)
			);

			if ( is_wp_error( $response ) ) {
				return '[SIGNAL_FAILURE]: Unable to sync with Supabase.';
			}

			$http_code = wp_remote_retrieve_response_code( $response );

			if ( $http_code < 200 || $http_code >= 300 ) {
				return '[SIGNAL_FAILURE]: Supabase returned HTTP ' . intval( $http_code ) . '.';
			}

			$body = wp_remote_retrieve_body( $response );
			$my_dead_agents = json_decode( $body, true );

			if ( ! is_array( $my_dead_agents ) ) {
				return '[DATA_ERR]: Invalid response received from archive.';
			}

			wp_cache_set( $cache_key, $my_dead_agents, $cache_group, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $my_dead_agents ) ) {
			return '<div class="operator-archive operator-archive--empty">[ARCHIVE_EMPTY]: No personal casualties recorded in this Node.</div>';
		}

		$parts   = array();
		$parts[] = '<div class="operator-archive">';
		$parts[] = '<h2 class="operator-archive__title">&gt; PERSONAL_DEATH_LOGS // NODE: ' . esc_html( substr( $world_id, 0, 8 ) ) . '</h2>';

		foreach ( $my_dead_agents as $agent ) {
			$created_raw = isset( $agent['created_at'] ) ? strtotime( (string) $agent['created_at'] ) : false;
			$timestamp   = $created_raw ? wp_date( 'Y-m-d H:i', $created_raw ) : 'UNKNOWN';
			$lvl         = intval( $agent['lvl'] ?? 0 );
			$name        = esc_html( (string) ( $agent['name'] ?? '???' ) );
			$notes       = ! empty( $agent['notes'] )
				? esc_html( (string) $agent['notes'] )
				: '[NO_LAST_WORDS_RECORDED]';

			$parts[] = '<div class="archive-entry">';
			$parts[] = '<div class="archive-entry__header">AGENT: ' . $name . ' | LVL: ' . $lvl . ' | TERMINATED: ' . esc_html( $timestamp ) . '</div>';
			$parts[] = '<div class="archive-entry__notes">' . $notes . '</div>';
			$parts[] = '</div>';
		}

		$parts[] = '<div class="operator-archive__footer">--- END OF ARCHIVE ENTRANCE ---</div>';
		$parts[] = '</div>';

		return implode( "\n", $parts );
	}

	add_shortcode( 'neoweave_my_world_archive', 'shortcode_neoweave_my_world_archive' );
}
