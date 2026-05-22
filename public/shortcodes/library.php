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

		$safe_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $selected_char_id );

		$all_cards = array();

		if ( function_exists( 'tw_supabase_get' ) ) {
			$all_cards = tw_supabase_get(
				'cyber_character_deck',
				array(
					'character_id' => 'eq.' . $safe_id,
					'select'       => '*,cyber_deck(id,name,img_url,deck_category,type,rarity,level,description,effect)',
				)
			);
		}

		if ( ! is_array( $all_cards ) ) {
			$all_cards = array();
		}

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

				<div class="deck-section">
					<h3>ACTIVE DECK (20 – 50)</h3>
					<div id="active-deck" class="card-slot-container card-slot-container--active">
						<?php
						$active_locations = array( 'pile', 'hand', 'discard' );
						foreach ( $all_cards as $card ) :
							$loc = isset( $card['location'] ) ? (string) $card['location'] : '';

							if ( ! in_array( $loc, $active_locations, true ) ) {
								continue;
							}

							$iid     = (string) ( $card['instance_id'] ?? $card['id'] ?? '' );
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
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="deck-section">
					<h3>LIBRARY (REPOSITORY)</h3>
					<div id="library-deck" class="card-slot-container card-slot-container--library">
						<?php foreach ( $all_cards as $card ) : ?>
							<?php
							if ( 'library' !== (string) ( $card['location'] ?? '' ) ) {
								continue;
							}

							$iid     = (string) ( $card['instance_id'] ?? $card['id'] ?? '' );
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
