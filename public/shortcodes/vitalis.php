<?php
/**
 * Shortcode: [nw_vitalis]
 *
 * Displays the character vitalis panel: HP, MP, XP and survival bars
 * (satiety, hydration, rest, sync_rate).
 *
 * Data sources:
 *   current values  — cyber_state_of_the_campaign (hp, mp, xp, satiety, hydration, rest, sync_rate)
 *   max HP / max MP — cyber_characters (hp, mp)
 *
 * Requires campaign_id — either passed as attribute or resolved via the
 * session helpers (get_cyber_active_session_character_id /
 * get_cyber_active_session_campaign_id) defined in supabase-helpers.php.
 *
 * Usage:
 *   [nw_vitalis]
 *   [nw_vitalis character_id="uuid" campaign_id="uuid"]
 *
 * BUG 28 — sync_rate semantics:
 *   sync_rate = 100  → Entropy = 0   (stable, GOOD)
 *   sync_rate =   0  → Entropy = 100 (critical, BAD)
 *   The bar element carries data-inverted="true" and CSS class
 *   nw-vitalis__bar--sync so themes can style it differently from HP/MP.
 *   A data-entropy attribute exposes the complementary value (100 - sync_rate)
 *   for CSS gradient / colour-threshold use.
 *
 * Template override: templates/partials/character-vitalis.php
 *   Variables available inside the template:
 *     $safe_character_id, $safe_campaign_id, $state, $char_max
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nw_shortcode_vitalis' ) ) {
	function nw_shortcode_vitalis( array $atts ): string {
		$atts = shortcode_atts(
			[
				'character_id' => '',
				'campaign_id'  => '',
			],
			$atts,
			'nw_vitalis'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$character_id = sanitize_text_field( $atts['character_id'] );
		$campaign_id  = sanitize_text_field( $atts['campaign_id'] );
		$wp_user_id   = get_current_user_id();

		// BUG 27 fix — nw_get_active_character_id / nw_get_active_campaign_id
		// are not defined in this plugin. Use the session helpers from
		// supabase-helpers.php which ARE defined and maintained.
		if ( ! $character_id && function_exists( 'get_cyber_active_session_character_id' ) ) {
			$character_id = (string) get_cyber_active_session_character_id( $wp_user_id );
		}
		if ( ! $campaign_id && function_exists( 'get_cyber_active_session_campaign_id' ) ) {
			$campaign_id = (string) get_cyber_active_session_campaign_id( $wp_user_id );
		}

		if ( ! $character_id || ! $campaign_id ) {
			return '<div class="nw-vitalis nw-vitalis--empty"></div>';
		}

		$safe_character_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );
		$safe_campaign_id  = preg_replace( '/[^a-zA-Z0-9\-]/', '', $campaign_id );

		// Try the template partial first.
		$template = NEOWEAVER_PLUGIN_DIR . 'templates/partials/character-vitalis.php';
		if ( file_exists( $template ) ) {
			$state    = nw_vitalis_fetch_state( $safe_character_id, $safe_campaign_id );
			$char_max = nw_vitalis_fetch_char_max( $safe_character_id );
			ob_start();
			include $template;
			return ob_get_clean();
		}

		// --- Inline fallback ---
		$state    = nw_vitalis_fetch_state( $safe_character_id, $safe_campaign_id );
		$char_max = nw_vitalis_fetch_char_max( $safe_character_id );

		if ( ! $state ) {
			return '<div class="nw-vitalis nw-vitalis--empty"></div>';
		}

		$hp_cur   = (int) ( $state['hp']          ?? 0 );
		$mp_cur   = (int) ( $state['mp']          ?? 0 );
		$xp       = (int) ( $state['xp']          ?? 0 );
		$satiety  = (int) ( $state['satiety']      ?? 100 );
		$hydro    = (int) ( $state['hydration']    ?? 100 );
		$rest     = (int) ( $state['rest']         ?? 100 );
		$sync     = (int) ( $state['sync_rate']    ?? 100 );

		$hp_max   = (int) ( $char_max['hp']        ?? 0 );
		$mp_max   = (int) ( $char_max['mp']        ?? 0 );

		$hp_pct   = $hp_max > 0 ? round( ( $hp_cur / $hp_max ) * 100 ) : 0;
		$mp_pct   = $mp_max > 0 ? round( ( $mp_cur / $mp_max ) * 100 ) : 0;

		// BUG 28 — entropy is the complement of sync_rate.
		// data-entropy lets CSS apply colour thresholds on the *danger* axis.
		$entropy  = 100 - $sync;

		ob_start();
		?>
		<div class="nw-vitalis" data-character-id="<?php echo esc_attr( $safe_character_id ); ?>" data-campaign-id="<?php echo esc_attr( $safe_campaign_id ); ?>">

			<div class="nw-vitalis__bar nw-vitalis__bar--hp">
				<span class="nw-vitalis__label">HP</span>
				<div class="nw-vitalis__track"><div class="nw-vitalis__fill" style="width:<?php echo esc_attr( $hp_pct ); ?>%"></div></div>
				<span class="nw-vitalis__numbers"><?php echo esc_html( $hp_cur . ' / ' . $hp_max ); ?></span>
			</div>

			<?php if ( $mp_max > 0 ) : ?>
			<div class="nw-vitalis__bar nw-vitalis__bar--mp">
				<span class="nw-vitalis__label">MP</span>
				<div class="nw-vitalis__track"><div class="nw-vitalis__fill" style="width:<?php echo esc_attr( $mp_pct ); ?>%"></div></div>
				<span class="nw-vitalis__numbers"><?php echo esc_html( $mp_cur . ' / ' . $mp_max ); ?></span>
			</div>
			<?php endif; ?>

			<div class="nw-vitalis__xp">XP: <?php echo esc_html( $xp ); ?></div>

			<div class="nw-vitalis__bars">
				<?php
				// Survival bars — all 0=bad, 100=good (normal direction).
				$survival_bars = [
					'satiety'  => [ 'label' => 'Satiety',   'value' => $satiety, 'inverted' => false ],
					'hydro'    => [ 'label' => 'Hydration', 'value' => $hydro,   'inverted' => false ],
					'rest'     => [ 'label' => 'Rest',      'value' => $rest,    'inverted' => false ],
					// BUG 28 fix — sync bar carries data-inverted and data-entropy.
					// CSS class nw-vitalis__bar--sync allows theme to use a distinct
					// colour ramp (e.g. cyan→red) that reflects entropy direction,
					// so sync=20 (critical entropy=80) is visually alarming — not
					// identical to hp=20 (low health).
					'sync'     => [ 'label' => 'Sync',      'value' => $sync,    'inverted' => true, 'entropy' => $entropy ],
				];
				foreach ( $survival_bars as $key => $bar ) : ?>
				<div
					class="nw-vitalis__bar nw-vitalis__bar--<?php echo esc_attr( $key ); ?>"
					<?php if ( ! empty( $bar['inverted'] ) ) : ?>data-inverted="true"<?php endif; ?>
					<?php if ( isset( $bar['entropy'] ) ) : ?>data-entropy="<?php echo esc_attr( $bar['entropy'] ); ?>"<?php endif; ?>
				>
					<span class="nw-vitalis__label"><?php echo esc_html( $bar['label'] ); ?></span>
					<div class="nw-vitalis__track"><div class="nw-vitalis__fill" style="width:<?php echo esc_attr( $bar['value'] ); ?>%"></div></div>
					<span class="nw-vitalis__numbers"><?php echo esc_html( $bar['value'] ); ?>/100</span>
				</div>
				<?php endforeach; ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * Fetch current state row from cyber_state_of_the_campaign.
 *
 * BUG 26 fix — check HTTP status before decoding. A Supabase 4xx/5xx
 * returns a JSON error object that decodes to a non-null array; without
 * the status check $rows[0] is null and the shortcode silently shows zeros.
 *
 * @return array|null
 */
