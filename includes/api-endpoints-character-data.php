<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
        $value = preg_replace( '/[^a-z0-9\-\s]+/', '', $value );
        $value = preg_replace( '/[\s\-]+/', '-', $value );
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

    error_log( 'NW SUPABASE REQUEST ' . $method . ' ' . $url );
    if ( ! empty( $query ) ) {
        error_log( 'NW SUPABASE QUERY ARGS ' . wp_json_encode( $query ) );
    }

    $method = strtoupper( $method );

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
        error_log( 'NW SUPABASE BODY ' . wp_json_encode( $body ) );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'NW SUPABASE WP ERROR ' . $response->get_error_message() );
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $data = $raw !== '' ? json_decode( $raw, true ) : array();
if ( $raw !== '' && null === $data && JSON_ERROR_NONE !== json_last_error() ) {
    error_log( 'NW SUPABASE JSON ERROR ' . json_last_error_msg() );
    return new WP_Error(
        'supabase_invalid_json',
        'Supabase returned an invalid JSON response.',
        array(
            'status' => 502,
            'raw'    => $raw,
        )
    );
}
    error_log( 'NW SUPABASE RESPONSE CODE ' . $code );
    error_log( 'NW SUPABASE RESPONSE RAW ' . $raw );

    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error(
            'supabase_http_error',
            is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Supabase request failed.',
            array(
                'status' => $code,
                'body'   => $data,
            )
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

        $params = array(
            'select' => $select_cols,
            'limit'  => $limit,
        );
        if ( '' !== $order ) {
            $params['order'] = $order;
        }
        foreach ( $extra_filters as $col => $filter ) {
            $params[ $col ] = $filter;
        }

        $data = tw_supabase_get( $table, $params );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'supabase_error', 'Database error.', array( 'status' => 500 ) );
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

