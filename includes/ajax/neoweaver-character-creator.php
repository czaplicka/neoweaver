<?php
/**
 * NeoWeaver — Character Creator AJAX Handlers
 *
 * Registers WordPress AJAX actions called by tw-character-creator.js:
 *   neoweaver_get_races          — fetch cyber_races from Supabase
 *   neoweaver_get_subraces       — fetch cyber_subraces filtered by parent race key
 *   neoweaver_get_classes        — fetch cyber_classes from Supabase
 *   neoweaver_get_nodes          — fetch cyber_nodes (worlds) available to current user
 *   neoweaver_create_character   — save new character to cyber_characters
 *
 * Reads Supabase credentials via tw_supabase_url() / tw_supabase_anon_key()
 * (defined in wp-config through the Hostinger Supabase integration).
 *
 * All handlers require a logged-in user and a valid nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Shared nonce action ──────────────────────────────────────────────────────
// JS sends nonce generated with 'neoweaver_char_creator' action.
// Falls back to 'tw_ajax_nonce' if the old nonce is used.
define( 'NW_CHAR_NONCE_ACTION', 'neoweaver_char_creator' );

/**
 * Verify nonce — accepts both new and legacy nonce action strings.
 */
function nw_char_verify_nonce(): bool {
	$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
	return wp_verify_nonce( $nonce, NW_CHAR_NONCE_ACTION )
		|| wp_verify_nonce( $nonce, 'tw_ajax_nonce' );
}

// ─── Helper: Supabase GET ─────────────────────────────────────────────────────
function nw_supa_get( string $table, string $query = '' ): ?array {
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return null;
	}
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table . ( $query ? '?' . $query : '' );
	$key      = tw_supabase_anon_key();
	$response = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		],
		'timeout' => 15,
	] );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

// ─── Helper: Supabase POST ────────────────────────────────────────────────────
function nw_supa_post( string $table, array $body ): ?array {
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return null;
	}
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table;
	$key      = tw_supabase_anon_key();
	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		],
		'body'    => wp_json_encode( $body ),
		'timeout' => 15,
	] );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

// ─── Helper: normalise tags column (jsonb array or comma string) ──────────────
function nw_normalise_tags( $raw ): array {
	if ( is_array( $raw ) ) {
		return array_values( array_filter( $raw ) );
	}
	if ( is_string( $raw ) && $raw !== '' ) {
		// Could be a JSON string stored as text
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( $decoded ) );
		}
		// Comma-separated fallback
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}
	return [];
}

// ─── 1. GET RACES ─────────────────────────────────────────────────────────────
if ( ! function_exists( 'neoweaver_get_races_handler' ) ) {
	add_action( 'wp_ajax_neoweaver_get_races',        'neoweaver_get_races_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_get_races_handler' );

	function neoweaver_get_races_handler(): void {
		$rows = nw_supa_get( 'cyber_races', 'select=id,key,name,description,img_url,icon_slug,tags,bonus&order=name.asc' );

		if ( $rows === null ) {
			wp_send_json_error( 'Supabase connection failed' );
			return;
		}

		$out = array_map( function ( $row ) {
			return [
				'key'   => $row['key']         ?? sanitize_key( $row['name'] ?? '' ),
				'label' => $row['name']         ?? '',
				'img'   => $row['img_url']      ?? '',
				'icon'  => $row['icon_slug']    ?? '&#10067;',
				'desc'  => $row['description']  ?? '',
				'bonus' => $row['bonus']         ?? '',
				'tags'  => nw_normalise_tags( $row['tags'] ?? [] ),
			];
		}, $rows );

		wp_send_json_success( $out );
	}
}

// ─── 2. GET SUBRACES ─────────────────────────────────────────────────────────
if ( ! function_exists( 'neoweaver_get_subraces_handler' ) ) {
	add_action( 'wp_ajax_neoweaver_get_subraces',        'neoweaver_get_subraces_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_get_subraces_handler' );

	function neoweaver_get_subraces_handler(): void {
		$parent = sanitize_key( wp_unslash( $_POST['parent'] ?? '' ) );

		if ( ! $parent ) {
			wp_send_json_error( 'Missing parent race key' );
			return;
		}

		$rows = nw_supa_get(
			'cyber_subraces',
			'select=id,key,name,description,img_url,tags&parent_key=eq.' . rawurlencode( $parent ) . '&order=name.asc'
		);

		if ( $rows === null ) {
			wp_send_json_error( 'Supabase connection failed' );
			return;
		}

		$out = array_map( function ( $row ) {
			return [
				'key'   => $row['key']        ?? sanitize_key( $row['name'] ?? '' ),
				'label' => $row['name']        ?? '',
				'img'   => $row['img_url']     ?? '',
				'desc'  => $row['description'] ?? '',
				'tags'  => nw_normalise_tags( $row['tags'] ?? [] ),
			];
		}, $rows );

		wp_send_json_success( $out );
	}
}

// ─── 3. GET CLASSES ──────────────────────────────────────────────────────────
if ( ! function_exists( 'neoweaver_get_classes_handler' ) ) {
	add_action( 'wp_ajax_neoweaver_get_classes',        'neoweaver_get_classes_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_get_classes_handler' );

	function neoweaver_get_classes_handler(): void {
		$rows = nw_supa_get( 'cyber_classes', 'select=id,name,description,img_url,icon_slug,tags&order=name.asc' );

		if ( $rows === null ) {
			wp_send_json_error( 'Supabase connection failed' );
			return;
		}

		$out = array_map( function ( $row ) {
			return [
				'id'          => $row['id']           ?? '',
				'name'        => $row['name']          ?? '',
				'description' => $row['description']   ?? '',
				'img_url'     => $row['img_url']       ?? '',
				'icon_slug'   => $row['icon_slug']     ?? '',
				'tags'        => nw_normalise_tags( $row['tags'] ?? [] ),
			];
		}, $rows );

		wp_send_json_success( $out );
	}
}

