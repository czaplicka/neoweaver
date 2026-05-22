<?php
/**
 * Shortcode: [tw_vitalis_panel]
 * NeoWeaver Vitalis — 6 semi-transparent stat cards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_vitalis_register_assets' ) ) {
	function tw_vitalis_register_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_register_style(
			'tw-vitalis-css',
			trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/css/public/vitalis.css',
			array(),
			defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0'
		);

		wp_register_script(
			'tw-vitalis-js',
			trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/js/public/vitalis.js',
			array(),
			defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0',
			true
		);
	}
	add_action( 'wp_enqueue_scripts', 'tw_vitalis_register_assets' );
}

if ( ! function_exists( 'tw_vitalis_percent_class' ) ) {
	function tw_vitalis_percent_class( $percent ) {
		$percent = (int) $percent;

		if ( $percent <= 25 ) {
			return 'is-critical';
		}

		if ( $percent <= 50 ) {
			return 'is-warning';
		}

		return 'is-stable';
	}
}

if ( ! function_exists( 'tw_vitalis_panel_shortcode' ) ) {
	function tw_vitalis_panel_shortcode() {
		global $c_hp, $m_hp, $c_mp, $m_mp, $c_satiety, $c_hydration, $c_rest, $sync_p;

		$current_hp        = (int) ( $c_hp ?? 0 );
		$max_hp            = (int) ( $m_hp ?? 0 );
		$current_magic     = (int) ( $c_mp ?? 0 );
		$max_magic         = (int) ( $m_mp ?? 0 );
		$current_satiety   = (int) ( $c_satiety ?? 0 );
		$current_hydration = (int) ( $c_hydration ?? 0 );
		$current_energy    = (int) ( $c_rest ?? 0 );
		$current_sync      = (int) ( $sync_p ?? 0 );

		$hp_p        = $max_hp > 0 ? max( 0, min( 100, (int) round( ( $current_hp / $max_hp ) * 100 ) ) ) : 0;
		$magic_p     = $max_magic > 0 ? max( 0, min( 100, (int) round( ( $current_magic / $max_magic ) * 100 ) ) ) : 0;
		$energy_p    = max( 0, min( 100, $current_energy ) );
		$satiety_p   = max( 0, min( 100, $current_satiety ) );
		$hydration_p = max( 0, min( 100, $current_hydration ) );
		$sync_val    = max( 0, min( 100, $current_sync ) );

		$stats = array(
			array(
				'key'          => 'health',
				'label'        => 'HEALTH',
				'value'        => $current_hp . '/' . $max_hp,
				'percent'      => $hp_p,
				'color_class'  => 'stat-health',
				'state_class'  => tw_vitalis_percent_class( $hp_p ),
			),
			array(
				'key'          => 'energy',
				'label'        => 'ENERGY',
				'value'        => $current_energy . '%',
				'percent'      => $energy_p,
				'color_class'  => 'stat-energy',
				'state_class'  => tw_vitalis_percent_class( $energy_p ),
			),
			array(
				'key'          => 'magic',
				'label'        => 'MAGIC',
				'value'        => $current_magic . '/' . $max_magic,
				'percent'      => $magic_p,
				'color_class'  => 'stat-magic',
				'state_class'  => tw_vitalis_percent_class( $magic_p ),
			),
			array(
				'key'          => 'sync',
				'label'        => 'SYNC',
				'value'        => $sync_val . '%',
				'percent'      => $sync_val,
				'color_class'  => 'stat-sync',
				'state_class'  => tw_vitalis_percent_class( $sync_val ),
			),
			array(
				'key'          => 'satiety',
				'label'        => 'SATIETY',
				'value'        => $current_satiety . '%',
				'percent'      => $satiety_p,
				'color_class'  => 'stat-satiety',
				'state_class'  => tw_vitalis_percent_class( $satiety_p ),
			),
			array(
				'key'          => 'hydration',
				'label'        => 'HYDRATION',
				'value'        => $current_hydration . '%',
				'percent'      => $hydration_p,
				'color_class'  => 'stat-hydration',
				'state_class'  => tw_vitalis_percent_class( $hydration_p ),
			),
		);

		wp_enqueue_style( 'tw-vitalis-css' );
		wp_enqueue_script( 'tw-vitalis-js' );

		wp_localize_script(
			'tw-vitalis-js',
			'twVitalisData',
			array(
				'stats' => $stats,
			)
		);

		ob_start();
		?>
		<div class="tw-vitalis-grid-6" data-vitalis="1">
			<?php foreach ( $stats as $stat ) : ?>
				<div
					class="tw-vitalis-tile <?php echo esc_attr( $stat['color_class'] ); ?> <?php echo esc_attr( $stat['state_class'] ); ?>"
					data-stat-key="<?php echo esc_attr( $stat['key'] ); ?>"
					data-percent="<?php echo esc_attr( $stat['percent'] ); ?>"
				>
					<div class="tw-vitalis-tile__chrome"></div>

					<div class="tw-vitalis-tile__top">
						<span class="tw-vitalis-tile__label">
							<?php echo esc_html( $stat['label'] ); ?>
						</span>
					</div>

					<div class="tw-vitalis-tile__main">
						<div class="tw-vitalis-ring" aria-hidden="true">
							<svg viewBox="0 0 42 42" class="tw-vitalis-ring-svg">
								<circle class="tw-vitalis-ring-bg" cx="21" cy="21" r="16"></circle>
								<circle class="tw-vitalis-ring-fg" cx="21" cy="21" r="16"></circle>
							</svg>
							<span class="tw-vitalis-ring-center"><?php echo esc_html( (int) $stat['percent'] ); ?></span>
						</div>

						<div class="tw-vitalis-readout">
							<div class="tw-vitalis-readout__value">
								<?php echo esc_html( $stat['value'] ); ?>
							</div>
							<div class="tw-vitalis-readout__state">
								<?php
								if ( $stat['percent'] <= 25 ) {
									echo 'CRITICAL';
								} elseif ( $stat['percent'] <= 50 ) {
									echo 'LOW';
								} else {
									echo 'STABLE';
								}
								?>
							</div>
						</div>
					</div>

					<div class="tw-vitalis-line">
						<span class="tw-vitalis-line-fill" style="width: <?php echo esc_attr( (int) $stat['percent'] ); ?>%;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'tw_vitalis_panel', 'tw_vitalis_panel_shortcode' );
}
