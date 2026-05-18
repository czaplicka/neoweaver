<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_loom_of_fate_shortcode' ) ) {
	function tw_loom_of_fate_shortcode() {
		if ( ! is_page_template(
			array(
				'templates/adventure.php',
				'templates/character-public-profile.php',
			)
		) ) {
			return '';
		}

		$char_id = function_exists( 'tw_get_current_character_id' ) ? tw_get_current_character_id() : '';
		$char_id = $char_id ?: '';

		$uid = 'loom_' . uniqid();

		wp_enqueue_script( 'chartjs' );

		$plugin_url = plugin_dir_url( __FILE__ );
		$plugin_dir = plugin_dir_path( __FILE__ );

		$js_rel  = 'fate-of-loom.js';
		$js_path = $plugin_dir . $js_rel;
		$js_url  = $plugin_url . $js_rel;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0';

		wp_enqueue_script(
			'tw-fate-of-loom',
			$js_url,
			array( 'chartjs' ),
			$js_ver,
			true
		);

		ob_start();
		?>

		<div
			id="tw-loom-container-<?php echo esc_attr( $uid ); ?>"
			class="tw-loom-container"
			data-loom-root="1"
			data-loom-uid="<?php echo esc_attr( $uid ); ?>"
			data-character-id="<?php echo esc_attr( $char_id ); ?>"
		>
			<div class="tw-loom-bg-grid"></div>

			<h2 class="tw-loom-title">THE LOOM OF FATE</h2>

			<div class="tw-loom-chart-wrapper">
				<canvas id="fateChart-<?php echo esc_attr( $uid ); ?>"></canvas>

				<div
					id="label-brutality-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-label tw-loom-label-brutality"
				>
					BRUTALITY <span>0</span>
				</div>

				<div
					id="label-cunning-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-label tw-loom-label-cunning"
				>
					CUNNING <span>0</span>
				</div>

				<div
					id="label-intellect-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-label tw-loom-label-intellect"
				>
					INTELLECT <span>0</span>
				</div>

				<div
					id="label-spirit-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-label tw-loom-label-spirit"
				>
					SPIRIT <span>0</span>
				</div>

				<div
					id="label-presence-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-label tw-loom-label-presence"
				>
					PRESENCE <span>0</span>
				</div>
			</div>

			<div
				id="archetype-container-<?php echo esc_attr( $uid ); ?>"
				class="tw-loom-archetype-container"
			>
				<div
					id="archetype-name-<?php echo esc_attr( $uid ); ?>"
					class="tw-loom-archetype-name"
				>
					AWAITING SYNC...
				</div>
			</div>
		</div>

		<?php
		return ob_get_clean();
	}

	add_shortcode( 'tw_loom_of_fate', 'tw_loom_of_fate_shortcode' );
}
