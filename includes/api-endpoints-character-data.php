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

if ( ! function_exists( 'nw_supabase_request' ) ) {
    function nw_supabase_request( string $method, string $table, array $query = array(), $body = null, bool $return_representation = false ) {
        if ( ! function_exists( 'tw_supabase_rest_url' ) || ! function_exists( 'tw_supabase_headers' ) ) {
            return new WP_Error( 'config_missing', 'Supabase REST configuration missing.', array( 'status' => 500 ) );
        }

        $url = trailingslashit( tw_supabase_rest_url() ) . ltrim( $table, '/' );
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $headers = tw_supabase_headers();
        if ( $return_representation ) {
            $headers['Prefer'] = 'return=representation';
        }

        $method = strtoupper( $method );
        if ( in_array( $method, array( 'POST', 'PATCH' ), true ) ) {
            $headers['Content-Type'] = 'application/json';
        }

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = '' !== $raw ? json_decode( $raw, true ) : array();

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'supabase_http_error',
                ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : 'Supabase request failed.',
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
        $class_name = strtolower( trim( $class_name ) );

        return array_map(
            static function ( $row ) use ( $class_name ) {
                $compatibility_tags = nw_decode_jsonb_array( $row['compatibility_tags'] ?? array() );
                if ( '' !== $class_name ) {
                    $compatibility_tags = array_values(
                        array_filter(
                            $compatibility_tags,
                            static function ( $tag ) use ( $class_name ) {
                                return strtolower( trim( (string) $tag ) ) !== $class_name;
                            }
                        )
                    );
                }

                return array(
                    'id'                => (string) ( $row['id'] ?? '' ),
                    'name'              => (string) ( $row['package_name'] ?? '' ),
                    'package_name'      => (string) ( $row['package_name'] ?? '' ),
                    'description'       => (string) ( $row['description'] ?? '' ),
                    'items'             => nw_decode_jsonb_array( $row['items_list'] ?? array() ),
                    'items_list'        => nw_decode_jsonb_array( $row['items_list'] ?? array() ),
                    'compatibility_tags'=> $compatibility_tags,
                    'attack_cards_pool' => nw_decode_jsonb_array( $row['attack_cards_pool'] ?? array() ),
                    'defense_cards_pool'=> nw_decode_jsonb_array( $row['defense_cards_pool'] ?? array() ),
                    'base_armor'        => isset( $row['base_armor'] ) ? (int) $row['base_armor'] : 0,
                );
            },
            $rows
        );
    }
}

if ( ! function_exists( 'nw_filter_packages_by_class_name' ) ) {
    function nw_filter_packages_by_class_name( array $rows, string $class_name ): array {
        $class_name = strtolower( trim( $class_name ) );
        if ( '' === $class_name ) {
            return array();
        }

        return array_values(
            array_filter(
                $rows,
                static function ( $row ) use ( $class_name ) {
                    $tags = nw_decode_jsonb_array( $row['compatibility_tags'] ?? array() );
                    $tags = array_map( 'strtolower', $tags );
                    return in_array( $class_name, $tags, true );
                }
            )
        );
    }
}

