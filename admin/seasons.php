<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NWSeasonsAdmin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_nw_seasons_load',    [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_seasons_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_seasons_delete',  [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_seasons_reorder', [ $this, 'ajax_reorder' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Seasons Config',
			'<span data-lucide-menu="sun"></span>Seasons',
			'manage_options',
			'nw-seasons',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'nw-seasons' ) ) return;
		wp_enqueue_style( 'nw-admin-core' );
		wp_enqueue_style(
			'nw-seasons-css',
			NW_PLUGIN_URL . 'assets/css/admin/seasons.css',
			[],
			NW_VERSION
		);
		wp_enqueue_script( 'nw-lucide' );
		wp_enqueue_script(
			'nw-seasons-js',
			NW_PLUGIN_URL . 'assets/js/admin/seasons.js',
			[ 'nw-lucide' ],
			NW_VERSION,
			true
		);
		wp_localize_script( 'nw-seasons-js', 'NWSeasons', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nwseasonsnonce' ),
		] );
	}

	public function render_page(): void {
		?>
		<div class="nw-admin-wrap" style="font-family:'Chakra Petch',monospace">
			<div class="nw-admin-header">
				<div class="nw-admin-header-left">
					<h1><i data-lucide="sun-moon" style="width:20px;height:20px;vertical-align:middle;margin-right:8px"></i> Seasons Config</h1>
					<p class="nw-admin-subtitle">Weather weights per season. All 6 weights must sum to 100.</p>
				</div>
				<div class="nw-admin-header-right">
					<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
						<i data-lucide="refresh-cw" style="width:13px;height:13px"></i> Refresh
					</button>
					<button id="nw-add-btn" class="nw-btn nw-btn-primary nw-btn-sm">
						<i data-lucide="plus" style="width:13px;height:13px"></i> Add Season
					</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none"></div>

			<div class="nw-stats-bar">
				<div class="nw-stat-item"><span id="nw-total">0</span><small>total seasons</small></div>
			</div>

			<div class="nw-table-card">
				<div class="nw-table-toolbar">
					<input id="nw-search" class="nw-input nw-input-sm" type="search" placeholder="Search season…">
					<span class="nw-toolbar-right"></span>
				</div>
				<div class="nw-table-wrap">
					<table class="nw-table">
						<thead>
							<tr>
								<th style="width:36px"></th>
								<th>Season</th>
								<th>Weather Weights</th>
								<th>Temp ×</th>
								<th>Sort</th>
								<th style="text-align:center">Actions</th>
							</tr>
						</thead>
						<tbody id="nw-seasons-tbody">
							<tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading…</td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- MODAL -->
			<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
				<div class="nw-modal nw-modal-seasons">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">New Season</h2>
						<button id="nw-modal-close" class="nw-btn nw-btn-ghost nw-btn-sm"><i data-lucide="x" style="width:14px;height:14px"></i></button>
					</div>
					<div class="nw-modal-body">
						<form id="nw-season-form" autocomplete="off">
							<input type="hidden" id="nw-field-original-name">

							<div class="nw-form-row">
								<label class="nw-label">Season Name <span class="nw-required">*</span></label>
								<input id="nw-field-name" class="nw-input" type="text" placeholder="e.g. spring" required>
								<small class="nw-field-hint">Lowercase, no spaces — this is the primary key.</small>
							</div>

							<div class="nw-form-row nw-form-3col">
								<div>
									<label class="nw-label">Description</label>
									<textarea id="nw-field-description" class="nw-input nw-textarea" rows="2" placeholder="Optional flavour text"></textarea>
								</div>
								<div>
									<label class="nw-label">Icon</label>
									<input id="nw-field-icon" class="nw-input" type="text" placeholder="☀️ or lucide name">
									<small class="nw-field-hint">Emoji or icon keyword</small>
								</div>
								<div>
									<label class="nw-label">Color</label>
									<div class="nw-color-row">
										<input id="nw-field-color-picker" class="nw-color-picker" type="color" value="#adff00">
										<input id="nw-field-color" class="nw-input" type="text" placeholder="#adff00">
									</div>
									<small class="nw-field-hint">Accent color</small>
								</div>
							</div>

							<div class="nw-form-row">
								<label class="nw-label">Weather Weights <span class="nw-required">*</span> — must sum to exactly 100</label>
								<div class="nw-weights-grid">
									<?php $weathers = [
										'sun'   => ['☀️', '#facc15'],
										'cloudy'=> ['🌥️', '#94a3b8'],
										'rain'  => ['🌧️', '#60a5fa'],
										'fog'   => ['🌫️', '#a1a1aa'],
										'storm' => ['⛈️', '#f87171'],
										'snow'  => ['❄️', '#bae6fd'],
									]; foreach ( $weathers as $k => $meta ) : ?>
									<div class="nw-weight-item">
										<span class="nw-weight-icon"><?= $meta[0]; ?></span>
										<label class="nw-weight-label"><?= ucfirst($k); ?></label>
										<input id="nw-field-<?= $k; ?>" class="nw-input nw-weight-input" type="number" min="0" max="100" value="<?= ($k === 'sun' || $k === 'cloudy' || $k === 'rain' || $k === 'fog') ? 25 : 0; ?>" data-weather="<?= $k; ?>">
									</div>
									<?php endforeach; ?>
								</div>
								<div class="nw-weights-total-row">
									<span>Total:</span>
									<span id="nw-weights-total" class="nw-weights-total">100</span>
									<span>/100</span>
									<span id="nw-weights-warning" class="nw-weights-warning" style="display:none">⚠ Must equal 100</span>
								</div>
								<div class="nw-weights-bar-wrap">
									<div id="nw-weights-bar" class="nw-weights-bar"></div>
								</div>
							</div>

							<div class="nw-form-row nw-form-3col">
								<div>
									<label class="nw-label">Temp Modifier</label>
									<input id="nw-field-temp" class="nw-input" type="number" min="0.01" step="0.01" value="1.00">
									<small class="nw-field-hint">Multiplier > 0 (1.0 = neutral)</small>
								</div>
								<div>
									<label class="nw-label">Sort Order</label>
									<input id="nw-field-sort" class="nw-input" type="number" min="0" value="0">
								</div>
							</div>
						</form>
					</div>
					<div class="nw-modal-footer">
						<button id="nw-delete-btn" class="nw-btn nw-btn-danger nw-btn-sm" style="display:none">
							<i data-lucide="trash-2" style="width:13px;height:13px"></i> Delete
						</button>
						<div class="nw-modal-footer-right">
							<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost nw-btn-sm">Cancel</button>
							<button id="nw-save-btn" class="nw-btn nw-btn-primary nw-btn-sm">
								<i data-lucide="save" style="width:13px;height:13px"></i>
								<span id="nw-save-label">Create Season</span>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---- helpers ---- */

	private function check_nonce(): void {
		if ( ! check_ajax_referer( 'nwseasonsnonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
	}

	private function build_payload( array $post ): array {
		$weathers = [ 'sun', 'cloudy', 'rain', 'fog', 'storm', 'snow' ];
		$weights  = [];
		$total    = 0;
		foreach ( $weathers as $w ) {
			$v = isset( $post[ 'weight_' . $w ] ) ? intval( $post[ 'weight_' . $w ] ) : 0;
			$v = max( 0, min( 100, $v ) );
			$weights[ 'weight_' . $w ] = $v;
			$total += $v;
		}
		if ( $total !== 100 ) return [ 'error' => 'Weather weights must sum to 100 (got ' . $total . ').' ];

		$temp = floatval( $post['temp_modifier'] ?? 1.0 );
		if ( $temp <= 0 ) return [ 'error' => 'Temp modifier must be greater than 0.' ];

		$name = sanitize_text_field( $post['season_name'] ?? '' );
		if ( ! $name ) return [ 'error' => 'Season name is required.' ];

		return array_merge( [
			'season_name'   => $name,
			'description'   => sanitize_textarea_field( $post['description'] ?? '' ) ?: null,
			'temp_modifier' => $temp,
			'color'         => sanitize_hex_color( $post['color'] ?? '' ) ?: null,
			'icon'          => sanitize_text_field( $post['icon'] ?? '' ) ?: null,
			'sort_order'    => intval( $post['sort_order'] ?? 0 ),
		], $weights );
	}

	/* ---- AJAX ---- */

	public function ajax_load(): void {
		$this->check_nonce();
		$res = tw_supabase_get_admin( 'cyber_seasons_config?order=sort_order.asc,season_name.asc' );
		if ( is_wp_error( $res ) ) { wp_send_json_error( $res->get_error_message() ); return; }
		wp_send_json_success( $res );
	}

	public function ajax_save(): void {
		$this->check_nonce();
		$payload = $this->build_payload( $_POST );
		if ( isset( $payload['error'] ) ) { wp_send_json_error( $payload['error'] ); return; }

		$original_name = sanitize_text_field( $_POST['original_name'] ?? '' );

		if ( empty( $original_name ) ) {
			$res = tw_supabase_request( 'POST', 'cyber_seasons_config', $payload );
		} else {
			$endpoint = 'cyber_seasons_config?season_name=eq.' . rawurlencode( $original_name );
			$res      = tw_supabase_request( 'PATCH', $endpoint, $payload );
		}

		if ( is_wp_error( $res ) ) { wp_send_json_error( $res->get_error_message() ); return; }
		wp_send_json_success( $res );
	}

	public function ajax_delete(): void {
		$this->check_nonce();
		$name = sanitize_text_field( $_POST['season_name'] ?? '' );
		if ( ! $name ) { wp_send_json_error( 'Season name is required.' ); return; }

		$res = tw_supabase_request( 'DELETE', 'cyber_seasons_config?season_name=eq.' . rawurlencode( $name ) );
		if ( is_wp_error( $res ) ) { wp_send_json_error( $res->get_error_message() ); return; }
		wp_send_json_success( 'Deleted.' );
	}

	public function ajax_reorder(): void {
		$this->check_nonce();
		$items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
		if ( ! is_array( $items ) ) { wp_send_json_error( 'Invalid data.' ); return; }
		foreach ( $items as $item ) {
			$name = sanitize_text_field( $item['season_name'] ?? '' );
			$sort = intval( $item['sort_order'] );
			if ( $name ) {
				tw_supabase_request(
					'PATCH',
					'cyber_seasons_config?season_name=eq.' . rawurlencode( $name ),
					[ 'sort_order' => $sort ]
				);
			}
		}
		wp_send_json_success( 'Reordered.' );
	}
}
