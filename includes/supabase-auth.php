<?php
/**
 * NeoWeaver — Supabase Auth Bridge
 *
 * WordPress ↔ Supabase auth handshake:
 *  1. Na logowaniu WP: tworzy/znajduje użytkownika w auth.users + cyber_users.
 *  2. Pobiera sesję przez Admin API (POST auth/v1/admin/users/{uid}/session).
 *     Supabase podpisuje token swoim aktywnym kluczem (ECC P-256 lub HS256).
 *  3. Cachuje access_token + refresh_token w transiencie WP (55 min).
 *     Przy kolejnych żądaniach odświeża token przez /auth/v1/token?grant_type=refresh_token.
 *
 * NIE wymaga TW_SUPABASE_JWT_SECRET — token generuje Supabase po stronie serwera.
 * Wymaga tylko TW_SUPABASE_SERVICE_KEY w wp-config.php.
 *
 * RLS note: token zawiera auth.uid() = Supabase UUID.
 *           cyber_users.id = supabase_uid — RLS używa: auth.uid() = id
 *           cyber_users.wp_user_id — tylko do joinów po stronie serwera (service key).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Hooks ───────────────────────────────────────────────────────────────────

add_action( 'wp_login', 'tw_supabase_on_wp_login', 10, 2 );
function tw_supabase_on_wp_login( string $user_login, WP_User $user ): void {
	tw_supabase_provision_user( $user->ID, $user->user_email );
}

add_action( 'init', 'tw_supabase_ensure_token_for_current_user' );
function tw_supabase_ensure_token_for_current_user(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! tw_supabase_get_cached_token( $user->ID ) ) {
		tw_supabase_provision_user( $user->ID, $user->user_email );
	}
}

// ─── Core ────────────────────────────────────────────────────────────────────

/**
 * Główna funkcja: provision użytkownika i zwróć access_token.
 */
function tw_supabase_provision_user( int $wp_user_id, string $email ): ?string {
	$supabase_uid = tw_supabase_get_or_create_auth_user( $wp_user_id, $email );
	if ( ! $supabase_uid ) {
		return null;
	}

	// Spróbuj odświeżyć istniejącą sesję zanim tworzysz nową.
	$token = tw_supabase_refresh_token_if_possible( $wp_user_id );
	if ( ! $token ) {
		$token = tw_supabase_create_session_for_uid( $supabase_uid, $wp_user_id );
	}

	return $token;
}

function tw_supabase_get_cached_token( int $wp_user_id ): ?string {
	$cached = get_transient( 'tw_supa_jwt_' . $wp_user_id );
	return ( is_array( $cached ) && ! empty( $cached['access_token'] ) )
		? $cached['access_token']
		: null;
}

function tw_supabase_get_current_user_token(): ?string {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	return tw_supabase_get_cached_token( get_current_user_id() );
}

// ─── Session via Admin API ────────────────────────────────────────────────────

/**
 * Tworzy nową sesję Supabase dla danego UUID (bez emaila/OTP).
 * Endpoint: POST auth/v1/admin/users/{uid}/session
 * Zwraca access_token lub null.
 */
function tw_supabase_create_session_for_uid( string $supabase_uid, int $wp_user_id ): ?string {
	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/users/' . $supabase_uid . '/session';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [ 'issuer' => 'neoweaver-wp' ] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NeoWeaver [session]: ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $body['access_token'] ) ) {
		error_log( 'NeoWeaver [session]: HTTP ' . $code . ' uid=' . $supabase_uid );
		return null;
	}

	// Cachuj access_token + refresh_token.
	set_transient(
		'tw_supa_jwt_' . $wp_user_id,
		[
			'access_token'  => $body['access_token'],
			'refresh_token' => $body['refresh_token'] ?? '',
		],
		55 * MINUTE_IN_SECONDS
	);

	return $body['access_token'];
}

/**
 * Odświeża token przez refresh_token jeśli jest zapisany.
 * Używa: POST auth/v1/token?grant_type=refresh_token
 */
