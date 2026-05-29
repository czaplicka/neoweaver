<?php
/**
 * ascension.php  — shortcode [nw_ascension]
 * Shows cards eligible for Ascension for a given character.
 * Usage: [nw_ascension character_id="{uuid}"]
 *        or just [nw_ascension] — auto-detects / shows selector
 *
 * Card layout mirrors library.php (tw_render_library_card):
 *   img → header (name + level) → body (desc, tags, stars, progress) → footer (button)
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

function _nw_asc_category_class( string $cat ): string {
	$map = [
		'magic'     => 'nw-card--magic',
		'combat'    => 'nw-card--combat',
		'action'    => 'nw-card--action',
		'social'    => 'nw-card--social',
		'equipment' => 'nw-card--equipment',
		'tech'      => 'nw-card--tech',
	];
	return $map[ strtolower( $cat ) ] ?? '';
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
		: preg_replace( '/[^a-f0-9\-]/', '', strtolower( $atts['character_id'] ) );

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
			: preg_replace( '/[^a-f0-9\-]/', '', strtolower( $qs_raw ) );

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

	// ── Fetch character's cards (same JOIN as library: cyber_deck + cyber_card_types) ──
	$owned = tw_supabase_get_admin( 'cyber_character_deck', [
		'character_id' => 'eq.' . $character_id,
		'is_locked'    => 'eq.false',
		'select'       => 'id,deck_id,current_level,current_xp,ascension_level,cyber_deck!cyber_character_deck_deck_id_fkey(id,name,img_url,rarity,description,effect,cyber_card_types!cyber_deck_type_fkey(id,category_id))',
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

	// ── Group by deck_id ──
	$groups   = [];
	$defs_map = []; // deck_id => card definition (from first row)

	foreach ( $owned as $row ) {
		$did   = (string) ( $row['deck_id'] ?? '' );
		$cdata = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		if ( '' === $did || empty( $cdata ) ) {
			continue;
		}

		if ( ! isset( $groups[ $did ] ) ) {
			$groups[ $did ]  = [ 'copies' => [], 'ascended' => [] ];
			$defs_map[ $did ] = [
				'id'          => $did,
				'name'        => (string) ( $cdata['name']        ?? '' ),
				'img_url'     => (string) ( $cdata['img_url']     ?? '' ),
				'rarity'      => (string) ( $cdata['rarity']      ?? 'common' ),
				'description' => (string) ( $cdata['description'] ?? '' ),
				'effect'      => (string) ( $cdata['effect']      ?? '' ),
				'category'    => (string) ( $cdata['cyber_card_types']['category_id'] ?? '' ),
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
	$asc_cost    = [ 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 ];
	$eligible    = [];
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
			$def        = $defs_map[ $did ] ?? null;
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

			$rarity     = strtolower( $def['rarity'] );
			$category   = strtolower( $def['category'] );
			$rarity_cls = _nw_asc_rarity_class( $rarity );
			$cat_cls    = _nw_asc_category_class( $category );
			$state_cls  = $can_ascend ? 'nw-card--ready' : ( $maxed ? '' : 'nw-card--dim' );

			$progress_pct = $required > 0 ? min( 100, round( $base_count / $required * 100 ) ) : 100;
		?>
			<div class="nw-card nw-card--foundry <?php echo esc_attr( "$rarity_cls $cat_cls $state_cls" ); ?>"
				data-instance-id="<?php echo esc_attr( (string) ( $base_copies[0]['id'] ?? $did ) ); ?>">

				<?php if ( ! empty( $def['img_url'] ) ) : ?>
					<img src="<?php echo esc_url( $def['img_url'] ); ?>"
						alt="<?php echo esc_attr( $def['name'] ); ?>"
						loading="lazy" width="200" height="200"
						style="width:100%;height:120px;object-fit:cover;">
				<?php endif; ?>

				<div class="nw-card__header">
					<span class="nw-card__name"><?php echo esc_html( $def['name'] ); ?></span>
					<span class="nw-card__level">
						<?php if ( $cur_asc > 0 ) : ?>
							ASC&nbsp;<?php echo $cur_asc; ?>
						<?php else : ?>
							LVL&nbsp;<?php echo (int) ( $base_copies[0]['current_level'] ?? 1 ); ?>
						<?php endif; ?>
					</span>
					<span class="nw-card__rarity-dot"></span>
				</div>

				<div class="nw-card__body">

					<?php if ( ! empty( $def['description'] ) ) : ?>
						<p class="nw-card__desc"><?php echo esc_html( $def['description'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $def['effect'] ) ) : ?>
						<p class="nw-card__effect"><?php echo esc_html( $def['effect'] ); ?></p>
					<?php endif; ?>

					<div class="nw-card__tags">
						<?php if ( $category ) : ?>
							<span class="nw-card__tag"><?php echo esc_html( ucfirst( $category ) ); ?></span>
						<?php endif; ?>
						<span class="nw-card__tag"><?php echo esc_html( ucfirst( $rarity ) ); ?></span>
					</div>

					<!-- Ascension stars -->
					<div class="nw-asc-stars">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="nw-asc-star <?php echo $i <= $cur_asc ? 'nw-asc-star--lit' : ''; ?>">⬡</span>
						<?php endfor; ?>
					</div>

					<?php if ( ! $maxed ) : ?>
					<div class="nw-card__progress">
						<div class="nw-card__progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
					</div>
					<div class="nw-card__progress-label">
						<span><?php echo $base_count; ?> / <?php echo $required; ?> copies</span>
						<span>→ ASC <?php echo $next_asc; ?></span>
					</div>
					<?php endif; ?>

				</div><!-- /.nw-card__body -->

				<div class="nw-card__footer">
					<?php if ( $maxed ) : ?>
						<span class="nw-card__btn" style="cursor:default;color:#adff00;border-color:#adff00;">MAX ⬡</span>
					<?php elseif ( $can_ascend ) : ?>
						<button class="nw-card__btn nw-asc-btn"
							data-deck-id="<?php echo esc_attr( $did ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
						>
							<i data-lucide="zap" style="width:11px;height:11px;vertical-align:middle;"></i>
							Ascend → Tier <?php echo $next_asc; ?>
						</button>
					<?php else : ?>
						<button class="nw-card__btn" disabled>
							<i data-lucide="lock" style="width:11px;height:11px;vertical-align:middle;"></i>
							Need <?php echo ( $required - $base_count ); ?> more
						</button>
					<?php endif; ?>
				</div><!-- /.nw-card__footer -->

			</div><!-- /.nw-card -->
		<?php endforeach; ?>
		</div><!-- /.nw-ascension-grid -->
	</div><!-- /.nw-ascension-wrap -->

	<style>
	.nw-ascension-title {
		font-family: 'Chakra Petch', monospace;
		font-size: 0.85rem;
		text-transform: uppercase;
		letter-spacing: 0.12em;
		color: #adff00;
		margin: 0 0 16px;
	}
	.nw-ascension-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 16px;
	}
	@media (max-width: 900px) {
		.nw-ascension-grid { grid-template-columns: repeat(2, 1fr); }
	}
	@media (max-width: 560px) {
		.nw-ascension-grid { grid-template-columns: 1fr; }
	}
	/* Effect text — smaller, muted italic, separator line */
	.nw-card__effect {
		font-size: 0.7rem;
		font-style: italic;
		color: var(--nw-text-muted, #666);
		margin-top: 4px;
		padding-top: 4px;
		border-top: 1px solid rgba(255,255,255,0.07);
	}
	/* Ascension stars */
	.nw-asc-stars {
		display: flex;
		gap: 4px;
		font-size: 0.75rem;
		letter-spacing: 0.05em;
		margin: 6px 0 4px;
	}
	.nw-asc-star { color: #2a2a2a; }
	.nw-asc-star--lit { color: var(--nw-card-color, #adff00); }
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
