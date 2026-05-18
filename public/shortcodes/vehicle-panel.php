<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode: [neoweave_vehicle_panel vehicle_id="UUID" character_id="UUID"]
 */

/**
 * Enqueue assetów tylko wtedy, gdy shortcode występuje w treści.
 */
function neoweaver_enqueue_vehicle_assets() {
	if ( is_admin() ) {
		return;
	}

	global $post;

	if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'neoweave_vehicle_panel' ) ) {
		return;
	}

	$plugin_url = plugin_dir_url( dirname( __FILE__, 1 ) );
	$plugin_dir = plugin_dir_path( dirname( __FILE__, 1 ) );

	$css_rel  = 'public/assets/css/vehicle-panel.css';
	$css_path = $plugin_dir . $css_rel;
	$css_url  = $plugin_url . $css_rel;
	$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.1';

	$js_rel  = 'public/assets/js/vehicle-panel.js';
	$js_path = $plugin_dir . $js_rel;
	$js_url  = $plugin_url . $js_rel;
	$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.1';

	wp_enqueue_style(
		'neoweave-vehicle-css',
		$css_url,
		array(),
		$css_ver
	);

	wp_enqueue_script(
		'neoweave-vehicle-js',
		$js_url,
		array(),
		$js_ver,
		true
	);

	wp_localize_script(
		'neoweave-vehicle-js',
		'neoweaveVehicle',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweave_vehicle_nonce' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_vehicle_assets' );

/**
 * Render shortcode.
 */
function neoweave_vehicle_panel_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'vehicle_id'   => '',
			'character_id' => '',
		),
		$atts,
		'neoweave_vehicle_panel'
	);

	$vehicle_id   = sanitize_text_field( $atts['vehicle_id'] );
	$character_id = sanitize_text_field( $atts['character_id'] );

	if ( '' === $vehicle_id ) {
		return '<div class="neoweave-vehicle-error">Missing vehicle_id.</div>';
	}

	$instance_id = 'nw-vehicle-' . wp_generate_uuid4();

	ob_start();
	?>
	<div
		id="<?php echo esc_attr( $instance_id ); ?>"
		class="terminal-border neoweave-vehicle-interface"
		data-vehicle-panel-root="1"
		data-vehicle-id="<?php echo esc_attr( $vehicle_id ); ?>"
		data-character-id="<?php echo esc_attr( $character_id ); ?>"
	>
		<h3 class="neoweave-neon-text">VEHICLE DIAGNOSTICS</h3>

		<div class="vehicle-stats">
			<div class="stat-row">
				<span>DURABILITY:</span>
				<div class="progress-bar">
					<div class="v-hp-bar" style="width: 80%; background: #adff00;"></div>
				</div>
				<span class="v-hp-text">80/100</span>
			</div>

			<div class="stat-row">
				<span>FUEL/ENERGY:</span>
				<div class="progress-bar">
					<div class="v-fuel-bar" style="width: 40%; background: #00e5ff;"></div>
				</div>
				<span class="v-fuel-text">40/100</span>
			</div>
		</div>

		<div class="vehicle-blueprint">
			<div class="slot-grid">
				<div class="v-slot core" data-slot="slot_core">
					<label>CORE</label>
					<div class="slot-occupant" data-occupant="core">V8 Steam Engine</div>
				</div>

				<div class="v-slot lateral" data-slot="slot_lateral_l">
					<label>LATERAL L</label>
					<div class="slot-occupant" data-occupant="lateral-l">---</div>
				</div>

				<div class="v-slot lateral" data-slot="slot_lateral_r">
					<label>LATERAL R</label>
					<div class="slot-occupant" data-occupant="lateral-r">---</div>
				</div>

				<div class="v-slot utility" data-slot="slot_utility">
					<label>CARGO/UTIL</label>
					<div class="slot-occupant" data-occupant="utility">Trunk (Cap: 50)</div>
				</div>
			</div>
		</div>

		<hr class="terminal-hr">

		<h4 class="neoweave-neon-text">GARAGE (STORAGE)</h4>
		<div class="garage-container" data-slot="garage">
			<div class="module-item" draggable="true" id="<?php echo esc_attr( $instance_id . '-mod-gatling-001' ); ?>" data-type="lateral">
				Side Gatling [Mass: 5]
			</div>
			<div class="module-item" draggable="true" id="<?php echo esc_attr( $instance_id . '-mod-smoke-002' ); ?>" data-type="utility">
				Smoke Launcher [Mass: 2]
			</div>
		</div>

		<div class="cargo-manifest">
			<h4>
				CARGO MANIFEST:
				<span class="cargo-weight">12</span> /
				<span class="cargo-max">50</span>
			</h4>

			<ul class="cargo-list">
				<li>* Scrap Metal (Mass: 10)</li>
				<li>* Ration Pack (Mass: 2)</li>
			</ul>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'neoweave_vehicle_panel', 'neoweave_vehicle_panel_shortcode' );
