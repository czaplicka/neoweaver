<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ---------------------------------------------------------------------------
// Helper: verify the current WP user is a participant (signup) or the host
// of the given campaign. Returns true/false.
// Used by the shortcode render (BUG 25) and can be reused by other handlers.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'neoweave_current_user_can_view_lobby' ) ) {
	function neoweave_current_user_can_view_lobby( string $campaign_id, int $user_id ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return false;
		}

		$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key      = tw_supabase_anon_key();
		$headers       = array(
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		);

		// Check host.
		$host_url = add_query_arg(
			array(
				'id'         => 'eq.' . $campaign_id,
				'wp_user_id' => 'eq.' . $user_id,
				'select'     => 'id',
				'limit'      => 1,
			),
			$supabase_rest . 'cyber_campaign'
		);
		$host_res = wp_remote_get( $host_url, array( 'headers' => $headers, 'timeout' => 10 ) );
		if ( ! is_wp_error( $host_res ) && 200 === (int) wp_remote_retrieve_response_code( $host_res ) ) {
			$host_data = json_decode( wp_remote_retrieve_body( $host_res ), true );
			if ( is_array( $host_data ) && ! empty( $host_data ) ) {
				return true;
			}
		}

		// Check signup.
		$signup_url = add_query_arg(
			array(
				'campaign_id' => 'eq.' . $campaign_id,
				'wp_user_id'  => 'eq.' . $user_id,
				'select'      => 'wp_user_id',
				'limit'       => 1,
			),
			$supabase_rest . 'cyber_campaign_signups'
		);
		$signup_res = wp_remote_get( $signup_url, array( 'headers' => $headers, 'timeout' => 10 ) );
		if ( ! is_wp_error( $signup_res ) && 200 === (int) wp_remote_retrieve_response_code( $signup_res ) ) {
			$signup_data = json_decode( wp_remote_retrieve_body( $signup_res ), true );
			if ( is_array( $signup_data ) && ! empty( $signup_data ) ) {
				return true;
			}
		}

		return false;
	}
}