if ( ! function_exists( 'nw_map_race_card_shape' ) ) {
    function nw_map_race_card_shape( array $rows ): array {
        return array_map(
            static function ( $row ) {
                $raw_tags  = nw_decode_jsonb_array( $row['tags'] ?? array() );
                $tags_out  = array_map( static function ( $tag ) { return array( 'name' => $tag ); }, $raw_tags );
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
	function nw_map_starting_package_shape( array $rows, string $class_name = '' ): array {
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

if ( ! function_exists( 'nw_filter_packages_by_class_name' ) ) {
    function nw_filter_packages_by_class_name( array $rows, string $class_name ): array {
        $class_name = strtolower( trim( (string) $class_name ) );

        error_log( 'NW class_name = ' . $class_name );
        error_log( 'NW packages raw = ' . print_r( $rows, true ) );

        $filtered = array_values(
            array_filter(
                $rows,
                static function ( $row ) use ( $class_name ) {
                    $tags = nw_decode_jsonb_array( $row['compatibility_tags'] ?? array() );
                    $tags = array_map(
                        static function ( $tag ) {
                            return strtolower( trim( (string) $tag ) );
                        },
                        $tags
                    );

                    error_log( 'NW package ' . ( $row['package_name'] ?? 'NO_NAME' ) . ' tags = ' . wp_json_encode( $tags ) );

                    return in_array( $class_name, $tags, true );
                }
            )
        );

        error_log( 'NW filtered count = ' . count( $filtered ) );

        return $filtered;
    }
}

if ( ! function_exists( 'nw_find_race_by_id' ) ) {
    function nw_find_race_by_id( string $race_id ) {
        $race_id = sanitize_text_field( $race_id );
        if ( '' === $race_id ) {
            return null;
        }
		  error_log('=== NW_FIND_RACE_BY_ID ===');
  error_log('Looking for race_id: ' . $race_id);

        $rows = nw_supabase_request(
            'GET',
            'cyber_races',
            array(
                'select' => 'id,name,parent_race',
                'id'     => 'eq.' . $race_id,
                'limit'  => 1,
            )
        );
 error_log('Supabase response: ' . print_r($rows, true));
        if ( is_wp_error( $rows ) || empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
		    error_log('Race NOT FOUND or error');
            return null;
        }
		  error_log('Race FOUND: ' . print_r($rows[0], true));
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
            array(
                'select' => 'id,name,skill_limit,is_active',
                'id'     => 'eq.' . $class_id,
                'limit'  => 1,
            )
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

if ( ! function_exists( 'nw_validate_starting_package_selection' ) ) {
	function nw_validate_starting_package_selection( string $class_id, string $package_id ) {
		if ( ! $package_id ) {
			return true;
		}

		$class_row = nw_find_class_by_id( $class_id );
		if ( empty( $class_row ) || empty( $class_row['id'] ) || empty( $class_row['is_active'] ) ) {
			return new WP_Error(
				'invalid_class',
				'Selected class does not exist or is inactive.',
				array( 'status' => 400 )
			);
		}

		$package_row = nw_find_starting_package_by_id( $package_id );
		if ( empty( $package_row ) || empty( $package_row['id'] ) ) {
			return new WP_Error(
				'invalid_starting_package',
				'Selected starting package does not exist or is not player selectable.',
				array( 'status' => 400 )
			 );
		}

		$package_class_ids = array_map(
			static function ( $id ) {
				return sanitize_text_field( (string) $id );
			},
			nw_decode_jsonb_array( $package_row['compatible_class_ids'] ?? array() )
		);

		if ( empty( $package_class_ids ) ) {
			return new WP_Error(
				'invalid_starting_package',
				'Selected starting package has no compatible class IDs.',
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $class_id, $package_class_ids, true ) ) {
			return new WP_Error(
				'starting_package_mismatch',
				'Selected starting package is not compatible with this class.',
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
if ( ! function_exists( 'nw_find_skills_by_ids' ) ) {
    function nw_find_skills_by_ids( array $skill_ids ): array {
        $skill_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $id ) {
                            return sanitize_text_field( (string) $id );
                        },
                        $skill_ids
                    )
                )
            )
        );

        if ( empty( $skill_ids ) ) {
            return array();
        }

        $quoted = array_map(
            static function ( $id ) {
                return '"' . str_replace( '"', '\\"', $id ) . '"';
            },
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

if ( ! function_exists( 'nw_validate_skill_selection' ) ) {
    function nw_validate_skill_selection( string $class_id, array $skills ) {
        $class_row = nw_find_class_by_id( $class_id );
        if ( empty( $class_row ) || empty( $class_row['id'] ) || empty( $class_row['is_active'] ) ) {
            return new WP_Error( 'invalid_class', 'Selected class does not exist or is inactive.', array( 'status' => 400 ) );
        }

        $skills = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $id ) {
                            return sanitize_text_field( (string) $id );
                        },
                        $skills
                    )
                )
            )
        );

        $limit = isset( $class_row['skill_limit'] ) ? (int) $class_row['skill_limit'] : 5;
        $limit = $limit > 0 ? $limit : 5;

        if ( empty( $skills ) ) {
            return new WP_Error( 'skills_required', 'At least one skill must be selected.', array( 'status' => 400 ) );
        }
        if ( count( $skills ) > $limit ) {
            return new WP_Error( 'skill_limit_exceeded', 'Too many skills selected for this class.', array( 'status' => 400 ) );
        }

        $skill_rows = nw_find_skills_by_ids( $skills );
        $found_ids  = array_map(
            static function ( $row ) {
                return (string) ( $row['id'] ?? '' );
            },
            $skill_rows
        );
        $missing = array_values( array_diff( $skills, $found_ids ) );
        if ( ! empty( $missing ) ) {
            return new WP_Error( 'invalid_skills', 'One or more selected skills are invalid or inactive.', array( 'status' => 400 ) );
        }

        return true;
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

		$rows = nw_supabase_request(
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

    if ( ! function_exists( 'nw_validate_backstory_tags' ) ) {
	function nw_validate_backstory_tags( array $tag_ids ) {
		error_log( 'NW BACKSTORY raw tag_ids ' . wp_json_encode( $tag_ids ) );

		$requested = array_values(
			array_unique(
				array_filter(
					array_map( 'nw_sanitize_int_id', $tag_ids )
				)
			)
		);

		error_log( 'NW BACKSTORY requested sanitized IDs ' . wp_json_encode( $requested ) );

		if ( empty( $requested ) ) {
			error_log( 'NW BACKSTORY requested is empty -> backstory_tags_required' );
			return new WP_Error(
				'backstory_tags_required',
				'Backstory tags are required.',
				array( 'status' => 400 )
			);
		}

		$defs = nw_find_tag_defs_by_ids( $requested );

		if ( is_wp_error( $defs ) ) {
			error_log( 'NW BACKSTORY nw_find_tag_defs_by_ids returned WP_Error ' . $defs->get_error_message() );
		} else {
			error_log( 'NW BACKSTORY defs from Supabase ' . wp_json_encode( $defs ) );
		}

		$found = array_map(
			static function ( $row ) {
				return isset( $row['id'] ) ? (int) $row['id'] : 0;
			},
			is_array( $defs ) ? $defs : array()
		);

		$missing = array_values( array_diff( $requested, $found ) );

		error_log( 'NW BACKSTORY found IDs ' . wp_json_encode( $found ) );
		error_log( 'NW BACKSTORY missing IDs ' . wp_json_encode( $missing ) );

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'invalid_backstory_tags',
				'One or more backstory tag IDs do not exist.',
				array(
					'status'  => 400,
					'missing' => $missing,
				)
			);
		}

		return true;
	}
}
function nw_validate_race_selection( string $race_id_input, string $subrace_id_input ) {
    $race_id_input    = sanitize_text_field( $race_id_input );
    $subrace_id_input = sanitize_text_field( $subrace_id_input );

    if ( ! $race_id_input ) {
        return new WP_Error( 'race_required', 'Parent race is required.', array( 'status' => 400 ) );
    }

    if ( ! $subrace_id_input ) {
        return new WP_Error( 'subrace_required', 'Subrace is required.', array( 'status' => 400 ) );
    }

    // 1. Pobierz i zwaliduj rasę nadrzędną
    $race_row = nw_find_race_by_id( $race_id_input );

    if ( empty( $race_row['id'] ) || ! is_string( $race_row['id'] ) ) {
        return new WP_Error( 'invalid_race', 'Selected parent race does not exist.', array( 'status' => 400 ) );
    }

    $race_parent = isset( $race_row['parent_race'] ) ? (string) $race_row['parent_race'] : '';

    if ( '' !== $race_parent ) {
        return new WP_Error( 'invalid_race', 'Selected race must be a parent race.', array( 'status' => 400 ) );
    }

    // 2. Pobierz i zwaliduj subrasę
    $subrace_row = nw_find_race_by_id( $subrace_id_input );

    if ( empty( $subrace_row['id'] ) || ! is_string( $subrace_row['id'] ) ) {
        return new WP_Error( 'invalid_subrace', 'Selected subrace does not exist.', array( 'status' => 400 ) );
    }

    $subrace_parent = trim( strtolower( (string) ( $subrace_row['parent_race'] ?? '' ) ) );

    if ( '' === $subrace_parent ) {
        return new WP_Error( 'invalid_subrace', 'Selected subrace is not linked to a parent race.', array( 'status' => 400 ) );
    }

    // 3. Porównaj parent_race subrasy z nazwą lub id rasy (case-insensitive)
    $race_name_normalized = trim( strtolower( (string) ( $race_row['name'] ?? '' ) ) );
    $race_id_normalized   = trim( strtolower( (string) ( $race_row['id']   ?? '' ) ) );

    if ( $subrace_parent !== $race_name_normalized && $subrace_parent !== $race_id_normalized ) {
        return new WP_Error( 'subrace_mismatch', 'Selected subrace does not belong to the chosen race.', array( 'status' => 400 ) );
    }

    return array(
        'stored_race_id' => $subrace_row['id'],
        'race_row'       => $race_row,
        'subrace_row'    => $subrace_row,
    );
}

if ( ! function_exists( 'nw_store_character_skills' ) ) {
    function nw_store_character_skills( string $character_id, array $skills ) {
        $character_id = sanitize_text_field( $character_id );
        if ( '' === $character_id ) {
            return new WP_Error( 'invalid_character', 'Invalid character ID for skills.', array( 'status' => 400 ) );
        }

        $skills = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $id ) {
                            return sanitize_text_field( (string) $id );
                        },
                        $skills
                    )
                )
            )
        );

        if ( empty( $skills ) ) {
            return true;
        }

        $payload = array();
        foreach ( $skills as $skill_id ) {
            $payload[] = array(
                'character_id' => $character_id,
                'skill_id'     => $skill_id,
                'proficiency'  => 1,
                'source'       => 'character_creator',
            );
        }

        $result = nw_supabase_request( 'POST', 'cyber_character_skills', array(), $payload, false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }
}

if ( ! function_exists( 'nw_store_character_backstory_tags' ) ) {
    function nw_store_character_backstory_tags( string $character_id, array $tag_ids ) {
        $character_id = sanitize_text_field( $character_id );
        if ( '' === $character_id ) {
            return new WP_Error( 'invalid_character', 'Invalid character ID for backstory tags.', array( 'status' => 400 ) );
        }

        $tag_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'nw_sanitize_int_id', $tag_ids )
                )
            )
        );

        if ( empty( $tag_ids ) ) {
            return true;
        }

        $payload = array();
        foreach ( $tag_ids as $tag_id ) {
            $payload[] = array(
                'character_id' => $character_id,
                'tag_id'       => $tag_id,
            );
        }

        $result = nw_supabase_request( 'POST', 'cyber_character_backstory_tags', array(), $payload, false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }
}