if ( ! function_exists( 'nw_vitalis_fetch_state' ) ) {
	function nw_vitalis_fetch_state( string $character_id, string $campaign_id ): ?array {
		if ( ! function_exists( 'nw_supabase_base' ) || ! function_exists( 'nw_supabase_service_headers' ) ) {
			return null;
		}

		$url = add_query_arg( [
			'character_id' => 'eq.' . $character_id,
			'campaign_id'  => 'eq.' . $campaign_id,
			'select'       => 'hp,mp,xp,satiety,hydration,rest,sync_rate',
			'limit'        => 1,
		], nw_supabase_base() . 'cyber_state_of_the_campaign' );

		$res = wp_remote_get( $url, [
			'headers' => nw_supabase_service_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'nw_vitalis_fetch_state — ' . $res->get_error_message() );
			return null;
		}

		// BUG 26 fix — reject non-2xx before decoding.
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'nw_vitalis_fetch_state — HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
			return null;
		}

		$rows = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $rows ) ) {
			return null;
		}

		return $rows[0] ?? null;
	}
}

/**
 * Fetch max HP and max MP from cyber_characters.
 *
 * BUG 26 fix — check HTTP status before decoding.
 *
 * @return array  ['hp' => int, 'mp' => int]
 */
if ( ! function_exists( 'nw_vitalis_fetch_char_max' ) ) {
	function nw_vitalis_fetch_char_max( string $character_id ): array {
		if ( ! function_exists( 'nw_supabase_base' ) || ! function_exists( 'nw_supabase_service_headers' ) ) {
			return [ 'hp' => 0, 'mp' => 0 ];
		}

		$url = add_query_arg( [
			'id'     => 'eq.' . $character_id,
			'select' => 'hp,mp',
			'limit'  => 1,
		], nw_supabase_base() . 'cyber_characters' );

		$res = wp_remote_get( $url, [
			'headers' => nw_supabase_service_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			error_log( 'nw_vitalis_fetch_char_max — ' . $res->get_error_message() );
			return [ 'hp' => 0, 'mp' => 0 ];
		}

		// BUG 26 fix — reject non-2xx before decoding.
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'nw_vitalis_fetch_char_max — HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
			return [ 'hp' => 0, 'mp' => 0 ];
		}

		$rows = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $rows ) ) {
			return [ 'hp' => 0, 'mp' => 0 ];
		}

		return [
			'hp' => (int) ( $rows[0]['hp'] ?? 0 ),
			'mp' => (int) ( $rows[0]['mp'] ?? 0 ),
		];
	}
}

add_shortcode( 'nw_vitalis', 'nw_shortcode_vitalis' );
