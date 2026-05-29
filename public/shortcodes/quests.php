<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_time_ago' ) ) {
	function tw_time_ago( $timestamp ): string {
		$created = strtotime( (string) $timestamp );

		if ( ! $created ) {
			return '';
		}

		$diff = time() - $created;

		if ( $diff < 60 ) {
			return 'just now';
		}

		if ( $diff < 3600 ) {
			return floor( $diff / 60 ) . ' min ago';
		}

		if ( $diff < 86400 ) {
			return floor( $diff / 3600 ) . ' h ago';
		}

		return floor( $diff / 86400 ) . ' d ago';
	}
}

if ( ! function_exists( 'tw_render_quest_card' ) ) {
	function tw_render_quest_card( array $quest ): string {
		$scenario = $quest['cyber_scenarios'] ?? null;

		if ( ! $scenario || ! is_array( $scenario ) ) {
			return '';
		}

		$quest_type = (string) ( $scenario['type'] ?? 'side' );
		$quest_name = (string) ( $scenario['name'] ?? 'Unknown objective' );
		$quest_tags = $scenario['tags'] ?? '';
		$category   = (string) ( $scenario['category'] ?? 'UNCATEGORIZED' );
		$goal       = (string) ( $scenario['goal'] ?? 'N/A' );

		$area      = $scenario['cyber_areas'] ?? null;
		$area_name = is_array( $area ) ? (string) ( $area['name'] ?? 'Unknown area' ) : 'Unknown area';

		$created_at = $quest['created_at'] ?? null;
		$time_ago   = $created_at ? tw_time_ago( $created_at ) : '';

		$tags_html = '';

		if ( ! empty( $quest_tags ) ) {
			$tags_list = is_array( $quest_tags ) ? $quest_tags : explode( ',', (string) $quest_tags );

			foreach ( $tags_list as $tag ) {
				$tag = trim( (string) $tag );

				if ( '' !== $tag ) {
					$tags_html .= '<span class="tw-tag">' . esc_html( $tag ) . '</span>';
				}
			}
		}

		return sprintf(
			"<div class='scenario-card'>
				<div class='quest-header'>// OBJECTIVE: %s - %s</div>
				<div class='quest-name-line'>%s</div>
				<div class='quest-tags-line'>%s</div>
				<div class='quest-what'>// WHAT: %s</div>
				<div class='quest-where'>// WHERE: %s</div>
				<div class='quest-time'>// TIME: %s</div>
			</div>",
			esc_html( strtoupper( $category ) ),
			esc_html( strtoupper( $quest_type ) ),
			esc_html( $quest_name ),
			$tags_html,
			esc_html( $goal ),
			esc_html( $area_name ),
			esc_html( $time_ago )
		);
	}
}

if ( ! function_exists( 'tw_verify_character_ownership' ) ) {
	/**
	 * Confirms that $character_id belongs to the current WP user.
	 * Queries Supabase directly — bypasses any cached/transient data.
	 *
	 * @return bool  true = ownership confirmed, false = denied or error.
	 */
	function tw_verify_character_ownership( string $character_id, int $wp_user_id ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return false;
		}

		$url = add_query_arg(
			array(
				'id'         => 'eq.' . rawurlencode( $character_id ),
				'wp_user_id' => 'eq.' . (int) $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			),
			trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_characters'
		);

		$anon_key = tw_supabase_anon_key();
		$res      = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}

		$rows = json_decode( wp_remote_retrieve_body( $res ), true );

		return is_array( $rows ) && ! empty( $rows );
	}
}

if ( ! function_exists( 'tw_display_active_scenarios_shortcode' ) ) {
	function tw_display_active_scenarios_shortcode(): string {
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return '<div class="echo-stream-container">// ERROR: OPERATOR NOT IDENTIFIED</div>';
		}

		if ( ! function_exists( 'tw_get_current_character_id' ) ) {
			return '<div class="echo-stream-container">// ERROR: SESSION HELPER MISSING</div>';
		}

		$character_id = tw_get_current_character_id();

		if ( ! $character_id ) {
			return '<div class="echo-stream-container">// ERROR: NO ACTIVE SESSION DETECTED</div>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<div class="echo-stream-container">// ERROR: SUPABASE CONFIG MISSING</div>';
		}

		// Explicit ownership check — guards against stale transients and disabled RLS.
		if ( ! tw_verify_character_ownership( (string) $character_id, $wp_user_id ) ) {
			return '<div class="echo-stream-container">// ERROR: ACCESS DENIED</div>';
		}

		if ( function_exists( 'tw_enqueue_quests_assets' ) ) {
			tw_enqueue_quests_assets();
		}

		$anon_key = tw_supabase_anon_key();

		$url = add_query_arg(
			array(
				'character_id' => 'eq.' . rawurlencode( (string) $character_id ),
				'select'       => '*,cyber_scenarios(*,cyber_areas(*))',
			),
			trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_active_quests'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				),
				'timeout' => 15,
			)
		);

		$error_card = '<div class="scenario-card scenario-card--error">%s</div>';

		if ( is_wp_error( $response ) ) {
			return sprintf( $error_card, '// CONNECTION ERROR' );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return sprintf( $error_card, '// API ERROR' );
		}

		$quests = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $quests ) || isset( $quests['code'], $quests['message'] ) ) {
			return sprintf( $error_card, '// API ERROR' );
		}

		if ( empty( $quests ) ) {
			return sprintf( $error_card, 'NO OBJECTIVES' );
		}

		$grouped = array_fill_keys( array( 'active', 'completed', 'failed', 'paused' ), array() );

		foreach ( $quests as $quest ) {
			if ( ! is_array( $quest ) ) {
				continue;
			}

			$status = isset( $quest['status'] ) ? (string) $quest['status'] : 'active';
			$key    = array_key_exists( $status, $grouped ) ? $status : 'active';

			$grouped[ $key ][] = $quest;
		}

		$output = '<div class="active-scenarios-container">';

		foreach ( $grouped as $status => $quests_in_group ) {
			if ( empty( $quests_in_group ) ) {
				continue;
			}

			$output .= '<div class="quest-status-header">' . esc_html( strtoupper( $status ) ) . ':</div>';

			foreach ( $quests_in_group as $quest ) {
				$output .= tw_render_quest_card( $quest );
			}
		}

		$output .= '</div>';

		return $output;
	}

	add_shortcode( 'active_scenarios', 'tw_display_active_scenarios_shortcode' );
}
