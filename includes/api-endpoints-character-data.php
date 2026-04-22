<?php
/**
 * Character creator endpoint helpers + submit handler
 * NeoWeaver / WordPress / Supabase
 */

/**
 * Validate race + subrace relation and return final race id for character row.
 *
 * Rules:
 * - race is required
 * - subrace is optional
 * - if subrace exists, it must belong to selected race
 * - stored race_id should be subrace id if subrace selected, otherwise parent race id
 */
function nw_validate_race_selection( string $race_id_input, string $subrace_id_input ) {
	$race_id_input    = sanitize_text_field( $race_id_input );
	$subrace_id_input = sanitize_text_field( $subrace_id_input );

	if ( '' === $race_id_input ) {
		return new WP_Error( 'race_required', 'Race is required.', [ 'status' => 400 ] );
	}

	$race_row = nw_find_race_by_id( $race_id_input );
	if ( empty( $race_row['id'] ) || ! is_string( $race_row['id'] ) ) {
		return new WP_Error( 'invalid_race', 'Selected race does not exist.', [ 'status' => 400 ] );
	}

	$race_parent = isset( $race_row['parent_race'] ) ? (string) $race_row['parent_race'] : '';
	if ( '' !== $race_parent ) {
		return new WP_Error( 'invalid_race', 'Selected race must be a parent race.', [ 'status' => 400 ] );
	}

	if ( '' === $subrace_id_input ) {
		return [
			'stored_race_id' => $race_row['id'],
			'race_row'       => $race_row,
			'subrace_row'    => null,
		];
	}

	$subrace_row = nw_find_race_by_id( $subrace_id_input );
	if ( empty( $subrace_row['id'] ) || ! is_string( $subrace_row['id'] ) ) {
		return new WP_Error( 'invalid_subrace', 'Selected subrace does not exist.', [ 'status' => 400 ] );
	}

	$subrace_parent = isset( $subrace_row['parent_race'] ) ? (string) $subrace_row['parent_race'] : '';
	if ( '' === $subrace_parent ) {
		return new WP_Error( 'invalid_subrace', 'Selected subrace is not linked to a parent race.', [ 'status' => 400 ] );
	}

	if ( $subrace_parent !== $race_row['id'] ) {
		return new WP_Error( 'subrace_mismatch', 'Selected subrace does not belong to the chosen race.', [ 'status' => 400 ] );
	}

	return [
		'stored_race_id' => $subrace_row['id'],
		'race_row'       => $race_row,
		'subrace_row'    => $subrace_row,
	];
}

/**
 * Strict avatar upload validation.
 *
 * Frontend allows JPG / PNG / WEBP up to 2 MB, so backend must enforce the same.
 */
function nw_handle_avatar_upload_strict() {
	if ( empty( $_FILES['avatar'] ) || empty( $_FILES['avatar']['name'] ) ) {
		return '';
	}

	$file = $_FILES['avatar'];

	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'avatar_upload_error', 'Avatar upload failed.', [ 'status' => 400 ] );
	}

	$max_size = 2 * 1024 * 1024;
	if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
		return new WP_Error( 'avatar_too_large', 'Avatar must be 2 MB or smaller.', [ 'status' => 400 ] );
	}

	$allowed_mimes = [
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	];

	$check = wp_check_filetype_and_ext(
		$file['tmp_name'],
		$file['name'],
		$allowed_mimes
	);

	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed_mimes, true ) ) {
		return new WP_Error( 'avatar_invalid_type', 'Avatar must be JPG, PNG, or WEBP.', [ 'status' => 400 ] );
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$uploaded = wp_handle_upload(
		$file,
		[
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		]
	);

	if ( ! empty( $uploaded['error'] ) ) {
		return new WP_Error( 'avatar_upload_error', 'Avatar could not be uploaded.', [ 'status' => 400 ] );
	}

	if ( empty( $uploaded['url'] ) ) {
		return new WP_Error( 'avatar_upload_error', 'Avatar upload returned no file URL.', [ 'status' => 400 ] );
	}

	return esc_url_raw( $uploaded['url'] );
}

/**
 * Create character from AJAX request.
 *
 * Expected fields from current JS:
 * - character_name
 * - pronouns
 * - bio
 * - race
 * - subrace
 * - char_class
 * - starting_package_id
 * - skills (JSON array)
 * - data_origin
 * - previous_operation
 * - sync_crisis
 * - backstory_tags (JSON array)
 * - attr_body, attr_reflex, attr_mind, attr_spirit
 * - avatar (file, optional)
 */
