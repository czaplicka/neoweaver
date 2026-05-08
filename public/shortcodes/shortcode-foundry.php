<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NeoWeave: Nanite Foundry Shortcode
 *
 * Renders the card upgrade interface for the current player's Field Agent.
 * Assumes helper functions exist to fetch:
 * - character_id bound to the current WP user,
 * - character's deck/library,
 * - character's current credits.
 */
function cyber_foundry_shortcode() {
    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return '<div class="foundry-container">ERROR: UPLINK REQUIRED. PLEASE LOG IN.</div>';
    }

    // Hard guards on required helpers – fail fast with clear messages instead of fatals.
    if ( ! function_exists( 'get_cyber_character_id_by_wp_id' ) ) {
        return '<div class="foundry-container">ERROR: CHARACTER LINK HELPER NOT AVAILABLE.</div>';
    }

    if ( ! function_exists( 'fetch_foundry_data' ) ) {
        return '<div class="foundry-container">ERROR: FOUNDRY DATASTREAM OFFLINE.</div>';
    }

    if ( ! function_exists( 'get_cyber_player_credits' ) ) {
        return '<div class="foundry-container">ERROR: CREDIT HELPER NOT AVAILABLE.</div>';
    }

    // Resolve character id for this WP user.
    $character_id = get_cyber_character_id_by_wp_id( $user_id );

    // Validate character_id – expect a non-empty scalar (UUID/string).
    if ( empty( $character_id ) || ! is_scalar( $character_id ) ) {
        return '<div class="foundry-container">ERROR: NO FIELD AGENT DETECTED.</div>';
    }

    // Fetch Foundry data – should return an array of objects (cards) or an empty array.
    $library_cards = fetch_foundry_data( $character_id );

    if ( is_wp_error( $library_cards ) ) {
        // Optional: log error for debugging.
        // error_log( 'Nanite Foundry: fetch_foundry_data error: ' . $library_cards->get_error_message() );
        return '<div class="foundry-container">ERROR: DATASTREAM CORRUPTED. PLEASE RETRY LATER.</div>';
    }

    if ( ! is_array( $library_cards ) ) {
        // Force into array to avoid foreach warnings.
        $library_cards = [];
    }

    // Fetch current credits once – stable during page render.
    $current_player_credits = get_cyber_player_credits( $character_id );
    if ( ! is_numeric( $current_player_credits ) ) {
        $current_player_credits = 0;
    }
    $current_player_credits = (int) $current_player_credits;

    // Optional: prepare a nonce for AJAX upgrade calls (you'll use it in JS).
    $nonce = wp_create_nonce( 'cyber_foundry_upgrade' );

    ob_start();
    ?>
    <div class="foundry-container" data-foundry-nonce="<?php echo esc_attr( $nonce ); ?>">
        <h2 class="foundry-title"><span class="blink">_</span> NANITE FOUNDRY</h2>

        <div class="foundry-grid">
            <?php if ( ! empty( $library_cards ) ) : ?>
                <?php foreach ( $library_cards as $card ) : ?>
                    <?php
                    // Defensive defaults.
                    $card_level          = isset( $card->level ) ? (int) $card->level : 0;
                    $card_name           = isset( $card->name ) ? (string) $card->name : '[UNKNOWN CARD]';
                    $card_duplicates     = isset( $card->duplicate_count ) ? (int) $card->duplicate_count : 0;
                    $card_instance_id    = isset( $card->instance_id ) ? (string) $card->instance_id : '';
                    $card_id_safe        = sanitize_text_field( $card_instance_id ); // basic sanity check

                    // Game logic: required duplicates and credits scale with level.
                    $needed_duplicates   = max( 1, $card_level * 2 );   // avoid division by zero
                    $needed_credits      = max( 0, $card_level * 100 );

                    $has_duplicates      = $card_duplicates >= $needed_duplicates;
                    $has_credits         = $current_player_credits >= $needed_credits;
                    $can_upgrade         = $has_duplicates && $has_credits;

                    // Progress bar width: guard division by zero and clamp to [0, 100].
                    if ( $needed_duplicates > 0 ) {
                        $progress_raw   = ( $card_duplicates / $needed_duplicates ) * 100;
                    } else {
                        $progress_raw   = 0;
                    }
                    $progress_width = max( 0, min( 100, $progress_raw ) );

                    // Button label logic.
                    if ( ! $has_duplicates ) {
                        $button_label = 'NEED MORE DATA';
                    } elseif ( ! $has_credits ) {
                        $button_label = 'INSUFFICIENT CC';
                    } else {
                        $button_label = 'START FUSION';
                    }
                    ?>
                    <div class="foundry-item <?php echo $can_upgrade ? 'ready' : ''; ?>">
                        <div class="card-preview">
                            <span class="lvl-badge">v.<?php echo esc_html( $card_level ); ?></span>
                            <div class="card-name"><?php echo esc_html( $card_name ); ?></div>
                        </div>

                        <div class="upgrade-info">
                            <div class="progress-bar">
                                <div
                                    class="progress-fill"
                                    style="width: <?php echo esc_attr( $progress_width ); ?>%;"
                                ></div>
                            </div>

                            <span class="count-text">
                                DATA NODES: <?php echo (int) $card_duplicates; ?> / <?php echo (int) $needed_duplicates; ?>
                            </span>

                            <div class="credit-cost <?php echo $has_credits ? '' : 'insufficient'; ?>">
                                COST: <?php echo (int) $needed_credits; ?> CC
                            </div>
                        </div>

                        <button
                            class="upgrade-btn"
                            type="button"
                            data-card-instance-id="<?php echo esc_attr( $card_id_safe ); ?>"
                            data-card-level="<?php echo esc_attr( $card_level ); ?>"
                            data-needed-duplicates="<?php echo esc_attr( $needed_duplicates ); ?>"
                            data-needed-credits="<?php echo esc_attr( $needed_credits ); ?>"
                            <?php echo ! $can_upgrade ? 'disabled' : ''; ?>
                            onclick="if (typeof upgradeCard === 'function') { upgradeCard(this); }"
                        >
                            <?php echo esc_html( $button_label ); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="buffer-empty">NO DATA NODES DETECTED IN ARCHIVE.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

// Shortcode is registered in main plugin file; this is a safe fallback only.
if ( ! shortcode_exists( 'cyber_foundry' ) ) {
    add_shortcode( 'cyber_foundry', 'cyber_foundry_shortcode' );
}
