<?php
/**
 * Shortcode: [neoweave_vehicle_panel vehicle_id="UUID" character_id="UUID"]
 */

function neoweaver_enqueue_vehicle_assets() {
	if ( is_admin() ) {
		return;
	}

	global $post;

	if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'neoweave_vehicle_panel' ) ) {
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
}
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_vehicle_assets' );

function neoweave_vehicle_panel_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'vehicle_id'   => '',
			'character_id' => '',
		),
		$atts
	);

	ob_start();
	?>
	<div
		id="neoweave-vehicle-interface"
		class="terminal-border"
		data-vehicle-id="<?php echo esc_attr( $atts['vehicle_id'] ); ?>"
		data-character-id="<?php echo esc_attr( $atts['character_id'] ); ?>"
	>
		<h3 class="neoweave-neon-text">VEHICLE DIAGNOSTICS</h3>

		<div class="vehicle-stats">
			<div class="stat-row">
				<span>DURABILITY:</span>
				<div class="progress-bar">
					<div id="v-hp-bar" style="width: 80%; background: #adff00;"></div>
				</div>
				<span id="v-hp-text">80/100</span>
			</div>
			<div class="stat-row">
				<span>FUEL/ENERGY:</span>
				<div class="progress-bar">
					<div id="v-fuel-bar" style="width: 40%; background: #00e5ff;"></div>
				</div>
				<span id="v-fuel-text">40/100</span>
			</div>
		</div>

		<div class="vehicle-blueprint">
			<div class="slot-grid">
				<div class="v-slot core" data-slot="slot_core">
					<label>CORE</label>
					<div class="slot-occupant" id="active-core">V8 Steam Engine</div>
				</div>

				<div class="v-slot lateral" data-slot="slot_lateral_l">
					<label>LATERAL L</label>
					<div class="slot-occupant" id="active-lateral-l">---</div>
				</div>

				<div class="v-slot lateral" data-slot="slot_lateral_r">
					<label>LATERAL R</label>
					<div class="slot-occupant" id="active-lateral-r">---</div>
				</div>

				<div class="v-slot utility" data-slot="slot_utility">
					<label>CARGO/UTIL</label>
					<div class="slot-occupant" id="active-utility">Trunk (Cap: 50)</div>
				</div>
			</div>
		</div>

		<hr class="terminal-hr">

		<h4 class="neoweave-neon-text">GARAGE (STORAGE)</h4>
		<div id="garage-container" data-slot="garage">
			<div class="module-item" draggable="true" id="mod-gatling-001" data-type="lateral">
				Side Gatling [Mass: 5]
			</div>
			<div class="module-item" draggable="true" id="mod-smoke-002" data-type="utility">
				Smoke Launcher [Mass: 2]
			</div>
		</div>

		<div class="cargo-manifest">
			<h4>CARGO MANIFEST: <span id="cargo-weight">12</span> / <span id="cargo-max">50</span></h4>
			<ul id="cargo-list">
				<li>* Scrap Metal (Mass: 10)</li>
				<li>* Ration Pack (Mass: 2)</li>
			</ul>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'neoweave_vehicle_panel', 'neoweave_vehicle_panel_shortcode' );
