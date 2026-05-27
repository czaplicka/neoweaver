<?php
/**
 * ascension.php  — shortcode [nw_ascension]
 * Shows cards eligible for Ascension for a given character.
 * Usage: [nw_ascension character_id="{uuid}"]
 *        or just [nw_ascension] — picks active character automatically
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'nw_shortcode_ascension' ) ) :

function nw_shortcode_ascension( array $atts ): string {
	$atts = shortcode_atts( [ 'character_id' => '' ], $atts );

	if ( ! is_user_logged_in() ) {
		return '<p class="nw-notice">You must be logged in.</p>';
	}

	$user_id      = get_current_user_id();
	$character_id = sanitize_text_field( $atts['character_id'] );

	// Auto-detect active character if not provided
	if ( ! $character_id && function_exists( 'tw_supabase_first' ) ) {
		$char = tw_supabase_first( 'cyber_characters', [
			'wp_user_id' => 'eq.' . $user_id,
			'select'     => 'id,name',
			'limit'      => 1,
			'order'      => 'created_at.desc',
		] );
		$character_id = $char['id'] ?? '';
	}

	if ( ! $character_id ) {
		return '<p class="nw-notice">No character found.</p>';
	}

	// Fetch all non-locked, non-ascended cards for this character
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return '<p class="nw-notice">Supabase not configured.</p>';
	}

	$owned = tw_supabase_get( 'cyber_character_deck', [
		'character_id' => 'eq.' . $character_id,
		'is_locked'    => 'eq.false',
		'select'       => 'id,deck_id,current_level,current_xp,ascension_level',
	] );

	if ( ! is_array( $owned ) || empty( $owned ) ) {
		return '<div class="nw-ascension-empty"><i data-lucide="layers"></i><p>No cards available for Ascension.</p></div>';
	}

	// Group by deck_id — count base copies (ascension_level = 0)
	$groups = [];
	foreach ( $owned as $card ) {
		$did = (int) $card['deck_id'];
		if ( ! isset( $groups[ $did ] ) ) {
			$groups[ $did ] = [ 'copies' => [], 'ascended' => [] ];
		}
		if ( (int) $card['ascension_level'] === 0 ) {
			$groups[ $did ]['copies'][] = $card;
		} else {
			$groups[ $did ]['ascended'][] = $card;
		}
	}

	// Ascension costs: [ascension_level_target => copies_needed]
	$asc_cost = [ 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 ];

	// Fetch card definitions for groups that have ≥2 base copies OR already ascended
	$deck_ids = array_keys( $groups );
	$eligible_ids = [];
	foreach ( $deck_ids as $did ) {
		$base_count     = count( $groups[ $did ]['copies'] );
		$has_ascended   = ! empty( $groups[ $did ]['ascended'] );
		if ( $base_count >= 2 || $has_ascended ) {
			$eligible_ids[] = $did;
		}
	}

	if ( empty( $eligible_ids ) ) {
		return '<div class="nw-ascension-empty"><i data-lucide="layers"></i><p>Collect duplicate cards to unlock Ascension.</p></div>';
	}

	// Batch fetch card definitions
	$id_list  = implode( ',', $eligible_ids );
	$card_defs = tw_supabase_get( 'cyber_deck', [
		'id'     => 'in.(' . $id_list . ')',
		'select' => 'id,name,rarity,level,deck_category,img_url',
	] );
	$defs_by_id = [];
	foreach ( (array) $card_defs as $def ) {
		$defs_by_id[ (int) $def['id'] ] = $def;
	}

	// Render
	wp_enqueue_style( 'neoweaver-foundry' );
	$nonce = wp_create_nonce( 'nw_ascension_nonce' );

	ob_start();
	?>
	<div class="nw-ascension-wrap" data-character="<?php echo esc_attr( $character_id ); ?>">
		<h2 class="nw-ascension-title">⬡ Ascension Forge</h2>

		<div class="nw-ascension-grid">
		<?php foreach ( $eligible_ids as $did ) :
			$def         = $defs_by_id[ $did ] ?? null;
			if ( ! $def ) continue;
			$base_copies = $groups[ $did ]['copies'];
			$base_count  = count( $base_copies );
			$ascended    = $groups[ $did ]['ascended'];
			$cur_asc     = ! empty( $ascended ) ? max( array_column( $ascended, 'ascension_level' ) ) : 0;
			$next_asc    = $cur_asc + 1;
			$required    = $asc_cost[ $next_asc ] ?? 999;
			$can_ascend  = ( $base_count >= $required && $next_asc <= 5 );
			$maxed       = ( $cur_asc >= 5 );
			$rarity_cls  = 'rarity-' . sanitize_html_class( $def['rarity'] ?? 'common' );
		?>
			<div class="nw-asc-card <?php echo $rarity_cls; ?><?php echo $can_ascend ? ' ready' : ''; ?><?php echo $maxed ? ' maxed' : ''; ?>">

				<?php if ( ! empty( $def['img_url'] ) ) : ?>
					<img class="nw-asc-card-img" src="<?php echo esc_url( $def['img_url'] ); ?>" alt="" loading="lazy">
				<?php endif; ?>

				<div class="nw-asc-card-body">
					<div class="nw-asc-card-header">
						<span class="nw-asc-rarity-dot"></span>
						<span class="nw-asc-name"><?php echo esc_html( $def['name'] ); ?></span>
						<span class="nw-asc-category"><?php echo esc_html( $def['deck_category'] ); ?></span>
					</div>

					<div class="nw-asc-stars">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="nw-asc-star <?php echo $i <= $cur_asc ? 'filled' : ''; ?>" data-index="<?php echo $i; ?>">⬡</span>
						<?php endfor; ?>
					</div>

					<?php if ( ! $maxed ) : ?>
					<div class="nw-asc-progress">
						<div class="nw-asc-progress-label">
							<span><?php echo $base_count; ?> / <?php echo $required; ?> copies</span>
							<span class="nw-asc-next-label">ASC <?php echo $next_asc; ?></span>
						</div>
						<div class="nw-asc-bar">
							<div class="nw-asc-bar-fill" style="width:<?php echo min( 100, round( $base_count / $required * 100 ) ); ?>%"></div>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( $maxed ) : ?>
						<p class="nw-asc-maxed-label">MAX ASCENSION</p>
					<?php elseif ( $can_ascend ) : ?>
						<button class="nw-asc-btn"
							data-deck-id="<?php echo esc_attr( $did ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
						>
							<i data-lucide="zap"></i>
							Ascend → Tier <?php echo $next_asc; ?>
						</button>
					<?php else : ?>
						<button class="nw-asc-btn" disabled>
							<i data-lucide="lock"></i>
							Need <?php echo ( $required - $base_count ); ?> more
						</button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
	</div>

	<script>
	(function() {
		document.querySelectorAll('.nw-asc-btn:not([disabled])').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var wrap   = btn.closest('[data-character]');
				var charId = wrap ? wrap.dataset.character : '';
				var deckId = btn.dataset.deckId;
				var nonce  = btn.dataset.nonce;
				if (!charId || !deckId) return;

				btn.disabled = true;
				btn.textContent = 'Processing…';

				var fd = new FormData();
				fd.append('action',       'nw_ascend_card');
				fd.append('nonce',        nonce);
				fd.append('character_id', charId);
				fd.append('deck_id',      deckId);

				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					body: fd
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success) {
						// Animate and reload
						var card = btn.closest('.nw-asc-card');
						if (card) { card.classList.add('ascending'); }
						setTimeout(function() { location.reload(); }, 900);
					} else {
						alert(data.data && data.data.message ? data.data.message : 'Ascension failed.');
						btn.disabled = false;
						btn.textContent = 'Ascend';
					}
				})
				.catch(function() {
					alert('Network error.');
					btn.disabled = false;
				});
			});
		});

		// Init Lucide icons if available
		if (typeof lucide !== 'undefined') { lucide.createIcons(); }
	})();
	</script>
	<?php
	return ob_get_clean();
}

add_shortcode( 'nw_ascension', 'nw_shortcode_ascension' );

endif;