// ---------------------------------------------------------------------------
// Shortcode: [neoweave_lobby]
// ---------------------------------------------------------------------------
if ( ! function_exists( 'neoweave_lobby_terminal' ) ) {
	function neoweave_lobby_terminal(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<div class="neoweave-terminal">ERROR: OPERATOR NOT IDENTIFIED. ACCESS DENIED.</div>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<div class="neoweave-terminal">ERROR: SUPABASE LINK OFFLINE. CHECK TW_SUPABASE_* IN WP-CONFIG.</div>';
		}

		$raw_campaign_id = isset( $_GET['campaign_id'] ) && is_scalar( $_GET['campaign_id'] )
			? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) )
			: '';

		$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $raw_campaign_id );

		if ( empty( $campaign_id ) ) {
			return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
		}

		// BUG 25 fix — verify the requesting user is the host or a signup
		// before rendering any campaign data (name, host ID, etc.).
		if ( ! neoweave_current_user_can_view_lobby( $campaign_id, $user_id ) ) {
			return '<div class="neoweave-terminal">ERROR: ACCESS DENIED. YOU ARE NOT A PARTICIPANT OF THIS DEPLOYMENT.</div>';
		}

		$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$supabase_key  = tw_supabase_anon_key();

		$campaign_name    = 'UNKNOWN';
		$campaign_host_id = 0;

		$camp_url = add_query_arg(
			array(
				'id'     => 'eq.' . $campaign_id,
				'select' => 'name,wp_user_id',
			),
			$supabase_rest . 'cyber_campaign'
		);

		$camp_res = wp_remote_get(
			$camp_url,
			array(
				'headers' => array(
					'apikey'        => $supabase_key,
					'Authorization' => 'Bearer ' . $supabase_key,
				),
				'timeout' => 15,
			)
		);

		if ( ! is_wp_error( $camp_res ) && 200 === (int) wp_remote_retrieve_response_code( $camp_res ) ) {
			$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );

			if ( is_array( $camp_data ) && ! empty( $camp_data[0] ) ) {
				$campaign_name    = isset( $camp_data[0]['name'] ) ? (string) $camp_data[0]['name'] : 'UNKNOWN';
				$campaign_host_id = isset( $camp_data[0]['wp_user_id'] ) ? (int) $camp_data[0]['wp_user_id'] : 0;
			}
		}

		$ajax_url         = admin_url( 'admin-ajax.php' );
		$nonce_launch     = wp_create_nonce( 'neoweave_launch' );
		$nonce_labels     = wp_create_nonce( 'neoweave_labels' );
		$nonce_heartbeat  = wp_create_nonce( 'neoweave_heartbeat' );
		$current_user     = wp_get_current_user();
		$user_map         = array();

		if ( $current_user instanceof WP_User && $current_user->ID ) {
			$user_map[ $current_user->ID ] = $current_user->display_name;
		}

		if ( function_exists( 'tw_enqueue_lobby_assets' ) ) {
			tw_enqueue_lobby_assets(
				array(
					'campaignId'     => $campaign_id,
					'ajaxUrl'        => $ajax_url,
					'currentUser'    => $user_id,
					'hostId'         => $campaign_host_id,
					'nonceLaunch'    => $nonce_launch,
					'nonceLabels'    => $nonce_labels,
					'nonceHeartbeat' => $nonce_heartbeat,
					'userMap'        => $user_map,
				)
			);
		}

		ob_start();
		?>
		<div
			class="neoweave-terminal"
			id="neoweave-lobby"
			data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
		>
			<div class="terminal-header">
				<div class="terminal-title">SQUAD DEPLOYMENT: ID_<?php echo esc_html( $campaign_id ); ?></div>
				<div class="terminal-status">
					SCANNING FOR AGENT SIGNALS...<span class="blink">_</span><br>
					&gt; NODE: [<?php echo esc_html( $campaign_name ); ?>]<br>
					&gt; PROTOCOL: NEURAL_LINK_4_WAY
				</div>
			</div>

			<div class="squad-grid">
				<div class="squad-slot" id="squad-slot-1"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
				<div class="squad-slot" id="squad-slot-2"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
				<div class="squad-slot" id="squad-slot-3"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
				<div class="squad-slot" id="squad-slot-4"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			</div>

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

	add_shortcode( 'neoweave_lobby', 'neoweave_lobby_terminal' );
}

// ---------------------------------------------------------------------------
// AJAX: resolve WP user display names from a list of IDs
// ---------------------------------------------------------------------------
if ( ! function_exists( 'neoweave_user_labels' ) ) {
	function neoweave_user_labels(): void {
		check_ajax_referer( 'neoweave_labels', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'not_logged_in' ), 401 );
			return;
		}

		$ids_raw = $_POST['ids'] ?? null;

		if ( ! is_array( $ids_raw ) ) {
			wp_send_json_error( array( 'message' => 'NO_IDS' ), 400 );
			return;
		}

		// BUG 24 fix — reject nested arrays; ids must be a flat list of scalars.
		// intval() on a nested array silently returns 0, which wp_unslash() does
		// not catch. We strip any non-scalar element before casting.
		$flat = array_filter(
			$ids_raw,
			static function ( $v ) {
				return is_scalar( $v );
			}
		);

		$ids = array_unique(
			array_filter(
				array_map( 'intval', wp_unslash( $flat ) )
			)
		);

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'map' => array() ) );
			return;
		}

		$users = get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		$map = array();

		foreach ( $users as $u ) {
			$map[ $u->ID ] = $u->display_name;
		}

		wp_send_json_success( array( 'map' => $map ) );
	}

	add_action( 'wp_ajax_neoweave_user_labels', 'neoweave_user_labels' );
}