if ( ! function_exists( 'nw_delete_character_by_id' ) ) {
    function nw_delete_character_by_id( string $character_id ): bool {
        $character_id = sanitize_text_field( $character_id );
        if ( '' === $character_id ) {
            return false;
        }

        $result = nw_supabase_request(
            'DELETE',
            'cyber_characters',
            array(
                'id' => 'eq.' . $character_id,
            )
        );

        return ! is_wp_error( $result );
    }
}

if ( ! function_exists( 'nw_handle_avatar_upload_strict' ) ) {
    function nw_handle_avatar_upload_strict() {
        if ( empty( $_FILES['avatar'] ) || empty( $_FILES['avatar']['name'] ) ) {
            return '';
        }

        $file = $_FILES['avatar'];
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'avatar_upload_error', 'Avatar upload failed.', array( 'status' => 400 ) );
        }

        $max_size = 2 * 1024 * 1024;
        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
            return new WP_Error( 'avatar_too_large', 'Avatar must be 2 MB or smaller.', array( 'status' => 400 ) );
        }

        $allowed_mimes = array(
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
            'svg'      => 'image/svg+xml',
        );

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed_mimes, true ) ) {
            return new WP_Error( 'avatar_invalid_type', 'Avatar must be JPG, PNG, WEBP, or SVG.', array( 'status' => 400 ) );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploaded = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => $allowed_mimes,
            )
        );

        if ( ! empty( $uploaded['error'] ) ) {
            return new WP_Error( 'avatar_upload_error', 'Avatar could not be uploaded.', array( 'status' => 400 ) );
        }
        if ( empty( $uploaded['url'] ) ) {
            return new WP_Error( 'avatar_upload_error', 'Avatar URL missing after upload.', array( 'status' => 400 ) );
        }

        return esc_url_raw( $uploaded['url'] );
    }
}

