<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_loom_of_fate_shortcode' ) ) {
	function tw_loom_of_fate_shortcode(): string {
		if ( ! is_page_template(
			array(
				'templates/adventure.php',
				'templates/public-character-profile.php',
			)
		) ) {
			return '';
		}

		if ( function_exists( 'tw_enqueue_fate_of_loom_assets' ) ) {
			tw_enqueue_fate_of_loom_assets();
		}

		$char_id = function_exists( 'tw_get_current_character_id' ) ? tw_get_current_character_id() : '';
		$char_id = $char_id ?: '';

		$uid = 'loom_' . uniqid( '', false );

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

				<div id="label-brutality-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-brutality">
					BRUTALITY <span>0</span>
				</div>

				<div id="label-cunning-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-cunning">
					CUNNING <span>0</span>
				</div>

				<div id="label-intellect-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-intellect">
					INTELLECT <span>0</span>
				</div>

				<div id="label-spirit-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-spirit">
					SPIRIT <span>0</span>
				</div>

				<div id="label-presence-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-presence">
					PRESENCE <span>0</span>
				</div>

				<div id="archetype-container-<?php echo esc_attr( $uid ); ?>" class="tw-loom-archetype-container">
					<div id="archetype-name-<?php echo esc_attr( $uid ); ?>" class="tw-loom-archetype-name">
						AWAITING SYNC...
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'tw_loom_of_fate', 'tw_loom_of_fate_shortcode' );
}
