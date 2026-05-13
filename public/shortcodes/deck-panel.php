<?php
/**
 * [TW] Deck Panel - UI tabs (Mission / Augments / Skills)
 *
 * Shortcode: [tw_deck_panel]
 *
 * Renders the sliding side-panel with three tabs.
 * JS is enqueued as a proper deferred asset via
 * Neoweaver_Public::enqueue_scripts() — see class-neoweaver-public.php.
 *
 * Opt 3 fix: removed inline <script> block; JS moved to
 * public/assets/js/deck-panel.js, enqueued with in_footer:true.
 * The only PHP-originated runtime value (sound base URL) is passed
 * via wp_localize_script() as window.twDeckPanelConfig.soundBase.
 *
 * CSS scope  : .neoweaver-screen #deck-panel
 * JS globals : window.twInitDeckPanel  (set by deck-panel.js)
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Deck Panel HTML only.
 * Called by Neoweaver_Public::shortcode_deck_panel().
 *
 * @return string
 */
function tw_deck_panel_render(): string {
	// Bug 8 fix: restrict output to the adventure template.
	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return '';
	}

	// Opt 3 fix: tell the enqueue layer to actually load the script.
	// wp_enqueue_scripts runs before shortcodes on normal page loads, so
	// we use wp_enqueue_script() here as a late-enqueue safety net in case
	// the shortcode fires after the hook (e.g. via AJAX or block editor
	// preview). wp_enqueue_script() is idempotent when the handle is already
	// registered — it just marks it for output.
	if ( ! wp_script_is( 'tw-deck-panel', 'enqueued' ) ) {
		wp_enqueue_script( 'tw-deck-panel' );
	}

	ob_start();
	?>
<div id="deck-panel" class="is-collapsed">
	<div class="deck-tabs-wrapper">

		<!-- Toggle button -->
		<button id="toggle-deck" class="panel-tab" aria-label="Toggle deck panel">&#9776;</button>

		<!-- Tab buttons -->
		<button class="panel-tab" data-tab="tab-mission">MISSION</button>
		<button class="panel-tab" data-tab="tab-augments">AUGMENTS</button>
		<button class="panel-tab" data-tab="tab-skills">SKILLS</button>

	</div>

	<!-- Tab content areas -->
	<div id="tab-mission"  class="deck-tab-content is-active">
		<h3 class="deck-tab-title">// MISSION_DATA</h3>
		<div id="tw-mission-content">Loading mission data...</div>
	</div>

	<div id="tab-augments" class="deck-tab-content">
		<h3 class="deck-tab-title">// AUGMENTS</h3>
		<div id="tw-augments-content">Loading augments...</div>
	</div>

	<div id="tab-skills" class="deck-tab-content">
		<h3 class="deck-tab-title">// SKILLS &amp; ABILITIES</h3>
		<div id="tw-skills-content">Loading skills...</div>
	</div>
</div>
	<?php
	return ob_get_clean();
}
