<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_world_news_shortcode' ) ) {
	function tw_world_news_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'world_id'     => '',
				'character_id' => '',
				'current_day'  => '',
				'current_hour' => '',
				'clearance'    => 0,
			),
			$atts,
			'tw_world_news'
		);

		$world_id     = sanitize_text_field( (string) $atts['world_id'] );
		$character_id = sanitize_text_field( (string) $atts['character_id'] );
		$current_day  = '' !== (string) $atts['current_day'] ? intval( $atts['current_day'] ) : '';
		$current_hour = '' !== (string) $atts['current_hour'] ? intval( $atts['current_hour'] ) : '';
		$clearance    = intval( $atts['clearance'] );

		if ( '' === $character_id && function_exists( 'tw_get_current_character_id' ) ) {
			$character_id = (string) tw_get_current_character_id();
		}

		if ( function_exists( 'tw_enqueue_world_news_assets' ) ) {
			tw_enqueue_world_news_assets(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'tw_world_news_nonce' ),
				)
			);
		}

		ob_start();
		?>
		<div
			class="tw-world-news"
			data-world-news-root="1"
			data-world-id="<?php echo esc_attr( $world_id ); ?>"
			data-character-id="<?php echo esc_attr( $character_id ); ?>"
			data-current-day="<?php echo '' !== (string) $current_day ? esc_attr( (string) $current_day ) : ''; ?>"
			data-current-hour="<?php echo '' !== (string) $current_hour ? esc_attr( (string) $current_hour ) : ''; ?>"
			data-clearance="<?php echo esc_attr( (string) $clearance ); ?>"
		>
			<div class="tw-world-news__header">
				<h3 class="tw-world-news__title">WORLD NEWS</h3>
				<div class="tw-world-news__count" data-world-news-count="1">0</div>
			</div>

			<div class="tw-world-news__state" data-world-news-state="1">SYNCING FEED...</div>
			<div class="tw-world-news__list" data-world-news-list="1"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	add_shortcode( 'tw_world_news', 'tw_world_news_shortcode' );
}
