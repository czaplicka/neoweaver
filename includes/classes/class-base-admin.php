<?php
/**
 * NeoWeaver Admin — Base Class
 *
 * Wspólna logika dla wszystkich ekranów admina.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class NW_Base_Admin {

	/**
	 * Jedyny dozwolony service key dla warstwy admin.
	 */
	protected function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) || ! TW_SUPABASE_SERVICE_KEY ) {
			return [];
		}

		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	/**
	 * Rozdziela endpoint na tabelę i raw query string bez parse_str().
	 *
	 * @return array{0:string,1:string}
	 */
	protected function split_endpoint( string $endpoint ): array {
		$parts = explode( '?', $endpoint, 2 );

		return [
			(string) ( $parts[0] ?? '' ),
			(string) ( $parts[1] ?? '' ),
		];
	}

	/**
	 * Bezpieczny parser query stringów PostgREST.
	 *
	 * Nie używa parse_str(), więc nie psuje składni typu:
	 * - or=(...)
	 * - id=not.in.(...)
	 * - select=*
	 *
	 * @return array<string,string>
	 */
	protected function raw_query_to_array( string $qs ): array {
		$query = [];

		if ( '' === trim( $qs ) ) {
			return $query;
		}

		foreach ( explode( '&', $qs ) as $pair ) {
			$pair = trim( $pair );

			if ( '' === $pair ) {
				continue;
			}

			$chunks = explode( '=', $pair, 2 );
			$key    = rawurldecode( (string) $chunks[0] );
			$value  = isset( $chunks[1] ) ? rawurldecode( (string) $chunks[1] ) : '';

			if ( '' === $key ) {
				continue;
			}

			$query[ $key ] = $value;
		}

		return $query;
	}

	/**
	 * Normalized Supabase wrapper.
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
		$headers = array_merge( $this->sk(), $extra_headers );

		[ $table, $qs ] = $this->split_endpoint( $endpoint );
		$query          = $this->raw_query_to_array( $qs );

		if ( '' === $table ) {
			return [
				'ok'    => false,
				'code'  => 0,
				'data'  => null,
				'error' => 'Missing Supabase table/endpoint.',
			];
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			$request_headers = $headers;

			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$request_headers['Prefer'] = 'return=representation';
			}

			$res = tw_supabase_request(
				$method,
				$table,
				$query,
				empty( $body ) ? null : $body,
				[
					'headers' => $request_headers,
				]
			);

			$ok   = $res['ok'] ?? false;
			$code = (int) ( $res['code'] ?? 0 );
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

		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			$data = tw_supabase_get(
				$table,
				$query,
				[
					'headers' => $headers,
				]
			);

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
					'error' => (string) $data['message'],
				];
			}

			return [
				'ok'    => true,
				'code'  => 200,
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

	protected function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	protected function bust_cache( string $scope ): void {
		delete_transient( $this->get_cache_key( $scope . '_all' ) );
	}

	protected function cached_get_all( string $table, string $order_by = 'created_at', string $order_dir = 'desc' ): array {
		$cache_key = $this->get_cache_key( $table . '_all_' . $order_by . '_' . $order_dir );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$order_dir = strtolower( $order_dir ) === 'asc' ? 'asc' : 'desc';

		$res = $this->supa(
			'GET',
			$table . '?select=*&order=' . rawurlencode( $order_by . '.' . $order_dir )
		);

		if ( ! $res['ok'] ) {
			return [
				'error' => $res['error'] ?? 'Failed to fetch records.',
			];
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );

		return $rows;
	}

	protected function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	protected function parse_tags( string $raw ): array {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return [];
		}

		$tags = array_map(
			static fn( $tag ) => sanitize_text_field( trim( $tag ) ),
			explode( ',', $raw )
		);

		return array_values(
			array_filter(
				array_unique( $tags ),
				static fn( $tag ) => '' !== $tag
			)
		);
	}

	protected function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/',
			$value
		);
	}
}
