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
            [
                'wp_user_id' => 'eq.' . (int) $user_id,
                'select'     => 'id,name,lvl,avatar',
                'order'      => 'name.asc',
            ]
        );

        $results = [];
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
        $params = [
            'p_user_id' => (int) $user_id,
            'p_type'    => in_array( $type, [ 'all', 'earned' ], true ) ? $type : 'all',
        ];

        if ( ! empty( $char_id ) ) {
            $params['p_character_id'] = (string) $char_id;
        }

        $rows = [];
        if ( function_exists( 'tw_supabase_rpc' ) ) {
            $rows = tw_supabase_rpc( 'get_player_achievements', $params );
        }

        $results = [];
        foreach ( $rows as $row ) {
            $results[] = (object) $row;
        }

        return $results;
    }
}

function render_player_achievements( $atts ) {
    // Enqueue via wp_footer hook so assets land in <head>/<footer>, not mid-HTML
    add_action( 'wp_footer', static function () {
        wp_enqueue_style(
            'neoweaver-achievements',
            plugin_dir_url( __FILE__ ) . '../assets/css/achievements.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'neoweaver-achievements',
            plugin_dir_url( __FILE__ ) . '../assets/js/achievements.js',
            [],
            '1.0.0',
            true
        );
    }, 5 );

    $a = shortcode_atts(
        [
            'type'    => 'all',
            'user_id' => get_current_user_id(),
            'char_id' => null,
        ],
        $atts
    );

    $current_user_id = (int) $a['user_id'];

    // Wybrana postać: z GET lub z atrybutu shortcode
    $selected_char_id = null;
    if ( ! empty( $_GET['char_id'] ) ) {
        $selected_char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ) );
    } elseif ( ! empty( $a['char_id'] ) ) {
        $selected_char_id = (string) $a['char_id'];
    }

    // Lista postaci
    $characters = tw_get_user_characters( $current_user_id );

    // Bezpieczeństwo: nie pozwól na char_id nie należący do usera
    if ( ! empty( $selected_char_id ) && ! empty( $characters ) ) {
        $allowed_char_ids = array_map(
            static function( $char ) {
                return (string) $char->id;
            },
            $characters
        );

        if ( ! in_array( $selected_char_id, $allowed_char_ids, true ) ) {
            $selected_char_id = null;
        }
    }

    // Achievementy dla konta lub konta + wybranej postaci
    $results = tw_get_player_achievements( $current_user_id, $selected_char_id, $a['type'] );

    if ( empty( $results ) ) {
        return '<p>No achievements to display.</p>';
    }

    $output = '';

    // Formularz z wyborem postaci
    if ( ! empty( $characters ) ) {
        $output .= '<form method="get" class="ach-filter-form">';

        foreach ( $_GET as $key => $value ) {
            if ( $key === 'char_id' ) {
                continue;
            }
            if ( is_scalar( $value ) ) {
                $output .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '">';
            }
        }

        $output .= '<label for="ach-char-select" class="ach-filter-label">Character view</label>';
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

    // Grid z kartami achievementów
    $output .= '<div class="achievements-grid">';

    foreach ( $results as $ach ) {
        $is_unlocked = ! empty( $ach->is_unlocked );
        $percent     = $is_unlocked ? 100 : ( isset( $ach->progress_percent ) ? (float) $ach->progress_percent : 0.0 );

        $legacy_class = '';

        $scope    = $ach->scope ?? 'account';
        $category = $ach->category ?? '';

        $theme = tw_get_achievement_theme(
            $category,
            $scope,
            $ach->bg_color ?? ''
        );

        $bg_color = $theme['color'];
        $style    = '--bg-color: ' . $bg_color . '; --prog-percent: ' . $percent . '%;';

        $status = $ach->css_status ?? ( $is_unlocked ? 'status-unlocked' : 'status-locked' );

        $base_icon = tw_resolve_achievement_icon(
            $ach->achievement_id ?? '',
            $scope,
            $status
        );

        $icon = ( $status === 'status-hidden' )
            ? 'scan-search'
            : ( $theme['icon'] ?? $base_icon );

        if ( $status === 'status-hidden' && ! $is_unlocked ) {
            $title = 'Secret achievement';
        } else {
            $title = $ach->display_title ?? 'Find achievement';
        }

        if ( $status === 'status-hidden' && ! $is_unlocked ) {
            $description = 'Hidden objective - keep playing to uncover this.';
        } else {
            $description = $ach->display_description ?? '';
        }

        $shape_class = ( $scope === 'account' ) ? 'ach-shape-hex' : '';
        $badge_label = ( $status === 'status-hidden' )
            ? 'SECRET'
            : ( $scope === 'character' ? 'CHARACTER' : 'ACCOUNT' );

        $output .= '<div class="ach-card scope-' . esc_attr( $scope ) . ' ' . esc_attr( trim( $status . ' ' . $shape_class . ' ' . $legacy_class ) ) . '" style="' . esc_attr( $style ) . '">';

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

        if ( $description !== '' ) {
            $output .= '<div class="ach-desc">' . esc_html( $description ) . '</div>';
        }

        $goal = isset( $ach->goal ) ? (int) $ach->goal : 0;
        if ( ! $is_unlocked && $status !== 'status-hidden' && $goal > 1 ) {
            $current = isset( $ach->current_progress ) ? (int) $ach->current_progress : 0;
            $output .= '<div class="ach-progress">' . esc_html( $current . '/' . $goal ) . '</div>';
        }

        $output .= '</div>'; // .ach-card
    }

    $output .= '</div>'; // .achievements-grid

    return $output;
}

