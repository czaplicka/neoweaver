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
	// TODO: nw_validate_starting_package_selection() confirms the package reference is valid
	// and stores package_id on the character row, but never applies the package contents
	// (items_list, attack_cards_pool, defense_cards_pool) to cyber_character_inventory or
	// the deck tables. This is a missing feature — applying the starting package and
	// ensuring it cannot be claimed twice needs its own implementation.
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
		$requested = array_values(
			array_unique(
				array_filter(
					array_map( 'nw_sanitize_int_id', $tag_ids )
				)
			)
		);

		if ( empty( $requested ) ) {
			return new WP_Error(
				'backstory_tags_required',
				'Backstory tags are required.',
				array( 'status' => 400 )
			);
		}

		$defs = nw_find_tag_defs_by_ids( $requested );

		$found = array_map(
			static function ( $row ) {
				return isset( $row['id'] ) ? (string) $row['id'] : '';
			},
			is_array( $defs ) ? $defs : array()
		);

		$requested_str = array_map( 'strval', $requested );
		$missing       = array_values( array_diff( $requested_str, $found ) );

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
if ( ! function_exists( 'nw_parse_attr_post' ) ) {
	/**
	 * Reads an attribute value from $_POST, accepting two possible key names.
	 * Returns an integer 1-5, or WP_Error on invalid input.
	 *
	 * @param string $key_primary   Primary POST key (e.g. 'attrbody').
	 * @param string $key_secondary Fallback POST key (e.g. 'attr_body').
	 * @return int|WP_Error
	 */
	function nw_parse_attr_post( string $key_primary, string $key_secondary ) {
		$raw = $_POST[ $key_primary ] ?? $_POST[ $key_secondary ] ?? null;

		if ( null === $raw || '' === (string) $raw ) {
			return new WP_Error(
				'attr_required',
				sprintf( 'Attribute "%s" is required.', $key_primary ),
				array( 'status' => 400 )
			);
		}

		$value = (int) $raw;

		if ( $value < 1 || $value > 5 ) {
			return new WP_Error(
				'attr_out_of_range',
				sprintf( 'Attribute "%s" must be between 1 and 5.', $key_primary ),
				array( 'status' => 400 )
			);
		}

		return $value;
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

    // NOTE: parent_race null from Postgres is safely coerced to '' by (string)(...??''),
    // so the empty-string check below is correct.
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

    // SCHEMA NOTE: parent_race column inconsistently stores either a race name or a race UUID
    // depending on which rows were seeded — this dual-match papers over that ambiguity.
    // The correct fix is a data migration normalising parent_race to always store a UUID FK,
    // at which point the name fallback below can be removed.
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

if ( ! function_exists( 'nw_handle_avatar_upload_strict' ) ) {
    /**
     * Handles optional avatar file upload from $_FILES['avatar'].
     * Returns the uploaded file URL (string) on success,
     * empty string if no file was uploaded,
     * or WP_Error on failure.
     *
     * On success also populates the $upload_path out-param with the server path
     * so the caller can delete the file if a later step fails (orphan prevention).
     *
     * @param string &$upload_path Filled with the server filesystem path on success.
     * @return string|WP_Error
     */
    function nw_handle_avatar_upload_strict( string &$upload_path = '' ) {
        $upload_path = '';

        if ( empty( $_FILES['avatar']['tmp_name'] ) ) {
            return '';
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file          = $_FILES['avatar'];
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $mime          = isset( $file['type'] ) ? strtolower( trim( $file['type'] ) ) : '';

        if ( ! in_array( $mime, $allowed_types, true ) ) {
            return new WP_Error(
                'avatar_invalid_type',
                'Avatar must be a JPEG, PNG, GIF, or WebP image.',
                array( 'status' => 400 )
            );
        }

        $max_size = 2 * 1024 * 1024; // 2 MB
        if ( isset( $file['size'] ) && (int) $file['size'] > $max_size ) {
            return new WP_Error(
                'avatar_too_large',
                'Avatar file must be smaller than 2 MB.',
                array( 'status' => 400 )
            );
        }

        $overrides = array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ),
        );

        $uploaded = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            return new WP_Error(
                'avatar_upload_failed',
                $uploaded['error'],
                array( 'status' => 500 )
            );
        }

        if ( ! empty( $uploaded['file'] ) ) {
            $upload_path = (string) $uploaded['file'];
        }

        return isset( $uploaded['url'] ) ? (string) $uploaded['url'] : '';
    }
}

/**
 * Deletes a character row from cyber_characters by ID.
 * Used as a rollback after a successful character insert when a subsequent
 * skills or backstory-tags insert fails.
 *
 * BUG FIX: this function was called in two rollback paths in nw_create_character_from_request()
 * but was never defined anywhere — causing a PHP fatal error that masked the original
 * skills/tags DB error with an unrelated "Call to undefined function" fatal.
 *
 * @param string $character_id UUID of the character to delete.
 * @return void  Errors are logged but not re-thrown — the caller already has an error to report.
 */
