<?php
/**
 * Shortcode: [tw_compass]
 * Renderuje interaktywny kompas pobierający dane z cyber_world_map.
 * Style i JS są enqueue'owane tylko wtedy, gdy shortcode jest renderowany.
 */

add_shortcode( 'tw_compass', 'tw_compass_render' );

function tw_compass_render() {
	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		return '';
	}

	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return '';
	}

	$css_rel  = 'assets/css/public/compass.css';
	$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
	$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
	$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION;

	$js_rel  = 'assets/js/public/compass.js';
	$js_path = NEOWEAVER_PLUGIN_DIR . $js_rel;
	$js_url  = NEOWEAVER_PLUGIN_URL . $js_rel;
	$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION;

	if ( ! wp_style_is( 'neoweaver-compass', 'enqueued' ) ) {
		wp_enqueue_style(
			'neoweaver-compass',
			$css_url,
			array(),
			$css_ver
		);
	}

if ( ! wp_script_is( 'neoweaver-compass', 'enqueued' ) ) {
	wp_enqueue_script(
		'neoweaver-compass',
		$js_url,
		array( 'tw-adventure' ), // albo lepiej: handle skryptu, który tworzy window.twSupabase
		$js_ver,
		true
	);

	wp_add_inline_script(
		'neoweaver-compass',
		'window.twCompassData = ' . wp_json_encode( array(
			'wpUserId'        => (int) $wp_user_id,
			'activeLocationId'=> (int) ( get_user_game_data_from_supabase( $wp_user_id )['active_location_id'] ?? 0 ),
		) ) . ';',
		'before'
	);
}

	ob_start();
	?>
	<div id="tw-compass-container" class="tw-compass-wrapper">
		<div class="tw-compass-grid">
			<div class="tw-compass-cell tw-n" data-dir="n">
				<span class="dir-label">N</span>
				<div class="loc-name">-</div>
			</div>

			<div class="tw-compass-cell tw-w" data-dir="w">
				<span class="dir-label">W</span>
				<div class="loc-name">-</div>
			</div>

			<div class="tw-compass-center">
				<div class="tw-compass-icon">&#x27E1;</div>
				<div id="tw-current-loc-name">Scanning...</div>
			</div>

			<div class="tw-compass-cell tw-e" data-dir="e">
				<span class="dir-label">E</span>
				<div class="loc-name">-</div>
			</div>

			<div class="tw-compass-cell tw-s" data-dir="s">
				<span class="dir-label">S</span>
				<div class="loc-name">-</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
