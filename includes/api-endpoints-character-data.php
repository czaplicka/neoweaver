<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

if ( ! function_exists( 'nw_decode_jsonb_array' ) ) {
	function nw_decode_jsonb_array( $value ): array {
		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map(
						static function ( $item ) {
							return is_scalar( $item ) ? trim( (string) $item ) : '';
						},
						$value
					)
				)
			);
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return array();
			}
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return array_values(
					array_filter(
						array_map(
							static function ( $item ) {
								return is_scalar( $item ) ? trim( (string) $item ) : '';
							},
							$decoded
						)
					)
				);
			}
			return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		}
		return array();
	}
}

if ( ! function_exists( 'nw_normalize_media_url' ) ) {
	function nw_normalize_media_url( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		if ( 0 === strpos( $value, '/wp-content/uploads/' ) ) {
			return esc_url_raw( home_url( $value ) );
		}
		$uploads = wp_get_upload_dir();
		return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . ltrim( $value, '/' ) );
	}
}

if ( ! function_exists( 'nw_resolve_img_urls' ) ) {
	function nw_resolve_img_urls( array $rows ): array {
		return array_map(
			static function ( $row ) {
				if ( isset( $row['img_url'] ) ) {
					$row['img_url'] = nw_normalize_media_url( $row['img_url'] );
				}
				if ( isset( $row['image_url'] ) ) {
					$row['image_url'] = nw_normalize_media_url( $row['image_url'] );
				}
				return $row;
			},
			$rows
		);
	}
}

if ( ! function_exists( 'tw_supabase_get' ) ) {
	function tw_supabase_get( string $table, array $query = array() ) {
		return nw_supabase_request( 'GET', $table, $query );
	}
}

if ( ! function_exists( 'nw_normalize_tag_label' ) ) {
	function nw_normalize_tag_label( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9\\-\\s]+/', '', $value );
		$value = preg_replace( '/[\\s\\-]+/', '-', $value );
		return trim( $value, '-' );
	}
}

if ( ! function_exists( 'nw_find_tag_defs_by_labels' ) ) {
	function nw_find_tag_defs_by_labels( array $labels ) {
		$normalized = array_values(
			array_unique(
				array_filter(
					array_map( 'nw_normalize_tag_label', $labels )
				)
			)
		);
		if ( empty( $normalized ) ) {
			return array();
		}
		$or_filters = array_map(
			static function ( $label ) {
				return 'label.ilike.' . rawurlencode( $label );
			},
			$normalized
		);
		$rows = nw_supabase_request(
			'GET',
			'cyber_character_tag_defs',
			array(
				'select' => 'id,label,category,icon,color,description,source,gm',
				'or'     => '(' . implode( ',', $or_filters ) . ')',
				'limit'  => count( $normalized ),
			)
		);
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $normalized ) {
					$label = isset( $row['label'] ) ? nw_normalize_tag_label( $row['label'] ) : '';
					return in_array( $label, $normalized, true );
				}
			)
		);
	}
}

