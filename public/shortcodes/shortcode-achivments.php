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
    $a = shortcode_atts(
        [
            'type'    => 'all',
            'user_id' => get_current_user_id(),
            'char_id' => null,
        ],
        $atts
    );

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

    $current_user_id  = (int) $a['user_id'];

    $current_user_id  = (int) $a['user_id'];

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

        $scope       = $ach->scope ?? 'account';
        $shape_class = ( $scope === 'character' ) ? 'ach-shape-shield' : 'ach-shape-hex';

        $bg_color = ! empty( $ach->bg_color ) ? $ach->bg_color : '#222222';
        $style    = "--bg-color: {$bg_color}; --prog-percent: {$percent}%;";

        $status = $ach->css_status ?? ( $is_unlocked ? 'status-unlocked' : 'status-locked' );

        $icon = ( $status === 'status-hidden' )
            ? 'question'
            : ( $ach->icon_slug ?? 'star' );

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

        $badge_label = ( $status === 'status-hidden' )
            ? 'SECRET'
            : ( $scope === 'character' ? 'CHARACTER' : 'ACCOUNT' );

        $output .= '<div class="ach-card ' . esc_attr( trim( $status . ' ' . $shape_class . ' ' . $legacy_class ) ) . '" style="' . esc_attr( $style ) . '">';

        $output .= '<div class="ach-top-row">';
        $output .= '<span class="ach-badge">' . esc_html( $badge_label ) . '</span>';

        if ( ! $is_unlocked ) {
            $output .= '<span class="ach-percent">' . esc_html( (int) round( $percent ) ) . '%</span>';
        } else {
            $output .= '<span class="ach-percent ach-percent-done">100%</span>';
        }
        $output .= '</div>';

        $output .= '<div class="ach-icon"><i class="fas fa-' . esc_attr( $icon ) . '" aria-hidden="true"></i></div>';

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
error_log( 'TW achievements params: ' . wp_json_encode( $params ) );
add_shortcode( 'achievements', 'render_player_achievements' );
