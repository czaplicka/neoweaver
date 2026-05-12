<?php
/**
 * NeoWeaver Admin Panel — Starting Packages (cyber_starting_packages)
 *
 * Columns: id, package_name, description, items_list, compatibility_tags,
 *          attack_cards_pool, defense_cards_pool, base_armor,
 *          is_player_selectable, head_item_id, torso_item_id,
 *          hand_r_item_id, hand_l_item_id, belt_item_id,
 *          compatible_class_ids, created_at
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NeoWeaver_Starting_Packages_Admin' ) ) {

	class NeoWeaver_Starting_Packages_Admin {

		private string $supabase_url;
		private string $supabase_key;
		private string $page_slug   = 'neoweaver-starting-packages';
		private string $parent_slug = 'neoweaver';
		private string $nonce_action = 'neoweaver_sp';

		public function __construct() {
			$this->supabase_url = rtrim( tw_supabase_url(), '/' );
			$this->supabase_key = tw_supabase_anon_key();

			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			add_action( 'wp_ajax_nw_sp_get_all', [ $this, 'ajax_get_all' ] );
			add_action( 'wp_ajax_nw_sp_get_items', [ $this, 'ajax_get_items' ] );
			add_action( 'wp_ajax_nw_sp_save', [ $this, 'ajax_save' ] );
			add_action( 'wp_ajax_nw_sp_toggle', [ $this, 'ajax_toggle' ] );
			add_action( 'wp_ajax_nw_sp_delete', [ $this, 'ajax_delete' ] );
		}

		public function register_menu(): void {
			add_submenu_page(
				$this->parent_slug,
				'NeoWeaver — Starting Packages',
				'🎯 Starting Packages',
				'manage_options',
				$this->page_slug,
				[ $this, 'render_page' ]
			);
		}

		public function enqueue_assets( string $hook ): void {
	if ( ! str_contains( $hook, $this->page_slug ) ) {
		return;
	}

	$ver = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0';
	$base = trailingslashit( NEOWEAVER_PLUGIN_URL );

	wp_enqueue_style(
		'nw-font-chakra-petch',
		'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'nw-admin-core',
		$base . 'assets/css/admin/admin-core.css',
		[ 'nw-font-chakra-petch' ],
		$ver
	);

	wp_enqueue_style(
		'nw-starting-packages-style',
		$base . 'assets/css/admin/starting-packages.css',
		[ 'nw-font-chakra-petch', 'nw-admin-core' ],
		$ver
	);

	wp_enqueue_script(
		'nw-starting-packages-script',
		$base . 'assets/js/admin/starting-packages.js',
		[ 'jquery', 'nw-lucide' ],
		$ver,
		true
	);

	wp_localize_script(
		'nw-starting-packages-script',
		'NW_SP',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( $this->nonce_action ),
		]
	);
}
		public function render_page(): void { ?>
			<div class="wrap nw-panel" id="nw-sp-panel">
				<div class="nw-panel-header">
					<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Starting Packages</span></h1>
					<div class="nw-header-actions">
						<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
						<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Package</button>
					</div>
				</div>

				<div id="nw-notice" class="nw-notice" style="display:none;"></div>

				<div class="nw-stats-bar">
					<span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
					<span class="nw-stat-pill nw-pill-active">Player Selectable: <strong id="nw-selectable">—</strong></span>
					<span class="nw-stat-pill nw-pill-inactive">Hidden: <strong id="nw-hidden">—</strong></span>
				</div>

				<div class="nw-table-wrap">
					<table class="nw-table">
						<thead>
							<tr>
								<th>Package Name</th>
								<th>Base Armor</th>
								<th>Slots</th>
								<th>Compat. Tags</th>
								<th>Class IDs</th>
								<th>Player Selectable</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody id="nw-sp-tbody">
							<tr class="nw-loading-row">
								<td colspan="7"><div class="nw-spinner"></div> Loading packages…</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
					<div class="nw-modal">
						<div class="nw-modal-header">
							<h2 id="nw-modal-title">Edit Package</h2>
							<button class="nw-modal-close" id="nw-modal-close" type="button">✕</button>
						</div>

						<div class="nw-modal-body">
							<form id="nw-sp-form">
								<input type="hidden" id="nw-field-id" name="id">

								<div class="nw-section-label">Identity</div>
								<div class="nw-form-grid">
									<div class="nw-field nw-field-full">
										<label for="nw-field-package_name">Package Name <span class="nw-req">*</span></label>
										<input type="text" id="nw-field-package_name" name="package_name" required placeholder="e.g. Street Runner Starter">
									</div>

									<div class="nw-field nw-field-full">
										<label for="nw-field-description">Description</label>
										<textarea id="nw-field-description" name="description" rows="3" placeholder="Brief description of this starting package…"></textarea>
									</div>
								</div>

								<div class="nw-section-label">Equipment Slots <span class="nw-hint">(pick from cyber_items)</span></div>
								<div class="nw-form-grid">
									<div class="nw-field"><label for="nw-field-head_item_id">Head Slot</label><select id="nw-field-head_item_id" name="head_item_id" class="nw-select nw-item-select"><option value="">— none —</option></select></div>
									<div class="nw-field"><label for="nw-field-torso_item_id">Torso Slot</label><select id="nw-field-torso_item_id" name="torso_item_id" class="nw-select nw-item-select"><option value="">— none —</option></select></div>
									<div class="nw-field"><label for="nw-field-hand_r_item_id">Right Hand Slot</label><select id="nw-field-hand_r_item_id" name="hand_r_item_id" class="nw-select nw-item-select"><option value="">— none —</option></select></div>
									<div class="nw-field"><label for="nw-field-hand_l_item_id">Left Hand Slot</label><select id="nw-field-hand_l_item_id" name="hand_l_item_id" class="nw-select nw-item-select"><option value="">— none —</option></select></div>
									<div class="nw-field"><label for="nw-field-belt_item_id">Belt Slot</label><select id="nw-field-belt_item_id" name="belt_item_id" class="nw-select nw-item-select"><option value="">— none —</option></select></div>
									<div class="nw-field"><label for="nw-field-base_armor">Base Armor <span class="nw-hint">(≥ 0)</span></label><input type="number" id="nw-field-base_armor" name="base_armor" min="0" value="0"></div>
								</div>

								<div class="nw-section-label">Card Pools &amp; Lists <span class="nw-hint">(comma-separated → JSON array)</span></div>
								<div class="nw-form-grid">
									<div class="nw-field nw-field-full"><label for="nw-field-items_list">Items List</label><input type="text" id="nw-field-items_list" name="items_list" placeholder="item-uuid-1, item-uuid-2"></div>
									<div class="nw-field nw-field-full"><label for="nw-field-attack_cards_pool">Attack Cards Pool</label><input type="text" id="nw-field-attack_cards_pool" name="attack_cards_pool" placeholder="card-id-1, card-id-2"></div>
									<div class="nw-field nw-field-full"><label for="nw-field-defense_cards_pool">Defense Cards Pool</label><input type="text" id="nw-field-defense_cards_pool" name="defense_cards_pool" placeholder="card-id-1, card-id-2"></div>
								</div>

								<div class="nw-section-label">Compatibility <span class="nw-hint">(comma-separated → JSON array)</span></div>
								<div class="nw-form-grid">
									<div class="nw-field nw-field-full"><label for="nw-field-compatibility_tags">Compatibility Tags</label><input type="text" id="nw-field-compatibility_tags" name="compatibility_tags" placeholder="e.g. melee, urban, stealth"></div>
									<div class="nw-field nw-field-full"><label for="nw-field-compatible_class_ids">Compatible Class IDs</label><input type="text" id="nw-field-compatible_class_ids" name="compatible_class_ids" placeholder="class-uuid-1, class-uuid-2"></div>
								</div>

								<div class="nw-section-label">Visibility</div>
								<div class="nw-form-grid">
									<div class="nw-field nw-field-center">
										<label for="nw-field-is_player_selectable">Player Selectable (visible on character creation)</label>
										<label class="nw-toggle">
											<input type="checkbox" id="nw-field-is_player_selectable" name="is_player_selectable">
											<span class="nw-toggle-slider"></span>
										</label>
									</div>
								</div>
							</form>
						</div>

						<div class="nw-modal-footer">
							<button class="nw-btn nw-btn-danger" id="nw-delete-btn" type="button" style="display:none;margin-right:auto;">🗑 Delete</button>
							<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn" type="button">Cancel</button>
							<button class="nw-btn nw-btn-primary" id="nw-save-btn" type="button"><span id="nw-save-label">Save Package</span></button>
						</div>
					</div>
				</div>

				<input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( $this->nonce_action ) ); ?>">
			</div>
		<?php }

		private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
			$headers = array_merge(
				[
					'apikey'        => $this->supabase_key,
					'Authorization' => 'Bearer ' . $this->supabase_key,
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=representation',
				],
				$extra_headers
			);

			if ( array_key_exists( 'Prefer', $headers ) && '' === $headers['Prefer'] ) {
				unset( $headers['Prefer'] );
			}

			$args = [
				'method'  => strtoupper( $method ),
				'timeout' => 15,
				'headers' => $headers,
			];

			if ( ! empty( $body ) && ! in_array( strtoupper( $method ), [ 'GET', 'DELETE' ], true ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$res = wp_remote_request(
				$this->supabase_url . '/rest/v1/' . ltrim( $endpoint, '/' ),
				$args
			);

			if ( is_wp_error( $res ) ) {
				return [
					'code'  => 0,
					'data'  => null,
					'error' => $res->get_error_message(),
				];
			}

			$body_raw = wp_remote_retrieve_body( $res );
			$data     = '' !== $body_raw ? json_decode( $body_raw, true ) : null;

			return [
				'code'  => (int) wp_remote_retrieve_response_code( $res ),
				'data'  => $data,
				'error' => null,
			];
		}

		private function csv_to_array( string $value ): array {
			$value = sanitize_text_field( $value );

			if ( '' === $value ) {
				return [];
			}

			return array_values(
				array_filter(
					array_map(
						static function ( $item ) {
							return sanitize_text_field( trim( (string) $item ) );
						},
						explode( ',', $value )
					),
					static fn( $item ) => '' !== $item
				)
			);
		}

		private function uuid_or_null( string $value ): ?string {
			$value = sanitize_text_field( $value );

			if ( '' === $value ) {
				return null;
			}

			return preg_match( '/^[0-9a-f-]{36}$/i', $value ) ? $value : null;
		}

		private function normalize_package( array $pkg ): array {
			$pkg['items_list']           = isset( $pkg['items_list'] ) && is_array( $pkg['items_list'] ) ? $pkg['items_list'] : [];
			$pkg['compatibility_tags']   = isset( $pkg['compatibility_tags'] ) && is_array( $pkg['compatibility_tags'] ) ? $pkg['compatibility_tags'] : [];
			$pkg['attack_cards_pool']    = isset( $pkg['attack_cards_pool'] ) && is_array( $pkg['attack_cards_pool'] ) ? $pkg['attack_cards_pool'] : [];
			$pkg['defense_cards_pool']   = isset( $pkg['defense_cards_pool'] ) && is_array( $pkg['defense_cards_pool'] ) ? $pkg['defense_cards_pool'] : [];
			$pkg['compatible_class_ids'] = isset( $pkg['compatible_class_ids'] ) && is_array( $pkg['compatible_class_ids'] ) ? $pkg['compatible_class_ids'] : [];
			$pkg['is_player_selectable'] = ! empty( $pkg['is_player_selectable'] );
			$pkg['base_armor']           = isset( $pkg['base_armor'] ) ? (int) $pkg['base_armor'] : 0;

			return $pkg;
		}

		public function ajax_get_all(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$qs = 'cyber_starting_packages?select=id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable,head_item_id,torso_item_id,hand_r_item_id,hand_l_item_id,belt_item_id,compatible_class_ids,created_at&order=package_name.asc';
			$res = $this->supa( 'GET', $qs );

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			if ( $res['code'] < 200 || $res['code'] >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $res['code'];
				wp_send_json_error( $msg, 500 );
			}

			$rows = is_array( $res['data'] ) ? $res['data'] : [];
			$rows = array_map( [ $this, 'normalize_package' ], $rows );

			wp_send_json_success( $rows );
		}

		public function ajax_get_items(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$res = $this->supa( 'GET', 'cyber_items?select=id,name,slot,type&order=name.asc&is_active=eq.true' );

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			if ( $res['code'] < 200 || $res['code'] >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $res['code'];
				wp_send_json_error( $msg, 500 );
			}

			wp_send_json_success( is_array( $res['data'] ) ? $res['data'] : [] );
		}

		public function ajax_save(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$raw = wp_unslash( $_POST['pkg'] ?? [] );

			if ( ! is_array( $raw ) ) {
				wp_send_json_error( 'Invalid payload', 400 );
			}

			$id = sanitize_text_field( $raw['id'] ?? '' );

			$payload = [
				'package_name'         => sanitize_text_field( $raw['package_name'] ?? '' ),
				'description'          => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
				'base_armor'           => max( 0, (int) ( $raw['base_armor'] ?? 0 ) ),
				'is_player_selectable' => filter_var( $raw['is_player_selectable'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'head_item_id'         => $this->uuid_or_null( (string) ( $raw['head_item_id'] ?? '' ) ),
				'torso_item_id'        => $this->uuid_or_null( (string) ( $raw['torso_item_id'] ?? '' ) ),
				'hand_r_item_id'       => $this->uuid_or_null( (string) ( $raw['hand_r_item_id'] ?? '' ) ),
				'hand_l_item_id'       => $this->uuid_or_null( (string) ( $raw['hand_l_item_id'] ?? '' ) ),
				'belt_item_id'         => $this->uuid_or_null( (string) ( $raw['belt_item_id'] ?? '' ) ),
				'items_list'           => $this->csv_to_array( (string) ( $raw['items_list'] ?? '' ) ),
				'attack_cards_pool'    => $this->csv_to_array( (string) ( $raw['attack_cards_pool'] ?? '' ) ),
				'defense_cards_pool'   => $this->csv_to_array( (string) ( $raw['defense_cards_pool'] ?? '' ) ),
				'compatibility_tags'   => $this->csv_to_array( (string) ( $raw['compatibility_tags'] ?? '' ) ),
				'compatible_class_ids' => $this->csv_to_array( (string) ( $raw['compatible_class_ids'] ?? '' ) ),
			];

			if ( '' === $payload['package_name'] ) {
				wp_send_json_error( 'Package name is required', 400 );
			}

			$res = $id
				? $this->supa( 'PATCH', 'cyber_starting_packages?id=eq.' . rawurlencode( $id ), $payload )
				: $this->supa( 'POST', 'cyber_starting_packages', $payload );

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];

			if ( is_array( $item ) ) {
				$item = $this->normalize_package( $item );
			}

			wp_send_json_success( $item );
		}

		public function ajax_toggle(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$id = sanitize_text_field( wp_unslash( $_POST['pkg_id'] ?? '' ) );
			$state = filter_var( wp_unslash( $_POST['is_player_selectable'] ?? false ), FILTER_VALIDATE_BOOLEAN );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID', 400 );
			}

			$res = $this->supa(
				'PATCH',
				'cyber_starting_packages?id=eq.' . rawurlencode( $id ),
				[ 'is_player_selectable' => $state ]
			);

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			wp_send_json_success(
				[
					'id'                   => $id,
					'is_player_selectable' => $state,
				]
			);
		}

		public function ajax_delete(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$id = sanitize_text_field( wp_unslash( $_POST['pkg_id'] ?? '' ) );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID', 400 );
			}

			$res = $this->supa(
				'DELETE',
				'cyber_starting_packages?id=eq.' . rawurlencode( $id ),
				[]
			);

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			wp_send_json_success( 'deleted' );
		}
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NeoWeaver_Starting_Packages_Admin();
	},
	20
);
