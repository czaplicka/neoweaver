<?php
// ============================================================
// AJAX HANDLER: tw_join_campaign
// Bug-Fix 3: signup moved server-side with nonce + ownership check.
// ============================================================
add_action( 'wp_ajax_tw_join_campaign', 'tw_ajax_join_campaign' );

function tw_ajax_join_campaign() {
	check_ajax_referer( 'tw_join_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ) );
		return;
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( array( 'message' => 'supabase_config_missing' ) );
		return;
	}

	$join_code    = isset( $_POST['join_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['join_code'] ) ) ) : '';
	$character_id = isset( $_POST['character_id'] ) ? sanitize_text_field( wp_unslash( $_POST['character_id'] ) ) : '';

	if ( ! $join_code ) {
		wp_send_json_error( array( 'message' => 'missing_join_code' ) );
		return;
	}

	if ( ! $character_id ) {
		wp_send_json_error( array( 'message' => 'missing_character_id' ) );
		return;
	}

	$base    = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon    = tw_supabase_anon_key();
	$headers = array(
		'apikey'        => $anon,
		'Authorization' => 'Bearer ' . $anon,
	);

	$safe_char_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );

	$char_url = add_query_arg(
		array(
			'id'         => 'eq.' . $safe_char_id,
			'wp_user_id' => 'eq.' . $user_id,
			'status'     => 'neq.STATUS_DEAD',
			'select'     => 'id',
			'limit'      => 1,
		),
		$base . 'cyber_characters'
	);

	$camp_url = add_query_arg(
		array(
			'join_code' => 'eq.' . $join_code,
			'select'    => 'id',
			'limit'     => 1,
		),
		$base . 'cyber_campaign'
	);

	$char_resp = wp_remote_get( $char_url, array( 'headers' => $headers, 'timeout' => 10 ) );
	$camp_resp = wp_remote_get( $camp_url, array( 'headers' => $headers, 'timeout' => 10 ) );

	if ( is_wp_error( $char_resp ) || 200 !== wp_remote_retrieve_response_code( $char_resp ) ) {
		wp_send_json_error( array( 'message' => 'character_lookup_failed' ) );
		return;
	}

	$char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true );
	if ( empty( $char_rows ) ) {
		wp_send_json_error( array( 'message' => 'character_not_owned_or_dead' ) );
		return;
	}

	$character_id = $safe_char_id;

	if ( is_wp_error( $camp_resp ) || 200 !== wp_remote_retrieve_response_code( $camp_resp ) ) {
		wp_send_json_error( array( 'message' => 'campaign_lookup_failed' ) );
		return;
	}

	$camp_rows = json_decode( wp_remote_retrieve_body( $camp_resp ), true );
	if ( empty( $camp_rows ) ) {
		wp_send_json_error( array( 'message' => 'no_campaign_for_code' ) );
		return;
	}

	$campaign_id = $camp_rows[0]['id'];

	$existing_url = add_query_arg(
		array(
			'campaign_id' => 'eq.' . $campaign_id,
			'wp_user_id'  => 'eq.' . $user_id,
			'select'      => 'id',
			'limit'       => 1,
		),
		$base . 'cyber_campaign_signups'
	);

	$existing_resp = wp_remote_get( $existing_url, array( 'headers' => $headers, 'timeout' => 10 ) );
	if ( ! is_wp_error( $existing_resp ) && 200 === wp_remote_retrieve_response_code( $existing_resp ) ) {
		$existing = json_decode( wp_remote_retrieve_body( $existing_resp ), true );
		if ( ! empty( $existing ) ) {
			wp_send_json_success( array( 'campaign_id' => $campaign_id, 'status' => 'already_joined' ) );
			return;
		}
	}

	$insert_resp = wp_remote_post(
		$base . 'cyber_campaign_signups',
		array(
			'headers' => array_merge(
				$headers,
				array(
					'Content-Type' => 'application/json',
					'Prefer'       => 'return=minimal',
				)
			),
			'body'    => wp_json_encode(
				array(
					'campaign_id'  => $campaign_id,
					'wp_user_id'   => $user_id,
					'character_id' => $character_id,
				)
			),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $insert_resp ) ) {
		wp_send_json_error( array( 'message' => 'signup_insert_failed' ) );
		return;
	}

	$insert_code = wp_remote_retrieve_response_code( $insert_resp );
	if ( $insert_code < 200 || $insert_code >= 300 ) {
		wp_send_json_error( array( 'message' => 'signup_insert_failed', 'http' => $insert_code ) );
		return;
	}

	wp_send_json_success( array( 'campaign_id' => $campaign_id, 'status' => 'joined' ) );
}

add_shortcode( 'neoweave_join_terminal', 'neoweave_join_terminal_shortcode' );

function neoweave_join_terminal_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<div class="neoweave-terminal">ERROR: OPERATOR NOT LOGGED IN. ACCESS DENIED.</div>';
	}

	$user_id = get_current_user_id();

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return '<div class="neoweave-terminal">ERROR: SUPABASE CONFIG MISSING.</div>';
	}

	$css_rel  = 'assets/css/public/join-terminal.css';
	$css_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $css_rel;
	$css_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $css_rel;
	$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0';

	$js_rel  = 'assets/js/public/join-terminal.js';
	$js_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $js_rel;
	$js_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $js_rel;
	$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0';

	wp_enqueue_style(
		'neoweave-join-fonts',
		'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'neoweave-join-terminal',
		$css_url,
		array( 'neoweave-join-fonts' ),
		$css_ver
	);

	wp_enqueue_script(
		'neoweave-join-terminal',
		$js_url,
		array(),
		$js_ver,
		true
	);

	$join_nonce = wp_create_nonce( 'tw_join_nonce' );
	$ajax_url   = admin_url( 'admin-ajax.php' );

	wp_localize_script(
		'neoweave-join-terminal',
		'neoWeaveJoinTerminal',
		array(
			'ajaxUrl' => $ajax_url,
			'nonce'   => $join_nonce,
		)
	);

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon_key      = tw_supabase_anon_key();

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
	if ( ! is_wp_error( $chars_resp ) && 200 === wp_remote_retrieve_response_code( $chars_resp ) ) {
		$available_chars = json_decode( wp_remote_retrieve_body( $chars_resp ), true ) ?: array();
	}

	ob_start();
	?>
	<div class="neoweave-terminal" id="neoweave-join-terminal">
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
						<option value="<?php echo esc_attr( $ch['id'] ); ?>">
							<?php echo esc_html( $ch['name'] ); ?>
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
?>
