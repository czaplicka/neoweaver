<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pobiera postacie aktualnego użytkownika z cyber_characters.
 */
if ( ! function_exists( 'tw_get_user_characters' ) ) {
	function tw_get_user_characters( $user_id ) {
		$rows = tw_supabase_get(
			'cyber_characters',
			array(
				'wp_user_id' => 'eq.' . (int) $user_id,
				'select'     => 'id,name,lvl,avatar',
				'order'      => 'name.asc',
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();

		foreach ( $rows as $row ) {
			$results[] = (object) $row;
		}

		return $results;
	}
}

/**
 * Pobiera achievementy gracza przez RPC get_player_achievements().
 */
if ( ! function_exists( 'tw_get_player_achievements' ) ) {
	function tw_get_player_achievements( $user_id, $char_id = null, $type = 'all' ) {
		$params = array(
			'p_user_id' => (int) $user_id,
			'p_type'    => in_array( $type, array( 'all', 'earned' ), true ) ? $type : 'all',
		);

		if ( ! empty( $char_id ) ) {
			$params['p_character_id'] = (string) $char_id;
		}

		$rows = array();

		if ( function_exists( 'tw_supabase_rpc' ) ) {
			$rows = tw_supabase_rpc( 'get_player_achievements', $params );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();

		foreach ( $rows as $row ) {
			$results[] = (object) $row;
		}

		return $results;
	}
}

if ( ! function_exists( 'tw_get_achievement_color' ) ) {
	function tw_get_achievement_color( $category = '', $scope = 'account', $bg_color = '' ) {
		if ( ! empty( $bg_color ) ) {
			return $bg_color;
		}

		$colors = array(
			'system'      => '#8b5cf6',
			'exploration' => '#14b8a6',
			'social'      => '#3b82f6',
			'progression' => '#f59e0b',
			'mission'     => '#ef4444',
			'loot'        => '#a855f7',
			'secret'      => '#64748b',
		);

		if ( isset( $colors[ $category ] ) ) {
			return $colors[ $category ];
		}

		return ( 'character' === $scope ) ? '#0ea5e9' : '#84cc16';
	}
}

if ( ! function_exists( 'render_player_achievements' ) ) {
	function render_player_achievements( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view achievements.', 'neoweaver' ) . '</p>';
		}

		wp_enqueue_style(
			'neoweaver-achievements',
			trailingslashit( NW_PLUGIN_URL ) . 'assets/css/public/achievements.css',
			array(),
			defined( 'NW_VERSION' ) ? NW_VERSION : '1.0.0'
		);

		wp_enqueue_script(
			'lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			array(),
			'0.468.0',
			true
		);

		wp_enqueue_script(
			'achievements-script',
			trailingslashit( NW_PLUGIN_URL ) . 'assets/js/public/achievements.js',
			array( 'jquery', 'lucide' ),
			defined( 'NW_VERSION' ) ? NW_VERSION : '1.0.0',
			true
		);

		$a = shortcode_atts(
			array(
				'type'    => 'all',
				'user_id' => get_current_user_id(),
				'char_id' => null,
			),
			$atts
		);

		$current_user_id = get_current_user_id();
		$requested_id    = isset( $a['user_id'] ) ? (int) $a['user_id'] : $current_user_id;

		if ( current_user_can( 'manage_options' ) ) {
			$profile_user_id = $requested_id > 0 ? $requested_id : $current_user_id;
		} else {
			$profile_user_id = $current_user_id;
		}

		$selected_char_id = null;

		if ( ! empty( $_GET['char_id'] ) ) {
			$selected_char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ) );
		} elseif ( ! empty( $a['char_id'] ) ) {
			$selected_char_id = sanitize_text_field( (string) $a['char_id'] );
		}

		$characters = tw_get_user_characters( $profile_user_id );

		if ( ! empty( $selected_char_id ) && ! empty( $characters ) ) {
			$allowed_char_ids = array_map(
				static function ( $char ) {
					return (string) $char->id;
				},
				$characters
			);

			if ( ! in_array( $selected_char_id, $allowed_char_ids, true ) ) {
				$selected_char_id = null;
			}
		}

		$results = tw_get_player_achievements( $profile_user_id, $selected_char_id, $a['type'] );

		if ( empty( $results ) ) {
			return '<p>' . esc_html__( 'No achievements to display.', 'neoweaver' ) . '</p>';
		}

		$output = '';

		if ( ! empty( $characters ) ) {
			$output .= '<form method="get" class="ach-filter-form">';

			foreach ( $_GET as $key => $value ) {
				if ( 'char_id' === $key ) {
					continue;
				}

				if ( is_scalar( $value ) ) {
					$output .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '">';
				}
			}

			$output .= '<label for="ach-char-select" class="ach-filter-label">' . esc_html__( 'Character view', 'neoweaver' ) . '</label>';
			$output .= '<select id="ach-char-select" name="char_id">';
			$output .= '<option value="">' . esc_html__( 'Account only', 'neoweaver' ) . '</option>';

			foreach ( $characters as $char ) {
				$selected = selected( $selected_char_id, $char->id, false );
				$label    = $char->name;

				if ( isset( $char->lvl ) ) {
					$label .= ' (Lv. ' . (int) $char->lvl . ')';
				}

				$output .= '<option value="' . esc_attr( $char->id ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
			}

			$output .= '</select>';
			$output .= '</form>';
		}

		$output .= '<div class="achievements-grid">';

		foreach ( $results as $ach ) {
			$is_unlocked = ! empty( $ach->is_unlocked );
			$percent     = $is_unlocked ? 100 : ( isset( $ach->progress_percent ) ? (float) $ach->progress_percent : 0.0 );

			$scope  = $ach->scope ?? 'account';
			$status = $ach->css_status ?? ( $is_unlocked ? 'status-unlocked' : 'status-locked' );
			$icon   = ! empty( $ach->icon_slug ) ? $ach->icon_slug : 'badge-check';

			$bg_color = tw_get_achievement_color( $ach->category ?? '', $scope, $ach->bg_color ?? '' );
			$style    = '--bg-color: ' . esc_attr( $bg_color ) . '; --prog-percent: ' . esc_attr( $percent ) . '%;';

			$title       = $ach->display_title ?? 'Unknown achievement';
			$description = $ach->display_description ?? '';

			$shape_class = ( 'account' === $scope ) ? 'ach-shape-hex' : '';
			$badge_label = ( 'status-hidden' === $status )
				? 'SECRET'
				: ( 'character' === $scope ? 'CHARACTER' : 'ACCOUNT' );

			$output .= '<div class="ach-card scope-' . esc_attr( $scope ) . ' ' . esc_attr( trim( $status . ' ' . $shape_class ) ) . '" style="' . $style . '">';
			$output .= '<div class="ach-top-row">';
			$output .= '<span class="ach-badge">' . esc_html( $badge_label ) . '</span>';

			if ( ! $is_unlocked ) {
				$output .= '<span class="ach-percent">' . esc_html( (int) round( $percent ) ) . '%</span>';
			} else {
				$output .= '<span class="ach-percent ach-percent-done">100%</span>';
			}

			$output .= '</div>';
			$output .= '<div class="ach-icon"><i data-lucide="' . esc_attr( $icon ) . '" aria-hidden="true"></i></div>';
			$output .= '<div class="ach-title">' . esc_html( $title ) . '</div>';

			if ( '' !== $description ) {
				$output .= '<div class="ach-desc">' . esc_html( $description ) . '</div>';
			}

			$goal = isset( $ach->goal ) ? (int) $ach->goal : 0;

			if ( ! $is_unlocked && 'status-hidden' !== $status && $goal > 1 ) {
				$current = isset( $ach->current_progress ) ? (int) $ach->current_progress : 0;
				$output .= '<div class="ach-progress">' . esc_html( $current . '/' . $goal ) . '</div>';
			}

			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}
}

add_shortcode( 'achievements', 'render_player_achievements' );
