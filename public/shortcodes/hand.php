<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'cyber_buffer_hand_shortcode' ) ) {
	function cyber_buffer_hand_shortcode(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<div class="terminal-error">UPLINK LOST: User not authenticated.</div>';
		}

		// BUG 21 fix — use session-active character, not "first character" for this WP user.
		// get_cyber_character_id_by_wp_id() is for character lists/selection only.
		if ( ! function_exists( 'get_cyber_active_session_character_id' ) ) {
			return '<div class="terminal-error">UPLINK LOST: Character resolver unavailable.</div>';
		}

		if ( ! function_exists( 'fetch_cyber_hand_with_details' ) ) {
			return '<div class="terminal-error">UPLINK LOST: Hand datastore unavailable.</div>';
		}

		$character_id = get_cyber_active_session_character_id( $user_id );

		if ( empty( $character_id ) || ! is_scalar( $character_id ) ) {
			return '<div class="terminal-error">UPLINK LOST: No active game session found.</div>';
		}

		$hand_cards = fetch_cyber_hand_with_details( $character_id );

		if ( is_wp_error( $hand_cards ) ) {
			return '<div class="terminal-error">UPLINK LOST: Hand sync failed.</div>';
		}

		if ( ! is_array( $hand_cards ) ) {
			$hand_cards = array();
		}

		$uid = 'buffer_hand_' . wp_generate_uuid4();

		if ( function_exists( 'tw_enqueue_buffer_hand_assets' ) ) {
			tw_enqueue_buffer_hand_assets(
				array(
					'characterId' => (string) $character_id,
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					// BUG 22 fix — must match check_ajax_referer( 'use_card_nonce' ) in buffer.php.
					'nonce'       => wp_create_nonce( 'use_card_nonce' ),
					'selectors'   => array(
						'overlay' => '#card-zoom-overlay-' . $uid,
						'content' => '#zoom-content-' . $uid,
					),
				)
			);
		}

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $uid ); ?>"
			class="buffer-wrapper"
			data-buffer-hand-root="1"
			data-character-id="<?php echo esc_attr( (string) $character_id ); ?>"
		>
			<div class="buffer-hud">
				<div class="hud-stat pile-count" title="Pile">
					<span class="hud-label">PILE</span>
					<span id="count-pile-<?php echo esc_attr( $uid ); ?>" class="hud-value">--</span>
				</div>

				<div class="buffer-slider-container">
					<div class="swiper buffer-slider">
						<div class="swiper-wrapper" id="buffer-hand-slots-<?php echo esc_attr( $uid ); ?>">
							<?php if ( ! empty( $hand_cards ) ) : ?>
								<?php foreach ( $hand_cards as $card ) : ?>
									<?php
									$category    = isset( $card->category ) ? sanitize_html_class( strtolower( (string) $card->category ) ) : 'unknown';
									$instance_id = isset( $card->instance_id ) ? (string) $card->instance_id : '';
									$level       = isset( $card->level ) ? (int) $card->level : 0;
									$name        = isset( $card->name ) ? (string) $card->name : '[UNKNOWN CARD]';
									$description = isset( $card->description ) ? (string) $card->description : '';

									if ( '' === $instance_id ) {
										continue;
									}
									?>
									<div class="swiper-slide">
										<div
											class="cyber-card-css <?php echo esc_attr( $category ); ?>"
											data-action="zoom-card"
											data-instance-id="<?php echo esc_attr( $instance_id ); ?>"
										>
											<div class="card-glitch-overlay"></div>

											<div class="card-header">
												<span class="card-cat"><?php echo esc_html( strtoupper( $category ) ); ?></span>
												<span class="card-lvl">v.<?php echo esc_html( (string) $level ); ?></span>
											</div>

											<div class="card-content">
												<h3 class="card-title"><?php echo esc_html( $name ); ?></h3>
												<p class="card-desc"><?php echo esc_html( $description ); ?></p>
											</div>

											<div class="card-footer">
												<button
													class="inject-btn"
													type="button"
													data-action="use-buffer-card"
													data-instance-id="<?php echo esc_attr( $instance_id ); ?>"
													data-card-name="<?php echo esc_attr( $name ); ?>"
												>
													INJECT PROTOCOL
												</button>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<div class="swiper-slide">
									<div class="terminal-error">BUFFER EMPTY: No cards in hand.</div>
								</div>
							<?php endif; ?>
						</div>

						<div class="swiper-pagination"></div>
					</div>
				</div>

				<div class="hud-stat discard-count" title="Discard">
					<span class="hud-label">DISCARD</span>
					<span id="count-discard-<?php echo esc_attr( $uid ); ?>" class="hud-value">--</span>
				</div>
			</div>

			<div
				id="card-zoom-overlay-<?php echo esc_attr( $uid ); ?>"
				class="card-zoom-overlay"
				hidden
				data-action="close-zoom"
			>
				<div id="zoom-content-<?php echo esc_attr( $uid ); ?>" class="zoom-content"></div>
				<div class="zoom-hint">CLICK ANYWHERE TO CLOSE</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}

if ( ! function_exists( 'tw_register_cyber_buffer_hand_shortcode' ) ) {
	function tw_register_cyber_buffer_hand_shortcode(): void {
		add_shortcode( 'cyber_buffer_hand', 'cyber_buffer_hand_shortcode' );
	}
}

// Register on init so WP shortcode infrastructure is ready.
add_action( 'init', 'tw_register_cyber_buffer_hand_shortcode' );
