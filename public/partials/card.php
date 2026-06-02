<?php
/**
 * card.php — NeoWeaver universal card partial
 *
 * Layout (top → bottom):
 *   ─── ascension dots (top bar, centered) ─────────────────
 *   | [cat icon]                         [type icon] |
 *   |              image                   [mp cost] |
 *   | title                                    level |
 *   | ─── xp/level progress bar ──────────────────── |
 *   | description                                    |
 *   | requirements                                   |
 *   ─── [cooldown]  ─────────────────  [time cost] ──
 *
 * Required $args keys:
 *   id            string   unique card/deck id
 *   name          string
 *   rarity        string   common|uncommon|rare|epic|legendary
 *   img_url       string   (optional)
 *   description   string   (optional)
 *   effect        string   (optional)
 *
 * Optional $args keys:
 *   level         int      current level (default 1)
 *   level_max     int      max level (default 10)
 *   level_xp      int      current xp in level (for bar)
 *   level_xp_max  int      xp needed for next level
 *   asc_level     int      0-5 filled ascension dots
 *   cat_label     string   category name
 *   cat_icon      string   lucide icon name for category
 *   cat_color     string   hex color for category
 *   type_icon     string   lucide icon name for card type
 *   mp_cost       int|null mana/energy cost (shown on image)
 *   time_cost     string   e.g. "2 turns" (footer right)
 *   cooldown      int|null turns cooldown (footer left)
 *   requirements  array    [ ['icon'=>'shield','label'=>'Level 5'], ... ]
 *   tags          array    [ 'fire', 'AOE', ... ]
 *   context       string   library|foundry|hand (css modifier)
 *   state         string   ready|dim|ghost (css modifier)
 *   extra_classes string   additional CSS classes
 *   data_attrs    array    [ 'deck-id' => 'xxx', ... ]
 *
 * Usage:
 *   nw_render_card( [ 'name' => 'Shadow Strike', 'rarity' => 'rare', ... ] );
 *   — or include directly:
 *   include NW_PLUGIN_DIR . 'public/partials/card.php'; (with $args in scope)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'nw_render_card' ) ) :
function nw_render_card( array $args ): string {

	$d = wp_parse_args( $args, [
		'id'           => '',
		'name'         => 'Unknown',
		'rarity'       => 'common',
		'img_url'      => '',
		'description'  => '',
		'effect'       => '',
		'level'        => 1,
		'level_max'    => 10,
		'level_xp'     => 0,
		'level_xp_max' => 100,
		'asc_level'    => 0,
		'cat_label'    => '',
		'cat_icon'     => 'tag',
		'cat_color'    => '#adff00',
		'type_icon'    => '',
		'mp_cost'      => null,
		'time_cost'    => '',
		'cooldown'     => null,
		'requirements' => [],
		'tags'         => [],
		'context'      => '',
		'state'        => '',
		'extra_classes'=> '',
		'data_attrs'   => [],
	] );

	// ── rarity color map ──────────────────────────────────────────────────────
	$rarity_colors = [
		'common'    => [ 'hex' => '#888888', 'rgb' => '136,136,136' ],
		'uncommon'  => [ 'hex' => '#3cb371', 'rgb' => '60,179,113'  ],
		'rare'      => [ 'hex' => '#4a90d9', 'rgb' => '74,144,217'  ],
		'epic'      => [ 'hex' => '#9b59b6', 'rgb' => '155,89,182'  ],
		'legendary' => [ 'hex' => '#e6b800', 'rgb' => '230,184,0'   ],
	];
	$rarity   = strtolower( $d['rarity'] );
	$rar_data = $rarity_colors[ $rarity ] ?? $rarity_colors['common'];

	// ── CSS classes ───────────────────────────────────────────────────────────
	$classes = [ 'nw-card', 'nw-card--' . $rarity ];
	if ( $d['context'] )       { $classes[] = 'nw-card--' . esc_attr( $d['context'] ); }
	if ( $d['state'] )         { $classes[] = 'nw-card--' . esc_attr( $d['state'] );   }
	if ( $d['extra_classes'] ) { $classes[] = esc_attr( $d['extra_classes'] );           }
	$class_str = implode( ' ', $classes );

	// ── data attributes ───────────────────────────────────────────────────────
	$data_str = '';
	if ( $d['id'] ) { $data_str .= ' data-card-id="' . esc_attr( $d['id'] ) . '"'; }
	foreach ( $d['data_attrs'] as $key => $val ) {
		$data_str .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
	}

	// ── level progress ────────────────────────────────────────────────────────
	$lvl_pct = 0;
	if ( $d['level_xp_max'] > 0 ) {
		$lvl_pct = min( 100, round( $d['level_xp'] / $d['level_xp_max'] * 100 ) );
	}

	// ── inline CSS vars ───────────────────────────────────────────────────────
	$style = sprintf(
		'--nw-card-color:%s; --nw-card-rgb:%s; --nw-cat-color:%s;',
		esc_attr( $rar_data['hex'] ),
		esc_attr( $rar_data['rgb'] ),
		esc_attr( $d['cat_color'] )
	);

	ob_start();
?>
<div class="<?php echo $class_str; ?>"<?php echo $data_str; ?> style="<?php echo $style; ?>">

	<?php /* ── Ascension dots (top bar) ─────────────────────────────── */ ?>
	<div class="nw-card__asc-bar">
		<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
			<span class="nw-card__asc-dot<?php echo ( $i <= (int) $d['asc_level'] ) ? ' nw-card__asc-dot--lit' : ''; ?>"></span>
		<?php endfor; ?>
	</div>

	<?php /* ── Image area with overlaid icons ───────────────────────── */ ?>
	<div class="nw-card__img-wrap">

		<?php /* category icon — top left */ ?>
		<?php if ( $d['cat_icon'] ) : ?>
		<div class="nw-card__cat-icon" title="<?php echo esc_attr( $d['cat_label'] ); ?>"
			style="border-color:<?php echo esc_attr( $d['cat_color'] ); ?>44; background:<?php echo esc_attr( $d['cat_color'] ); ?>18;">
			<i data-lucide="<?php echo esc_attr( $d['cat_icon'] ); ?>" style="color:<?php echo esc_attr( $d['cat_color'] ); ?>;"></i>
		</div>
		<?php endif; ?>

		<?php /* type icon — top right */ ?>
		<?php if ( $d['type_icon'] ) : ?>
		<div class="nw-card__type-icon">
			<i data-lucide="<?php echo esc_attr( $d['type_icon'] ); ?>"></i>
		</div>
		<?php endif; ?>

		<?php /* image or placeholder */ ?>
		<?php if ( $d['img_url'] ) : ?>
			<img src="<?php echo esc_url( $d['img_url'] ); ?>"
				 alt="<?php echo esc_attr( $d['name'] ); ?>"
				 loading="lazy" width="260" height="130">
		<?php else : ?>
			<div class="nw-card__img-placeholder">
				<i data-lucide="image" style="width:28px;height:28px;"></i>
			</div>
		<?php endif; ?>

		<?php /* MP cost badge — bottom right of image */ ?>
		<?php if ( $d['mp_cost'] !== null ) : ?>
		<div class="nw-card__mp-badge">
			<i data-lucide="zap" style="width:9px;height:9px;"></i>
			<?php echo (int) $d['mp_cost']; ?>
		</div>
		<?php endif; ?>

		<div class="nw-card__img-overlay"></div>
	</div>

	<?php /* ── Title + level ────────────────────────────────────────── */ ?>
	<div class="nw-card__header">
		<span class="nw-card__name"><?php echo esc_html( $d['name'] ); ?></span>
		<?php if ( $d['level'] ) : ?>
		<span class="nw-card__level">LVL&nbsp;<?php echo (int) $d['level']; ?></span>
		<?php endif; ?>
	</div>

	<?php /* ── XP / level progress bar ──────────────────────────────── */ ?>
	<div class="nw-card__lvl-bar-wrap">
		<div class="nw-card__lvl-bar">
			<div class="nw-card__lvl-bar-fill" style="width:<?php echo $lvl_pct; ?>%"></div>
		</div>
	</div>

	<?php /* ── Body: description + requirements ───────────────────── */ ?>
	<div class="nw-card__body">

		<?php if ( $d['description'] ) : ?>
			<p class="nw-card__desc"><?php echo esc_html( $d['description'] ); ?></p>
		<?php endif; ?>

		<?php if ( $d['effect'] ) : ?>
			<p class="nw-card__effect"><?php echo esc_html( $d['effect'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $d['requirements'] ) ) : ?>
		<div class="nw-card__req">
			<i data-lucide="lock" style="width:10px;height:10px;"></i>
			<span>
			<?php foreach ( $d['requirements'] as $req ) :
				$req_icon  = esc_attr( $req['icon']  ?? 'check' );
				$req_label = esc_html( $req['label'] ?? '' );
			?>
				<span class="nw-card__req-item">
					<i data-lucide="<?php echo $req_icon; ?>" style="width:9px;height:9px;"></i>
					<?php echo $req_label; ?>
				</span>
			<?php endforeach; ?>
			</span>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $d['tags'] ) ) : ?>
		<div class="nw-card__tags">
			<?php foreach ( $d['tags'] as $tag ) : ?>
				<span class="nw-card__tag"><?php echo esc_html( $tag ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

	</div><!-- /.nw-card__body -->

	<?php /* ── Footer: cooldown + time cost ──────────────────────────── */ ?>
	<div class="nw-card__footer">
		<div class="nw-card__cost nw-card__cost--cd">
			<?php if ( $d['cooldown'] !== null ) : ?>
				<i data-lucide="refresh-cw" style="width:10px;height:10px;"></i>
				<span><?php echo (int) $d['cooldown']; ?>t CD</span>
			<?php else : ?>
				<span class="nw-card__cost-empty">—</span>
			<?php endif; ?>
		</div>
		<div class="nw-card__rarity-bar">
			<span class="nw-card__rarity-dot"></span>
			<span class="nw-card__rarity-name"><?php echo esc_html( ucfirst( $rarity ) ); ?></span>
		</div>
		<div class="nw-card__cost nw-card__cost--time">
			<?php if ( $d['time_cost'] ) : ?>
				<i data-lucide="clock" style="width:10px;height:10px;"></i>
				<span><?php echo esc_html( $d['time_cost'] ); ?></span>
			<?php else : ?>
				<span class="nw-card__cost-empty">—</span>
			<?php endif; ?>
		</div>
	</div><!-- /.nw-card__footer -->

</div><!-- /.nw-card -->
<?php
	return ob_get_clean();
}
endif;
