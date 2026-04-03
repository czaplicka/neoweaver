<?php
/**
 * AJAX handlers: neoweaver_get_races & neoweaver_get_subraces
 *
 * Serves race and subrace data from cyber_races to the character creator
 * wizard (Step 2). Both handlers require a logged-in user and a valid nonce.
 *
 * Nonce: 'neoweaver_nonce' — matches what class-neoweaver-public.php
 * injects via wp_localize_script( 'neoweaver-char-creator', 'twCharCreatorConfig', [...] ).
 *
 * Schema notes (cyber_races):
 *   - Base races:  parent_race IS NULL
 *   - Subraces:    parent_race = <parent name string> (text FK on cyber_races.name)
 *   - id:          UUID (primary key)
 *   - name:        unique text — used as the card "key" in JS (formState.race).
 *                  The UUID is also returned as "id" for use in the submit payload.
 *   - img_url:     relative filename (e.g. "echo.svg") or full URL.
 *                  Relative paths are prefixed with the WP uploads base URL.
 *   - tags:        JSONB array — joined into a "bonus" string for display.
 *
 * Response shape (matches buildRaceCard / buildSubraceCard in tw-character-creator.js):
 *   {
 *     key:   string   // cyber_races.name  (unique, stable)
 *     id:    string   // UUID — kept for submit payload
 *     label: string   // display name (same as key)
 *     img:   string   // full URL or ''
 *     icon:  string   // always '' — images preferred over emoji
 *     bonus: string   // e.g. "stealth · shadow"
 *   }
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Base URL for resolving relative img_url values stored in cyber_races.
if ( ! defined( 'TW_RACE_UPLOADS_BASE' ) ) {
	define( 'TW_RACE_UPLOADS_BASE', 'https://neoweaver.nieodparady.pl/wp-content/uploads/' );
}

// ─── Helper: standard Supabase headers ──────────────────────────────────────

if ( ! function_exists( 'tw_races_headers' ) ) {
	function tw_races_headers(): array {
		$key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
		return [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
		];
	}
}

// ─── Helper: resolve relative img_url to absolute URL ───────────────────────

if ( ! function_exists( 'tw_resolve_race_img' ) ) {
	function tw_resolve_race_img( string $img_url ): string {
		if ( empty( $img_url ) ) {
			return '';
		}
		// Already absolute.
		if ( strpos( $img_url, 'http' ) === 0 ) {
			return esc_url_raw( $img_url );
		}
		// Relative filename (e.g. "echo.svg") — prepend uploads base.
		return esc_url_raw( TW_RACE_UPLOADS_BASE . ltrim( $img_url, '/' ) );
	}
}

// ─── Helper: map a raw cyber_races DB row → JS card shape ───────────────────

if ( ! function_exists( 'tw_race_row_to_card' ) ) {
	function tw_race_row_to_card( array $row ): array {
		// Tags JSONB → "tag1 · tag2 · tag3" bonus string.
		$tags = $row['tags'] ?? [];
		if ( is_string( $tags ) ) {
			$tags = json_decode( $tags, true ) ?: [];
		}
		$bonus = is_array( $tags ) && ! empty( $tags ) ? implode( ' · ', $tags ) : '';

		return [
			'key'   => sanitize_text_field( $row['name'] ?? '' ),
			'id'    => sanitize_text_field( $row['id']   ?? '' ),
			'label' => sanitize_text_field( $row['name'] ?? '' ),
			'img'   => tw_resolve_race_img( $row['img_url'] ?? '' ),
			'icon'  => '',
			'bonus' => sanitize_text_field( $bonus ),
		];
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. GET BASE RACES  (parent_race IS NULL)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_get_races_handler' );
// nopriv intentionally omitted — character creation requires login.

if ( ! function_exists( 'neoweaver_get_races_handler' ) ) {
	function neoweaver_get_races_handler(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ] );
			return;
		}
		if ( ! check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase config missing.' ] );
			return;
		}

		// 5-minute transient cache — race list is essentially static.
		// Key bumped to v2 so old cache (with description) is ignored.
		$cache_key = 'tw_races_base_v2';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$url = add_query_arg(
			[
				'parent_race' => 'is.null',
				'select'      => 'id,name,img_url,tags',
				'order'       => 'name.asc',
			],
			trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_races'
		);

		$response = wp_remote_get( $url, [
			'headers' => tw_races_headers(),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW Races: GET error — ' . $response->get_error_message() );
			wp_send_json_error( [ 'message' => 'Database connection error.' ] );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			error_log( 'TW Races: GET HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
			wp_send_json_error( [ 'message' => 'Database returned HTTP ' . $code . '.' ] );
			return;
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => 'Invalid response from database.' ] );
			return;
		}

		if ( empty( $rows ) ) {
			// Empty array → JS falls back to hardcoded RACES_FALLBACK.
			wp_send_json_success( [] );
			return;
		}

		$cards = array_map( 'tw_race_row_to_card', $rows );
		set_transient( $cache_key, $cards, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( $cards );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. GET SUBRACES  (parent_race = <name>)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_get_subraces_handler' );
// nopriv intentionally omitted.

if ( ! function_exists( 'neoweaver_get_subraces_handler' ) ) {
	function neoweaver_get_subraces_handler(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ] );
			return;
		}
		if ( ! check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
			return;
		}

		$parent = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';
		if ( empty( $parent ) ) {
			wp_send_json_error( [ 'message' => 'Missing parent race name.' ] );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase config missing.' ] );
			return;
		}

		// Key bumped to v2 so old cache (with description) is ignored.
		$cache_key = 'tw_subraces_' . md5( $parent ) . '_v2';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$url = add_query_arg(
			[
				'parent_race' => 'eq.' . rawurlencode( $parent ),
				'select'      => 'id,name,img_url,tags',
				'order'       => 'name.asc',
			],
			trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_races'
		);

		$response = wp_remote_get( $url, [
			'headers' => tw_races_headers(),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW Subraces: GET error — ' . $response->get_error_message() );
			wp_send_json_error( [ 'message' => 'Database connection error.' ] );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			error_log( 'TW Subraces: GET HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
			wp_send_json_error( [ 'message' => 'Database returned HTTP ' . $code . '.' ] );
			return;
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => 'Invalid response from database.' ] );
			return;
		}

		// Empty → JS hides the subrace section automatically.
		$cards = array_map( 'tw_race_row_to_card', $rows );
		set_transient( $cache_key, $cards, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( $cards );
	}
}
