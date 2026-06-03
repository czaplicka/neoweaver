<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWSkillsAdmin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		add_action( 'wp_ajax_nw_skills_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_skills_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_skills_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_skills_duplicate', [ $this, 'ajax_duplicate' ] );
	}

	public function register_menu() {
		add_submenu_page(
			'neoweaver',
			'Skills',
			'<span data-lucide-menu="pocket-knife"></span>Skills',
			'manage_options',
			'nw-skills',
			[ $this, 'render_page' ]
		);
	}

public function enqueue( string $hook ): void {
	if ( ! str_contains( $hook, 'nw-skills' ) ) {
		return;
	}

wp_enqueue_style(
    'nw-admin-core',
    NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',
    [],
    NW_VERSION
);

wp_enqueue_style(
    'nw-skills-css',
    NW_PLUGIN_URL . 'assets/css/admin/skills.css',
    [ 'nw-admin-core' ],
    NW_VERSION
);

	wp_enqueue_script( 'nw-lucide' );

	wp_enqueue_script(
		'nw-skills-js',
		NW_PLUGIN_URL . 'assets/js/admin/skills.js',
		[ 'jquery', 'nw-lucide' ],
		NW_VERSION,
		true
	);

	wp_localize_script(
		'nw-skills-js',
		'NWSkills',
		[
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nwskillsnonce' ),
		]
	);
}

	public function render_page() {
		$categories = [ 'Physical', 'Social', 'Mental', 'Exploration' ];
		?>
		<div class="nw-admin-wrap" style="font-family:'Chakra Petch',monospace">
			<div class="nw-admin-header">
				<div class="nw-admin-header-left">
					<h1><i data-lucide="zap" style="width:20px;height:20px;vertical-align:middle;margin-right:8px"></i> Skills</h1>
					<p class="nw-admin-subtitle">Character skills — categories, linked attributes, card effects.</p>
				</div>
				<div class="nw-admin-header-right">
					<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
						<i data-lucide="refresh-cw" style="width:13px;height:13px"></i> Refresh
					</button>
					<button id="nw-add-btn" class="nw-btn nw-btn-primary nw-btn-sm">
						<i data-lucide="plus" style="width:13px;height:13px"></i> Add Skill
					</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none"></div>

			<div class="nw-stats-bar">
				<div class="nw-stat-item"><span id="nw-total">0</span><small>total</small></div>
				<div class="nw-stat-item"><span id="nw-active">0</span><small>active</small></div>
				<div class="nw-stat-item nw-stat-cat" id="nw-stat-physical">0 <small>Physical</small></div>
				<div class="nw-stat-item nw-stat-cat" id="nw-stat-social">0 <small>Social</small></div>
				<div class="nw-stat-item nw-stat-cat" id="nw-stat-mental">0 <small>Mental</small></div>
				<div class="nw-stat-item nw-stat-cat" id="nw-stat-exploration">0 <small>Exploration</small></div>
			</div>

			<div class="nw-table-card">
				<div class="nw-table-toolbar">
					<input id="nw-search" class="nw-input nw-input-sm" type="search" placeholder="Search skills…">
					<select id="nw-filter-category" class="nw-input nw-input-sm nw-select-sm">
						<option value="">All categories</option>
						<?php foreach ( $categories as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="nw-filter-active" class="nw-input nw-input-sm nw-select-sm">
						<option value="">All status</option>
						<option value="1">Active</option>
						<option value="0">Inactive</option>
					</select>
					<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none">
						<i data-lucide="x" style="width:11px;height:11px"></i> Clear
					</button>
				</div>

				<div class="nw-table-wrap">
					<table class="nw-table">
						<thead>
							<tr>
								<th style="width:40px"></th>
								<th>Name</th>
								<th>Category</th>
								<th>Application</th>
								<th>Card Effect</th>
								<th>Tags</th>
								<th style="text-align:center;width:60px">Status</th>
								<th style="width:80px">Actions</th>
							</tr>
						</thead>
						<tbody id="nw-skills-tbody">
							<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading…</td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
				<div class="nw-modal nw-modal-skills">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">New Skill</h2>
						<button id="nw-modal-close" class="nw-btn nw-btn-ghost nw-btn-sm" type="button">
							<i data-lucide="x" style="width:14px;height:14px"></i>
						</button>
					</div>

					<div class="nw-modal-body">
						<form id="nw-skill-form" autocomplete="off">
							<input type="hidden" id="nw-field-id">

							<div class="nw-form-2col">
								<div class="nw-form-row">
									<label class="nw-label">Name <span class="nw-required">*</span></label>
									<input id="nw-field-name" class="nw-input" type="text" placeholder="Skill name" required>
								</div>
								<div class="nw-form-row">
									<label class="nw-label">Category</label>
									<select id="nw-field-category" class="nw-input">
										<option value="">— none —</option>
										<?php foreach ( $categories as $c ) : ?>
											<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="nw-form-row">
								<label class="nw-label">Description</label>
								<textarea id="nw-field-description" class="nw-input nw-textarea" rows="2" placeholder="What this skill represents…"></textarea>
							</div>

							<div class="nw-form-row">
								<label class="nw-label">Application</label>
								<textarea id="nw-field-application" class="nw-input nw-textarea" rows="2" placeholder="How the skill is used in-game…"></textarea>
							</div>

							<div class="nw-form-row">
								<label class="nw-label">Card Effect</label>
								<textarea id="nw-field-card-effect" class="nw-input nw-textarea" rows="2" placeholder="Effect when drawn from the deck…"></textarea>
							</div>

							<div class="nw-form-2col">
								<div class="nw-form-row">
									<label class="nw-label">Tags</label>
									<div id="nw-tags-wrap" class="nw-tag-input-wrap">
										<div id="nw-tags-list" class="nw-tag-chips"></div>
										<input id="nw-tag-input" class="nw-tag-text-input" type="text" placeholder="Add tag + Enter">
									</div>
									<input type="hidden" id="nw-field-tags" value="[]">
								</div>

								<div class="nw-form-row">
									<label class="nw-label">Linked Attributes</label>
									<div id="nw-attrs-wrap" class="nw-tag-input-wrap">
										<div id="nw-attrs-list" class="nw-tag-chips"></div>
										<input id="nw-attr-input" class="nw-tag-text-input" type="text" placeholder="Add attribute + Enter">
									</div>
									<input type="hidden" id="nw-field-linked-attributes" value="[]">
								</div>
							</div>

							<div class="nw-form-row">
								<label class="nw-label">Image URL</label>
								<input id="nw-field-img-url" class="nw-input" type="url" placeholder="https://…">
								<div id="nw-img-preview-wrap" style="display:none;margin-top:8px">
									<img id="nw-img-preview" src="" alt="" style="max-height:80px;border-radius:6px;object-fit:cover" loading="lazy">
								</div>
							</div>

							<div class="nw-form-row">
								<label class="nw-toggle-row">
									<input id="nw-field-is-active" type="checkbox" checked>
									<span>Active</span>
								</label>
							</div>
						</form>
					</div>

					<div class="nw-modal-footer">
						<button id="nw-delete-btn" class="nw-btn nw-btn-danger nw-btn-sm" type="button" style="display:none">
							<i data-lucide="trash-2" style="width:13px;height:13px"></i> Delete
						</button>
						<div class="nw-modal-footer-right">
							<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost nw-btn-sm" type="button">Cancel</button>
							<button id="nw-save-btn" class="nw-btn nw-btn-primary nw-btn-sm" type="button">
								<i data-lucide="save" style="width:13px;height:13px"></i>
								<span id="nw-save-label">Create Skill</span>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function current_user_can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No permission.' );
		}
	}

	private function check_nonce() {
		$this->current_user_can_manage();

		if ( ! check_ajax_referer( 'nwskillsnonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
	}

	private function parse_json_field( $raw, $fallback = [] ) {
		if ( empty( $raw ) || 'null' === $raw ) {
			return $fallback;
		}

		$decoded = json_decode( stripslashes( $raw ), true );

		return is_array( $decoded ) ? $decoded : $fallback;
	}

	public function ajax_load() {
    $this->check_nonce();

    $res = tw_supabase_get_admin( 'cyber_skills?order=name.asc' );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    // doklejamy pełny URL do img_url przed wysłaniem do JS
    $res = array_map( function( $row ) {
        if ( ! empty( $row['img_url'] ) ) {
            $row['img_url'] = nw_uploads_url( $row['img_url'] );
        }
        return $row;
    }, $res );

    wp_send_json_success( $res );
}
	public function ajax_save() {
		$this->check_nonce();

		$id   = sanitize_text_field( $_POST['id'] ?? '' );
		$name = sanitize_text_field( $_POST['name'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
		}

		$cat          = sanitize_text_field( $_POST['category'] ?? '' );
		$allowed_cats = [ 'Physical', 'Social', 'Mental', 'Exploration', '' ];

		if ( ! in_array( $cat, $allowed_cats, true ) ) {
			wp_send_json_error( 'Invalid category.' );
		}

		$payload = [
			'name'              => $name,
			'description'       => sanitize_textarea_field( $_POST['description'] ?? '' ) ?: null,
			'category'          => $cat ?: null,
			'application'       => sanitize_textarea_field( $_POST['application'] ?? '' ) ?: null,
			'card_effect'       => sanitize_textarea_field( $_POST['card_effect'] ?? '' ) ?: null,
			'img_url' => esc_url_raw( $_POST['img_url'] ?? '' ) ?: null,
			'tags'              => $this->parse_json_field( $_POST['tags'] ?? '' ),
			'linked_attributes' => $this->parse_json_field( $_POST['linked_attributes'] ?? '' ),
			'is_active'         => ! empty( $_POST['is_active'] ),
		];

		if ( $id ) {
			$res = tw_supabase_request(
				'PATCH',
				'cyber_skills?id=eq.' . rawurlencode( $id ),
				$payload
			);
		} else {
			$res = tw_supabase_request(
				'POST',
				'cyber_skills',
				$payload
			);
		}

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}

		$reload = tw_supabase_get_admin(
			'cyber_skills' . ( $id ? '?id=eq.' . rawurlencode( $id ) : '?name=eq.' . rawurlencode( $name ) ) . '&order=name.asc'
		);

		if ( is_wp_error( $reload ) ) {
			wp_send_json_success( [] );
		}

		wp_send_json_success( $reload );
	}

	public function ajax_delete() {
		$this->check_nonce();

		$id = sanitize_text_field( $_POST['id'] ?? '' );

		if ( ! $id ) {
			wp_send_json_error( 'ID required.' );
		}

		$res = tw_supabase_request(
			'DELETE',
			'cyber_skills?id=eq.' . rawurlencode( $id )
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}

		wp_send_json_success( 'Deleted.' );
	}

	public function ajax_duplicate() {
		$this->check_nonce();

		$id = sanitize_text_field( $_POST['id'] ?? '' );

		if ( ! $id ) {
			wp_send_json_error( 'ID required.' );
		}

		$res = tw_supabase_get_admin( 'cyber_skills?id=eq.' . rawurlencode( $id ) );

		if ( is_wp_error( $res ) || empty( $res[0] ) ) {
			wp_send_json_error( 'Skill not found.' );
		}

		$row = $res[0];

		unset( $row['id'], $row['created_at'] );

		$row['name'] = $row['name'] . ' (copy)';

		$res2 = tw_supabase_request(
			'POST',
			'cyber_skills',
			$row
		);

		if ( is_wp_error( $res2 ) ) {
			wp_send_json_error( $res2->get_error_message() );
		}

		$reload = tw_supabase_get_admin(
			'cyber_skills?name=eq.' . rawurlencode( $row['name'] )
		);

		if ( is_wp_error( $reload ) ) {
			wp_send_json_success( [] );
		}

		wp_send_json_success( $reload );
	}
}
