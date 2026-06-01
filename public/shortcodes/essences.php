<?php
/**
 * Shortcode: [nw_essences]
 *
 * Displays the current character's essence/attribute panel.
 * Usage: [nw_essences] or [nw_essences character_id="uuid"]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nw_shortcode_essences' ) ) {
	function nw_shortcode_essences( array $atts ): string {
		$atts = shortcode_atts(
			[ 'character_id' => '' ],
			$atts,
			'nw_essences'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$character_id = sanitize_text_field( $atts['character_id'] );

		// Fall back to the active character stored in the session / transient.
		if ( ! $character_id && function_exists( 'nw_get_active_character_id' ) ) {
			$character_id = nw_get_active_character_id( get_current_user_id() );
		}

		if ( ! $character_id ) {
			return '<div class="nw-essences nw-essences--empty"></div>';
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );

		// Fetch character row — use actual column names: body, reflex, mind, spirit.
		$character = null;
		if ( function_exists( 'nw_supabase_base' ) && function_exists( 'nw_supabase_service_headers' ) ) {
			$url = add_query_arg( [
				'id'     => 'eq.' . $safe_id,
				'select' => 'id,name,body,reflex,mind,spirit,race_id,class_id',
				'limit'  => 1,
			], nw_supabase_base() . 'cyber_characters' );

			$res = wp_remote_get( $url, [
				'headers' => nw_supabase_service_headers(),
				'timeout' => 10,
			] );

			if ( ! is_wp_error( $res ) ) {
				$rows      = json_decode( wp_remote_retrieve_body( $res ), true );
				$character = $rows[0] ?? null;
			}
		}

		if ( ! $character ) {
			return '<div class="nw-essences nw-essences--empty"></div>';
		}

		$essences = [
			'body'   => (int) ( $character['body']   ?? 0 ),
			'reflex' => (int) ( $character['reflex'] ?? 0 ),
			'mind'   => (int) ( $character['mind']   ?? 0 ),
			'spirit' => (int) ( $character['spirit'] ?? 0 ),
		];

		ob_start();
		?>
		<div class="nw-essences" data-character-id="<?php echo esc_attr( $safe_id ); ?>">
			<?php foreach ( $essences as $key => $value ) : ?>
				<div class="nw-essences__item nw-essences__item--<?php echo esc_attr( $key ); ?>">
					<span class="nw-essences__label"><?php echo esc_html( ucfirst( $key ) ); ?></span>
					<span class="nw-essences__value"><?php echo esc_html( $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'nw_essences', 'nw_shortcode_essences' );
