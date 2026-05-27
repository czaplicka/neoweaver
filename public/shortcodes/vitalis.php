<?php
/**
 * Shortcode: [nw_vitalis]
 *
 * Displays the character vitalis (HP / vitality) panel.
 * Loads the template partial templates/partials/character-vitalis.php if available,
 * otherwise renders a minimal inline fallback.
 *
 * Usage: [nw_vitalis] or [nw_vitalis character_id="uuid"]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nw_shortcode_vitalis' ) ) {
	function nw_shortcode_vitalis( array $atts ): string {
		$atts = shortcode_atts(
			[ 'character_id' => '' ],
			$atts,
			'nw_vitalis'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$character_id = sanitize_text_field( $atts['character_id'] );

		if ( ! $character_id && function_exists( 'nw_get_active_character_id' ) ) {
			$character_id = nw_get_active_character_id( get_current_user_id() );
		}

		if ( ! $character_id ) {
			return '<div class="nw-vitalis nw-vitalis--empty"></div>';
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );

		// Try the template partial first.
		$template = NEOWEAVER_PLUGIN_DIR . 'templates/partials/character-vitalis.php';
		if ( file_exists( $template ) ) {
			ob_start();
			set_query_var( 'nw_character_id', $safe_id );
			load_template( $template, false );
			return ob_get_clean();
		}

		// --- Inline fallback (renders until the template partial is created) ---
		$vitalis = null;
		if ( function_exists( 'nw_supabase_base' ) && function_exists( 'nw_supabase_service_headers' ) ) {
			$url = add_query_arg( [
				'character_id' => 'eq.' . $safe_id,
				'select'       => 'hp_current,hp_max,shield_current,shield_max',
				'limit'        => 1,
			], nw_supabase_base() . 'cyber_character_stats' );

			$res = wp_remote_get( $url, [
				'headers' => nw_supabase_service_headers(),
				'timeout' => 10,
			] );

			if ( ! is_wp_error( $res ) ) {
				$rows    = json_decode( wp_remote_retrieve_body( $res ), true );
				$vitalis = $rows[0] ?? null;
			}
		}

		if ( ! $vitalis ) {
			return '<div class="nw-vitalis nw-vitalis--empty"></div>';
		}

		$hp_cur  = (int) ( $vitalis['hp_current']     ?? 0 );
		$hp_max  = (int) ( $vitalis['hp_max']          ?? 0 );
		$sh_cur  = (int) ( $vitalis['shield_current']  ?? 0 );
		$sh_max  = (int) ( $vitalis['shield_max']       ?? 0 );
		$hp_pct  = $hp_max > 0 ? round( ( $hp_cur / $hp_max ) * 100 ) : 0;
		$sh_pct  = $sh_max > 0 ? round( ( $sh_cur / $sh_max ) * 100 ) : 0;

		ob_start();
		?>
		<div class="nw-vitalis" data-character-id="<?php echo esc_attr( $safe_id ); ?>">
			<div class="nw-vitalis__bar nw-vitalis__bar--hp">
				<span class="nw-vitalis__label">HP</span>
				<div class="nw-vitalis__track">
					<div class="nw-vitalis__fill" style="width:<?php echo esc_attr( $hp_pct ); ?>%"></div>
				</div>
				<span class="nw-vitalis__numbers"><?php echo esc_html( $hp_cur . ' / ' . $hp_max ); ?></span>
			</div>
			<?php if ( $sh_max > 0 ) : ?>
			<div class="nw-vitalis__bar nw-vitalis__bar--shield">
				<span class="nw-vitalis__label">Shield</span>
				<div class="nw-vitalis__track">
					<div class="nw-vitalis__fill" style="width:<?php echo esc_attr( $sh_pct ); ?>%"></div>
				</div>
				<span class="nw-vitalis__numbers"><?php echo esc_html( $sh_cur . ' / ' . $sh_max ); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'nw_vitalis', 'nw_shortcode_vitalis' );
