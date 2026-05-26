<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// SVG badge-cent (Lucide) — używane w kilku miejscach.
define(
	'NW_ICON_GOLD',
	'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"'
	. ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
	. ' stroke-linejoin="round" class="nw-icon-gold" aria-hidden="true">'
	. '<circle cx="12" cy="12" r="10"/>'
	. '<path d="M14.5 9a3.5 3.5 0 0 0-5 0v6a3.5 3.5 0 0 0 5 0"/>'
	. '<path d="M12 6v2m0 8v2"/>'
	. '</svg>'
);

// ============================================================
// AJAX: zwróć karty Foundry dla wybranej postaci
// ============================================================
add_action( 'wp_ajax_nw_foundry_get_cards', 'nw_foundry_get_cards_ajax' );

if ( ! function_exists( 'nw_foundry_get_cards_ajax' ) ) {
	function nw_foundry_get_cards_ajax(): void {
		check_ajax_referer( 'cyber_foundry_upgrade', 'nonce' );

		$user_id      = get_current_user_id();
		$character_id = sanitize_text_field( $_POST['character_id'] ?? '' );

		if ( ! $user_id || empty( $character_id ) ) {
			wp_send_json_error( 'Invalid request.', 400 );
			return;
		}

		if ( ! function_exists( 'tw_user_owns_character' ) || ! tw_user_owns_character( $character_id, $user_id ) ) {
			wp_send_json_error( 'Access denied.', 403 );
			return;
		}

		$cards   = fetch_foundry_data( $character_id );
		$credits = function_exists( 'get_cyber_player_credits' ) ? get_cyber_player_credits( $character_id ) : 0;

		if ( is_wp_error( $cards ) ) {
			wp_send_json_error( $cards->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( [
			'cards'   => $cards,
			'credits' => (int) $credits,
		] );
	}
}

// ============================================================
// Helper: pobierz wszystkie postaci usera
// ============================================================
if ( ! function_exists( 'get_cyber_characters_by_wp_id' ) ) {
	function get_cyber_characters_by_wp_id( int $wp_user_id ): array {
		if ( $wp_user_id <= 0 ) {
			return [];
		}

		$result = tw_supabase_get_admin(
			'cyber_characters',
			[
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id,name',
				'order'      => 'created_at.asc',
			]
		);

		return ( is_wp_error( $result ) || ! is_array( $result ) ) ? [] : $result;
	}
}

// ============================================================
// SHORTCODE [cyber_foundry]
// ============================================================
if ( ! function_exists( 'cyber_foundry_shortcode' ) ) {
	function cyber_foundry_shortcode(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<div class="foundry-container">ERROR: UPLINK REQUIRED. PLEASE LOG IN.</div>';
		}

		if ( ! function_exists( 'fetch_foundry_data' ) ) {
			return '<div class="foundry-container">ERROR: FOUNDRY DATASTREAM OFFLINE.</div>';
		}

		if ( ! function_exists( 'get_cyber_player_credits' ) ) {
			return '<div class="foundry-container">ERROR: CREDIT HELPER NOT AVAILABLE.</div>';
		}

		$characters = get_cyber_characters_by_wp_id( $user_id );

		if ( empty( $characters ) ) {
			return '<div class="foundry-container">ERROR: NO FIELD AGENT DETECTED.</div>';
		}

		$character_id  = (string) ( $characters[0]['id'] ?? '' );
		$library_cards = fetch_foundry_data( $character_id );

		if ( is_wp_error( $library_cards ) ) {
			$library_cards = [];
		}
		if ( ! is_array( $library_cards ) ) {
			$library_cards = [];
		}

		$current_player_credits = (int) get_cyber_player_credits( $character_id );
		$nonce                  = wp_create_nonce( 'cyber_foundry_upgrade' );
		$uid                    = 'foundry_' . wp_generate_uuid4();
		$icon                   = NW_ICON_GOLD;

		if ( function_exists( 'tw_enqueue_foundry_assets' ) ) {
			tw_enqueue_foundry_assets( [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => $nonce,
			] );
		}

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $uid ); ?>"
			class="foundry-container"
			data-foundry-root="1"
			data-character-id="<?php echo esc_attr( $character_id ); ?>"
			data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<h2 class="foundry-title"><span class="blink">_</span> NANITE FOUNDRY</h2>

			<?php if ( count( $characters ) > 1 ) : ?>
				<div class="foundry-agent-select">
					<label for="foundry-char-<?php echo esc_attr( $uid ); ?>">FIELD AGENT:</label>
					<select id="foundry-char-<?php echo esc_attr( $uid ); ?>" class="foundry-character-select">
						<?php foreach ( $characters as $char ) : ?>
							<option
								value="<?php echo esc_attr( (string) $char['id'] ); ?>"
								<?php selected( $char['id'], $character_id ); ?>
							>
								<?php echo esc_html( $char['name'] ?? $char['id'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<div class="foundry-credits" data-credits-display="1">
				GOLD: <span class="credits-value"><?php echo esc_html( (string) $current_player_credits ); ?></span>
				<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

			<div class="foundry-grid" data-foundry-grid="1">
				<?php echo nw_foundry_render_cards( $library_cards, $current_player_credits ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>

		<script>
		(function(){
			const ICON_GOLD = <?php echo wp_json_encode( NW_ICON_GOLD ); ?>;

			const root = document.getElementById( <?php echo wp_json_encode( $uid ); ?> );
			if ( ! root ) return;

			const sel = root.querySelector( '.foundry-character-select' );
			if ( ! sel ) return;

			sel.addEventListener( 'change', function() {
				const charId   = this.value;
				const grid     = root.querySelector( '[data-foundry-grid]' );
				const credSpan = root.querySelector( '.credits-value' );

				grid.innerHTML = '<p class="buffer-empty">LOADING...</p>';

				const fd = new FormData();
				fd.append( 'action',       'nw_foundry_get_cards' );
				fd.append( 'nonce',        root.dataset.nonce );
				fd.append( 'character_id', charId );

				fetch( root.dataset.ajaxUrl, { method: 'POST', body: fd } )
					.then( r => r.json() )
					.then( res => {
						if ( ! res.success ) {
							grid.innerHTML = '<p class="buffer-empty">ERROR: ' + ( res.data || 'UNKNOWN' ) + '</p>';
							return;
						}
						const { cards, credits } = res.data;
						if ( credSpan ) credSpan.textContent = credits;
						root.dataset.characterId = charId;
						grid.innerHTML = nwBuildFoundryCards( cards, credits );
					} )
					.catch( () => {
						grid.innerHTML = '<p class="buffer-empty">ERROR: CONNECTION LOST.</p>';
					} );
			} );

			window.nwBuildFoundryCards = function( cards, credits ) {
				if ( ! cards || ! cards.length ) {
					return '<p class="buffer-empty">NO DATA NODES DETECTED IN ARCHIVE.</p>';
				}
				return cards.map( card => {
					const level      = Math.max( 1, parseInt( card.level ) || 1 );
					const dupes      = Math.max( 0, parseInt( card.duplicate_count ) || 0 );
					const needed     = Math.max( 1, level * 2 );
					const cost       = level * 100;
					const hasDupes   = dupes >= needed;
					const hasCredits = credits >= cost;
					const canUpgrade = hasDupes && hasCredits;
					const progress   = Math.min( 100, Math.max( 0, ( dupes / needed ) * 100 ) );
					const btnLabel   = ! hasDupes ? 'NEED MORE DATA' : ( ! hasCredits ? 'INSUFFICIENT GOLD' : 'START FUSION' );
					const instId     = card.instance_id || '';
					if ( ! instId ) return '';
					return `<div class="foundry-item ${ canUpgrade ? 'ready' : '' }">
						<div class="card-preview">
							<span class="lvl-badge">v.${ level }</span>
							<div class="card-name">${ card.name || '[UNKNOWN]' }</div>
						</div>
						<div class="upgrade-info">
							<div class="progress-bar"><div class="progress-fill" style="width:${ progress }%"></div></div>
							<span class="count-text">DATA NODES: ${ dupes } / ${ needed }</span>
							<div class="credit-cost ${ hasCredits ? '' : 'insufficient' }">COST: ${ cost } ${ ICON_GOLD }</div>
						</div>
						<button class="upgrade-btn" type="button"
							data-action="upgrade-card"
							data-card-instance-id="${ instId }"
							data-card-level="${ level }"
							data-needed-duplicates="${ needed }"
							data-needed-credits="${ cost }"
							${ canUpgrade ? '' : 'disabled' }>${ btnLabel }</button>
					</div>`;
				} ).join( '' );
			};
		})();
		</script>
		<?php

		return ob_get_clean();
	}

	add_shortcode( 'cyber_foundry', 'cyber_foundry_shortcode' );
}

// ============================================================
// Helper renderujący karty po stronie PHP (pierwsze ładowanie)
// ============================================================
if ( ! function_exists( 'nw_foundry_render_cards' ) ) {
	function nw_foundry_render_cards( array $cards, int $credits ): string {
		if ( empty( $cards ) ) {
			return '<p class="buffer-empty">NO DATA NODES DETECTED IN ARCHIVE.</p>';
		}

		$icon = NW_ICON_GOLD;
		$out  = '';

		foreach ( $cards as $card ) {
			$level      = max( 1, (int) ( $card->level ?? 1 ) );
			$name       = esc_html( (string) ( $card->name ?? '[UNKNOWN CARD]' ) );
			$dupes      = max( 0, (int) ( $card->duplicate_count ?? 0 ) );
			$inst_id    = (string) ( $card->instance_id ?? '' );
			if ( '' === $inst_id ) continue;

			$needed    = max( 1, $level * 2 );
			$cost      = $level * 100;
			$has_dupes = $dupes >= $needed;
			$has_creds = $credits >= $cost;
			$can_up    = $has_dupes && $has_creds;
			$progress  = max( 0, min( 100, ( $dupes / $needed ) * 100 ) );
			$btn_label = ! $has_dupes ? 'NEED MORE DATA' : ( ! $has_creds ? 'INSUFFICIENT GOLD' : 'START FUSION' );

			$out .= '<div class="foundry-item ' . ( $can_up ? 'ready' : '' ) . '">';
			$out .= '<div class="card-preview">';
			$out .= '<span class="lvl-badge">v.' . esc_html( (string) $level ) . '</span>';
			$out .= '<div class="card-name">' . $name . '</div>';
			$out .= '</div>';
			$out .= '<div class="upgrade-info">';
			$out .= '<div class="progress-bar"><div class="progress-fill" style="width:' . esc_attr( (string) $progress ) . '%"></div></div>';
			$out .= '<span class="count-text">DATA NODES: ' . esc_html( (string) $dupes ) . ' / ' . esc_html( (string) $needed ) . '</span>';
			// phpcs:ignore WordPress.Security.EscapeOutput -- $icon is a hardcoded SVG constant
			$out .= '<div class="credit-cost ' . ( $has_creds ? '' : 'insufficient' ) . '">COST: ' . esc_html( (string) $cost ) . ' ' . $icon . '</div>';
			$out .= '</div>';
			$out .= '<button class="upgrade-btn" type="button"';
			$out .= ' data-action="upgrade-card"';
			$out .= ' data-card-instance-id="' . esc_attr( $inst_id ) . '"';
			$out .= ' data-card-level="' . esc_attr( (string) $level ) . '"';
			$out .= ' data-needed-duplicates="' . esc_attr( (string) $needed ) . '"';
			$out .= ' data-needed-credits="' . esc_attr( (string) $cost ) . '"';
			$out .= disabled( ! $can_up, true, false ) . '>';
			$out .= esc_html( $btn_label );
			$out .= '</button>';
			$out .= '</div>';
		}

		return $out;
	}
}
