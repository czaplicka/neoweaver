<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'tw_get_player_achievements' ) ) {
    function tw_get_player_achievements( $user_id, $char_id = null, $type = 'all' ) {

        $filters = [
            'user_id' => 'eq.' . intval( $user_id ),
            'select'  => 'achievement_id,user_id,character_id,display_title,display_description,icon_slug,bg_color,scope,goal,current_progress,is_unlocked,unlocked_at,progress_percent,css_status',
        ];

        if ( ! empty( $char_id ) ) {
            $filters['character_id'] = 'eq.' . intval( $char_id );
        }

        if ( $type === 'earned' ) {
            $filters['is_unlocked'] = 'eq.true';
        }

        // to korzysta z Twojego istniejącego helpera Supabase
        $rows = tw_supabase_get( 'player_achievements_view', $filters );

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

    $results = tw_get_player_achievements( $a['user_id'], $a['char_id'], $a['type'] );

    if ( empty( $results ) ) {
        return '<p>Brak osiągnięć do wyświetlenia.</p>';
    }

    $output = '<div class="achievements-grid">';

foreach ( $results as $ach ) {
    $is_unlocked = ! empty( $ach->is_unlocked );
    $percent     = $is_unlocked ? 100 : ( isset( $ach->progress_percent ) ? (float) $ach->progress_percent : 0 );

    $legacy_class = '';

    $scope       = $ach->scope ?? 'account';
    $shape_class = ( $scope === 'character' ) ? 'ach-shape-shield' : 'ach-shape-hex';

    $bg_color = ! empty( $ach->bg_color ) ? $ach->bg_color : '#222222';
    $style    = "--bg-color: {$bg_color}; --prog-percent: {$percent}%;";

    $status = $ach->css_status ?? '';
    $icon   = ( $status === 'status-hidden' ) ? 'question' : ( $ach->icon_slug ?? 'star' );
    $title  = $ach->display_title ?? ( $status === 'status-hidden' ? 'Hidden achievement' : 'Untitled achievement' );

    $output .= '<div class="ach-card ' . esc_attr( trim( $status . ' ' . $shape_class . ' ' . $legacy_class ) ) . '" style="' . esc_attr( $style ) . '">';
    $output .= '<div class="ach-icon"><i class="fas fa-' . esc_attr( $icon ) . '"></i></div>';
    $output .= '<div class="ach-title">' . esc_html( $title ) . '</div>';

    $goal = isset( $ach->goal ) ? (int) $ach->goal : 0;
    if ( ! $is_unlocked && $status !== 'status-hidden' && $goal > 1 ) {
        $current = isset( $ach->current_progress ) ? (int) $ach->current_progress : 0;
        $output .= '<div class="ach-progress">' . esc_html( $current . '/' . $goal ) . '</div>';
    }

    $output .= '</div>';
}

$output .= '</div>';

return $output;
}
add_shortcode( 'achievements', 'render_player_achievements' );
