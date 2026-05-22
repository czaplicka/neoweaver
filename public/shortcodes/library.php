<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'cyber_deck_builder_shortcode' ) ) {
	function cyber_deck_builder_shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'user_id' => get_current_user_id(),
				'char_id' => null,
			),
			$atts
		);

		$current_user_id = (int) $a['user_id'];

		if ( ! $current_user_id ) {
			return '<p>Log in to manage your deck.</p>';
		}

		$selected_char_id = null;

		if ( isset( $_GET['char_id'] ) && is_scalar( $_GET['char_id'] ) ) {
			$selected_char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ) );
		} elseif ( ! empty( $a['char_id'] ) ) {
			$selected_char_id = sanitize_text_field( (string) $a['char_id'] );
		}

		$characters = array();

		if ( function_exists( 'tw_get_user_characters' ) ) {
			$characters = tw_get_user_characters( $current_user_id );
		}

		if ( empty( $characters ) || ! is_array( $characters ) ) {
			return '<p>No characters found. Create one first.</p>';
		}

		$allowed_char_ids = array_values(
			array_filter(
				array_map(
					static function ( $char ) {
						return isset( $char->id ) ? (string) $char->id : '';
					},
					$characters
				)
			)
		);

		if ( empty( $allowed_char_ids ) ) {
			return '<p>No characters found. Create one first.</p>';
		}

		if ( ! empty( $selected_char_id ) && ! in_array( $selected_char_id, $allowed_char_ids, true ) ) {
			$selected_char_id = null;
		}

		if ( empty( $selected_char_id ) ) {
			$selected_char_id = $allowed_char_ids[0];
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $selected_char_id );

		// ── 1. Karty w aktywnej rozgrywce (cyber_buffer) ──────────────────────────
		$buffer_cards = array();
		if ( function_exists( 'tw_supabase_get' ) ) {
			$raw_buffer = tw_supabase_get(
				'cyber_buffer',
				array(
					'char_id' => 'eq.' . $safe_id,
					'select'  => 'id,zone,deck_card_id,cyber_character_deck(id,card_id,cyber_deck(id,name,img_url,deck_category,type,rarity,level,description,effect))',
				)
			);
			if ( is_array( $raw_buffer ) ) {
				$buffer_cards = $raw_buffer;
			}
		}

		// Zbierz IDs rekordów cyber_character_deck będących w bufferze
		$in_game_deck_ids = array();
		foreach ( $buffer_cards as $b ) {
			$deck_row_id = $b['deck_card_id'] ?? ( $b['cyber_character_deck']['id'] ?? null );
			if ( $deck_row_id ) {
				$in_game_deck_ids[] = (int) $deck_row_id;
			}
		}

		// ── 2. Wszystkie karty postaci (cyber_character_deck) ─────────────────────
		$all_assigned = array();
		if ( function_exists( 'tw_supabase_get' ) ) {
			$raw_assigned = tw_supabase_get(
				'cyber_character_deck',
				array(
					'character_id' => 'eq.' . $safe_id,
					'select'       => 'id,card_id,cyber_deck(id,name,img_url,deck_category,type,rarity,level,description,effect)',
				)
			);
			if ( is_array( $raw_assigned ) ) {
				$all_assigned = $raw_assigned;
			}
		}

		// Karty NIE będące w bufferze = nieaktywne (biblioteka)
		$inactive_cards = array_filter(
			$all_assigned,
			static function ( $row ) use ( $in_game_deck_ids ) {
				return ! in_array( (int) ( $row['id'] ?? 0 ), $in_game_deck_ids, true );
			}
		);

		if ( function_exists( 'tw_enqueue_library_assets' ) ) {
			tw_enqueue_library_assets(
				array(
					'characterId' => $safe_id,
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'cyber_deck_builder' ),
					'limits'      => array(
						'minActive' => 20,
						'maxActive' => 50,
					),
				)
			);
		}

		ob_start();
		?>
		<div class="deck-builder-wrap" data-deck-builder-root="1" data-character-id="<?php echo esc_attr( $safe_id ); ?>">

			<?php if ( count( $characters ) > 1 ) : ?>
				<form method="get" class="ach-filter-form">
					<?php foreach ( $_GET as $key => $value ) : ?>
						<?php
						if ( 'char_id' === $key ) {
							continue;
						}
						if ( ! is_scalar( $value ) ) {
							continue;
						}
						?>
						<input
							type="hidden"
							name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( wp_unslash( (string) $value ) ); ?>"
						>
					<?php endforeach; ?>

					<label for="deck-char-select" class="ach-filter-label">Character</label>
					<select id="deck-char-select" name="char_id" onchange="this.form.submit()">
						<?php foreach ( $characters as $char ) : ?>
							<?php
							$char_id = isset( $char->id ) ? (string) $char->id : '';
							$label   = isset( $char->name ) ? (string) $char->name : 'Unnamed character';

							if ( isset( $char->lvl ) ) {
								$label .= ' (Lv. ' . (int) $char->lvl . ')';
							}
							?>
							<option value="<?php echo esc_attr( $char_id ); ?>" <?php selected( $selected_char_id, $char_id ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>
			<?php endif; ?>

			<div class="deck-builder-container">
				<div id="deck-warning" class="deck-warning"></div>

				<?php // ── SEKCJA 1: KARTY W ROZGRYWCE (z cyber_buffer, 20–50) ── ?>
				<div class="deck-section">
					<h3>IN GAME (<?php echo count( $buffer_cards ); ?> / 20–50)</h3>
					<div id="active-deck" class="card-slot-container card-slot-container--active">
						<?php if ( empty( $buffer_cards ) ) : ?>
							<p class="deck-empty-note">No cards in active play.</p>
						<?php else : ?>
							<?php foreach ( $buffer_cards as $buf ) : ?>
								<?php
								$buf_id  = (string) ( $buf['id'] ?? '' );
								$zone    = (string) ( $buf['zone'] ?? 'hand' );
								$deck_row = is_array( $buf['cyber_character_deck'] ?? null ) ? $buf['cyber_character_deck'] : array();
								$cdata   = is_array( $deck_row['cyber_deck'] ?? null ) ? $deck_row['cyber_deck'] : array();
								$img_url = (string) ( $cdata['img_url'] ?? '' );
								$name    = (string) ( $cdata['name'] ?? '' );
								$level   = (string) ( $cdata['level'] ?? '' );

								if ( '' === $buf_id ) {
									continue;
								}
								?>
								<div
									class="cyber-card cyber-card--<?php echo esc_attr( $zone ); ?>"
									id="card-buf-<?php echo esc_attr( $buf_id ); ?>"
									data-buffer-id="<?php echo esc_attr( $buf_id ); ?>"
									data-zone="<?php echo esc_attr( $zone ); ?>"
									data-card-location="active"
								>
									<?php if ( '' !== $img_url ) : ?>
										<img
											src="<?php echo esc_url( $img_url ); ?>"
											alt="<?php echo esc_attr( $name ); ?>"
											loading="lazy"
										>
									<?php endif; ?>
									<div class="card-info">
										<div class="card-name"><?php echo esc_html( $name ); ?></div>
										<div class="card-lvl">LVL <?php echo esc_html( $level ); ?></div>
										<div class="card-zone"><?php echo esc_html( strtoupper( $zone ) ); ?></div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<?php // ── SEKCJA 2: BIBLIOTEKA (karty nieaktywne, bez limitu) ── ?>
				<div class="deck-section">
					<h3>LIBRARY (<?php echo count( $inactive_cards ); ?> cards)</h3>
					<div id="library-deck" class="card-slot-container card-slot-container--library">
						<?php if ( empty( $inactive_cards ) ) : ?>
							<p class="deck-empty-note">Library is empty.</p>
						<?php else : ?>
							<?php foreach ( $inactive_cards as $card ) : ?>
								<?php
								$iid     = (string) ( $card['id'] ?? '' );
								$cdata   = is_array( $card['cyber_deck'] ?? null ) ? $card['cyber_deck'] : array();
								$img_url = (string) ( $cdata['img_url'] ?? '' );
								$name    = (string) ( $cdata['name'] ?? '' );
								$level   = (string) ( $cdata['level'] ?? '' );

								if ( '' === $iid ) {
									continue;
								}
								?>
								<div
									class="cyber-card"
									draggable="true"
									id="card-<?php echo esc_attr( $iid ); ?>"
									data-instance-id="<?php echo esc_attr( $iid ); ?>"
									data-card-location="library"
								>
									<?php if ( '' !== $img_url ) : ?>
										<img
											src="<?php echo esc_url( $img_url ); ?>"
											alt="<?php echo esc_attr( $name ); ?>"
											loading="lazy"
										>
									<?php endif; ?>
									<div class="card-info">
										<div class="card-name"><?php echo esc_html( $name ); ?></div>
										<div class="card-lvl">LVL <?php echo esc_html( $level ); ?></div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<button id="save-deck-btn" type="button" class="save-deck-btn">
					SYNC WITH TERMINAL
				</button>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	add_shortcode( 'cyber_deck_builder', 'cyber_deck_builder_shortcode' );
}