if ( ! function_exists( 'nw_resolve_backstory_tag_ids' ) ) {
	function nw_resolve_backstory_tag_ids( array $tags ) {
		$ids    = array();
		$labels = array();
		foreach ( $tags as $tag ) {
			if ( is_numeric( $tag ) ) {
				$id = (int) $tag;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
				continue;
			}
			$label = nw_normalize_tag_label( $tag );
			if ( $label !== '' ) {
				$labels[] = $label;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! empty( $labels ) ) {
			$defs = nw_find_tag_defs_by_labels( $labels );
			foreach ( $defs as $row ) {
				if ( isset( $row['id'] ) ) {
					$ids[] = (int) $row['id'];
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}

if ( ! function_exists( 'nw_supabase_request' ) ) {
	function nw_supabase_request( string $method, string $table, array $query = array(), $body = null, bool $return_representation = false ) {
		if ( ! function_exists( 'tw_supabase_rest_base' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return new WP_Error( 'config_missing', 'Supabase REST configuration missing.', array( 'status' => 500 ) );
		}
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return new WP_Error( 'config_missing', 'Supabase REST URL is empty.', array( 'status' => 500 ) );
		}
		$url = trailingslashit( $base ) . ltrim( $table, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$debug_supabase = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug_supabase ) {
			error_log( 'NW SUPABASE REQUEST ' . $method . ' ' . $url );
			if ( ! empty( $query ) ) {
				error_log( 'NW SUPABASE QUERY ARGS ' . wp_json_encode( $query ) );
			}
		}
		$method  = strtoupper( $method );
		$headers = array(
			'apikey'        => tw_supabase_anon_key(),
			'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
			'Accept'        => 'application/json',
		);
		if ( $return_representation ) {
			$headers['Prefer'] = 'return=representation';
		}
		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$headers['Content-Type'] = 'application/json';
		}
		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => 30,
			'sslverify' => true,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
			if ( $debug_supabase ) {
				error_log( 'NW SUPABASE BODY ' . wp_json_encode( $body ) );
			}
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			if ( $debug_supabase ) {
				error_log( 'NW SUPABASE WP ERROR ' . $response->get_error_message() );
			}
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( '' !== $raw && null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			if ( $debug_supabase ) {
				error_log( 'NW SUPABASE JSON ERROR ' . json_last_error_msg() );
			}
			return new WP_Error(
				'supabase_invalid_json',
				'Supabase returned an invalid JSON response.',
				array( 'status' => 502, 'raw' => $raw )
			);
		}
		if ( $debug_supabase ) {
			error_log( 'NW SUPABASE RESPONSE CODE ' . $code );
			if ( $code < 200 || $code >= 300 ) {
				error_log( 'NW SUPABASE RESPONSE RAW ' . $raw );
			}
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'supabase_http_error',
				is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Supabase request failed.',
				array( 'status' => $code, 'body' => $data )
			);
		}
		return is_array( $data ) ? $data : array();
	}
}

if ( ! function_exists( 'nw_fetch_lookup_table' ) ) {
	function nw_fetch_lookup_table( string $table, string $select_cols, string $order = '', int $limit = 300, array $extra_filters = array(), int $ttl = 60 ) {
		$cache_key = 'nw_lookup_' . md5( wp_json_encode( array( $table, $select_cols, $order, $limit, $extra_filters ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$params = array( 'select' => $select_cols, 'limit' => $limit );
		if ( '' !== $order ) {
			$params['order'] = $order;
		}
		foreach ( $extra_filters as $col => $filter ) {
			$params[ $col ] = $filter;
		}
		$data = tw_supabase_get( $table, $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'supabase_error', 'Database returned invalid data format.', array( 'status' => 500 ) );
		}
		set_transient( $cache_key, $data, $ttl );
		return $data;
	}
}

if ( ! function_exists( 'nw_sanitize_int_id' ) ) {
	function nw_sanitize_int_id( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return 0;
			}
			$value = (int) $value;
			return $value > 0 ? $value : 0;
		}
		return 0;
	}
}

if ( ! function_exists( 'nw_sanitize_text_id' ) ) {
	function nw_sanitize_text_id( $value ): string {
		return sanitize_text_field( (string) $value );
	}
}

// ─── Card shape mappers ───────────────────────────────────────────────────────

if ( ! function_exists( 'nw_map_race_card_shape' ) ) {
	function nw_map_race_card_shape( array $rows ): array {
		return array_map(
			static function ( $row ) {
				$raw_tags = nw_decode_jsonb_array( $row['tags'] ?? array() );
				$tags_out = array_map( static function ( $tag ) { return array( 'name' => $tag ); }, $raw_tags );
				$raw_bonus = nw_decode_jsonb_array( $row['bonus'] ?? array() );
				return array(
					'id'      => (string) ( $row['id'] ?? '' ),
					'key'     => (string) ( $row['id'] ?? '' ),
					'label'   => (string) ( $row['name'] ?? '' ),
					'name'    => (string) ( $row['name'] ?? '' ),
					'desc'    => (string) ( $row['description'] ?? '' ),
					'bonus'   => implode( ' · ', $raw_bonus ),
					'img'     => (string) ( $row['img_url'] ?? '' ),
					'img_url' => (string) ( $row['img_url'] ?? '' ),
					'tags'    => $tags_out,
				);
			},
			$rows
		);
	}
}

if ( ! function_exists( 'nw_map_class_card_shape' ) ) {
	function nw_map_class_card_shape( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return array(
					'id'          => (string) ( $row['id'] ?? '' ),
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'tags'        => nw_decode_jsonb_array( $row['tags'] ?? array() ),
					'img_url'     => (string) ( $row['img_url'] ?? '' ),
					'icon_slug'   => (string) ( $row['icon_slug'] ?? '' ),
					'skill_limit' => isset( $row['skill_limit'] ) ? (int) $row['skill_limit'] : 5,
				);
			},
			$rows
		);
	}
}

if ( ! function_exists( 'nw_map_skill_card_shape' ) ) {
	function nw_map_skill_card_shape( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return array(
					'id'                => (string) ( $row['id'] ?? '' ),
					'name'              => (string) ( $row['name'] ?? '' ),
					'description'       => (string) ( $row['description'] ?? '' ),
					'category'          => (string) ( $row['category'] ?? 'Other' ),
					'application'       => (string) ( $row['application'] ?? '' ),
					'card_effect'       => (string) ( $row['card_effect'] ?? '' ),
					'img_url'           => (string) ( $row['img_url'] ?? '' ),
					'tags'              => nw_decode_jsonb_array( $row['tags'] ?? array() ),
					'linked_attributes' => nw_decode_jsonb_array( $row['linked_attributes'] ?? array() ),
				);
			},
			$rows
		);
	}
}

