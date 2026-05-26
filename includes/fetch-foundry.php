<?php
/**
 * Fetch foundry data for a character.
 * Returns array of objects with: instance_id, name, level, duplicate_count.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function fetch_foundry_data( string $character_id ) {
    if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
        return new WP_Error( 'missing_helper', 'tw_supabase_get_admin not available.' );
    }

    $rows = tw_supabase_get_admin(
        'cyber_character_deck',
        [
            'character_id' => 'eq.' . $character_id,
            'select'       => 'id,deck_id,current_level,cyber_deck(name)',
            'order'        => 'deck_id.asc',
        ]
    );

    if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
        return $rows instanceof WP_Error ? $rows : array();
    }

    // Grupuj po deck_id — pierwsza karta = instancja do upgrade, reszta = duplikaty
    $grouped = array();
    foreach ( $rows as $row ) {
        $deck_id = (int) ( $row->deck_id ?? 0 );
        if ( ! isset( $grouped[ $deck_id ] ) ) {
            $grouped[ $deck_id ] = array(
                'instance_id'     => (string) ( $row->id ?? '' ),
                'name'            => (string) ( $row->cyber_deck->name ?? '[UNKNOWN]' ),
                'level'           => (int) ( $row->current_level ?? 1 ),
                'duplicate_count' => 0,
            );
        } else {
            $grouped[ $deck_id ]['duplicate_count']++;
        }
    }

    // Mapuj na obiekty których oczekuje shortcode
    $result = array();
    foreach ( $grouped as $item ) {
        $obj                  = new stdClass();
        $obj->instance_id     = $item['instance_id'];
        $obj->name            = $item['name'];
        $obj->level           = $item['level'];
        $obj->duplicate_count = $item['duplicate_count'];
        $result[]             = $obj;
    }

    return $result;
}
