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
 *   - [tw_tags char="<uuid>"]               — shortcode (display)
 *   - wp_ajax_tw_get_tags                   — JSON endpoint for JS consumers
 *
 * The view returns rows with at minimum:
 *   character_id, label, category, color, source_type, ai_instructions
 *
 * Tags are normalised to the form "#Label" (trimmed, unique).
 */

// ============================================================
// HELPER: fetch + normalise tags
// ============================================================

if ( ! function_exists( 'tw_get_all_active_tags' ) ) {

	/**
	 * Return an array of normalised tag strings for $character_id.
	 *
	 * BUG-FIX: The parameter was typed `int`, causing PHP to silently coerce
	 * any UUID string argument to 0 before the function body even ran. The
	 * Supabase filter then became character_id=eq.0 and returned an empty
	 * array for every character. Fixed by accepting mixed and sanitizing with
	 * the project-wide UUID-safe preg_replace pattern.
	 *
	 * @param  string|int $character_id  cyber_characters.id (UUID or integer)
	 * @return string[]
	 */
	function tw_get_all_active_tags( $character_id ): array {
		// UUID-safe sanitization — strips everything except alphanumerics and
		// hyphens, which covers both UUID v4 strings and legacy integer IDs.
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $character_id );

		if ( empty( $safe_id ) ) {
			return [];
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return [];
		}

		$query = http_build_query( [
			'select'       => 'label,category,color,source_type,ai_instructions',
			'character_id' => 'eq.' . $safe_id,
		] );

		// Prefer shared helpers; fall back to inline construction.
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
// SHORTCODE: [tw_tags char="<uuid>"]
// ============================================================

if ( ! shortcode_exists( 'tw_tags' ) ) {

	add_shortcode(
		'tw_tags',
		function ( array $atts ): string {
			$atts    = shortcode_atts( [ 'char' => '' ], $atts, 'tw_tags' );
			// UUID-safe: strip non-alphanumeric/hyphen characters.
			$char_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $atts['char'] );

			if ( empty( $char_id ) ) {
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
// AJAX: tw_get_tags
// ============================================================

if ( ! function_exists( 'tw_ajax_get_tags' ) ) {

	// BUG-FIX: was registered on both wp_ajax_ and wp_ajax_nopriv_ with no
	// authentication or nonce check, exposing the full Echo tag list (statuses,
	// injuries, buffs) to any unauthenticated caller who knows a character_id.
	// Fixed: removed wp_ajax_nopriv_ registration and added nonce + login check.
	add_action( 'wp_ajax_tw_get_tags', 'tw_ajax_get_tags' );

	/**
	 * Returns the tag array as a JSON success response.
	 *
	 * Request params (GET or POST):
	 *   character_id  (string, required) — UUID of cyber_characters.id
	 *   nonce         (string, required) — tw_ajax_nonce
	 *
	 * Success: { "success": true,  "data": ["#Hacker", "#Cybernetic", ...] }
	 * Error:   { "success": false, "data": "..." }
	 */
	function tw_ajax_get_tags(): void {
		// Nonce verification.
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Security check failed' );
			return;
		}

		// Must be logged in.
		if ( ! get_current_user_id() ) {
			wp_send_json_error( 'Not logged in' );
			return;
		}

		// UUID-safe sanitization of character_id.
		$char_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_REQUEST['character_id'] ?? '' ) );

		if ( empty( $char_id ) ) {
			wp_send_json_error( 'No character ID' );
			return;
		}

		wp_send_json_success( tw_get_all_active_tags( $char_id ) );
	}
}
