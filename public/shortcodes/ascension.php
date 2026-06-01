<?php
/**
 * ascension.php  — shortcode [nw_ascension]
 * Shows cards eligible for Ascension for a given character.
 * Usage: [nw_ascension character_id="{uuid}"]
 *        or just [nw_ascension] — auto-detects / shows selector
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ── helpers ──────────────────────────────────────────────────────────────────

function _nw_asc_char_id( $char ): string {
	if ( is_object( $char ) ) return (string) ( $char->id ?? '' );
	if ( is_array( $char ) )  return (string) ( $char['id'] ?? '' );
	return '';
}

function _nw_asc_char_field( $char, string $field ): string {
	if ( is_object( $char ) ) return (string) ( $char->$field ?? '' );
	if ( is_array( $char ) )  return (string) ( $char[ $field ] ?? '' );
	return '';
}

function _nw_asc_rarity_class( string $rarity ): string {
	$map = [
		'common'    => 'nw-card--common',
		'uncommon'  => 'nw-card--uncommon',
		'rare'      => 'nw-card--rare',
		'epic'      => 'nw-card--epic',
		'legendary' => 'nw-card--legendary',
	];
	return $map[ strtolower( $rarity ) ] ?? 'nw-card--common';
}

function _nw_asc_render_bonuses( array $all_bonuses, int $asc_level ): string {
	$active_bonuses = [];
	for ( $lvl = 1; $lvl <= $asc_level; $lvl++ ) {
		$key = (string) $lvl;
		if ( ! empty( $all_bonuses[ $key ] ) && is_array( $all_bonuses[ $key ] ) ) {
			foreach ( $all_bonuses[ $key ] as $bonus ) {
				$active_bonuses[] = $bonus;
			}
		}
	}
	if ( empty( $active_bonuses ) ) return '';

	$icons = [
		'damage'        => 'sword',
		'defense'       => 'shield',
		'xp_gain'       => 'star',
		'hp'            => 'heart',
		'speed'         => 'zap',
		'crit'          => 'crosshair',
		'special'       => 'sparkles',
		'unlock_effect' => 'unlock',
	];

	$pills = '';
	foreach ( $active_bonuses as $bonus ) {
		if ( ! is_array( $bonus ) ) continue;
		$type  = (string) ( $bonus['type']  ?? 'bonus' );
		$value = $bonus['value'] ?? null;
		$icon  = $icons[ $type ] ?? 'plus';
		if ( is_numeric( $value ) ) {
			$label = esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ' +' . esc_html( $value );
		} elseif ( $value !== null ) {
			$label = esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ': ' . esc_html( $value );
		} else {
			$label = esc_html( ucwords( str_replace( '_', ' ', $type ) ) );
		}
		$pills .= '<span class="nw-asc-bonus-pill">';
		$pills .= '<i data-lucide="' . esc_attr( $icon ) . '" style="width:10px;height:10px;vertical-align:middle;"></i> ';
		$pills .= $label . '</span>';
	}
	return '<div class="nw-asc-bonuses">' . $pills . '</div>';
}

// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'nw_shortcode_ascension' ) ) :

function nw_shortcode_ascension( array $atts ): string {
	$atts = shortcode_atts( [ 'character_id' => '' ], $atts );

	if ( ! is_user_logged_in() ) {
		return '<p class="nw-notice">You must be logged in.</p>';
	}

	$user_id      = get_current_user_id();
	$character_id = function_exists( 'nw_sanitize_uuid' )
		? nw_sanitize_uuid( $atts['character_id'] )
		: preg_replace( '/[^a-f0-9\\-]/', '', strtolower( $atts['character_id'] ) );

	$characters = function_exists( 'tw_get_user_characters' )
		? tw_get_user_characters( $user_id )
		: [];

	if ( empty( $characters ) ) {
		return '<p class="nw-notice">No character found. Create a character first.</p>';
	}

	if ( ! $character_id ) {
		$qs_raw = isset( $_GET['nw_char'] ) ? (string) $_GET['nw_char'] : '';
		$qs_id  = function_exists( 'nw_sanitize_uuid' )
			? nw_sanitize_uuid( $qs_raw )
			: preg_replace( '/[^a-f0-9\\-]/', '', strtolower( $qs_raw ) );
		if ( $qs_id ) {
			foreach ( $characters as $c ) {
				if ( _nw_asc_char_id( $c ) === $qs_id ) {
					$character_id = $qs_id;
					break;
				}
			}
		}
		if ( ! $character_id ) {
			$character_id = _nw_asc_char_id( $characters[0] );
		}
	}

	if ( function_exists( 'tw_user_owns_character' ) && ! tw_user_owns_character( $character_id, $user_id ) ) {
		return '<p class="nw-notice">Character not found.</p>';
	}

	if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
		return '<p class="nw-notice">Supabase not configured.</p>';
	}

	// ── Query 1: character deck + card definition ──
	// cyber_deck.deck_category = ID lub slug kategorii (text)
	$owned = tw_supabase_get_admin( 'cyber_character_deck', [
		'character_id' => 'eq.' . $character_id,
		'is_locked'    => 'eq.false',
		'select'       => 'id,deck_id,current_level,current_xp,ascension_level,cyber_deck(id,name,img_url,rarity,description,effect,asc_bonuses,deck_category)',
	] );

	$no_cards = '
		<div class="nw-cards-empty">
			<i data-lucide="layers" style="width:32px;height:32px;margin-bottom:12px;color:#2a2a2a;"></i>
			<p>No cards available for Ascension.</p>
		</div>';

	if ( is_wp_error( $owned ) || ! is_array( $owned ) || empty( $owned ) ) {
		$out = count( $characters ) > 1 ? _nw_asc_selector( $characters, $character_id ) : '';
		return $out . $no_cards;
	}

	// ── Collect unique deck_category values ──
	$cat_ids = [];
	foreach ( $owned as $row ) {
		$cdata  = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		$cat_id = (string) ( $cdata['deck_category'] ?? '' );
		if ( $cat_id !== '' ) {
			$cat_ids[ $cat_id ] = true;
		}
	}

	// ── Query 2: fetch cyber_card_categories for those IDs ──
	// cat_map: category_id => [ 'label' => '', 'icon' => '', 'color' => '' ]
	$cat_map = [];
	if ( ! empty( $cat_ids ) ) {
		$ids_csv = implode( ',', array_map( 'sanitize_text_field', array_keys( $cat_ids ) ) );
		$cats    = tw_supabase_get_admin( 'cyber_card_categories', [
			'id'     => 'in.(' . $ids_csv . ')',
			'select' => 'id,label,icon,color',
		] );
		if ( ! is_wp_error( $cats ) && is_array( $cats ) ) {
			foreach ( $cats as $cat ) {
				$cid = (string) ( $cat['id'] ?? '' );
				if ( $cid !== '' ) {
					$cat_map[ $cid ] = [
						'label' => (string) ( $cat['label'] ?? $cid ),
						'icon'  => (string) ( $cat['icon']  ?? 'tag' ),
						'color' => (string) ( $cat['color'] ?? '#adff00' ),
					];
				}
			}
		}
	}

	// ── Group by deck_id ──
	$groups   = [];
	$defs_map = [];

	foreach ( $owned as $row ) {
		$did   = (string) ( $row['deck_id'] ?? '' );
		$cdata = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		if ( '' === $did || empty( $cdata ) ) continue;

		if ( ! isset( $groups[ $did ] ) ) {
			$groups[ $did ] = [ 'copies' => [], 'ascended' => [] ];

			$raw_bonuses = $cdata['asc_bonuses'] ?? [];
			if ( is_string( $raw_bonuses ) ) {
				$raw_bonuses = json_decode( $raw_bonuses, true ) ?: [];
			}

			$cat_id  = (string) ( $cdata['deck_category'] ?? '' );
			$cat     = $cat_map[ $cat_id ] ?? [ 'label' => '', 'icon' => 'tag', 'color' => '#adff00' ];

			$defs_map[ $did ] = [
				'id'          => $did,
				'name'        => (string) ( $cdata['name']        ?? '' ),
				'img_url'     => (string) ( $cdata['img_url']     ?? '' ),
				'rarity'      => (string) ( $cdata['rarity']      ?? 'common' ),
				'description' => (string) ( $cdata['description'] ?? '' ),
				'effect'      => (string) ( $cdata['effect']      ?? '' ),
				'asc_bonuses' => is_array( $raw_bonuses ) ? $raw_bonuses : [],
				'cat_label'   => $cat['label'],
				'cat_icon'    => $cat['icon']  !== '' ? $cat['icon']  : 'tag',
				'cat_color'   => $cat['color'] !== '' ? $cat['color'] : '#adff00',
			];
		}

		$asc_lvl = (int) ( $row['ascension_level'] ?? 0 );
		if ( 0 === $asc_lvl ) {
			$groups[ $did ]['copies'][] = $row;
		} else {
			$groups[ $did ]['ascended'][] = $row;
		}
	}

	// ── Eligibility ──
	$asc_cost = [ 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 ];
	$eligible = [];
	foreach ( $groups as $did => $group ) {
		if ( count( $group['copies'] ) >= 2 || ! empty( $group['ascended'] ) ) {
			$eligible[] = $did;
		}
	}

	if ( empty( $eligible ) ) {
		$msg = '
			<div class="nw-cards-empty">
				<i data-lucide="layers" style="width:32px;height:32px;margin-bottom:12px;color:#2a2a2a;"></i>
				<p>Collect duplicate cards to unlock Ascension.</p>
			</div>';
		$out = count( $characters ) > 1 ? _nw_asc_selector( $characters, $character_id ) : '';
		return $out . $msg;
	}

	wp_enqueue_style( 'neoweaver-cards' );
	$nonce = wp_create_nonce( 'nw_ascension_nonce' );

	ob_start();
	?>
	<div class="nw-ascension-wrap" data-character="<?php echo esc_attr( $character_id ); ?>">

		<?php if ( count( $characters ) > 1 ) : ?>
			<?php echo _nw_asc_selector( $characters, $character_id ); ?>
		<?php endif; ?>

		<h2 class="nw-ascension-title">⬡ Ascension Forge</h2>

		<div class="nw-ascension-grid">
		<?php foreach ( $eligible as $did ) :
			$def = $defs_map[ $did ] ?? null;
			if ( ! $def ) continue;

			$base_copies = $groups[ $did ]['copies'];
			$base_count  = count( $base_copies );
			$ascended    = $groups[ $did ]['ascended'];
			$cur_asc     = ! empty( $ascended )
				? (int) max( array_column( $ascended, 'ascension_level' ) )
				: 0;
			$next_asc    = $cur_asc + 1;
			$required    = $asc_cost[ $next_asc ] ?? 999;
			$can_ascend  = ( $base_count >= $required && $next_asc <= 5 );
			$maxed       = ( $cur_asc >= 5 );
			$all_bonuses = $def['asc_bonuses'];

			$rarity     = strtolower( $def['rarity'] );
			$rarity_cls = _nw_asc_rarity_class( $rarity );
			$state_cls  = $can_ascend ? 'nw-card--ready' : ( $maxed ? '' : 'nw-card--dim' );

			// Category — wszystko pochodzi z cyber_card_categories
			$cat_label = $def['cat_label'];
			$cat_icon  = $def['cat_icon'];
			$cat_color = $def['cat_color'];

			$progress_pct = $required > 0 ? min( 100, round( $base_count / $required * 100 ) ) : 100;

			$rarity_colors = [
				'common'    => '#6b7280',
				'uncommon'  => '#22c55e',
				'rare'      => '#3b82f6',
				'epic'      => '#a855f7',
				'legendary' => '#f59e0b',
			];
			$card_color = $can_ascend ? '#adff00' : ( $rarity_colors[ $rarity ] ?? '#6b7280' );
		?>
			<div class="nw-asc-card <?php echo esc_attr( "$rarity_cls $state_cls" ); ?>"
				style="--nw-rarity-color:<?php echo esc_attr( $card_color ); ?>; --nw-cat-color:<?php echo esc_attr( $cat_color ); ?>;"
				data-instance-id="<?php echo esc_attr( (string) ( $base_copies[0]['id'] ?? $did ) ); ?>">

				<span class="nw-asc-corner nw-asc-corner--tl"></span>
				<span class="nw-asc-corner nw-asc-corner--tr"></span>
				<span class="nw-asc-corner nw-asc-corner--bl"></span>
				<span class="nw-asc-corner nw-asc-corner--br"></span>

				<!-- Category badge — icon + color z cyber_card_categories -->
				<?php if ( $cat_label ) : ?>
				<div class="nw-asc-cat-badge"
					style="background:<?php echo esc_attr( $cat_color ); ?>22; border-color:<?php echo esc_attr( $cat_color ); ?>66;"
					title="<?php echo esc_attr( $cat_label ); ?>">
					<i data-lucide="<?php echo esc_attr( $cat_icon ); ?>" style="color:<?php echo esc_attr( $cat_color ); ?>;"></i>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $def['img_url'] ) ) : ?>
				<div class="nw-asc-img-wrap">
					<img src="<?php echo esc_url( $def['img_url'] ); ?>"
						alt="<?php echo esc_attr( $def['name'] ); ?>"
						loading="lazy" width="200" height="200">
					<div class="nw-asc-img-overlay"></div>
				</div>
				<?php endif; ?>

				<div class="nw-asc-header">
					<span class="nw-asc-name"><?php echo esc_html( $def['name'] ); ?></span>
					<span class="nw-asc-level">
						<?php if ( $cur_asc > 0 ) : ?>
							<i data-lucide="chevrons-up" style="width:9px;height:9px;"></i>&nbsp;ASC&nbsp;<?php echo $cur_asc; ?>
						<?php else : ?>
							LVL&nbsp;<?php echo (int) ( $base_copies[0]['current_level'] ?? 1 ); ?>
						<?php endif; ?>
					</span>
				</div>

				<div class="nw-asc-body">
					<?php if ( ! empty( $def['description'] ) ) : ?>
						<p class="nw-asc-desc"><?php echo esc_html( $def['description'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $def['effect'] ) ) : ?>
						<p class="nw-asc-effect"><?php echo esc_html( $def['effect'] ); ?></p>
					<?php endif; ?>

					<div class="nw-asc-dots">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="nw-asc-dot <?php echo $i <= $cur_asc ? 'nw-asc-dot--lit' : ''; ?>"></span>
						<?php endfor; ?>
					</div>

					<?php
					if ( $cur_asc > 0 && ! empty( $all_bonuses ) ) {
						echo _nw_asc_render_bonuses( $all_bonuses, $cur_asc );
					}
					if ( ! $maxed && ! empty( $all_bonuses ) ) {
						$next_key = (string) $next_asc;
						if ( ! empty( $all_bonuses[ $next_key ] ) ) : ?>
							<div class="nw-asc-bonuses-preview">
								<span class="nw-asc-preview-label">
									<i data-lucide="chevron-right" style="width:10px;height:10px;vertical-align:middle;"></i>
									ASC <?php echo $next_asc; ?> unlocks:
								</span>
								<?php
								$bonus_icons = [ 'damage' => 'sword', 'defense' => 'shield', 'xp_gain' => 'star', 'hp' => 'heart', 'speed' => 'zap', 'crit' => 'crosshair', 'special' => 'sparkles', 'unlock_effect' => 'unlock' ];
								foreach ( $all_bonuses[ $next_key ] as $bonus ) :
									if ( ! is_array( $bonus ) ) continue;
									$type  = (string) ( $bonus['type']  ?? 'bonus' );
									$value = $bonus['value'] ?? null;
									$icon  = $bonus_icons[ $type ] ?? 'plus';
									$label = is_numeric( $value )
										? esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ' +' . esc_html( $value )
										: esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ( $value !== null ? ': ' . esc_html( $value ) : '' );
									?>
									<span class="nw-asc-bonus-pill nw-asc-bonus-pill--next">
										<i data-lucide="<?php echo esc_attr( $icon ); ?>" style="width:10px;height:10px;vertical-align:middle;"></i>
										<?php echo $label; ?>
									</span>
								<?php endforeach; ?>
							</div>
						<?php endif;
					} ?>

					<?php if ( ! $maxed ) : ?>
					<div class="nw-asc-progress-wrap">
						<div class="nw-asc-progress-bar">
							<div class="nw-asc-progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
						</div>
						<div class="nw-asc-progress-label">
							<span><?php echo $base_count; ?> / <?php echo $required; ?> copies</span>
							<span>→ ASC <?php echo $next_asc; ?></span>
						</div>
					</div>
					<?php endif; ?>
				</div><!-- /.nw-asc-body -->

				<div class="nw-asc-footer">
					<?php if ( $maxed ) : ?>
						<span class="nw-asc-btn nw-asc-btn--max">MAX ⬡</span>
					<?php elseif ( $can_ascend ) : ?>
						<button class="nw-asc-btn nw-asc-btn--ready nw-asc-trigger"
							data-deck-id="<?php echo esc_attr( $did ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<i data-lucide="zap" style="width:11px;height:11px;vertical-align:middle;"></i>
							Ascend → Tier <?php echo $next_asc; ?>
						</button>
					<?php else : ?>
						<button class="nw-asc-btn nw-asc-btn--locked" disabled>
							<i data-lucide="lock" style="width:11px;height:11px;vertical-align:middle;"></i>
							Need <?php echo ( $required - $base_count ); ?> more
						</button>
					<?php endif; ?>
				</div>

			</div><!-- /.nw-asc-card -->
		<?php endforeach; ?>
		</div><!-- /.nw-ascension-grid -->
	</div><!-- /.nw-ascension-wrap -->

	<style>
	.nw-ascension-title {
		font-family: 'Chakra Petch', monospace; font-size: 0.85rem;
		text-transform: uppercase; letter-spacing: 0.12em; color: #adff00; margin: 0 0 16px;
	}
	.nw-ascension-grid {
		display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
	}
	@media (max-width: 900px) { .nw-ascension-grid { grid-template-columns: repeat(2, 1fr); } }
	@media (max-width: 560px) { .nw-ascension-grid { grid-template-columns: 1fr; } }

	.nw-asc-card {
		position: relative; background: #0d0d0d;
		border: 1px solid var(--nw-rarity-color, #6b7280);
		border-radius: 6px; overflow: hidden; display: flex; flex-direction: column;
		transition: box-shadow 0.2s ease, transform 0.2s ease;
		box-shadow: 0 0 0 1px rgba(0,0,0,0.8), inset 0 0 20px rgba(0,0,0,0.4);
	}
	.nw-asc-card:hover {
		transform: translateY(-2px);
		box-shadow: 0 0 0 1px rgba(0,0,0,0.8), 0 0 12px var(--nw-rarity-color,#6b7280), inset 0 0 20px rgba(0,0,0,0.4);
	}
	.nw-asc-card.nw-card--ready { border-color: #adff00; --nw-rarity-color: #adff00; }
	.nw-asc-card.nw-card--dim { opacity: 0.7; }

	.nw-asc-corner {
		position: absolute; width: 10px; height: 10px;
		border-color: var(--nw-rarity-color,#6b7280); border-style: solid;
		z-index: 10; pointer-events: none;
	}
	.nw-asc-corner--tl { top:4px; left:4px;   border-width: 2px 0 0 2px; }
	.nw-asc-corner--tr { top:4px; right:4px;  border-width: 2px 2px 0 0; }
	.nw-asc-corner--bl { bottom:4px; left:4px;  border-width: 0 0 2px 2px; }
	.nw-asc-corner--br { bottom:4px; right:4px; border-width: 0 2px 2px 0; }

	.nw-asc-cat-badge {
		position: absolute; top:14px; left:14px; z-index:20;
		width:26px; height:26px; border-radius:4px; border:1px solid;
		display:flex; align-items:center; justify-content:center; backdrop-filter:blur(4px);
	}
	.nw-asc-cat-badge i { width:13px; height:13px; display:block; }

	.nw-asc-img-wrap { position:relative; height:110px; overflow:hidden; }
	.nw-asc-img-wrap img { width:100%; height:110px; object-fit:cover; display:block; }
	.nw-asc-img-overlay { position:absolute; inset:0; background:linear-gradient(to bottom,transparent 40%,#0d0d0d 100%); }

	.nw-asc-header { display:flex; align-items:baseline; justify-content:space-between; gap:6px; padding:8px 12px 4px; }
	.nw-asc-name {
		font-family:'Chakra Petch',monospace; font-size:0.8rem; font-weight:700;
		text-transform:uppercase; letter-spacing:0.06em; color:#e5e5e5;
		white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
	}
	.nw-asc-level {
		font-family:'Chakra Petch',monospace; font-size:0.6rem; font-weight:600;
		text-transform:uppercase; letter-spacing:0.08em; color:var(--nw-rarity-color,#6b7280);
		white-space:nowrap; display:flex; align-items:center; gap:2px;
	}

	.nw-asc-body { flex:1; padding:0 12px 8px; display:flex; flex-direction:column; gap:4px; }
	.nw-asc-desc { font-size:0.7rem; color:#888; line-height:1.4; margin:0; }
	.nw-asc-effect { font-size:0.68rem; font-style:italic; color:#555; margin:0; padding-top:3px; border-top:1px solid rgba(255,255,255,0.06); }

	.nw-asc-dots { display:flex; gap:5px; align-items:center; margin:4px 0; }
	.nw-asc-dot { width:8px; height:8px; border-radius:50%; background:#2a2a2a; border:1px solid #3a3a3a; transition:background 0.2s,box-shadow 0.2s; }
	.nw-asc-dot--lit { background:var(--nw-rarity-color,#adff00); border-color:var(--nw-rarity-color,#adff00); box-shadow:0 0 5px var(--nw-rarity-color,#adff00); }

	.nw-asc-bonuses { display:flex; flex-wrap:wrap; gap:4px; margin:2px 0; }
	.nw-asc-bonus-pill {
		display:inline-flex; align-items:center; gap:3px;
		font-family:'Chakra Petch',monospace; font-size:0.62rem; font-weight:600;
		text-transform:uppercase; letter-spacing:0.06em; padding:2px 6px;
		border-radius:3px; background:rgba(173,255,0,0.1); color:#adff00; border:1px solid rgba(173,255,0,0.25);
	}
	.nw-asc-bonus-pill--next { background:rgba(173,255,0,0.04); color:rgba(173,255,0,0.5); border-color:rgba(173,255,0,0.12); }

	.nw-asc-bonuses-preview {
		display:flex; flex-wrap:wrap; align-items:center; gap:4px;
		padding:5px 6px; border-radius:4px; border:1px dashed rgba(173,255,0,0.15); background:rgba(173,255,0,0.03);
	}
	.nw-asc-preview-label {
		width:100%; font-family:'Chakra Petch',monospace; font-size:0.58rem;
		text-transform:uppercase; letter-spacing:0.08em; color:rgba(173,255,0,0.4); margin-bottom:2px;
	}

	.nw-asc-progress-wrap { margin-top:4px; }
	.nw-asc-progress-bar { height:3px; background:rgba(255,255,255,0.08); border-radius:2px; overflow:hidden; }
	.nw-asc-progress-fill { height:100%; background:var(--nw-rarity-color,#adff00); border-radius:2px; transition:width 0.4s ease; }
	.nw-asc-progress-label {
		display:flex; justify-content:space-between;
		font-family:'Chakra Petch',monospace; font-size:0.58rem; color:#555;
		margin-top:3px; text-transform:uppercase; letter-spacing:0.05em;
	}

	.nw-asc-footer { padding:8px 12px 10px; border-top:1px solid rgba(255,255,255,0.06); }
	.nw-asc-btn {
		display:flex; align-items:center; gap:5px; width:100%; justify-content:center;
		font-family:'Chakra Petch',monospace; font-size:0.65rem; font-weight:700;
		text-transform:uppercase; letter-spacing:0.1em; padding:6px 10px;
		border-radius:4px; border:1px solid; cursor:pointer; transition:all 0.2s ease;
	}
	.nw-asc-btn--ready { background:rgba(173,255,0,0.1); color:#adff00; border-color:rgba(173,255,0,0.4); }
	.nw-asc-btn--ready:hover { background:rgba(173,255,0,0.2); box-shadow:0 0 8px rgba(173,255,0,0.3); }
	.nw-asc-btn--locked { background:transparent; color:#444; border-color:#333; cursor:not-allowed; }
	.nw-asc-btn--max { background:transparent; color:#adff00; border-color:rgba(173,255,0,0.3); cursor:default; }
	</style>
	<?php
	return ob_get_clean();
}

function _nw_asc_selector( array $characters, string $current_id ): string {
	$current_url = esc_url( strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ) );
	ob_start();
	?>
	<div class="ach-filter-form">
		<label class="ach-filter-label" for="nw-char-select">
			<i data-lucide="user" style="width:14px;height:14px;vertical-align:middle;"></i>
			Character
		</label>
		<select id="nw-char-select"
			onchange="window.location = '<?php echo $current_url; ?>?nw_char=' + this.value">
			<?php foreach ( $characters as $char ) :
				$cid  = _nw_asc_char_id( $char );
				$name = _nw_asc_char_field( $char, 'name' );
				$lvl  = _nw_asc_char_field( $char, 'lvl' );
			?>
				<option value="<?php echo esc_attr( $cid ); ?>"
					<?php selected( $cid, $current_id ); ?>>
					<?php echo esc_html( $name ); ?>
					<?php if ( $lvl !== '' ) : ?>(Lvl <?php echo (int) $lvl; ?>)<?php endif; ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'nw_ascension', 'nw_shortcode_ascension' );

endif;
