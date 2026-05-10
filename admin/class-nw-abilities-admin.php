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

	// ── Render ────────────────────────────────────────────────────────────

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
			<div class="nw-admin-header">
				<h1 class="nw-admin-heading">⚡ Abilities</h1>
				<div class="nw-admin-actions">
					<button id="nw-add-btn" class="nw-btn nw-btn-primary">+ Add Ability</button>
					<button id="nw-refresh-btn" class="nw-btn nw-btn-secondary">↺ Refresh</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-filter-bar">
				<select id="nw-filter-type" class="nw-select">
					<option value="">All Types</option>
					<option value="active">Active</option>
					<option value="passive">Passive</option>
					<option value="reaction">Reaction</option>
				</select>
				<select id="nw-filter-active" class="nw-select">
					<option value="">All Status</option>
					<option value="1">Active</option>
					<option value="0">Inactive</option>
				</select>
				<input type="text" id="nw-search" class="nw-input" placeholder="Search abilities…" />
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table" id="nw-abilities-table">
					<thead>
						<tr>
							<th class="nw-col-sort">⇅</th>
							<th>Name</th>
							<th>Type</th>
							<th>Cost</th>
							<th>Tags</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="nw-abilities-tbody">
						<tr><td colspan="7" class="nw-loading">Loading…</td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Modal -->
		<div id="nw-modal" class="nw-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
			<div class="nw-modal-backdrop"></div>
			<div class="nw-modal-box">
				<div class="nw-modal-header">
					<h2 id="nw-modal-title" class="nw-modal-heading">Ability</h2>
					<button class="nw-modal-close" aria-label="Close">&times;</button>
				</div>
				<form id="nw-ability-form" class="nw-form">
					<input type="hidden" name="id" id="nw-field-id" value="0" />
					<div class="nw-form-row">
						<label for="nw-field-name" class="nw-label">Name *</label>
						<input type="text" id="nw-field-name" name="name" class="nw-input" required />
					</div>
					<div class="nw-form-row">
						<label for="nw-field-description" class="nw-label">Description</label>
						<textarea id="nw-field-description" name="description" class="nw-textarea" rows="3"></textarea>
					</div>
					<div class="nw-form-row nw-form-row--half">
						<div>
							<label for="nw-field-type" class="nw-label">Type</label>
							<select id="nw-field-type" name="type" class="nw-select">
								<option value="active">Active</option>
								<option value="passive">Passive</option>
								<option value="reaction">Reaction</option>
							</select>
						</div>
						<div>
							<label for="nw-field-cost" class="nw-label">Cost</label>
							<input type="number" id="nw-field-cost" name="cost" class="nw-input" value="0" min="0" />
						</div>
					</div>
					<div class="nw-form-row">
						<label for="nw-field-tags" class="nw-label">Tags (comma-separated)</label>
						<input type="text" id="nw-field-tags" name="tags" class="nw-input" />
					</div>
					<div class="nw-form-row">
						<label for="nw-field-effect-json" class="nw-label">Effect JSON</label>
						<textarea id="nw-field-effect-json" name="effect_json" class="nw-textarea nw-textarea--mono" rows="4">{}</textarea>
					</div>
					<div class="nw-form-row nw-form-row--toggle">
						<label class="nw-label">Active</label>
						<label class="nw-toggle">
							<input type="checkbox" id="nw-field-active" name="active" value="1" checked />
							<span class="nw-toggle-slider"></span>
						</label>
					</div>
					<div class="nw-form-actions">
						<button type="submit" class="nw-btn nw-btn-primary">Save</button>
						<button type="button" class="nw-modal-close nw-btn nw-btn-ghost">Cancel</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
