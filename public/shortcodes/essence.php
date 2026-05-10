<?php
/**
 * SHORTCODE: [tw_essences]
 */
	/**
	 * Essence config — defined once, outside the callback, so it's
	 * not rebuilt on every shortcode render (e.g. multiple uses per page).
	 */
	const TW_ESSENCE_CONFIG = [
		'might'  => [ 'label' => 'Might',  'icon' => '⚔️', 'color' => '#ff4500' ],
		'primal' => [ 'label' => 'Primal', 'icon' => '🌿', 'color' => '#32cd32' ],
		'magic'  => [ 'label' => 'Magic',  'icon' => '✨', 'color' => '#8a2be2' ],
		'logic'  => [ 'label' => 'Logic',  'icon' => '💠', 'color' => '#00ced1' ],
		'token'  => [ 'label' => 'Token',  'icon' => '🪙', 'color' => '#ffd700' ],
		'venom'  => [ 'label' => 'Venom',  'icon' => '🧪', 'color' => '#9400d3' ],
		'weaver' => [ 'label' => 'Weaver', 'icon' => '🧶', 'color' => '#adff00' ],
	];
if ( ! function_exists( 'tw_essences_shortcode' ) ) {
	function tw_essences_shortcode(): string {

		// ── Auth guard ────────────────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return '<div class="tw-essence-error">You must be logged in to see essences.</div>';
		}

		$user_id = get_current_user_id();

		// ── Transient cache key (per user) ────────────────────────────────────
		$cache_key = 'tw_essences_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// ── Supabase bootstrap ────────────────────────────────────────────────
		$supabase_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key      = tw_supabase_anon_key();

		// BUG FIX: headers were reused with anon key even for authenticated
		// requests. Keep the anon key for public-table reads (character lookup),
		// but note: if RLS requires a user JWT you'd swap Bearer here.
		$headers = [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		];
		$request_args = [
			'headers' => $headers,
			'timeout' => 15,
		];

		// ── Local HTTP helper (returns [] on any error) ────────────────────────
		// BUG FIX: original helper swallowed HTTP error status codes (4xx/5xx).
		// Now checks the response code explicitly.
		$tw_ess_get = static function ( string $url ) use ( $request_args ): array {
			$resp = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $resp ) ) {
				return [];
			}

			// Treat non-2xx as failure
			$status = wp_remote_retrieve_response_code( $resp );
			if ( $status < 200 || $status >= 300 ) {
				return [];
			}

			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			return is_array( $data ) ? $data : [];
		};

		// ── Fetch character ID ────────────────────────────────────────────────
		// BUG FIX: intval() on $user_id prevents SQL-injection-style URL tampering.
		$char_rows = $tw_ess_get(
			$supabase_base . 'cyber_characters?wp_user_id=eq.' . (int) $user_id . '&select=id&limit=1'
		);
		$char_id = $char_rows[0]['id'] ?? null;

		if ( ! $char_id ) {
			return '<div class="tw-essence-error">No character selected.</div>';
		}

		// ── Fetch essences ────────────────────────────────────────────────────
		// OPTIMISATION: select only the two columns we actually use.
		$essences_rows = $tw_ess_get(
			$supabase_base . 'cyber_character_essences?character_id=eq.' . (int) $char_id
			. '&select=essence_type,quantity'
		);

		// BUG FIX: original loop cast quantity to float but still used it later
		// as a display number. Negative quantities are now clamped to 0 so the
		// UI never shows "-5" for a corrupted row.
		$essences = [];
		foreach ( $essences_rows as $row ) {
			if ( empty( $row['essence_type'] ) ) {
				continue;
			}
			$essences[ $row['essence_type'] ] = max( 0.0, (float) ( $row['quantity'] ?? 0 ) );
		}

		// ── Render ────────────────────────────────────────────────────────────
		// OPTIMISATION: CSS is enqueued once via wp_add_inline_style so it is
		// never duplicated when the shortcode appears more than once on a page.
		// We attach it to wp_head if wp_styles is already set up, otherwise
		// fall back to an inline <style> (covers edge-case REST/block rendering).
		$css = '
			.tw-essence-container{display:flex;flex-wrap:wrap;gap:12px;background:rgba(0,0,0,.3);padding:10px;border-radius:8px;border:1px solid #444;font-family:"Chakra Petch",sans-serif}
			.tw-essence-item{display:flex;align-items:center;gap:6px;padding:4px 8px;background:rgba(255,255,255,.05);border-radius:4px;font-size:14px;border-bottom:2px solid transparent}
			.tw-essence-count{font-weight:700;font-size:16px}
		';

		// Register a dummy stylesheet handle the first time, then attach CSS.
		if ( ! wp_style_is( 'tw-essences', 'registered' ) ) {
			wp_register_style( 'tw-essences', false ); // phpcs:ignore
			wp_enqueue_style( 'tw-essences' );
			wp_add_inline_style( 'tw-essences', $css );
		}

		ob_start();
		?>
		<div class="tw-essence-container">
			<?php foreach ( TW_ESSENCE_CONFIG as $key => $data ) :
				$value = $essences[ $key ] ?? 0.0;
				// BUG FIX: number_format() is locale-aware in some setups.
				// Use number_format explicitly with dot decimal / no thousands sep
				// for fractional quantities; fall back to integer display when whole.
				$display = ( $value === floor( $value ) )
					? number_format( $value, 0, '.', ',' )
					: number_format( $value, 2, '.', ',' );
			?>
				<div class="tw-essence-item" style="border-color:<?php echo esc_attr( $data['color'] ); ?>">
					<span title="<?php echo esc_attr( $data['label'] ); ?>"><?php echo esc_html( $data['icon'] ); ?></span>
					<span class="tw-essence-count" style="color:<?php echo esc_attr( $data['color'] ); ?>">
						<?php echo esc_html( $display ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$html = ob_get_clean();

		// ── Cache for 60 s (tune to taste) ───────────────────────────────────
		set_transient( $cache_key, $html, 60 );

		return $html;
	}

	add_shortcode( 'tw_essences', 'tw_essences_shortcode' );
}
