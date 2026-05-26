<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Tactical Left Panel (Battle Grid + Map + Scanner)
 * Expected variables in scope:
 * - $map_data  (array)
 * - $grid_map  (array<int, array>)  slots 1–9 => unit rows
 * - $has_enemy (bool)
 */
if ( ! function_exists( 'nw_render_tactical_left_panel' ) ) {
	function nw_render_tactical_left_panel( $map_data = array(), $grid_map = array(), $has_enemy = false ) {
		if ( ! is_page_template( array( 'templates/adventure.php' ) ) ) {
			return '';
		}

		$css_rel  = 'assets/css/public/panel-tactical-left.css';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0';

		$js_rel  = 'assets/js/public/panel-tactical-left.js';
		$js_path = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$js_url  = NEOWEAVER_PLUGIN_URL . $js_rel;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0';

		wp_enqueue_style( 'tactical-overlay', $css_url, array(), $css_ver );
		wp_enqueue_script( 'tactical-overlay', $js_url, array(), $js_ver, true );

		$combat_active    = ! empty( $has_enemy );
		$header_css_class = $combat_active ? 'hp-red' : 'tactical-mode';
		$header_label     = $combat_active ? 'THREAT DETECTED' : 'SYSTEM: ACTIVE';
		$kingdom_name     = esc_html( $map_data['kingdom_name'] ?? 'Wilderness' );
		$location_name    = esc_html( $map_data['location_name'] ?? 'Unknown' );

		// Allowed CSS classes for unit health — whitelist prevents arbitrary class injection.
		$allowed_health_classes = array( 'green', 'yellow', 'orange', 'red' );

		ob_start();
		?>

<!-- Status bridge for JS — aria-hidden keeps it out of the a11y tree -->
<div id="tactical-status-bridge"
     data-combat-active="<?php echo $combat_active ? 'true' : 'false'; ?>"
     aria-hidden="true"
     style="display:none;"></div>

<!-- Side navigation -->
<nav class="tw-side-nav left-nav" id="twLeftNavTactical" role="tablist" aria-label="Tactical Panel">

    <button class="tw-nav-btn-tactical" data-tab="left-map-tab" title="World Map"
            type="button" role="tab" aria-selected="true" aria-controls="left-map-tab" aria-label="World Map">
        <svg class="icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
            <line x1="9" y1="3" x2="9" y2="18"/>
            <line x1="15" y1="6" x2="15" y2="21"/>
        </svg>
    </button>

    <button class="tw-nav-btn-tactical" data-tab="left-battle-tab" title="Combat Grid"
            type="button" role="tab" aria-selected="false" aria-controls="left-battle-tab" aria-label="Combat Grid">
        <svg class="icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <path d="M14.5 17.5L3 6V3h3l11.5 11.5"/>
            <path d="M13 19l6-6"/>
            <path d="M16 16l4 4"/>
            <path d="M19 21l2-2"/>
            <path d="M4.5 6.5l6 6"/>
        </svg>
    </button>

    <button class="tw-nav-btn-tactical" data-tab="left-scanning-tab" title="Scan Area"
            type="button" role="tab" aria-selected="false" aria-controls="left-scanning-tab" aria-label="Scan Area">
        <svg class="icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
            <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
            <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
            <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
            <circle cx="12" cy="12" r="3"/>
            <path d="M12 9a6 6 0 0 1 6 6"/>
        </svg>
    </button>

</nav>

<!-- Main panel -->
<div class="tw-character-panel-container left-panel" id="tacticalPanelLeft">
    <div class="tw-character-card tactical-card tw-tactical-card--full">

        <!-- Header -->
        <div class="tw-char-header tactical-header tw-tactical-header--fixed">
            <div class="tw-char-info">
                <div class="tw-lvl-frame <?php echo esc_attr( $header_css_class ); ?>">
                    <?php echo esc_html( $header_label ); ?>
                </div>
                <h3 class="tw-char-name">TACTICAL HUD</h3>
                <div class="tw-char-class-line">
                    KINGDOM: <span class="highlight"><?php echo $kingdom_name; ?></span>
                    <span class="tw-divider" aria-hidden="true">//</span>
                    LOC: <span class="highlight"><?php echo $location_name; ?></span>
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
                        $unit = $grid_map[ $slot ] ?? null;

                        // Whitelist health class — never trust raw DB value as CSS class.
                        $raw_health   = $unit['current_health_visual'] ?? 'green';
                        $health_class = in_array( $raw_health, $allowed_health_classes, true ) ? $raw_health : 'green';

                        $unit_type = $unit ? sanitize_key( $unit['unit_type'] ?? 'npc' ) : '';
                        $is_player = ( $unit_type === 'player' );

                        // Normalise active_effects: string | array | empty → clean array.
                        $raw_effects  = $unit['active_effects'] ?? [];
                        $effects_list = is_array( $raw_effects )
                            ? array_filter( array_map( 'strval', $raw_effects ) )
                            : ( $raw_effects !== '' ? [ (string) $raw_effects ] : [] );
                        $effects_string = implode( ', ', array_map( 'esc_attr', $effects_list ) );
                    ?>
                        <div class="tw-grid-slot <?php echo $unit ? 'occupied' : ''; ?>"
                             data-slot="<?php echo intval( $slot ); ?>">
                            <?php if ( $unit ) : ?>
                                <div class="tw-unit <?php echo esc_attr( $health_class ); ?>"
                                     <?php if ( $effects_string ) : ?>title="<?php echo $effects_string; ?>"<?php endif; ?>>
                                    <svg class="unit-icon" xmlns="http://www.w3.org/2000/svg"
                                         width="26" height="26" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="1.6"
                                         stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true" focusable="false">
                                        <?php if ( $is_player ) : ?>
                                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                            <circle cx="12" cy="7" r="4"/>
                                        <?php else : ?>
                                            <rect x="3" y="11" width="18" height="10" rx="2"/>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                            <circle cx="12" cy="16" r="1" fill="currentColor"/>
                                        <?php endif; ?>
                                    </svg>
                                    <?php if ( ! empty( $effects_list ) ) : ?>
                                        <div class="unit-effects-dots" aria-hidden="true"></div>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <span class="slot-coord" aria-hidden="true"><?php echo intval( $slot ); ?></span>
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

        </div><!-- /.tw-panel-scroll-area -->
    </div><!-- /.tw-tactical-card--full -->
</div><!-- /#tacticalPanelLeft -->
<?php
		return ob_get_clean();
	}
}
