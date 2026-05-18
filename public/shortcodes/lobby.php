<?php
/**
 * NEOWEAVE LOBBY SHORTCODE + AJAX USER LABELS + AVATARS + ONLINE DOT + LAUNCH/READY + AUTO-JOIN
 *
 */

add_shortcode( 'neoweave_lobby', 'neoweave_lobby_terminal' );

function neoweave_lobby_terminal() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<div class="neoweave-terminal">ERROR: OPERATOR NOT IDENTIFIED. ACCESS DENIED.</div>';
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return '<div class="neoweave-terminal">ERROR: SUPABASE LINK OFFLINE. CHECK TW_SUPABASE_* IN WP-CONFIG.</div>';
	}
wp_enqueue_style(
				'nw-lobby',
				NEOWEAVER_PLUGIN_URL . 'assets/css/public/lobby.css',
				[],
				(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/public/lobby.css' )
			);

			wp_enqueue_script(
				'nw-lobby',
				NEOWEAVER_PLUGIN_URL . 'assets/js/public/lobby.js',
				[],
				(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/public/lobby.js' ),
				true
			);
	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	$raw_campaign_id = isset( $_GET['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
	}

	// [FIX-1] Corrected table name: cyber_campaign (no trailing s)
	$campaign_name    = 'UNKNOWN';
	$campaign_host_id = 0;
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=name,wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $camp_res ) ) {
		$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
		if ( is_array( $camp_data ) && ! empty( $camp_data[0] ) ) {
			$campaign_name    = $camp_data[0]['name']       ?? 'UNKNOWN';
			$campaign_host_id = intval( $camp_data[0]['wp_user_id'] ?? 0 );
		}
	}

	$ajax_url = admin_url( 'admin-ajax.php' );

	$nonce_launch    = wp_create_nonce( 'neoweave_launch' );
	$nonce_labels    = wp_create_nonce( 'neoweave_labels' );
	$nonce_heartbeat = wp_create_nonce( 'neoweave_heartbeat' );

	$user_map     = [];
	$current_user = wp_get_current_user();
	if ( $current_user && $current_user->ID ) {
		$user_map[ $current_user->ID ] = $current_user->display_name;
	}
	$user_map_json = esc_attr( wp_json_encode( $user_map ) );

	ob_start();
	?>
	<div class="neoweave-terminal" id="neoweave-lobby"
		 data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
		 data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
		 data-user-map="<?php echo $user_map_json; ?>"
		 data-current-user="<?php echo esc_attr( get_current_user_id() ); ?>"
		 data-host-id="<?php echo esc_attr( $campaign_host_id ); ?>"
		 data-nonce-launch="<?php echo esc_attr( $nonce_launch ); ?>"
		 data-nonce-labels="<?php echo esc_attr( $nonce_labels ); ?>"
		 data-nonce-heartbeat="<?php echo esc_attr( $nonce_heartbeat ); ?>">
		<div class="terminal-header">
			<div class="terminal-title">SQUAD DEPLOYMENT: ID_<?php echo esc_html( $campaign_id ); ?></div>
			<div class="terminal-status">
				SCANNING FOR AGENT SIGNALS...<span class="blink">_</span><br>
				> NODE: [<?php echo esc_html( $campaign_name ); ?>]<br>
				> PROTOCOL: NEURAL_LINK_4_WAY
			</div>
		</div>

		<div class="squad-grid">
			<div class="squad-slot" id="squad-slot-1"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-2"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-3"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-4"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
		</div>

		<!-- [FIX-4] Inline status message replaces alert() -->
		<div id="launch-status" class="info"></div>

		<div class="terminal-actions">
			<button type="button" class="terminal-button" id="neoweave-launch-button">LAUNCH DEPLOYMENT</button>
			<button type="button" class="terminal-button secondary" id="neoweave-leave-button">LEAVE LOBBY</button>
		</div>
	</div>

	<noscript>
		<div class="neoweave-terminal">ERROR: JAVASCRIPT REQUIRED. ENABLE SCRIPTING TO ACCESS LOBBY.</div>
	</noscript>
	<?php
	return ob_get_clean();
}

/**
 * AJAX: mapa wp_user_id -> display_name dla lobby
 *
 * SECURITY: requires a valid nonce (neoweave_labels).
 * nopriv removed — display names must not leak to unauthenticated callers.
 */
add_action( 'wp_ajax_neoweave_user_labels', 'neoweave_user_labels' );

function neoweave_user_labels() {
	check_ajax_referer( 'neoweave_labels', 'nonce' );

	if ( empty( $_POST['ids'] ) || ! is_array( $_POST['ids'] ) ) {
		wp_send_json_error( [ 'message' => 'NO_IDS' ] );
		return;
	}

	$ids = array_unique( array_filter( array_map( 'intval', $_POST['ids'] ) ) );

	if ( empty( $ids ) ) {
		wp_send_json_success( [ 'map' => [] ] );
		return;
	}

	$users = get_users( [
		'include' => $ids,
		'fields'  => [ 'ID', 'display_name' ],
	] );

	$map = [];
	foreach ( $users as $u ) {
		$map[ $u->ID ] = $u->display_name;
	}

	wp_send_json_success( [ 'map' => $map ] );
}

