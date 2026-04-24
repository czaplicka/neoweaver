<?php
/**
 * NeoWeaver Character Creator shortcode
 * Full version with preserved multi-step form and compatibility fixes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_register_character_creator_assets' ) ) {
	function neoweaver_register_character_creator_assets(): void {
		$plugin_url  = plugin_dir_url( __FILE__ );
		$plugin_path = plugin_dir_path( __FILE__ );
		$css_file    = $plugin_path . 'tw-character-creator-4.css';
		$js_file     = $plugin_path . 'tw-character-creator-4.js';
		$uploads     = wp_upload_dir();
		$baseurl     = trailingslashit( $uploads['baseurl'] );

		wp_register_style(
			'neoweaver-character-creator',
			$plugin_url . 'tw-character-creator-4.css',
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0'
		);

		wp_register_script(
			'neoweaver-character-creator',
			$plugin_url . 'tw-character-creator-4.js',
			array(),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);

		wp_localize_script(
			'neoweaver-character-creator',
			'twCharCreatorConfig',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'neoweaver_nonce' ),
				'site_base'      => home_url(),
				'uploads_base'   => $baseurl,
				'avatar_gallery' => array(
					array(
						'id'   => 'avatar-1',
						'name' => 'Avatar',
						'url'  => $baseurl . 'Avatar.svg',
					),
					array(
						'id'   => 'avatar-2',
						'name' => 'Avatar 2',
						'url'  => $baseurl . 'Avatar-1.svg',
					),
				),
			)
		);
	}
	add_action( 'wp_enqueue_scripts', 'neoweaver_register_character_creator_assets' );
}

if ( ! function_exists( 'neoweaver_cc_uploads_base_url' ) ) {
	function neoweaver_cc_uploads_base_url(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] );
	}
}

if ( ! function_exists( 'neoweaver_cc_normalize_media_url' ) ) {
	function neoweaver_cc_normalize_media_url( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '~^https?://~i', $value ) ) {
			return esc_url_raw( $value );
		}

		if ( 0 === strpos( $value, '/wp-content/uploads/' ) ) {
			return esc_url_raw( home_url( $value ) );
		}

		$value = ltrim( $value, '/' );
		return esc_url_raw( neoweaver_cc_uploads_base_url() . $value );
	}
}

if ( ! function_exists( 'neoweaver_cc_decode_array' ) ) {
	function neoweaver_cc_decode_array( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'strval', $value ) ) );
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}

/* ===== AJAX LOOKUPS — compatibility with current JS ===== */

