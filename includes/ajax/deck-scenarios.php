<?php
/**
 * TALE WEAVER - Deck / Scenarios AJAX
 *
 * 1. tw_localize_deck_vars()  - merges deck vars into twGameConfig on the adventure page.
 * 2. tw_get_scenarios_ajax()  - returns available (unplayed) scenarios for a campaign.
 *
 * All Supabase reads use tw_supabase_get() (anon key, respects RLS).
 * No writes happen here — service key is not needed.
 *
 * JS handle expected: 'adventure-js'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Merge deck config vars into twGameConfig on the adventure page.
//
// FIX: is_page_template() slug must match _wp_page_template post meta.
//      Registered in neoweaver-wp-core.php as 'templates/adventure.php'
//      (via filter_page_templates), so the check must use that path.
//
// FIX: wp_localize_script() replaces the entire twGameConfig object,
//      destroying keys set by other handlers (e.g. buffer.php sets
//      use_card_nonce / foundry_nonce). We now use wp_add_inline_script()
//      with Object.assign() so all handlers merge non-destructively,
//      matching the pattern already used in buffer.php.
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', 'tw_localize_deck_vars', 11 );

function tw_localize_deck_vars() {

	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	// campaign_id from query var or GET fallback.
	// cyber_campaign.id is a UUID — sanitize by stripping non-alphanumeric/hyphen.
	$campaign_id_raw = get_query_var( 'campaign_id' );

	if ( ! is_string( $campaign_id_raw ) || $campaign_id_raw === '' ) {
		if ( isset( $_GET['campaign_id'] ) && is_string( $_GET['campaign_id'] ) && $_GET['campaign_id'] !== '' ) {
			$campaign_id_raw = $_GET['campaign_id'];
		} else {
			$campaign_id_raw = '';
		}
	}

	$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $campaign_id_raw );

	// Auto-detect last campaign for this user if not provided.
	if ( ! $campaign_id ) {
		$result = tw_supabase_get(
			'cyber_campaign',
			[
				'wp_user_id' => 'eq.' . (int) $user_id,
				'order'      => 'created_at.desc',
				'limit'      => 1,
				'select'     => 'id',
			]
		);

		if ( ! is_wp_error( $result ) && ! empty( $result[0]['id'] ) ) {
			$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $result[0]['id'] );
		}
	}

	// Merge into existing twGameConfig via Object.assign — non-destructive.
	$data = wp_json_encode(
		[
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'tw_deck_nonce' ),
			'campaign_id' => $campaign_id,
			'user_id'     => (int) $user_id,
		]
	);

	wp_add_inline_script(
		'adventure-js',
		'window.twGameConfig = Object.assign( window.twGameConfig || {}, ' . $data . ' );',
		'before'
	);
}

// ---------------------------------------------------------------------------
// 2. AJAX: get available scenarios for a campaign
// Tylko zalogowani użytkownicy — nie rejestrujemy nopriv.
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_tw_get_scenarios_ajax', 'tw_get_scenarios_ajax' );

function tw_get_scenarios_ajax(): void {

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
		return;
	}

	if ( ! check_ajax_referer( 'tw_deck_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
		return;
	}

	// cyber_campaign.id is a UUID — never cast to int.
	$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['campaign_id'] ?? '' ) );

	if ( ! $campaign_id ) {
		wp_send_json_error( [ 'message' => 'Missing campaign_id' ], 400 );
		return;
	}

	// 1. Campaign row.
	$campaigns = tw_supabase_get(
		'cyber_campaign',
		[
			'id'     => 'eq.' . $campaign_id,
			'select' => 'id,world_type',
			'limit'  => 1,
		]
	);

	if ( is_wp_error( $campaigns ) ) {
		wp_send_json_error( [ 'message' => 'Campaign fetch failed', 'error' => $campaigns->get_error_message() ], 502 );
		return;
	}

	if ( empty( $campaigns ) ) {
		wp_send_json_error( [ 'message' => 'Campaign not found' ], 404 );
		return;
	}

	$campaign       = $campaigns[0];
	$world_type     = isset( $campaign['world_type'] ) ? (int) $campaign['world_type'] : 1;
	$difficulty_min = $world_type - 1;
	$difficulty_max = $world_type + 1;

	// 2. Played scenarios.
	$played = tw_supabase_get(
		'cyber_campaign_played_scenarios',
		[
			'campaign_id' => 'eq.' . $campaign_id,
			'select'      => 'scenario_id',
		]
	);

	if ( is_wp_error( $played ) ) {
		wp_send_json_error( [ 'message' => 'Played scenarios fetch failed', 'error' => $played->get_error_message() ], 502 );
		return;
	}

	// UUID-safe: explicit '' !== $v callback — default array_filter would drop
	// any value that loosely evaluates to false (e.g. '0', '', null).
	$played     = $played ?: [];
	$played_ids = ! empty( $played )
		? array_values(
			array_filter(
				array_map(
					static fn( $row ) => preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $row['scenario_id'] ?? '' ) ),
					$played
				),
				static fn( $v ) => '' !== $v
			)
		)
		: [];

	// 3. Difficulty range (min 1).
	$difficulty_values = array_unique( array_filter(
		[ $difficulty_min, $world_type, $difficulty_max ],
		fn( $v ) => $v >= 1
	) );

	// 4. Scenarios query.
	$query = [
		'difficulty' => 'in.(' . implode( ',', $difficulty_values ) . ')',
		'type'       => 'eq.main',
		'order'      => 'created_at.desc',
		'limit'      => 3,
	];

	if ( ! empty( $played_ids ) ) {
		$query['id'] = 'not.in.(' . implode( ',', $played_ids ) . ')';
	}

	$scenarios = tw_supabase_get( 'cyber_scenarios', $query );

	if ( is_wp_error( $scenarios ) ) {
		wp_send_json_error( [ 'message' => 'Scenarios fetch failed', 'error' => $scenarios->get_error_message() ], 502 );
		return;
	}

	if ( empty( $scenarios ) ) {
		wp_send_json_error( [ 'message' => 'No scenarios found' ], 404 );
		return;
	}

	wp_send_json_success( [ 'scenarios' => $scenarios ] );
}
