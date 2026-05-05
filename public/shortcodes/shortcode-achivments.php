<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! function_exists( 'tw_get_player_achievements' ) ) {
    function tw_get_player_achievements( $user_id, $char_id = null, $type = 'all' ) {
        $params = [
            'p_user_id' => (int) $user_id,
            'p_type'    => in_array( $type, [ 'all', 'earned' ], true ) ? $type : 'all',
            'p_character_id' => ! empty( $char_id ) ? (string) $char_id : null,
        ];

        $rows = tw_supabase_rpc( 'get_player_achievements', $params );

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
        return '<p>No achievments. Please log in.</p>';
    }

    // HTML wynikowy
    $output  = '<div class="achievements-grid">';

    foreach ( $results as $ach ) {
        $is_unlocked = ! empty( $ach->is_unlocked );
        $percent     = $is_unlocked ? 100 : ( isset( $ach->progress_percent ) ? (float) $ach->progress_percent : 0.0 );

        $legacy_class = '';

        $scope       = $ach->scope ?? 'account';
        $shape_class = ( $scope === 'character' ) ? 'ach-shape-shield' : 'ach-shape-hex';

        $bg_color = ! empty( $ach->bg_color ) ? $ach->bg_color : '#222222';
        $style    = "--bg-color: {$bg_color}; --prog-percent: {$percent}%;";

        $status = $ach->css_status ?? ( $is_unlocked ? 'status-unlocked' : 'status-locked' );

        // ICONA
        $icon = ( $status === 'status-hidden' )
            ? 'question'
            : ( $ach->icon_slug ?? 'star' );

        // TYTUŁ
        if ( $status === 'status-hidden' && ! $is_unlocked ) {
            $title = 'Secret achievement';
        } else {
            $title = $ach->display_title ?? 'Find achievement';
        }

        // OPIS
        if ( $status === 'status-hidden' && ! $is_unlocked ) {
            $description = 'Hidden objective - keep playing to uncover this.';
        } else {
            $description = $ach->display_description ?? '';
        }

        $badge_label = ( $status === 'status-hidden' )
            ? 'SECRET'
            : ( $scope === 'character' ? 'CHARACTER' : 'ACCOUNT' );

        $output .= '<div class="ach-card ' . esc_attr( trim( $status . ' ' . $shape_class . ' ' . $legacy_class ) ) . '" style="' . esc_attr( $style ) . '">';

        // górna linia: badge + progres %
        $output .= '<div class="ach-top-row">';
        $output .= '<span class="ach-badge">' . esc_html( $badge_label ) . '</span>';

        if ( ! $is_unlocked ) {
            $output .= '<span class="ach-percent">' . esc_html( (int) round( $percent ) ) . '%</span>';
        } else {
            $output .= '<span class="ach-percent ach-percent-done">100%</span>';
        }
        $output .= '</div>';

        // ikona
        $output .= '<div class="ach-icon"><i class="fas fa-' . esc_attr( $icon ) . '" aria-hidden="true"></i></div>';

        // tytuł + opis
        $output .= '<div class="ach-title">' . esc_html( $title ) . '</div>';

        if ( $description !== '' ) {
            $output .= '<div class="ach-desc">' . esc_html( $description ) . '</div>';
        }

        // licznik postępu (np. 0/5)
        $goal = isset( $ach->goal ) ? (int) $ach->goal : 0;
        if ( ! $is_unlocked && $status !== 'status-hidden' && $goal > 1 ) {
            $current = isset( $ach->current_progress ) ? (int) $ach->current_progress : 0;
            $output .= '<div class="ach-progress">' . esc_html( $current . '/' . $goal ) . '</div>';
        }

        $output .= '</div>'; // .ach-card
    }

    $output .= '</div>'; // .achievements-grid

    // Możesz ten CSS przenieść do pliku .css pluginu / motywu,
    // ale jeśli chcesz inline:
    $output .= '<style>
.achievements-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    max-width: 1200px;
    margin: 24px auto;
}

@media (max-width: 980px) {
    .achievements-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .achievements-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .ach-card,
    .ach-shape-hex,
    .ach-shape-shield {
        clip-path: none;
        border-radius: 16px;
        padding-left: 16px;
        padding-right: 16px;
    }
}