if ( ! function_exists( 'tw_resolve_achievement_icon' ) ) {
    function tw_resolve_achievement_icon( $achievement_id = '', $scope = 'account', $status = '' ) {
        if ( $status === 'status-hidden' ) {
            return 'scan-search';
        }

        $map = [
            'beta_tester'      => 'flask-conical',
            'explorer'         => 'compass',
            'first_deployment' => 'rocket',
            'deck_master'      => 'layers-3',
            'craft_10'         => 'cpu',
            'social_link'      => 'radio-tower',
            'secret_path'      => 'fingerprint',
            'lore_hunter'      => 'eye',
            'node_walker'      => 'orbit',
            'signal_sync'      => 'zap',
        ];

        if ( ! empty( $achievement_id ) && isset( $map[ $achievement_id ] ) ) {
            return $map[ $achievement_id ];
        }

        return ( $scope === 'character' ) ? 'shield' : 'badge-check';
    }
}

if ( ! function_exists( 'tw_get_achievement_theme' ) ) {
    function tw_get_achievement_theme( $category = '', $scope = 'account', $bg_color = '' ) {
        $themes = [
            'system'      => [ 'icon' => 'cpu',         'color' => '#8b5cf6' ],
            'exploration' => [ 'icon' => 'compass',     'color' => '#14b8a6' ],
            'social'      => [ 'icon' => 'radio',       'color' => '#3b82f6' ],
            'progression' => [ 'icon' => 'trending-up', 'color' => '#f59e0b' ],
            'mission'     => [ 'icon' => 'shield',      'color' => '#ef4444' ],
            'loot'        => [ 'icon' => 'sparkles',    'color' => '#a855f7' ],
            'secret'      => [ 'icon' => 'scan-search', 'color' => '#64748b' ],
        ];

        $theme = $themes[ $category ] ?? [
            'icon'  => ( $scope === 'character' ? 'shield' : 'badge-check' ),
            'color' => ( $scope === 'character' ? '#0ea5e9' : '#84cc16' ),
        ];

        if ( ! empty( $bg_color ) ) {
            $theme['color'] = $bg_color;
        }

        return $theme;
    }
}

add_shortcode( 'achievements', 'render_player_achievements' );
