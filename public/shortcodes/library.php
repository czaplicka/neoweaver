<?php
/**
 * Shortcode [cyber_deck_library]
 * Displays deck builder: active play cards + library cards.
 *
 * Uses:
 *   cyber_character_deck       — all cards owned by the character
 *   cyber_character_play_cards — cards currently in active game (pile/hand/discard)
 */

// Detect shortcode BEFORE wp_head so tw_register_library_assets() can enqueue on time.
add_action( 'wp', function () {
	global $tw_library_needed, $post;
	if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'cyber_deck_library' ) ) {
		$tw_library_needed = true;
	}
} );

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

	// ── Character selection ───────────────────────────────────────────────
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
		array_map( static fn( $c ) => isset( $c['id'] ) ? (string) $c['id'] : '', $characters )
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

	// ── 1. All cards owned by the character (admin key – bypasses RLS) ──
	$all_assigned = [];
	if ( function_exists( 'tw_supabase_get_admin' ) ) {
		$raw = tw_supabase_get_admin( 'cyber_character_deck', [
			'character_id' => 'eq.' . $safe_id,
			'select'       => 'id,deck_id,current_level,cyber_deck!cyber_character_deck_deck_id_fkey(id,name,img_url,type,rarity,description,effect,deck_category,cyber_card_types!cyber_deck_type_fkey(id,category_id))',
		] );
		if ( is_array( $raw ) ) {
			$all_assigned = $raw;
		}
	}

	// ── 2. Active play cards (admin key – bypasses RLS) ──────────────────
	$active_deck_ids = [];
	if ( function_exists( 'tw_supabase_get_admin' ) ) {
		$raw_play = tw_supabase_get_admin( 'cyber_character_play_cards', [
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

	// ── 2b. Fetch category labels / icons / colors ────────────────────────
	$cat_ids = [];
	foreach ( $all_assigned as $row ) {
		$cdata  = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		$cat_id = (string) ( $cdata['deck_category'] ?? '' );
		if ( $cat_id !== '' ) {
			$cat_ids[ $cat_id ] = true;
		}
	}
	$cat_map = [];
	if ( ! empty( $cat_ids ) && function_exists( 'tw_supabase_get_admin' ) ) {
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

	// ── 3. Split into active vs library ─────────────────────────────
	$active_cards  = [];
	$library_cards = [];

	foreach ( $all_assigned as $row ) {
		$rid   = (int) ( $row['id'] ?? 0 );
		$cdata = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		if ( ! $rid || empty( $cdata ) ) {
			continue;
		}

		$cat_id = (string) ( $cdata['deck_category'] ?? '' );
		$cat    = $cat_map[ $cat_id ] ?? [ 'label' => '', 'icon' => 'tag', 'color' => '#adff00' ];

		$card = [
			'instance_id' => $rid,
			'level'       => (int) ( $row['current_level'] ?? 1 ),
			'img_url'     => (string) ( $cdata['img_url']     ?? '' ),
			'name'        => (string) ( $cdata['name']        ?? '' ),
			'description' => (string) ( $cdata['description'] ?? '' ),
			'effect'      => (string) ( $cdata['effect']      ?? '' ),
			'category'    => (string) ( $cdata['cyber_card_types']['category_id'] ?? '' ),
			'rarity'      => (string) ( $cdata['rarity']      ?? '' ),
			'cat_label'   => $cat['label'],
			'cat_icon'    => $cat['icon'] !== '' ? $cat['icon'] : 'tag',
			'cat_color'   => $cat['color'] !== '' ? $cat['color'] : '#adff00',
		];

		if ( in_array( $rid, $active_deck_ids, true ) ) {
			$active_cards[] = $card;
		} else {
			$library_cards[] = $card;
		}
	}

	// ── Enqueue assets (fallback — normalnie odpala wp hook wyzej) ───────
	if ( function_exists( 'tw_enqueue_library_assets' ) ) {
		tw_enqueue_library_assets( [
			'characterId' => $safe_id,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'cyber_deck_builder' ),
			'limits'      => [ 'minActive' => 20, 'maxActive' => 50 ],
		] );
	}

	$ajax_nonce = wp_create_nonce( 'cyber_deck_library_switch' );
	$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );

	// ── HTML ───────────────────────────────────────────────────────────
	ob_start();
	?>
	<div class="deck-builder-wrap" data-deck-builder-root data-character-id="<?php echo esc_attr( $safe_id ); ?>">

		<?php if ( count( $characters ) > 1 ) : ?>
		<div class="ach-filter-form">
			<label class="ach-filter-label" for="char_id_select">Character</label>
			<select id="char_id_select" name="char_id">
				<?php foreach ( $characters as $char ) :
					$cid   = (string) ( $char['id'] ?? '' );
					$cname = (string) ( $char['name'] ?? $cid );
				?>
				<option value="<?php echo esc_attr( $cid ); ?>"
					<?php selected( $cid, $safe_id ); ?>>
					<?php echo esc_html( $cname ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			<span id="deck-switch-spinner" style="display:none;margin-left:8px;color:var(--nw-text-muted);font-size:.8rem;">Loading...</span>
		</div>
		<?php endif; ?>

		<div id="deck-warning" class="deck-warning"></div>

		<div id="deck-builder-inner" class="deck-builder-container">
			<!-- ── Active deck ── -->
			<div class="deck-section">
				<h3>
					Active Deck
					<span id="active-deck-count" style="font-size:.65rem;margin-left:8px;color:var(--nw-text-muted);">
						<?php echo count( $active_cards ); ?>/50
					</span>
				</h3>
				<div id="active-deck" class="nw-ascension-grid">
					<?php if ( empty( $active_cards ) ) : ?>
						<p class="nw-cards-empty">No cards in active play.</p>
					<?php else : ?>
						<?php foreach ( $active_cards as $card ) : ?>
							<?php echo tw_render_library_card( $card, 'active' ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- ── Library ── -->
			<div class="deck-section">
				<h3>Library</h3>
				<div id="library-deck" class="nw-ascension-grid">
					<?php if ( empty( $library_cards ) ) : ?>
						<p class="nw-cards-empty">Library is empty.</p>
					<?php else : ?>
						<?php foreach ( $library_cards as $card ) : ?>
							<?php echo tw_render_library_card( $card, 'library' ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<button id="save-deck-btn" class="save-deck-btn" disabled>
				SYNC WITH TERMINAL
			</button>

		</div><!-- .deck-builder-container -->
	</div><!-- .deck-builder-wrap -->

	<script>
	(function(){
		var sel = document.getElementById('char_id_select');
		if ( ! sel ) return;
		sel.addEventListener('change', function(){
			var charId  = this.value;
			var spinner = document.getElementById('deck-switch-spinner');
			var inner   = document.getElementById('deck-builder-inner');
			if ( spinner ) spinner.style.display = 'inline';
			if ( inner )   inner.style.opacity   = '0.4';

			var fd = new FormData();
			fd.append('action',  'nw_deck_library_switch');
			fd.append('nonce',   '<?php echo esc_js( $ajax_nonce ); ?>');
			fd.append('char_id', charId);

			fetch('<?php echo esc_js( $ajax_url ); ?>', { method: 'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					if ( data.success && inner ) {
						inner.outerHTML = data.data.html;
						var root = document.querySelector('[data-deck-builder-root]');
						if ( root ) root.setAttribute('data-character-id', charId);
						if ( window.lucide ) window.lucide.createIcons();
					} else {
						if ( inner ) inner.style.opacity = '1';
					}
					if ( spinner ) spinner.style.display = 'none';
				})
				.catch(function(){
					if ( inner )   inner.style.opacity   = '1';
					if ( spinner ) spinner.style.display = 'none';
				});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * Render a single library / active-deck card.
 * Uses the same HTML structure as .nw-asc-card (ascension.php).
 *
 * @param array  $card     Card data.
 * @param string $location 'active' or 'library'.
 * @return string HTML.
 */
function tw_render_library_card( array $card, string $location ): string {

	$rarity_map = [
		'common'    => 'nw-card--common',
		'uncommon'  => 'nw-card--uncommon',
		'rare'      => 'nw-card--rare',
		'epic'      => 'nw-card--epic',
		'legendary' => 'nw-card--legendary',
	];

	$rarity_colors = [
		'common'    => '#6b7280',
		'uncommon'  => '#22c55e',
		'rare'      => '#3b82f6',
		'epic'      => '#a855f7',
		'legendary' => '#f59e0b',
	];

	$rarity_key   = strtolower( $card['rarity'] );
	$rarity_cls   = $rarity_map[ $rarity_key ] ?? 'nw-card--common';
	$active_cls   = ( 'active' === $location ) ? 'nw-card--ready' : '';
	$rarity_color = ( 'active' === $location ) ? '#adff00' : ( $rarity_colors[ $rarity_key ] ?? '#6b7280' );

	$classes = trim( implode( ' ', array_filter( [ 'nw-asc-card', $rarity_cls, $active_cls ] ) ) );

	$name      = esc_html( $card['name'] );
	$level     = (int) $card['level'];
	$desc      = esc_html( $card['description'] );
	$effect    = esc_html( $card['effect'] ?? '' );
	$iid       = esc_attr( (string) $card['instance_id'] );
	$loc       = esc_attr( $location );
	$cat_label = esc_html( $card['cat_label'] ?? '' );
	$cat_icon  = esc_attr( $card['cat_icon']  ?? 'tag' );
	$cat_color = esc_attr( $card['cat_color'] ?? '#adff00' );
	$r_color   = esc_attr( $rarity_color );

	// Image
	$img_html = '';
	if ( ! empty( $card['img_url'] ) ) {
		$src      = esc_url( $card['img_url'] );
		$img_html = <<<HTML
<div class="nw-asc-img-wrap">
	<img src="{$src}" alt="{$name}" loading="lazy" width="200" height="200">
	<div class="nw-asc-img-overlay"></div>
</div>
HTML;
	}

	// Category badge
	$cat_badge = '';
	if ( $cat_label !== '' ) {
		$cat_badge = <<<HTML
<div class="nw-asc-cat-badge"
	style="background:{$cat_color}22; border-color:{$cat_color}66;"
	title="{$cat_label}">
	<i data-lucide="{$cat_icon}" style="color:{$cat_color};"></i>
</div>
HTML;
	}

	// Effect line
	$effect_html = $effect !== '' ? "<p class=\"nw-asc-effect\">{$effect}</p>" : '';

	// Footer button
	$action_label = ( 'active' === $location ) ? 'Remove' : 'Add to Deck';
	$action_icon  = ( 'active' === $location ) ? 'minus-circle' : 'plus-circle';
	$btn_cls      = ( 'active' === $location ) ? 'nw-asc-btn--ready' : 'nw-asc-btn--locked';

	return <<<HTML
<div class="{$classes}"
	 style="--nw-rarity-color:{$r_color}; --nw-cat-color:{$cat_color};"
	 draggable="true"
	 data-instance-id="{$iid}"
	 data-card-location="{$loc}">

	<span class="nw-asc-corner nw-asc-corner--tl"></span>
	<span class="nw-asc-corner nw-asc-corner--tr"></span>
	<span class="nw-asc-corner nw-asc-corner--bl"></span>
	<span class="nw-asc-corner nw-asc-corner--br"></span>

	{$cat_badge}
	{$img_html}

	<div class="nw-asc-header">
		<span class="nw-asc-name">{$name}</span>
		<span class="nw-asc-level">LVL&nbsp;{$level}</span>
	</div>

	<div class="nw-asc-body">
		<p class="nw-asc-desc">{$desc}</p>
		{$effect_html}
	</div>

	<div class="nw-asc-footer">
		<button class="nw-asc-btn {$btn_cls} nw-lib-toggle"
			data-instance-id="{$iid}"
			data-location="{$loc}">
			<i data-lucide="{$action_icon}" style="width:11px;height:11px;vertical-align:middle;"></i>
			{$action_label}
		</button>
	</div>

</div>
HTML;
}

// ── AJAX handler: zwraca tylko inner HTML deck-builder-container ────────────
add_action( 'wp_ajax_nw_deck_library_switch', 'tw_ajax_deck_library_switch' );
function tw_ajax_deck_library_switch(): void {
	check_ajax_referer( 'cyber_deck_library_switch', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( [ 'message' => 'Not logged in.' ] );
	}

	$char_id = isset( $_POST['char_id'] ) ? sanitize_text_field( wp_unslash( $_POST['char_id'] ) ) : '';
	$safe_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char_id ) );

	if ( empty( $safe_id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid character.' ] );
	}

	$characters = function_exists( 'tw_get_user_characters' ) ? tw_get_user_characters( $user_id ) : [];
	$allowed    = array_map( static fn( $c ) => (string) ( $c['id'] ?? '' ), $characters );
	if ( ! in_array( $safe_id, $allowed, true ) ) {
		wp_send_json_error( [ 'message' => 'Access denied.' ] );
	}

	tw_supabase_rpc( 'cyber_init_play_cards', [ 'p_character_id' => $safe_id ] );

	$all_assigned = [];
	if ( function_exists( 'tw_supabase_get_admin' ) ) {
		$raw = tw_supabase_get_admin( 'cyber_character_deck', [
			'character_id' => 'eq.' . $safe_id,
			'select'       => 'id,deck_id,current_level,cyber_deck!cyber_character_deck_deck_id_fkey(id,name,img_url,type,rarity,description,effect,deck_category,cyber_card_types!cyber_deck_type_fkey(id,category_id))',
		] );
		if ( is_array( $raw ) ) $all_assigned = $raw;
	}

	$active_deck_ids = [];
	if ( function_exists( 'tw_supabase_get_admin' ) ) {
		$raw_play = tw_supabase_get_admin( 'cyber_character_play_cards', [
			'character_id' => 'eq.' . $safe_id,
			'select'       => 'character_deck_id',
		] );
		if ( is_array( $raw_play ) ) {
			foreach ( $raw_play as $p ) {
				$did = (int) ( $p['character_deck_id'] ?? 0 );
				if ( $did ) $active_deck_ids[] = $did;
			}
		}
	}

	// Fetch categories
	$cat_ids = [];
	foreach ( $all_assigned as $row ) {
		$cdata  = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		$cat_id = (string) ( $cdata['deck_category'] ?? '' );
		if ( $cat_id !== '' ) $cat_ids[ $cat_id ] = true;
	}
	$cat_map = [];
	if ( ! empty( $cat_ids ) && function_exists( 'tw_supabase_get_admin' ) ) {
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

	$active_cards  = [];
	$library_cards = [];
	foreach ( $all_assigned as $row ) {
		$rid   = (int) ( $row['id'] ?? 0 );
		$cdata = is_array( $row['cyber_deck'] ?? null ) ? $row['cyber_deck'] : [];
		if ( ! $rid || empty( $cdata ) ) continue;

		$cat_id = (string) ( $cdata['deck_category'] ?? '' );
		$cat    = $cat_map[ $cat_id ] ?? [ 'label' => '', 'icon' => 'tag', 'color' => '#adff00' ];

		$card = [
			'instance_id' => $rid,
			'level'       => (int) ( $row['current_level'] ?? 1 ),
			'img_url'     => (string) ( $cdata['img_url']     ?? '' ),
			'name'        => (string) ( $cdata['name']        ?? '' ),
			'description' => (string) ( $cdata['description'] ?? '' ),
			'effect'      => (string) ( $cdata['effect']      ?? '' ),
			'category'    => (string) ( $cdata['cyber_card_types']['category_id'] ?? '' ),
			'rarity'      => (string) ( $cdata['rarity']      ?? '' ),
			'cat_label'   => $cat['label'],
			'cat_icon'    => $cat['icon'] !== '' ? $cat['icon'] : 'tag',
			'cat_color'   => $cat['color'] !== '' ? $cat['color'] : '#adff00',
		];

		if ( in_array( $rid, $active_deck_ids, true ) ) {
			$active_cards[] = $card;
		} else {
			$library_cards[] = $card;
		}
	}

	ob_start();
	?>
	<div id="deck-builder-inner" class="deck-builder-container">
		<div class="deck-section">
			<h3>
				Active Deck
				<span id="active-deck-count" style="font-size:.65rem;margin-left:8px;color:var(--nw-text-muted);">
					<?php echo count( $active_cards ); ?>/50
				</span>
			</h3>
			<div id="active-deck" class="nw-ascension-grid">
				<?php if ( empty( $active_cards ) ) : ?>
					<p class="nw-cards-empty">No cards in active play.</p>
				<?php else : foreach ( $active_cards as $card ) : ?>
					<?php echo tw_render_library_card( $card, 'active' ); ?>
				<?php endforeach; endif; ?>
			</div>
		</div>

		<div class="deck-section">
			<h3>Library</h3>
			<div id="library-deck" class="nw-ascension-grid">
				<?php if ( empty( $library_cards ) ) : ?>
					<p class="nw-cards-empty">Library is empty.</p>
				<?php else : foreach ( $library_cards as $card ) : ?>
					<?php echo tw_render_library_card( $card, 'library' ); ?>
				<?php endforeach; endif; ?>
			</div>
		</div>

		<button id="save-deck-btn" class="save-deck-btn" disabled>
			SYNC WITH TERMINAL
		</button>
	</div>
	<?php
	$html = ob_get_clean();
	wp_send_json_success( [ 'html' => $html, 'char_id' => $safe_id ] );
}
