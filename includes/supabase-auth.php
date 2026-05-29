<?php
/**
 * NeoWeaver — Supabase Auth Bridge
 *
 * WordPress ↔ Supabase auth handshake:
 *  1. Na logowaniu WP: tworzy/znajduje użytkownika w auth.users + cyber_users.
 *  2. Generuje magic link przez Admin API, wymienia hashed_token na access_token.
 *     Hostinger Supabase zwraca hashed_token w root body (nie w properties).
 *  3. Cachuje access_token + refresh_token w transiencie WP (55 min).
 *     Przy kolejnych żądaniach odświeża token zamiast tworzyć nową sesję.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Hooks ─────────────────────────────────────────────────────────────

add_action( 'wp_login', 'tw_supabase_on_wp_login', 10, 2 );
function tw_supabase_on_wp_login( string $user_login, WP_User $user ): void {
	tw_supabase_provision_user( $user->ID, $user->user_email );
}

add_action( 'init', 'tw_supabase_ensure_token_for_current_user' );
function tw_supabase_ensure_token_for_current_user(): void {
	if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
		return;
	}
	$user = wp_get_current_user();

	// Szybki short-circuit: jeśli token istnieje — nic nie rób.
	if ( tw_supabase_get_cached_token( $user->ID ) ) {
		return;
	}

	// Blokada przeciw równoległym requestóm: prowizjonuj najwyżej raz na 5 minut.
	// Bez tego każdy request po wygaśnięciu transientu odpala 2-3 HTTP calls do Supabase.
	$lock_key = 'tw_prov_lock_' . $user->ID;
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

	tw_supabase_provision_user( $user->ID, $user->user_email );
}

// ─── Core ─────────────────────────────────────────────────────────────────

function tw_supabase_provision_user( int $wp_user_id, string $email ): ?string {
	$supabase_uid = tw_supabase_get_or_create_auth_user( $wp_user_id, $email );
	if ( ! $supabase_uid ) {
		return null;
	}

	// Spróbuj odświeżyć istniejącą sesję.
	$token = tw_supabase_refresh_token_if_possible( $wp_user_id );
	if ( ! $token ) {
		$token = tw_supabase_fetch_token_via_magiclink( $email, $wp_user_id );
	}

	// Po udanym prowizjonowaniu usuń blokadę — następny request użyje tokena z cache.
	delete_transient( 'tw_prov_lock_' . $wp_user_id );

	return $token;
}

function tw_supabase_get_cached_token( int $wp_user_id ): ?string {
	$cached = get_transient( 'tw_supa_jwt_' . $wp_user_id );
	return ( is_array( $cached ) && ! empty( $cached['access_token'] ) )
		? $cached['access_token']
		: null;
}

function tw_supabase_get_current_user_token(): ?string {
	if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
		return null;
	}
	return tw_supabase_get_cached_token( get_current_user_id() );
}

// ─── Token via generate_link + verify ──────────────────────────────────────────

/**
 * Hostinger Supabase zwraca generate_link z hashed_token w ROOT body:
 *   { "hashed_token": "...", "action_link": "...", ... }
 * NIE w properties.token_hash jak nowsze wersje Supabase.
 *
 * verify używa tego jako token_hash w body requestu.
 */
function tw_supabase_fetch_token_via_magiclink( string $email, int $wp_user_id ): ?string {
	// Krok 1: generate_link.
	$gen_url = trailingslashit( tw_supabase_url() ) . 'auth/v1/admin/generate_link';
	$gen = wp_remote_post( $gen_url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [
			'type'  => 'magiclink',
			'email' => $email,
		] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $gen ) ) {
		error_log( 'NeoWeaver [generate_link]: ' . $gen->get_error_message() );
		return null;
	}

	$gcode = wp_remote_retrieve_response_code( $gen );
	$gbody = json_decode( wp_remote_retrieve_body( $gen ), true );

	if ( $gcode !== 200 ) {
		error_log( 'NeoWeaver [generate_link]: HTTP ' . $gcode . ' — ' . wp_remote_retrieve_body( $gen ) );
		return null;
	}

	// Hostinger zwraca hashed_token bezpośrednio w root body.
	$hashed_token = $gbody['hashed_token'] ?? null;

	if ( ! $hashed_token ) {
		error_log( 'NeoWeaver [generate_link]: brak hashed_token. Keys: ' . implode( ', ', array_keys( $gbody ) ) );
		return null;
	}

	// Krok 2: verify — wymień hashed_token na access_token.
	$verify = wp_remote_post(
		trailingslashit( tw_supabase_url() ) . 'auth/v1/verify',
		[
			'headers' => [
				'apikey'       => tw_supabase_anon_key(),
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( [
				'type'       => 'magiclink',
				'token_hash' => $hashed_token,
			] ),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $verify ) ) {
		error_log( 'NeoWeaver [verify]: ' . $verify->get_error_message() );
		return null;
	}

	$vcode = wp_remote_retrieve_response_code( $verify );
	$vbody = json_decode( wp_remote_retrieve_body( $verify ), true );

	if ( $vcode !== 200 || empty( $vbody['access_token'] ) ) {
		error_log( 'NeoWeaver [verify]: HTTP ' . $vcode . ' — ' . wp_remote_retrieve_body( $verify ) );
		return null;
	}

	set_transient(
		'tw_supa_jwt_' . $wp_user_id,
		[
			'access_token'  => $vbody['access_token'],
			'refresh_token' => $vbody['refresh_token'] ?? '',
		],
		55 * MINUTE_IN_SECONDS
	);

	error_log( 'NeoWeaver [auth]: token OK dla wp_user_id=' . $wp_user_id );

	return $vbody['access_token'];
}

/**
 * Odświeża token przez refresh_token.
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

// ─── Supabase auth.users provisioning ─────────────────────────────────────────────

function tw_supabase_get_or_create_auth_user( int $wp_user_id, string $email ): ?string {
	$existing = tw_supabase_find_supabase_uid_by_wp_id( $wp_user_id );
	if ( $existing ) {
		return $existing;
	}

	$supabase_uid = tw_supabase_find_auth_user_by_email( $email );

	if ( ! $supabase_uid ) {
		$supabase_uid = tw_supabase_create_auth_user( $wp_user_id, $email );
	} else {
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
		error_log( 'NeoWeaver [create_user]: HTTP ' . $code );
		return null;
	}

	return $body['id'] ?? null;
}

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
	// ?on_conflict=id jest wymagany przez PostgREST gdy używamy resolution=merge-duplicates.
	// Bez tego parametru Supabase ignoruje Prefer lub rzuca błąd constraint.
	$url = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_users?on_conflict=id';

	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'resolution=merge-duplicates,return=minimal',
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
