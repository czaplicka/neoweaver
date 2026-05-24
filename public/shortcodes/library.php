<?php
/**
 * Shortcode [cyber_deck_library]
 * Displays deck builder: active play cards + library cards.
 *
 * Uses:
 *   cyber_character_deck       — all cards owned by the character
 *   cyber_character_play_cards — cards currently in active game (pile/hand/discard)
 */

add_shortcode( 'cyber_deck_library', 'tw_shortcode_deck_library' );
function tw_shortcode_deck_library( $atts ): string {

	$a = shortcode_atts( [
		'user_id' => get_current_user_id(),
		'char_id' => null,
	], $atts );

	$current_user_id = (int) $a['user_id'];
	if ( ! $current_user_id ) {
		return '<p class="nw-notice">Log in to manage your deck.</p>';
	}

	// ── Character selection ────────────────────────────────────────────
	$selected_char_id = null;
	if ( isset( $_GET['char_id'] ) && is_scalar( $_GET['char_id'] ) ) {
		$selected_char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ) );
	} elseif ( ! empty( $a['char_id'] ) ) {
		$selected_char_id = sanitize_text_field( (string) $a['char_id'] );
	}

	$characters = function_exists( 'tw_get_user_characters' )
		? tw_get_user_characters( $current_user_id )
		: [];

	if ( empty( $characters ) || ! is_array( $characters ) ) {
		return '<p class="nw-notice">No characters found. Create one first.</p>';
	}

	$allowed_char_ids = array_values( array_filter(
		array_map( static fn( $c ) => isset( $c->id ) ? (string) $c->id : '', $characters )
	) );

	if ( empty( $allowed_char_ids ) ) {
		return '<p class="nw-notice">No characters found. Create one first.</p>';
	}

	if ( ! empty( $selected_char_id ) && ! in_array( $selected_char_id, $allowed_char_ids, true ) ) {
		$selected_char_id = null;
	}
	if ( empty( $selected_char_id ) ) {
		$selected_char_id = $allowed_char_ids[0];
	}

	$safe_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( (string) $selected_char_id ) );

	$result = tw_supabase_rpc( 'cyber_init_play_cards', [
    'p_character_id' => $safe_id,
] );

	// ── 1. All cards owned by the character ────────────────────────────
	// cyber_character_deck.id  = instance id (sent to JS as data-instance-id)
	// cyber_deck.*             = card definition
	$all_assigned = [];
	if ( function_exists( 'tw_supabase_get' ) ) {
		$raw = tw_supabase_get( 'cyber_character_deck', [
			'character_id' => 'eq.' . $safe_id,
			'select' => 'id,deck_id,current_level,cyber_deck!cyber_character_deck_deck_id_fkey(id,name,img_url,deck_category,type,rarity,description,effect)',
		] );
		if ( is_array( $raw ) ) {
			$all_assigned = $raw;
		}
	}

	// ── 2. Active play cards (pile / hand / discard) ───────────────────
	// These are cyber_character_deck.id values already in a game session.
	$active_deck_ids = [];
	if ( function_exists( 'tw_supabase_get' ) ) {
		$raw_play = tw_supabase_get( 'cyber_character_play_cards', [
			'character_id' => 'eq.' . $safe_id,
			'select'       => 'character_deck_id',
		] );
		if ( is_array( $raw_play ) ) {
			foreach ( $raw_play as $p ) {
				$did = (int) ( $p['character_deck_id'] ?? 0 );
				if ( $did ) {
					$active_deck_ids[] = $did;
				}
			}
		}
	}

	// ── 3. Split into active vs library ───────────────────────────────
	$active_cards  = [];
	$library_cards = [];

	foreach ( $all_assigned as $row ) {
		$rid   = (int) ( $row['id'] ?? 0 );
		$cdata = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		if ( ! $rid || empty( $cdata ) ) {
			continue;
		}

		$card = [
			'instance_id' => $rid,
			'level'       => (int) ( $row['current_level'] ?? 1 ),
			'img_url'     => (string) ( $cdata['img_url']  ?? '' ),
			'name'        => (string) ( $cdata['name']     ?? '' ),
			'category'    => (string) ( $cdata['deck_category'] ?? '' ),
			'rarity'      => (string) ( $cdata['rarity']   ?? '' ),
		];

		if ( in_array( $rid, $active_deck_ids, true ) ) {
			$active_cards[] = $card;
		} else {
			$library_cards[] = $card;
		}
	}
	// ── Enqueue assets ─────────────────────────────────────────────────
	if ( function_exists( 'tw_enqueue_library_assets' ) ) {
		tw_enqueue_library_assets( [
			'characterId' => $safe_id,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'cyber_deck_builder' ),
			'limits'      => [ 'minActive' => 20, 'maxActive' => 50 ],
		] );
	}

	// ── HTML ───────────────────────────────────────────────────────────
	ob_start();
	?>
	<div class="deck-builder-wrap" data-deck-builder-root data-character-id="<?php echo esc_attr( $safe_id ); ?>">

		<?php if ( count( $characters ) > 1 ) : ?>
		<form class="ach-filter-form" method="get">
			<label class="ach-filter-label" for="char_id_select">Character</label>
			<select id="char_id_select" name="char_id" onchange="this.form.submit()">
				<?php foreach ( $characters as $char ) :
					$cid   = (string) ( $char->id ?? '' );
					$cname = (string) ( $char->name ?? $cid );
				?>
				<option value="<?php echo esc_attr( $cid ); ?>"
					<?php selected( $cid, $safe_id ); ?>>
					<?php echo esc_html( $cname ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php endif; ?>

		<div id="deck-warning" class="deck-warning"></div>

		<div class="deck-builder-container">
			<!-- ── Active deck ── -->
			<div class="deck-section">
				<h3>
					Active Deck
					<span id="active-deck-count" style="font-size:.65rem;margin-left:8px;color:var(--nw-text-muted);">
						<?php echo count( $active_cards ); ?>/50
					</span>
				</h3>
				<div id="active-deck" class="card-slot-container">
					<?php if ( empty( $active_cards ) ) : ?>
						<p class="deck-empty-note">No cards in active play.</p>
					<?php else : ?>
						<?php foreach ( $active_cards as $card ) : ?>
							<div class="cyber-card cyber-card--active"
								draggable="true"
								data-instance-id="<?php echo esc_attr( $card['instance_id'] ); ?>"
								data-card-location="active">
								<?php if ( ! empty( $card['img_url'] ) ) : ?>
									<img src="<?php echo esc_url( $card['img_url'] ); ?>"
										alt="<?php echo esc_attr( $card['name'] ); ?>"
										loading="lazy" width="120" height="118">
								<?php endif; ?>
								<div class="card-info">
									<div class="card-name"><?php echo esc_html( $card['name'] ); ?></div>
									<div class="card-lvl">LVL <?php echo esc_html( $card['level'] ); ?></div>
									<?php if ( ! empty( $card['category'] ) ) : ?>
										<span class="card-zone"><?php echo esc_html( strtoupper( $card['category'] ) ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- ── Library ── -->
			<div class="deck-section">
				<h3>Library</h3>
				<div id="library-deck" class="card-slot-container">
					<?php if ( empty( $library_cards ) ) : ?>
						<p class="deck-empty-note">Library is empty.</p>
					<?php else : ?>
						<?php foreach ( $library_cards as $card ) : ?>
							<div class="cyber-card"
								draggable="true"
								data-instance-id="<?php echo esc_attr( $card['instance_id'] ); ?>"
								data-card-location="library">
								<?php if ( ! empty( $card['img_url'] ) ) : ?>
									<img src="<?php echo esc_url( $card['img_url'] ); ?>"
										alt="<?php echo esc_attr( $card['name'] ); ?>"
										loading="lazy" width="120" height="118">
								<?php endif; ?>
								<div class="card-info">
									<div class="card-name"><?php echo esc_html( $card['name'] ); ?></div>
									<div class="card-lvl">LVL <?php echo esc_html( $card['level'] ); ?></div>
									<?php if ( ! empty( $card['category'] ) ) : ?>
										<span class="card-zone"><?php echo esc_html( strtoupper( $card['category'] ) ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<button id="save-deck-btn" class="save-deck-btn" disabled>
				SYNC WITH TERMINAL
			</button>

		</div><!-- .deck-builder-container -->
	</div><!-- .deck-builder-wrap -->
	<?php
	return ob_get_clean();
}
