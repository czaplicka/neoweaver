<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
        return isset( $row['id'] ) ? (string) $row['id'] : '';
    },
    is_array( $defs ) ? $defs : array()
);

$requested_str = array_map( 'strval', $requested );
$missing = array_values( array_diff( $requested_str, $found ) );

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

    $race_row = nw_find_race_by_id( $race_id_input );

    if ( empty( $race_row['id'] ) || ! is_string( $race_row['id'] ) ) {
        return new WP_Error( 'invalid_race', 'Selected parent race does not exist.', array( 'status' => 400 ) );
    }

    $race_parent = isset( $race_row['parent_race'] ) ? (string) $race_row['parent_race'] : '';

    if ( '' !== $race_parent ) {
        return new WP_Error( 'invalid_race', 'Selected race must be a parent race.', array( 'status' => 400 ) );
    }

    $subrace_row = nw_find_race_by_id( $subrace_id_input );

    if ( empty( $subrace_row['id'] ) || ! is_string( $subrace_row['id'] ) ) {
        return new WP_Error( 'invalid_subrace', 'Selected subrace does not exist.', array( 'status' => 400 ) );
    }

    $subrace_parent = trim( strtolower( (string) ( $subrace_row['parent_race'] ?? '' ) ) );

    if ( '' === $subrace_parent ) {
        return new WP_Error( 'invalid_subrace', 'Selected subrace is not linked to a parent race.', array( 'status' => 400 ) );
    }

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
			return new WP_Error(
				'invalid_character',
				'Invalid character ID for skills.',
				array( 'status' => 400 )
			);
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

		$result = nw_supabase_request(
			'POST',
			'cyber_character_skills',
			array(),
			$payload,
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || count( $result ) !== count( $payload ) ) {
			return new WP_Error(
				'skills_insert_incomplete',
				'Character skills could not be fully saved.',
				array(
					'status'   => 500,
					'expected' => count( $payload ),
					'received' => is_array( $result ) ? count( $result ) : 0,
				)
			);
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
if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
    function tw_supabase_get_admin( string $table, array $query = array() ) {
        if ( ! function_exists( 'tw_supabase_rest_base' ) ) {
            return new WP_Error( 'config_missing', 'tw_supabase_rest_base not available.', array( 'status' => 500 ) );
        }

        $service_key = defined( 'NW_SUPABASE_SERVICE_KEY' ) ? NW_SUPABASE_SERVICE_KEY : '';
        if ( '' === $service_key ) {
            return new WP_Error( 'config_missing', 'NW_SUPABASE_SERVICE_KEY not defined in wp-config.php.', array( 'status' => 500 ) );
        }

        $base = tw_supabase_rest_base();
        if ( empty( $base ) ) {
            return new WP_Error( 'config_missing', 'Supabase REST URL is empty.', array( 'status' => 500 ) );
        }

        $url = trailingslashit( $base ) . ltrim( $table, '/' );
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'apikey'        => $service_key,
                'Authorization' => 'Bearer ' . $service_key,
                'Accept'        => 'application/json',
            ),
            'timeout'   => 30,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = '' !== $raw ? json_decode( $raw, true ) : array();

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'supabase_http_error',
                is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Supabase admin request failed.',
                array( 'status' => $code, 'body' => $data )
            );
        }

        return is_array( $data ) ? $data : array();
    }
}
