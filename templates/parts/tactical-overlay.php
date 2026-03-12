<?php
/**
 * Tactical Left Panel (Battle Grid + Map + Scanner)
 * Expected variables in scope:
 * - $map_data  (array)
 * - $grid_map  (array<int, array>)  slots 1–9 => unit rows
 * - $has_enemy (bool)
 */
?>

<!-- Mostek statusu dla JS -->
<div id="tactical-status-bridge"
     data-combat-active="<?= !empty($has_enemy) ? 'true' : 'false'; ?>"
     style="display:none;"></div>

<!-- Nawigacja boczna -->
<div class="tw-side-nav left-nav" id="twLeftNavTactical">
    <button class="tw-nav-btn-tactical" data-tab="left-map-tab" title="World Map">
        <span class="icon">🧭</span>
    </button>
    <button class="tw-nav-btn-tactical" data-tab="left-battle-tab" title="Combat Grid">
        <span class="icon">⚔️</span>
    </button>
    <button class="tw-nav-btn-tactical" data-tab="left-scanning-tab" title="Scan Area">
        <span class="icon">📡</span>
    </button>
</div>

<!-- Panel Główny -->
<div class="tw-character-panel-container left-panel" id="tacticalPanelLeft">
    <div class="tw-character-card tactical-card" style="height: 100%; display: flex; flex-direction: column;">

        <!-- Nagłówek -->
        <div class="tw-char-header tactical-header" style="flex-shrink: 0;">
            <div class="tw-char-info">
                <div class="tw-lvl-frame <?= !empty($has_enemy) ? 'hp-red' : 'tactical-mode'; ?>">
                    <?= !empty($has_enemy) ? 'THREAT DETECTED' : 'SYSTEM: ACTIVE'; ?>
                </div>
                <h3 class="tw-char-name">TACTICAL HUD</h3>
                <div class="tw-char-class-line">
                    KINGDOM: <span class="highlight"><?= esc_html($map_data['kingdom_name'] ?? 'Wilderness'); ?></span>
                    <span style="opacity: 0.6; margin: 0 5px;">//</span>
                    LOC: <span class="highlight"><?= esc_html($map_data['location_name'] ?? 'Unknown'); ?></span>
                </div>
            </div>
        </div>

        <!-- Obszar treści -->
        <div class="tw-panel-scroll-area" style="flex-grow: 1; overflow: hidden; display: flex; flex-direction: column; padding: 0;">

            <!-- TAB: MAPA -->
            <div class="tw-tab-content-tactical active"
                 id="left-map-tab"
                 style="width: 100%; height: 100%; display: flex; flex-direction: column;">
                <div class="tw-map-wrapper" style="flex-grow: 1; width: 100%; height: 100%; position: relative;">
                    <?php echo do_shortcode('[cyber_active_map]'); ?>
                    <?php echo do_shortcode('[kingdom_info]'); ?>
                </div>
            </div>

            <!-- TAB: BITWA -->
            <div class="tw-tab-content-tactical"
                 id="left-battle-tab"
                 style="padding: 15px; overflow-y: auto; display: none;">
                <div class="tw-grid-header">
                    <span class="stat-label">ENGAGEMENT GRID (3x3)</span>
                </div>

                <div class="tw-tactical-grid">
                    <?php for ( $i = 1; $i <= 9; $i++ ) :
                        $unit = $grid_map[ $i ] ?? null;
                    ?>
                        <div class="tw-grid-slot <?= $unit ? 'occupied' : ''; ?>" data-slot="<?= $i; ?>">
                            <?php if ( $unit ) : ?>
                                <div class="tw-unit <?= esc_attr($unit['current_health_visual'] ?? 'green'); ?>"
                                     title="<?= esc_attr(implode(', ', (array)($unit['active_effects'] ?? []))); ?>">
                                    <span class="unit-icon">
                                        <?= ($unit['unit_type'] ?? '') === 'player' ? '👤' : '🤖'; ?>
                                    </span>
                                    <?php if ( !empty($unit['active_effects']) ) : ?>
                                        <div class="unit-effects-dots"></div>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <span class="slot-coord"><?= $i; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- TAB: SKANER -->
            <div class="tw-tab-content-tactical"
                 id="left-scanning-tab"
                 style="padding: 15px; overflow-y: auto; display: none;">
                <div class="tw-gold-hud">
                    <div class="tw-gold-label">LOCAL ENTITIES</div>
                </div>
                <?php echo do_shortcode('[tw_local_scanner]'); ?>
            </div>

        </div>
    </div>
</div>
