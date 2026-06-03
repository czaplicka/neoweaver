<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER – AJAX: get_char_state
 *
 * Two actions:
 *
 * 1. tw_get_char_state_active
 *    For the GAME HUD — fetches full state from cyber_state_of_the_campaign
 *    for the character that is currently in an ACTIVE (not paused) session.
 *    Fails fast if there is no active session.
 *
 * 2. tw_get_char_state_profile
 *    For CHARACTER SELECT lists — fetches base stats from cyber_characters.
 *    Does NOT require an active game session.
 */

// Guard: bail early if supabase-helpers.php was not loaded yet.
// Prevents fatal call-to-undefined-function instead of a silent 500.
// Pattern mirrors ascension.php.
foreach ( [ 'nw_sanitize_uuid', 'tw_supabase_get', 'tw_supabase_get_admin' ] as $_nw_fn ) {
	if ( ! function_exists( $_nw_fn ) ) {
		error_log(
			'NeoWeaver get-char-state.php: required helper ' . $_nw_fn
			. '() not found — skipping handler registration. Check include order.'
		);
		return;
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. ACTIVE GAME HUD — full state from cyber_state_of_the_campaign
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_tw_get_char_state_active', 'tw_get_char_state_active_handler' );

if ( ! function_exists( 'tw_get_char_state_active_handler' ) ) {
	function tw_get_char_state_active_handler(): void {
		// BUG 6 FIX: nonce check MUST come before any configuration guard.
		// The previous order let unauthenticated requests probe for tw_supabase_get
		// by comparing 500 vs 403 response codes, fingerprinting plugin state.
		check_ajax_referer( 'tw_adventure_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in', 401 );
			return;
		}

		// Use service key (tw_supabase_get_admin) to bypass RLS.
		// tw_supabase_get (anon key) is subject to RLS; a misconfigured policy
		// silently returns empty rows with no error, giving no diagnostic path.
		// wp_user_id filter here acts as the ownership guard instead of RLS.
		$sessions = tw_supabase_get_admin(
			'cyber_game_sessions',
			[
				'wp_user_id' => 'eq.' . $user_id,
				'status'     => 'eq.active',
				'select'     => 'character_id,campaign_id',
				'order'      => 'updated_at.desc',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $sessions ) || empty( $sessions[0] ) ) {
			wp_send_json_error( 'No active session', 404 );
			return;
		}

		$character_id = isset( $sessions[0]['character_id'] )
			? nw_sanitize_uuid( (string) $sessions[0]['character_id'] )
			: '';
		$campaign_id  = isset( $sessions[0]['campaign_id'] )
			? nw_sanitize_uuid( (string) $sessions[0]['campaign_id'] )
			: '';

		if ( empty( $character_id ) || empty( $campaign_id ) ) {
			wp_send_json_error( 'Session missing character or campaign', 422 );
			return;
		}

		// BUG 7 FIX: fetch all HUD fields, not just hp,mp.
		// Added: satiety, hydration, sync_rate, xp, current_location_id
		// per the cyber_state_of_the_campaign protocol spec.
		$data = tw_supabase_get(
			'cyber_state_of_the_campaign',
			[
				'character_id' => 'eq.' . $character_id,
				'campaign_id'  => 'eq.' . $campaign_id,
				'select'       => 'hp,mp,satiety,hydration,sync_rate,xp,current_location_id',
				'order'        => 'created_at.desc',
				'limit'        => 1,
			]
		);

		if ( is_wp_error( $data ) || empty( $data[0] ) ) {
			wp_send_json_error( 'No state found', 404 );
			return;
		}

		wp_send_json_success( $data[0] );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 2. CHARACTER SELECT / PROFILE — base data from cyber_characters
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_tw_get_char_state_profile', 'tw_get_char_state_profile_handler' );

if ( ! function_exists( 'tw_get_char_state_profile_handler' ) ) {
	function tw_get_char_state_profile_handler(): void {
		check_ajax_referer( 'tw_adventure_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in', 401 );
			return;
		}

		// Optional: filter by specific character_id passed from JS.
		$character_id = isset( $_POST['character_id'] )
			? nw_sanitize_uuid( (string) $_POST['character_id'] )
			: '';

		$params = [
			'wp_user_id' => 'eq.' . $user_id,
			// Exclude dead agents from character select, consistent with
			// join-terminal.php, campaign-creator.js and class-agents-repository.php.
			'status'     => 'neq.STATUS_DEAD',
			'select'     => 'id,name,avatar_url,class,level,hp,mp,world_id',
			'order'      => 'name.asc',
		];

		if ( ! empty( $character_id ) ) {
			$params['id'] = 'eq.' . $character_id;
			$params['limit'] = 1;
		}

		// Optional: filter by world_id — verify it belongs to the current user
		// to prevent probing other users' worlds via this endpoint.
		$world_id = isset( $_POST['world_id'] )
			? nw_sanitize_uuid( (string) $_POST['world_id'] )
			: '';

		if ( ! empty( $world_id ) ) {
			$world_check = tw_supabase_get(
				'cyber_worlds',
				[
					'id'         => 'eq.' . $world_id,
					'wp_user_id' => 'eq.' . $user_id,
					'select'     => 'id',
					'limit'      => 1,
				]
			);
			if ( is_wp_error( $world_check ) || empty( $world_check[0] ) ) {
				wp_send_json_error( 'Invalid world_id', 403 );
				return;
			}
			$params['world_id'] = 'eq.' . $world_id;
		}

		$data = tw_supabase_get( 'cyber_characters', $params );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( 'Supabase error: ' . $data->get_error_message(), 502 );
			return;
		}

		if ( empty( $data ) ) {
			wp_send_json_error( 'No characters found', 404 );
			return;
		}

		// If single character requested, return object; otherwise return array.
		wp_send_json_success( ! empty( $character_id ) ? $data[0] : $data );
	}
}
