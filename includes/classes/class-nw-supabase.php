<?php
/**
 * NW_Supabase — static facade over tw_supabase_* helpers.
 *
 * Races, skills, api-endpoints i trait-transient-cache wołają
 * NW_Supabase::get_all(), ::get_one(), ::insert(), ::patch(), ::delete().
 * Ta klasa tłumaczy te wywołania na istniejące tw_supabase_get()
 * i tw_supabase_request() z supabase-helpers.php.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NW_Supabase' ) ) {
	return;
}

class NW_Supabase {

	/**
	 * Pobiera wszystkie wiersze z tabeli.
	 *
	 * @param string $table     Nazwa tabeli, np. 'cyber_races'.
	 * @param string $order_col Kolumna sortowania.
	 * @return array            Tablica wierszy lub ['error' => string].
	 */
	public static function get_all( string $table, string $order_col = 'created_at' ): array {
		$safe_order = preg_replace( '/[^a-zA-Z0-9_]/', '', $order_col );
		$rows = tw_supabase_get( $table, [
			'select' => '*',
			'order'  => $safe_order . '.asc',
		] );

		if ( ! is_array( $rows ) ) {
			return [ 'error' => 'Invalid response from Supabase.' ];
		}

		// Supabase zwraca błąd jako obiekt z kluczem 'code' i 'message'.
		if ( isset( $rows['code'], $rows['message'] ) ) {
			return [ 'error' => $rows['message'] ];
		}

		return $rows;
	}

	/**
	 * Pobiera jeden wiersz po ID.
	 *
	 * @param string $table Nazwa tabeli.
	 * @param string $id    UUID lub int ID wiersza.
	 * @return array        Wiersz lub ['error' => string].
	 */
	public static function get_one( string $table, string $id ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );
		$rows = tw_supabase_get( $table, [
			'select' => '*',
			'id'     => 'eq.' . $safe_id,
			'limit'  => '1',
		] );

		if ( ! is_array( $rows ) ) {
			return [ 'error' => 'Invalid response from Supabase.' ];
		}

		if ( isset( $rows['code'], $rows['message'] ) ) {
			return [ 'error' => $rows['message'] ];
		}

		return $rows[0] ?? [ 'error' => 'Not found.' ];
	}

	/**
	 * Wstawia nowy wiersz.
	 *
	 * @param string $table   Nazwa tabeli.
	 * @param array  $payload Dane do wstawienia.
	 * @return array          ['ok' => bool, 'code' => int, 'data' => array|null]
	 */
	public static function insert( string $table, array $payload ): array {
		return tw_supabase_request(
			'POST',
			$table,
			[ 'select' => '*' ],
			$payload,
			[
				'headers' => [
					'apikey'        => tw_supabase_anon_key(),
					'Authorization' => 'Bearer ' . tw_supabase_service_key(),
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=representation',
				],
			]
		);
	}

	/**
	 * Aktualizuje istniejący wiersz po ID.
	 *
	 * @param string $table   Nazwa tabeli.
	 * @param string $id      UUID lub int ID wiersza.
	 * @param array  $payload Dane do zaktualizowania.
	 * @return array          ['ok' => bool, 'code' => int, 'data' => array|null]
	 */
	public static function patch( string $table, string $id, array $payload ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );
		return tw_supabase_request(
			'PATCH',
			$table,
			[
				'id'     => 'eq.' . $safe_id,
				'select' => '*',
			],
			$payload,
			[
				'headers' => [
					'apikey'        => tw_supabase_anon_key(),
					'Authorization' => 'Bearer ' . tw_supabase_service_key(),
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=representation',
				],
			]
		);
	}

	/**
	 * Usuwa wiersz po ID.
	 *
	 * @param string $table Nazwa tabeli.
	 * @param string $id    UUID lub int ID wiersza.
	 * @return array        ['ok' => bool, 'code' => int, 'data' => array|null]
	 */
	public static function delete( string $table, string $id ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );
		return tw_supabase_request(
			'DELETE',
			$table,
			[ 'id' => 'eq.' . $safe_id ],
			null,
			[
				'headers' => [
					'apikey'        => tw_supabase_anon_key(),
					'Authorization' => 'Bearer ' . tw_supabase_service_key(),
					'Content-Type'  => 'application/json',
				],
			]
		);
	}
}