// ─── 4. GET NODES ────────────────────────────────────────────────────────────
// Returns worlds (cyber_nodes) that the current user has access to.
// For now: all public nodes. Later filter by user_id ownership / membership.
if ( ! function_exists( 'neoweaver_get_nodes_handler' ) ) {
	add_action( 'wp_ajax_neoweaver_get_nodes', 'neoweaver_get_nodes_handler' );

	function neoweaver_get_nodes_handler(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Login required' );
			return;
		}

		if ( ! nw_char_verify_nonce() ) {
			wp_send_json_error( 'Security check failed' );
			return;
		}

		$wp_user_id = get_current_user_id();

		// Fetch nodes owned by this user OR marked as public.
		// cyber_nodes columns: id, name, description, owner_id, is_public
		$rows = nw_supa_get(
			'cyber_nodes',
			'select=id,name,description&or=(owner_id.eq.' . $wp_user_id . ',is_public.eq.true)&order=name.asc&limit=100'
		);

		if ( $rows === null ) {
			wp_send_json_error( 'Supabase connection failed' );
			return;
		}

		if ( empty( $rows ) ) {
			wp_send_json_success( [] ); // JS shows "No nodes available" message
			return;
		}

		$out = array_map( function ( $row ) {
			return [
				'id'    => $row['id']          ?? '',
				'label' => $row['name']         ?? '',
				'desc'  => $row['description']  ?? '',
			];
		}, $rows );

		wp_send_json_success( $out );
	}
}

// ─── 5. CREATE CHARACTER ─────────────────────────────────────────────────────
if ( ! function_exists( 'neoweaver_create_character_handler' ) ) {
	add_action( 'wp_ajax_neoweaver_create_character', 'neoweaver_create_character_handler' );

	function neoweaver_create_character_handler(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Login required' ] );
			return;
		}

		if ( ! nw_char_verify_nonce() ) {
			wp_send_json_error( [ 'message' => 'Security check failed' ] );
			return;
		}

		$wp_user_id     = get_current_user_id();
		$character_name = sanitize_text_field( wp_unslash( $_POST['character_name'] ?? '' ) );

		if ( ! $character_name ) {
			wp_send_json_error( [ 'message' => 'Character name is required' ] );
			return;
		}

		$node_id = sanitize_text_field( wp_unslash( $_POST['node_id'] ?? '' ) );
		if ( ! $node_id ) {
			wp_send_json_error( [ 'message' => 'Node ID is required' ] );
			return;
		}

		// ── Avatar upload (optional) ──────────────────────────────────────────
		$avatar_url = '';
		if ( ! empty( $_FILES['avatar'] ) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK ) {
			$upload = wp_handle_upload( $_FILES['avatar'], [ 'test_form' => false ] );
			if ( ! isset( $upload['error'] ) && isset( $upload['url'] ) ) {
				$avatar_url = $upload['url'];
			}
		}

		// ── Build character record ────────────────────────────────────────────
		$char_data = [
			'wp_user_id'     => $wp_user_id,
			'node_id'        => $node_id,
			'name'           => $character_name,
			'pronouns'       => sanitize_text_field( wp_unslash( $_POST['pronouns']  ?? '' ) ),
			'backstory'      => sanitize_textarea_field( wp_unslash( $_POST['backstory'] ?? '' ) ),
			'race'           => sanitize_key( wp_unslash( $_POST['race']       ?? '' ) ),
			'subrace'        => sanitize_key( wp_unslash( $_POST['subrace']    ?? '' ) ),
			'class'          => sanitize_text_field( wp_unslash( $_POST['char_class'] ?? '' ) ),
			'attr_body'      => max( 1, min( 5, (int) ( $_POST['attr_body']   ?? 1 ) ) ),
			'attr_reflex'    => max( 1, min( 5, (int) ( $_POST['attr_reflex'] ?? 1 ) ) ),
			'attr_mind'      => max( 1, min( 5, (int) ( $_POST['attr_mind']   ?? 1 ) ) ),
			'attr_spirit'    => max( 1, min( 5, (int) ( $_POST['attr_spirit'] ?? 1 ) ) ),
			'avatar_url'     => $avatar_url,
			'is_active'      => true,
			'created_at'     => current_time( 'c' ),
		];

		// Remove empty optional fields to avoid Supabase NOT NULL violations
		foreach ( [ 'pronouns', 'backstory', 'subrace', 'avatar_url' ] as $opt ) {
			if ( $char_data[ $opt ] === '' ) {
				unset( $char_data[ $opt ] );
			}
		}

		$result = nw_supa_post( 'cyber_characters', $char_data );

		if ( $result === null ) {
			wp_send_json_error( [ 'message' => 'Database connection failed. Please try again.' ] );
			return;
		}

		// Supabase returns array of inserted rows; first element is the new record
		$new_char = is_array( $result ) && isset( $result[0] ) ? $result[0] : null;

		if ( ! $new_char ) {
			// Supabase may return an error object instead of a row
			$err_msg = is_array( $result ) && isset( $result['message'] ) ? $result['message'] : 'Character save failed.';
			wp_send_json_error( [ 'message' => $err_msg ] );
			return;
		}

		wp_send_json_success( [
			'message'      => 'Agent ' . esc_html( $character_name ) . ' synchronized to the Grid.',
			'character_id' => $new_char['id'] ?? '',
			'redirect'     => home_url( '/dashboard/' ),
		] );
	}
}
