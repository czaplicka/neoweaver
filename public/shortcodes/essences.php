<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Essence config — zdefiniowane raz jako stała poza callbackiem,
 * żeby nie była przebudowywana przy każdym renderze shortcode.
 *
 * Używamy define() zamiast const na poziomie pliku,
 * żeby uniknąć fatal error przy ponownym include.
 */
if ( ! defined( 'TW_ESSENCE_CONFIG' ) ) {
	define(
		'TW_ESSENCE_CONFIG',
		[
			'might'  => [ 'label' => 'Might',  'icon' => '⚔️', 'color' => '#ff4500' ],
			'primal' => [ 'label' => 'Primal', 'icon' => '🌿', 'color' => '#32cd32' ],
			'magic'  => [ 'label' => 'Magic',  'icon' => '✨', 'color' => '#8a2be2' ],
			'logic'  => [ 'label' => 'Logic',  'icon' => '💠', 'color' => '#00ced1' ],
			'token'  => [ 'label' => 'Token',  'icon' => '🪙', 'color' => '#ffd700' ],
			'venom'  => [ 'label' => 'Venom',  'icon' => '🧪', 'color' => '#9400d3' ],
			'weaver' => [ 'label' => 'Weaver', 'icon' => '🧶', 'color' => '#adff00' ],
		]
	);
}

if ( ! function_exists( 'tw_essences_shortcode' ) ) {
	function tw_essences_shortcode(): string {

		// ── Auth guard ────────────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return '<div class="tw-essence-error">You must be logged in to see essences.</div>';
		}

		$user_id = (int) get_current_user_id();

		// ── Enqueue assets (bezpieczne po stronie frontend, poza <head>) ───
		// Shortcode może się pojawić po wp_head, więc enqueue wywołujemy tutaj
		// jako fallback — assets/essences.php już je załadował przez template,
		// więc wp_enqueue zignoruje duplikat.
		if ( function_exists( 'tw_enqueue_essences_assets' ) ) {
			tw_enqueue_essences_assets();
		}

		// ── Transient cache (per user) ────────────────────────────────────
		$cache_key = 'tw_essences_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		// ── Supabase bootstrap ────────────────────────────────────────────
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<div class="tw-essence-error">Supabase not configured.</div>';
		}

		$supabase_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key      = tw_supabase_anon_key();

		$request_args = [
			'headers' => [
				'apikey'        => $anon_key,
				'Authorization' => 'Bearer ' . $anon_key,
			],
			'timeout' => 15,
		];

		// ── HTTP helper — zwraca [] przy każdym błędzie ───────────────────
		$tw_ess_get = static function ( string $url ) use ( $request_args ): array {
			$resp = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $resp ) ) {
				return [];
			}

			$status = (int) wp_remote_retrieve_response_code( $resp );
			if ( $status < 200 || $status >= 300 ) {
				return [];
			}

			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			return is_array( $data ) ? $data : [];
		};

		// ── Fetch character ID ────────────────────────────────────────────
		$char_rows = $tw_ess_get(
			$supabase_base . 'cyber_characters?wp_user_id=eq.' . $user_id . '&select=id&limit=1'
		);
		$char_id = isset( $char_rows[0]['id'] ) ? (int) $char_rows[0]['id'] : null;

		if ( ! $char_id ) {
			return '<div class="tw-essence-error">No character selected.</div>';
		}

		// ── Fetch essences ────────────────────────────────────────────────
		$essences_rows = $tw_ess_get(
			$supabase_base . 'cyber_character_essences?character_id=eq.' . $char_id
			. '&select=essence_type,quantity'
		);

		$essences = [];
		foreach ( $essences_rows as $row ) {
			if ( empty( $row['essence_type'] ) ) {
				continue;
			}
			// Ilości nigdy nie pokazujemy jako ujemne.
			$essences[ $row['essence_type'] ] = max( 0.0, (float) ( $row['quantity'] ?? 0 ) );
		}

		// ── Render ────────────────────────────────────────────────────────
		ob_start();
		?>
		<div class="tw-essence-container">
			<?php foreach ( TW_ESSENCE_CONFIG as $key => $data ) :
				$value = $essences[ $key ] ?? 0.0;
				// Liczby całkowite bez kropki, ułamkowe z 2 miejscami.
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
		$html = (string) ob_get_clean();

		// Cache 60 s per user.
		set_transient( $cache_key, $html, 60 );

		return $html;
	}

	add_shortcode( 'tw_essences', 'tw_essences_shortcode' );
}
