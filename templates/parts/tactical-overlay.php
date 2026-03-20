<?php
/**
 * Tactical Left Panel (Battle Grid + Map + Scanner)
 * Expected variables in scope:
 * - $map_data  (array)
 * - $grid_map  (array<int, array>)  slots 1–9 => unit rows
 * - $has_enemy (bool)
 */

// FIX: Pre-sanitize and resolve values once, not inline repeatedly.
$combat_active    = ! empty( $has_enemy );
$header_css_class = $combat_active ? 'hp-red' : 'tactical-mode';
$header_label     = $combat_active ? 'THREAT DETECTED' : 'SYSTEM: ACTIVE';
$kingdom_name     = esc_html( $map_data['kingdom_name'] ?? 'Wilderness' );
$location_name    = esc_html( $map_data['location_name'] ?? 'Unknown' );
?>

<!-- Status bridge for JS — aria-hidden keeps it out of the a11y tree -->
<div id="tactical-status-bridge"
     data-combat-active="<?= $combat_active ? 'true' : 'false'; ?>"
     aria-hidden="true"
     style="display:none;"></div>

<!-- Side navigation -->
<div class="tw-side-nav left-nav" id="twLeftNavTactical">
    <button class="tw-nav-btn-tactical" data-tab="left-map-tab" title="World Map" type="button">
        <span class="icon" aria-hidden="true">🧭</span>
    </button>
    <button class="tw-nav-btn-tactical" data-tab="left-battle-tab" title="Combat Grid" type="button">
        <span class="icon" aria-hidden="true">⚔️</span>
    </button>
    <button class="tw-nav-btn-tactical" data-tab="left-scanning-tab" title="Scan Area" type="button">
        <span class="icon" aria-hidden="true">📡</span>
    </button>
</div>

<!-- Main panel -->
<div class="tw-character-panel-container left-panel" id="tacticalPanelLeft">
    <div class="tw-character-card tactical-card tw-tactical-card--full">

        <!-- Header -->
        <div class="tw-char-header tactical-header tw-tactical-header--fixed">
            <div class="tw-char-info">
                <div class="tw-lvl-frame <?= $header_css_class; ?>">
                    <?= $header_label; ?>
                </div>
                <h3 class="tw-char-name">TACTICAL HUD</h3>
                <div class="tw-char-class-line">
                    KINGDOM: <span class="highlight"><?= $kingdom_name; ?></span>
                    <span class="tw-divider" aria-hidden="true">//</span>
                    LOC: <span class="highlight"><?= $location_name; ?></span>
                </div>
            </div>
        </div>

        <!-- Scrollable content area -->
        <div class="tw-panel-scroll-area tw-panel-scroll-area--flex">

            <!-- TAB: MAP (active by default) -->
            <div class="tw-tab-content-tactical tw-tab-content-tactical--active"
                 id="left-map-tab"
                 role="tabpanel"
                 aria-label="World Map">
                <div class="tw-map-wrapper">
                    <?php echo do_shortcode( '[cyber_active_map]' ); ?>
                    <?php echo do_shortcode( '[kingdom_info]' ); ?>
                </div>
            </div>

            <!-- TAB: BATTLE -->
            <div class="tw-tab-content-tactical tw-tab-content-tactical--padded tw-tab-content-tactical--hidden"
                 id="left-battle-tab"
                 role="tabpanel"
                 aria-label="Combat Grid">
                <div class="tw-grid-header">
                    <span class="stat-label">ENGAGEMENT GRID (3x3)</span>
                </div>

                <div class="tw-tactical-grid">
                    <?php for ( $slot = 1; $slot <= 9; $slot++ ) :
                        // FIX: use strict int key; avoid accidental loose comparisons.
                        $unit = $grid_map[ $slot ] ?? null;

                        // FIX: sanitize CSS class and unit_type before output.
                        $health_class = $unit ? esc_attr( $unit['current_health_visual'] ?? 'green' ) : '';
                        $unit_type    = $unit ? sanitize_key( $unit['unit_type'] ?? 'npc' ) : '';
                        $is_player    = ( $unit_type === 'player' );

                        // FIX: active_effects may be a string or array — normalise first.
                        $raw_effects    = $unit['active_effects'] ?? [];
                        $effects_list   = is_array( $raw_effects ) ? $raw_effects : [ $raw_effects ];
                        $effects_string = implode( ', ', array_map( 'esc_attr', $effects_list ) );
                    ?>
                        <div class="tw-grid-slot <?= $unit ? 'occupied' : ''; ?>"
                             data-slot="<?= intval( $slot ); ?>">
                            <?php if ( $unit ) : ?>
                                <div class="tw-unit <?= $health_class; ?>"
                                     title="<?= $effects_string; ?>">
                                    <span class="unit-icon" aria-hidden="true">
                                        <?= $is_player ? '👤' : '🤖'; ?>
                                    </span>
                                    <?php if ( ! empty( $effects_list ) ) : ?>
                                        <div class="unit-effects-dots" aria-hidden="true"></div>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <span class="slot-coord" aria-hidden="true"><?= intval( $slot ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- TAB: SCANNER -->
            <div class="tw-tab-content-tactical tw-tab-content-tactical--padded tw-tab-content-tactical--hidden"
                 id="left-scanning-tab"
                 role="tabpanel"
                 aria-label="Area Scanner">
                <div class="tw-gold-hud">
                    <div class="tw-gold-label">LOCAL ENTITIES</div>
                </div>
                <?php echo do_shortcode( '[tw_local_scanner]' ); ?>
            </div>

        </div>
    </div>
</div>