if ( ! function_exists( 'nw_find_race_by_id' ) ) {
    function nw_find_race_by_id( string $race_id ) {
        $race_id = sanitize_text_field( $race_id );
        if ( '' === $race_id ) {
            return null;
        }

        $rows = nw_supabase_request(
            'GET',
            'cyber_races',
            array(
                'select' => 'id,name,parent_race',
                'id'     => 'eq.' . $race_id,
                'limit'  => 1,
            )
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
        if ( '' === $package_id ) {
            return null;
        }

        $rows = nw_supabase_request(
            'GET',
            'cyber_starting_packages',
            array(
                'select'                 => 'id,package_name,compatibility_tags,is_player_selectable',
                'id'                     => 'eq.' . $package_id,
                'is_player_selectable'   => 'eq.true',
                'limit'                  => 1,
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
        if ( '' === $package_id ) {
            return true;
        }

        $class_row = nw_find_class_by_id( $class_id );
        if ( empty( $class_row ) || empty( $class_row['id'] ) || empty( $class_row['is_active'] ) ) {
            return new WP_Error( 'invalid_class', 'Selected class does not exist or is inactive.', array( 'status' => 400 ) );
        }

        $package_row = nw_find_starting_package_by_id( $package_id );
        if ( empty( $package_row ) || empty( $package_row['id'] ) ) {
            return new WP_Error( 'invalid_starting_package', 'Selected starting package does not exist or is not player selectable.', array( 'status' => 400 ) );
        }

        $class_name = strtolower( trim( (string) ( $class_row['name'] ?? '' ) ) );
        if ( '' === $class_name ) {
            return new WP_Error( 'invalid_class', 'Selected class has no valid name.', array( 'status' => 400 ) );
        }

        $package_tags = array_map( 'strtolower', nw_decode_jsonb_array( $package_row['compatibility_tags'] ?? array() ) );
        if ( empty( $package_tags ) ) {
            return new WP_Error( 'invalid_starting_package', 'Selected starting package has no compatibility tags.', array( 'status' => 400 ) );
        }

        if ( ! in_array( $class_name, $package_tags, true ) ) {
            return new WP_Error( 'starting_package_mismatch', 'Selected starting package is not compatible with this class.', array( 'status' => 400 ) );
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
    function nw_find_tag_defs_by_ids( array $tag_ids ): array {
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

        $rows = nw_supabase_request(
            'GET',
            'cyber_character_tag_defs',
            array(
                'select' => 'id,label,category,icon,color,description,source,gm',
                'id'     => 'in.(' . implode( ',', $tag_ids ) . ')',
            )
        );

        if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
            return array();
        }
        return $rows;
    }
}

if ( ! function_exists( 'nw_validate_backstory_tags' ) ) {
    function nw_validate_backstory_tags( array $tag_ids ) {
        $requested = array_values(
            array_unique(
                array_filter(
                    array_map( 'nw_sanitize_int_id', $tag_ids )
                )
            )
        );

        if ( empty( $requested ) ) {
            return new WP_Error( 'backstory_tags_required', 'Backstory tags are required.', array( 'status' => 400 ) );
        }

        $defs   = nw_find_tag_defs_by_ids( $requested );
        $found  = array_map( static function ( $row ) { return isset( $row['id'] ) ? (int) $row['id'] : 0; }, $defs );
        $missing = array_values( array_diff( $requested, $found ) );

        if ( ! empty( $missing ) ) {
            return new WP_Error( 'invalid_backstory_tags', 'One or more backstory tag IDs do not exist.', array( 'status' => 400 ) );
        }

        return true;
    }
}

if ( ! function_exists( 'nw_validate_race_selection' ) ) {
    function nw_validate_race_selection( string $race_id_input, string $subrace_id_input ) {
        $race_id_input    = sanitize_text_field( $race_id_input );
        $subrace_id_input = sanitize_text_field( $subrace_id_input );

        if ( '' === $race_id_input ) {
            return new WP_Error( 'race_required', 'Race is required.', array( 'status' => 400 ) );
        }

        $race_row = nw_find_race_by_id( $race_id_input );
        if ( empty( $race_row['id'] ) || ! is_string( $race_row['id'] ) ) {
            return new WP_Error( 'invalid_race', 'Selected race does not exist.', array( 'status' => 400 ) );
        }

        $race_parent = isset( $race_row['parent_race'] ) ? (string) $race_row['parent_race'] : '';
        if ( '' !== $race_parent ) {
            return new WP_Error( 'invalid_race', 'Selected race must be a parent race.', array( 'status' => 400 ) );
        }

        if ( '' === $subrace_id_input ) {
            return array(
                'stored_race_id' => $race_row['id'],
                'race_row'       => $race_row,
                'subrace_row'    => null,
            );
        }

        $subrace_row = nw_find_race_by_id( $subrace_id_input );
        if ( empty( $subrace_row['id'] ) || ! is_string( $subrace_row['id'] ) ) {
            return new WP_Error( 'invalid_subrace', 'Selected subrace does not exist.', array( 'status' => 400 ) );
        }

        $subrace_parent = isset( $subrace_row['parent_race'] ) ? (string) $subrace_row['parent_race'] : '';
        if ( '' === $subrace_parent ) {
            return new WP_Error( 'invalid_subrace', 'Selected subrace is not linked to a parent race.', array( 'status' => 400 ) );
        }
        if ( $subrace_parent !== $race_row['name'] && $subrace_parent !== $race_row['id'] ) {
            return new WP_Error( 'subrace_mismatch', 'Selected subrace does not belong to the chosen race.', array( 'status' => 400 ) );
        }

        return array(
            'stored_race_id' => $subrace_row['id'],
            'race_row'       => $race_row,
            'subrace_row'    => $subrace_row,
        );
    }
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
            return new WP_Error( 'avatar_upload_error', 'Avatar upload returned no file URL.', array( 'status' => 400 ) );
        }

        return esc_url_raw( $uploaded['url'] );
    }
}

if ( ! function_exists( 'nw_map_pronouns_to_gender' ) ) {
    function nw_map_pronouns_to_gender( string $pronouns ) {
        $pronouns = strtolower( trim( sanitize_text_field( $pronouns ) ) );
        switch ( $pronouns ) {
            case 'he':
            case 'he/him':
            case 'he-him':
                return 'male';
            case 'she':
            case 'she/her':
            case 'she-her':
                return 'female';
            case 'they':
            case 'they/them':
            case 'they-them':
                return 'non-binary';
            case 'xe':
            case 'xe/xem':
            case 'xe-xem':
                return 'sexless';
            case 'custom':
                return 'non-binary';
            default:
                return null;
        }
    }
}

if ( ! function_exists( 'neoweaver_ajax_get_races' ) ) {
    function neoweaver_ajax_get_races(): void {
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $data = nw_fetch_lookup_table(
            'cyber_races',
            'id,name,description,tags,bonus,img_url,parent_race',
            'name.asc',
            300,
            array( 'parent_race' => 'is.null' )
        );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
    }
    add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_ajax_get_races' );
    add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_ajax_get_races' );
}

if ( ! function_exists( 'neoweaver_ajax_get_subraces' ) ) {
    function neoweaver_ajax_get_subraces(): void {
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $parent = sanitize_text_field( $_POST['parent'] ?? '' );
        if ( '' === $parent ) {
            wp_send_json_success( array() );
        }

        $data = nw_fetch_lookup_table(
            'cyber_races',
            'id,name,description,tags,bonus,img_url,parent_race',
            'name.asc',
            300,
            array( 'parent_race' => 'eq.' . $parent )
        );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        wp_send_json_success( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
    }
    add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );
    add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );
}

if ( ! function_exists( 'neoweaver_ajax_get_classes' ) ) {
    function neoweaver_ajax_get_classes(): void {
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $data = nw_fetch_lookup_table(
            'cyber_classes',
            'id,name,description,tags,img_url,icon_slug,skill_limit,is_active',
            'name.asc',
            300,
            array( 'is_active' => 'eq.true' )
        );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        wp_send_json_success( nw_map_class_card_shape( nw_resolve_img_urls( $data ) ) );
    }
    add_action( 'wp_ajax_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );
    add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );
}

if ( ! function_exists( 'neoweaver_ajax_get_skills' ) ) {
    function neoweaver_ajax_get_skills(): void {
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $data = nw_fetch_lookup_table(
            'cyber_skills',
            'id,name,description,category,application,card_effect,img_url,tags,linked_attributes,is_active',
            'category.asc,name.asc',
            300,
            array( 'is_active' => 'eq.true' )
        );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        wp_send_json_success( nw_map_skill_card_shape( nw_resolve_img_urls( $data ) ) );
    }
    add_action( 'wp_ajax_neoweaver_get_skills', 'neoweaver_ajax_get_skills' );
    add_action( 'wp_ajax_nopriv_neoweaver_get_skills', 'neoweaver_ajax_get_skills' );
}

if ( ! function_exists( 'neoweaver_ajax_get_packages' ) ) {
    function neoweaver_ajax_get_packages(): void {
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $class_id = sanitize_text_field(
            $_POST['class_tag'] ?? $_POST['classtag'] ?? $_POST['classId'] ?? ''
        );

        if ( '' === $class_id ) {
            wp_send_json_success( array() );
        }

        $class_row = nw_find_class_by_id( $class_id );
        if ( empty( $class_row ) || empty( $class_row['name'] ) ) {
            wp_send_json_success( array() );
        }

        $class_name = strtolower( trim( (string) $class_row['name'] ) );

        $data = nw_fetch_lookup_table(
            'cyber_starting_packages',
            'id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable',
            'package_name.asc',
            300,
            array(
                'is_player_selectable' => 'eq.true',
            )
        );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( array( 'message' => $data->get_error_message() ) );
        }

        $data = nw_filter_packages_by_class_name( $data, $class_name );

        wp_send_json_success( nw_map_starting_package_shape( $data, $class_name ) );
    }

    add_action( 'wp_ajax_neoweaver_get_packages', 'neoweaver_ajax_get_packages' );
    add_action( 'wp_ajax_nopriv_neoweaver_get_packages', 'neoweaver_ajax_get_packages' );
}
if ( ! function_exists( 'nw_create_character_from_request' ) ) {
    function nw_create_character_from_request(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ), 403 );
        }
        if ( false === check_ajax_referer( 'neoweaver_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
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

        $backstory_raw    = json_decode( wp_unslash( $_POST['backstorytags'] ?? $_POST['backstory_tags'] ?? '[]' ), true );
        $backstory_tags   = is_array( $backstory_raw ) ? array_values( array_unique( array_filter( array_map( 'nw_sanitize_int_id', $backstory_raw ) ) ) ) : array();

        if ( '' === $name ) {
            wp_send_json_error( array( 'message' => 'Character name is required.' ), 400 );
        }
        if ( '' === $pronouns ) {
            wp_send_json_error( array( 'message' => 'Pronouns are required.' ), 400 );
        }
        if ( '' === $class_id ) {
            wp_send_json_error( array( 'message' => 'Class is required.' ), 400 );
        }

        $body   = max( 1, min( 5, (int) ( $_POST['attrbody'] ?? $_POST['attr_body'] ?? 1 ) ) );
        $reflex = max( 1, min( 5, (int) ( $_POST['attrreflex'] ?? $_POST['attr_reflex'] ?? 1 ) ) );
        $mind   = max( 1, min( 5, (int) ( $_POST['attrmind'] ?? $_POST['attr_mind'] ?? 1 ) ) );
        $spirit = max( 1, min( 5, (int) ( $_POST['attrspirit'] ?? $_POST['attr_spirit'] ?? 1 ) ) );

        if ( 12 !== ( $body + $reflex + $mind + $spirit ) ) {
            wp_send_json_error( array( 'message' => 'Attribute total must equal 12.' ), 400 );
        }

        $race_validation = nw_validate_race_selection( $race_id_input, $subrace_id_input );
        if ( is_wp_error( $race_validation ) ) {
            wp_send_json_error( array( 'message' => $race_validation->get_error_message() ), 400 );
        }

        $skills_validation = nw_validate_skill_selection( $class_id, $skills );
        if ( is_wp_error( $skills_validation ) ) {
            wp_send_json_error( array( 'message' => $skills_validation->get_error_message() ), 400 );
        }

        $package_validation = nw_validate_starting_package_selection( $class_id, $start_pack );
        if ( is_wp_error( $package_validation ) ) {
            wp_send_json_error( array( 'message' => $package_validation->get_error_message() ), 400 );
        }

        $tags_validation = nw_validate_backstory_tags( $backstory_tags );
        if ( is_wp_error( $tags_validation ) ) {
            wp_send_json_error( array( 'message' => $tags_validation->get_error_message() ), 400 );
        }

        $avatar_upload_url = nw_handle_avatar_upload_strict();
        if ( is_wp_error( $avatar_upload_url ) ) {
            wp_send_json_error( array( 'message' => $avatar_upload_url->get_error_message() ), 400 );
        }

        $avatar_url_from_gallery = ! empty( $_POST['avatarurl'] ) ? esc_url_raw( wp_unslash( $_POST['avatarurl'] ) ) : '';
        $final_avatar_url = '';
        if ( is_string( $avatar_upload_url ) && '' !== $avatar_upload_url ) {
            $final_avatar_url = $avatar_upload_url;
        } elseif ( '' !== $avatar_url_from_gallery ) {
            $final_avatar_url = $avatar_url_from_gallery;
        }

        $gender = nw_map_pronouns_to_gender( $pronouns );
        if ( null === $gender ) {
            wp_send_json_error( array( 'message' => 'Pronouns value is invalid.' ), 400 );
        }

        $character_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'char_', true );

        $payload = array(
            'id'         => $character_id,
            'name'       => $name,
            'wp_user_id' => $user_id,
            'gender'     => $gender,
            'bio'        => '' !== $bio ? $bio : null,
            'avatar'     => '' !== $final_avatar_url ? $final_avatar_url : null,
            'body'       => $body,
            'reflex'     => $reflex,
            'mind'       => $mind,
            'spirit'     => $spirit,
            'class_id'   => $class_id,
            'race_id'    => $race_validation['stored_race_id'],
            'start_pack' => '' !== $start_pack ? $start_pack : null,
            'is_public'  => false,
            'gold'       => 0,
        );

        $result = nw_supabase_request( 'POST', 'cyber_characters', array(), $payload, true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
        }

        $skills_store = nw_store_character_skills( $character_id, $skills );
        if ( is_wp_error( $skills_store ) ) {
            nw_delete_character_by_id( $character_id );
            wp_send_json_error( array( 'message' => 'Character could not be saved with skills.' ), 500 );
        }

        $tags_store = nw_store_character_backstory_tags( $character_id, $backstory_tags );
        if ( is_wp_error( $tags_store ) ) {
            nw_delete_character_by_id( $character_id );
            wp_send_json_error( array( 'message' => 'Character could not be saved with backstory tags.' ), 500 );
        }

        $redirect = home_url( '/character/' . rawurlencode( $character_id ) . '/' );
        wp_send_json_success(
            array(
                'message'      => 'Character created successfully.',
                'character_id' => $character_id,
                'redirect'     => $redirect,
            )
        );
    }
    add_action( 'wp_ajax_neoweaver_create_character', 'nw_create_character_from_request' );
}

