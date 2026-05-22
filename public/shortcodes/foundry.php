<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'cyber_foundry_shortcode' ) ) {
	function cyber_foundry_shortcode(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<div class="foundry-container">ERROR: UPLINK REQUIRED. PLEASE LOG IN.</div>';
		}

		if ( ! function_exists( 'get_cyber_character_id_by_wp_id' ) ) {
			return '<div class="foundry-container">ERROR: CHARACTER LINK HELPER NOT AVAILABLE.</div>';
		}

		if ( ! function_exists( 'fetch_foundry_data' ) ) {
			return '<div class="foundry-container">ERROR: FOUNDRY DATASTREAM OFFLINE.</div>';
		}

		if ( ! function_exists( 'get_cyber_player_credits' ) ) {
			return '<div class="foundry-container">ERROR: CREDIT HELPER NOT AVAILABLE.</div>';
		}

		$character_id = get_cyber_character_id_by_wp_id( $user_id );

		if ( empty( $character_id ) || ! is_scalar( $character_id ) ) {
			return '<div class="foundry-container">ERROR: NO FIELD AGENT DETECTED.</div>';
		}

		$library_cards = fetch_foundry_data( $character_id );

		if ( is_wp_error( $library_cards ) ) {
			return '<div class="foundry-container">ERROR: DATASTREAM CORRUPTED. PLEASE RETRY LATER.</div>';
		}

		if ( ! is_array( $library_cards ) ) {
			$library_cards = array();
		}

		$current_player_credits = get_cyber_player_credits( $character_id );

		if ( ! is_numeric( $current_player_credits ) ) {
			$current_player_credits = 0;
		}

		$current_player_credits = (int) $current_player_credits;
		$nonce                  = wp_create_nonce( 'cyber_foundry_upgrade' );
		$uid                    = 'foundry_' . wp_generate_uuid4();

		if ( function_exists( 'tw_enqueue_foundry_assets' ) ) {
			tw_enqueue_foundry_assets(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => $nonce,
				)
			);
		}

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $uid ); ?>"
			class="foundry-container"
			data-foundry-root="1"
			data-character-id="<?php echo esc_attr( (string) $character_id ); ?>"
		>
			<h2 class="foundry-title"><span class="blink">_</span> NANITE FOUNDRY</h2>

			<div class="foundry-grid">
				<?php if ( ! empty( $library_cards ) ) : ?>
					<?php foreach ( $library_cards as $card ) : ?>
						<?php
						$card_level       = isset( $card->level ) ? (int) $card->level : 0;
						$card_level       = max( 1, $card_level );

						$card_name        = isset( $card->name ) ? (string) $card->name : '[UNKNOWN CARD]';
						$card_duplicates  = isset( $card->duplicate_count ) ? (int) $card->duplicate_count : 0;
						$card_duplicates  = max( 0, $card_duplicates );

						$card_instance_id = isset( $card->instance_id ) ? (string) $card->instance_id : '';

						if ( '' === $card_instance_id ) {
							continue;
						}

						$needed_duplicates = max( 1, $card_level * 2 );
						$needed_credits    = max( 0, $card_level * 100 );

						$has_duplicates = $card_duplicates >= $needed_duplicates;
						$has_credits    = $current_player_credits >= $needed_credits;
						$can_upgrade    = $has_duplicates && $has_credits;

						$progress_raw   = ( $card_duplicates / $needed_duplicates ) * 100;
						$progress_width = max( 0, min( 100, $progress_raw ) );

						if ( ! $has_duplicates ) {
							$button_label = 'NEED MORE DATA';
						} elseif ( ! $has_credits ) {
							$button_label = 'INSUFFICIENT CC';
						} else {
							$button_label = 'START FUSION';
						}
						?>
						<div class="foundry-item <?php echo $can_upgrade ? 'ready' : ''; ?>">
							<div class="card-preview">
								<span class="lvl-badge">v.<?php echo esc_html( (string) $card_level ); ?></span>
								<div class="card-name"><?php echo esc_html( $card_name ); ?></div>
							</div>

							<div class="upgrade-info">
								<div class="progress-bar">
									<div
										class="progress-fill"
										style="width: <?php echo esc_attr( (string) $progress_width ); ?>%;"
									></div>
								</div>

								<span class="count-text">
									DATA NODES: <?php echo esc_html( (string) $card_duplicates ); ?> / <?php echo esc_html( (string) $needed_duplicates ); ?>
								</span>

								<div class="credit-cost <?php echo $has_credits ? '' : 'insufficient'; ?>">
									COST: <?php echo esc_html( (string) $needed_credits ); ?> CC
								</div>
							</div>

							<button
								class="upgrade-btn"
								type="button"
								data-action="upgrade-card"
								data-card-instance-id="<?php echo esc_attr( $card_instance_id ); ?>"
								data-card-level="<?php echo esc_attr( (string) $card_level ); ?>"
								data-needed-duplicates="<?php echo esc_attr( (string) $needed_duplicates ); ?>"
								data-needed-credits="<?php echo esc_attr( (string) $needed_credits ); ?>"
								<?php disabled( ! $can_upgrade ); ?>
							>
								<?php echo esc_html( $button_label ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="buffer-empty">NO DATA NODES DETECTED IN ARCHIVE.</p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	add_shortcode( 'cyber_foundry', 'cyber_foundry_shortcode' );
}
