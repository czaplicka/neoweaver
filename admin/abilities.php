<?php
/**
 * NeoWeaver Admin — Abilities (cyber_abilities)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NW_Abilities_Admin', false ) ) {
	return;
}

class NW_Abilities_Admin extends NW_Base_Admin {

	private string $page_slug = 'nw-abilities';
	private string $page_hook = '';

	private const ABILITY_TYPES = [ 'active', 'passive', 'reaction', 'aura' ];
	private const COST_TYPES    = [ 'none', 'mana', 'stamina', 'hp', 'gold', 'action' ];
	private const TARGET_TYPES  = [ 'self', 'single', 'aoe', 'line', 'cone', 'all' ];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_abilities_get_all', [ $this, 'ajax_get_abilities' ] );
		add_action( 'wp_ajax_nw_abilities_toggle', [ $this, 'ajax_toggle_ability' ] );
		add_action( 'wp_ajax_nw_save_ability', [ $this, 'ajax_save_ability' ] );
		add_action( 'wp_ajax_nw_delete_ability', [ $this, 'ajax_delete_ability' ] );
		add_action( 'wp_ajax_nw_reorder_abilities', [ $this, 'ajax_reorder_abilities' ] );
	}

	public function register_menu(): void {
		$this->page_hook = add_submenu_page(
			'neoweaver',
			'NeoWeaver — Abilities',
			'⚡ Abilities',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'nw-admin-core',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/admin/admin-core.css' )
		);

		wp_enqueue_style(
			'nw-abilities-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/abilities.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/admin/abilities.css' )
		);

		wp_enqueue_script(
			'nw-abilities-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/abilities.js',
			[ 'jquery' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/admin/abilities.js' ),
			true
		);

		wp_localize_script(
			'nw-abilities-script',
			'NWAbilities',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_abilities' ),
			]
		);
	}

	private function normalize_tags( $raw ): array {
		$raw = wp_unslash( $raw );

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $decoded ) ) );
		}

		$parts = array_map( 'trim', explode( ',', $raw ) );
		return array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
	}

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}

	public function ajax_get_abilities(): void {
	check_ajax_referer( 'neoweaver_abilities', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
		return;
	}

	// Tymczasowy test – bez Supabase, tylko sprawdzamy, czy AJAX/JS działają.
	wp_send_json_success(
		[
			[
				'id'           => '11111111-1111-1111-1111-111111111111',
				'name'         => 'Debug Ability',
				'description'  => 'If you see this, AJAX and JS work.',
				'ability_type' => 'active',
				'cost_type'    => 'none',
				'cost_value'   => 0,
				'target_type'  => 'self',
				'range_tiles'  => 1,
				'duration_turns' => 0,
				'is_passive'   => false,
				'is_active'    => true,
				'tags'         => [ 'debug' ],
				'img_url'      => '',
				'source'       => 'debug',
				'gm_notes'     => '',
			],
		]
	);
}

	public function ajax_save_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$record_id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description    = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$ability_type   = sanitize_text_field( wp_unslash( $_POST['ability_type'] ?? 'active' ) );
		$cost_type      = sanitize_text_field( wp_unslash( $_POST['cost_type'] ?? 'none' ) );
		$cost_value     = intval( wp_unslash( $_POST['cost_value'] ?? 0 ) );
		$target_type    = sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'self' ) );
		$range_tiles    = intval( wp_unslash( $_POST['range_tiles'] ?? 1 ) );
		$duration_turns = intval( wp_unslash( $_POST['duration_turns'] ?? 0 ) );
		$is_passive     = (bool) intval( wp_unslash( $_POST['is_passive'] ?? 0 ) );
		$is_active      = (bool) intval( wp_unslash( $_POST['is_active'] ?? 1 ) );
		$tags           = $this->normalize_tags( $_POST['tags'] ?? '' );
		$img_url        = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$source         = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$gm_notes       = sanitize_textarea_field( wp_unslash( $_POST['gm_notes'] ?? '' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		if ( ! in_array( $ability_type, self::ABILITY_TYPES, true ) ) {
			$ability_type = 'active';
		}

		if ( ! in_array( $cost_type, self::COST_TYPES, true ) ) {
			$cost_type = 'none';
		}

		if ( ! in_array( $target_type, self::TARGET_TYPES, true ) ) {
			$target_type = 'self';
		}

		$payload = [
			'name'           => $name,
			'description'    => $description ?: null,
			'ability_type'   => $ability_type,
			'source'         => $source ?: null,
			'gm_notes'       => $gm_notes ?: null,
			'img_url'        => $img_url ?: null,
			'tags'           => $tags,
			'cost_type'      => $cost_type,
			'cost_value'     => max( 0, $cost_value ),
			'target_type'    => $target_type,
			'range_tiles'    => max( 0, $range_tiles ),
			'duration_turns' => max( 0, $duration_turns ),
			'is_passive'     => $is_passive,
			'is_active'      => $is_active,
		];

		if ( $record_id ) {
			if ( ! $this->is_uuid( $record_id ) ) {
				wp_send_json_error( 'Invalid UUID for ability record.' );
				return;
			}

			$res = $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . rawurlencode( $record_id ), $payload );

			if ( ! $res['ok'] ) {
				wp_send_json_error( $res['error'] ?? 'Update failed.' );
				return;
			}

			wp_send_json_success(
				[
					'action' => 'updated',
					'id'     => $record_id,
				]
			);
			return;
		}

		$res = $this->supa( 'POST', 'cyber_abilities', $payload );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Insert failed.' );
			return;
		}

		$created = $res['data'][0] ?? $res['data'] ?? [];

		wp_send_json_success(
			[
				'action' => 'created',
				'id'     => $created['id'] ?? null,
			]
		);
	}

	public function ajax_toggle_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['ability_id'] ?? '' ) );
		$is_active = (bool) intval( wp_unslash( $_POST['is_active'] ?? 0 ) );

		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa(
			'PATCH',
			'cyber_abilities?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $is_active ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success(
			[
				'id'        => $id,
				'is_active' => $is_active,
			]
		);
	}

	public function ajax_delete_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa( 'DELETE', 'cyber_abilities?id=eq.' . rawurlencode( $id ) );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( [ 'deleted' => $id ] );
	}

	public function ajax_reorder_abilities(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$order = isset( $_POST['order'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['order'] ) )
			: [];

		if ( empty( $order ) ) {
			wp_send_json_error( 'No order data.' );
			return;
		}

		$errors = [];

		foreach ( $order as $position => $id ) {
			if ( ! $id || ! $this->is_uuid( $id ) ) {
				$errors[] = $id;
				continue;
			}

			$res = $this->supa(
				'PATCH',
				'cyber_abilities?id=eq.' . rawurlencode( $id ),
				[ 'sort_order' => (int) $position ]
			);

			if ( ! $res['ok'] ) {
				$errors[] = $id;
			}
		}

		if ( $errors ) {
			wp_send_json_error( 'Reorder partially failed for IDs: ' . implode( ', ', array_filter( $errors ) ) );
			return;
		}

		wp_send_json_success( 'Reordered.' );
	}

	public function render_page(): void { ?>
		<div class="wrap nw-panel" id="nw-abilities-panel">
			<div class="nw-panel-header">
				<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Abilities</span></h1>
				<div class="nw-header-actions">
					<select id="nw-filter-type" class="nw-select-filter">
						<option value="">All types</option>
						<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="nw-filter-active" class="nw-select-filter">
						<option value="">Active &amp; Inactive</option>
						<option value="1">Active only</option>
						<option value="0">Inactive only</option>
					</select>
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search UUID, name or tag…" />
					<button id="nw-add-ability" class="button button-primary nw-btn-add">
						<i data-lucide="plus"></i> Add Ability
					</button>
				</div>
			</div>

			<div id="nw-abilities-list" class="nw-items-grid"></div>

			<div id="nw-ability-modal" class="nw-modal" style="display:none;">
				<div class="nw-modal-backdrop"></div>
				<div class="nw-modal-box">
					<div class="nw-modal-header">
						<h2 class="nw-modal-title" id="nw-modal-title">Add Ability</h2>
						<button class="nw-modal-close" id="nw-modal-close" type="button">
							<i data-lucide="x"></i>
						</button>
					</div>

					<form id="nw-ability-form" class="nw-form">
						<input type="hidden" id="ability-id" name="id" />

						<div class="nw-form-row">
							<label for="ability-name">Name *</label>
							<input type="text" id="ability-name" name="name" required />
						</div>

						<div class="nw-form-row">
							<label for="ability-description">Description</label>
							<textarea id="ability-description" name="description" rows="3"></textarea>
						</div>

						<div class="nw-form-row nw-form-row--half">
							<div>
								<label for="ability-type">Ability Type</label>
								<select id="ability-type" name="ability_type">
									<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label for="ability-target">Target Type</label>
								<select id="ability-target" name="target_type">
									<?php foreach ( self::TARGET_TYPES as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="nw-form-row nw-form-row--half">
							<div>
								<label for="ability-cost-type">Cost Type</label>
								<select id="ability-cost-type" name="cost_type">
									<?php foreach ( self::COST_TYPES as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label for="ability-cost-value">Cost Value</label>
								<input type="number" id="ability-cost-value" name="cost_value" min="0" value="0" />
							</div>
						</div>

						<div class="nw-form-row nw-form-row--half">
							<div>
								<label for="ability-range">Range (tiles)</label>
								<input type="number" id="ability-range" name="range_tiles" min="0" value="1" />
							</div>
							<div>
								<label for="ability-duration">Duration (turns)</label>
								<input type="number" id="ability-duration" name="duration_turns" min="0" value="0" />
							</div>
						</div>

						<div class="nw-form-row">
							<label for="ability-tags">Tags (comma-separated)</label>
							<input type="text" id="ability-tags" name="tags" placeholder="fire, ranged, debuff" />
						</div>

						<div class="nw-form-row">
							<label for="ability-img">Image URL</label>
							<input type="url" id="ability-img" name="img_url" />
						</div>

						<div class="nw-form-row">
							<label for="ability-source">Source</label>
							<input type="text" id="ability-source" name="source" />
						</div>

						<div class="nw-form-row">
							<label for="ability-gm-notes">GM Notes</label>
							<textarea id="ability-gm-notes" name="gm_notes" rows="2"></textarea>
						</div>

						<div class="nw-form-row nw-form-row--checkboxes">
							<label><input type="checkbox" name="is_passive" value="1" id="ability-is-passive" /> Passive</label>
							<label><input type="checkbox" name="is_active" value="1" id="ability-is-active" checked /> Active</label>
						</div>

						<div class="nw-form-actions">
							<button type="submit" class="button button-primary">Save</button>
							<button type="button" class="button nw-modal-cancel">Cancel</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php }
}