// ---------------------------------------------------------------------------
// AJAX: launch campaign — create game sessions for all signups
// ---------------------------------------------------------------------------
if ( ! function_exists( 'neoweave_launch_campaign' ) ) {
	function neoweave_launch_campaign(): void {
		check_ajax_referer( 'neoweave_launch', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'not_logged_in' ), 401 );
			return;
		}

		$raw_campaign_id = isset( $_POST['campaign_id'] ) && is_scalar( $_POST['campaign_id'] )
			? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) )
			: '';

		$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $raw_campaign_id );

		if ( empty( $campaign_id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_campaign' ), 400 );
			return;
		}

		$current_user_id = get_current_user_id();

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			wp_send_json_error( array( 'message' => 'supabase_config_missing' ), 500 );
			return;
		}

		$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$supabase_anon = tw_supabase_anon_key();
		$supabase_svc  = tw_supabase_service_key();

		$read_headers = array(
			'apikey'        => $supabase_anon,
			'Authorization' => 'Bearer ' . $supabase_anon,
		);

		$write_headers = array(
			'apikey'        => $supabase_svc,
			'Authorization' => 'Bearer ' . $supabase_svc,
			'Content-Type'  => 'application/json',
		);

		// Verify host.
		$camp_url = add_query_arg(
			array(
				'id'     => 'eq.' . $campaign_id,
				'select' => 'wp_user_id',
			),
			$supabase_rest . 'cyber_campaign'
		);

		$camp_res = wp_remote_get(
			$camp_url,
			array(
				'headers' => $read_headers,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $camp_res ) || 200 !== (int) wp_remote_retrieve_response_code( $camp_res ) ) {
			wp_send_json_error( array( 'message' => 'campaign_fetch_error' ), 500 );
			return;
		}

		$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
		$host_id   = isset( $camp_data[0]['wp_user_id'] ) ? (int) $camp_data[0]['wp_user_id'] : 0;

		if ( $host_id !== $current_user_id ) {
			wp_send_json_error( array( 'message' => 'not_host' ), 403 );
			return;
		}

		// Fetch world.
		$world_id  = null;
		$world_url = add_query_arg(
			array(
				'campaign_id' => 'eq.' . $campaign_id,
				'select'      => 'world_id',
			),
			$supabase_rest . 'cyber_campaign_worlds'
		);

		$world_res = wp_remote_get(
			$world_url,
			array(
				'headers' => $read_headers,
				'timeout' => 15,
			)
		);

		if ( ! is_wp_error( $world_res ) && 200 === (int) wp_remote_retrieve_response_code( $world_res ) ) {
			$world_data = json_decode( wp_remote_retrieve_body( $world_res ), true );

			if ( is_array( $world_data ) && ! empty( $world_data[0]['world_id'] ) ) {
				$world_id = sanitize_text_field( (string) $world_data[0]['world_id'] );
			}
		}

		if ( ! $world_id ) {
			wp_send_json_error( array( 'message' => 'no_world_linked' ), 400 );
			return;
		}

		// Fetch start location.
		$location_id = null;
		$loc_url     = add_query_arg(
			array(
				'world_id' => 'eq.' . $world_id,
				'coord_x'  => 'eq.0',
				'coord_y'  => 'eq.0',
				'select'   => 'id',
				'limit'    => 1,
			),
			$supabase_rest . 'cyber_world_map'
		);

		$loc_res = wp_remote_get(
			$loc_url,
			array(
				'headers' => $read_headers,
				'timeout' => 15,
			)
		);

		if ( ! is_wp_error( $loc_res ) && 200 === (int) wp_remote_retrieve_response_code( $loc_res ) ) {
			$loc_data = json_decode( wp_remote_retrieve_body( $loc_res ), true );

			if ( is_array( $loc_data ) && ! empty( $loc_data[0]['id'] ) ) {
				$location_id = sanitize_text_field( (string) $loc_data[0]['id'] );
			}
		}

		if ( ! $location_id ) {
			wp_send_json_error( array( 'message' => 'no_start_location' ), 400 );
			return;
		}

		// Fetch signups.
		$signup_url = add_query_arg(
			array(
				'campaign_id' => 'eq.' . $campaign_id,
				'select'      => 'wp_user_id,character_id',
			),
			$supabase_rest . 'cyber_campaign_signups'
		);

		$signup_res = wp_remote_get(
			$signup_url,
			array(
				'headers' => $read_headers,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $signup_res ) || 200 !== (int) wp_remote_retrieve_response_code( $signup_res ) ) {
			wp_send_json_error( array( 'message' => 'signup_fetch_error' ), 500 );
			return;
		}

		$signups = json_decode( wp_remote_retrieve_body( $signup_res ), true );

		if ( ! is_array( $signups ) || empty( $signups ) ) {
			wp_send_json_error( array( 'message' => 'no_signups' ), 400 );
			return;
		}

		$user_ids = array_filter(
			array_unique(
				array_map(
					static function ( $s ) {
						return isset( $s['wp_user_id'] ) ? (int) $s['wp_user_id'] : 0;
					},
					$signups
				)
			)
		);

		// BUG 23 fix (part 1) — cleanup filter scoped to this campaign only.
		// Previously filtered only on wp_user_id IN (...), which paused active
		// sessions from ALL campaigns for those users. Now requires campaign_id
		// to match so other campaigns are untouched.
		if ( ! empty( $user_ids ) ) {
			$cleanup_url = add_query_arg(
				array(
					'campaign_id' => 'eq.' . $campaign_id,
					'wp_user_id'  => 'in.(' . implode( ',', $user_ids ) . ')',
					'status'      => 'eq.active',
				),
				$supabase_rest . 'cyber_game_sessions'
			);

			wp_remote_request(
				$cleanup_url,
				array(
					'method'  => 'PATCH',
					'headers' => $write_headers,
					'body'    => wp_json_encode( array( 'status' => 'paused' ) ),
					'timeout' => 15,
				)
			);
		}

		// Build sessions payload.
		$sessions_payload = array();

		foreach ( $signups as $s ) {
			$wp_user_id   = isset( $s['wp_user_id'] ) ? (int) $s['wp_user_id'] : 0;
			$character_id = isset( $s['character_id'] ) ? sanitize_text_field( (string) $s['character_id'] ) : '';

			if ( ! $wp_user_id || '' === $character_id ) {
				continue;
			}

			$sessions_payload[] = array(
				'campaign_id'  => $campaign_id,
				'wp_user_id'   => $wp_user_id,
				'character_id' => $character_id,
				'world_id'     => $world_id,
				'location_id'  => $location_id,
				'status'       => 'active',
			);
		}

		if ( empty( $sessions_payload ) ) {
			wp_send_json_error( array( 'message' => 'no_valid_sessions' ), 400 );
			return;
		}

		// BUG 23 fix (part 2) — ON CONFLICT DO NOTHING via PostgREST resolution header.
		// Requires a UNIQUE constraint on (campaign_id, wp_user_id) in
		// cyber_game_sessions. If the host double-clicks, the second insert is a
		// no-op instead of creating duplicate active sessions.
		$session_url = $supabase_rest . 'cyber_game_sessions';
		$session_res = wp_remote_post(
			$session_url,
			array(
				'headers' => array_merge(
					$write_headers,
					array(
						// resolution=ignore-duplicates maps to ON CONFLICT DO NOTHING.
						// Requires: UNIQUE (campaign_id, wp_user_id) on cyber_game_sessions.
						'Prefer' => 'return=minimal,resolution=ignore-duplicates',
					)
				),
				'body'    => wp_json_encode( $sessions_payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $session_res ) || (int) wp_remote_retrieve_response_code( $session_res ) >= 300 ) {
			wp_send_json_error( array( 'message' => 'session_insert_error' ), 500 );
			return;
		}

		wp_send_json_success( array( 'message' => 'launched' ) );
	}

	add_action( 'wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign' );
}
