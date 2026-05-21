<?php
/**
 * NeoWeaver — Supabase Auth Bridge
 *
 * WordPress ↔ Supabase auth handshake:
 *  1. Na logowaniu WP: tworzy/znajduje użytkownika w auth.users + cyber_users.
 *  2. Pobiera token od Supabase Admin API (obsługuje nowe klucze ECC/P-256).
 *  3. Cachuje token w transiencie WP i przekazuje do JS przez twAdventureData.
 *
 * NIE wymaga TW_SUPABASE_JWT_SECRET — token generuje Supabase po stronie serwera.
 * Wymaga tylko TW_SUPABASE_SERVICE_KEY w wp-config.php.
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

function tw_supabase_provision_user( int $wp_user_id, string $email ): ?string {
	$supabase_uid = tw_supabase_get_or_create_auth_user( $wp_user_id, $email );
	if ( ! $supabase_uid ) {
		return null;
	}
	$token = tw_supabase_fetch_token_for_uid( $supabase_uid );
	if ( $token ) {
		set_transient( 'tw_supa_jwt_' . $wp_user_id, $token, 55 * MINUTE_IN_SECONDS );
	}
	return $token;
}

function tw_supabase_get_cached_token( int $wp_user_id ): ?string {
	$token = get_transient( 'tw_supa_jwt_' . $wp_user_id );
	return $token ?: null;
}

function tw_supabase_get_current_user_token(): ?string {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	return tw_supabase_get_cached_token( get_current_user_id() );
}

// ─── Token via Admin API (obsługuje ECC P-256 i HS256) ───────────────────────

/**
 * Prosi Supabase o wystawienie sesji (access_token) dla danego UUID.
 * Supabase podpisuje token swoim aktywnym kluczem (ECC lub HS256).
 */
function tw_supabase_fetch_token_for_uid( string $supabase_uid ): ?string {
	$url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/users/' . $supabase_uid . '/token';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [ 'expiresIn' => 3600 ] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NeoWeaver: tw_supabase_fetch_token_for_uid error: ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $body['access_token'] ) ) {
		error_log( 'NeoWeaver: fetch_token HTTP ' . $code . ' — ' . wp_json_encode( $body ) );
		return null;
	}

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
			'user_metadata' => [ 'wp_user_id' => $wp_user_id ],
		] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return $body['id'] ?? null;
}

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