if ( ! function_exists( 'nw_map_starting_package_shape' ) ) {
	function nw_map_starting_package_shape( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return array(
					'id'                 => (string) ( $row['id'] ?? '' ),
					'name'               => (string) ( $row['package_name'] ?? '' ),
					'packagename'        => (string) ( $row['package_name'] ?? '' ),
					'description'        => (string) ( $row['description'] ?? '' ),
					'items'              => nw_decode_jsonb_array( $row['items_list'] ?? array() ),
					'itemslist'          => nw_decode_jsonb_array( $row['items_list'] ?? array() ),
					'compatibilitytags'  => nw_decode_jsonb_array( $row['compatibility_tags'] ?? array() ),
					'compatibleclassids' => nw_decode_jsonb_array( $row['compatible_class_ids'] ?? array() ),
					'attackcardspool'    => nw_decode_jsonb_array( $row['attack_cards_pool'] ?? array() ),
					'defensecardspool'   => nw_decode_jsonb_array( $row['defense_cards_pool'] ?? array() ),
					'basearmor'          => isset( $row['base_armor'] ) ? (int) $row['base_armor'] : 0,
				);
			},
			$rows
		);
	}
}

function nw_filter_packages_by_class_name( array $rows, string $class_name ): array {
    $class_name = strtolower( trim( $class_name ) );
    return array_values(
        array_filter(
            $rows,
            static function ( $row ) use ( $class_name ) {
                $name = strtolower( trim( (string) ( $row['package_name'] ?? '' ) ) );
                return str_contains( $name, $class_name );
            }
        )
    );
}

// ─── Lookup finders ──────────────────────────────────────────────────────────

