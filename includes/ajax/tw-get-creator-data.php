<?php
/**
 * Character Creator — AJAX data endpoints
 *
 * Provides neoweaver_get_races, neoweaver_get_subraces,
 * neoweaver_get_classes, and neoweaver_get_nodes
 * for the character creator wizard (step 2 / 3 / 5).
 *
 * All four handlers:
 *  - require a logged-in user + valid nonce
 *  - read from Supabase via tw_supabase_url() / tw_supabase_anon_key()
 *  - cache results with 5-minute transients (same pattern as races handler)
 *  - return wp_send_json_success( $rows ) on success
 *
 * Column mapping (cyber_classes):
 *   id, name, description, tags (jsonb), img_url, icon_slug
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Shared helpers
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( '_tw_creator_supa_get' ) ) {
	/**
	 * Perform a Supabase REST GET and return decoded rows.
	 *
	 * @param string $table  Table name (without prefix — pass the full cyber_… name).
	 * @param array  $query  Query-string args passed to add_query_arg().
	 * @return array         Decoded rows, or empty array on any failure.
	 */
	function _tw_creator_supa_get( string $table, array $query ): array {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW Creator AJAX: Supabase helpers not available.' );
			return [];
		}

		$key = tw_supabase_anon_key();
		$url = add_query_arg(
			$query,
			trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table
		);

		$resp = wp_remote_get( $url, [
			'headers' => [
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $resp ) ) {
			error_log( 'TW Creator AJAX: Supabase error for ' . $table . ' — ' . $resp->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW Creator AJAX: Supabase HTTP ' . $code . ' for ' . $table . ' — ' . wp_remote_retrieve_body( $resp ) );
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $rows ) ? $rows : [];
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. RACES  (neoweaver_get_races)
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'neoweaver_ajax_get_races' ) ) {

	add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_ajax_get_races' );

	function neoweaver_ajax_get_races(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$cache_key = 'tw_creator_races_v1';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$rows = _tw_creator_supa_get( 'cyber_races', [
			'select'    => 'id,name,description,img_url,icon_slug,tags,bonus_text',
			'is_active' => 'eq.true',
			'order'     => 'name.asc',
		] );

		// Normalise to the shape the JS buildRaceCard() expects:
		// { key, label, img, desc, bonus, tags[] }
		$races = array_map( function ( $r ) {
			$tags = $r['tags'] ?? [];
			if ( is_string( $tags ) ) {
				$tags = json_decode( $tags, true ) ?: [];
			}
			return [
				'key'   => (string) ( $r['id'] ?? '' ),
				'label' => (string) ( $r['name'] ?? '' ),
				'img'   => (string) ( $r['img_url'] ?? '' ),
				'icon'  => (string) ( $r['icon_slug'] ?? '&#10067;' ),
				'desc'  => (string) ( $r['description'] ?? '' ),
				'bonus' => (string) ( $r['bonus_text'] ?? '' ),
				'tags'  => $tags,
			];
		}, $rows );

		set_transient( $cache_key, $races, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $races );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 2. SUBRACES  (neoweaver_get_subraces)
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'neoweaver_ajax_get_subraces' ) ) {

	add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );

	function neoweaver_ajax_get_subraces(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$parent_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['parent'] ?? '' ) );
		if ( ! $parent_id ) {
			wp_send_json_error( 'Missing parent race ID.' );
			return;
		}

		$cache_key = 'tw_creator_subraces_' . $parent_id . '_v1';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$rows = _tw_creator_supa_get( 'cyber_subraces', [
			'race_id'   => 'eq.' . $parent_id,
			'select'    => 'id,name,description,img_url,tags',
			'is_active' => 'eq.true',
			'order'     => 'name.asc',
		] );

		// Normalise to the shape buildSubraceCard() expects:
		// { key, label, img, desc, tags[] }
		$subraces = array_map( function ( $r ) {
			$tags = $r['tags'] ?? [];
			if ( is_string( $tags ) ) {
				$tags = json_decode( $tags, true ) ?: [];
			}
			return [
				'key'   => (string) ( $r['id'] ?? '' ),
				'label' => (string) ( $r['name'] ?? '' ),
				'img'   => (string) ( $r['img_url'] ?? '' ),
				'desc'  => (string) ( $r['description'] ?? '' ),
				'tags'  => $tags,
			];
		}, $rows );

		set_transient( $cache_key, $subraces, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $subraces );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 3. CLASSES  (neoweaver_get_classes)
//    cyber_classes: id, name, description, tags (jsonb), img_url, icon_slug
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'neoweaver_ajax_get_classes' ) ) {

	add_action( 'wp_ajax_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );

	function neoweaver_ajax_get_classes(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$cache_key = 'tw_creator_classes_v1';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$rows = _tw_creator_supa_get( 'cyber_classes', [
			'select'    => 'id,name,description,tags,img_url,icon_slug',
			'is_active' => 'eq.true',
			'order'     => 'name.asc',
		] );

		// Decode tags jsonb → PHP array; pass columns straight through
		// because the JS fetchClassGrid() already reads cls.img_url,
		// cls.name, cls.description, cls.tags, cls.icon_slug, cls.id.
		$classes = array_map( function ( $r ) {
			$tags = $r['tags'] ?? [];
			if ( is_string( $tags ) ) {
				$tags = json_decode( $tags, true ) ?: [];
			}
			return [
				'id'          => (string) ( $r['id'] ?? '' ),
				'name'        => (string) ( $r['name'] ?? '' ),
				'description' => (string) ( $r['description'] ?? '' ),
				'tags'        => $tags,
				'img_url'     => (string) ( $r['img_url'] ?? '' ),
				'icon_slug'   => (string) ( $r['icon_slug'] ?? '' ),
			];
		}, $rows );

		set_transient( $cache_key, $classes, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $classes );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// 4. NODES  (neoweaver_get_nodes)
//    Returns only nodes owned by the current WP user (wp_user_id match).
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'neoweaver_ajax_get_nodes' ) ) {

	add_action( 'wp_ajax_neoweaver_get_nodes', 'neoweaver_ajax_get_nodes' );

	function neoweaver_ajax_get_nodes(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( 'Not logged in.' );
			return;
		}

		$cache_key = 'tw_creator_nodes_' . $wp_user_id . '_v1';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$rows = _tw_creator_supa_get( 'cyber_worlds', [
			'wp_user_id' => 'eq.' . $wp_user_id,
			'select'     => 'id,name,description,entropy',
			'order'      => 'created_at.desc',
		] );

		// Filter out Hard-Reset worlds (entropy >= 100).
		// Normalise to the shape the JS buildNodeCard() expects:
		// { id, label, desc }
		$nodes = [];
		foreach ( $rows as $r ) {
			if ( (int) ( $r['entropy'] ?? 0 ) >= 100 ) {
				continue; // Hard Reset — cannot bind new agents.
			}
			$nodes[] = [
				'id'    => (string) ( $r['id'] ?? '' ),
				'label' => (string) ( $r['name'] ?? '' ),
				'desc'  => (string) ( $r['description'] ?? '' ),
			];
		}

		set_transient( $cache_key, $nodes, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $nodes );
	}
}
