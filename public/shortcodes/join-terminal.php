<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'neoweave_join_terminal_shortcode' ) ) {
	function neoweave_join_terminal_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweave-terminal">ERROR: OPERATOR NOT LOGGED IN. ACCESS DENIED.</div>';
		}

		$user_id = get_current_user_id();

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			return '<div class="neoweave-terminal">ERROR: SUPABASE CONFIG MISSING.</div>';
		}

		$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key = tw_supabase_service_key();

		$chars_url = add_query_arg(
			array(
				'wp_user_id' => 'eq.' . $user_id,
				'status'     => 'neq.STATUS_DEAD',
				'select'     => 'id,name',
				'order'      => 'created_at.asc',
			),
			$supabase_rest . 'cyber_characters'
		);

		$chars_resp = wp_remote_get(
			$chars_url,
			array(
				'headers' => array(
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				),
				'timeout' => 10,
			)
		);

		$available_chars = array();

		if ( ! is_wp_error( $chars_resp ) && 200 === (int) wp_remote_retrieve_response_code( $chars_resp ) ) {
			$available_chars = json_decode( wp_remote_retrieve_body( $chars_resp ), true ) ?: array();
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'tw_join_nonce' );

		if ( function_exists( 'tw_enqueue_join_terminal_assets' ) ) {
			tw_enqueue_join_terminal_assets(
				array(
					'ajaxUrl' => $ajax_url,
					'nonce'   => $nonce,
					'action'  => 'tw_join_campaign',
				)
			);
		}

		ob_start();
		?>
		<div class="neoweave-terminal" id="neoweave-join-terminal"
			data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="terminal-header">
				<div class="terminal-title">NEURAL LINK JOIN TERMINAL</div>
				<div class="terminal-status">
					AWAITING DEPLOYMENT ACCESS CODE<span class="blink">_</span><br>
					&gt; PROTOCOL: SECURE_SQUAD_HANDSHAKE
				</div>
			</div>

			<div class="terminal-input">
				<label for="neoweave-join-code">ENTER DEPLOYMENT CODE:</label>
				<input type="text" id="neoweave-join-code" maxlength="16" autocomplete="off" placeholder="TYPE CODE HERE">
			</div>

			<div class="terminal-input">
				<label for="neoweave-join-character">SELECT FIELD AGENT:</label>
				<select id="neoweave-join-character">
					<?php if ( empty( $available_chars ) ) : ?>
						<option value="">-- NO LIVING AGENTS FOUND --</option>
					<?php else : ?>
						<option value="">-- SELECT AGENT --</option>
						<?php foreach ( $available_chars as $ch ) : ?>
							<option value="<?php echo esc_attr( (string) ( $ch['id'] ?? '' ) ); ?>">
								<?php echo esc_html( (string) ( $ch['name'] ?? 'Unnamed Agent' ) ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<button type="button" class="terminal-button" id="neoweave-join-button">
				INITIATE LINK
			</button>

			<div class="terminal-message" id="neoweave-join-message"></div>
		</div>
		<?php

		return ob_get_clean();
	}

	add_shortcode( 'neoweave_join_terminal', 'neoweave_join_terminal_shortcode' );
}
