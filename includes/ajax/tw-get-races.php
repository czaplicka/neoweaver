<?php
/**
 * AJAX handlers: neoweaver_get_races & neoweaver_get_subraces
 *
 * NOTE: These handlers are intentionally removed from this file.
 * They are now registered exclusively in api-endpoints-character-data.php
 * to avoid duplicate action registration (PHP fatal: cannot redeclare function).
 *
 * The helpers tw_races_headers(), tw_resolve_race_img(), and tw_race_row_to_card()
 * are kept here for backward compatibility with any code that calls them directly,
 * but the wp_ajax_ hooks are not re-registered.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Base URL for resolving relative img_url values stored in cyber_races.
if ( ! defined( 'TW_RACE_UPLOADS_BASE' ) ) {
	define( 'TW_RACE_UPLOADS_BASE', 'https://neoweaver.nieodparady.pl/wp-content/uploads/' );
}

if ( ! function_exists( 'tw_races_headers' ) ) {
	function tw_races_headers(): array {
		$key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
		return [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
		];
	}
}

if ( ! function_exists( 'tw_resolve_race_img' ) ) {
	function tw_resolve_race_img( string $img_url ): string {
		if ( empty( $img_url ) ) {
			return '';
		}
		if ( strpos( $img_url, 'http' ) === 0 ) {
			return esc_url_raw( $img_url );
		}
		return esc_url_raw( TW_RACE_UPLOADS_BASE . ltrim( $img_url, '/' ) );
	}
}

if ( ! function_exists( 'tw_race_row_to_card' ) ) {
	function tw_race_row_to_card( array $row ): array {
		$tags = $row['tags'] ?? [];
		if ( is_string( $tags ) ) {
			$tags = json_decode( $tags, true ) ?: [];
		}
		$bonus = is_array( $tags ) && ! empty( $tags ) ? implode( ' · ', $tags ) : '';

		return [
			'key'   => sanitize_text_field( $row['name'] ?? '' ),
			'id'    => sanitize_text_field( $row['id']   ?? '' ),
			'label' => sanitize_text_field( $row['name'] ?? '' ),
			'img'   => tw_resolve_race_img( $row['img_url'] ?? '' ),
			'icon'  => '',
			'bonus' => sanitize_text_field( $bonus ),
		];
	}
}