.ach-card {
    --card-bg: rgba(12, 16, 22, 0.92);
    --card-border: rgba(173, 255, 0, 0.22);
    --card-glow: rgba(173, 255, 0, 0.18);

    position: relative;
    overflow: hidden;
    min-height: 190px;
    padding: 18px 16px 16px;
    border: 1px solid var(--card-border);
    border-radius: 18px;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0)) ,
        linear-gradient(135deg, color-mix(in srgb, var(--bg-color, #222) 32%, #0b0f14 68%), #0b0f14);
    box-shadow:
        0 0 0 1px rgba(255,255,255,0.02) inset,
        0 10px 30px rgba(0,0,0,0.35),
        0 0 24px var(--card-glow);
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 12px;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.ach-card::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(130deg, rgba(173,255,0,0.12), transparent 28%),
        linear-gradient(320deg, rgba(255,255,255,0.04), transparent 30%);
    pointer-events: none;
}

.ach-card::after {
    content: "";
    position: absolute;
    left: 16px;
    right: 16px;
    bottom: 0;
    height: 4px;
    border-radius: 999px;
    background: linear-gradient(90deg, #adff00 0%, #d7ff70 var(--prog-percent, 0%), rgba(255,255,255,0.08) var(--prog-percent, 0%), rgba(255,255,255,0.08) 100%);
    box-shadow: 0 0 14px rgba(173,255,0,0.35);
}

.ach-card:hover {
    transform: translateY(-4px);
    border-color: rgba(173, 255, 0, 0.45);
    box-shadow:
        0 0 0 1px rgba(255,255,255,0.03) inset,
        0 16px 38px rgba(0,0,0,0.42),
        0 0 34px rgba(173,255,0,0.24);
}

.ach-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(173,255,0,0.10);
    border: 1px solid rgba(173,255,0,0.24);
    color: #adff00;
    font-size: 22px;
    box-shadow: inset 0 0 18px rgba(173,255,0,0.08);
}

.ach-title {
    font-family: "Chakra Petch", sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1.2;
    color: #f5f7fb;
    letter-spacing: 0.02em;
}

.ach-desc {
    font-size: 0.85rem;
    line-height: 1.4;
    color: rgba(255,255,255,0.78);
    margin-top: 4px;
    max-width: 30em;
}

.ach-progress {
    margin-top: auto;
    font-family: "Chakra Petch", sans-serif;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.72);
}

.ach-card.status-hidden {
    opacity: 0.78;
    border-color: rgba(255,255,255,0.1);
    backdrop-filter: blur(3px);
}

.ach-card.status-hidden::before {
    background:
        linear-gradient(130deg, rgba(255,255,255,0.18), transparent 30%),
        linear-gradient(320deg, rgba(255,255,255,0.08), transparent 32%);
}

.ach-card.status-hidden .ach-icon {
    background: rgba(0,0,0,0.4);
    border-color: rgba(255,255,255,0.18);
    color: rgba(255,255,255,0.78);
}

.ach-card.status-hidden .ach-title,
.ach-card.status-hidden .ach-desc {
    color: rgba(255,255,255,0.82);
}

.ach-card.status-locked {
    filter: saturate(0.9);
}

.ach-card.status-unlocked {
    border-color: rgba(173,255,0,0.45);
    box-shadow:
        0 0 0 1px rgba(173,255,0,0.08) inset,
        0 12px 32px rgba(0,0,0,0.36),
        0 0 30px rgba(173,255,0,0.22);
}

.ach-card.status-unlocked .ach-icon {
    background: rgba(173,255,0,0.16);
    border-color: rgba(173,255,0,0.36);
}

.ach-shape-hex {
    clip-path: polygon(8% 0, 92% 0, 100% 50%, 92% 100%, 8% 100%, 0 50%);
    border-radius: 0;
    padding-left: 22px;
    padding-right: 22px;
}

.ach-shape-shield {
    clip-path: polygon(50% 0%, 92% 14%, 92% 58%, 50% 100%, 8% 58%, 8% 14%);
    border-radius: 0;
    padding-left: 22px;
    padding-right: 22px;
}

.ach-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-family: "Chakra Petch", sans-serif;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: rgba(255,255,255,0.65);
}

.ach-badge {
    padding: 3px 8px;
    border-radius: 999px;
    border: 1px solid rgba(173,255,0,0.4);
    background: rgba(173,255,0,0.12);
    color: #adff00;
}

.ach-card.status-hidden .ach-badge {
    border-color: rgba(255,255,255,0.5);
    background: rgba(0,0,0,0.3);
    color: rgba(255,255,255,0.7);
}

.ach-percent {
    opacity: 0.8;
}

.ach-percent-done {
    color: #adff00;
    font-weight: 600;
}
</style>';

    return $output;
}
add_shortcode( 'achievements', 'render_player_achievements' );
