<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Abilities_Admin {

	private string $page_slug = 'nw-abilities';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_get_abilities',    [ $this, 'ajax_get_abilities' ] );
		add_action( 'wp_ajax_nw_save_ability',     [ $this, 'ajax_save_ability' ] );
		add_action( 'wp_ajax_nw_delete_ability',   [ $this, 'ajax_delete_ability' ] );
		add_action( 'wp_ajax_nw_reorder_abilities',[ $this, 'ajax_reorder_abilities' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'NeoWeaver — Abilities',
			'⚡ Abilities',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-admin-core',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/nw-admin-core.css',
			[ 'chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-abilities-style',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/abilities-admin.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_script(
			'nw-abilities-script',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/abilities-admin.js',
			[ 'jquery', 'lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script( 'nw-abilities-script', 'NWAbilities', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweaver_abilities' ),
		] );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	public function ajax_get_abilities(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_abilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );
		wp_send_json_success( $rows );
	}

	public function ajax_save_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_abilities';

		$id          = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$type        = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'active' ) );
		$cost        = intval( $_POST['cost'] ?? 0 );
		$tags        = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
		$effect_json = wp_unslash( $_POST['effect_json'] ?? '{}' );
		$active      = intval( $_POST['active'] ?? 1 );

		if ( empty( $name ) ) {
			wp_send_json_error( 'Name is required.' );
		}

		// validate effect_json
		json_decode( $effect_json );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$effect_json = '{}';
		}

		$data = [
			'name'        => $name,
			'description' => $description,
			'type'        => $type,
			'cost'        => $cost,
			'tags'        => $tags,
			'effect_json' => $effect_json,
			'active'      => $active,
		];

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, [ 'id' => $id ] );
			wp_send_json_success( [ 'action' => 'updated', 'id' => $id ] );
		} else {
			$data['sort_order'] = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			wp_send_json_success( [ 'action' => 'created', 'id' => $wpdb->insert_id ] );
		}
	}

	public function ajax_delete_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		$id = intval( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( 'Invalid ID.' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'cyber_abilities', [ 'id' => $id ] );
		wp_send_json_success( [ 'deleted' => $id ] );
	}

	public function ajax_reorder_abilities(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );

		$order = isset( $_POST['order'] ) ? array_map( 'intval', (array) $_POST['order'] ) : [];
		if ( empty( $order ) ) {
			wp_send_json_error( 'No order data.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cyber_abilities';

		foreach ( $order as $position => $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, [ 'sort_order' => $position ], [ 'id' => $id ] );
		}

		wp_send_json_success( 'Reordered.' );
	}

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

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
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search id or title&hellip;">
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Ability</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total: <strong id="nw-total">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">&mdash;</strong></span>
				<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
					<span class="nw-stat-pill"><?php echo esc_html( ucfirst( $t ) ); ?>: <strong id="nw-count-<?php echo esc_attr( $t ); ?>">&mdash;</strong></span>
				<?php endforeach; ?>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead><tr>
						<th>ID / Title</th>
						<th>Type</th>
						<th>Cost</th>
						<th>Target</th>
						<th>Range</th>
						<th>Duration</th>
						<th>Passive</th>
						<th>Active</th>
						<th>Actions</th>
					</tr></thead>
					<tbody id="nw-abilities-tbody">
						<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading&hellip;</td></tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Ability</h2>
						<button class="nw-modal-close" id="nw-modal-close">&#x2715;</button>
					</div>
					<div class="nw-modal-body">
						<form id="nw-ability-form">
							<input type="hidden" id="nw-field-original_id" name="original_id">

							<div class="nw-section-label">Identity</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>ID (slug) <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-id" name="id" required placeholder="e.g. fireball">
								</div>
								<div class="nw-field">
									<label>Title <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-title" name="title" required placeholder="e.g. Fireball">
								</div>
								<div class="nw-field nw-field-full">
									<label>Description</label>
									<textarea id="nw-field-description" name="description" rows="3" placeholder="Ability description&hellip;"></textarea>
								</div>
								<div class="nw-field nw-field-full">
									<label>Tags <span class="nw-hint">(comma-separated slugs)</span></label>
									<input type="text" id="nw-field-tags" name="tags" placeholder="e.g. fire,aoe,damage">
								</div>
							</div>

							<div class="nw-section-label">Mechanics</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>Ability Type <span class="nw-req">*</span></label>
									<select id="nw-field-ability_type" name="ability_type" class="nw-select">
										<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
											<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Cost Type</label>
									<select id="nw-field-cost_type" name="cost_type" class="nw-select">
										<?php foreach ( self::COST_TYPES as $c ) : ?>
											<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Cost Value</label>
									<input type="number" id="nw-field-cost_value" name="cost_value" min="0" value="0">
								</div>
								<div class="nw-field">
									<label>Target Type</label>
									<select id="nw-field-target_type" name="target_type" class="nw-select">
										<?php foreach ( self::TARGET_TYPES as $tt ) : ?>
											<option value="<?php echo esc_attr( $tt ); ?>"><?php echo esc_html( ucfirst( $tt ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Range (tiles)</label>
									<input type="number" id="nw-field-range_tiles" name="range_tiles" min="0" value="1">
								</div>
								<div class="nw-field">
									<label>Duration (turns)</label>
									<input type="number" id="nw-field-duration_turns" name="duration_turns" min="0" value="0">
								</div>
							</div>

							<div class="nw-section-label">Status</div>
							<div class="nw-form-grid">
								<div class="nw-field nw-field-toggles">
									<div class="nw-toggle-row">
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_passive" name="is_passive">
												<span class="nw-toggle-slider nw-toggle-orange"></span>
											</span>
											<span>Passive ability</span>
										</label>
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_active" name="is_active" checked>
												<span class="nw-toggle-slider"></span>
											</span>
											<span>Active (available in game)</span>
										</label>
									</div>
								</div>
							</div>
						</form>
					</div>
					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Ability</span></button>
					</div>
				</div>
			</div>
		</div>
	<?php }
}

add_action(
	'plugins_loaded',
	static function () {
		new NeoWeaver_Abilities_Admin();
	},
	20
);
