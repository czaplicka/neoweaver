<?php
/**
 * NW_Transient_Cache — reusable transient cache for Supabase GET-all calls.
 *
 * Usage:
 *   use NW_Transient_Cache;
 *   $rows = $this->cached_get_all( $this->table, 'created_at' );
 *   $this->bust_cache( $this->table );
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NW_CACHE_TTL' ) ) {
	define( 'NW_CACHE_TTL', 120 );
}

trait NW_Transient_Cache {

	/**
	 * Return all rows for $table, served from a WP transient when fresh.
	 *
	 * @param string $table     Supabase table name.
	 * @param string $order_col Column to order by.
	 * @return array
	 */
	private function cached_get_all( string $table, string $order_col = 'created_at' ): array {
		$key    = $this->cache_key( $table, $order_col );
		$cached = get_transient( $key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->nw_cache_fetch_all( $table, $order_col );

		if ( ! isset( $rows['error'] ) && is_array( $rows ) ) {
			set_transient( $key, $rows, NW_CACHE_TTL );
		}

		return $rows;
	}

	/**
	 * Delete common transients for $table.
	 *
	 * @param string $table Supabase table name.
	 */
	private function bust_cache( string $table ): void {
		foreach ( [ 'created_at', 'name', 'sort_order', 'title', 'id' ] as $col ) {
			delete_transient( $this->cache_key( $table, $col ) );
		}
	}

	/**
	 * Fetch all rows from Supabase with graceful fallback.
	 *
	 * @param string $table     Supabase table name.
	 * @param string $order_col Column to order by.
	 * @return array
	 */
	private function nw_cache_fetch_all( string $table, string $order_col ): array {
		if ( class_exists( 'NW_Supabase' ) && method_exists( 'NW_Supabase', 'get_all' ) ) {
			$rows = NW_Supabase::get_all( $table, $order_col );
			return is_array( $rows ) ? $rows : [ 'error' => 'NW_Supabase::get_all returned invalid response.' ];
		}

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				$table,
				[
					'select' => '*',
					'order'  => $order_col . '.asc',
				]
			);

			if ( ! is_array( $rows ) ) {
				return [ 'error' => 'tw_supabase_get returned invalid response.' ];
			}

			if ( isset( $rows['code'], $rows['message'] ) ) {
				return [ 'error' => $rows['message'] ];
			}

			return $rows;
		}

		return [ 'error' => 'No Supabase client available.' ];
	}

	/**
	 * Build cache key.
	 *
	 * @param string $table
	 * @param string $order_col
	 * @return string
	 */
	private function cache_key( string $table, string $order_col ): string {
		return 'nw_cache_' . $table . '_' . substr( md5( $order_col ), 0, 8 );
	}
}