if ( ! function_exists( 'nw_find_race_by_id' ) ) {
	function nw_find_race_by_id( string $race_id ) {
		$race_id = sanitize_text_field( $race_id );
		if ( '' === $race_id ) {
			return null;
		}
		$rows = nw_supabase_request(
			'GET',
			'cyber_races',
			array( 'select' => 'id,name,parent_race', 'id' => 'eq.' . $race_id, 'limit' => 1 )
		);
		if ( is_wp_error( $rows ) || empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return null;
		}
		return $rows[0];
	}
}

if ( ! function_exists( 'nw_find_class_by_id' ) ) {
	function nw_find_class_by_id( string $class_id ) {
		$class_id = sanitize_text_field( $class_id );
		if ( '' === $class_id ) {
			return null;
		}
		$rows = nw_supabase_request(
			'GET',
			'cyber_classes',
			array( 'select' => 'id,name,skill_limit,is_active', 'id' => 'eq.' . $class_id, 'limit' => 1 )
		);
		if ( is_wp_error( $rows ) || empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return null;
		}
		return $rows[0];
	}
}

if ( ! function_exists( 'nw_find_starting_package_by_id' ) ) {
	function nw_find_starting_package_by_id( string $package_id ) {
		$package_id = sanitize_text_field( $package_id );
		if ( ! $package_id ) {
			return null;
		}
		$rows = nw_supabase_request(
			'GET',
			'cyber_starting_packages',
			array(
				'select'               => 'id,package_name,compatibility_tags,compatible_class_ids,is_player_selectable',
				'id'                   => 'eq.' . $package_id,
				'is_player_selectable' => 'eq.true',
				'limit'                => 1,
			)
		);
		if ( is_wp_error( $rows ) || empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return null;
		}
		return $rows[0];
	}
}

if ( ! function_exists( 'nw_find_skills_by_ids' ) ) {
	function nw_find_skills_by_ids( array $skill_ids ): array {
		$skill_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $id ) { return sanitize_text_field( (string) $id ); },
						$skill_ids
					)
				)
			)
		);
		if ( empty( $skill_ids ) ) {
			return array();
		}
		$quoted = array_map(
			static function ( $id ) { return '"' . str_replace( '"', '\\"', $id ) . '"'; },
			$skill_ids
		);
		$rows = nw_supabase_request(
			'GET',
			'cyber_skills',
			array(
				'select'    => 'id,is_active',
				'id'        => 'in.(' . implode( ',', $quoted ) . ')',
				'is_active' => 'eq.true',
			)
		);
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		return $rows;
	}
}

if ( ! function_exists( 'nw_find_tag_defs_by_ids' ) ) {
	function nw_find_tag_defs_by_ids( array $tag_ids ) {
		$tag_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'nw_sanitize_int_id', $tag_ids )
				)
			)
		);
		if ( empty( $tag_ids ) ) {
			return array();
		}
		$in_list = implode( ',', $tag_ids );
		$rows    = nw_supabase_request(
			'GET',
			'cyber_character_tag_defs',
			array(
				'select' => 'id,label,category,icon,color,description,source,gm',
				'id'     => 'in.(' . $in_list . ')',
				'limit'  => count( $tag_ids ),
			)
		);
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		$rows_by_id = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) ) {
				$rows_by_id[ (int) $row['id'] ] = $row;
			}
		}
		$ordered = array();
		foreach ( $tag_ids as $tag_id ) {
			if ( isset( $rows_by_id[ $tag_id ] ) ) {
				$ordered[] = $rows_by_id[ $tag_id ];
			}
		}
		return $ordered;
	}
}

// ─── AJAX GET handlers ───────────────────────────────────────────────────────

if ( ! function_exists( 'nw_get_races_handler' ) ) {
	function nw_get_races_handler(): void {
		$rows = nw_fetch_lookup_table(
			'cyber_races',
			'id,name,description,tags,bonus,img_url,parent_race',
			'name.asc',
			300,
			array( 'parent_race' => 'is.null' )
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $rows ) ) );
	}
	add_action( 'wp_ajax_neoweaver_get_races', 'nw_get_races_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'nw_get_races_handler' );
}

