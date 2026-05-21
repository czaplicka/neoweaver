<?php
/**
 * NeoWeaver — Supabase Auth Bridge
 *
 * Handles the WordPress ↔ Supabase auth handshake:
 *  1. On WP login: provisions a matching row in auth.users + cyber_users via service key.
 *  2. Generates a short-lived Supabase JWT signed with TW_SUPABASE_JWT_SECRET.
 *  3. Stores the token in a WP transient (per user) and exposes it to JS via
 *     window.NW_CONFIG.supabaseToken (injected by head-injection.php or wp_localize_script).
 *
 * Requires wp-config.php:
 *   define( 'TW_SUPABASE_JWT_SECRET', 'your-jwt-secret-from-supabase-dashboard' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Hook: provision + generate token after WP login ────────────────────────
add_action( 'wp_login', 'tw_supabase_on_wp_login', 10, 2 );
function tw_supabase_on_wp_login( string $user_login, WP_User $user ): void {
	tw_supabase_provision_user( $user->ID, $user->user_email );
}

// Also generate token for already-logged-in users on every page load
add_action( 'init', 'tw_supabase_ensure_token_for_current_user' );
function tw_supabase_ensure_token_for_current_user(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$user = wp_get_current_user();
	// If no valid token cached, provision + regenerate
	if ( ! tw_supabase_get_cached_token( $user->ID ) ) {
		tw_supabase_provision_user( $user->ID, $user->user_email );
	}
}

// ─── Core: provision Supabase user + generate JWT ───────────────────────────

/**
 * Ensures a matching Supabase auth.users row and cyber_users row exist for
 * the given WP user, then caches a fresh JWT.
 */
function tw_supabase_provision_user( int $wp_user_id, string $email ): ?string {
	$supabase_uid = tw_supabase_get_or_create_auth_user( $wp_user_id, $email );
	if ( ! $supabase_uid ) {
		return null;
	}
	$token = tw_supabase_generate_jwt( $supabase_uid );
	if ( $token ) {
		set_transient( 'tw_supa_jwt_' . $wp_user_id, $token, 55 * MINUTE_IN_SECONDS );
	}
	return $token;
}

/**
 * Returns cached JWT for given WP user ID, or null if expired/missing.
 */
function tw_supabase_get_cached_token( int $wp_user_id ): ?string {
	$token = get_transient( 'tw_supa_jwt_' . $wp_user_id );
	return $token ?: null;
}

/**
 * Returns the Supabase JWT for the currently logged-in user.
 * This is what you inject into JS.
 */
function tw_supabase_get_current_user_token(): ?string {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	return tw_supabase_get_cached_token( get_current_user_id() );
}

// ─── Supabase auth.users provisioning via Admin API ─────────────────────────

/**
 * Finds or creates a Supabase auth.users row for the given WP user.
 * Also upserts a cyber_users row linking wp_user_id ↔ supabase_uid.
 * Returns the Supabase UUID on success, null on failure.
 */
function tw_supabase_get_or_create_auth_user( int $wp_user_id, string $email ): ?string {
	// 1. Check if already mapped in cyber_users
	$existing = tw_supabase_find_supabase_uid_by_wp_id( $wp_user_id );
	if ( $existing ) {
		return $existing;
	}

	// 2. Try to find by email in auth.users via Admin API
	$supabase_uid = tw_supabase_find_auth_user_by_email( $email );

	// 3. If not found, create a new auth.users row
	if ( ! $supabase_uid ) {
		$supabase_uid = tw_supabase_create_auth_user( $wp_user_id, $email );
	}

	if ( ! $supabase_uid ) {
		return null;
	}

	// 4. Upsert cyber_users row
	tw_supabase_upsert_cyber_user( $supabase_uid, $wp_user_id );

	return $supabase_uid;
}

/**
 * Queries cyber_users for an existing supabase UUID by wp_user_id.
 */
function tw_supabase_find_supabase_uid_by_wp_id( int $wp_user_id ): ?string {
	$url = trailingslashit( tw_supabase_url() ) .
		'rest/v1/cyber_users?wp_user_id=eq.' . $wp_user_id . '&select=id&limit=1';

	$response = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$rows = json_decode( wp_remote_retrieve_body( $response ), true );
	return ( is_array( $rows ) && ! empty( $rows[0]['id'] ) ) ? $rows[0]['id'] : null;
}

/**
 * Finds a Supabase auth.users row by email via Admin API.
 */
function tw_supabase_find_auth_user_by_email( string $email ): ?string {
	$url = trailingslashit( tw_supabase_url() ) .
		'auth/v1/admin/users?email=' . rawurlencode( $email ) . '&page=1&per_page=1';

	$response = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$users = $body['users'] ?? [];
	return ( ! empty( $users[0]['id'] ) ) ? $users[0]['id'] : null;
}

/**
 * Creates a new Supabase auth.users row via Admin API.
 * The password is a random string — WP manages authentication, not Supabase.
 */
function tw_supabase_create_auth_user( int $wp_user_id, string $email ): ?string {
	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/users';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [
			'email'            => $email,
			'password'         => wp_generate_password( 32, true, true ),
			'email_confirm'    => true,
			'user_metadata'    => [ 'wp_user_id' => $wp_user_id ],
		] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return $body['id'] ?? null;
}

/**
 * Upserts a row in cyber_users linking supabase UUID to WP user ID.
 */
function tw_supabase_upsert_cyber_user( string $supabase_uid, int $wp_user_id ): void {
	$url = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_users';

	wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'resolution=merge-duplicates',
		],
		'body'    => wp_json_encode( [
			'id'         => $supabase_uid,
			'wp_user_id' => $wp_user_id,
			'updated_at' => gmdate( 'c' ),
		] ),
		'timeout' => 10,
	] );
}

// ─── JWT generation (HS256, no external library) ────────────────────────────

/**
 * Generates a Supabase-compatible JWT for the given Supabase UUID.
 * Token is valid for 60 minutes.
 */
function tw_supabase_generate_jwt( string $supabase_uid ): ?string {
	if ( ! defined( 'TW_SUPABASE_JWT_SECRET' ) || empty( TW_SUPABASE_JWT_SECRET ) ) {
		error_log( 'NeoWeaver: TW_SUPABASE_JWT_SECRET is not defined in wp-config.php' );
		return null;
	}

	$now = time();

	$header = tw_supabase_jwt_base64url_encode(
		wp_json_encode( [ 'alg' => 'HS256', 'typ' => 'JWT' ] )
	);

	$payload = tw_supabase_jwt_base64url_encode(
		wp_json_encode( [
			'iss'  => 'supabase',
			'ref'  => defined( 'TW_SUPABASE_PROJECT_ID' ) ? TW_SUPABASE_PROJECT_ID : '',
			'role' => 'authenticated',
			'sub'  => $supabase_uid,
			'aud'  => 'authenticated',
			'iat'  => $now,
			'exp'  => $now + ( 60 * MINUTE_IN_SECONDS ),
		] )
	);

	$signature = tw_supabase_jwt_base64url_encode(
		hash_hmac( 'sha256', $header . '.' . $payload, TW_SUPABASE_JWT_SECRET, true )
	);

	return $header . '.' . $payload . '.' . $signature;
}

function tw_supabase_jwt_base64url_encode( string $data ): string {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}