/**
 * ---------------------------------------------------------------------------
 * nw_parse_attr_post
 *
 * Reads a single character attribute from $_POST with strict validation.
 *
 * Rules enforced:
 *  - Both spelling variants present simultaneously → WP_Error (prevents
 *    crafted requests that send conflicting values to manipulate the sum).
 *  - Neither spelling present                      → WP_Error (no silent
 *    default-of-1 that makes the sum check pass for partial requests).
 *  - Value outside [1, 5]                          → WP_Error.
 *
 * @param string $key1  Primary POST key   (e.g. 'attrbody').
 * @param string $key2  Alias POST key     (e.g. 'attr_body').
 * @return int|WP_Error Validated integer on success.
 * ---------------------------------------------------------------------------
 */
if ( ! function_exists( 'nw_parse_attr_post' ) ) {
    function nw_parse_attr_post( string $key1, string $key2 ) {
        $has1 = isset( $_POST[ $key1 ] );
        $has2 = isset( $_POST[ $key2 ] );

        if ( $has1 && $has2 ) {
            return new WP_Error(
                'attr_duplicate_key',
                /* translators: %1$s and %2$s are POST key names */
                sprintf( 'Duplicate attribute keys in request (%1$s / %2$s).', $key1, $key2 ),
                array( 'status' => 400 )
            );
        }

        if ( ! $has1 && ! $has2 ) {
            return new WP_Error(
                'attr_missing',
                sprintf( 'Missing required attribute: %s.', $key1 ),
                array( 'status' => 400 )
            );
        }

        $raw   = $has1 ? $_POST[ $key1 ] : $_POST[ $key2 ];
        $value = (int) $raw;

        if ( $value < 1 || $value > 5 ) {
            return new WP_Error(
                'attr_out_of_range',
                sprintf( 'Attribute %s must be between 1 and 5 (got %d).', $key1, $value ),
                array( 'status' => 400 )
            );
        }

        return $value;
    }
}

