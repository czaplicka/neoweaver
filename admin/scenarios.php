<?php
/**
 * NeoWeaver Admin — Scenarios
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWScenariosAdmin {

	private string $page_slug    = 'nw-scenarios';
	private string $menu_parent  = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_scenarios_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_scenarios_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_scenarios_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			'Scenarios',
			'<span data-lucide-menu="scroll-text"></span> Scenarios',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) return;

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style( 'chakra-petch', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
		}

		wp_enqueue_style( 'nw-admin-core',       NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',    [ 'chakra-petch' ],                    NW_VERSION );
		wp_enqueue_style( 'nw-scenarios-style',  NW_PLUGIN_URL . 'assets/css/admin/scenarios.css',     [ 'chakra-petch', 'nw-admin-core' ],   NW_VERSION );

		wp_enqueue_script( 'lucide',              'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script( 'nw-scenarios-script', NW_PLUGIN_URL . 'assets/js/admin/scenarios.js', [ 'jquery', 'lucide' ], NW_VERSION, true );

		wp_localize_script( 'nw-scenarios-script', 'NWScenarios', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( $this->nonce_action ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	private function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) return [];
		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		if ( $method === 'GET' && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];
			if ( $qs ) parse_str( $qs, $query );
			$data = tw_supabase_get( $table, $query, array_merge( $this->sk(), $extra_headers ) );
			if ( ! is_array( $data ) ) return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'Non-array response' ];
			if ( isset( $data['code'], $data['message'] ) ) return [ 'ok' => false, 'code' => (int) $data['code'], 'data' => null, 'error' => $data['message'] ];
			return [ 'ok' => true, 'code' => 200, 'data' => $data, 'error' => null ];
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query      = [];
			if ( $qs ) parse_str( $qs, $query );
			$extra_args = [];
			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}
			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}
			$res  = tw_supabase_request( $method, $table, $query, empty( $body ) ? null : $body, $extra_args );
			$ok   = $res['ok']   ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;
			if ( ! $ok ) {
				$msg = is_array( $data ) ? ( $data['message'] ?? 'Supabase error ' . $code ) : 'Supabase error ' . $code;
				return [ 'ok' => false, 'code' => $code, 'data' => $data, 'error' => $msg ];
			}
			return [ 'ok' => true, 'code' => $code, 'data' => $data, 'error' => null ];
		}

		return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'Supabase helpers not available.' ];
	}

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache(): void {
		delete_transient( $this->get_cache_key( $this->table . '_all' ) );
	}

	private function cached_get_all(): array {
		$cache_key = $this->get_cache_key( $this->table . '_all' );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) return $cached;

		$res = $this->supa( 'GET', $this->table . '?select=*&order=name.asc', [], $this->sk() );
		if ( ! $res['ok'] ) return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );
		return $rows;
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) return $default;
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function sanitize_type( string $v ): string {
		return in_array( $v, self::TYPES, true ) ? $v : 'main';
	}

	private function sanitize_category( string $v ): string {
		return in_array( $v, self::CATEGORIES, true ) ? $v : 'combat';
	}

	private function sanitize_json( string $raw, mixed $default ): mixed {
		$raw = trim( $raw );
		if ( $raw === '' ) return $default;
		$decoded = json_decode( $raw, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $default;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                 */
	/* ------------------------------------------------------------------ */

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$rows = $this->cached_get_all();
		if ( isset( $rows['error'] ) ) { wp_send_json_error( $rows['error'] ); return; }
		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id         = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		$name       = sanitize_text_field( wp_unslash( $_POST['name']      ?? '' ) );
		$type       = $this->sanitize_type( sanitize_text_field( wp_unslash( $_POST['type']     ?? 'main' ) ) );
		$category   = $this->sanitize_category( sanitize_text_field( wp_unslash( $_POST['category'] ?? 'combat' ) ) );
		$goal             = sanitize_textarea_field( wp_unslash( $_POST['goal']              ?? '' ) );
		$gm_instruction   = sanitize_textarea_field( wp_unslash( $_POST['gm_instruction']    ?? '' ) );
		$victory_condition = sanitize_textarea_field( wp_unslash( $_POST['victory_condition'] ?? '' ) );
		$fail_conditions  = sanitize_textarea_field( wp_unslash( $_POST['fail_conditions']   ?? '' ) );
		$img_url          = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$area_id          = sanitize_text_field( wp_unslash( $_POST['area_id'] ?? '' ) );

		$difficulty        = max( 1, min( 5, intval( wp_unslash( $_POST['difficulty']        ?? 3 ) ) ) );
		$min_entropy_raw   = wp_unslash( $_POST['min_entropy'] ?? '' );
		$max_entropy_raw   = wp_unslash( $_POST['max_entropy'] ?? '' );
		$min_entropy       = $min_entropy_raw !== '' ? intval( $min_entropy_raw ) : null;
		$max_entropy       = $max_entropy_raw !== '' ? intval( $max_entropy_raw ) : null;
		$reward_credits    = wp_unslash( $_POST['reward_credits'] ?? '' ) !== '' ? intval( wp_unslash( $_POST['reward_credits'] ) ) : null;
		$kingdom_tech      = wp_unslash( $_POST['kingdom_tech']   ?? '' ) !== '' ? intval( wp_unslash( $_POST['kingdom_tech'] ) )   : null;
		$kingdom_magic     = wp_unslash( $_POST['kingdom_magic']  ?? '' ) !== '' ? intval( wp_unslash( $_POST['kingdom_magic'] ) )  : null;
		$kingdom_wealth    = wp_unslash( $_POST['kingdom_wealth'] ?? '' ) !== '' ? intval( wp_unslash( $_POST['kingdom_wealth'] ) ) : null;
		$required_archetype_id = wp_unslash( $_POST['required_archetype_id'] ?? '' ) !== '' ? intval( wp_unslash( $_POST['required_archetype_id'] ) ) : null;

		$tags              = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['tags']           ?? '[]' ) ), [] );
		$required_tags     = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['required_tags']  ?? '[]' ) ), [] );
		$success_tags      = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['success_tags']   ?? '[]' ) ), [] );
		$failure_tags      = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['failure_tags']   ?? '[]' ) ), [] );
		$reward_items      = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['reward_items']   ?? '' ) ), null );
		$giver_npc_tag     = $this->sanitize_json( sanitize_textarea_field( wp_unslash( $_POST['giver_npc_tag']  ?? '' ) ), null );

		if ( ! is_array( $tags ) )          $tags          = [];
		if ( ! is_array( $required_tags ) ) $required_tags = [];
		if ( ! is_array( $success_tags ) )  $success_tags  = [];
		if ( ! is_array( $failure_tags ) )  $failure_tags  = [];

		$is_boss        = $this->bool_from_post( 'is_boss',        false );
		$is_key_arc     = $this->bool_from_post( 'is_key_arc',     false );
		$is_active      = $this->bool_from_post( 'is_active',      true );
		$is_repeatable  = $this->bool_from_post( 'is_repeatable',  false );

		if ( ! $name ) { wp_send_json_error( 'Name is required.' ); return; }

		// entropy validation
		if ( $min_entropy !== null && $max_entropy !== null && $min_entropy > $max_entropy ) {
			wp_send_json_error( 'Min entropy cannot be greater than max entropy.' ); return;
		}

		$payload = [
			'name'                  => $name,
			'type'                  => $type,
			'category'              => $category,
			'goal'                  => $goal ?: null,
			'gm_instruction'        => $gm_instruction ?: null,
			'victory_condition'     => $victory_condition ?: null,
			'fail_conditions'       => $fail_conditions ?: null,
			'img_url'               => $img_url ?: null,
			'area_id'               => $area_id ?: null,
			'difficulty'            => $difficulty,
			'min_entropy'           => $min_entropy,
			'max_entropy'           => $max_entropy,
			'reward_credits'        => $reward_credits,
			'kingdom_tech'          => $kingdom_tech,
			'kingdom_magic'         => $kingdom_magic,
			'kingdom_wealth'        => $kingdom_wealth,
			'required_archetype_id' => $required_archetype_id,
			'tags'                  => $tags,
			'required_tags'         => $required_tags,
			'success_tags'          => $success_tags,
			'failure_tags'          => $failure_tags,
			'reward_items'          => $reward_items,
			'giver_npc_tag'         => $giver_npc_tag,
			'is_boss'               => $is_boss,
			'is_key_arc'            => $is_key_arc,
			'is_active'             => $is_active,
			'is_repeatable'         => $is_repeatable,
		];

		if ( ! $id ) {
			$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		} else {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
		}

		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Save failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $row );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) { wp_send_json_error( 'Invalid scenario ID.' ); return; }

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Delete failed.' ); return; }

		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) { wp_send_json_error( 'Invalid scenario ID.' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) { wp_send_json_error( 'Original scenario not found.' ); return; }

		$orig = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		if ( empty( $orig ) ) { wp_send_json_error( 'Failed to read original.' ); return; }

		$payload              = $orig;
		unset( $payload['id'], $payload['created_at'] );
		$payload['name']      = ( $orig['name'] ?? 'Scenario' ) . ' (Copy)';
		$payload['is_active'] = false;

		$dup = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup['ok'] ) { wp_send_json_error( $dup['error'] ?? 'Duplicate failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $dup['data'] ) ? ( $dup['data'][0] ?? $dup['data'] ) : $dup['data'];
		wp_send_json_success( $row );
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                               */
	/* ------------------------------------------------------------------ */

	public function render_page(): void { ?>
<div class="nw-panel nw-scenarios-panel">

	<!-- Header -->
	<div class="nw-panel-header">
		<div>
			<h1 class="nw-panel-title">
				<i data-lucide="scroll-text" style="width:22px;height:22px;vertical-align:middle;margin-right:6px"></i>
				Scenarios
			</h1>
			<p class="nw-panel-subtitle">Create and manage game scenarios, quests and encounters.</p>
		</div>
		<div class="nw-header-actions">
			<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost" title="Refresh">
				<i data-lucide="refresh-cw" style="width:14px;height:14px;vertical-align:middle"></i>
			</button>
			<button id="nw-add-btn" class="nw-btn nw-btn-primary">
				<i data-lucide="plus" style="width:14px;height:14px;vertical-align:middle;margin-right:4px"></i>
				New Scenario
			</button>
		</div>
	</div>

	<!-- Notice -->
	<div id="nw-notice" class="nw-notice" style="display:none"></div>

	<!-- Stats -->
	<div class="nw-stats-bar">
		<span class="nw-stat-pill">Total <strong id="nw-total"></strong></span>
		<span class="nw-stat-pill nw-pill-active">Active <strong id="nw-active"></strong></span>
		<span class="nw-stat-pill nw-pill-inactive">Inactive <strong id="nw-inactive"></strong></span>
		<span class="nw-stat-pill nw-pill-boss">Boss <strong id="nw-boss"></strong></span>
		<span class="nw-stat-pill nw-pill-arc">Key Arc <strong id="nw-arc"></strong></span>
	</div>

	<!-- Filters -->
	<div class="nw-filters-bar">
		<input id="nw-search" type="text" class="nw-search-input" placeholder="Search name, goal…">
		<select id="nw-filter-type" class="nw-select-filter">
			<option value="">All types</option>
			<?php foreach ( self::TYPES as $t ) : ?>
				<option value="<?= esc_attr( $t ) ?>"><?= esc_html( ucfirst( $t ) ) ?></option>
			<?php endforeach; ?>
		</select>
		<select id="nw-filter-category" class="nw-select-filter">
			<option value="">All categories</option>
			<?php foreach ( self::CATEGORIES as $c ) : ?>
				<option value="<?= esc_attr( $c ) ?>"><?= esc_html( ucfirst( $c ) ) ?></option>
			<?php endforeach; ?>
		</select>
		<select id="nw-filter-difficulty" class="nw-select-filter">
			<option value="">All difficulties</option>
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
				<option value="<?= $i ?>">Difficulty <?= $i ?></option>
			<?php endfor; ?>
		</select>
		<select id="nw-filter-active" class="nw-select-filter">
			<option value="">All status</option>
			<option value="1">Active</option>
			<option value="0">Inactive</option>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none">
			<i data-lucide="x" style="width:12px;height:12px;vertical-align:middle"></i> Clear
		</button>
	</div>

	<!-- Table -->
	<div class="nw-table-wrap">
		<table class="nw-table">
			<thead>
				<tr>
					<th style="width:52px">Img</th>
					<th>Name</th>
					<th style="width:90px">Type</th>
					<th style="width:110px">Category</th>
					<th style="width:100px">Difficulty</th>
					<th style="width:80px">Entropy</th>
					<th>Flags</th>
					<th style="width:72px">Active</th>
					<th style="width:170px">Actions</th>
				</tr>
			</thead>
			<tbody id="nw-scenarios-tbody">
				<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading scenarios…</td></tr>
			</tbody>
		</table>
	</div>

</div><!-- .nw-panel -->

<!-- ===================== MODAL ===================== -->
<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
<div class="nw-modal nw-modal-wide">

	<div class="nw-modal-header">
		<h2 id="nw-modal-title">New Scenario</h2>
		<button id="nw-modal-close" class="nw-modal-close" aria-label="Close">
			<i data-lucide="x" style="width:16px;height:16px"></i>
		</button>
	</div>

	<div class="nw-modal-body">
	<form id="nw-scenario-form" autocomplete="off">
		<input type="hidden" id="nw-field-id">

		<!-- Basic Info -->
		<div class="nw-section-label">Basic Info</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<label for="nw-field-name">Name <span class="nw-req">*</span></label>
				<input type="text" id="nw-field-name" maxlength="200">
			</div>
			<div class="nw-field">
				<label for="nw-field-type">Type</label>
				<select id="nw-field-type" class="nw-select">
					<?php foreach ( self::TYPES as $t ) : ?>
						<option value="<?= esc_attr( $t ) ?>"><?= esc_html( ucfirst( $t ) ) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="nw-field">
				<label for="nw-field-category">Category</label>
				<select id="nw-field-category" class="nw-select">
					<?php foreach ( self::CATEGORIES as $c ) : ?>
						<option value="<?= esc_attr( $c ) ?>"><?= esc_html( ucfirst( $c ) ) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="nw-field">
				<label for="nw-field-area-id">Area ID <span class="nw-hint">(FK → cyber_areas)</span></label>
				<input type="text" id="nw-field-area-id" placeholder="e.g. area-slug">
			</div>
			<div class="nw-field">
				<label for="nw-field-img-url">Image URL</label>
				<input type="url" id="nw-field-img-url" placeholder="https://…">
			</div>
			<div id="nw-img-preview-wrap" class="nw-field-full" style="display:none">
				<img id="nw-img-preview" src="" alt="Preview" style="max-height:120px;border-radius:6px;border:1px solid #2a2a2a" loading="lazy">
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-goal">Goal</label>
				<textarea id="nw-field-goal" rows="2" placeholder="What must the player achieve?"></textarea>
			</div>
		</div>

		<!-- Difficulty & Entropy -->
		<div class="nw-section-label">
			<i data-lucide="gauge" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			Difficulty &amp; Entropy
		</div>
		<div class="nw-form-grid">
			<div class="nw-field">
				<label for="nw-field-difficulty">Difficulty <span class="nw-hint">(1–5)</span></label>
				<div class="nw-diff-wrap">
					<input type="number" id="nw-field-difficulty" min="1" max="5" value="3">
					<div class="nw-diff-stars" id="nw-diff-stars"></div>
				</div>
			</div>
			<div class="nw-field">
				<label>Entropy Range <span class="nw-hint">(optional)</span></label>
				<div class="nw-entropy-row">
					<input type="number" id="nw-field-min-entropy" placeholder="min" min="0">
					<span class="nw-entropy-sep">–</span>
					<input type="number" id="nw-field-max-entropy" placeholder="max" min="0">
				</div>
			</div>
		</div>

		<!-- Conditions -->
		<div class="nw-section-label">Conditions</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<label for="nw-field-victory">Victory Condition</label>
				<textarea id="nw-field-victory" rows="2"></textarea>
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-fail">Fail Conditions</label>
				<textarea id="nw-field-fail" rows="2"></textarea>
			</div>
		</div>

		<!-- Tags -->
		<div class="nw-section-label">
			<i data-lucide="tags" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			Tags (JSON arrays)
		</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<label for="nw-field-tags">Tags <span class="nw-hint">general tags for this scenario</span></label>
				<textarea id="nw-field-tags" rows="2" class="nw-code-field" placeholder='["urban","stealth"]'>[]</textarea>
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-required-tags">Required Tags <span class="nw-hint">world must have these</span></label>
				<textarea id="nw-field-required-tags" rows="2" class="nw-code-field" placeholder='["has_tech","no_magic"]'>[]</textarea>
			</div>
			<div class="nw-field">
				<label for="nw-field-success-tags">Success Tags <span class="nw-hint">added on win</span></label>
				<textarea id="nw-field-success-tags" rows="2" class="nw-code-field" placeholder='["boss_defeated"]'>[]</textarea>
			</div>
			<div class="nw-field">
				<label for="nw-field-failure-tags">Failure Tags <span class="nw-hint">added on loss</span></label>
				<textarea id="nw-field-failure-tags" rows="2" class="nw-code-field" placeholder='["fled_battle"]'>[]</textarea>
			</div>
		</div>

		<!-- Rewards -->
		<div class="nw-section-label">
			<i data-lucide="coins" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			Rewards &amp; Kingdom Impact
		</div>
		<div class="nw-form-grid">
			<div class="nw-field">
				<label for="nw-field-credits">Reward Credits</label>
				<input type="number" id="nw-field-credits" min="0" value="100">
			</div>
			<div class="nw-field">
				<label for="nw-field-kingdom-tech">Kingdom Tech Δ</label>
				<input type="number" id="nw-field-kingdom-tech" placeholder="e.g. 2 or -1">
			</div>
			<div class="nw-field">
				<label for="nw-field-kingdom-magic">Kingdom Magic Δ</label>
				<input type="number" id="nw-field-kingdom-magic" placeholder="e.g. 1">
			</div>
			<div class="nw-field">
				<label for="nw-field-kingdom-wealth">Kingdom Wealth Δ</label>
				<input type="number" id="nw-field-kingdom-wealth" placeholder="e.g. 3">
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-reward-items">Reward Items <span class="nw-hint">JSON array</span></label>
				<textarea id="nw-field-reward-items" rows="2" class="nw-code-field" placeholder='[{"item_id":"xxx","qty":1}]'></textarea>
			</div>
		</div>

		<!-- NPC & Archetype -->
		<div class="nw-section-label">NPC &amp; Archetype</div>
		<div class="nw-form-grid">
			<div class="nw-field">
				<label for="nw-field-archetype-id">Required Archetype ID <span class="nw-hint">(FK)</span></label>
				<input type="number" id="nw-field-archetype-id" min="1" placeholder="optional">
			</div>
			<div class="nw-field">
				<label for="nw-field-giver-npc-tag">Giver NPC Tag <span class="nw-hint">JSON</span></label>
				<input type="text" id="nw-field-giver-npc-tag" class="nw-code-field" placeholder='{"role":"merchant"}'>
			</div>
		</div>

		<!-- GM Instructions -->
		<div class="nw-section-label">GM Instructions</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<textarea id="nw-field-gm-instruction" rows="3" placeholder="Private notes for the Game Master…"></textarea>
			</div>
		</div>

		<!-- Flags -->
		<div class="nw-section-label">Flags</div>
		<div class="nw-flags-row">
			<label class="nw-toggle-label">
				<span class="nw-toggle"><input type="checkbox" id="nw-field-is-active" checked><span class="nw-toggle-slider"></span></span>
				Active
			</label>
			<label class="nw-toggle-label">
				<span class="nw-toggle"><input type="checkbox" id="nw-field-is-boss"><span class="nw-toggle-slider"></span></span>
				<span class="nw-flag-boss">Boss</span>
			</label>
			<label class="nw-toggle-label">
				<span class="nw-toggle"><input type="checkbox" id="nw-field-is-key-arc"><span class="nw-toggle-slider"></span></span>
				<span class="nw-flag-arc">Key Arc</span>
			</label>
			<label class="nw-toggle-label">
				<span class="nw-toggle"><input type="checkbox" id="nw-field-is-repeatable"><span class="nw-toggle-slider"></span></span>
				Repeatable
			</label>
		</div>

	</form>
	</div><!-- .nw-modal-body -->

	<div class="nw-modal-footer">
		<button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto">
			<i data-lucide="trash-2" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>Delete
		</button>
		<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
		<button id="nw-save-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			<span id="nw-save-label">Create Scenario</span>
		</button>
	</div>

</div><!-- .nw-modal -->
</div><!-- #nw-modal-overlay -->
<?php
	}
}
