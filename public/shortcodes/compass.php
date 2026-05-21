<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_compass_render' ) ) {
	function tw_compass_render(): string {
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return '';
		}

		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return '';
		}

		if ( function_exists( 'tw_enqueue_compass_assets' ) ) {
			tw_enqueue_compass_assets();
		}

		ob_start();
		?>
		<div id="tw-compass-container" class="tw-compass-wrapper" data-tw-compass="1">
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

		return (string) ob_get_clean();
	}
}

add_shortcode( 'tw_compass', 'tw_compass_render' );