/**
 * AJAX: host LAUNCH — creates cyber_game_sessions from campaign_signups
 *
 * [FIX-1] All table references verified against live Supabase schema:
 *   cyber_campaign          ✅ (was: cyber_campaigns — fixed)
 *   cyber_campaign_worlds   ✅
 *   cyber_world_map         ✅
 *   cyber_campaign_signups  ✅
 *   cyber_game_sessions     ✅
 */
add_action( 'wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign' );

function neoweave_launch_campaign() {
	check_ajax_referer( 'neoweave_launch', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	$raw_campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return;
	}

	$current_user_id = get_current_user_id();

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	// 1) host check — [FIX-1] correct table name: cyber_campaign
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( is_wp_error( $camp_res ) ) {
		wp_send_json_error( [ 'message' => 'campaign_fetch_error' ] );
		return;
	}
	$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
	$host_id   = isset( $camp_data[0]['wp_user_id'] ) ? intval( $camp_data[0]['wp_user_id'] ) : 0;
	if ( $host_id !== $current_user_id ) {
		wp_send_json_error( [ 'message' => 'not_host' ] );
		return;
	}

	// 2) world_id from cyber_campaign_worlds
	$world_id  = null;
	$world_url = $supabase_rest . 'cyber_campaign_worlds?campaign_id=eq.' . $campaign_id . '&select=world_id';
	$world_res = wp_remote_get( $world_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $world_res ) ) {
		$world_data = json_decode( wp_remote_retrieve_body( $world_res ), true );
		if ( is_array( $world_data ) && ! empty( $world_data[0]['world_id'] ) ) {
			$world_id = sanitize_text_field( $world_data[0]['world_id'] );
		}
	}
	if ( ! $world_id ) {
		wp_send_json_error( [ 'message' => 'no_world_linked' ] );
		return;
	}

	// 3) start location (0,0) from cyber_world_map
	$location_id = null;
	$loc_url = $supabase_rest
		. 'cyber_world_map?world_id=eq.' . $world_id
		. '&coord_x=eq.0&coord_y=eq.0&select=id&limit=1';
	$loc_res = wp_remote_get( $loc_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $loc_res ) ) {
		$loc_data = json_decode( wp_remote_retrieve_body( $loc_res ), true );
		if ( is_array( $loc_data ) && ! empty( $loc_data[0]['id'] ) ) {
			$location_id = sanitize_text_field( (string) $loc_data[0]['id'] );
		}
	}
	if ( ! $location_id ) {
		wp_send_json_error( [ 'message' => 'no_start_location' ] );
		return;
	}

	// 4) signups from cyber_campaign_signups
	$signup_url = $supabase_rest . 'cyber_campaign_signups?campaign_id=eq.' . $campaign_id
		. '&select=wp_user_id,character_id';
	$signup_res = wp_remote_get( $signup_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( is_wp_error( $signup_res ) ) {
		wp_send_json_error( [ 'message' => 'signup_fetch_error' ] );
		return;
	}
	$signups = json_decode( wp_remote_retrieve_body( $signup_res ), true );
	if ( ! is_array( $signups ) || ! count( $signups ) ) {
		wp_send_json_error( [ 'message' => 'no_signups' ] );
		return;
	}

	// 5) pause existing active sessions for these users
	$user_ids = array_filter( array_unique( array_map(
		static function ( $s ) { return intval( $s['wp_user_id'] ); },
		$signups
	) ) );

	if ( ! empty( $user_ids ) ) {
		$ids_list    = implode( ',', $user_ids );
		$cleanup_url = $supabase_rest . 'cyber_game_sessions'
			. '?wp_user_id=in.(' . $ids_list . ')&status=eq.active';
		wp_remote_request( $cleanup_url, [
			'method'  => 'PATCH',
			'headers' => [
				'apikey'        => $supabase_key,
				'Authorization' => 'Bearer ' . $supabase_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'status' => 'paused' ] ),
		] );
	}

	// 6) insert new active sessions into cyber_game_sessions
	$sessions_payload = [];
	foreach ( $signups as $s ) {
		$sessions_payload[] = [
			'campaign_id'  => $campaign_id,
			'wp_user_id'   => intval( $s['wp_user_id'] ),
			'character_id' => sanitize_text_field( (string) $s['character_id'] ),
			'world_id'     => $world_id,
			'location_id'  => $location_id,
			'status'       => 'active',
		];
	}

	$session_url = $supabase_rest . 'cyber_game_sessions';
	$session_res = wp_remote_post( $session_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $sessions_payload ),
	] );

	if ( is_wp_error( $session_res ) || wp_remote_retrieve_response_code( $session_res ) >= 300 ) {
		wp_send_json_error( [ 'message' => 'session_insert_error' ] );
		return;
	}

	wp_send_json_success( [ 'message' => 'launched' ] );
}
