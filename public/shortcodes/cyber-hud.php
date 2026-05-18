<?php
/**
 * TALE WEAVER – Cyber HUD Overlay
 * Shortcode: [cyber_hud]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function display_cyber_hud() {
	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return '';
	}

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

$js_rel  = 'assets/js/public/cyber-hud.js';
$js_path = NEOWEAVER_PLUGIN_DIR . $js_rel;
$js_url  = NEOWEAVER_PLUGIN_URL . $js_rel;
$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION;

	wp_enqueue_script(
		'neoweaver-cyber-hud',
		$js_url,
		array(),
		$js_ver,
		true
	);

	wp_localize_script(
		'neoweaver-cyber-hud',
		'twCyberHud',
		array(
			'supabaseUrl'  => trailingslashit( tw_supabase_url() ) . 'rest/v1',
			'supabaseKey'  => tw_supabase_anon_key(),
			'currentUserId'=> (int) $current_user_id,
		)
	);

	ob_start();
	?>

	<div id="hud-wrapper" class="cyber-hud-wrapper">
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
				array( 'id' => 'rep_local',       'l' => 'LOCAL FAME',  'r' => '',       'b' => false ),
				array( 'id' => 'rep_world',       'l' => 'REPUTATION',  'r' => '',       'b' => false ),
				array( 'id' => 'danger',          'l' => 'DANGER',      'r' => '',       'b' => false ),
				array( 'id' => 'stealth',         'l' => 'STEALTH',     'r' => 'DETECT', 'b' => true ),
				array( 'id' => 'order',           'l' => 'CHAOS',       'r' => 'ORDER',  'b' => true ),
				array( 'id' => 'rep_tech_nature', 'l' => 'TECH',        'r' => 'NATURE', 'b' => true ),
				array( 'id' => 'rep_chaos_order', 'l' => 'MAGIC',       'r' => 'SYSTEM', 'b' => true ),
				array( 'id' => 'rep_gold_thief',  'l' => 'GOLD',        'r' => 'THIEF',  'b' => true ),
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
	return ob_get_clean();
}
add_shortcode( 'cyber_hud', 'display_cyber_hud' );
