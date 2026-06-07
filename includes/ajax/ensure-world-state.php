<?php
// ==========================================
// WORLD STATE: AUTO-INIT FOR CAMPAIGN
// ==========================================

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_ensure_world_state' ) ) {
	add_action( 'wp_ajax_tw_ensure_world_state', 'tw_ensure_world_state' );

	function tw_ensure_world_state(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			return;
		}

		$campaign_id = isset( $_POST['campaign_id'] )
			? strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $_POST['campaign_id'] ) )
			: '';

		if ( ! $campaign_id ) {
			wp_send_json_error( [ 'message' => 'Missing campaign_id' ], 400 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_get' ) || ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase helper missing' ], 500 );
			return;
		}

		// Verify the calling user owns or is a participant of this campaign.
		$is_owner = false;
		if ( function_exists( 'tw_supabase_get_admin' ) ) {
			$owner_row = tw_supabase_get_admin(
				'cyber_campaign',
				[
					'id'         => 'eq.' . $campaign_id,
					'wp_user_id' => 'eq.' . $user_id,
					'select'     => 'id',
					'limit'      => 1,
				]
			);
			$is_owner = is_array( $owner_row ) && ! empty( $owner_row );
		}

		$is_participant = false;
		if ( ! $is_owner && function_exists( 'tw_supabase_get_admin' ) ) {
			$part_row = tw_supabase_get_admin(
				'cyber_campaign_characters',
				[
					'campaign_id' => 'eq.' . $campaign_id,
					'wp_user_id'  => 'eq.' . $user_id,
					'select'      => 'campaign_id',
					'limit'       => 1,
				]
			);
			$is_participant = is_array( $part_row ) && ! empty( $part_row );
		}

		if ( ! $is_owner && ! $is_participant ) {
			wp_send_json_error( [ 'message' => 'Campaign not found or access denied' ], 403 );
			return;
		}

		$payload = [
			'campaign_id'     => $campaign_id,
			'current_hour'    => 8,
			'current_weather' => 'Sun',
			'next_weather'    => 'Cloudy',
			'current_season'  => 'Spring',
		];

		$insert = tw_supabase_request(
			'POST',
			'cyber_world_state',
			[],
			$payload,
			[
				'headers' => [
					'Prefer' => 'return=representation',
				],
				'timeout'   => 10,
				'sslverify' => true,
			]
		);

		if ( ! is_wp_error( $insert ) ) {
			// tw_supabase_request returns ['ok' => true, 'code' => int, 'data' => mixed].
			// With Prefer: return=representation, 'data' contains the inserted rows array.
			$inserted_row = is_array( $insert['data'] ?? null ) ? ( $insert['data'][0] ?? null ) : null;
			wp_send_json_success(
				[
					'status' => 'created',
					'row'    => $inserted_row,
				]
			);
			return;
		}

		$error_data = $insert->get_error_data();
		$status     = (int) ( $error_data['status'] ?? 500 );
		$body_raw   = (string) ( $error_data['body'] ?? '' );
		$data       = $error_data['data'] ?? null;
		$message    = $insert->get_error_message();

		$sqlstate = '';
		if ( is_array( $data ) ) {
			$sqlstate = (string) ( $data['code'] ?? '' );
		}

		// PostgREST returns HTTP 409 for unique constraint violations (SQLSTATE 23505).
		$is_unique_violation =
			409 === $status ||
			'23505' === $sqlstate ||
			false !== stripos( $body_raw, 'duplicate key value violates unique constraint' );

		if ( $is_unique_violation ) {
			$existing = tw_supabase_get(
				'cyber_world_state',
				[
					'campaign_id' => 'eq.' . $campaign_id,
					'select'      => 'campaign_id,current_hour,current_weather,next_weather,current_season',
					'limit'       => 1,
				]
			);

			if ( is_wp_error( $existing ) ) {
				error_log(
					'TW ensure_world_state unique-conflict fetch error: '
					. $existing->get_error_message()
					. ' | campaign_id=' . $campaign_id
				);
				wp_send_json_error(
					[
						'message' => 'World state exists but fetch failed',
						'error'   => $existing->get_error_message(),
					],
					502
				);
				return;
			}

			if ( ! empty( $existing ) ) {
				wp_send_json_success(
					[
						'status' => 'exists',
						'row'    => $existing[0],
					]
				);
				return;
			}

			wp_send_json_error(
				[ 'message' => 'World state conflict detected but existing row was not found' ],
				409
			);
			return;
		}

		error_log(
			'TW ensure_world_state insert error: '
			. $message
			. ' | status=' . $status
			. ' | sqlstate=' . $sqlstate
			. ' | body=' . $body_raw
			. ' | campaign_id=' . $campaign_id
		);

		wp_send_json_error(
			[
				'message' => 'Insert failed',
				'status'  => $status,
				'error'   => $message,
			],
			$status > 0 ? $status : 500
		);
	}
}
