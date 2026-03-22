<?php
/**
 * Shortcode: [tw_create_campaign]
 *
 * Renders the 8-step deployment (campaign) creation wizard.
 * Extracted from Neoweaver_Public to keep each wizard in its own file.
 *
 * Dependencies (must be loaded before this file):
 *   - tw_supabase_url() / tw_supabase_anon_key()  (supabase-helpers.php)
 *   - neoweaver-campaign-creator JS/CSS            (enqueued by Neoweaver_Public::enqueue_assets)
 *
 * The two Supabase look-up lists (worlds, characters) are cached per user
 * with a short transient (CAMPAIGN_CACHE_TTL seconds) so a warm-cache page
 * render costs zero outbound HTTP calls. The transient key includes the user
 * ID so different operators never see each other's data.
 *
 * CSS scope : .neoweaver-screen #tw-campaign-creator-container
 * JS file   : assets/js/tw-campaign-creator.js
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transient lifetime in seconds for the worlds/characters lookup lists. */
define( 'NW_CAMPAIGN_CREATOR_CACHE_TTL', 60 );

if ( ! function_exists( 'neoweaver_shortcode_campaign_creator' ) ) {

	/**
	 * [tw_create_campaign] callback.
	 *
	 * Intentionally a standalone function so it can be registered
	 * independently of Neoweaver_Public.  The shortcode tag is still
	 * registered by Neoweaver_Public::__construct() via
	 * [ $this, 'shortcode_campaign_creator' ], which now delegates here.
	 *
	 * @return string  Rendered HTML wrapped in .neoweaver-screen.
	 */
	function neoweaver_shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<div class="neoweaver-screen"><p class="tw-error">UPLINK REQUIRED. LOG IN.</p></div>';
		}

		// ── Cached Supabase look-ups ──────────────────────────────────────────
		$worlds     = neoweaver_campaign_creator_supabase_get(
			'cyber_worlds',
			[ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ],
			$user_id,
			NW_CAMPAIGN_CREATOR_CACHE_TTL
		);
		$characters = neoweaver_campaign_creator_supabase_get(
			'cyber_characters',
			[ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ],
			$user_id,
			NW_CAMPAIGN_CREATOR_CACHE_TTL
		);

		$campaign_nonce = wp_create_nonce( 'tw_campaign_nonce' );

		wp_localize_script(
			'neoweaver-campaign-creator',
			'twCampaignConfig',
			[ 'nonce' => $campaign_nonce ]
		);

		$path = get_stylesheet_directory() . '/templates/partials/campaign-creator.php';
		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial campaign-creator.php -->';
		}

		ob_start();
		( static function ( $tw_data, $__path ) {
			extract( [ 'tw_data' => $tw_data ], EXTR_SKIP );
			include $__path;
		} )( [ 'worlds' => $worlds, 'characters' => $characters ], $path );
		$html = ob_get_clean() ?: '';

		return '<div class="neoweaver-screen">' . $html . '</div>';
	}
}

if ( ! function_exists( 'neoweaver_campaign_creator_supabase_get' ) ) {

	/**
	 * Thin Supabase GET helper with optional per-user transient caching.
	 * Private to this file — other code should use tw_supabase_get() instead.
	 *
	 * @param string $table       Table name.
	 * @param array  $query_args  Query-string parameters.
	 * @param int    $user_id     Cache key component; 0 = no caching.
	 * @param int    $ttl         Transient lifetime in seconds.
	 * @return array
	 */
	function neoweaver_campaign_creator_supabase_get(
		string $table,
		array $query_args,
		int $user_id = 0,
		int $ttl = 0
	): array {
		$cache_key = ( $ttl > 0 && $user_id > 0 )
			? 'tw_sb_' . $user_id . '_' . md5( $table . serialize( $query_args ) )
			: '';

		if ( $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$anon_key = tw_supabase_anon_key();
		$response = wp_remote_get(
			add_query_arg( $query_args, trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table ),
			[
				'headers' => [
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				],
			]
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$reason = is_wp_error( $response )
				? $response->get_error_message()
				: wp_remote_retrieve_response_code( $response );
			error_log( "NeoWeaver campaign creator: Supabase fetch failed for '{$table}' – {$reason}" );
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

		if ( $cache_key ) {
			set_transient( $cache_key, $rows, $ttl );
		}

		return $rows;
	}
}
