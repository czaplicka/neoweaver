<?php
/**
 * ascension.php  — shortcode [nw_ascension]
 * Shows cards eligible for Ascension for a given character.
 * Usage: [nw_ascension character_id="{uuid}"]
 *        or just [nw_ascension] — auto-detects / shows selector
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'nw_shortcode_ascension' ) ) :

function nw_shortcode_ascension( array $atts ): string {
	$atts = shortcode_atts( [ 'character_id' => '' ], $atts );

	if ( ! is_user_logged_in() ) {
		return '<p class="nw-notice">You must be logged in.</p>';
	}

	$user_id      = get_current_user_id();
	$character_id = nw_sanitize_uuid( $atts['character_id'] );

	// Pobierz wszystkie postacie usera
	$characters = function_exists( 'tw_get_user_characters' )
		? tw_get_user_characters( $user_id )
		: [];

	if ( empty( $characters ) ) {
		return '<p class="nw-notice">No character found. Create a character first.</p>';
	}

	// Jeśli nie podano character_id z atrybutu, weź z query string lub pierwszą postać
	if ( ! $character_id ) {
		$qs_id = isset( $_GET['nw_char'] ) ? nw_sanitize_uuid( $_GET['nw_char'] ) : '';
		if ( $qs_id ) {
			// Sprawdź czy ta postać należy do usera
			foreach ( $characters as $c ) {
				if ( (string) $c->id === $qs_id ) {
					$character_id = $qs_id;
					break;
				}
			}
		}
		if ( ! $character_id ) {
			$character_id = (string) $characters[0]->id;
		}
	}

	// Weryfikacja właściciela
	if ( function_exists( 'tw_user_owns_character' ) && ! tw_user_owns_character( $character_id, $user_id ) ) {
		return '<p class="nw-notice">Character not found.</p>';
	}

	// --- Fetch kart postaci ---
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return '<p class="nw-notice">Supabase not configured.</p>';
	}

	$owned = tw_supabase_get( 'cyber_character_deck', [
		'character_id' => 'eq.' . $character_id,
		'is_locked'    => 'eq.false',
		'select'       => 'id,deck_id,current_level,current_xp,ascension_level',
	] );

	if ( is_wp_error( $owned ) || ! is_array( $owned ) || empty( $owned ) ) {
		$no_cards = '<div class="nw-ascension-empty"><i data-lucide="layers"></i><p>No cards available for Ascension.</p></div>';
		if ( count( $characters ) > 1 ) {
			return nw_ascension_selector( $characters, $character_id ) . $no_cards;
		}
		return $no_cards;
	}

	// Grupowanie po deck_id
	$groups = [];
	foreach ( $owned as $card ) {
		$did = (string) $card['deck_id'];
		if ( ! isset( $groups[ $did ] ) ) {
			$groups[ $did ] = [ 'copies' => [], 'ascended' => [] ];
		}
		if ( (int) $card['ascension_level'] === 0 ) {
			$groups[ $did ]['copies'][] = $card;
		} else {
			$groups[ $did ]['ascended'][] = $card;
		}
	}

	// Koszty wzniesienia: [poziom_docelowy => wymagane_kopie]
	$asc_cost = [ 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 ];

	// Karty kwalifikujące się do Ascension
	$deck_ids     = array_keys( $groups );
	$eligible_ids = [];
	foreach ( $deck_ids as $did ) {
		$base_count   = count( $groups[ $did ]['copies'] );
		$has_ascended = ! empty( $groups[ $did ]['ascended'] );
		if ( $base_count >= 2 || $has_ascended ) {
			$eligible_ids[] = $did;
		}
	}

	if ( empty( $eligible_ids ) ) {
		$msg = '<div class="nw-ascension-empty"><i data-lucide="layers"></i><p>Collect duplicate cards to unlock Ascension.</p></div>';
		if ( count( $characters ) > 1 ) {
			return nw_ascension_selector( $characters, $character_id ) . $msg;
		}
		return $msg;
	}

	// Pobierz definicje kart
	$id_list   = implode( ',', array_map( 'esc_sql', $eligible_ids ) );
	$card_defs = tw_supabase_get( 'cyber_deck', [
		'id'     => 'in.(' . $id_list . ')',
		'select' => 'id,name,rarity,level,deck_category,img_url',
	] );
	$defs_by_id = [];
	foreach ( (array) $card_defs as $def ) {
		$defs_by_id[ (string) $def['id'] ] = $def;
	}

	// Render
	wp_enqueue_style( 'neoweaver-foundry' );
	$nonce = wp_create_nonce( 'nw_ascension_nonce' );

	ob_start();
	?>
	<div class="nw-ascension-wrap" data-character="<?php echo esc_attr( $character_id ); ?>">
		<?php if ( count( $characters ) > 1 ) : ?>
			<?php echo nw_ascension_selector( $characters, $character_id ); ?>
		<?php endif; ?>

		<h2 class="nw-ascension-title">⬡ Ascension Forge</h2>

		<div class="nw-ascension-grid">
		<?php foreach ( $eligible_ids as $did ) :
			$def        = $defs_by_id[ $did ] ?? null;
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
	<?php
	return ob_get_clean();
}

/**
 * Renderuje dropdown wyboru postaci.
 * Po wyborze przeładowuje stronę z ?nw_char={id}.
 *
 * @param object[] $characters  Wynik tw_get_user_characters().
 * @param string   $current_id  Aktualnie wybrany character UUID.
 * @return string               HTML selektora.
 */
function nw_ascension_selector( array $characters, string $current_id ): string {
	$current_url = esc_url( strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ) );
	ob_start();
	?>
	<div class="nw-char-selector">
		<label for="nw-char-select"><i data-lucide="user"></i> Character</label>
		<select id="nw-char-select" onchange="window.location = '<?php echo $current_url; ?>?nw_char=' + this.value">
			<?php foreach ( $characters as $char ) : ?>
				<option value="<?php echo esc_attr( $char->id ); ?>"
					<?php selected( (string) $char->id, $current_id ); ?>>
					<?php echo esc_html( $char->name ); ?>
					<?php if ( isset( $char->lvl ) ) : ?>(Lvl <?php echo (int) $char->lvl; ?>)<?php endif; ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'nw_ascension', 'nw_shortcode_ascension' );

endif;