if ( ! function_exists( 'nw_create_character_from_request' ) ) {
    function nw_create_character_from_request(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ), 403 );
            return;
        }
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
            return;
        }

        $user_id          = get_current_user_id();
        $name             = sanitize_text_field( $_POST['charactername'] ?? $_POST['character_name'] ?? '' );
        $pronouns         = sanitize_text_field( $_POST['pronouns'] ?? '' );
        $bio              = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $race_id_input    = nw_sanitize_text_id( $_POST['race'] ?? '' );
        $subrace_id_input = nw_sanitize_text_id( $_POST['subrace'] ?? '' );
        $class_id         = nw_sanitize_text_id( $_POST['charclass'] ?? $_POST['char_class'] ?? '' );
        $start_pack       = nw_sanitize_text_id( $_POST['startingpackageid'] ?? $_POST['starting_package_id'] ?? '' );

        $skills_raw       = json_decode( wp_unslash( $_POST['skills'] ?? '[]' ), true );
        $skills           = is_array( $skills_raw ) ? array_values( array_unique( array_filter( array_map( 'nw_sanitize_text_id', $skills_raw ) ) ) ) : array();

        $backstoryraw = json_decode( wp_unslash( $_POST['backstorytags'] ?? $_POST['backstory_tags'] ?? '[]' ), true );

        $backstorytags = is_array( $backstoryraw )
            ? array_values( array_unique( array_filter(
                array_map(
                    static function ( $item ) {
                        return is_scalar( $item ) ? trim( (string) $item ) : '';
                    },
                    $backstoryraw
                )
            ) ) )
            : array();

        if ( '' === $name ) {
            wp_send_json_error( array( 'message' => 'Character name is required.' ), 400 );
            return;
        }
        if ( '' === $pronouns ) {
            wp_send_json_error( array( 'message' => 'Pronouns are required.' ), 400 );
            return;
        }
        if ( '' === $class_id ) {
            wp_send_json_error( array( 'message' => 'Class is required.' ), 400 );
            return;
        }

        // Attribute parsing: all four keys must be present; duplicate spellings rejected.
        $body   = nw_parse_attr_post( 'attrbody',   'attr_body' );
        $reflex = nw_parse_attr_post( 'attrreflex', 'attr_reflex' );
        $mind   = nw_parse_attr_post( 'attrmind',   'attr_mind' );
        $spirit = nw_parse_attr_post( 'attrspirit', 'attr_spirit' );

        foreach ( array( 'body' => $body, 'reflex' => $reflex, 'mind' => $mind, 'spirit' => $spirit ) as $_attr_name => $_attr_val ) {
            if ( is_wp_error( $_attr_val ) ) {
                wp_send_json_error( array( 'message' => $_attr_val->get_error_message() ), 400 );
                return;
            }
        }

        if ( 12 !== ( $body + $reflex + $mind + $spirit ) ) {
            wp_send_json_error( array( 'message' => 'Attribute total must equal 12.' ), 400 );
            return;
        }

        $race_validation = nw_validate_race_selection( $race_id_input, $subrace_id_input );
        if ( is_wp_error( $race_validation ) ) {
            wp_send_json_error( array( 'message' => $race_validation->get_error_message() ), 400 );
            return;
        }

        $skills_validation = nw_validate_skill_selection( $class_id, $skills );
        if ( is_wp_error( $skills_validation ) ) {
            wp_send_json_error( array( 'message' => $skills_validation->get_error_message() ), 400 );
            return;
        }

        $package_validation = nw_validate_starting_package_selection( $class_id, $start_pack );
        if ( is_wp_error( $package_validation ) ) {
            wp_send_json_error( array( 'message' => $package_validation->get_error_message() ), 400 );
            return;
        }

        $resolved_backstory_tag_ids = nw_resolve_backstory_tag_ids( $backstorytags );

        $tagsvalidation = nw_validate_backstory_tags( $resolved_backstory_tag_ids );
        if ( is_wp_error( $tagsvalidation ) ) {
            wp_send_json_error( array( 'message' => $tagsvalidation->get_error_message() ), 400 );
            return;
        }

        $avatar_upload_url = nw_handle_avatar_upload_strict();
        if ( is_wp_error( $avatar_upload_url ) ) {
            wp_send_json_error( array( 'message' => $avatar_upload_url->get_error_message() ), 400 );
            return;
        }

        $avatar_url_from_gallery = '';
        if ( isset( $_POST['avatarurlgallery'] ) ) {
            $avatar_url_from_gallery = nw_normalize_media_url( $_POST['avatarurlgallery'] );
        } elseif ( isset( $_POST['avatar_url'] ) ) {
            $avatar_url_from_gallery = nw_normalize_media_url( $_POST['avatar_url'] );
        }

        $final_avatar_url = '' !== $avatar_upload_url ? $avatar_upload_url : $avatar_url_from_gallery;

        $stored_race_id = $race_validation['stored_race_id'];

        $payload = array(
            'user_id'      => $user_id,
            'name'         => $name,
            'pronouns'     => $pronouns,
            'bio'          => $bio,
            'race_id'      => $stored_race_id,
            'class_id'     => $class_id,
            'attr_body'    => $body,
            'attr_reflex'  => $reflex,
            'attr_mind'    => $mind,
            'attr_spirit'  => $spirit,
            'avatar_url'   => $final_avatar_url,
            'package_id'   => $start_pack ?: null,
            'status'       => 'active',
        );

        $result = nw_supabase_request( 'POST', 'cyber_characters', array(), $payload, true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
            return;
        }

        $character = is_array( $result ) && isset( $result[0] ) ? $result[0] : ( is_array( $result ) ? $result : null );

        if ( empty( $character['id'] ) ) {
            wp_send_json_error( array( 'message' => 'Character creation failed — no ID returned.' ), 500 );
            return;
        }

        $character_id = (string) $character['id'];

        if ( ! empty( $skills ) ) {
            $skills_stored = nw_store_character_skills( $character_id, $skills );
            if ( is_wp_error( $skills_stored ) ) {
                nw_delete_character_by_id( $character_id );
                wp_send_json_error( array( 'message' => 'Skills could not be saved: ' . $skills_stored->get_error_message() ), 500 );
                return;
            }
        }

        if ( ! empty( $resolved_backstory_tag_ids ) ) {
            $tags_stored = nw_store_character_backstory_tags( $character_id, $resolved_backstory_tag_ids );
            if ( is_wp_error( $tags_stored ) ) {
                nw_delete_character_by_id( $character_id );
                wp_send_json_error( array( 'message' => 'Backstory tags could not be saved: ' . $tags_stored->get_error_message() ), 500 );
                return;
            }
        }

        wp_send_json_success(
            array(
                'message'      => 'Character created successfully.',
                'character_id' => $character_id,
                'character'    => $character,
            )
        );
    }

    add_action( 'wp_ajax_neoweaver_create_character', 'nw_create_character_from_request' );
}

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

        $extra = array( 'is_active' => 'eq.true' );
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

        $rows = nw_fetch_lookup_table(
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
        wp_send_json_success( nw_map_starting_package_shape( $filtered, $class_name ) );
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
