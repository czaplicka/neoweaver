<?php
/**
 * NeoWeaver Admin Panel — Skills (cyber_skills)
 *
 * Columns: id, name, description, category, application, card_effect,
 *          img_url, tags, linked_attributes, is_active, created_at
 *
 * Categories: Physical | Social | Mental | Exploration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Skills_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-skills';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_skills_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_skills_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_skills_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_skills_delete',  [ $this, 'ajax_delete'  ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Skills',
            '🧠 Skills',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;
        $base = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/';
        $ver  = '1.0.0';
        wp_enqueue_style( 'chakra-petch', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_enqueue_style( 'nw-skills-admin', $base . 'css/skills-admin.css', [ 'chakra-petch' ], $ver );
        wp_enqueue_script( 'nw-skills-admin', $base . 'js/skills-admin.js', [ 'jquery' ], $ver, true );
    }

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-skills-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Skills</span>
                </h1>
                <div class="nw-header-actions">
                    <div class="nw-filter-bar">
                        <select id="nw-filter-category" class="nw-select-filter">
                            <option value="">All categories</option>
                            <?php foreach ( $this->categories() as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Skill</button>
                </div>
            </div>
            <div id="nw-notice" class="nw-notice" style="display:none;"></div>
            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
            </div>
            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-img"></th>
                        <th>Name</th><th>Category</th><th>Application</th>
                        <th>Tags</th><th>Card Effect</th><th>Active</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-skills-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading skills…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Skill</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-skill-form">
                            <input type="hidden" id="nw-field-id" name="id">
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Neural Hacking">
                                </div>
                                <div class="nw-field">
                                    <label>Category</label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( $this->categories() as $c ) : ?>
                                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Application</label>
                                    <input type="text" id="nw-field-application" name="application" placeholder="e.g. Combat, Stealth…">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Public skill description…"></textarea>
                                </div>
                            </div>
                            <div class="nw-section-label">Mechanics</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Card Effect <span class="nw-hint">(effect triggered when the skill card is drawn)</span></label>
                                    <textarea id="nw-field-card_effect" name="card_effect" rows="3" placeholder="e.g. +2 to next hacking roll, ignore 1 firewall…"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. cyber, passive, combat">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Linked Attributes <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-linked_attributes" name="linked_attributes" placeholder="e.g. intelligence, agility">
                                </div>
                            </div>
                            <div class="nw-section-label">Media</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-img_url" name="img_url" placeholder="https://…">
                                    <div id="nw-img-preview-wrap" style="display:none;margin-top:6px;">
                                        <img id="nw-img-preview" src="" alt="preview" style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>
                            </div>
                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Active (visible in game)</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active" checked>
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Skill</span></button>
                    </div>
                </div>
            </div>
            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_skills' ) ); ?>">
        </div>
    <?php }

    private function categories(): array {
        return [ 'Physical', 'Social', 'Mental', 'Exploration' ];
    }

    private function supa( string $method, string $endpoint, array $body = [], array $extra = [] ): array {
        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => array_merge( [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ], $extra ),
        ];
        if ( $body ) $args['body'] = wp_json_encode( $body );
        $res = wp_remote_request( $this->supabase_url . '/rest/v1/' . $endpoint, $args );
        if ( is_wp_error( $res ) ) return [ 'error' => $res->get_error_message() ];
        return [ 'code' => wp_remote_retrieve_response_code( $res ), 'data' => json_decode( wp_remote_retrieve_body( $res ), true ) ];
    }

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $category = sanitize_text_field( $_POST['filter_category'] ?? '' );
        $qs = 'cyber_skills?select=id,name,description,category,application,card_effect,img_url,tags,linked_attributes,is_active,created_at&order=name.asc';
        if ( $category ) $qs .= '&category=eq.' . rawurlencode( $category );
        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $raw  = $_POST['skill'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );
        $tags  = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) ) ) );
        $attrs = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['linked_attributes'] ?? '' ) ) ) ) );
        $payload = [
            'name'              => sanitize_text_field(     $raw['name']        ?? '' ),
            'description'       => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'category'          => sanitize_text_field(     $raw['category']    ?? '' ) ?: null,
            'application'       => sanitize_text_field(     $raw['application'] ?? '' ) ?: null,
            'card_effect'       => sanitize_textarea_field( $raw['card_effect'] ?? '' ) ?: null,
            'img_url'           => esc_url_raw(             $raw['img_url']     ?? '' ) ?: null,
            'tags'              => $tags,
            'linked_attributes' => $attrs,
            'is_active'         => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];
        $res = $id
            ? $this->supa( 'PATCH', 'cyber_skills?id=eq.' . rawurlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_skills', $payload );
        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id    = sanitize_text_field( $_POST['skill_id']  ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'PATCH', 'cyber_skills?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = sanitize_text_field( $_POST['skill_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'DELETE', 'cyber_skills?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_Skills_Admin();
