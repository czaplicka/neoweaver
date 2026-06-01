<?php
/**
 * TALE WEAVER – Cyber HUD Overlay
 * Shortcode: [cyber_hud]
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'display_cyber_hud' ) ) {
	function display_cyber_hud(): string {

		if ( ! function_exists( 'tw_supabase_url' ) || ! tw_supabase_url() ) {
			return '';
		}

		if ( ! function_exists( 'tw_supabase_anon_key' ) || ! tw_supabase_anon_key() ) {
			return '';
		}

		$current_user_id = get_current_user_id();

		if ( ! $current_user_id ) {
			return '';
		}

		if ( function_exists( 'tw_enqueue_cyber_hud_assets' ) ) {
			tw_enqueue_cyber_hud_assets(
				array(
					'supabaseUrl'   => trailingslashit( tw_supabase_url() ) . 'rest/v1',
					'supabaseKey'   => tw_supabase_anon_key(),
					'currentUserId' => (int) $current_user_id,
				)
			);
		}

		// BUG 19 fix — check for active character / session before rendering the HUD shell.
		// If no character is resolved, return a visible error state instead of empty bars.
		$has_character = false;
		if ( function_exists( 'tw_get_current_character_id' ) ) {
			$has_character = ! empty( tw_get_current_character_id() );
		}

		if ( ! $has_character ) {
			return '<div id="hud-wrapper" class="cyber-hud-wrapper cyber-hud-wrapper--offline" aria-live="polite">' .
				'<div class="hud-status-label">// NO ACTIVE SESSION — NEURAL LINK OFFLINE</div>' .
				'</div>';
		}

		ob_start();
		?>

		<div id="hud-wrapper" class="cyber-hud-wrapper" aria-live="polite">
			<!-- BUG 19 fix — noscript / JS-failure fallback: hidden by default, shown by CSS if
			     .hud-js-ready is never added to #hud-wrapper (i.e. the JS bundle never executed). -->
			<div class="hud-error-state" aria-hidden="true">
				// HUD OFFLINE — SCRIPT LOAD FAILURE
			</div>

			<div class="status-dots-row" data-hud-toggle="1">
				<div class="hud-status-label" id="hud-trigger-text">&rsaquo; SYSTEM_ACTIVE</div>
				<div class="dots-group">
					<div class="dot" id="dot-rep_local"       style="--base-color: #0055ff"></div>
					<div class="dot" id="dot-rep_world"       style="--base-color: #6699ff"></div>
					<div class="dot" id="dot-danger"          style="--base-color: #ff0033"></div>
					<div class="dot" id="dot-stealth"         style="--base-color: #00f2ff"></div>
					<div class="dot" id="dot-order"           style="--base-color: #ffd700"></div>
					<div class="dot" id="dot-rep_tech_nature" style="--base-color: #adff00"></div>
					<div class="dot" id="dot-rep_chaos_order" style="--base-color: #cc00ff"></div>
					<div class="dot" id="dot-rep_gold_thief"  style="--base-color: #ff8800"></div>
				</div>
			</div>

			<div class="cyber-hud-grid">
				<?php
				$stats = array(
					array( 'id' => 'rep_local',       'l' => 'LOCAL FAME', 'r' => '',       'b' => false ),
					array( 'id' => 'rep_world',       'l' => 'REPUTATION', 'r' => '',       'b' => false ),
					array( 'id' => 'danger',          'l' => 'DANGER',     'r' => '',       'b' => false ),
					array( 'id' => 'stealth',         'l' => 'STEALTH',    'r' => 'DETECT', 'b' => true ),
					array( 'id' => 'order',           'l' => 'CHAOS',      'r' => 'ORDER',  'b' => true ),
					array( 'id' => 'rep_tech_nature', 'l' => 'TECH',       'r' => 'NATURE', 'b' => true ),
					array( 'id' => 'rep_chaos_order', 'l' => 'MAGIC',      'r' => 'SYSTEM', 'b' => true ),
					array( 'id' => 'rep_gold_thief',  'l' => 'GOLD',       'r' => 'THIEF',  'b' => true ),
				);

				foreach ( $stats as $s ) :
					?>
					<div class="hud-column" id="p-<?php echo esc_attr( $s['id'] ); ?>">
						<div class="hud-labels">
							<span class="l-label"><?php echo esc_html( $s['l'] ); ?></span>
							<span class="val-num" id="v-<?php echo esc_attr( $s['id'] ); ?>">0</span>
							<span class="r-label"><?php echo esc_html( $s['r'] ); ?></span>
						</div>

						<div class="hud-bar-container">
							<div class="scanlines"></div>
							<div class="hud-bar-fill" id="b-<?php echo esc_attr( $s['id'] ); ?>"></div>
							<?php if ( $s['b'] ) : ?>
								<div class="center-line"></div>
							<?php endif; ?>
						</div>

						<div class="tag-cloud" id="t-<?php echo esc_attr( $s['id'] ); ?>"></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="hud-close-trigger" data-hud-toggle="1">[ ESC ] TERMINAL_OFF</div>
		</div>

		<div id="hud-global-alert"></div>

		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tw_register_cyber_hud_shortcode' ) ) {
	function tw_register_cyber_hud_shortcode(): void {
		add_shortcode( 'cyber_hud', 'display_cyber_hud' );
	}
}

// BUG 18 fix — register on init instead of at file scope so WordPress shortcode
// infrastructure is fully initialised before the hook fires.
add_action( 'init', 'tw_register_cyber_hud_shortcode' );