if ( ! function_exists( 'neoweaver_register_character_lookup_routes' ) ) {
    function neoweaver_register_character_lookup_routes(): void {
        register_rest_route(
            'neoweaver/v1',
            '/races',
            array(
                'methods'             => 'GET',
                'callback'            => function () {
                    $data = nw_fetch_lookup_table(
                        'cyber_races',
                        'id,name,description,tags,bonus,img_url,parent_race',
                        'name.asc',
                        300,
                        array( 'parent_race' => 'is.null' )
                    );
                    if ( is_wp_error( $data ) ) {
                        return $data;
                    }
                    return rest_ensure_response( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'neoweaver/v1',
            '/subraces',
            array(
                'methods'             => 'GET',
                'callback'            => function ( WP_REST_Request $request ) {
                    $parent = sanitize_text_field( (string) $request->get_param( 'parent' ) );
                    if ( '' === $parent ) {
                        return rest_ensure_response( array() );
                    }
                    $data = nw_fetch_lookup_table(
                        'cyber_races',
                        'id,name,description,tags,bonus,img_url,parent_race',
                        'name.asc',
                        300,
                        array( 'parent_race' => 'eq.' . $parent )
                    );
                    if ( is_wp_error( $data ) ) {
                        return $data;
                    }
                    return rest_ensure_response( nw_map_race_card_shape( nw_resolve_img_urls( $data ) ) );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'neoweaver/v1',
            '/classes',
            array(
                'methods'             => 'GET',
                'callback'            => function () {
                    $data = nw_fetch_lookup_table(
                        'cyber_classes',
                        'id,name,description,tags,img_url,icon_slug,skill_limit,is_active',
                        'name.asc',
                        300,
                        array( 'is_active' => 'eq.true' )
                    );
                    if ( is_wp_error( $data ) ) {
                        return $data;
                    }
                    return rest_ensure_response( nw_map_class_card_shape( nw_resolve_img_urls( $data ) ) );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'neoweaver/v1',
            '/skills',
            array(
                'methods'             => 'GET',
                'callback'            => function () {
                    $data = nw_fetch_lookup_table(
                        'cyber_skills',
                        'id,name,description,category,application,card_effect,img_url,tags,linked_attributes,is_active',
                        'category.asc,name.asc',
                        300,
                        array( 'is_active' => 'eq.true' )
                    );
                    if ( is_wp_error( $data ) ) {
                        return $data;
                    }
                    return rest_ensure_response( nw_map_skill_card_shape( nw_resolve_img_urls( $data ) ) );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'neoweaver/v1',
            '/starting-packages',
            array(
                'methods'             => 'GET',
                'callback'            => function ( WP_REST_Request $request ) {
                    $class_id = sanitize_text_field( (string) $request->get_param( 'class_tag' ) );
                    if ( '' === $class_id ) {
                        return rest_ensure_response( array() );
                    }
                    $class_row = nw_find_class_by_id( $class_id );
                    if ( empty( $class_row ) || empty( $class_row['name'] ) ) {
                        return rest_ensure_response( array() );
                    }
                    $class_name = strtolower( trim( (string) $class_row['name'] ) );
                    $data = nw_fetch_lookup_table(
                        'cyber_starting_packages',
                        'id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable',
                        'package_name.asc',
                        300,
                        array( 'is_player_selectable' => 'eq.true' )
                    );
                    if ( is_wp_error( $data ) ) {
                        return $data;
                    }
                    $data = nw_filter_packages_by_class_name( $data, $class_name );
                    return rest_ensure_response( nw_map_starting_package_shape( $data, $class_name ) );
                },
                'permission_callback' => '__return_true',
            )
        );
    }
    add_action( 'rest_api_init', 'neoweaver_register_character_lookup_routes' );
}
?>
