<?php
/**
 * NeoWeaver – Character data endpoints
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NW_UPLOADS_BASE' ) ) {
	define( 'NW_UPLOADS_BASE', trailingslashit( home_url( '/wp-content/uploads/' ) ) );
}

/**
 * Helper: decode jsonb-like arrays safely.
 */
function nw_decode_jsonb_array( $value ): array {
	if ( is_array( $value ) ) {
		return array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					array_filter( $value, 'is_scalar' )
				)
			)
		);
	}

	if ( is_string( $value ) && '' !== $value ) {
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						array_filter( $decoded, 'is_scalar' )
					)
				)
			);
		}
	}

	return [];
}

/**
 * Helper: resolve Supabase storage / uploads URLs if needed.
 */
function nw_normalize_single_img_url( $url ): string {
	$url = is_string( $url ) ? trim( $url ) : '';
	if ( '' === $url ) {
		return '';
	}

	// Full URL already.
	if ( preg_match( '#^https?://#i', $url ) ) {
		return esc_url_raw( $url );
	}

	$url = ltrim( $url, '/' );

	// WordPress uploads path stored bare.
	if ( 0 === strpos( $url, 'wp-content/uploads/' ) ) {
		return esc_url_raw( home_url( '/' . $url ) );
	}

	// Fallback: treat as relative to uploads base.
	return esc_url_raw( NW_UPLOADS_BASE . $url );
}

function nw_resolve_img_urls( array $rows ): array {
	foreach ( $rows as &$row ) {
		if ( array_key_exists( 'img_url', $row ) ) {
			$row['img_url'] = nw_normalize_single_img_url( $row['img_url'] 
