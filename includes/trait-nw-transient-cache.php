<?php
/**
 * NW_Transient_Cache — reusable 2-minute transient cache for Supabase GET-all calls.
 *
 * Usage in any admin class:
 *
 *   use NW_Transient_Cache;
 *
 * Then replace direct NW_Supabase::get_all() calls with:
 *
 *   $rows = $this->cached_get_all( $this->table, 'created_at' );
 *
 * Invalidate on save/delete:
 *
 *   $this->bust_cache( $this->table );
 *
 * Transient TTL: NW_CACHE_TTL constant (default 120 seconds).
 * Key format:    nw_cache_{table}_{md5(sort_column)}
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Default TTL: 2 minutes. Override in wp-config: define( 'NW_CACHE_TTL', 300 ); */
if ( ! defined( 'NW_CACHE_TTL' ) ) {
    define( 'NW_CACHE_TTL', 120 );
}

trait NW_Transient_Cache {

    /**
     * Return all rows for $table, served from a WP transient when fresh.
     *
     * @param string $table      Supabase table name (e.g. 'cyber_classes').
     * @param string $order_col  Column to order by (default 'created_at').
     * @return array             Row array or ['error' => string].
     */
    private function cached_get_all( string $table, string $order_col = 'created_at' ): array {
        $key    = $this->cache_key( $table, $order_col );
        $cached = get_transient( $key );

        if ( false !== $cached ) {
            return $cached;
        }

        $rows = NW_Supabase::get_all( $table, $order_col );

        if ( ! isset( $rows['error'] ) ) {
            set_transient( $key, $rows, NW_CACHE_TTL );
        }

        return $rows;
    }

    /**
     * Delete all transients for $table (call after save or delete).
     *
     * @param string $table Supabase table name.
     */
    private function bust_cache( string $table ): void {
        // Bust the default sort key and any plausible alternatives.
        foreach ( [ 'created_at', 'name', 'sort_order', 'title', 'id' ] as $col ) {
            delete_transient( $this->cache_key( $table, $col ) );
        }
    }

    /** @internal */
    private function cache_key( string $table, string $order_col ): string {
        return 'nw_cache_' . $table . '_' . substr( md5( $order_col ), 0, 8 );
    }
}
