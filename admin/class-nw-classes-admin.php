<?php
/**
 * NeoWeaver Admin Panel — Classes (cyberclasses)
 * Schema: id, name, description, tags (jsonb), starting_gold, gm_instructions,
 *         ai_personality_modifier, mechanics, attribute_bonuses (jsonb),
 *         vulnerability, icon_slug, img_url, is_active, skill_limit, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Classes_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-classes';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_classes_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_classes_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_classes_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Classes',
            '⚔️ Classes',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    /* ---------------------------------------------------------------- */
    /*  ASSETS                                                            */
    /* ---------------------------------------------------------------- */

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
        
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-classes-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Classes</span>
                </h1>
                <div class="nw-header-actions">
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="🔍 Search name…">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Class</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill">Active: <strong id="nw-active">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-img"></th>
                        <th>Name</th>
                        <th>Tags</th>
                        <th>Gold</th>
                        <th>Skill Limit</th>
                        <th>Vulnerability</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-classes-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading classes…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Class</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-class-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Shadowblade">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="2" placeholder="Short class description visible to players…"></textarea>
                                </div>

                                <div class="nw-field">
                                    <label>Icon Slug</label>
                                    <input type="text" id="nw-field-icon_slug" name="icon_slug" placeholder="e.g. shadowblade">
                                </div>

                                <div class="nw-field">
                                    <label>Starting Gold</label>
                                    <input type="number" id="nw-field-starting_gold" name="starting_gold" min="0" placeholder="100">
                                </div>

                                <div class="nw-field">
                                    <label>Skill Limit</label>
                                    <input type="number" id="nw-field-skill_limit" name="skill_limit" min="0" placeholder="3">
                                </div>

                                <div class="nw-field">
                                    <label>Vulnerability</label>
                                    <input type="text" id="nw-field-vulnerability" name="vulnerability" placeholder="e.g. Fire, Poison">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. Stealth, Melee, Shadow">
                                </div>

                                <div class="nw-field">
                                    <label>Active</label>
                                    <select id="nw-field-is_active" name="is_active">
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>

                            </div>

                            <div class="nw-section-label">Mechanics &amp; Lore</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Mechanics <span class="nw-hint">(shown to players)</span></label>
                                    <textarea id="nw-field-mechanics" name="mechanics" rows="3" placeholder="Class mechanics description…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Instructions <span class="nw-hint">(internal / AI context)</span></label>
                                    <textarea id="nw-field-gm_instructions" name="gm_instructions" rows="3" placeholder="GM/AI hints…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>AI Personality Modifier</label>
                                    <textarea id="nw-field-ai_personality_modifier" name="ai_personality_modifier" rows="2" placeholder="How AI narrates this class…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Attribute Bonuses <span class="nw-hint">(JSON object, e.g. {"Reflex":2,"Mind":1})</span></label>
                                    <input type="text" id="nw-field-attribute_bonuses" name="attribute_bonuses" placeholder='{"Reflex":2,"Mind":1}'>
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

                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Class</span></button>
                    </div>
                </div>
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_classes' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                          */
    /* ---------------------------------------------------------------- */

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
        return [
            'code' => wp_remote_retrieve_response_code( $res ),
            'data' => json_decode( wp_remote_retrieve_body( $res ), true ),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $res = $this->supa( 'GET',
            'cyber_classes?select=id,name,description,tags,starting_gold,gm_instructions,mechanics,icon_slug,ai_personality_modifier,attribute_bonuses,vulnerability,img_url,is_active,skill_limit,created_at&order=name.asc'
        );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['nw_class'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );

        // tags: comma-separated → JSON array
        $tags = array_values( array_filter( array_map( 'trim',
            explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) )
        ) ) );

        // attribute_bonuses: JSON string → decoded object (jsonb column)
        $ab_raw = trim( sanitize_text_field( $raw['attribute_bonuses'] ?? '' ) );
        $attribute_bonuses = null;
        if ( $ab_raw ) {
            $decoded = json_decode( $ab_raw, true );
            $attribute_bonuses = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : null;
        }

        $payload = [
            'name'                    => sanitize_text_field(     $raw['name']                    ?? '' ),
            'description'             => sanitize_textarea_field( $raw['description']             ?? '' ) ?: null,
            'icon_slug'               => sanitize_text_field(     $raw['icon_slug']               ?? '' ) ?: null,
            'vulnerability'           => sanitize_text_field(     $raw['vulnerability']           ?? '' ) ?: null,
            'attribute_bonuses'       => $attribute_bonuses,
            'mechanics'               => sanitize_textarea_field( $raw['mechanics']               ?? '' ) ?: null,
            'gm_instructions'         => sanitize_textarea_field( $raw['gm_instructions']         ?? '' ) ?: null,
            'ai_personality_modifier' => sanitize_textarea_field( $raw['ai_personality_modifier'] ?? '' ) ?: null,
            'img_url'                 => esc_url_raw(             $raw['img_url']                 ?? '' ) ?: null,
            'starting_gold'           => isset( $raw['starting_gold'] ) && $raw['starting_gold'] !== '' ? (int) $raw['starting_gold'] : 100,
            'skill_limit'             => isset( $raw['skill_limit']   ) && $raw['skill_limit']   !== '' ? (int) $raw['skill_limit']   : 3,
            'is_active'               => ( ( $raw['is_active'] ?? '1' ) === '1' ),
            'tags'                    => $tags,
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_classes?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_classes', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['class_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_classes?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}
new NeoWeaver_Classes_Admin();