if ( ! function_exists( 'nw_delete_character_by_id' ) ) {
    function nw_delete_character_by_id( string $character_id ): void {
        $character_id = sanitize_text_field( $character_id );
        if ( '' === $character_id ) {
            error_log( 'NW: nw_delete_character_by_id called with empty ID — skipping.' );
            return;
        }

        $result = tw_supabase_request(
            'DELETE',
            'cyber_characters',
            array( 'id' => 'eq.' . $character_id ),
            null,
            false
        );

        if ( is_wp_error( $result ) ) {
            error_log( 'NW: rollback delete of character ' . $character_id . ' failed — ' . $result->get_error_message() );
        }
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

        $avatar_url_from_gallery = '';
        if ( isset( $_POST['avatarurlgallery'] ) ) {
            $avatar_url_from_gallery = nw_normalize_media_url( $_POST['avatarurlgallery'] );
        } elseif ( isset( $_POST['avatar_url'] ) ) {
            $avatar_url_from_gallery = nw_normalize_media_url( $_POST['avatar_url'] );
        }

        $stored_race_id = $race_validation['stored_race_id'];

        $payload = array(
            'wp_user_id'   => $user_id,
            'name'         => $name,
            'pronouns'     => $pronouns,
            'bio'          => $bio,
            'race_id'      => $stored_race_id,
            'class_id'     => $class_id,
            'attr_body'    => $body,
            'attr_reflex'  => $reflex,
            'attr_mind'    => $mind,
            'attr_spirit'  => $spirit,
            'avatar_url'   => $avatar_url_from_gallery,
            'package_id'   => $start_pack ?: null,
            'status'       => 'active',
        );

        // BUG FIX (orphaned upload): avatar upload moved to AFTER the character row is
        // successfully inserted. Previously, upload happened before the INSERT — if the
        // INSERT failed the uploaded file was left on disk with no character pointing to it.
        // Now: character row is created first; if that fails we return immediately with no
        // file written. Upload happens next; if it fails we roll back the character row.
        // Any subsequent failure (skills/tags) also cleans up the file via wp_delete_file().
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

        // Avatar upload — happens AFTER the character row exists so a failed upload
        // can be recovered cleanly (roll back the row; no orphan file).
        $avatar_upload_path = '';
        $avatar_upload_url  = nw_handle_avatar_upload_strict( $avatar_upload_path );
        if ( is_wp_error( $avatar_upload_url ) ) {
            nw_delete_character_by_id( $character_id );
            wp_send_json_error( array( 'message' => $avatar_upload_url->get_error_message() ), 400 );
            return;
        }

        // If an upload succeeded, update the character row with the avatar URL.
        if ( '' !== $avatar_upload_url ) {
            $patch_result = tw_supabase_request(
                'PATCH',
                'cyber_characters',
                array( 'id' => 'eq.' . $character_id ),
                array( 'avatar_url' => $avatar_upload_url ),
                false
            );
            if ( is_wp_error( $patch_result ) ) {
                // Upload is already on disk but failed to link — delete the file and the character.
                wp_delete_file( $avatar_upload_path );
                nw_delete_character_by_id( $character_id );
                wp_send_json_error( array( 'message' => 'Avatar could not be linked to the character.' ), 500 );
                return;
            }
            $character['avatar_url'] = $avatar_upload_url;
        }

        if ( ! empty( $skills ) ) {
            $skills_stored = nw_store_character_skills( $character_id, $skills );
            if ( is_wp_error( $skills_stored ) ) {
                if ( '' !== $avatar_upload_path ) {
                    wp_delete_file( $avatar_upload_path );
                }
                nw_delete_character_by_id( $character_id );
                wp_send_json_error( array( 'message' => 'Skills could not be saved: ' . $skills_stored->get_error_message() ), 500 );
                return;
            }
        }

        if ( ! empty( $resolved_backstory_tag_ids ) ) {
            $tags_stored = nw_store_character_backstory_tags( $character_id, $resolved_backstory_tag_ids );
            if ( is_wp_error( $tags_stored ) ) {
                if ( '' !== $avatar_upload_path ) {
                    wp_delete_file( $avatar_upload_path );
                }
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

        $service_key = defined( 'TW_SUPABASE_SERVICE_KEY' ) ? TW_SUPABASE_SERVICE_KEY : '';
        if ( '' === $service_key ) {
            return new WP_Error( 'config_missing', 'TW_SUPABASE_SERVICE_KEY not defined in wp-config.php.', array( 'status' => 500 ) );
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
