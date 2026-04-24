<?php
/**
 * NeoWeaver Character Creator Shortcode
 * Path: public/shortcodes/shortcode-character-creator.php
 *
 * This version fixes:
 * - asset paths/names without the -4 suffix
 * - xe/xem pronoun option restored
 * - races/subraces loaded from cyber_races (subrace = race with parent_race)
 * - package loading aligned to cyber_starting_packages schema
 * - image filenames normalized to uploads URLs
 * - duplicate broken character insert removed; create is handled by includes/api-endpoints-character-data.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

if ( ! function_exists( 'neoweaver_cc_decode_jsonish_array' ) ) {
	function neoweaver_cc_decode_jsonish_array( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'strval', $value ), static function ( $item ) {
				return '' !== trim( $item );
			} ) );
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ), static function ( $item ) {
				return '' !== trim( $item );
			} ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}

if ( ! function_exists( 'neoweaver_cc_get_results' ) ) {
	function neoweaver_cc_get_results( string $sql ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'neoweaver_register_character_creator_assets' ) ) {
	function neoweaver_register_character_creator_assets(): void {
		$base_url = plugin_dir_url( __FILE__ );

		wp_register_style(
			'tw-character-creator',
			$base_url . '../../assets/css/tw-character-creator.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . '../../assets/css/tw-character-creator.css' ) ?: null
		);

		wp_register_script(
			'tw-character-creator',
			$base_url . '../../assets/js/tw-character-creator.js',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . '../../assets/js/tw-character-creator.js' ) ?: null,
			true
		);

		wp_localize_script(
			'tw-character-creator',
			'twCharCreatorConfig',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'neoweaver_nonce' ),
				'site_base'      => home_url(),
				'uploads_base'   => trailingslashit( wp_upload_dir()['baseurl'] ),
				'avatar_gallery' => array(
					array(
						'id'   => 'avatar-1',
						'name' => 'Avatar',
						'url'  => trailingslashit( wp_upload_dir()['baseurl'] ) . 'Avatar.svg',
					),
					array(
						'id'   => 'avatar-2',
						'name' => 'Avatar 2',
						'url'  => trailingslashit( wp_upload_dir()['baseurl'] ) . 'Avatar-1.svg',
					),
				),
			)
		);
	}
	add_action( 'wp_enqueue_scripts', 'neoweaver_register_character_creator_assets' );
}

if ( ! function_exists( 'neoweaver_get_races_ajax' ) ) {
	function neoweaver_get_races_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_races';

		$rows = $wpdb->get_results(
			"SELECT id, name, parent_race, description, img_url, tags
			 FROM {$table}
			 WHERE parent_race IS NULL OR parent_race = ''
			 ORDER BY name ASC",
			ARRAY_A
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) $row['id'],
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_jsonish_array( $row['tags'] ?? '[]' ),
				);
			}
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_races', 'neoweaver_get_races_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_get_races_ajax' );
}

if ( ! function_exists( 'neoweaver_get_subraces_ajax' ) ) {
	function neoweaver_get_subraces_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		global $wpdb;
		$parent = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';
		$table  = $wpdb->prefix . 'cyber_races';

		if ( '' === $parent ) {
			wp_send_json_success( array() );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, parent_race, description, img_url, tags
				 FROM {$table}
				 WHERE parent_race = %s
				 ORDER BY name ASC",
				$parent
			),
			ARRAY_A
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) $row['id'],
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_jsonish_array( $row['tags'] ?? '[]' ),
				);
			}
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_subraces', 'neoweaver_get_subraces_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_get_subraces_ajax' );
}

if ( ! function_exists( 'neoweaver_get_classes_ajax' ) ) {
	function neoweaver_get_classes_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_classes';

		$rows = $wpdb->get_results(
			"SELECT id, name, description, img_url, tags, skill_limit
			 FROM {$table}
			 WHERE is_active = 1
			 ORDER BY name ASC",
			ARRAY_A
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) $row['id'],
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_jsonish_array( $row['tags'] ?? '[]' ),
					'skill_limit' => isset( $row['skill_limit'] ) ? (int) $row['skill_limit'] : 5,
				);
			}
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_classes', 'neoweaver_get_classes_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_get_classes_ajax' );
}

if ( ! function_exists( 'neoweaver_get_skills_ajax' ) ) {
	function neoweaver_get_skills_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_skills';

		$rows = $wpdb->get_results(
			"SELECT id, name, description, category, img_url, tags
			 FROM {$table}
			 WHERE is_active = 1
			 ORDER BY category ASC, name ASC",
			ARRAY_A
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data[] = array(
					'id'          => (string) $row['id'],
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'category'    => (string) ( $row['category'] ?? 'Other' ),
					'image_url'   => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'img_url'     => neoweaver_cc_normalize_media_url( $row['img_url'] ?? '' ),
					'tags'        => neoweaver_cc_decode_jsonish_array( $row['tags'] ?? '[]' ),
				);
			}
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_skills', 'neoweaver_get_skills_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_skills', 'neoweaver_get_skills_ajax' );
}

if ( ! function_exists( 'neoweaver_get_packages_ajax' ) ) {
	function neoweaver_get_packages_ajax(): void {
		check_ajax_referer( 'neoweaver_nonce', 'nonce' );

		global $wpdb;
		$class_tag = isset( $_POST['class_tag'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['class_tag'] ) ) ) : '';
		$table     = $wpdb->prefix . 'cyber_starting_packages';

		$rows = $wpdb->get_results(
			"SELECT id, package_name, description, items_list, compatibility_tags, base_armor
			 FROM {$table}
			 WHERE is_player_selectable = 1
			 ORDER BY package_name ASC",
			ARRAY_A
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$compatibility_tags = array_map( 'strtolower', neoweaver_cc_decode_jsonish_array( $row['compatibility_tags'] ?? '[]' ) );

				if ( '' !== $class_tag && ! in_array( $class_tag, $compatibility_tags, true ) ) {
					continue;
				}

				$data[] = array(
					'id'         => (string) $row['id'],
					'name'       => (string) ( $row['package_name'] ?? '' ),
					'description'=> (string) ( $row['description'] ?? '' ),
					'image_url'  => '',
					'img_url'    => '',
					'tags'       => $compatibility_tags,
					'items'      => neoweaver_cc_decode_jsonish_array( $row['items_list'] ?? '[]' ),
					'base_armor' => isset( $row['base_armor'] ) ? (string) $row['base_armor'] : '0',
				);
			}
		}

		wp_send_json_success( $data );
	}
	add_action( 'wp_ajax_neoweaver_get_packages', 'neoweaver_get_packages_ajax' );
	add_action( 'wp_ajax_nopriv_neoweaver_get_packages', 'neoweaver_get_packages_ajax' );
}

if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {
	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="tw-cc-login-required"><p>You must be logged in to create a Field Agent.</p></div>';
		}

		wp_enqueue_style( 'tw-character-creator' );
		wp_enqueue_script( 'tw-character-creator' );

		ob_start();
		?>
		<div id="tw-char-creator-wrapper" class="tw-char-creator" data-module="neo-character-creator">
			<div class="tw-progress-bar" aria-label="Character creation progress">
				<div class="tw-progress-header">
					<div class="tw-progress-label">Field Agent Initialization <span class="tw-blink" aria-hidden="true"></span></div>
					<div class="tw-progress-counter">Step <span id="tw-char-step-current">1</span> / 11</div>
				</div>
				<div class="tw-progress-track">
					<div id="tw-char-progress-fill" class="tw-progress-fill"></div>
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
				<div id="tw-char-progress-phase" class="tw-progress-phase">IDENTITY MATRIX</div>
			</div>

			<div id="tw-char-status" class="tw-char-status" aria-live="polite"></div>

			<section class="tw-step active" data-phase="IDENTITY MATRIX">
				<h2>Identity Matrix</h2>
				<p class="tw-question-text">Set the core identity for your Field Agent.</p>

				<label class="tw-field-label" for="tw-char-name">
					<span>Agent Name <span class="tw-required">*</span></span>
					<input type="text" id="tw-char-name" name="character_name" maxlength="80" placeholder="Enter your agent designation" autocomplete="off">
				</label>

				<fieldset class="tw-pronoun-fieldset">
					<legend>Pronouns / identity mapping <span class="tw-required">*</span></legend>
					<div class="tw-pronoun-options">
						<label class="tw-pronoun-option">
							<input type="radio" class="tw-pronoun-radio" name="tw_pronouns" value="she">
							<span class="tw-pronoun-label">she/her</span>
						</label>
						<label class="tw-pronoun-option">
							<input type="radio" class="tw-pronoun-radio" name="tw_pronouns" value="he">
							<span class="tw-pronoun-label">he/him</span>
						</label>
						<label class="tw-pronoun-option">
							<input type="radio" class="tw-pronoun-radio" name="tw_pronouns" value="they">
							<span class="tw-pronoun-label">they/them</span>
						</label>
						<label class="tw-pronoun-option">
							<input type="radio" class="tw-pronoun-radio" name="tw_pronouns" value="xe">
							<span class="tw-pronoun-label">xe/xem</span>
						</label>
						<label class="tw-pronoun-option">
							<input type="radio" class="tw-pronoun-radio" name="tw_pronouns" value="custom">
							<span class="tw-pronoun-label">custom</span>
						</label>
					</div>
					<label class="tw-field-label" for="tw-char-pronouns-custom">
						<span>Custom pronouns</span>
						<input type="text" id="tw-char-pronouns-custom" name="custom_pronouns" maxlength="50" placeholder="Optional when custom is selected" autocomplete="off">
					</label>
				</fieldset>

				<div class="tw-step-actions">
					<button type="button" id="tw-char-step1-next" class="tw-btn tw-btn--primary tw-btn-next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="RACE PROTOCOL">
				<h2>Race Protocol</h2>
				<p class="tw-question-text">Choose race first, then subrace if available. Only the subrace is stored in the character record when selected.</p>
				<div id="tw-race-grid" class="tw-dynamic-grid" aria-live="polite"></div>
				<div id="tw-subrace-section" class="tw-subrace-section" hidden>
					<h3 class="tw-subrace-heading">Available Subraces</h3>
					<div id="tw-subrace-grid" class="tw-dynamic-grid" aria-live="polite"></div>
				</div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="CLASS MATRIX">
				<h2>Class Matrix</h2>
				<p class="tw-question-text">Choose the class matrix for your agent.</p>
				<div id="tw-class-grid" class="tw-dynamic-grid" aria-live="polite"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="BIOMETRIC CALIBRATION">
				<h2>Biometric Calibration</h2>
				<p class="tw-question-text">Each attribute starts at 1 and caps at 5. Distribute the remaining 8 points for a total of 12.</p>
				<div class="tw-attr-remaining-label">Remaining points: <strong id="tw-attr-remaining">8</strong></div>
				<div class="tw-attr-grid">
					<?php
					$attrs = array(
						'body'   => array( 'BODY', 'Physical durability, resilience, force.' ),
						'reflex' => array( 'REFLEX', 'Reaction speed, movement, evasiveness.' ),
						'mind'   => array( 'MIND', 'Analysis, focus, logic, memory.' ),
						'spirit' => array( 'SPIRIT', 'Willpower, magic, intuition, inner stability.' ),
					);
					foreach ( $attrs as $key => $attr ) :
						?>
						<div class="tw-attr-row" data-attr="<?php echo esc_attr( $key ); ?>">
							<div class="tw-attr-icon" aria-hidden="true">+</div>
							<div class="tw-attr-info">
								<h4><?php echo esc_html( $attr[0] ); ?> <small>1-5</small></h4>
								<span><?php echo esc_html( $attr[1] ); ?></span>
							</div>
							<div class="tw-attr-controls">
								<div class="tw-attr-stepper">
									<button type="button" class="tw-attr-btn" data-action="minus" data-attr-key="<?php echo esc_attr( $key ); ?>">−</button>
									<input type="number" class="tw-attr-val" id="tw-attr-<?php echo esc_attr( $key ); ?>" min="1" max="5" value="1" readonly>
									<button type="button" class="tw-attr-btn" data-action="plus" data-attr-key="<?php echo esc_attr( $key ); ?>">+</button>
								</div>
								<div class="tw-attr-pips">
									<span class="tw-pip active" data-pip="1"></span>
									<span class="tw-pip" data-pip="2"></span>
									<span class="tw-pip" data-pip="3"></span>
									<span class="tw-pip" data-pip="4"></span>
									<span class="tw-pip" data-pip="5"></span>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="SKILL SELECTION">
				<h2>Skill Selection</h2>
				<p class="tw-question-text">Select active skills unlocked for this class.</p>
				<div id="tw-skill-counter" class="tw-skill-counter">0 / 5 skills</div>
				<div id="tw-skill-grid" class="tw-skill-grid" aria-live="polite"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="STARTING PACKAGE">
				<h2>Starting Package</h2>
				<p class="tw-question-text">Choose the starting package available to the selected class. Packages are filtered by class tag.</p>
				<div id="tw-package-grid" class="tw-dynamic-grid" aria-live="polite"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="DATA ORIGIN">
				<h2>Data Origin</h2>
				<p class="tw-question-text">Pick the origin layer of your pattern.</p>
				<div id="tw-origin-grid" class="tw-dynamic-grid"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="PREVIOUS OPERATION">
				<h2>Previous Operation</h2>
				<p class="tw-question-text">Choose the previous operation profile.</p>
				<div id="tw-operation-grid" class="tw-dynamic-grid"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="SYNCHRONIZATION CRISIS">
				<h2>Synchronization Crisis</h2>
				<p class="tw-question-text">Choose the crisis response pattern. Each backstory answer contributes tags stored separately for the character.</p>
				<div id="tw-crisis-grid" class="tw-dynamic-grid"></div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="VISUAL SIGNATURE">
				<h2>Visual Signature</h2>
				<p class="tw-question-text">Upload an avatar or pick one from the gallery, then add a short bio.</p>
				<div class="tw-upload-box" id="tw-upload-box">
					<input type="file" id="tw-char-avatar" name="avatar" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml">
					<div id="tw-avatar-preview" class="tw-upload-preview">
						<div class="tw-upload-icon" aria-hidden="true">⬆</div>
						<p>Drag &amp; drop or choose a file.</p>
						<p>JPG / PNG / WEBP / SVG, max 2 MB</p>
					</div>
					<div id="tw-avatar-selected" class="tw-avatar-selected" style="display:none;">
						<img id="tw-avatar-img" src="" alt="Selected avatar">
						<button type="button" class="tw-avatar-clear">Clear avatar</button>
					</div>
				</div>
				<div id="tw-avatar-gallery" class="tw-dynamic-grid tw-avatar-gallery"></div>
				<label class="tw-field-label" for="tw-char-bio">
					<span>Bio</span>
					<textarea id="tw-char-bio" name="bio" maxlength="1000" placeholder="Describe your Field Agent."></textarea>
				</label>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" class="tw-btn tw-btn--primary tw-btn-next" data-dir="next">Continue</button>
				</div>
			</section>

			<section class="tw-step" data-phase="SYSTEM REVIEW">
				<h2>System Review</h2>
				<p class="tw-question-text">Review the final profile before creating the character.</p>
				<div class="tw-summary-grid">
					<div class="tw-summary-row"><div class="tw-summary-key">Name</div><div id="tw-summary-character-name" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="0">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Pronouns</div><div id="tw-summary-pronouns" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="0">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Race</div><div id="tw-summary-race" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="1">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Class</div><div id="tw-summary-class" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="2">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Attributes</div><div id="tw-summary-attrs" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="3">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Skills</div><div id="tw-summary-skills" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="4">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Package</div><div id="tw-summary-package" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="5">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Origin</div><div id="tw-summary-origin" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="6">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Operation</div><div id="tw-summary-operation" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="7">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Crisis</div><div id="tw-summary-crisis" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="8">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Backstory tags</div><div id="tw-summary-tag-bundle" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="8">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Bio</div><div id="tw-summary-bio" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="9">Edit</button></div>
					<div class="tw-summary-row"><div class="tw-summary-key">Avatar</div><div id="tw-summary-avatar" class="tw-summary-val">—</div><button type="button" class="tw-summary-edit tw-btn-review-return" data-target-step="9">Edit</button></div>
				</div>
				<div class="tw-step-actions">
					<button type="button" class="tw-btn-nav tw-btn-prev" data-dir="prev">Back</button>
					<button type="button" id="tw-char-submit" class="tw-btn tw-btn--primary">Create Character</button>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}
	add_shortcode( 'neoweaver_character_creator', 'neoweaver_shortcode_character_creator' );
}