function nw_create_character_from_request() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Login required.' ], 403 );
	}

	if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
	}

	$user_id = get_current_user_id();

	$name              = sanitize_text_field( $_POST['character_name'] ?? '' );
	$pronouns          = sanitize_text_field( $_POST['pronouns'] ?? '' );
	$bio               = sanitize_textarea_field( $_POST['bio'] ?? '' );
	$race_id_input     = sanitize_text_field( $_POST['race'] ?? '' );
	$subrace_id_input  = sanitize_text_field( $_POST['subrace'] ?? '' );
	$class_id          = sanitize_text_field( $_POST['char_class'] ?? '' );
	$start_pack        = sanitize_text_field( $_POST['starting_package_id'] ?? '' );
	$data_origin       = sanitize_text_field( $_POST['data_origin'] ?? '' );
	$prev_operation    = sanitize_text_field( $_POST['previous_operation'] ?? '' );
	$sync_crisis       = sanitize_text_field( $_POST['sync_crisis'] ?? '' );

	$skills = json_decode( wp_unslash( $_POST['skills'] ?? '[]' ), true );
	$skills = is_array( $skills )
		? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $skills ) ) ) )
		: [];

	$backstory_tags = json_decode( wp_unslash( $_POST['backstory_tags'] ?? '[]' ), true );
	$backstory_tags = is_array( $backstory_tags )
		? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $backstory_tags ) ) ) )
		: [];

	if ( '' === $name ) {
		wp_send_json_error( [ 'message' => 'Character name is required.' ], 400 );
	}

	if ( '' === $class_id ) {
		wp_send_json_error( [ 'message' => 'Class is required.' ], 400 );
	}

	$body   = max( 1, min( 5, (int) ( $_POST['attr_body'] ?? 1 ) ) );
	$reflex = max( 1, min( 5, (int) ( $_POST['attr_reflex'] ?? 1 ) ) );
	$mind   = max( 1, min( 5, (int) ( $_POST['attr_mind'] ?? 1 ) ) );
	$spirit = max( 1, min( 5, (int) ( $_POST['attr_spirit'] ?? 1 ) ) );

	if ( 12 !== ( $body + $reflex + $mind + $spirit ) ) {
		wp_send_json_error( [ 'message' => 'Attribute total must equal 12.' ], 400 );
	}

	$race_validation = nw_validate_race_selection( $race_id_input, $subrace_id_input );
	if ( is_wp_error( $race_validation ) ) {
		wp_send_json_error( [ 'message' => $race_validation->get_error_message() ], 400 );
	}

	$skills_validation = nw_validate_skill_selection( $class_id, $skills );
	if ( is_wp_error( $skills_validation ) ) {
		wp_send_json_error( [ 'message' => $skills_validation->get_error_message() ], 400 );
	}

	$package_validation = nw_validate_starting_package_selection( $class_id, $start_pack );
	if ( is_wp_error( $package_validation ) ) {
		wp_send_json_error( [ 'message' => $package_validation->get_error_message() ], 400 );
	}

	$tags_validation = nw_validate_backstory_tags( $backstory_tags );
	if ( is_wp_error( $tags_validation ) ) {
		wp_send_json_error( [ 'message' => $tags_validation->get_error_message() ], 400 );
	}

	$avatar_url = nw_handle_avatar_upload_strict();
	if ( is_wp_error( $avatar_url ) ) {
		wp_send_json_error( [ 'message' => $avatar_url->get_error_message() ], 400 );
	}

	$character_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'char_', true );

	$payload = [
		'id'                => $character_id,
		'name'              => $name,
		'wp_user_id'        => $user_id,
		'gender'            => '' !== $pronouns ? $pronouns : null,
		'bio'               => '' !== $bio ? $bio : null,
		'avatar'            => is_string( $avatar_url ) && '' !== $avatar_url ? $avatar_url : null,
		'body'              => $body,
		'reflex'            => $reflex,
		'mind'              => $mind,
		'spirit'            => $spirit,
		'class_id'          => $class_id,
		'race_id'           => $race_validation['stored_race_id'],
		'start_pack'        => '' !== $start_pack ? $start_pack : null,
		'is_public'         => false,
		'gold'              => 0,
		'world_id'          => null,
		'world_credentials' => null,
		'data_origin'       => '' !== $data_origin ? $data_origin : null,
		'prev_operation'    => '' !== $prev_operation ? $prev_operation : null,
		'sync_crisis'       => '' !== $sync_crisis ? $sync_crisis : null,
	];

	$result = nw_supabase_request( 'POST', 'cyber_characters', [], $payload, true );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
	}

	$skills_store = nw_store_character_skills( $character_id, $skills );
	if ( is_wp_error( $skills_store ) ) {
		nw_delete_character_by_id( $character_id );
		wp_send_json_error( [ 'message' => 'Character could not be saved with skills.' ], 500 );
	}

	$tags_store = nw_store_character_backstory_tags( $character_id, $backstory_tags );
	if ( is_wp_error( $tags_store ) ) {
		nw_delete_character_by_id( $character_id );
		wp_send_json_error( [ 'message' => 'Character could not be saved with backstory tags.' ], 500 );
	}

	$redirect = home_url( '/character/' . rawurlencode( $character_id ) . '/' );

	wp_send_json_success(
		[
			'message'      => 'Character created successfully.',
			'character_id' => $character_id,
			'redirect'     => $redirect,
		]
	);
}

add_action( 'wp_ajax_neoweaver_create_character', 'nw_create_character_from_request' );
