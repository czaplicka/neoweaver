<?php
/**
 * NeoWeaver — Deck (Cards) Admin Page
 *
 * Zarządza tabelą cyber_deck. Wzorowane na admin/containers.
 *
 * @package NeoWeaver
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class NW_Admin_Deck {

    private string $page_slug    = 'nw-deck';
    private string $nonce_action = 'nw_deck_nonce';
    private string $table        = 'cyber_deck';

    private const RARITIES  = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
    private const CATEGORIES = [ 'magic', 'combat', 'action', 'social', 'equipment', 'tech' ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_deck_load',           [ $this, 'ajax_load' ] );
        add_action( 'wp_ajax_nw_deck_save',           [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_nw_deck_delete',         [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_nw_deck_duplicate',      [ $this, 'ajax_duplicate' ] );
        add_action( 'wp_ajax_nw_deck_load_types',     [ $this, 'ajax_load_types' ] );
        add_action( 'wp_ajax_nw_deck_load_classes',   [ $this, 'ajax_load_classes' ] );
    }

    public function register_menu( string $menu_parent = '' ): void {
        // Inline SVG card icon — works in WP admin menu without Lucide JS
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#adff00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2"/><line x1="3" x2="21" y1="10" y2="10"/></svg>'
        );
        add_submenu_page(
            $menu_parent ?: 'neoweaver',
            __( 'Deck / Cards', 'neoweaver' ),
            '<span data-lucide-menu="id-card-lanyard"></span> Card Deck',
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
        wp_enqueue_style( 'nw-admin-core', NW_PLUGIN_URL . 'assets/css/admin/admin-core.css', [ 'chakra-petch' ], NW_VERSION );
        wp_enqueue_style( 'nw-deck-style',  NW_PLUGIN_URL . 'assets/css/admin/deck.css',       [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );

        wp_enqueue_script( 'lucide',      'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
        wp_enqueue_script( 'nw-deck-script', NW_PLUGIN_URL . 'assets/js/admin/deck.js', [ 'jquery', 'lucide' ], NW_VERSION, true );

        wp_localize_script( 'nw-deck-script', 'NWDeck', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( $this->nonce_action ),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function sk(): array {
        if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
            return [];
        }
        return [
            'apikey'        => TW_SUPABASE_SERVICE_KEY,
            'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
        ];
    }

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

    private function get_cache_key( string $suffix ): string {
        return 'nw_' . md5( $suffix );
    }

    private function bust_cache(): void {
        delete_transient( $this->get_cache_key( $this->table . '_all' ) );
        delete_transient( $this->get_cache_key( 'cyber_card_types_all' ) );
        delete_transient( $this->get_cache_key( 'cyber_classes_all' ) );
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

    private function bool_from_post( string $key, bool $default = false ): bool {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return (bool) intval( wp_unslash( $_POST[ $key ] ) );
    }

    private function sanitize_rarity( string $r ): string {
        $r = strtolower( trim( $r ) );
        return in_array( $r, self::RARITIES, true ) ? $r : 'common';
    }

    private function sanitize_category( string $c ): string {
        $c = strtolower( trim( $c ) );
        return in_array( $c, self::CATEGORIES, true ) ? $c : 'action';
    }

    private function json_from_post( string $key, mixed $default = [] ): mixed {
        $raw = wp_unslash( $_POST[ $key ] ?? '' );
        if ( '' === $raw ) {
            return $default;
        }
        $decoded = json_decode( $raw, true );
        return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $default;
    }

    // ── AJAX ────────────────────────────────────────────────────────────────────────

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

    public function ajax_load_types(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $cache_key = $this->get_cache_key( 'cyber_card_types_all' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            wp_send_json_success( $cached );
            return;
        }
        $res = $this->supa( 'GET', 'cyber_card_types?select=id,label,icon,color,category_id&order=label.asc', [], $this->sk() );
        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Failed to fetch card types.' );
            return;
        }
        $data = is_array( $res['data'] ) ? $res['data'] : [];
        set_transient( $cache_key, $data, MINUTE_IN_SECONDS * 30 );
        wp_send_json_success( $data );
    }

    public function ajax_load_classes(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $cache_key = $this->get_cache_key( 'cyber_classes_all' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            wp_send_json_success( $cached );
            return;
        }
        $res = $this->supa( 'GET', 'cyber_classes?select=id,name,icon_slug&is_active=eq.true&order=name.asc', [], $this->sk() );
        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Failed to fetch classes.' );
            return;
        }
        $data = is_array( $res['data'] ) ? $res['data'] : [];
        set_transient( $cache_key, $data, MINUTE_IN_SECONDS * 30 );
        wp_send_json_success( $data );
    }

    public function ajax_save(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id   = intval( wp_unslash( $_POST['id'] ?? 0 ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) {
            wp_send_json_error( 'Name is required.' );
            return;
        }

        $payload = [
            'name'                    => $name,
            'description'             => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ) ?: null,
            'deck_category'           => $this->sanitize_category( sanitize_text_field( wp_unslash( $_POST['deck_category'] ?? 'action' ) ) ),
            'type'                    => sanitize_text_field( wp_unslash( $_POST['type'] ?? 'Action' ) ),
            'mechanic'                => sanitize_text_field( wp_unslash( $_POST['mechanic'] ?? '' ) ) ?: null,
            'mechanic_goal'           => sanitize_textarea_field( wp_unslash( $_POST['mechanic_goal'] ?? '' ) ) ?: null,
            'cost_number'             => max( 0, intval( wp_unslash( $_POST['cost_number'] ?? 0 ) ) ),
            'effect'                  => sanitize_textarea_field( wp_unslash( $_POST['effect'] ?? '' ) ) ?: null,
            'bonus'                   => $this->json_from_post( 'bonus', (object) [] ),
            'ai_instruction'          => sanitize_textarea_field( wp_unslash( $_POST['ai_instruction'] ?? '' ) ) ?: null,
            'gm'                      => sanitize_textarea_field( wp_unslash( $_POST['gm'] ?? '' ) ) ?: null,
            'tags'                    => $this->json_from_post( 'tags', [] ),
            'requirement_tags'        => $this->json_from_post( 'requirement_tags', [] ),
            'denied_tags'             => $this->json_from_post( 'denied_tags', [] ),
            'required_item_tags'      => $this->json_from_post( 'required_item_tags', [] ),
            'required_location_tags'  => $this->json_from_post( 'required_location_tags', [] ),
            'denied_location_tags'    => $this->json_from_post( 'denied_location_tags', [] ),
            'requirement_description' => sanitize_textarea_field( wp_unslash( $_POST['requirement_description'] ?? '' ) ) ?: null,
            'time_cost_minutes'       => max( 0, intval( wp_unslash( $_POST['time_cost_minutes'] ?? 0 ) ) ),
            'cooldown_messages'       => max( 0, intval( wp_unslash( $_POST['cooldown_messages'] ?? 0 ) ) ),
            'entropy_on_fail'         => max( 0, intval( wp_unslash( $_POST['entropy_on_fail'] ?? 0 ) ) ),
            'rarity'                  => $this->sanitize_rarity( sanitize_text_field( wp_unslash( $_POST['rarity'] ?? 'common' ) ) ),
            'level'                   => max( 1, min( 15, intval( wp_unslash( $_POST['level'] ?? 1 ) ) ) ),
            'xp_current'              => max( 0, intval( wp_unslash( $_POST['xp_current'] ?? 0 ) ) ),
            'xp_to_next'              => max( 1, intval( wp_unslash( $_POST['xp_to_next'] ?? 10 ) ) ),
            'is_leveling'             => $this->bool_from_post( 'is_leveling', true ),
            'is_disposable'           => $this->bool_from_post( 'is_disposable', false ),
            'is_active'               => $this->bool_from_post( 'is_active', true ),
            'sound_effect'            => sanitize_text_field( wp_unslash( $_POST['sound_effect'] ?? '' ) ) ?: null,
            'img_url'                 => esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) ) ?: null,
            'base_damage'             => max( 0, min( 100, intval( wp_unslash( $_POST['base_damage'] ?? 0 ) ) ) ),
            'ap_cost'                 => max( 0, intval( wp_unslash( $_POST['ap_cost'] ?? 1 ) ) ),
            'mp_cost'                 => max( 0, intval( wp_unslash( $_POST['mp_cost'] ?? 0 ) ) ),
            'level_scaling'           => $this->json_from_post( 'level_scaling', (object) [] ),
            'xp_per_level'            => max( 1, intval( wp_unslash( $_POST['xp_per_level'] ?? 10 ) ) ),
            'counts_toward_hand_limit'=> $this->bool_from_post( 'counts_toward_hand_limit', true ),
            'asc_bonuses'             => $this->json_from_post( 'asc_bonuses', (object) [] ),
        ];

        // class_id (optional UUID)
        $class_id = sanitize_text_field( wp_unslash( $_POST['class_id'] ?? '' ) );
        if ( $class_id ) {
            $payload['class_id'] = $class_id;
        }

        if ( $id ) {
            $res = $this->supa( 'PATCH', $this->table . '?id=eq.' . $id, $payload, $this->sk() );
        } else {
            $res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
        }

        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Save failed.' );
            return;
        }

        $this->bust_cache();
        $row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
        wp_send_json_success( $row );
    }

    public function ajax_delete(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid card ID.' );
            return;
        }
        $res = $this->supa( 'DELETE', $this->table . '?id=eq.' . $id, [], $this->sk() );
        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Delete failed.' );
            return;
        }
        $this->bust_cache();
        wp_send_json_success( 'deleted' );
    }

    public function ajax_duplicate(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid card ID.' );
            return;
        }

        $res = $this->supa( 'GET', $this->table . '?id=eq.' . $id . '&select=*', [], $this->sk() );
        if ( ! $res['ok'] || empty( $res['data'] ) ) {
            wp_send_json_error( 'Original card not found.' );
            return;
        }
        $original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];

        $payload = $original;
        unset( $payload['id'], $payload['created_at'] );
        $payload['name']      = $original['name'] . ' (Copy)';
        $payload['is_active'] = false;

        $dup_res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
        if ( ! $dup_res['ok'] ) {
            wp_send_json_error( $dup_res['error'] ?? 'Duplicate failed.' );
            return;
        }
        $this->bust_cache();
        $row = is_array( $dup_res['data'] ) ? ( $dup_res['data'][0] ?? $dup_res['data'] ) : $dup_res['data'];
        wp_send_json_success( $row );
    }

    // ── Render ───────────────────────────────────────────────────────────────────────

    public function render_page(): void {
        ?>
<div class="nw-admin-panel nw-deck-panel">

    <div class="nw-panel-header">
        <h1 class="nw-panel-title">
            <i data-lucide="layers"></i> Deck — Cards
        </h1>
        <button id="nw-btn-add" class="nw-btn nw-btn-primary">
            <i data-lucide="plus"></i> Add Card
        </button>
    </div>

    <div id="nw-notice" class="nw-notice" style="display:none;"></div>

    <!-- Stats -->
    <div class="nw-stats-row">
        <div class="nw-stat-box"><span id="nw-total">—</span><small>Total</small></div>
        <div class="nw-stat-box"><span id="nw-active">—</span><small>Active</small></div>
        <div class="nw-stat-box"><span id="nw-inactive">—</span><small>Inactive</small></div>
        <div class="nw-stat-box"><span id="nw-rareplus">—</span><small>Rare+</small></div>
    </div>

    <!-- Filters -->
    <div class="nw-filters-row">
        <input type="text" id="nw-search" class="nw-input" placeholder="Search name…">
        <select id="nw-filter-cat" class="nw-select">
            <option value="">All categories</option>
            <option value="action">Action</option>
            <option value="combat">Combat</option>
            <option value="magic">Magic</option>
            <option value="social">Social</option>
            <option value="equipment">Equipment</option>
            <option value="tech">Tech</option>
        </select>
        <select id="nw-filter-rarity" class="nw-select">
            <option value="">All rarities</option>
            <option value="common">Common</option>
            <option value="uncommon">Uncommon</option>
            <option value="rare">Rare</option>
            <option value="epic">Epic</option>
            <option value="legendary">Legendary</option>
        </select>
        <select id="nw-filter-active" class="nw-select">
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
    </div>

    <!-- Table -->
    <div class="nw-table-wrap">
        <table class="nw-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Rarity</th>
                    <th>Mechanic</th>
                    <th>Effect</th>
                    <th>Tags</th>
                    <th>Lvl</th>
                    <th>AP</th>
                    <th>MP</th>
                    <th>DMG</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="nw-deck-tbody">
                <tr><td colspan="13" class="nw-table-loading">Loading cards…</td></tr>
            </tbody>
        </table>
    </div>

</div><!-- .nw-deck-panel -->

<!-- ── MODAL ────────────────────────────────────────────────────────────── -->
<div id="nw-modal" class="nw-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
    <div class="nw-modal-backdrop"></div>
    <div class="nw-modal-box nw-modal-wide">

        <div class="nw-modal-header">
            <h2 id="nw-modal-title" class="nw-modal-title">Add Card</h2>
            <button class="nw-modal-close" aria-label="Close"><i data-lucide="x"></i></button>
        </div>

        <form id="nw-form">
            <input type="hidden" id="nw-field-id" name="id" value="">

            <!-- TABS -->
            <div class="nw-tabs">
                <button type="button" class="nw-tab nw-tab-active" data-tab="basic">Basic</button>
                <button type="button" class="nw-tab" data-tab="combat">Combat</button>
                <button type="button" class="nw-tab" data-tab="tags">Tags</button>
                <button type="button" class="nw-tab" data-tab="advanced">Advanced</button>
            </div>

            <!-- TAB: Basic -->
            <div class="nw-tab-panel" id="nw-tab-basic">
                <div class="nw-form-grid">
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-name">Name *</label>
                        <input type="text" id="nw-field-name" name="name" class="nw-input" required>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-deck-category">Category</label>
                        <select id="nw-field-deck-category" name="deck_category" class="nw-select">
                            <option value="action">Action</option>
                            <option value="combat">Combat</option>
                            <option value="magic">Magic</option>
                            <option value="social">Social</option>
                            <option value="equipment">Equipment</option>
                            <option value="tech">Tech</option>
                        </select>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-type">Type</label>
                        <select id="nw-field-type" name="type" class="nw-select">
                            <option value="">— loading —</option>
                        </select>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-rarity">Rarity</label>
                        <select id="nw-field-rarity" name="rarity" class="nw-select">
                            <option value="common">Common</option>
                            <option value="uncommon">Uncommon</option>
                            <option value="rare">Rare</option>
                            <option value="epic">Epic</option>
                            <option value="legendary">Legendary</option>
                        </select>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-class-id">Class (optional)</label>
                        <select id="nw-field-class-id" name="class_id" class="nw-select">
                            <option value="">— Any class —</option>
                        </select>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-cost-number">Resource Cost</label>
                        <input type="number" id="nw-field-cost-number" name="cost_number" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-description">Description</label>
                        <textarea id="nw-field-description" name="description" class="nw-textarea" rows="3"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-effect">Effect (in-game text)</label>
                        <textarea id="nw-field-effect" name="effect" class="nw-textarea" rows="3"></textarea>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-img-url">Image URL</label>
                        <input type="url" id="nw-field-img-url" name="img_url" class="nw-input" placeholder="https://…">
                    </div>
                    <div class="nw-field nw-field-img-preview">
                        <label>Image Preview</label>
                        <div id="nw-img-preview" class="nw-img-preview-box"><span class="nw-img-placeholder"><i data-lucide="image"></i></span></div>
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-sound-effect">Sound Effect slug</label>
                        <input type="text" id="nw-field-sound-effect" name="sound_effect" class="nw-input" placeholder="e.g. card-draw">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-mechanic">Mechanic</label>
                        <input type="text" id="nw-field-mechanic" name="mechanic" class="nw-input">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-mechanic-goal">Mechanic Goal</label>
                        <textarea id="nw-field-mechanic-goal" name="mechanic_goal" class="nw-textarea" rows="2"></textarea>
                    </div>
                    <div class="nw-field nw-field-inline">
                        <label><input type="checkbox" id="nw-field-is-active" name="is_active" value="1" checked> Active</label>
                        <label><input type="checkbox" id="nw-field-is-leveling" name="is_leveling" value="1" checked> Can level up</label>
                        <label><input type="checkbox" id="nw-field-is-disposable" name="is_disposable" value="1"> Disposable (single use)</label>
                        <label><input type="checkbox" id="nw-field-counts-hand" name="counts_toward_hand_limit" value="1" checked> Counts toward hand limit</label>
                    </div>
                </div>
            </div><!-- /tab-basic -->

            <!-- TAB: Combat -->
            <div class="nw-tab-panel" id="nw-tab-combat" style="display:none;">
                <div class="nw-form-grid">
                    <div class="nw-field">
                        <label for="nw-field-base-damage">Base Damage (0–100)</label>
                        <input type="number" id="nw-field-base-damage" name="base_damage" class="nw-input" value="0" min="0" max="100">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-ap-cost">AP Cost</label>
                        <input type="number" id="nw-field-ap-cost" name="ap_cost" class="nw-input" value="1" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-mp-cost">MP Cost</label>
                        <input type="number" id="nw-field-mp-cost" name="mp_cost" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-time-cost">Time Cost (minutes)</label>
                        <input type="number" id="nw-field-time-cost" name="time_cost_minutes" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-cooldown">Cooldown (messages)</label>
                        <input type="number" id="nw-field-cooldown" name="cooldown_messages" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-entropy">Entropy on Fail</label>
                        <input type="number" id="nw-field-entropy" name="entropy_on_fail" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-level">Level (1–15)</label>
                        <input type="number" id="nw-field-level" name="level" class="nw-input" value="1" min="1" max="15">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-xp-current">XP Current</label>
                        <input type="number" id="nw-field-xp-current" name="xp_current" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-xp-to-next">XP to Next</label>
                        <input type="number" id="nw-field-xp-to-next" name="xp_to_next" class="nw-input" value="10" min="1">
                    </div>
                    <div class="nw-field">
                        <label for="nw-field-xp-per-level">XP per Level</label>
                        <input type="number" id="nw-field-xp-per-level" name="xp_per_level" class="nw-input" value="10" min="1">
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-bonus">Bonus (JSON)</label>
                        <textarea id="nw-field-bonus" name="bonus" class="nw-textarea nw-json-field" rows="3" placeholder="{}"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-level-scaling">Level Scaling (JSON)</label>
                        <textarea id="nw-field-level-scaling" name="level_scaling" class="nw-textarea nw-json-field" rows="3" placeholder="{}"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-asc-bonuses">Ascension Bonuses (JSON)</label>
                        <textarea id="nw-field-asc-bonuses" name="asc_bonuses" class="nw-textarea nw-json-field" rows="3" placeholder="{}"></textarea>
                    </div>
                </div>
            </div><!-- /tab-combat -->

            <!-- TAB: Tags -->
            <div class="nw-tab-panel" id="nw-tab-tags" style="display:none;">
                <p class="nw-tab-hint">Type a tag and press Enter or comma to add. Click × to remove.</p>
                <div class="nw-form-grid">
                    <div class="nw-field nw-field-full">
                        <label>Tags</label>
                        <div class="nw-tag-input" data-field="tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="tags" id="nw-hidden-tags">
                    </div>
                    <div class="nw-field">
                        <label>Requirement Tags</label>
                        <div class="nw-tag-input" data-field="requirement_tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="requirement_tags" id="nw-hidden-requirement_tags">
                    </div>
                    <div class="nw-field">
                        <label>Denied Tags</label>
                        <div class="nw-tag-input" data-field="denied_tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="denied_tags" id="nw-hidden-denied_tags">
                    </div>
                    <div class="nw-field">
                        <label>Required Item Tags</label>
                        <div class="nw-tag-input" data-field="required_item_tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="required_item_tags" id="nw-hidden-required_item_tags">
                    </div>
                    <div class="nw-field">
                        <label>Required Location Tags</label>
                        <div class="nw-tag-input" data-field="required_location_tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="required_location_tags" id="nw-hidden-required_location_tags">
                    </div>
                    <div class="nw-field">
                        <label>Denied Location Tags</label>
                        <div class="nw-tag-input" data-field="denied_location_tags"><div class="nw-tag-input-inner"><input type="text" placeholder="add tag…"></div></div>
                        <input type="hidden" name="denied_location_tags" id="nw-hidden-denied_location_tags">
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-req-description">Requirement Description</label>
                        <textarea id="nw-field-req-description" name="requirement_description" class="nw-textarea" rows="2" placeholder="Human-readable requirement summary…"></textarea>
                    </div>
                </div>
            </div><!-- /tab-tags -->

            <!-- TAB: Advanced -->
            <div class="nw-tab-panel" id="nw-tab-advanced" style="display:none;">
                <div class="nw-info-box">
                    <i data-lucide="info"></i>
                    AI instructions are sent to the GM model on every card activation. Keep them concise but precise.
                </div>
                <div class="nw-form-grid" style="margin-top:12px;">
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-ai-instruction">AI Instruction
                            <button type="button" class="nw-expand-btn" data-target="nw-field-ai-instruction">
                                <i data-lucide="maximize-2"></i>
                            </button>
                        </label>
                        <textarea id="nw-field-ai-instruction" name="ai_instruction" class="nw-textarea nw-textarea-tall" rows="6" placeholder="Instructions for the AI Game Master…"></textarea>
                    </div>
                    <div class="nw-field nw-field-full">
                        <label for="nw-field-gm">GM Notes
                            <button type="button" class="nw-expand-btn" data-target="nw-field-gm">
                                <i data-lucide="maximize-2"></i>
                            </button>
                        </label>
                        <textarea id="nw-field-gm" name="gm" class="nw-textarea nw-textarea-tall" rows="5" placeholder="Internal GM/designer notes…"></textarea>
                    </div>
                </div>
            </div><!-- /tab-advanced -->

            <div class="nw-modal-footer">
                <button type="button" class="nw-btn nw-btn-ghost nw-modal-close">Cancel</button>
                <button type="submit" class="nw-btn nw-btn-primary" id="nw-btn-save">
                    <i data-lucide="save"></i> Save Card
                </button>
            </div>
        </form>
    </div><!-- .nw-modal-box -->
</div><!-- #nw-modal -->

<!-- ── DELETE CONFIRM MODAL ─────────────────────────────────────────────── -->
<div id="nw-confirm-modal" class="nw-modal" style="display:none;" role="dialog" aria-modal="true">
    <div class="nw-modal-backdrop"></div>
    <div class="nw-modal-box nw-modal-sm">
        <div class="nw-modal-header">
            <h2 class="nw-modal-title">Delete Card</h2>
            <button class="nw-modal-close" data-modal="nw-confirm-modal" aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <p class="nw-confirm-text">Are you sure you want to delete <strong id="nw-confirm-name"></strong>? This cannot be undone.</p>
        <div class="nw-modal-footer">
            <button type="button" class="nw-btn nw-btn-ghost nw-modal-close" data-modal="nw-confirm-modal">Cancel</button>
            <button type="button" id="nw-btn-confirm-delete" class="nw-btn nw-btn-danger">
                <i data-lucide="trash-2"></i> Delete
            </button>
        </div>
    </div>
</div>
        <?php
    }
}
