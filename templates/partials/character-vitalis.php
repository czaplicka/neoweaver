<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partial: Vitalis HUD (6 tiles)
 * Oczekuje w $args:
 * - c_hp, m_hp, c_mp, m_mp, sync_p, c_satiety, c_hydration, c_rest
 */

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

$c_hp        = (int) $args['c_hp'];
$m_hp        = (int) $args['m_hp'];
$c_mp        = (int) $args['c_mp'];
$m_mp        = (int) $args['m_mp'];
$sync_p      = (int) $args['sync_p'];
$c_satiety   = (int) $args['c_satiety'];
$c_hydration = (int) $args['c_hydration'];
$c_rest      = (int) $args['c_rest'];

// tutaj wklej cały HTML Vitalis (6 kafelków) oparty na tych zmiennych
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