if ( ! function_exists( 'nw_get_subraces_handler' ) ) {
	function nw_get_subraces_handler(): void {
		$parent = sanitize_text_field( $_POST['parent'] ?? $_GET['parent'] ?? '' );
		if ( '' === $parent ) {
			wp_send_json_error( array( 'message' => 'Parent race required.' ), 400 );
			return;
		}
		$rows = nw_fetch_lookup_table(
			'cyber_races',
			'id,name,description,tags,bonus,img_url,parent_race',
			'name.asc',
			300,
			array( 'parent_race' => 'eq.' . $parent )
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $rows ) ) );
	}
	add_action( 'wp_ajax_neoweaver_get_subraces', 'nw_get_subraces_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'nw_get_subraces_handler' );
}

if ( ! function_exists( 'nw_get_classes_handler' ) ) {
	function nw_get_classes_handler(): void {
		$rows = nw_fetch_lookup_table(
			'cyber_classes',
			'id,name,description,tags,img_url,icon_slug,skill_limit',
			'name.asc',
			100,
			array( 'is_active' => 'eq.true' )
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		wp_send_json_success( nw_map_class_card_shape( nw_resolve_img_urls( $rows ) ) );
	}
	add_action( 'wp_ajax_neoweaver_get_classes', 'nw_get_classes_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'nw_get_classes_handler' );
}

if ( ! function_exists( 'nw_get_skills_handler' ) ) {
	function nw_get_skills_handler(): void {
		$class_id = nw_sanitize_text_id( $_POST['class_id'] ?? $_GET['class_id'] ?? '' );
		$extra    = array( 'is_active' => 'eq.true' );
		if ( '' !== $class_id ) {
			$extra['class_id'] = 'eq.' . $class_id;
		}
		$rows = nw_fetch_lookup_table(
			'cyber_skills',
			'id,name,description,category,application,card_effect,img_url,tags,linked_attributes',
			'name.asc',
			300,
			$extra
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		wp_send_json_success( nw_map_skill_card_shape( nw_resolve_img_urls( $rows ) ) );
	}
	add_action( 'wp_ajax_neoweaver_get_skills', 'nw_get_skills_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_skills', 'nw_get_skills_handler' );
}

if ( ! function_exists( 'nw_get_starting_packages_handler' ) ) {
	function nw_get_starting_packages_handler(): void {
		$class_name = sanitize_text_field( $_POST['class_name'] ?? $_GET['class_name'] ?? '' );
		$rows       = nw_fetch_lookup_table(
			'cyber_starting_packages',
			'id,package_name,description,items_list,compatibility_tags,compatible_class_ids,attack_cards_pool,defense_cards_pool,base_armor',
			'package_name.asc',
			100,
			array( 'is_player_selectable' => 'eq.true' )
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		$filtered = '' !== $class_name ? nw_filter_packages_by_class_name( $rows, $class_name ) : $rows;
		wp_send_json_success( nw_map_starting_package_shape( $filtered ) );
	}
	add_action( 'wp_ajax_neoweaver_get_starting_packages', 'nw_get_starting_packages_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_starting_packages', 'nw_get_starting_packages_handler' );
}

if ( ! function_exists( 'nw_get_backstory_tags_handler' ) ) {
	function nw_get_backstory_tags_handler(): void {
		$rows = nw_fetch_lookup_table(
			'cyber_character_tag_defs',
			'id,label,category,icon,color,description',
			'category.asc,label.asc',
			500,
			array( 'gm' => 'eq.false' )
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 500 );
			return;
		}
		wp_send_json_success( $rows );
	}
	add_action( 'wp_ajax_neoweaver_get_backstory_tags', 'nw_get_backstory_tags_handler' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_backstory_tags', 'nw_get_backstory_tags_handler' );
}
