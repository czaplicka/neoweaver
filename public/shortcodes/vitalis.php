<?php
/**
 * Shortcode: [tw_vitalis_panel]
 * NeoWeaver — 3-kaflowy panel biometrów (Vitalis HUD).
 *
 * Wymaga:
 * - w zasięgu: $char_data, $c_hp, $m_hp, $c_mp, $m_mp, $c_satiety, $c_hydration, $c_energy, $sync_p
 *   tak jak w istniejącym panelu (dostosuj nazwy, jeśli u Ciebie są inne).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestracja assetów tylko jeśli shortcode występuje na stronie.
 */
function tw_vitalis_register_assets() {
	if ( is_admin() ) {
		return;
	}

	// CSS
	wp_register_style(
		'tw-vitalis-css',
		trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/css/public/vitalis.css',
		array(),
		NEOWEAVER_VERSION
	);

	// JS
	wp_register_script(
		'tw-vitalis-js',
		trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/js/public/vitalis.js',
		array(),
		NEOWEAVER_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'tw_vitalis_register_assets' );

/**
 * Shortcode renderer.
 */
function tw_vitalis_panel_shortcode() {
	// Tu zakładam, że masz te zmienne w zasięgu tak jak w obecnym szablonie character panel.
	// Jeśli nie — pobierz je z Supabase / helpera tak jak resztę karty postaci.
	global $c_hp, $m_hp, $c_mp, $m_mp, $c_satiety, $c_hydration, $c_rest, $sync_p;

	$current_hp        = (int) ( $c_hp ?? 0 );
	$max_hp            = (int) ( $m_hp ?? 0 );
	$current_magic     = (int) ( $c_mp ?? 0 );
	$max_magic         = (int) ( $m_mp ?? 0 );
	$current_satiety   = (int) ( $c_satiety ?? 0 );
	$current_hydration = (int) ( $c_hydration ?? 0 );
	$current_energy    = (int) ( $c_rest ?? 0 ); // dawny REST → ENERGY
	$current_sync      = (int) ( $sync_p ?? 0 );

	// Procenty (bez dzielenia przez 0).
	$hp_p        = $max_hp > 0 ? max( 0, min( 100, ( $current_hp / $max_hp ) * 100 ) ) : 0;
	$magic_p     = $max_magic > 0 ? max( 0, min( 100, ( $current_magic / $max_magic ) * 100 ) ) : 0;
	$energy_p    = max( 0, min( 100, $current_energy ) );
	$satiety_p   = max( 0, min( 100, $current_satiety ) );
	$hydration_p = max( 0, min( 100, $current_hydration ) );
	$sync_p_s    = max( 0, min( 100, $current_sync ) );

	// Enqueue assetów (tylko gdy shortcode jest użyty).
	wp_enqueue_style( 'tw-vitalis-css' );
	wp_enqueue_script( 'tw-vitalis-js' );

	// Dane dla JS (np. do animacji / alertów).
	wp_localize_script(
		'tw-vitalis-js',
		'twVitalisData',
		array(
			'hp'        => array(
				'current' => $current_hp,
				'max'     => $max_hp,
				'percent' => $hp_p,
			),
			'magic'     => array(
				'current' => $current_magic,
				'max'     => $max_magic,
				'percent' => $magic_p,
			),
			'energy'    => $energy_p,
			'satiety'   => $satiety_p,
			'hydration' => $hydration_p,
			'sync'      => $sync_p_s,
		)
	);

	ob_start();
	?>
	<div class="tw-vitalis-panel" data-sync="<?php echo esc_attr( $sync_p_s ); ?>">
		<div class="tw-vitalis-grid">

			<!-- BODY: HP + ENERGY -->
			<div class="tw-vitalis-card tw-vitalis-card--body">
				<div class="tw-vitalis-card-header">
					<span class="tw-vitalis-card-label">BODY</span>
				</div>
				<div class="tw-vitalis-card-content">
					<div class="tw-vitalis-ring" data-meter="hp" data-percent="<?php echo esc_attr( $hp_p ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg hp" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">HEALTH</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $current_hp ); ?>/<?php echo esc_html( $max_hp ); ?>
							</div>
						</div>
					</div>

					<div class="tw-vitalis-ring" data-meter="energy" data-percent="<?php echo esc_attr( $energy_p ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg energy" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">ENERGY</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $current_energy ); ?>%
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ARCANE: MAGIC + SYNC -->
			<div class="tw-vitalis-card tw-vitalis-card--arcane">
				<div class="tw-vitalis-card-header">
					<span class="tw-vitalis-card-label">ARCANE</span>
				</div>
				<div class="tw-vitalis-card-content">
					<div class="tw-vitalis-ring" data-meter="magic" data-percent="<?php echo esc_attr( $magic_p ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg magic" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">MAGIC</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $current_magic ); ?>/<?php echo esc_html( $max_magic ); ?>
							</div>
						</div>
					</div>

					<div class="tw-vitalis-ring" data-meter="sync" data-percent="<?php echo esc_attr( $sync_p_s ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg sync" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">SYNC-RATE</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $sync_p_s ); ?>%
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- SURVIVAL: SATIETY + HYDRATION -->
			<div class="tw-vitalis-card tw-vitalis-card--survival">
				<div class="tw-vitalis-card-header">
					<span class="tw-vitalis-card-label">SURVIVAL</span>
				</div>
				<div class="tw-vitalis-card-content">
					<div class="tw-vitalis-ring" data-meter="satiety" data-percent="<?php echo esc_attr( $satiety_p ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg satiety" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">SATIETY</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $current_satiety ); ?>%
							</div>
						</div>
					</div>

					<div class="tw-vitalis-ring" data-meter="hydration" data-percent="<?php echo esc_attr( $hydration_p ); ?>">
						<svg viewBox="0 0 40 40">
							<circle class="tw-vitalis-ring-bg" cx="20" cy="20" r="16"></circle>
							<circle class="tw-vitalis-ring-fg hydration" cx="20" cy="20" r="16"></circle>
						</svg>
						<div class="tw-vitalis-ring-label">
							<div class="tw-vitalis-ring-title">HYDRATION</div>
							<div class="tw-vitalis-ring-value">
								<?php echo esc_html( $current_hydration ); ?>%
							</div>
						</div>
					</div>
				</div>
			</div>

		</div><!-- /.tw-vitalis-grid -->
	</div><!-- /.tw-vitalis-panel -->
	<?php
	return ob_get_clean();
}
add_shortcode( 'tw_vitalis_panel', 'tw_vitalis_panel_shortcode' );
