<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function nw_is_adventure_template() {
    if ( ! is_singular() ) return false;
    $template = get_page_template_slug( get_queried_object_id() );
    return $template === 'page-adventure.php'; // dopasuj do swojej nazwy
}

add_shortcode('SIGNAL_QUALITY', function() {
    if ( ! nw_is_adventure_template() ) {
        return '';
    }

    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id ) {
        return ''; // lub np. "AUTH_REQUIRED"
    }

    // 1. Aktywna sesja gracza
    $sessions = tw_supabase_get(
        'cyber_game_sessions',
        [
            'wp_user_id' => 'eq.' . (int) $wp_user_id,
            'status'     => 'eq.active',
            'select'     => 'location_id,cyber_world_map(location_archetype_id,cyber_location_archetypes(base_tech))'
        ]
    );

    if ( empty( $sessions ) ) {
        return ''; // brak aktywnej sesji
    }

    $session = $sessions[0];
    $location = $session['cyber_world_map'] ?? null;
    if ( ! $location ) {
        return '';
    }

    $archetype = $location['cyber_location_archetypes'] ?? null;
    $base_tech = isset($archetype['base_tech']) ? (int) $archetype['base_tech'] : 3;

    // Skala 1–5 → procent paska
    $world_tech_level = max(1, min(5, $base_tech));
    $signal_strength = ($world_tech_level / 5) * 100;

    ob_start(); ?>
    <div class="neoweave-signal-monitor">
        <div class="signal-label">
            SIGNAL INTEGRITY: <?php echo $world_tech_level; ?>/5
        </div>
        <div class="signal-bar-container">
            <div class="signal-bar-fill" style="width: <?php echo $signal_strength; ?>%;"></div>
        </div>
        <div class="signal-status">
            <?php 
            if ( $world_tech_level <= 2 ) {
                echo "STATUS: UNSTABLE / ANALOG INTERFERENCE DETECTED";
            } elseif ( $world_tech_level <= 4 ) {
                echo "STATUS: HYBRID GRID – SIGNAL WITH NOISE";
            } else {
                echo "STATUS: QUANTUM-CLEAN LINK ESTABLISHED";
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
});
