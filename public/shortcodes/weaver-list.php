<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_display_weaver_list' ) ) {
	function tw_display_weaver_list(): string {
		if ( ! function_exists( 'tw_get_current_character_id' ) ) {
			return '<div class="tw-weaver-error">Character handler missing.</div>';
		}

		$char_id = tw_get_current_character_id();

		if ( ! $char_id || 0 == $char_id ) {
			return '<div class="tw-weaver-error">No active character found. Please select a character first.</div>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<p>Configuration Error: Supabase credentials missing.</p>';
		}

		$supabase_url = tw_supabase_url();
		$anon_key     = tw_supabase_anon_key();

		if ( empty( $supabase_url ) || empty( $anon_key ) ) {
			return '<p>Configuration Error: Supabase credentials missing.</p>';
		}

		if ( function_exists( 'tw_enqueue_weaver_list_assets' ) ) {
			tw_enqueue_weaver_list_assets();
		}

		$url = add_query_arg(
			array(
				'character_id' => 'eq.' . intval( $char_id ),
				'is_consumed'  => 'eq.false',
				'select'       => '*',
			),
			trailingslashit( $supabase_url ) . 'rest/v1/cyber_weaves'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
					'Content-Type'  => 'application/json',
				),
				'timeout'   => apply_filters( 'tw_supabase_timeout', 15 ),
				'sslverify' => apply_filters( 'tw_supabase_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '<p>Connection Error: ' . esc_html( $response->get_error_message() ) . '</p>';
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return '<p>API Error: Received HTTP ' . esc_html( (string) intval( $status_code ) ) . '.</p>';
		}

		$weaves = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $weaves ) || ! is_array( $weaves ) ) {
			return '<div class="tw-no-weaves">Your Weaver pouch is empty. Dissolve items or complete quests to gain Weaves.</div>';
		}

		$allowed_rarities = array( 'common', 'uncommon', 'rare', 'epic', 'legendary' );

		$output  = '<div class="tw-weaver-container">';
		$output .= '<div class="tw-weaver-grid">';

		foreach ( $weaves as $weave ) {
			$rarity       = strtolower( (string) ( $weave['rarity'] ?? 'common' ) );
			$rarity_class = in_array( $rarity, $allowed_rarities, true ) ? $rarity : 'common';
			$tag          = (string) ( $weave['tag_reference'] ?? 'General' );
			$name         = (string) ( $weave['name'] ?? 'Unknown Weave' );
			$desc         = (string) ( $weave['description'] ?? '' );

			$output .= sprintf(
				"<div class='tw-weaver-card rarity-%s'>
					<div class='tw-weaver-header'>
						<span class='tw-weaver-name'>%s</span>
						<span class='tw-weaver-tag'>#%s</span>
					</div>
					<div class='tw-weaver-desc'>%s</div>
					<div class='tw-weaver-footer'>
						<span class='tw-rarity-label'>%s</span>
					</div>
				</div>",
				esc_attr( $rarity_class ),
				esc_html( $name ),
				esc_html( $tag ),
				esc_html( $desc ),
				esc_html( $rarity_class )
			);
		}

		$output .= '</div></div>';

		return $output;
	}

	add_shortcode( 'tw_weaver_list', 'tw_display_weaver_list' );
}
