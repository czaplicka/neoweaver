<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BUG-FIX: The previous nw_is_adventure_template() called is_singular() at
 * shortcode-registration time (file-include during plugins_loaded), before the
 * main query has run, triggering a "Function is_singular was called incorrectly"
 * notice and always returning false.
 *
 * Fixed: the template check is deferred into the shortcode callback itself,
 * which only executes at render time (well past wp hook), so is_singular() and
 * get_page_template_slug() are safe.
 */

add_shortcode( 'SIGNAL_QUALITY', function() {
	// Safe here — shortcode callbacks fire during the_content, long after wp hook.
	if ( ! is_singular() ) {
		return '';
	}
	$template = get_page_template_slug( get_queried_object_id() );
	if ( $template !== 'page-adventure.php' ) {
		return '';
	}

	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		return '';
	}

	// 1. Active session for the player
	$sessions = tw_supabase_get(
		'cyber_game_sessions',
		[
			'wp_user_id' => 'eq.' . (int) $wp_user_id,
			'status'     => 'eq.active',
			'select'     => 'location_id,cyber_world_map(location_archetype_id,cyber_location_archetypes(base_tech))',
		]
	);

	if ( empty( $sessions ) ) {
		return '';
	}

	$session  = $sessions[0];
	$location = $session['cyber_world_map'] ?? null;
	if ( ! $location ) {
		return '';
	}

	$archetype = $location['cyber_location_archetypes'] ?? null;
	$base_tech = isset( $archetype['base_tech'] ) ? (int) $archetype['base_tech'] : 3;

	// Scale 1–5 → percentage
	$world_tech_level = max( 1, min( 5, $base_tech ) );
	$signal_strength  = ( $world_tech_level / 5 ) * 100;

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
				echo 'STATUS: UNSTABLE / ANALOG INTERFERENCE DETECTED';
			} elseif ( $world_tech_level <= 4 ) {
				echo 'STATUS: HYBRID GRID – SIGNAL WITH NOISE';
			} else {
				echo 'STATUS: QUANTUM-CLEAN LINK ESTABLISHED';
			}
			?>
		</div>
	</div>
	<?php
	return ob_get_clean();
} );
