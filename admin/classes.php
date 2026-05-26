<?php
/**
 * NeoWeaver — Field Agents (Classes) Admin
 * Table: cyber_classes (uuid PK, name, description, tags jsonb,
 *        starting_gold, gm_instructions, ai_personality_modifier,
 *        mechanics, attribute_bonuses jsonb, vulnerability,
 *        icon_slug, img_url, is_active, created_at, skill_limit)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NW_Classes_Admin {

    private string $page_slug    = 'nw-classes';
    private string $menu_parent  = 'neoweaver';
    private string $table        = 'cyber_classes';
    private string $nonce_action = 'nw_classes_nonce';

    public function __construct() {
        add_action( 'admin_menu',           [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nw_classes_load',      [ $this, 'ajax_load' ] );
        add_action( 'wp_ajax_nw_classes_save',      [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_nw_classes_delete',    [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_nw_classes_duplicate', [ $this, 'ajax_duplicate' ] );
    }

    /* ---------------------------------------------------------------- */
    /* MENU                                                               */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->menu_parent,
            __( 'Field Agents', 'neoweaver' ),
            __( 'Field Agents', 'neoweaver' ),
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    /* ---------------------------------------------------------------- */
    /* ASSETS                                                             */
    /* ---------------------------------------------------------------- */

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
            NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',
            [ 'chakra-petch' ],
            NW_VERSION
        );

        wp_enqueue_style(
            'nw-classes-style',
            NW_PLUGIN_URL . 'assets/css/admin/classes.css',
            [ 'chakra-petch', 'nw-admin-core' ],
            NW_VERSION
        );

        wp_enqueue_script(
            'lucide',
            'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
            [],
            '0.468.0',
            true
        );

        wp_enqueue_script(
            'nw-classes-script',
            NW_PLUGIN_URL . 'assets/js/admin/classes.js',
            [ 'jquery', 'lucide' ],
            NW_VERSION,
            true
        );

        $uploads = wp_upload_dir();
        wp_localize_script( 'nw-classes-script', 'NWClasses', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( $this->nonce_action ),
            'uploads_url' => isset( $uploads['baseurl'] )
                ? untrailingslashit( $uploads['baseurl'] )
                : '',
        ] );
    }

    /* ---------------------------------------------------------------- */
    /* SERVICE KEY                                                        */
    /* ---------------------------------------------------------------- */

    private function sk(): array {
        if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
            return [];
        }
        return [
            'apikey'        => TW_SUPABASE_SERVICE_KEY,
            'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
        ];
    }

    /* ---------------------------------------------------------------- */
    /* SUPABASE WRAPPER                                                   */
    /* ---------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
        $method = strtoupper( $method );

        if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
            [ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
            $query = [];
            if ( $qs ) {
                parse_str( $qs, $query );
            }
            $data = tw_supabase_get( $table, $query, [ 'headers' => $extra_headers ] );
            if ( ! is_array( $data ) ) {
                return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'tw_supabase_get returned non-array' ];
            }
            if ( isset( $data['code'], $data['message'] ) ) {
                return [ 'ok' => false, 'code' => (int) $data['code'], 'data' => null, 'error' => $data['message'] ];
            }
            return [ 'ok' => true, 'code' => 200, 'data' => $data, 'error' => null ];
        }

        if ( function_exists( 'tw_supabase_request' ) ) {
            [ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
            $query = [];
            if ( $qs ) {
                parse_str( $qs, $query );
            }
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

        return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'Supabase helper functions not available.' ];
    }

    /* ---------------------------------------------------------------- */
    /* CACHE                                                              */
    /* ---------------------------------------------------------------- */

    private function get_cache_key( string $suffix ): string {
        return 'nw_' . md5( $suffix );
    }

    private function bust_cache(): void {
        delete_transient( $this->get_cache_key( $this->table . '_all' ) );
    }

    private function cached_get_all(): array {
        $cache_key = $this->get_cache_key( $this->table . '_all' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
        $res = $this->supa( 'GET', $this->table . '?select=*&order=created_at.desc', [], $this->sk() );
        if ( ! $res['ok'] ) {
            return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
        }
        $rows = is_array( $res['data'] ) ? $res['data'] : [];
        set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );
        return $rows;
    }

    /* ---------------------------------------------------------------- */
    /* VALIDATE & PARSE                                                   */
    /* ---------------------------------------------------------------- */

    private function is_uuid( string $value ): bool {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $value
        );
    }

    private function parse_tags( string $raw ): array {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return [];
        }
        $tags = array_map(
            static fn( $t ) => sanitize_text_field( trim( $t ) ),
            explode( ',', $raw )
        );
        return array_values( array_filter( array_unique( $tags ), static fn( $t ) => '' !== $t ) );
    }

    private function parse_attribute_bonuses( string $raw ): array {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return [];
        }
        return $decoded;
    }

    private function bool_from_post( string $key, bool $default = false ): bool {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return (bool) intval( wp_unslash( $_POST[ $key ] ) );
    }

    /* ---------------------------------------------------------------- */
    /* AJAX — LOAD                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_load(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $rows = $this->cached_get_all();
        if ( isset( $rows['error'] ) ) {
            wp_send_json_error( $rows['error'] );
            return;
        }
        wp_send_json_success( $rows );
    }

    /* ---------------------------------------------------------------- */
    /* AJAX — SAVE (create + update)                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id                      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
        $name                    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $description             = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $icon_slug               = sanitize_text_field( wp_unslash( $_POST['icon_slug'] ?? '' ) );
        $vulnerability           = sanitize_text_field( wp_unslash( $_POST['vulnerability'] ?? '' ) );
        $mechanics               = sanitize_textarea_field( wp_unslash( $_POST['mechanics'] ?? '' ) );
        $gm_instructions         = sanitize_textarea_field( wp_unslash( $_POST['gm_instructions'] ?? '' ) );
        $ai_personality_modifier = sanitize_textarea_field( wp_unslash( $_POST['ai_personality_modifier'] ?? '' ) );
        $img_url                 = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
        $starting_gold           = max( 0, intval( wp_unslash( $_POST['starting_gold'] ?? 100 ) ) );
        $skill_limit             = max( 0, intval( wp_unslash( $_POST['skill_limit'] ?? 3 ) ) );
        $is_active               = $this->bool_from_post( 'is_active', true );
        $tags_raw                = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
        $attribute_bonuses_raw   = wp_unslash( $_POST['attribute_bonuses'] ?? '' );

        if ( ! $name ) {
            wp_send_json_error( 'Name is required.' );
            return;
        }

        if ( $id && ! $this->is_uuid( $id ) ) {
            wp_send_json_error( 'Invalid class ID.' );
            return;
        }

        if ( $attribute_bonuses_raw ) {
            $parsed = json_decode( $attribute_bonuses_raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $parsed ) || array_is_list( $parsed ) ) {
                wp_send_json_error( 'Attribute Bonuses must be a valid JSON object.' );
                return;
            }
        }

        $payload = [
            'name'                    => $name,
            'description'             => '' !== $description ? $description : null,
            'icon_slug'               => '' !== $icon_slug ? $icon_slug : null,
            'vulnerability'           => '' !== $vulnerability ? $vulnerability : null,
            'mechanics'               => '' !== $mechanics ? $mechanics : null,
            'gm_instructions'         => '' !== $gm_instructions ? $gm_instructions : null,
            'ai_personality_modifier' => '' !== $ai_personality_modifier ? $ai_personality_modifier : null,
            'img_url'                 => '' !== $img_url ? $img_url : null,
            'starting_gold'           => $starting_gold,
            'skill_limit'             => $skill_limit,
            'is_active'               => $is_active,
            'tags'                    => $this->parse_tags( $tags_raw ),
            'attribute_bonuses'       => $this->parse_attribute_bonuses( (string) $attribute_bonuses_raw ),
        ];

        if ( $id ) {
            $res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
        } else {
            $res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
        }

        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Save failed.' );
            return;
        }

        $this->bust_cache();
        $item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
        wp_send_json_success( $item );
    }

    /* ---------------------------------------------------------------- */
    /* AJAX — DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID.' );
            return;
        }
        if ( ! $this->is_uuid( $id ) ) {
            wp_send_json_error( 'Invalid class ID.' );
            return;
        }

        $res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Delete failed.' );
            return;
        }

        $this->bust_cache();
        wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /* AJAX — DUPLICATE                                                   */
    /* ---------------------------------------------------------------- */

    public function ajax_duplicate(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
        if ( ! $id || ! $this->is_uuid( $id ) ) {
            wp_send_json_error( 'Invalid class ID.' );
            return;
        }

        /* Fetch original */
        $res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
        if ( ! $res['ok'] || empty( $res['data'] ) ) {
            wp_send_json_error( 'Original class not found.' );
            return;
        }

        $original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];
        if ( empty( $original ) ) {
            wp_send_json_error( 'Failed to read original class.' );
            return;
        }

        /* Build duplicate payload — strip id + created_at */
        $payload = $original;
        unset( $payload['id'], $payload['created_at'] );
        $payload['name']      = $original['name'] . ' (Copy)';
        $payload['is_active'] = false;

        $res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Duplicate failed.' );
            return;
        }

        $this->bust_cache();
        $item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
        wp_send_json_success( $item );
    }

    /* ---------------------------------------------------------------- */
    /* RENDER                                                             */
    /* ---------------------------------------------------------------- */

    public function render_page(): void {
        ?>
<div class="nw-panel">

    <div class="nw-panel-header">
        <div>
            <h1 class="nw-panel-title">⚔ Field Agents</h1>
            <p class="nw-panel-subtitle">Manage playable character classes for NeoWeaver.</p>
        </div>
        <div class="nw-header-actions">
            <button id="nw-refresh-btn" class="nw-btn nw-btn-ghost" title="Refresh">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;vertical-align:middle;"></i>
            </button>
            <button id="nw-add-btn" class="nw-btn nw-btn-primary">+ New Class</button>
        </div>
    </div>

    <div id="nw-notice" class="nw-notice" style="display:none;"></div>

    <!-- Stats -->
    <div class="nw-stats-bar">
        <span class="nw-stat-pill">Total: <strong id="nw-total">…</strong></span>
        <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">…</strong></span>
        <span class="nw-stat-pill">Inactive: <strong id="nw-inactive">…</strong></span>
    </div>

    <!-- Filters -->
    <div class="nw-filters-bar">
        <input id="nw-search" type="text" class="nw-search-input" placeholder="Search name, tag, vulnerability…">
        <select id="nw-filter-active" class="nw-select-filter">
            <option value="">All status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        <button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none;">✕ Clear</button>
    </div>

    <!-- Table -->
    <div class="nw-table-wrap">
        <table class="nw-table">
            <thead>
                <tr>
                    <th style="width:52px;">Img</th>
                    <th>Name</th>
                    <th>Tags</th>
                    <th style="width:70px;">Gold</th>
                    <th style="width:60px;">Skills</th>
                    <th>Vulnerability</th>
                    <th style="width:72px;">Active</th>
                    <th style="width:150px;">Actions</th>
                </tr>
            </thead>
            <tbody id="nw-classes-tbody">
                <tr class="nw-loading-row"><td colspan="8">
                    <span class="nw-spinner"></span> Loading field agents…
                </td></tr>
            </tbody>
        </table>
    </div>

</div><!-- .nw-panel -->

<!-- ================================================================ -->
<!-- MODAL                                                              -->
<!-- ================================================================ -->
<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none;">
    <div class="nw-modal">
        <div class="nw-modal-header">
            <h2 id="nw-modal-title">New Class</h2>
            <button id="nw-modal-close" class="nw-modal-close" aria-label="Close">✕</button>
        </div>
        <div class="nw-modal-body">
            <form id="nw-class-form" autocomplete="off">
                <input type="hidden" id="nw-field-id">

                <!-- BASIC -->
                <div class="nw-section-label">Basic Info</div>
                <div class="nw-form-grid">
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-name">Name <span class="nw-req">*</span></label>
                        <input type="text" id="nw-field-name" maxlength="120">
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-description">Description</label>
                        <textarea id="nw-field-description" rows="3"></textarea>
                    </div>
                </div>

                <!-- STATS -->
                <div class="nw-section-label">Stats</div>
                <div class="nw-form-grid">
                    <div class="nw-field">
                        <label for="nw-field-starting_gold">Starting Gold</label>
                        <input type="number" id="nw-field-starting_gold" min="0" value="100">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-skill_limit">Skill Limit</label>
                        <input type="number" id="nw-field-skill_limit" min="0" value="3">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-vulnerability">Vulnerability</label>
                        <input type="text" id="nw-field-vulnerability" maxlength="120">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-icon_slug">Icon Slug</label>
                        <input type="text" id="nw-field-icon_slug" maxlength="80">
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-tags">Tags <span class="nw-hint">(comma-separated)</span></label>
                        <input type="text" id="nw-field-tags" placeholder="hacker, stealth, tech">
                    </div>
                </div>

                <!-- AI / GM -->
                <div class="nw-section-label">AI &amp; GM</div>
                <div class="nw-form-grid">
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-gm_instructions">GM Instructions</label>
                        <textarea id="nw-field-gm_instructions" rows="3"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-ai_personality_modifier">AI Personality Modifier</label>
                        <textarea id="nw-field-ai_personality_modifier" rows="3"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-mechanics">Mechanics</label>
                        <textarea id="nw-field-mechanics" rows="3"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-attribute_bonuses">Attribute Bonuses
                            <span class="nw-hint">(JSON object: {"str":2,"dex":1})</span>
                        </label>
                        <textarea id="nw-field-attribute_bonuses" rows="3" placeholder='{"str": 2, "dex": 1}'></textarea>
                    </div>
                </div>

                <!-- IMAGE -->
                <div class="nw-section-label">Image</div>
                <div class="nw-form-grid">
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-img_url">Image URL</label>
                        <input type="url" id="nw-field-img_url" placeholder="https://…">
                    </div>
                    <div id="nw-img-preview-wrap" class="nw-field-full" style="display:none;">
                        <img id="nw-img-preview" src="" alt="Preview" style="max-height:120px;border-radius:6px;border:1px solid #2a2a2a;">
                    </div>
                </div>

                <!-- STATUS -->
                <div class="nw-section-label">Status</div>
                <div class="nw-toggle-row">
                    <label class="nw-toggle-label">
                        <span class="nw-toggle">
                            <input type="checkbox" id="nw-field-is_active" checked>
                            <span class="nw-toggle-slider"></span>
                        </span>
                        Active
                    </label>
                </div>

            </form>
        </div>
        <div class="nw-modal-footer">
            <button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto;">Delete</button>
            <button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
            <button id="nw-save-btn" class="nw-btn nw-btn-primary">
                <span id="nw-save-label">Create Class</span>
            </button>
        </div>
    </div>
</div>

        <?php
    }
}

new NW_Classes_Admin();
