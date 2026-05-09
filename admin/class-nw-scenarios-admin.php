<?php
/**
 * NeoWeaver Admin — Scenarios (cyber_scenarios)
 *
 * Full CRUD for game scenarios: list, add, edit, delete, toggle active.
 * Auto-loaded by the glob() in neoweaver-wp-core.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Scenarios_Admin {

	private $slug        = 'neoweaver';
	private $page_slug   = 'nw-scenarios';
	private $table       = 'cyber_scenarios';
	private $per_page    = 20;

	/* ---------- valid enum values (mirror DB constraints) ---------- */
	private $types      = [ 'main', 'personal', 'social', 'world' ];
	private $categories = [ 'combat', 'social', 'magic', 'investigation', 'worlds', 'sidequest', 'family' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_submenu'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'    ] );
		add_action( 'wp_ajax_nw_scenario_save',   [ $this, 'ajax_save'   ] );
		add_action( 'wp_ajax_nw_scenario_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_scenario_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_scenario_list',   [ $this, 'ajax_list'   ] );
		add_action( 'wp_ajax_nw_scenario_get',    [ $this, 'ajax_get'    ] );
	}

	/* ================================================================ */
	/*  MENU                                                             */
	/* ================================================================ */

	public function register_submenu() {
		add_submenu_page(
			$this->slug,
			'Scenarios',
			'🗺️ Scenarios',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/* ================================================================ */
	/*  ASSETS                                                           */
	/* ================================================================ */

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, $this->page_slug ) === false ) return;
		wp_enqueue_style(
			'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[],
			null
		);
	}

	/* ================================================================ */
	/*  SUPABASE HELPERS                                                 */
	/* ================================================================ */

	private function supa_url()  {
		return function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
	}
	private function supa_key()  {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return trim( (string) tw_supabase_service_key() );
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return trim( (string) tw_supabase_anon_key() );
		}
		return '';
	}
	private function headers() {
		return [
			'apikey'        => $this->supa_key(),
			'Authorization' => 'Bearer ' . $this->supa_key(),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	/** Generic GET → returns ['ok', 'status', 'body', 'error', 'count'] */
	private function supa_get( $path, $prefer = '' ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . ltrim( $path, '/' );
		$hdrs = $this->headers();
		if ( $prefer ) $hdrs['Prefer'] = $prefer;

		$res = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => $hdrs ] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'status' => 0, 'body' => null, 'error' => $res->get_error_message(), 'count' => 0 ];
		}
		$code  = (int) wp_remote_retrieve_response_code( $res );
		$body  = wp_remote_retrieve_body( $res );
		$data  = json_decode( $body, true );
		$cr    = wp_remote_retrieve_header( $res, 'content-range' );
		$count = 0;
		if ( $cr && preg_match( '/\/(\d+)$/', $cr, $m ) ) $count = (int) $m[1];
		return [
			'ok'     => ( $code >= 200 && $code < 300 ),
			'status' => $code,
			'body'   => $data,
			'error'  => ( $code < 200 || $code >= 300 ) ? substr( $body, 0, 400 ) : null,
			'count'  => $count,
		];
	}

	/** Generic POST (insert) */
	private function supa_post( $path, $payload ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . ltrim( $path, '/' );
		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'headers' => array_merge( $this->headers(), [ 'Prefer' => 'return=representation' ] ),
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code, 'body' => $body ];
	}

	/** Generic PATCH (update by id) */
	private function supa_patch( $table, $id, $payload ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . $table . '?id=eq.' . (int) $id;
		$res = wp_remote_request( $url, [
			'method'  => 'PATCH',
			'timeout' => 15,
			'headers' => array_merge( $this->headers(), [ 'Prefer' => 'return=representation' ] ),
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code, 'body' => $body ];
	}

	/** Generic DELETE by id */
	private function supa_delete( $table, $id ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . $table . '?id=eq.' . (int) $id;
		$res = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => $this->headers(),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code ];
	}

	/* ================================================================ */
	/*  AJAX HANDLERS                                                    */
	/* ================================================================ */

	/** List with pagination + optional filters */
	public function ajax_list() {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$page     = max( 1, (int) ( $_POST['page']     ?? 1 ) );
		$search   = sanitize_text_field( $_POST['search']   ?? '' );
		$type     = sanitize_text_field( $_POST['type']     ?? '' );
		$category = sanitize_text_field( $_POST['category'] ?? '' );
		$diff     = (int) ( $_POST['difficulty'] ?? 0 );
		$only_active = ! empty( $_POST['only_active'] );

		$offset = ( $page - 1 ) * $this->per_page;

		$qs = $this->table
			. '?select=id,name,type,category,difficulty,is_boss,is_key_arc,is_active,is_repeatable,area_id,reward_credits,created_at'
			. '&order=created_at.desc'
			. '&limit=' . $this->per_page
			. '&offset=' . $offset;

		if ( $search )      $qs .= '&name=ilike.*' . rawurlencode( $search ) . '*';
		if ( $type )        $qs .= '&type=eq.'     . rawurlencode( $type );
		if ( $category )    $qs .= '&category=eq.' . rawurlencode( $category );
		if ( $diff > 0 )    $qs .= '&difficulty=eq.' . $diff;
		if ( $only_active ) $qs .= '&is_active=eq.true';

		$res = $this->supa_get( $qs, 'count=exact' );

		if ( ! $res['ok'] ) {
			wp_send_json_error( 'Supabase error: ' . $res['error'] );
		}

		wp_send_json_success( [
			'rows'     => is_array( $res['body'] ) ? $res['body'] : [],
			'total'    => $res['count'],
			'page'     => $page,
			'per_page' => $this->per_page,
		] );
	}

	/** Get single scenario by id */
	public function ajax_get() {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$id  = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		$res = $this->supa_get( $this->table . '?id=eq.' . $id . '&limit=1' );
		if ( ! $res['ok'] || empty( $res['body'][0] ) ) {
			wp_send_json_error( 'Not found' );
		}
		wp_send_json_success( $res['body'][0] );
	}

	/** Save (insert or update) */
	public function ajax_save() {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$id = (int) ( $_POST['id'] ?? 0 );

		/* --- validate required --- */
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		if ( ! $name ) wp_send_json_error( 'Name is required.' );

		$type     = sanitize_text_field( $_POST['type']     ?? 'main' );
		$category = sanitize_text_field( $_POST['category'] ?? 'combat' );
		$diff     = max( 1, min( 5, (int) ( $_POST['difficulty'] ?? 3 ) ) );

		if ( ! in_array( $type,     $this->types,      true ) ) wp_send_json_error( 'Invalid type.' );
		if ( ! in_array( $category, $this->categories, true ) ) wp_send_json_error( 'Invalid category.' );

		/* --- sanitise json fields --- */
		$tags           = $this->clean_json_array( $_POST['tags']           ?? '[]' );
		$required_tags  = $this->clean_json_array( $_POST['required_tags']  ?? '[]' );
		$success_tags   = $this->clean_json_array( $_POST['success_tags']   ?? '[]' );
		$failure_tags   = $this->clean_json_array( $_POST['failure_tags']   ?? '[]' );
		$reward_items   = $this->clean_json_object( $_POST['reward_items']  ?? 'null' );
		$giver_npc_tag  = $this->clean_json_object( $_POST['giver_npc_tag'] ?? 'null' );

		/* --- entropy --- */
		$min_entropy = strlen( $_POST['min_entropy'] ?? '' ) ? (int) $_POST['min_entropy'] : null;
		$max_entropy = strlen( $_POST['max_entropy'] ?? '' ) ? (int) $_POST['max_entropy'] : null;
		if ( $min_entropy !== null && $max_entropy !== null && $min_entropy > $max_entropy ) {
			wp_send_json_error( 'min_entropy must be ≤ max_entropy.' );
		}

		$payload = [
			'name'                  => $name,
			'type'                  => $type,
			'category'              => $category,
			'goal'                  => sanitize_textarea_field( $_POST['goal']                ?? '' ) ?: null,
			'gm_instruction'        => sanitize_textarea_field( $_POST['gm_instruction']      ?? '' ) ?: null,
			'tags'                  => $tags,
			'required_tags'         => $required_tags,
			'success_tags'          => $success_tags,
			'failure_tags'          => $failure_tags,
			'victory_condition'     => sanitize_textarea_field( $_POST['victory_condition']   ?? '' ) ?: null,
			'fail_conditions'       => sanitize_textarea_field( $_POST['fail_conditions']     ?? '' ) ?: null,
			'difficulty'            => $diff,
			'min_entropy'           => $min_entropy,
			'max_entropy'           => $max_entropy,
			'is_boss'               => ! empty( $_POST['is_boss'] ),
			'is_key_arc'            => ! empty( $_POST['is_key_arc'] ),
			'is_active'             => ! empty( $_POST['is_active'] ),
			'is_repeatable'         => ! empty( $_POST['is_repeatable'] ),
			'area_id'               => sanitize_text_field( $_POST['area_id'] ?? '' ) ?: null,
			'img_url'               => esc_url_raw( $_POST['img_url'] ?? '' ) ?: null,
			'reward_credits'        => strlen( $_POST['reward_credits'] ?? '' ) ? (int) $_POST['reward_credits'] : null,
			'reward_items'          => $reward_items,
			'kingdom_tech'          => strlen( $_POST['kingdom_tech']   ?? '' ) ? (int) $_POST['kingdom_tech']   : null,
			'kingdom_magic'         => strlen( $_POST['kingdom_magic']  ?? '' ) ? (int) $_POST['kingdom_magic']  : null,
			'kingdom_wealth'        => strlen( $_POST['kingdom_wealth'] ?? '' ) ? (int) $_POST['kingdom_wealth'] : null,
			'required_archetype_id' => strlen( $_POST['required_archetype_id'] ?? '' ) ? (int) $_POST['required_archetype_id'] : null,
			'giver_npc_tag'         => $giver_npc_tag,
		];

		if ( $id ) {
			$res = $this->supa_patch( $this->table, $id, $payload );
		} else {
			$res = $this->supa_post( $this->table, $payload );
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( 'Save failed (HTTP ' . $res['status'] . ')' );
		}
		wp_send_json_success( [ 'id' => $id ?: ( $res['body'][0]['id'] ?? 0 ) ] );
	}

	/** Delete */
	public function ajax_delete() {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		$res = $this->supa_delete( $this->table, $id );
		if ( ! $res['ok'] ) wp_send_json_error( 'Delete failed (HTTP ' . $res['status'] . ')' );
		wp_send_json_success();
	}

	/** Toggle is_active */
	public function ajax_toggle() {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$id    = (int)  ( $_POST['id']    ?? 0 );
		$value = (bool) ( $_POST['value'] ?? false );
		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		$res = $this->supa_patch( $this->table, $id, [ 'is_active' => $value ] );
		if ( ! $res['ok'] ) wp_send_json_error( 'Toggle failed' );
		wp_send_json_success();
	}

	/* ================================================================ */
	/*  HELPERS                                                          */
	/* ================================================================ */

	/** Parse JSON array from POST, return PHP array (never null). */
	private function clean_json_array( $raw ) {
		$v = json_decode( stripslashes( $raw ), true );
		return ( is_array( $v ) ) ? $v : [];
	}

	/** Parse JSON object/null from POST. Returns PHP array or null. */
	private function clean_json_object( $raw ) {
		$stripped = trim( stripslashes( $raw ) );
		if ( $stripped === '' || $stripped === 'null' ) return null;
		$v = json_decode( $stripped, true );
		return is_array( $v ) ? $v : null;
	}

	/* ================================================================ */
	/*  RENDER PAGE                                                      */
	/* ================================================================ */

	public function render_page() {
		$nonce = wp_create_nonce( 'nw_scenarios_nonce' );
		?>
		<div class="wrap nw-scenarios-wrap">

			<!-- ── HEADER ─────────────────────────────────────────────── -->
			<div class="nw-page-header">
				<h1 class="nw-page-title">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-4px;margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-3-3v6M3 12a9 9 0 1 0 18 0A9 9 0 0 0 3 12z"/></svg>
					Scenarios
				</h1>
				<button class="nw-btn nw-btn-primary" id="nw-scen-add-btn">+ Add Scenario</button>
			</div>

			<!-- ── FILTERS ────────────────────────────────────────────── -->
			<div class="nw-scen-filters">
				<input type="text" id="nw-scen-search" class="nw-input" placeholder="Search by name…" style="min-width:200px;">
				<select id="nw-scen-filter-type" class="nw-input">
					<option value="">All types</option>
					<?php foreach ( $this->types as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="nw-scen-filter-cat" class="nw-input">
					<option value="">All categories</option>
					<?php foreach ( $this->categories as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="nw-scen-filter-diff" class="nw-input">
					<option value="0">All difficulties</option>
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<option value="<?php echo $i; ?>"><?php echo str_repeat('★', $i) . str_repeat('☆', 5-$i); ?></option>
					<?php endfor; ?>
				</select>
				<label class="nw-toggle-label">
					<input type="checkbox" id="nw-scen-filter-active"> Active only
				</label>
				<button class="nw-btn nw-btn-ghost" id="nw-scen-search-btn">Filter</button>
			</div>

			<!-- ── TABLE ──────────────────────────────────────────────── -->
			<div id="nw-scen-table-wrap">
				<div class="nw-spinner" style="margin:40px auto;display:block;"></div>
			</div>

			<!-- ── PAGINATION ─────────────────────────────────────────── -->
			<div class="nw-scen-pagination" id="nw-scen-pagination"></div>

			<!-- ══════════════════════════════════════════════════════════ -->
			<!--  MODAL: Add / Edit                                         -->
			<!-- ══════════════════════════════════════════════════════════ -->
			<div id="nw-scen-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-scen-modal-title">
				<div class="nw-modal-box nw-scen-modal-box">
					<div class="nw-modal-header">
						<h2 class="nw-modal-title" id="nw-scen-modal-title">Add Scenario</h2>
						<button class="nw-modal-close" id="nw-scen-modal-close" aria-label="Close">&times;</button>
					</div>
					<form id="nw-scen-form" autocomplete="off">
						<input type="hidden" name="id" id="nw-scen-id">

						<div class="nw-form-grid-2">
							<!-- Name -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-name">Name <span class="nw-required">*</span></label>
								<input class="nw-input" type="text" id="nw-scen-name" name="name" required>
							</div>

							<!-- Type -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-type">Type</label>
								<select class="nw-input" id="nw-scen-type" name="type">
									<?php foreach ( $this->types as $t ) : ?>
										<option value="<?php echo esc_attr($t); ?>"><?php echo esc_html( ucfirst($t) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<!-- Category -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-category">Category</label>
								<select class="nw-input" id="nw-scen-category" name="category">
									<?php foreach ( $this->categories as $c ) : ?>
										<option value="<?php echo esc_attr($c); ?>"><?php echo esc_html( ucfirst($c) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<!-- Difficulty -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-difficulty">Difficulty (1–5)</label>
								<input class="nw-input" type="number" id="nw-scen-difficulty" name="difficulty" min="1" max="5" value="3">
							</div>

							<!-- Area ID -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-area-id">Area ID</label>
								<input class="nw-input" type="text" id="nw-scen-area-id" name="area_id" placeholder="optional">
							</div>

							<!-- Goal -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-goal">Goal</label>
								<textarea class="nw-input nw-textarea" id="nw-scen-goal" name="goal" rows="2"></textarea>
							</div>

							<!-- GM Instruction -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-gm-instruction">GM Instruction</label>
								<textarea class="nw-input nw-textarea" id="nw-scen-gm-instruction" name="gm_instruction" rows="3"></textarea>
							</div>

							<!-- Victory Condition -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-victory">Victory Condition</label>
								<textarea class="nw-input nw-textarea" id="nw-scen-victory" name="victory_condition" rows="2"></textarea>
							</div>

							<!-- Fail Conditions -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-fail">Fail Conditions</label>
								<textarea class="nw-input nw-textarea" id="nw-scen-fail" name="fail_conditions" rows="2"></textarea>
							</div>

							<!-- Tags (JSON array displayed as comma list) -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-scen-tags">Tags <span class="nw-field-hint">(comma-separated)</span></label>
								<input class="nw-input" type="text" id="nw-scen-tags" placeholder='e.g. "combat","dark"'>
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-required-tags">Required Tags</label>
								<input class="nw-input" type="text" id="nw-scen-required-tags" placeholder="comma-separated">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-success-tags">Success Tags</label>
								<input class="nw-input" type="text" id="nw-scen-success-tags" placeholder="comma-separated">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-failure-tags">Failure Tags</label>
								<input class="nw-input" type="text" id="nw-scen-failure-tags" placeholder="comma-separated">
							</div>

							<!-- Entropy -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-min-entropy">Min Entropy</label>
								<input class="nw-input" type="number" id="nw-scen-min-entropy" name="min_entropy" placeholder="null">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-max-entropy">Max Entropy</label>
								<input class="nw-input" type="number" id="nw-scen-max-entropy" name="max_entropy" placeholder="null">
							</div>

							<!-- Rewards -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-reward-credits">Reward Credits</label>
								<input class="nw-input" type="number" id="nw-scen-reward-credits" name="reward_credits" placeholder="100">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-reward-items">Reward Items <span class="nw-field-hint">(JSON or null)</span></label>
								<input class="nw-input" type="text" id="nw-scen-reward-items" name="reward_items" placeholder="null">
							</div>

							<!-- Kingdom effects -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-ktech">Kingdom Tech Δ</label>
								<input class="nw-input" type="number" id="nw-scen-ktech" name="kingdom_tech">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-kmagic">Kingdom Magic Δ</label>
								<input class="nw-input" type="number" id="nw-scen-kmagic" name="kingdom_magic">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-kwealth">Kingdom Wealth Δ</label>
								<input class="nw-input" type="number" id="nw-scen-kwealth" name="kingdom_wealth">
							</div>
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-archetype">Required Archetype ID</label>
								<input class="nw-input" type="number" id="nw-scen-archetype" name="required_archetype_id">
							</div>

							<!-- Giver NPC tag -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-giver-npc">Giver NPC Tag <span class="nw-field-hint">(JSON or null)</span></label>
								<input class="nw-input" type="text" id="nw-scen-giver-npc" name="giver_npc_tag" placeholder="null">
							</div>

							<!-- Image URL -->
							<div class="nw-field">
								<label class="nw-label" for="nw-scen-img">Image URL</label>
								<input class="nw-input" type="url" id="nw-scen-img" name="img_url" placeholder="https://…">
							</div>

							<!-- Booleans -->
							<div class="nw-field nw-field-full nw-bool-row">
								<label class="nw-check-label"><input type="checkbox" name="is_active"     id="nw-scen-is-active"     checked> Active</label>
								<label class="nw-check-label"><input type="checkbox" name="is_boss"       id="nw-scen-is-boss"            > Boss</label>
								<label class="nw-check-label"><input type="checkbox" name="is_key_arc"    id="nw-scen-is-key-arc"         > Key Arc</label>
								<label class="nw-check-label"><input type="checkbox" name="is_repeatable" id="nw-scen-is-repeatable"      > Repeatable</label>
							</div>
						</div><!-- /.nw-form-grid-2 -->

						<div class="nw-modal-footer">
							<span class="nw-save-error" id="nw-scen-save-error"></span>
							<button type="button" class="nw-btn nw-btn-ghost" id="nw-scen-cancel-btn">Cancel</button>
							<button type="submit" class="nw-btn nw-btn-primary" id="nw-scen-save-btn">Save Scenario</button>
						</div>
					</form>
				</div>
			</div><!-- /#nw-scen-modal -->

		</div><!-- /.wrap -->

		<?php $this->render_styles(); ?>
		<?php $this->render_scripts( $nonce ); ?>
		<?php
	}

	/* ================================================================ */
	/*  INLINE CSS                                                       */
	/* ================================================================ */

	private function render_styles() { ?>
		<style>
		:root { --nw-accent:#adff00; --nw-bg:#0d0d0d; --nw-surface:#141414; --nw-border:#2a2a2a; --nw-text:#e0e0e0; --nw-muted:#888; }
		.nw-scenarios-wrap { font-family:'Chakra Petch',sans-serif; color:var(--nw-text); background:var(--nw-bg); padding:20px; min-height:80vh; }
		/* Header */
		.nw-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
		.nw-page-title  { font-size:1.4rem; font-weight:700; color:var(--nw-accent); margin:0; }
		/* Filters */
		.nw-scen-filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; align-items:center; }
		.nw-toggle-label { display:flex; align-items:center; gap:6px; font-size:0.82rem; color:var(--nw-muted); cursor:pointer; }
		/* Inputs */
		.nw-input { background:#1a1a1a; border:1px solid var(--nw-border); color:var(--nw-text); padding:6px 10px; border-radius:4px; font-family:inherit; font-size:0.82rem; }
		.nw-input:focus { outline:none; border-color:var(--nw-accent); }
		.nw-textarea { width:100%; resize:vertical; min-height:60px; }
		/* Buttons */
		.nw-btn { padding:7px 16px; border-radius:4px; font-family:inherit; font-size:0.82rem; font-weight:600; cursor:pointer; border:none; transition:background .15s,color .15s; }
		.nw-btn-primary { background:var(--nw-accent); color:#0d0d0d; }
		.nw-btn-primary:hover { background:#c8ff1a; }
		.nw-btn-ghost { background:transparent; border:1px solid var(--nw-border); color:var(--nw-text); }
		.nw-btn-ghost:hover { border-color:var(--nw-accent); color:var(--nw-accent); }
		.nw-btn-danger { background:#c0392b; color:#fff; }
		.nw-btn-danger:hover { background:#e74c3c; }
		/* Spinner */
		.nw-spinner { width:28px; height:28px; border:3px solid var(--nw-border); border-top-color:var(--nw-accent); border-radius:50%; animation:nw-spin .7s linear infinite; }
		@keyframes nw-spin { to { transform:rotate(360deg); } }
		/* Table */
		.nw-scen-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
		.nw-scen-table th { background:var(--nw-surface); color:var(--nw-muted); text-align:left; padding:8px 12px; border-bottom:1px solid var(--nw-border); font-weight:600; white-space:nowrap; }
		.nw-scen-table td { padding:8px 12px; border-bottom:1px solid var(--nw-border); vertical-align:middle; }
		.nw-scen-table tr:hover td { background:#1a1a1a; }
		/* Badges */
		.nw-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
		.nw-badge-active   { background:#1e3a1e; color:#adff00; }
		.nw-badge-inactive { background:#2a1e1e; color:#ff6b6b; }
		.nw-badge-type     { background:#1e2a3a; color:#7ec8ff; }
		.nw-badge-cat      { background:#2a2a1e; color:#ffe07e; }
		.nw-stars { color:var(--nw-accent); letter-spacing:-1px; }
		.nw-stars-empty { color:var(--nw-border); }
		/* Action buttons in table */
		.nw-tbl-actions { display:flex; gap:6px; flex-wrap:nowrap; }
		.nw-btn-xs { padding:3px 10px; font-size:0.75rem; }
		/* Pagination */
		.nw-scen-pagination { display:flex; gap:8px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
		.nw-page-btn { padding:4px 12px; font-size:0.8rem; }
		.nw-page-btn.active { background:var(--nw-accent); color:#0d0d0d; border-color:var(--nw-accent); }
		/* Modal */
		.nw-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:99999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
		.nw-scen-modal-box { background:#141414; border:1px solid var(--nw-border); border-radius:8px; width:min(780px,95vw); max-height:90vh; overflow-y:auto; display:flex; flex-direction:column; }
		.nw-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--nw-border); position:sticky; top:0; background:#141414; z-index:2; }
		.nw-modal-title { margin:0; font-size:1.05rem; color:var(--nw-accent); }
		.nw-modal-close { background:none; border:none; color:var(--nw-muted); font-size:1.5rem; cursor:pointer; padding:0 4px; line-height:1; }
		.nw-modal-close:hover { color:var(--nw-text); }
		/* Form inside modal */
		#nw-scen-form { padding:20px; }
		.nw-form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
		.nw-field { display:flex; flex-direction:column; gap:5px; }
		.nw-field-full { grid-column:1/-1; }
		.nw-label { font-size:0.78rem; color:var(--nw-muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
		.nw-required { color:var(--nw-accent); }
		.nw-field-hint { font-weight:400; text-transform:none; letter-spacing:0; color:#666; }
		.nw-bool-row { display:flex; flex-wrap:wrap; gap:20px; align-items:center; }
		.nw-check-label { display:flex; align-items:center; gap:6px; font-size:0.82rem; cursor:pointer; }
		.nw-check-label input { accent-color:var(--nw-accent); width:15px; height:15px; }
		.nw-modal-footer { display:flex; align-items:center; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid var(--nw-border); }
		.nw-save-error { color:#ff6b6b; font-size:0.8rem; flex:1; }
		/* Empty state */
		.nw-empty { text-align:center; padding:60px 20px; color:var(--nw-muted); }
		.nw-empty svg { margin:0 auto 16px; opacity:.3; }
		</style>
	<?php }

	/* ================================================================ */
	/*  INLINE JS                                                        */
	/* ================================================================ */

	private function render_scripts( $nonce ) {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$types_json = wp_json_encode( $this->types );
		$cats_json  = wp_json_encode( $this->categories );
		?>
		<script>
		(function($){
			/* ── state ─────────────────────────────── */
			const NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
			const AJAX     = <?php echo wp_json_encode( $ajax_url ); ?>;
			const TYPES    = <?php echo $types_json; ?>;
			const CATS     = <?php echo $cats_json; ?>;
			let currentPage = 1;
			let lastTotal   = 0;

			/* ── helpers ───────────────────────────── */
			const esc = s => $('<span>').text(s).html();
			const stars = n => '★'.repeat(n) + '☆'.repeat(5-n);
			const commaTags = arr => Array.isArray(arr) ? arr.join(', ') : '';
			const tagArray  = str => str.split(',').map(s=>s.trim().replace(/^"|"$/g,'')).filter(Boolean);

			function post(action, data){
				return $.post(AJAX, Object.assign({action, nonce:NONCE}, data));
			}

			/* ── load list ─────────────────────────── */
			function loadList(page){
				page = page || 1;
				currentPage = page;
				$('#nw-scen-table-wrap').html('<div class="nw-spinner" style="margin:40px auto;display:block;"></div>');

				post('nw_scenario_list', {
					page,
					search:      $('#nw-scen-search').val(),
					type:        $('#nw-scen-filter-type').val(),
					category:    $('#nw-scen-filter-cat').val(),
					difficulty:  $('#nw-scen-filter-diff').val(),
					only_active: $('#nw-scen-filter-active').is(':checked') ? 1 : 0,
				}).done(function(res){
					if(!res.success){ $('#nw-scen-table-wrap').html('<p style="color:#ff6b6b;">'+esc(res.data)+'</p>'); return; }
					const d = res.data;
					lastTotal = d.total;
					renderTable(d.rows);
					renderPagination(d.total, d.page, d.per_page);
				}).fail(function(){
					$('#nw-scen-table-wrap').html('<p style="color:#ff6b6b;">Request failed.</p>');
				});
			}

			function renderTable(rows){
				if(!rows.length){
					$('#nw-scen-table-wrap').html('<div class="nw-empty"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2a4 4 0 0 1 4-4h2m-6 6H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4"/></svg><p>No scenarios found.</p><p style="font-size:.8rem">Add one with the button above.</p></div>');
					return;
				}
				let html = '<table class="nw-scen-table"><thead><tr>'
					+'<th>#</th><th>Name</th><th>Type</th><th>Category</th><th>Diff</th><th>Boss</th><th>Active</th><th>Actions</th>'
					+'</tr></thead><tbody>';
				rows.forEach(r=>{
					const active = r.is_active
						? '<span class="nw-badge nw-badge-active">Active</span>'
						: '<span class="nw-badge nw-badge-inactive">Inactive</span>';
					const boss = r.is_boss ? '⚡' : '';
					html += `<tr data-id="${r.id}">
						<td style="color:var(--nw-muted);font-size:.75rem">${r.id}</td>
						<td><strong>${esc(r.name)}</strong>${r.is_key_arc?'<span title="Key Arc" style="margin-left:6px;color:var(--nw-accent)">◆</span>':''}</td>
						<td><span class="nw-badge nw-badge-type">${esc(r.type)}</span></td>
						<td><span class="nw-badge nw-badge-cat">${esc(r.category)}</span></td>
						<td><span class="nw-stars">${'★'.repeat(r.difficulty)}</span><span class="nw-stars-empty">${'☆'.repeat(5-r.difficulty)}</span></td>
						<td style="text-align:center">${boss}</td>
						<td>${active}</td>
						<td><div class="nw-tbl-actions">
							<button class="nw-btn nw-btn-ghost nw-btn-xs nw-edit-btn" data-id="${r.id}">Edit</button>
							<button class="nw-btn nw-btn-xs ${r.is_active?'nw-btn-ghost':'nw-btn-ghost'} nw-toggle-btn" data-id="${r.id}" data-val="${r.is_active?0:1}">${r.is_active?'Deactivate':'Activate'}</button>
							<button class="nw-btn nw-btn-danger nw-btn-xs nw-delete-btn" data-id="${r.id}">Delete</button>
						</div></td>
					</tr>`;
				});
				html += '</tbody></table>';
				$('#nw-scen-table-wrap').html(html);
			}

			function renderPagination(total, page, perPage){
				const pages = Math.ceil(total/perPage);
				if(pages<=1){ $('#nw-scen-pagination').html(''); return; }
				let html = '';
				for(let i=1;i<=pages;i++){
					html += `<button class="nw-btn nw-btn-ghost nw-page-btn${i===page?' active':''}" data-page="${i}">${i}</button>`;
				}
				html += `<span style="font-size:.78rem;color:var(--nw-muted);align-self:center;">(${total} total)</span>`;
				$('#nw-scen-pagination').html(html);
			}

			/* ── modal helpers ─────────────────────── */
			function openModal(title){
				$('#nw-scen-modal-title').text(title);
				$('#nw-scen-save-error').text('');
				$('#nw-scen-modal').show();
				$('#nw-scen-name').focus();
			}
			function closeModal(){
				$('#nw-scen-modal').hide();
				$('#nw-scen-form')[0].reset();
				$('#nw-scen-id').val('');
			}

			function populateForm(r){
				$('#nw-scen-id').val(r.id);
				$('#nw-scen-name').val(r.name);
				$('#nw-scen-type').val(r.type);
				$('#nw-scen-category').val(r.category);
				$('#nw-scen-difficulty').val(r.difficulty);
				$('#nw-scen-goal').val(r.goal||'');
				$('#nw-scen-gm-instruction').val(r.gm_instruction||'');
				$('#nw-scen-victory').val(r.victory_condition||'');
				$('#nw-scen-fail').val(r.fail_conditions||'');
				$('#nw-scen-tags').val(commaTags(r.tags));
				$('#nw-scen-required-tags').val(commaTags(r.required_tags));
				$('#nw-scen-success-tags').val(commaTags(r.success_tags));
				$('#nw-scen-failure-tags').val(commaTags(r.failure_tags));
				$('#nw-scen-min-entropy').val(r.min_entropy!==null?r.min_entropy:'');
				$('#nw-scen-max-entropy').val(r.max_entropy!==null?r.max_entropy:'');
				$('#nw-scen-reward-credits').val(r.reward_credits!==null?r.reward_credits:'');
				$('#nw-scen-reward-items').val(r.reward_items?JSON.stringify(r.reward_items):'');
				$('#nw-scen-ktech').val(r.kingdom_tech!==null?r.kingdom_tech:'');
				$('#nw-scen-kmagic').val(r.kingdom_magic!==null?r.kingdom_magic:'');
				$('#nw-scen-kwealth').val(r.kingdom_wealth!==null?r.kingdom_wealth:'');
				$('#nw-scen-archetype').val(r.required_archetype_id!==null?r.required_archetype_id:'');
				$('#nw-scen-giver-npc').val(r.giver_npc_tag?JSON.stringify(r.giver_npc_tag):'');
				$('#nw-scen-img').val(r.img_url||'');
				$('#nw-scen-area-id').val(r.area_id||'');
				$('#nw-scen-is-active').prop('checked', !!r.is_active);
				$('#nw-scen-is-boss').prop('checked', !!r.is_boss);
				$('#nw-scen-is-key-arc').prop('checked', !!r.is_key_arc);
				$('#nw-scen-is-repeatable').prop('checked', !!r.is_repeatable);
			}

			function formToData(){
				const tagsToJson = id => JSON.stringify(tagArray($('#'+id).val()));
				return {
					id:                   $('#nw-scen-id').val(),
					name:                 $('#nw-scen-name').val(),
					type:                 $('#nw-scen-type').val(),
					category:             $('#nw-scen-category').val(),
					difficulty:           $('#nw-scen-difficulty').val(),
					goal:                 $('#nw-scen-goal').val(),
					gm_instruction:       $('#nw-scen-gm-instruction').val(),
					victory_condition:    $('#nw-scen-victory').val(),
					fail_conditions:      $('#nw-scen-fail').val(),
					tags:                 tagsToJson('nw-scen-tags'),
					required_tags:        tagsToJson('nw-scen-required-tags'),
					success_tags:         tagsToJson('nw-scen-success-tags'),
					failure_tags:         tagsToJson('nw-scen-failure-tags'),
					min_entropy:          $('#nw-scen-min-entropy').val(),
					max_entropy:          $('#nw-scen-max-entropy').val(),
					reward_credits:       $('#nw-scen-reward-credits').val(),
					reward_items:         $('#nw-scen-reward-items').val()||'null',
					kingdom_tech:         $('#nw-scen-ktech').val(),
					kingdom_magic:        $('#nw-scen-kmagic').val(),
					kingdom_wealth:       $('#nw-scen-kwealth').val(),
					required_archetype_id:$('#nw-scen-archetype').val(),
					giver_npc_tag:        $('#nw-scen-giver-npc').val()||'null',
					img_url:              $('#nw-scen-img').val(),
					area_id:              $('#nw-scen-area-id').val(),
					is_active:            $('#nw-scen-is-active').is(':checked')?1:0,
					is_boss:              $('#nw-scen-is-boss').is(':checked')?1:0,
					is_key_arc:           $('#nw-scen-is-key-arc').is(':checked')?1:0,
					is_repeatable:        $('#nw-scen-is-repeatable').is(':checked')?1:0,
				};
			}

			/* ── events ────────────────────────────── */
			$(document)

				/* Add new */
				.on('click','#nw-scen-add-btn',function(){
					closeModal();
					openModal('Add Scenario');
					$('#nw-scen-is-active').prop('checked', true);
				})

				/* Close modal */
				.on('click','#nw-scen-modal-close, #nw-scen-cancel-btn',closeModal)
				.on('click','#nw-scen-modal',function(e){
					if($(e.target).is('#nw-scen-modal')) closeModal();
				})
				.on('keydown',function(e){ if(e.key==='Escape') closeModal(); })

				/* Pagination */
				.on('click','.nw-page-btn',function(){ loadList($(this).data('page')); })

				/* Filter */
				.on('click','#nw-scen-search-btn',function(){ loadList(1); })
				.on('keypress','#nw-scen-search',function(e){ if(e.which===13) loadList(1); })

				/* Edit */
				.on('click','.nw-edit-btn',function(){
					const id = $(this).data('id');
					post('nw_scenario_get',{id}).done(function(res){
						if(!res.success){ alert('Could not load scenario.'); return; }
						populateForm(res.data);
						openModal('Edit Scenario');
					});
				})

				/* Toggle active */
				.on('click','.nw-toggle-btn',function(){
					const btn = $(this);
					const id  = btn.data('id');
					const val = btn.data('val');
					btn.prop('disabled',true).text('…');
					post('nw_scenario_toggle',{id,value:val}).done(function(res){
						if(!res.success){ alert('Toggle failed.'); btn.prop('disabled',false); return; }
						loadList(currentPage);
					});
				})

				/* Delete */
				.on('click','.nw-delete-btn',function(){
					if(!confirm('Delete this scenario? This cannot be undone.')) return;
					const id = $(this).data('id');
					post('nw_scenario_delete',{id}).done(function(res){
						if(!res.success){ alert('Delete failed.'); return; }
						loadList(currentPage);
					});
				})

				/* Save form */
				.on('submit','#nw-scen-form',function(e){
					e.preventDefault();
					$('#nw-scen-save-btn').prop('disabled',true).text('Saving…');
					$('#nw-scen-save-error').text('');
					post('nw_scenario_save', formToData())
						.done(function(res){
							if(!res.success){
								$('#nw-scen-save-error').text(res.data||'Save failed.');
								$('#nw-scen-save-btn').prop('disabled',false).text('Save Scenario');
								return;
							}
							closeModal();
							loadList(currentPage);
						})
						.fail(function(){
							$('#nw-scen-save-error').text('Request failed.');
							$('#nw-scen-save-btn').prop('disabled',false).text('Save Scenario');
						});
				});

			/* ── init ──────────────────────────────── */
			loadList(1);

		})(jQuery);
		</script>
		<?php
	}
}

new NeoWeaver_Scenarios_Admin();
