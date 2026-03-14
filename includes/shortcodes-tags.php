<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TAG SYSTEM
 *
 * Fetches all active tags for a character from the Supabase view
 * cyber_character_complete_tags and exposes them via:
 *
 *   - tw_get_all_active_tags( $character_id ) — PHP helper
 *   - [tw_tags char="11"]               — shortcode (display)
 *   - wp_ajax_tw_get_tags               — JSON endpoint for JS consumers
 *
 * The view is expected to return rows with at minimum:
 *   character_id, label, category, color, source_type, ai_instructions
 *
 * Tags are normalised to the form "#Label" (lowercase-trimmed, unique).
 */

// ============================================================
// HELPER: fetch + normalise tags
// ============================================================

if ( ! function_exists( 'tw_get_all_active_tags' ) ) {

	/**
	 * Return an array of normalised tag strings for $character_id.
	 *
	 * Each element looks like "#Hacker", "#Cybernetic", etc.
	 * Returns an empty array on any error or when no tags exist.
	 *
	 * Uses tw_supa_url() / tw_supa_headers() from ajax-handlers.php when
	 * available (avoids duplicate URL-building logic). Falls back to
	 * inline construction so the function works standalone.
	 *
	 * @param int $character_id  cyber_characters.id
	 * @return string[]
	 */
	function tw_get_all_active_tags( int $character_id ): array {
		if ( ! $character_id ) {
			return [];
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return [];
		}

		$query = http_build_query( [
			'select'       => 'label,category,color,source_type,ai_instructions',
			'character_id' => 'eq.' . $character_id,
		] );

		// Prefer shared helpers; construct inline if not yet loaded.
		if ( function_exists( 'tw_supa_url' ) && function_exists( 'tw_supa_headers' ) ) {
			$url     = tw_supa_url( 'cyber_character_complete_tags', $query );
			$headers = tw_supa_headers();
		} else {
			$key     = tw_supabase_anon_key();
			$url     = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_character_complete_tags?' . $query;
			$headers = [
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
			];
		}

		$resp = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 10 ] );

		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$labels = [];
		foreach ( $rows as $row ) {
			$label = trim( (string) ( $row['label'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$labels[] = '#' . $label;
		}

		return array_values( array_unique( $labels ) );
	}
}

// ============================================================
// SHORTCODE: [tw_tags char="11"]
// ============================================================

if ( ! shortcode_exists( 'tw_tags' ) ) {

	/**
	 * Renders faction tags for a character as a styled <div>.
	 *
	 * Attributes:
	 *   char  (int, required) — cyber_characters.id
	 *
	 * Output example:
	 *   <div class="cyber-tags" style="color:#adff00">#Hacker, #Cybernetic</div>
	 */
	add_shortcode(
		'tw_tags',
		function ( array $atts ): string {
			$atts    = shortcode_atts( [ 'char' => 0 ], $atts, 'tw_tags' );
			$char_id = (int) $atts['char'];

			if ( ! $char_id ) {
				return '<span class="tw-error" style="color:red">tw_tags: missing char attribute</span>';
			}

			$tags = tw_get_all_active_tags( $char_id );

			if ( empty( $tags ) ) {
				return '<div class="cyber-tags" style="color:#adff00">No tags</div>';
			}

			return '<div class="cyber-tags" style="color:#adff00">'
				. esc_html( implode( ', ', $tags ) )
				. '</div>';
		}
	);
}

// ============================================================
// AJAX: tw_get_tags  (GET or POST, supports both priv + nopriv)
// ============================================================

if ( ! function_exists( 'tw_ajax_get_tags' ) ) {

	add_action( 'wp_ajax_tw_get_tags',        'tw_ajax_get_tags' );
	add_action( 'wp_ajax_nopriv_tw_get_tags', 'tw_ajax_get_tags' );

	/**
	 * Returns the tag array as a JSON success response.
	 *
	 * Request params (GET or POST):
	 *   character_id  (int, required)
	 *
	 * Success: { "success": true, "data": ["#Hacker", "#Cybernetic", ...] }
	 * Error:   { "success": false, "data": "No character ID" }
	 */
	function tw_ajax_get_tags(): void {
		$char_id = (int) ( $_REQUEST['character_id'] ?? 0 );

		if ( ! $char_id ) {
			wp_send_json_error( 'No character ID' );
		}

		wp_send_json_success( tw_get_all_active_tags( $char_id ) );
	}
}
