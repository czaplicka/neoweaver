<?php
/**
 * api-endpoints-character-data.php
 *
 * REST routes + wp_ajax handlers that serve lookup data for the character creator wizard
 * and process final character creation.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NW_UPLOADS_BASE' ) ) {
	define( 'NW_UPLOADS_BASE', 'https://neoweaver.nieodparady.pl/wp-content/uploads/' );
}

function nw_resolve_img_urls( array $rows ): array {
	return array_map(
		function ( $row ) {
			if ( ! empty( $row['img_url'] ) && 0 !== strpos( (string) $row['img_url'], 'http' ) ) {
				$row['img_url'] = NW_UPLOADS_BASE . ltrim( (string) $row['img_url'], '/' );
			}
			return $row;
		},
		$rows
	);
}

function nw_decode_jsonb_array( $value ): array {
	if ( is_string( $value ) ) {
		$value = json_decode( $value, true );
	}
	if ( ! is_array( $value ) ) {
		return [];
	}
	return array_values(
		array_map(
			'sanitize_text_field',
			array_filter( $value, 'is_scalar' )
		)
	);
}

function nw_fetch_lookup_table(
	string $table,
	string $select_cols,
	string $order = 'name.asc',
	int $ttl = 300,
	array $extra_filters = []
) {
	$cache_key = 'nw_lookup_' . md5( $table . '|' . $select_cols . '|' . $order . '|' . serialize( $extra_filters ) );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$params = array_merge(
		[
			'select' => $select_cols,
			'order'  => $order,
		],
		$extra_filters
	);

	$data = tw_supabase_get( $table, $params );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	set_transient( $cache_key, $data, $ttl );
	return $data;
}

function nw_map_race_card_shape( array $rows ): array {
	return array_map(
		function ( $row ) {
			$raw_tags  = nw_decode_jsonb_array( $row['tags'] ?? [] );
			$tags_out  = array_map(
				function ( $tag ) {
					return [ 'name' => $tag ];
				},
				$raw_tags
			);
			$raw_bonus = nw_decode_jsonb_array( $row['bonus'] ?? [] );
			$bonus_str = implode( ' · ', $raw_bonus );

			return [
				'id'    => $row['id'] ?? null,
				'key'   => (string) ( $row['name'] ?? $row['id'] ?? '' ),
				'label' => $row['name'] ?? '',
				'desc'  => $row['description'] ?? '',
				'img'   => $row['img_url'] ?? '',
				'bonus' => $bonus_str,
				'tags'  => $tags_out,
			];
		},
		$rows
	);
}

function nw_map_class_card_shape( array $rows ): array {
	return array_map(
		function ( $row ) {
			return [
				'id'          => $row['id'] ?? null,
				'name'        => $row['name'] ?? '',
				'description' => $row['description'] ?? '',
				'tags'        => nw_decode_jsonb_array( $row['tags'] ?? [] ),
				'img_url'     => $row['img_url'] ?? '',
				'icon_slug'   => $row['icon_slug'] ?? '',
				'skill_limit' => isset( $row['skill_limit'] ) ? (int) $row['skill_limit'] : 5,
			];
		},
		$rows
	);
}

function nw_map_skill_card_shape( array $rows ): array {
	return array_map(
		function ( $row ) {
			return [
				'id'                => $row['id'] ?? null,
				'name'              => $row['name'] ?? '',
				'description'       => $row['description'] ?? '',
				'category'          => $row['category'] ?? 'Other',
				'application'       => $row['application'] ?? '',
				'card_effect'       => $row['card_effect'] ?? '',
				'img_url'           => $row['img_url'] ?? '',
				'tags'              => nw_decode_jsonb_array( $row['tags'] ?? [] ),
				'linked_attributes' => nw_decode_jsonb_array( $row['linked_attributes'] ?? [] ),
			];
		},
		$rows
	);
}

function nw_map_starting_package_shape( array $rows ): array {
	return array_map(
		function ( $row ) {
			return [
				'id'                 => $row['id'] ?? null,
				'package_name'       => $row['package_name'] ?? '',
				'description'        => $row['description'] ?? '',
				'items_list'         => nw_decode_jsonb_array( $row['items_list'] ?? [] ),
				'compatibility_tags' => nw_decode_jsonb_array( $row['compatibility_tags'] ?? [] ),
				'attack_cards_pool'  => nw_decode_jsonb_array( $row['attack_cards_pool'] ?? [] ),
				'defense_cards_pool' => nw_decode_jsonb_array( $row['defense_cards_pool'] ?? [] ),
				'base_armor'         => isset( $row['base_armor'] ) ? (int) $row['base_armor'] : 0,
			];
		},
		$rows
	);
}

function nw_filter_packages_by_class_tag( array $rows, string $class_tag ): array {
	$class_tag = strtolower( trim( $class_tag ) );
	if ( '' === $class_tag ) {
		return [];
	}

	return array_values(
		array_filter(
			$rows,
			function ( $row ) use ( $class_tag ) {
				$tags = nw_decode_jsonb_array( $row['compatibility_tags'] ?? [] );
				$tags = array_map( 'strtolower', $tags );
				return in_array( $class_tag, $tags, true );
			}
		)
	);
}

function nw_supabase_request( string $method, string $table, array $query = [], $body = null, bool $return_representation = false ) {
	if ( ! function_exists( 'tw_supabase_rest_url' ) || ! function_exists( 'tw_supabase_headers' ) ) {
		return new WP_Error( 'config_missing', 'Supabase REST configuration missing.', [ 'status' => 500 ] );
	}

	$url = trailingslashit( tw_supabase_rest_url() ) . ltrim( $table, '/' );
	if ( ! empty( $query ) ) {
		$url = add_query_arg( $query, $url );
	}

	$headers = tw_supabase_headers();
	if ( $return_representation ) {
		$headers['Prefer'] = 'return=representation';
	}
	if ( in_array( strtoupper( $method ), [ 'POST', 'PATCH' ], true ) ) {
		$headers['Content-Type'] = 'application/json';
	}

	$args = [
		'method'  => strtoupper( $method ),
		'headers' => $headers,
		'timeout' => 30,
	];

	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = '' !== $raw ? json_decode( $raw, true ) : [];

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'supabase_http_error',
			is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Supabase request failed.',
			[
				'status' => $code,
				'body'   => $data,
			]
		);
	}

	return is_array( $data ) ? $data : [];
}

function nw_find_race_by_name( string $name ) {
	$name = sanitize_text_field( $name );
	if ( '' === $name ) {
		return null;
	}
	$rows = nw_supabase_request(
		'GET',
		'cyber_races',
		[
			'select' => 'id,name,parent_race',
			'name'   => 'eq.' . $name,
			'limit'  => 1,
		]
	);
	if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
		return null;
	}
	return $rows[0];
}

function nw_find_race_by_id( string $race_id ) {
	$race_id = sanitize_text_field( $race_id );
	if ( '' === $race_id ) {
		return null;
	}
	$rows = nw_supabase_request(
		'GET',
		'cyber_races',
		[
			'select' => 'id,name,parent_race',
			'id'     => 'eq.' . $race_id,
			'limit'  => 1,
		]
	);
	if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
		return null;
	}
	return $rows[0];
}

function nw_find_starting_package_by_id( string $package_id ) {
	$package_id = sanitize_text_field( $package_id );
	if ( '' === $package_id ) {
		return null;
	}
	$rows = nw_supabase_request(
		'GET',
		'cyber_starting_packages',
		[
			'select'               => 'id,package_name,compatibility_tags,is_player_selectable',
			'id'                   => 'eq.' . $package_id,
			'is_player_selectable' => 'eq.true',
			'limit'                => 1,
		]
	);
	if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
		return null;
	}
	return $rows[0];
}

function nw_validate_starting_package_selection( string $class_id, string $package_id ) {
	if ( '' === $package_id ) {
		return true;
	}

	$class_row = nw_find_class_by_id( $class_id );
	if ( empty( $class_row ) || empty( $class_row['id'] ) || empty( $class_row['is_active'] ) ) {
		return new WP_Error( 'invalid_class', 'Selected class does not exist or is inactive.', [ 'status' => 400 ] );
	}

	$package_row = nw_find_starting_package_by_id( $package_id );
	if ( empty( $package_row ) || empty( $package_row['id'] ) ) {
		return new WP_Error( 'invalid_starting_package', 'Selected starting package does not exist or is not player selectable.', [ 'status' => 400 ] );
	}

	$class_tags   = array_filter( array_map( 'strtolower', nw_decode_jsonb_array( $class_row['name'] ?? '' ? [ $class_row['name'] ] : [] ) ) );
	$package_tags = array_map( 'strtolower', nw_decode_jsonb_array( $package_row['compatibility_tags'] ?? [] ) );

	if ( empty( $package_tags ) ) {
		return new WP_Error( 'invalid_starting_package', 'Selected starting package has no compatibility tags.', [ 'status' => 400 ] );
	}

	$matched = false;
	foreach ( $class_tags as $tag ) {
		if ( in_array( $tag, $package_tags, true ) ) {
			$matched = true;
			break;
		}
	}

	if ( ! $matched ) {
		return new WP_Error( 'starting_package_mismatch', 'Selected starting package is not compatible with this class.', [ 'status' => 400 ] );
	}

	return true;
}


function nw_find_tag_defs_by_labels( array $labels ): array {
	$labels = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $labels ) ) ) );
	if ( empty( $labels ) ) {
		return [];
	}
	$quoted = array_map(
		function ( $label ) {
			return '"' . str_replace( '"', '\\"', $label ) . '"';
		},
		$labels
	);
	$rows = nw_supabase_request(
		'GET',
		'cyber_character_tag_defs',
		[
			'select' => 'id,label',
			'label'  => 'in.(' . implode( ',', $quoted ) . ')',
		]
	);
	if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
		return [];
	}
	return $rows;
}


function nw_find_class_by_id( string $class_id ) {
	$class_id = sanitize_text_field( $class_id );
	if ( '' === $class_id ) {
		return null;
	}
	$rows = nw_supabase_request(
		'GET',
		'cyber_classes',
		[
			'select' => 'id,name,skill_limit,is_active',
			'id'     => 'eq.' . $class_id,
			'limit'  => 1,
		]
	);
	if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
		return null;
	}
	return $rows[0];
}

function nw_find_skills_by_ids( array $skill_ids ): array {
	$skill_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $skill_ids ) ) ) );
	if ( empty( $skill_ids ) ) {
		return [];
	}
	$quoted = array_map(
		function ( $id ) {
			return '"' . str_replace( '"', '\\"', $id ) . '"';
		},
		$skill_ids
	);
	$rows = nw_supabase_request(
		'GET',
		'cyber_skills',
		[
			'select'    => 'id,is_active',
			'id'        => 'in.(' . implode( ',', $quoted ) . ')',
			'is_active' => 'eq.true',
		]
	);
	if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
		return [];
	}
	return $rows;
}

function nw_validate_skill_selection( string $class_id, array $skills ) {
	$class_row = nw_find_class_by_id( $class_id );
	if ( empty( $class_row ) || empty( $class_row['id'] ) || empty( $class_row['is_active'] ) ) {
		return new WP_Error( 'invalid_class', 'Selected class does not exist or is inactive.', [ 'status' => 400 ] );
	}

	$limit = isset( $class_row['skill_limit'] ) ? (int) $class_row['skill_limit'] : 5;
	$limit = $limit > 0 ? $limit : 5;

	if ( count( $skills ) > $limit ) {
		return new WP_Error( 'skill_limit_exceeded', 'Too many skills selected for this class.', [ 'status' => 400 ] );
	}

	if ( empty( $skills ) ) {
		return true;
	}

	$skill_rows = nw_find_skills_by_ids( $skills );
	$found_ids  = array_map(
		function ( $row ) {
			return (string) ( $row['id'] ?? '' );
		},
		$skill_rows
	);
	$missing = array_values( array_diff( $skills, $found_ids ) );

	if ( ! empty( $missing ) ) {
		return new WP_Error( 'invalid_skills', 'One or more selected skills are invalid or inactive.', [ 'status' => 400 ] );
	}

	return true;
}

function nw_validate_backstory_tags( array $tag_labels ) {
	if ( empty( $tag_labels ) ) {
		return true;
	}
	$defs      = nw_find_tag_defs_by_labels( $tag_labels );
	$found     = array_map(
		function ( $row ) {
			return (string) ( $row['label'] ?? '' );
		},
		$defs
	);
	$requested = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tag_labels ) ) ) );
	$missing   = array_values( array_diff( $requested, $found ) );
	if ( ! empty( $missing ) ) {
		return new WP_Error( 'invalid_backstory_tags', 'One or more backstory tags do not exist.', [ 'status' => 400 ] );
	}
	return true;
}

function nw_store_character_skills( string $character_id, array $skills ): void {
	if ( empty( $skills ) ) {
		return;
	}
	$payload = [];
	foreach ( $skills as $skill_id ) {
		$skill_id = sanitize_text_field( $skill_id );
		if ( '' === $skill_id ) {
			continue;
		}
		$payload[] = [
			'character_id' => $character_id,
			'skill_id'     => $skill_id,
			'proficiency'  => 1,
			'source'       => 'character_creator',
		];
	}
	if ( ! empty( $payload ) ) {
		nw_supabase_request( 'POST', 'cyber_character_skills', [], $payload, false );
	}
}

function nw_store_character_backstory_tags( string $character_id, array $tag_labels ): void {
	$defs = nw_find_tag_defs_by_labels( $tag_labels );
	if ( empty( $defs ) ) {
		return;
	}
	$payload = [];
	foreach ( $defs as $row ) {
		if ( empty( $row['id'] ) ) {
			continue;
		}
		$payload[] = [
			'character_id' => $character_id,
			'tag_id'       => (int) $row['id'],
		];
	}
	if ( ! empty( $payload ) ) {
		nw_supabase_request( 'POST', 'cyber_character_backstory_tags', [], $payload, false );
	}
}

function nw_handle_avatar_upload(): string {
	if ( empty( $_FILES['avatar'] ) || empty( $_FILES['avatar']['name'] ) ) {
		return '';
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$uploaded = wp_handle_upload(
		$_FILES['avatar'],
		[
			'test_form' => false,
			'mimes'     => [
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
			],
		]
	);

	if ( isset( $uploaded['url'] ) ) {
		return esc_url_raw( $uploaded['url'] );
	}

	return '';
}

function nw_create_character_from_request() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Login required.' ], 403 );
	}

	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );

	$user_id = get_current_user_id();
	$name    = sanitize_text_field( $_POST['character_name'] ?? '' );
	if ( '' === $name ) {
		wp_send_json_error( [ 'message' => 'Character name is required.' ], 400 );
	}

	$pronouns        = sanitize_text_field( $_POST['pronouns'] ?? '' );
	$backstory       = sanitize_textarea_field( $_POST['backstory'] ?? '' );
	$bio             = sanitize_textarea_field( $_POST['bio'] ?? '' );
	$race_id_input   = sanitize_text_field( $_POST['race'] ?? '' );
	$subrace_id_input = sanitize_text_field( $_POST['subrace'] ?? '' );
	$class_id        = sanitize_text_field( $_POST['char_class'] ?? '' );
	$start_pack      = sanitize_text_field( $_POST['starting_package_id'] ?? '' );
	$data_origin     = sanitize_text_field( $_POST['data_origin'] ?? '' );
	$prev_operation  = sanitize_text_field( $_POST['previous_operation'] ?? '' );
	$sync_crisis     = sanitize_text_field( $_POST['sync_crisis'] ?? '' );
	$skills          = json_decode( wp_unslash( $_POST['skills'] ?? '[]' ), true );
	$backstory_tags  = json_decode( wp_unslash( $_POST['backstory_tags'] ?? '[]' ), true );

	$skills = is_array( $skills ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $skills ) ) ) ) : [];
	$backstory_tags = is_array( $backstory_tags ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $backstory_tags ) ) ) ) : [];

	if ( '' === $class_id ) {
		wp_send_json_error( [ 'message' => 'Class is required.' ], 400 );
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

	$selected_race_id = $subrace_id_input ?: $race_id_input;
	$race_row    = nw_find_race_by_id( $selected_race_id );
	if ( '' !== $selected_race_id && empty( $race_row['id'] ) ) {
		wp_send_json_error( [ 'message' => 'Selected race or subrace does not exist.' ], 400 );
	}
	$race_id     = $race_row['id'] ?? null;
	$avatar_url  = nw_handle_avatar_upload();
	$character_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'char_', true );

	$body   = max( 1, min( 5, (int) ( $_POST['attr_body'] ?? 1 ) ) );
	$reflex = max( 1, min( 5, (int) ( $_POST['attr_reflex'] ?? 1 ) ) );
	$mind   = max( 1, min( 5, (int) ( $_POST['attr_mind'] ?? 1 ) ) );
	$spirit = max( 1, min( 5, (int) ( $_POST['attr_spirit'] ?? 1 ) ) );

	$payload = [
		'id'           => $character_id,
		'name'         => $name,
		'wp_user_id'   => $user_id,
		'gender'       => $pronouns ?: null,
		'notes'        => $backstory,
		'bio'          => $bio,
		'avatar'       => $avatar_url,
		'body'         => $body,
		'reflex'       => $reflex,
		'mind'         => $mind,
		'spirit'       => $spirit,
		'class_id'     => $class_id ?: null,
		'race_id'      => $race_id,
		'start_pack'   => $start_pack ?: null,
		'is_public'    => false,
		'gold'         => 0,
		'world_id'     => null,
		'world_credentials' => null,
	];

	$result = nw_supabase_request( 'POST', 'cyber_characters', [], $payload, true );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
	}

	nw_store_character_skills( $character_id, $skills );
	nw_store_character_backstory_tags( $character_id, $backstory_tags );

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

function neoweaver_get_races( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table(
		'cyber_races',
		'id,name,description,tags,bonus,img_url',
		'name.asc',
		300,
		[ 'parent_race' => 'is.null' ]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
}

function neoweaver_get_subraces( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$parent = sanitize_text_field( (string) $request->get_param( 'parent' ) );
	if ( '' === $parent ) {
		return new WP_Error( 'missing_param', 'parent parameter required.', [ 'status' => 400 ] );
	}
	$data = nw_fetch_lookup_table(
		'cyber_races',
		'id,name,description,tags,bonus,img_url',
		'name.asc',
		300,
		[ 'parent_race' => 'eq.' . $parent ]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
}

function neoweaver_get_classes_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table(
		'cyber_classes',
		'id,name,description,tags,img_url,icon_slug,skill_limit',
		'name.asc',
		300,
		[ 'is_active' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( nw_map_class_card_shape( nw_resolve_img_urls( $data ) ) );
}

function neoweaver_get_skills_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table(
		'cyber_skills',
		'id,name,description,category,application,card_effect,img_url,tags,linked_attributes',
		'category.asc,name.asc',
		300,
		[ 'is_active' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( nw_map_skill_card_shape( nw_resolve_img_urls( $data ) ) );
}

function neoweaver_get_starting_packages_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$class_tag = sanitize_text_field( (string) $request->get_param( 'class_tag' ) );
	if ( '' === $class_tag ) {
		return new WP_Error( 'missing_param', 'class_tag parameter required.', [ 'status' => 400 ] );
	}

	$data = nw_fetch_lookup_table(
		'cyber_starting_packages',
		'id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor',
		'package_name.asc',
		300,
		[ 'is_player_selectable' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$data = nw_filter_packages_by_class_tag( $data, $class_tag );
	return rest_ensure_response( nw_map_starting_package_shape( $data ) );
}

function neoweaver_ajax_get_races(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );
	$data = nw_fetch_lookup_table(
		'cyber_races',
		'id,name,description,tags,bonus,img_url',
		'name.asc',
		300,
		[ 'parent_race' => 'is.null' ]
	);
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}
	wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
}
add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_ajax_get_races' );
add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_ajax_get_races' );

function neoweaver_ajax_get_subraces(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );
	$parent = sanitize_text_field( $_POST['parent'] ?? '' );
	if ( '' === $parent ) {
		wp_send_json_error( [ 'message' => 'parent parameter required.' ] );
	}
	$data = nw_fetch_lookup_table(
		'cyber_races',
		'id,name,description,tags,bonus,img_url',
		'name.asc',
		300,
		[ 'parent_race' => 'eq.' . $parent ]
	);
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}
	wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
}
add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );
add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );

function neoweaver_ajax_get_classes(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );
	$data = nw_fetch_lookup_table(
		'cyber_classes',
		'id,name,description,tags,img_url,icon_slug,skill_limit',
		'name.asc',
		300,
		[ 'is_active' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}
	wp_send_json_success( nw_map_class_card_shape( nw_resolve_img_urls( $data ) ) );
}
add_action( 'wp_ajax_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );
add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );

function neoweaver_ajax_get_skills(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );
	$data = nw_fetch_lookup_table(
		'cyber_skills',
		'id,name,description,category,application,card_effect,img_url,tags,linked_attributes',
		'category.asc,name.asc',
		300,
		[ 'is_active' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}
	wp_send_json_success( nw_map_skill_card_shape( nw_resolve_img_urls( $data ) ) );
}
add_action( 'wp_ajax_neoweaver_get_skills', 'neoweaver_ajax_get_skills' );
add_action( 'wp_ajax_nopriv_neoweaver_get_skills', 'neoweaver_ajax_get_skills' );

function neoweaver_ajax_get_starting_packages(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );
	$class_tag = sanitize_text_field( $_POST['class_tag'] ?? '' );
	if ( '' === $class_tag ) {
		wp_send_json_error( [ 'message' => 'class_tag parameter required.' ] );
	}

	$data = nw_fetch_lookup_table(
		'cyber_starting_packages',
		'id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor',
		'package_name.asc',
		300,
		[ 'is_player_selectable' => 'eq.true' ]
	);
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}

	$data = nw_filter_packages_by_class_tag( $data, $class_tag );
	wp_send_json_success( nw_map_starting_package_shape( $data ) );
}
add_action( 'wp_ajax_neoweaver_get_starting_packages', 'neoweaver_ajax_get_starting_packages' );
add_action( 'wp_ajax_nopriv_neoweaver_get_starting_packages', 'neoweaver_ajax_get_starting_packages' );

function neoweaver_register_character_lookup_routes(): void {
	register_rest_route(
		'neoweaver/v1',
		'/races',
		[
			'methods'             => 'GET',
			'callback'            => 'neoweaver_get_races',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'neoweaver/v1',
		'/subraces',
		[
			'methods'             => 'GET',
			'callback'            => 'neoweaver_get_subraces',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'neoweaver/v1',
		'/classes',
		[
			'methods'             => 'GET',
			'callback'            => 'neoweaver_get_classes_rest',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'neoweaver/v1',
		'/skills',
		[
			'methods'             => 'GET',
			'callback'            => 'neoweaver_get_skills_rest',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'neoweaver/v1',
		'/starting-packages',
		[
			'methods'             => 'GET',
			'callback'            => 'neoweaver_get_starting_packages_rest',
			'permission_callback' => '__return_true',
		]
	);
}
add_action( 'rest_api_init', 'neoweaver_register_character_lookup_routes' );