function tw_supabase_refresh_token_if_possible( int $wp_user_id ): ?string {
	$cached = get_transient( 'tw_supa_jwt_' . $wp_user_id );
	if ( ! is_array( $cached ) || empty( $cached['refresh_token'] ) ) {
		return null;
	}

	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/token?grant_type=refresh_token';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'       => tw_supabase_anon_key(),
			'Content-Type' => 'application/json',
		],
		'body'    => wp_json_encode( [ 'refresh_token' => $cached['refresh_token'] ] ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $body['access_token'] ) ) {
		// refresh wygasł — usuń cache, provision zrobi nową sesję.
		delete_transient( 'tw_supa_jwt_' . $wp_user_id );
		return null;
	}

	set_transient(
		'tw_supa_jwt_' . $wp_user_id,
		[
			'access_token'  => $body['access_token'],
			'refresh_token' => $body['refresh_token'] ?? $cached['refresh_token'],
		],
		55 * MINUTE_IN_SECONDS
	);

	return $body['access_token'];
}

// ─── Supabase auth.users provisioning ────────────────────────────────────────

function tw_supabase_get_or_create_auth_user( int $wp_user_id, string $email ): ?string {
	$existing = tw_supabase_find_supabase_uid_by_wp_id( $wp_user_id );
	if ( $existing ) {
		return $existing;
	}

	$supabase_uid = tw_supabase_find_auth_user_by_email( $email );

	if ( ! $supabase_uid ) {
		$supabase_uid = tw_supabase_create_auth_user( $wp_user_id, $email );
	} else {
		// User istnieje w Supabase Auth ale nie ma wp_user_id w app_metadata — uzupełnij.
		tw_supabase_patch_app_metadata( $supabase_uid, $wp_user_id );
	}

	if ( ! $supabase_uid ) {
		return null;
	}

	tw_supabase_upsert_cyber_user( $supabase_uid, $wp_user_id );

	return $supabase_uid;
}

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
		error_log( 'NeoWeaver [find_uid]: ' . $response->get_error_message() );
		return null;
	}

	$rows = json_decode( wp_remote_retrieve_body( $response ), true );
	return ( is_array( $rows ) && ! empty( $rows[0]['id'] ) ) ? $rows[0]['id'] : null;
}

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
		error_log( 'NeoWeaver [find_email]: ' . $response->get_error_message() );
		return null;
	}

	$body  = json_decode( wp_remote_retrieve_body( $response ), true );
	$users = $body['users'] ?? [];
	return ( ! empty( $users[0]['id'] ) ) ? $users[0]['id'] : null;
}

function tw_supabase_create_auth_user( int $wp_user_id, string $email ): ?string {
	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/users';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [
			'email'         => $email,
			'password'      => wp_generate_password( 32, true, true ),
			'email_confirm' => true,
			// app_metadata jest dostępne w JWT jako auth.jwt()->'app_metadata'
			// i może być używane w RLS policies.
			'app_metadata'  => [ 'wp_user_id' => $wp_user_id ],
			'user_metadata' => [ 'wp_user_id' => $wp_user_id ],
		] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NeoWeaver [create_user]: ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 && $code !== 201 ) {
		error_log( 'NeoWeaver [create_user]: HTTP ' . $code . ' email=' . $email );
		return null;
	}

	return $body['id'] ?? null;
}

/**
 * Uzupełnia app_metadata.wp_user_id dla userów którzy istnieli przed tym fixem.
 * Ważne dla RLS: bez tego stary user nie ma wp_user_id w JWT.
 */
function tw_supabase_patch_app_metadata( string $supabase_uid, int $wp_user_id ): void {
	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/users/' . $supabase_uid;

	$response = wp_remote_request( $url, [
		'method'  => 'PUT',
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [
			'app_metadata'  => [ 'wp_user_id' => $wp_user_id ],
			'user_metadata' => [ 'wp_user_id' => $wp_user_id ],
		] ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NeoWeaver [patch_meta]: ' . $response->get_error_message() );
	}
}

function tw_supabase_upsert_cyber_user( string $supabase_uid, int $wp_user_id ): void {
	$url = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_users';

	$response = wp_remote_post( $url, [
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

	if ( is_wp_error( $response ) ) {
		error_log( 'NeoWeaver [upsert_cyber]: ' . $response->get_error_message() );
	}
}