if ( ! function_exists( 'neoweaver_get_races_ajax' ) ) {
	function neoweaver_get_races_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				'cyber_races',
				array(
					'select'      => 'id,name,description,tags,bonus,img_url,parent_race',
					'parent_race' => 'is.null',
					'order'       => 'name.asc',
					'limit'       => 300,
				)
			);

			$rows = is_array( $rows ) ? $rows : array();
			$data = array();

			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) ( $row['id'] ?? '' ),
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
					'bonus'       => neoweaver_cc_decode_array( $row['bonus'] ?? array() ),
				);
			}

			wp_send_json_success( $data );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_races';
		$rows  = $wpdb->get_results(
			"SELECT id, name, slug, description, image_url, img_url, tags, parent_race
			 FROM {$table}
			 WHERE parent_race IS NULL OR parent_race = ''
			 ORDER BY name ASC",
			ARRAY_A
		);

		$data = array();

		foreach ( (array) $rows as $row ) {
			$raw_img = ! empty( $row['image_url'] ) ? $row['image_url'] : ( $row['img_url'] ?? '' );

			$data[] = array(
				'id'          => (string) ( $row['id'] ?? ( $row['slug'] ?? '' ) ),
				'name'        => (string) ( $row['name'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'image_url'   => neoweaver_cc_normalize_media_url( $raw_img ),
				'img_url'     => neoweaver_cc_normalize_media_url( $raw_img ),
				'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
			);
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_get_races_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_get_races_ajax' );
}

if ( ! function_exists( 'neoweaver_get_subraces_ajax' ) ) {
	function neoweaver_get_subraces_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$parent = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				'cyber_races',
				array(
					'select'      => 'id,name,description,tags,bonus,img_url,parent_race',
					'parent_race' => 'eq.' . $parent,
					'order'       => 'name.asc',
					'limit'       => 300,
				)
			);

			$rows = is_array( $rows ) ? $rows : array();
			$data = array();

			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) ( $row['id'] ?? '' ),
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
					'bonus'       => neoweaver_cc_decode_array( $row['bonus'] ?? array() ),
				);
			}

			wp_send_json_success( $data );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_races';
		$rows  = array();

		if ( '' !== $parent ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, slug, description, image_url, img_url, tags, parent_race
					 FROM {$table}
					 WHERE parent_race = %s
					 ORDER BY name ASC",
					$parent
				),
				ARRAY_A
			);
		}

		$data = array();

		foreach ( (array) $rows as $row ) {
			$raw_img = ! empty( $row['image_url'] ) ? $row['image_url'] : ( $row['img_url'] ?? '' );

			$data[] = array(
				'id'          => (string) ( $row['id'] ?? ( $row['slug'] ?? '' ) ),
				'name'        => (string) ( $row['name'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'image_url'   => neoweaver_cc_normalize_media_url( $raw_img ),
				'img_url'     => neoweaver_cc_normalize_media_url( $raw_img ),
				'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
			);
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_get_subraces_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_get_subraces_ajax' );
}

if ( ! function_exists( 'neoweaver_get_classes_ajax' ) ) {
	function neoweaver_get_classes_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				'cyber_classes',
				array(
					'select'    => 'id,name,description,tags,img_url,icon_slug,skill_limit',
					'is_active' => 'eq.true',
					'order'     => 'name.asc',
					'limit'     => 300,
				)
			);

			$rows = is_array( $rows ) ? $rows : array();
			$data = array();

			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) ( $row['id'] ?? '' ),
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
					'skill_limit' => isset( $row['skill_limit'] ) ? (int) $row['skill_limit'] : 5,
				);
			}

			wp_send_json_success( $data );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_classes';
		$rows  = $wpdb->get_results(
			"SELECT id, name, slug, description, image_url, img_url, tags, skill_limit
			 FROM {$table}
			 ORDER BY name ASC",
			ARRAY_A
		);

		$data = array();

		foreach ( (array) $rows as $row ) {
			$raw_img = ! empty( $row['image_url'] ) ? $row['image_url'] : ( $row['img_url'] ?? '' );

			$data[] = array(
				'id'          => (string) ( $row['id'] ?? ( $row['slug'] ?? '' ) ),
				'name'        => (string) ( $row['name'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'image_url'   => neoweaver_cc_normalize_media_url( $raw_img ),
				'img_url'     => neoweaver_cc_normalize_media_url( $raw_img ),
				'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
				'skill_limit' => isset( $row['skill_limit'] ) ? (int) $row['skill_limit'] : 5,
			);
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_classes', 'neoweaver_get_classes_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_get_classes_ajax' );
}

if ( ! function_exists( 'neoweaver_get_skills_ajax' ) ) {
	function neoweaver_get_skills_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				'cyber_skills',
				array(
					'select'    => 'id,name,description,category,application,card_effect,img_url,tags,linked_attributes',
					'is_active' => 'eq.true',
					'order'     => 'category.asc,name.asc',
					'limit'     => 300,
				)
			);

			$rows = is_array( $rows ) ? $rows : array();
			$data = array();

			foreach ( $rows as $row ) {
				$data[] = array(
					'id'                => (string) ( $row['id'] ?? '' ),
					'name'              => (string) ( $row['name'] ?? '' ),
					'description'       => (string) ( $row['description'] ?? '' ),
					'category'          => (string) ( $row['category'] ?? 'Other' ),
					'application'       => (string) ( $row['application'] ?? '' ),
					'card_effect'       => (string) ( $row['card_effect'] ?? '' ),
					'image_url'         => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'           => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'              => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
					'linked_attributes' => neoweaver_cc_decode_array( $row['linked_attributes'] ?? array() ),
				);
			}

			wp_send_json_success( $data );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_skills';
		$rows  = $wpdb->get_results(
			"SELECT id, name, slug, description, category, image_url, img_url, tags
			 FROM {$table}
			 ORDER BY category ASC, name ASC",
			ARRAY_A
		);

		$data = array();

		foreach ( (array) $rows as $row ) {
			$raw_img = ! empty( $row['image_url'] ) ? $row['image_url'] : ( $row['img_url'] ?? '' );

			$data[] = array(
				'id'          => (string) ( $row['id'] ?? ( $row['slug'] ?? '' ) ),
				'name'        => (string) ( $row['name'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'category'    => (string) ( $row['category'] ?? 'Other' ),
				'image_url'   => neoweaver_cc_normalize_media_url( $raw_img ),
				'img_url'     => neoweaver_cc_normalize_media_url( $raw_img ),
				'tags'        => neoweaver_cc_decode_array( $row['tags'] ?? array() ),
			);
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_skills', 'neoweaver_get_skills_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_skills', 'neoweaver_get_skills_ajax' );
}

if ( ! function_exists( 'neoweaver_get_packages_ajax' ) ) {
	function neoweaver_get_packages_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		$class_tag = isset( $_POST['class_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['class_tag'] ) ) : '';

		if ( function_exists( 'tw_supabase_get' ) ) {
			$rows = tw_supabase_get(
				'cyber_starting_packages',
				array(
					'select'               => 'id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable',
					'is_player_selectable' => 'eq.true',
					'order'                => 'package_name.asc',
					'limit'                => 300,
				)
			);

			$rows = is_array( $rows ) ? $rows : array();
			$data = array();

			foreach ( $rows as $row ) {
				$compatibility = array_map( 'strtolower', neoweaver_cc_decode_array( $row['compatibility_tags'] ?? array() ) );

				if ( '' !== $class_tag && ! in_array( strtolower( $class_tag ), $compatibility, true ) ) {
					continue;
				}

				$data[] = array(
					'id'                 => (string) ( $row['id'] ?? '' ),
					'name'               => (string) ( $row['package_name'] ?? '' ),
					'package_name'       => (string) ( $row['package_name'] ?? '' ),
					'description'        => (string) ( $row['description'] ?? '' ),
					'items'              => neoweaver_cc_decode_array( $row['items_list'] ?? array() ),
					'items_list'         => neoweaver_cc_decode_array( $row['items_list'] ?? array() ),
					'tags'               => $compatibility,
					'compatibility_tags' => $compatibility,
					'base_armor'         => isset( $row['base_armor'] ) ? (string) $row['base_armor'] : '',
				);
			}

			wp_send_json_success( $data );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_starting_packages';
		$rows  = $wpdb->get_results(
			"SELECT id, package_name, name, slug, description, compatibility_tags, items_list, items, base_armor
			 FROM {$table}
			 ORDER BY package_name ASC, name ASC",
			ARRAY_A
		);

		$data = array();

		foreach ( (array) $rows as $row ) {
			$compatibility = array_map( 'strtolower', neoweaver_cc_decode_array( $row['compatibility_tags'] ?? array() ) );

			if ( '' !== $class_tag && ! in_array( strtolower( $class_tag ), $compatibility, true ) ) {
				continue;
			}

			$data[] = array(
				'id'                 => (string) ( $row['id'] ?? ( $row['slug'] ?? '' ) ),
				'name'               => (string) ( $row['package_name'] ?? ( $row['name'] ?? '' ) ),
				'package_name'       => (string) ( $row['package_name'] ?? ( $row['name'] ?? '' ) ),
				'description'        => (string) ( $row['description'] ?? '' ),
				'items'              => neoweaver_cc_decode_array( $row['items_list'] ?? ( $row['items'] ?? array() ) ),
				'items_list'         => neoweaver_cc_decode_array( $row['items_list'] ?? ( $row['items'] ?? array() ) ),
				'tags'               => $compatibility,
				'compatibility_tags' => $compatibility,
				'base_armor'         => isset( $row['base_armor'] ) ? (string) $row['base_armor'] : '',
			);
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_packages', 'neoweaver_get_packages_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_packages', 'neoweaver_get_packages_ajax' );
	add_action( 'wp_ajax_neoweaver_get_starting_packages', 'neoweaver_get_packages_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_starting_packages', 'neoweaver_get_packages_ajax' );
}

if ( ! function_exists( 'neoweaver_create_character_ajax' ) ) {
	function neoweaver_create_character_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => 'You must be logged in.',
				),
				403
			);
		}

		if ( function_exists( 'neoweaver_ajax_create_character' ) ) {
			neoweaver_ajax_create_character();
		}

		global $wpdb;

		$table              = $wpdb->prefix . 'cyber_characters';
		$user               = get_current_user_id();
		$character_name     = isset( $_POST['character_name'] ) ? sanitize_text_field( wp_unslash( $_POST['character_name'] ) ) : '';
		$pronouns           = isset( $_POST['pronouns'] ) ? sanitize_text_field( wp_unslash( $_POST['pronouns'] ) ) : '';
		$bio                = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';
		$race               = isset( $_POST['race'] ) ? sanitize_text_field( wp_unslash( $_POST['race'] ) ) : '';
		$subrace            = isset( $_POST['subrace'] ) ? sanitize_text_field( wp_unslash( $_POST['subrace'] ) ) : '';
		$char_class         = isset( $_POST['char_class'] ) ? sanitize_text_field( wp_unslash( $_POST['char_class'] ) ) : '';
		$starting_package   = isset( $_POST['starting_package_id'] ) ? sanitize_text_field( wp_unslash( $_POST['starting_package_id'] ) ) : '';
		$data_origin        = isset( $_POST['data_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['data_origin'] ) ) : '';
		$previous_operation = isset( $_POST['previous_operation'] ) ? sanitize_text_field( wp_unslash( $_POST['previous_operation'] ) ) : '';
		$sync_crisis        = isset( $_POST['sync_crisis'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_crisis'] ) ) : '';
		$skills             = array();
		$backstory_tags     = array();

		if ( isset( $_POST['skills'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['skills'] ), true );
			if ( is_array( $decoded ) ) {
				$skills = array_values( array_map( 'sanitize_text_field', $decoded ) );
			}
		}

		if ( isset( $_POST['backstory_tags'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['backstory_tags'] ), true );
			if ( is_array( $decoded ) ) {
				$backstory_tags = array_values( array_map( 'sanitize_text_field', $decoded ) );
			}
		}

		$attr_body   = isset( $_POST['attr_body'] ) ? (int) $_POST['attr_body'] : 1;
		$attr_reflex = isset( $_POST['attr_reflex'] ) ? (int) $_POST['attr_reflex'] : 1;
		$attr_mind   = isset( $_POST['attr_mind'] ) ? (int) $_POST['attr_mind'] : 1;
		$attr_spirit = isset( $_POST['attr_spirit'] ) ? (int) $_POST['attr_spirit'] : 1;
		$avatar_url  = isset( $_POST['avatar_url'] ) ? esc_url_raw( wp_unslash( $_POST['avatar_url'] ) ) : '';

		if ( '' === $character_name ) {
			wp_send_json_error(
				array(
					'message' => 'Character name is required.',
				),
				400
			);
		}

		if ( ! empty( $_FILES['avatar']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$uploaded = wp_handle_upload(
				$_FILES['avatar'],
				array(
					'test_form' => false,
				)
			);

			if ( empty( $uploaded['error'] ) && ! empty( $uploaded['url'] ) ) {
				$avatar_url = esc_url_raw( $uploaded['url'] );
			}
		}

		$insert = $wpdb->insert(
			$table,
			array(
				'user_id'             => $user,
				'character_name'      => $character_name,
				'pronouns'            => $pronouns,
				'bio'                 => $bio,
				'race'                => $race,
				'subrace'             => $subrace,
				'char_class'          => $char_class,
				'starting_package_id' => $starting_package,
				'skills'              => wp_json_encode( $skills ),
				'data_origin'         => $data_origin,
				'previous_operation'  => $previous_operation,
				'sync_crisis'         => $sync_crisis,
				'backstory_tags'      => wp_json_encode( $backstory_tags ),
				'attr_body'           => $attr_body,
				'attr_reflex'         => $attr_reflex,
				'attr_mind'           => $attr_mind,
				'attr_spirit'         => $attr_spirit,
				'avatar_url'          => $avatar_url,
				'created_at'          => current_time( 'mysql' ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
			)
		);

		if ( false === $insert ) {
			wp_send_json_error(
				array(
					'message' => 'Could not create character.',
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message'  => 'Character created successfully.',
				'id'       => (int) $wpdb->insert_id,
				'redirect' => '',
			)
		);
	}
	add_action( 'wp_ajax_neoweaver_create_character', 'neoweaver_create_character_ajax' );
}

if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="tw-char-login-required">You must be logged in to create a Field Agent.</div>';
		}

		wp_enqueue_style( 'neoweaver-character-creator' );
		wp_enqueue_script( 'neoweaver-character-creator' );

		ob_start();
		?>
		<div id="tw-char-creator-wrapper">
			<style>
				#tw-char-creator-wrapper .tw-attr-controls{align-items:flex-start}
				#tw-char-creator-wrapper .tw-attr-stepper,#tw-char-creator-wrapper .tw-attr-pips{justify-content:flex-start}
				#tw-char-creator-wrapper .tw-avatar-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:16px}
				#tw-char-creator-wrapper .tw-avatar-option{display:flex;flex-direction:column;gap:10px;padding:12px;border:1px solid rgba(173,255,0,.18);border-radius:16px;background:rgba(255,255,255,.03);cursor:pointer;transition:all .18s ease}
				#tw-char-creator-wrapper .tw-avatar-option:hover,#tw-char-creator-wrapper .tw-avatar-option.selected{border-color:rgba(173,255,0,.7);box-shadow:0 0 0 2px rgba(173,255,0,.14);background:rgba(173,255,0,.05)}
				#tw-char-creator-wrapper .tw-avatar-option img{width:100%;aspect-ratio:1/1;object-fit:contain;border-radius:12px;background:rgba(255,255,255,.03);padding:8px}
				#tw-char-creator-wrapper .tw-avatar-option span{color:var(--tw-text,#e8ffe1);font-size:.9rem}
			</style>

			<div class="tw-progress-bar">
				<div class="tw-progress-header">
					<div class="tw-progress-label">Character Sync <span class="tw-blink">●</span></div>
					<div class="tw-progress-counter">Step <span id="tw-char-step-current">1</span> / 11</div>
				</div>
				<div class="tw-progress-track">
					<div class="tw-progress-fill" id="tw-char-progress-fill"></div>
					<span class="tw-progress-tick active" data-tick="1"></span>
					<span class="tw-progress-tick" data-tick="2"></span>
					<span class="tw-progress-tick" data-tick="3"></span>
					<span class="tw-progress-tick" data-tick="4"></span>
					<span class="tw-progress-tick" data-tick="5"></span>
					<span class="tw-progress-tick" data-tick="6"></span>
					<span class="tw-progress-tick" data-tick="7"></span>
					<span class="tw-progress-tick" data-tick="8"></span>
					<span class="tw-progress-tick" data-tick="9"></span>
					<span class="tw-progress-tick" data-tick="10"></span>
					<span class="tw-progress-tick" data-tick="11"></span>
				</div>
				<div class="tw-progress-phase" id="tw-char-progress-phase">IDENTITY</div>
			</div>

			<div class="tw-step active" data-phase="IDENTITY">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Define the operative identity</h2>
				<p class="tw-question-text">Set the core identity for your Field Agent.</p>
				<label class="tw-field-label">
					<span>Character name <span class="tw-required">*</span></span>
					<input type="text" id="tw-char-name" placeholder="Enter agent designation">
				</label>
				<fieldset class="tw-pronoun-fieldset">
					<legend>Pronouns</legend>
					<div class="tw-pronoun-options">
						<label class="tw-pronoun-option"><input class="tw-pronoun-radio" type="radio" name="tw-pronouns" value="she/her"><span class="tw-pronoun-label">She / Her</span></label>
						<label class="tw-pronoun-option"><input class="tw-pronoun-radio" type="radio" name="tw-pronouns" value="he/him"><span class="tw-pronoun-label">He / Him</span></label>
						<label class="tw-pronoun-option"><input class="tw-pronoun-radio" type="radio" name="tw-pronouns" value="they/them"><span class="tw-pronoun-label">They / Them</span></label>
						<label class="tw-pronoun-option"><input class="tw-pronoun-radio" type="radio" name="tw-pronouns" value="xe/xem"><span class="tw-pronoun-label">Xe / Xem</span></label>
						<label class="tw-pronoun-option"><input class="tw-pronoun-radio" type="radio" name="tw-pronouns" value="custom"><span class="tw-pronoun-label">Custom</span></label>
					</div>
				</fieldset>
				<label class="tw-field-label">
					<span>Custom pronouns</span>
					<input type="text" id="tw-char-pronouns-custom" placeholder="Optional">
				</label>
				<div class="tw-nav-row"><button type="button" id="tw-char-step1-next" class="tw-btn tw-btn--primary tw-btn-next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="RACE PROTOCOL">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Select the operative's biological or synthetic origin</h2>
				<p class="tw-question-text">Choose race first, then subrace if available.</p>
				<div id="tw-race-grid" class="tw-dynamic-grid"></div>
				<section id="tw-subrace-section" class="tw-subrace-section hidden">
					<h3 class="tw-subrace-heading">Subrace variants</h3>
					<div id="tw-subrace-grid" class="tw-dynamic-grid"></div>
				</section>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="CLASS MATRIX">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Select the operative's combat and skill archetype</h2>
				<p class="tw-question-text">Choose the class matrix for your agent.</p>
				<div id="tw-class-grid" class="tw-dynamic-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="BIOMETRIC CALIBRATION">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Distribute attribute points</h2>
				<p class="tw-question-text">Each attribute starts at 1 and caps at 5. Remaining <span class="tw-attr-remaining-label"><span id="tw-attr-remaining">8</span> points</span></p>
				<div class="tw-attr-grid">
					<?php
					$attrs = array(
						'body'   => array( 'icon' => '⬢', 'label' => 'Body',   'desc' => 'Strength, endurance, resistance.' ),
						'reflex' => array( 'icon' => '⬣', 'label' => 'Reflex', 'desc' => 'Speed, agility, reaction time.' ),
						'mind'   => array( 'icon' => '◈', 'label' => 'Mind',   'desc' => 'Logic, focus, technical cognition.' ),
						'spirit' => array( 'icon' => '✦', 'label' => 'Spirit', 'desc' => 'Willpower, intuition, entropy handling.' ),
					);
					foreach ( $attrs as $key => $attr ) :
						?>
						<div class="tw-attr-row" data-attr="<?php echo esc_attr( $key ); ?>">
							<div class="tw-attr-icon"><?php echo esc_html( $attr['icon'] ); ?></div>
							<div class="tw-attr-info"><h4><?php echo esc_html( $attr['label'] ); ?> <small>1-5</small></h4><span><?php echo esc_html( $attr['desc'] ); ?></span></div>
							<div class="tw-attr-controls">
								<div class="tw-attr-stepper">
									<button type="button" class="tw-attr-btn" data-attr-action="minus" data-attr-key="<?php echo esc_attr( $key ); ?>">−</button>
									<input type="number" id="tw-attr-<?php echo esc_attr( $key ); ?>" class="tw-attr-val" value="1" min="1" max="5" readonly>
									<button type="button" class="tw-attr-btn" data-attr-action="plus" data-attr-key="<?php echo esc_attr( $key ); ?>">+</button>
								</div>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span><span class="tw-pip" data-pip="2"></span><span class="tw-pip" data-pip="3"></span><span class="tw-pip" data-pip="4"></span><span class="tw-pip" data-pip="5"></span>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="SKILL SELECTION">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Choose active skills</h2>
				<p class="tw-question-text">Select active skills unlocked for this class.</p>
				<div class="tw-skill-counter" id="tw-skill-counter">0 / 5 skills</div>
				<div id="tw-skill-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="STARTING PACKAGE">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Select the initial equipment loadout</h2>
				<p class="tw-question-text">Choose the starting package available to the selected class.</p>
				<div id="tw-package-grid" class="tw-dynamic-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="DATA ORIGIN">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Where was your consciousness first stabilized?</h2>
				<p class="tw-question-text">Pick the origin layer of your pattern.</p>
				<div id="tw-origin-grid" class="tw-dynamic-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="PREVIOUS OPERATION">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>What was your primary function before current Deployment?</h2>
				<p class="tw-question-text">Choose the previous operation profile.</p>
				<div id="tw-operation-grid" class="tw-dynamic-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="SYNCHRONIZATION CRISIS">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>How did you react to the first contact with Entropy?</h2>
				<p class="tw-question-text">Choose the crisis response pattern.</p>
				<div id="tw-crisis-grid" class="tw-dynamic-grid"></div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="VISUAL SIGNATURE">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Upload an operative portrait and add a manual bio</h2>
				<p class="tw-question-text">Both are optional.</p>
				<div class="tw-upload-box" id="tw-upload-box">
					<div class="tw-upload-preview" id="tw-avatar-preview">
						<div class="tw-upload-icon">⬡</div>
						<p>Drag &amp; drop or <button type="button" class="tw-link-btn" id="tw-avatar-trigger">browse</button></p>
						<p>JPG / PNG / WEBP / SVG, max 2 MB</p>
					</div>
					<div class="tw-avatar-selected" id="tw-avatar-selected" style="display:none">
						<img id="tw-avatar-img" src="" alt="">
						<button type="button" class="tw-avatar-clear" id="tw-avatar-clear">Remove image</button>
					</div>
					<input type="file" id="tw-char-avatar" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml" hidden>
				</div>
				<div style="margin-top:16px">
					<div class="tw-field-label">Or choose from gallery</div>
					<div id="tw-avatar-gallery" class="tw-avatar-gallery"></div>
					<label class="tw-field-label" style="margin-top:18px"><span>Bio</span><textarea id="tw-char-bio" placeholder="Who is this Field Agent?"></textarea></label>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button><button type="button" class="tw-btn-nav tw-btn-next" data-dir="next">Continue</button></div>
			</div>

			<div class="tw-step" data-phase="SYSTEM REVIEW">
				<div class="tw-step-error"><span class="tw-step-error-icon">!</span><span class="tw-step-error-msg"></span></div>
				<h2>Verify operative parameters before synchronization</h2>
				<p class="tw-question-text">Review the final profile before creating the character.</p>
				<div class="tw-summary-grid">
					<div class="tw-summary-row"><div class="tw-summary-key">Name</div><div class="tw-summary-val" id="tw-summary-character-name"></div><button type="button" class="tw-summary-edit" data-edit-step="0">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Pronouns</div><div class="tw-summary-val" id="tw-summary-pronouns"></div><button type="button" class="tw-summary-edit" data-edit-step="0">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Race</div><div class="tw-summary-val" id="tw-summary-race"></div><button type="button" class="tw-summary-edit" data-edit-step="1">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Class</div><div class="tw-summary-val" id="tw-summary-class"></div><button type="button" class="tw-summary-edit" data-edit-step="2">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Attributes</div><div class="tw-summary-val" id="tw-summary-attrs"></div><button type="button" class="tw-summary-edit" data-edit-step="3">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Skills</div><div class="tw-summary-val" id="tw-summary-skills"></div><button type="button" class="tw-summary-edit" data-edit-step="4">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Package</div><div class="tw-summary-val" id="tw-summary-package"></div><button type="button" class="tw-summary-edit" data-edit-step="5">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Origin</div><div class="tw-summary-val" id="tw-summary-origin"></div><button type="button" class="tw-summary-edit" data-edit-step="6">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Operation</div><div class="tw-summary-val" id="tw-summary-operation"></div><button type="button" class="tw-summary-edit" data-edit-step="7">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Crisis</div><div class="tw-summary-val" id="tw-summary-crisis"></div><button type="button" class="tw-summary-edit" data-edit-step="8">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Tag bundle</div><div class="tw-summary-val" id="tw-summary-tag-bundle"></div><button type="button" class="tw-summary-edit" data-edit-step="8">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Avatar</div><div class="tw-summary-val" id="tw-summary-avatar"></div><button type="button" class="tw-summary-edit" data-edit-step="9">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Bio</div><div class="tw-summary-val" id="tw-summary-bio"></div><button type="button" class="tw-summary-edit" data-edit-step="9">Edit</button></div>
				</div>
				<div class="tw-nav-row"><button type="button" class="tw-btn-nav tw-btn-review-return" data-dir="prev">Back</button><button type="button" class="tw-btn tw-btn--primary" id="tw-char-submit">Create character</button></div>
			</div>

			<div class="tw-char-status" id="tw-char-status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
	add_shortcode( 'taleweaver_character_creator', 'neoweaver_shortcode_character_creator' );
}
