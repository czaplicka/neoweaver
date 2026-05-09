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

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Starting_Packages_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-starting-packages';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_sp_get_all',   [ $this, 'ajax_get_all'   ] );
        add_action( 'wp_ajax_nw_sp_get_items', [ $this, 'ajax_get_items' ] );
        add_action( 'wp_ajax_nw_sp_save',      [ $this, 'ajax_save'      ] );
        add_action( 'wp_ajax_nw_sp_toggle',    [ $this, 'ajax_toggle'    ] );
        add_action( 'wp_ajax_nw_sp_delete',    [ $this, 'ajax_delete'    ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

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

    /* ---------------------------------------------------------------- */
    /*  ASSETS                                                            */
    /* ---------------------------------------------------------------- */

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;

        $base = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/';
        $ver  = '1.0.0';

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [], null
        );
        wp_enqueue_style(
            'nw-starting-packages-admin',
            $base . 'css/starting-packages-admin.css',
            [ 'chakra-petch' ],
            $ver
        );
        wp_enqueue_script(
            'nw-starting-packages-admin',
            $base . 'js/starting-packages-admin.js',
            [ 'jquery' ],
            $ver,
            true
        );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-sp-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Starting Packages</span>
                </h1>
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
                    <thead><tr>
                        <th>Package Name</th>
                        <th>Base Armor</th>
                        <th>Slots</th>
                        <th>Compat. Tags</th>
                        <th>Class IDs</th>
                        <th>Player Selectable</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-sp-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading packages…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Package</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-sp-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Package Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-package_name" name="package_name" required placeholder="e.g. Street Runner Starter">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Brief description of this starting package…"></textarea>
                                </div>
                            </div>

                            <!-- Equipment Slots -->
                            <div class="nw-section-label">Equipment Slots <span class="nw-hint">(pick from cyber_items)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Head Slot</label>
                                    <select id="nw-field-head_item_id" name="head_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Torso Slot</label>
                                    <select id="nw-field-torso_item_id" name="torso_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Right Hand Slot</label>
                                    <select id="nw-field-hand_r_item_id" name="hand_r_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Left Hand Slot</label>
                                    <select id="nw-field-hand_l_item_id" name="hand_l_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Belt Slot</label>
                                    <select id="nw-field-belt_item_id" name="belt_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Base Armor <span class="nw-hint">(≥ 0)</span></label>
                                    <input type="number" id="nw-field-base_armor" name="base_armor" min="0" value="0">
                                </div>
                            </div>

                            <!-- JSON Pools -->
                            <div class="nw-section-label">Card Pools &amp; Lists <span class="nw-hint">(comma-separated → JSON array)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Items List <span class="nw-hint">(additional item IDs / names beyond slots)</span></label>
                                    <input type="text" id="nw-field-items_list" name="items_list" placeholder="item-uuid-1, item-uuid-2">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Attack Cards Pool</label>
                                    <input type="text" id="nw-field-attack_cards_pool" name="attack_cards_pool" placeholder="card-id-1, card-id-2">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Defense Cards Pool</label>
                                    <input type="text" id="nw-field-defense_cards_pool" name="defense_cards_pool" placeholder="card-id-1, card-id-2">
                                </div>
                            </div>

                            <!-- Compatibility -->
                            <div class="nw-section-label">Compatibility <span class="nw-hint">(comma-separated → JSON array)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Compatibility Tags</label>
                                    <input type="text" id="nw-field-compatibility_tags" name="compatibility_tags" placeholder="e.g. melee, urban, stealth">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Compatible Class IDs</label>
                                    <input type="text" id="nw-field-compatible_class_ids" name="compatible_class_ids" placeholder="class-uuid-1, class-uuid-2">
                                </div>
                            </div>

                            <!-- Visibility -->
                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Player Selectable (visible on character creation)</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_player_selectable" name="is_player_selectable">
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                        </form>
                    </div><!-- .nw-modal-body -->
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Package</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_sp' ) ); ?>">
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
        return [ 'code' => wp_remote_retrieve_response_code( $res ), 'data' => json_decode( wp_remote_retrieve_body( $res ), true ) ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL PACKAGES                                            */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $qs = 'cyber_starting_packages?select=id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable,head_item_id,torso_item_id,hand_r_item_id,hand_l_item_id,belt_item_id,compatible_class_ids,created_at&order=package_name.asc';
        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ITEMS (for slot selects)                                */
    /* ---------------------------------------------------------------- */

    public function ajax_get_items(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $res = $this->supa( 'GET', 'cyber_items?select=id,name,slot,type&order=name.asc&is_active=eq.true' );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['pkg'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        $csv_to_arr = function( string $val ): array {
            return array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $val ) ) ) ) );
        };
        $uuid_or_null = function( string $val ): ?string {
            $v = sanitize_text_field( $val );
            return ( $v && preg_match( '/^[0-9a-f\-]{36}$/i', $v ) ) ? $v : null;
        };

        $payload = [
            'package_name'         => sanitize_text_field(     $raw['package_name']  ?? '' ),
            'description'          => sanitize_textarea_field( $raw['description']   ?? '' ) ?: null,
            'base_armor'           => max( 0, (int) ( $raw['base_armor'] ?? 0 ) ),
            'is_player_selectable' => filter_var( $raw['is_player_selectable'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'head_item_id'         => $uuid_or_null( $raw['head_item_id']   ?? '' ),
            'torso_item_id'        => $uuid_or_null( $raw['torso_item_id']  ?? '' ),
            'hand_r_item_id'       => $uuid_or_null( $raw['hand_r_item_id'] ?? '' ),
            'hand_l_item_id'       => $uuid_or_null( $raw['hand_l_item_id'] ?? '' ),
            'belt_item_id'         => $uuid_or_null( $raw['belt_item_id']   ?? '' ),
            'items_list'           => $csv_to_arr( $raw['items_list']           ?? '' ),
            'attack_cards_pool'    => $csv_to_arr( $raw['attack_cards_pool']    ?? '' ),
            'defense_cards_pool'   => $csv_to_arr( $raw['defense_cards_pool']   ?? '' ),
            'compatibility_tags'   => $csv_to_arr( $raw['compatibility_tags']   ?? '' ),
            'compatible_class_ids' => $csv_to_arr( $raw['compatible_class_ids'] ?? '' ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_starting_packages?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_starting_packages', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: TOGGLE player_selectable                                    */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['pkg_id']              ?? '' );
        $state = filter_var(           $_POST['is_player_selectable'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_starting_packages?id=eq.' . urlencode( $id ), [ 'is_player_selectable' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_player_selectable' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['pkg_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_starting_packages?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_Starting_Packages_Admin();
