<?php
/**
 * Partial: Vitalis HUD – 6 stat tiles.
 * Includowany z character-card.php przez $args.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = wp_parse_args(
	$args ?? array(),
	array(
		'c_hp'        => 0,
		'm_hp'        => 0,
		'c_mp'        => 0,
		'm_mp'        => 0,
		'sync_p'      => 0,
		'c_satiety'   => 0,
		'c_hydration' => 0,
		'c_rest'      => 0,
	)
);

$current_hp        = (int) $args['c_hp'];
$max_hp            = (int) $args['m_hp'];
$current_magic     = (int) $args['c_mp'];
$max_magic         = (int) $args['m_mp'];
$current_sync      = (int) $args['sync_p'];
$current_satiety   = (int) $args['c_satiety'];
$current_hydration = (int) $args['c_hydration'];
$current_energy    = (int) $args['c_rest'];

$hp_p        = $max_hp > 0 ? max( 0, min( 100, (int) round( ( $current_hp / $max_hp ) * 100 ) ) ) : 0;
$magic_p     = $max_magic > 0 ? max( 0, min( 100, (int) round( ( $current_magic / $max_magic ) * 100 ) ) ) : 0;
$energy_p    = max( 0, min( 100, $current_energy ) );
$satiety_p   = max( 0, min( 100, $current_satiety ) );
$hydration_p = max( 0, min( 100, $current_hydration ) );
$sync_val    = max( 0, min( 100, $current_sync ) );

$vitalis_class = static function ( int $p ): string {
	if ( $p <= 25 ) {
		return 'is-critical';
	}
	if ( $p <= 50 ) {
		return 'is-warning';
	}
	return 'is-stable';
};

$vitalis_label = static function ( int $p ): string {
	if ( $p <= 25 ) {
		return 'CRITICAL';
	}
	if ( $p <= 50 ) {
		return 'LOW';
	}
	return 'STABLE';
};

$stats = array(
	array(
		'key'         => 'health',
		'label'       => 'HEALTH',
		'value'       => $current_hp . '/' . $max_hp,
		'percent'     => $hp_p,
		'color_class' => 'stat-health',
		'state_class' => $vitalis_class( $hp_p ),
		'state_text'  => $vitalis_label( $hp_p ),
	),
	array(
		'key'         => 'energy',
		'label'       => 'ENERGY',
		'value'       => $current_energy . '%',
		'percent'     => $energy_p,
		'color_class' => 'stat-energy',
		'state_class' => $vitalis_class( $energy_p ),
		'state_text'  => $vitalis_label( $energy_p ),
	),
	array(
		'key'         => 'magic',
		'label'       => 'MAGIC',
		'value'       => $current_magic . '/' . $max_magic,
		'percent'     => $magic_p,
		'color_class' => 'stat-magic',
		'state_class' => $vitalis_class( $magic_p ),
		'state_text'  => $vitalis_label( $magic_p ),
	),
	array(
		'key'         => 'sync',
		'label'       => 'SYNC',
		'value'       => $sync_val . '%',
		'percent'     => $sync_val,
		'color_class' => 'stat-sync',
		'state_class' => $vitalis_class( $sync_val ),
		'state_text'  => $vitalis_label( $sync_val ),
	),
	array(
		'key'         => 'satiety',
		'label'       => 'SATIETY',
		'value'       => $current_satiety . '%',
		'percent'     => $satiety_p,
		'color_class' => 'stat-satiety',
		'state_class' => $vitalis_class( $satiety_p ),
		'state_text'  => $vitalis_label( $satiety_p ),
	),
	array(
		'key'         => 'hydration',
		'label'       => 'HYDRATION',
		'value'       => $current_hydration . '%',
		'percent'     => $hydration_p,
		'color_class' => 'stat-hydration',
		'state_class' => $vitalis_class( $hydration_p ),
		'state_text'  => $vitalis_label( $hydration_p ),
	),
);

wp_enqueue_style(
	'tw-vitalis-css',
	trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/css/public/vitalis.css',
	array(),
	defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0'
);

wp_enqueue_script(
	'tw-vitalis-js',
	trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/js/public/vitalis.js',
	array(),
	defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0',
	true
);
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
						<?php echo esc_html( $stat['state_text'] ); ?>
					</div>
				</div>
			</div>

			<div class="tw-vitalis-line">
				<span class="tw-vitalis-line-fill" style="width: <?php echo esc_attr( (int) $stat['percent'] ); ?>;"></span>
			</div>
		</div>
	<?php endforeach; ?>
</div>
