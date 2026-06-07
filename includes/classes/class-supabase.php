<?php
/**
 * NW_Supabase — static facade over tw_supabase_* helpers.
 *
 * Races, skills, api-endpoints i trait-transient-cache wołają
 * NW_Supabase::get_all(), ::get_one(), ::insert(), ::patch(), ::delete().
 *
 * Kontrakt zwracanych wartości (stabilny dla callerów):
 *   get_all()  → array wierszy LUB ['error' => string]
 *   get_one()  → array wiersza  LUB ['error' => string]
 *   insert()   → array wierszy  LUB ['error' => string]
 *               (użyj $result[0]['id'] bezpośrednio po udanym insercie)
 *   patch()    → ['ok' => bool, 'code' => int, 'data' => mixed, 'error' => ?string]
 *   delete()   → ['ok' => bool, 'code' => int, 'data' => mixed, 'error' => ?string]
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

	// --------------------------------------------------------
	// PRYWATNY HELPER: normalizuje WP_Error → ['error' => string]
	// Używany przez read i insert żeby zachować spójny kontrakt.
	// --------------------------------------------------------

	private static function wp_error_to_array( WP_Error $error ): array {
		return [ 'error' => $error->get_error_message() ];
	}

	// --------------------------------------------------------
	// PRYWATNY HELPER: normalizuje WP_Error → wynik write-metod
	// ['ok'=>false, 'code'=>int, 'data'=>null, 'error'=>string]
	// --------------------------------------------------------

	private static function wp_error_to_result( WP_Error $error ): array {
		$data = $error->get_error_data();
		return [
			'ok'    => false,
			'code'  => is_array( $data ) ? ( $data['status'] ?? 0 ) : 0,
			'data'  => is_array( $data ) ? ( $data['data'] ?? null ) : null,
			'error' => $error->get_error_message(),
		];
	}

	// --------------------------------------------------------
	// READ
	// --------------------------------------------------------

	public static function get_all( string $table, string $order_col = 'created_at' ): array {
		$safe_order = preg_replace( '/[^a-zA-Z0-9_]/', '', $order_col );

		$rows = tw_supabase_get( $table, [
			'select' => '*',
			'order'  => $safe_order . '.asc',
		] );

		if ( is_wp_error( $rows ) ) {
			return self::wp_error_to_array( $rows );
		}

		// Supabase zwraca błąd API jako obiekt {'code':…,'message':…} — przy
		// starszych wersjach helpera mógł trafić tu jako tablica asocjacyjna.
		if ( isset( $rows['code'], $rows['message'] ) ) {
			return [ 'error' => $rows['message'] ];
		}

		return $rows;
	}

	public static function get_one( string $table, string $id ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );

		$rows = tw_supabase_get( $table, [
			'select' => '*',
			'id'     => 'eq.' . $safe_id,
			'limit'  => '1',
		] );

		if ( is_wp_error( $rows ) ) {
			return self::wp_error_to_array( $rows );
		}

		if ( isset( $rows['code'], $rows['message'] ) ) {
			return [ 'error' => $rows['message'] ];
		}

		return $rows[0] ?? [ 'error' => 'Not found.' ];
	}

	// --------------------------------------------------------
	// WRITE
	// --------------------------------------------------------

	/**
	 * Wstawia wiersz do tabeli i zwraca tablice wierszy (jak get_all).
	 *
	 * tw_supabase_request() zwraca wrapper ['ok','code','data'] — rozpakowujemy
	 * 'data' żeby callerzy mogli robić $result[0]['id'] bezpośrednio.
	 * Błąd → ['error' => string], spójnie z get_all()/get_one().
	 */
	public static function insert( string $table, array $payload ): array {
		$result = tw_supabase_request(
			'POST',
			$table,
			[ 'select' => '*' ],
			$payload,
			[ 'prefer' => 'return=representation' ]
		);

		if ( is_wp_error( $result ) ) {
			return self::wp_error_to_array( $result );
		}

		// Rozpakuj wrapper tw_supabase_request → surowa tablica wierszy.
		if ( isset( $result['data'] ) ) {
			$rows = $result['data'];
			if ( is_array( $rows ) ) {
				return $rows;
			}
			// data może być null gdy Supabase zwraca 204 bez treści
			return [];
		}

		// Stary kształt helpera — bezpośrednio tablica wierszy
		if ( isset( $result[0] ) || [] === $result ) {
			return $result;
		}

		return [ 'error' => 'Unexpected response shape from Supabase.' ];
	}

	public static function patch( string $table, string $id, array $payload ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );

		$result = tw_supabase_request(
			'PATCH',
			$table,
			[
				'id'     => 'eq.' . $safe_id,
				'select' => '*',
			],
			$payload,
			[ 'prefer' => 'return=representation' ]
		);

		return is_wp_error( $result )
			? self::wp_error_to_result( $result )
			: $result;
	}

	public static function delete( string $table, string $id ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $id );

		$result = tw_supabase_request(
			'DELETE',
			$table,
			[ 'id' => 'eq.' . $safe_id ],
			null
		);

		return is_wp_error( $result )
			? self::wp_error_to_result( $result )
			: $result;
	}
}
