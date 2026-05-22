<?php
/**
 * NeoWeaver Admin — Base Class
 *
 * Wszystkie klasy admin dziedziczą stąd.
 * Zawiera supa() i sk() — service key omija RLS w Supabase.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class NW_Base_Admin {

	/* ---------------------------------------------------------------- */
	/*  SERVICE KEY HEADERS (omija RLS — tylko dla admin PHP)           */
	/* ---------------------------------------------------------------- */

	protected function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			return [];
		}
		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	/* ---------------------------------------------------------------- */
	/*  SUPABASE WRAPPER                                                 */
	/* ---------------------------------------------------------------- */

	/**
	 * Normalized Supabase wrapper. Zawsze używa service key.
	 *
	 * Zwraca:
	 * [
	 *   'ok'    => bool,
	 *   'code'  => int,
	 *   'data'  => mixed,
	 *   'error' => string|null,
	 * ]
	 */
	protected function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method  = strtoupper( $method );
		$sk      = $this->sk();
		$headers = array_merge( $sk, $extra_headers );

		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$data = tw_supabase_get( $table, $query, [ 'headers' => $headers ] );

			if ( ! is_array( $data ) ) {
				return [
					'ok'    => false,
					'code'  => 0,
					'data'  => null,
					'error' => 'tw_supabase_get returned non-array',
				];
			}

			if ( isset( $data['code'], $data['message'] ) ) {
				return [
					'ok'    => false,
					'code'  => (int) $data['code'],
					'data'  => null,
					'error' => $data['message'],
				];
			}

			return [
				'ok'    => true,
				'code'  => 200,
				'data'  => $data,
				'error' => null,
			];
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$extra_args = [];

			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}

			if ( ! empty( $headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $headers );
			}

			$res = tw_supabase_request(
				$method,
				$table,
				$query,
				empty( $body ) ? null : $body,
				$extra_args
			);

			$ok   = $res['ok']   ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;

			if ( ! $ok ) {
				$msg = is_array( $data )
					? ( $data['message'] ?? 'Supabase error ' . $code )
					: 'Supabase error ' . $code;

				return [
					'ok'    => false,
					'code'  => $code,
					'data'  => $data,
					'error' => $msg,
				];
			}

			return [
				'ok'    => true,
				'code'  => $code,
				'data'  => $data,
				'error' => null,
			];
		}

		return [
			'ok'    => false,
			'code'  => 0,
			'data'  => null,
			'error' => 'Supabase helper functions not available.',
		];
	}
}
