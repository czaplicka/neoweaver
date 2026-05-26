<?php
/**
 * NeoWeaver — Achievements Admin Panel
 *
 * Zarządza tabelą cyber_achievements w Supabase.
 * Wzorowany 1:1 na classes.php — ta sama architektura.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NW_Achievements_Admin {

    private string $table        = 'cyber_achievements';
    private string $page_slug    = 'nw-achievements';
    private string $nonce_action = 'nw_achievements_nonce';

    /* ---------------------------------------------------------------- */
    /* BOOT                                                               */
    /* ---------------------------------------------------------------- */

    public function __construct() {
        add_action( 'admin_menu',       [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nw_achievements_load',   [ $this, 'ajax_load' ] );
        add_action( 'wp_ajax_nw_achievements_save',   [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_nw_achievements_delete', [ $this, 'ajax_delete' ] );
    }

    /* ---------------------------------------------------------------- */
    /* MENU                                                               */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            'neoweaver',                     // parent slug (menu główne NeoWeaver)
            __( 'Achievements', 'neoweaver' ),
            __( '<span data-lucide-menu="trophy"></span> Achievements', 'neoweaver' ),
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
            'nw-achievements-style',
            NW_PLUGIN_URL . 'assets/css/admin/achievements.css',
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
            'nw-achievements-script',
            NW_PLUGIN_URL . 'assets/js/admin/achievements.js',
            [ 'jquery', 'lucide' ],
            NW_VERSION,
            true
        );

        wp_localize_script( 'nw-achievements-script', 'NWAchievements', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( $this->nonce_action ),
        ] );
    }

    /* ---------------------------------------------------------------- */
    /* SERVICE KEY HEADERS                                                */
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
    /* VALIDATE & NORMALIZE                                               */
    /* ---------------------------------------------------------------- */

    private function is_valid_id( string $value ): bool {
        // ID to text — sprawdzamy że nie jest pusty i nie zawiera znaków SQL-injection
        return '' !== $value && strlen( $value ) <= 64 && preg_match( '/^[a-zA-Z0-9_\-]+$/', $value );
    }

    private function bool_from_post( string $key, bool $default = false ): bool {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return (bool) intval( wp_unslash( $_POST[ $key ] ) );
    }

    private function valid_scope( string $v ): bool {
        return in_array( $v, [ 'account', 'character' ], true );
    }

    private function valid_category( string $v ): bool {
        if ( '' === $v ) {
            return true; // nullable
        }
        return in_array( $v, [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ], true );
    }

    /* ---------------------------------------------------------------- */
    /* AJAX                                                               */
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

    public function ajax_save(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id          = sanitize_text_field( wp_unslash( $_POST['id']          ?? '' ) );
        $title       = sanitize_text_field( wp_unslash( $_POST['title']       ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $icon_slug   = sanitize_text_field( wp_unslash( $_POST['icon_slug']   ?? '' ) );
        $bg_color    = sanitize_hex_color( wp_unslash( $_POST['bg_color']     ?? '#2c3e50' ) ) ?? '#2c3e50';
        $scope       = sanitize_text_field( wp_unslash( $_POST['scope']       ?? '' ) );
        $goal        = max( 1, intval( wp_unslash( $_POST['goal']             ?? 1 ) ) );
        $category    = sanitize_text_field( wp_unslash( $_POST['category']    ?? '' ) );
        $hidden      = $this->bool_from_post( 'hidden_until_earned', false );
        $is_active   = $this->bool_from_post( 'is_active', true );

        if ( ! $title ) {
            wp_send_json_error( 'Title is required.' );
            return;
        }

        if ( $id && ! $this->is_valid_id( $id ) ) {
            wp_send_json_error( 'Invalid achievement ID.' );
            return;
        }

        if ( $scope && ! $this->valid_scope( $scope ) ) {
            wp_send_json_error( 'Invalid scope value.' );
            return;
        }

        if ( ! $this->valid_category( $category ) ) {
            wp_send_json_error( 'Invalid category value.' );
            return;
        }

        $payload = [
            'title'               => $title,
            'description'         => '' !== $description ? $description : null,
            'icon_slug'           => '' !== $icon_slug   ? $icon_slug   : 'default_icon',
            'bg_color'            => $bg_color,
            'scope'               => '' !== $scope       ? $scope       : null,
            'goal'                => $goal,
            'category'            => '' !== $category    ? $category    : null,
            'hidden_until_earned' => $hidden,
            'is_active'           => $is_active,
        ];

        if ( $id ) {
            // UPDATE — ID to text PK
            $res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
        } else {
            // INSERT — wymagamy ID (text PK) podanego przez admina lub autogenerowanego
            $new_id = sanitize_text_field( wp_unslash( $_POST['new_id'] ?? '' ) );
            if ( ! $new_id || ! $this->is_valid_id( $new_id ) ) {
                wp_send_json_error( 'ID is required for new achievement (text primary key).' );
                return;
            }
            $payload['id'] = $new_id;
            $res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
        }

        if ( ! $res['ok'] ) {
            wp_send_json_error( $res['error'] ?? 'Save failed.' );
            return;
        }

        $item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
        $this->bust_cache();
        wp_send_json_success( $item );
    }

    public function ajax_delete(): void {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
        if ( ! $id || ! $this->is_valid_id( $id ) ) {
            wp_send_json_error( 'Invalid or missing ID.' );
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
    /* RENDER                                                             */
    /* ---------------------------------------------------------------- */

    public function render_page(): void {
        ?>
        <div class="wrap nw-panel" id="nw-achievements-panel">

            <div id="nw-notice" class="nw-notice" style="display:none"></div>

            <div class="nw-panel-header">
                <div>
                    <h1 class="nw-panel-title">
                        <i data-lucide="trophy" style="display:inline-block;vertical-align:middle;margin-right:8px;width:22px;height:22px;"></i>
                        <?php esc_html_e( 'Achievements', 'neoweaver' ); ?>
                    </h1>
                    <p class="nw-panel-subtitle"><?php esc_html_e( 'Manage cyber_achievements — account & character scope trophies.', 'neoweaver' ); ?></p>
                </div>
                <div class="nw-header-actions">
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="Search…" autocomplete="off">
                    <select id="nw-filter-scope" class="nw-select-filter">
                        <option value=""><?php esc_html_e( 'All scopes', 'neoweaver' ); ?></option>
                        <option value="account"><?php esc_html_e( 'Account', 'neoweaver' ); ?></option>
                        <option value="character"><?php esc_html_e( 'Character', 'neoweaver' ); ?></option>
                    </select>
                    <select id="nw-filter-category" class="nw-select-filter">
                        <option value=""><?php esc_html_e( 'All categories', 'neoweaver' ); ?></option>
                        <?php foreach ( [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ] as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( ucfirst( $cat ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button id="nw-add-btn" class="nw-btn nw-btn-primary">
                        <i data-lucide="plus" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
                        <?php esc_html_e( 'Add Achievement', 'neoweaver' ); ?>
                    </button>
                    <button id="nw-refresh-btn" class="nw-btn nw-btn-ghost">
                        <i data-lucide="refresh-cw" style="width:13px;height:13px;vertical-align:middle;"></i>
                    </button>
                </div>
            </div>

            <div class="nw-stats-bar">
                <div class="nw-stat-pill nw-pill-active">Total: <strong id="nw-total">—</strong></div>
                <div class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></div>
                <div class="nw-stat-pill nw-pill-account">Account: <strong id="nw-scope-account">—</strong></div>
                <div class="nw-stat-pill nw-pill-character">Character: <strong id="nw-scope-character">—</strong></div>
                <div class="nw-stat-pill nw-pill-hidden">Hidden: <strong id="nw-hidden-count">—</strong></div>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead>
                        <tr>
                            <th style="width:44px;"><?php esc_html_e( 'Icon', 'neoweaver' ); ?></th>
                            <th><?php esc_html_e( 'ID', 'neoweaver' ); ?></th>
                            <th><?php esc_html_e( 'Title', 'neoweaver' ); ?></th>
                            <th><?php esc_html_e( 'Category', 'neoweaver' ); ?></th>
                            <th><?php esc_html_e( 'Scope', 'neoweaver' ); ?></th>
                            <th style="width:60px;"><?php esc_html_e( 'Goal', 'neoweaver' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Hidden', 'neoweaver' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Active', 'neoweaver' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Actions', 'neoweaver' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="nw-achievements-tbody">
                        <tr class="nw-loading-row"><td colspan="9">
                            <span class="nw-spinner"></span><?php esc_html_e( 'Loading achievements…', 'neoweaver' ); ?>
                        </td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- #nw-achievements-panel -->

        <!-- ── MODAL ──────────────────────────────────────── -->
        <div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
            <div class="nw-modal" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">

                <div class="nw-modal-header">
                    <h2 id="nw-modal-title"><?php esc_html_e( 'New Achievement', 'neoweaver' ); ?></h2>
                    <button id="nw-modal-close" class="nw-modal-close" aria-label="<?php esc_attr_e( 'Close', 'neoweaver' ); ?>">✕</button>
                </div>

                <div class="nw-modal-body">
                    <form id="nw-achievement-form" autocomplete="off">
                        <input type="hidden" id="nw-field-id">

                        <div class="nw-section-label"><?php esc_html_e( 'Identity', 'neoweaver' ); ?></div>
                        <div class="nw-form-grid">

                            <div class="nw-field">
                                <label for="nw-field-new-id">
                                    <?php esc_html_e( 'ID (slug)', 'neoweaver' ); ?>
                                    <span class="nw-req">*</span>
                                    <span class="nw-hint"><?php esc_html_e( 'unique, a-z 0-9 _ - (wymagane przy tworzeniu)', 'neoweaver' ); ?></span>
                                </label>
                                <input type="text" id="nw-field-new-id" placeholder="first_login" maxlength="64">
                            </div>

                            <div class="nw-field">
                                <label for="nw-field-title">
                                    <?php esc_html_e( 'Title', 'neoweaver' ); ?>
                                    <span class="nw-req">*</span>
                                </label>
                                <input type="text" id="nw-field-title" placeholder="First Steps" maxlength="120">
                            </div>

                            <div class="nw-field nw-field-full">
                                <label for="nw-field-description"><?php esc_html_e( 'Description', 'neoweaver' ); ?></label>
                                <textarea id="nw-field-description" rows="2" placeholder="Complete your first mission…"></textarea>
                            </div>

                        </div>

                        <div class="nw-section-label"><?php esc_html_e( 'Appearance', 'neoweaver' ); ?></div>
                        <div class="nw-form-grid">

                            <div class="nw-field">
                                <label for="nw-field-icon-slug"><?php esc_html_e( 'Icon slug', 'neoweaver' ); ?></label>
                                <input type="text" id="nw-field-icon-slug" placeholder="trophy">
                                <span class="nw-hint"><?php esc_html_e( 'Lucide icon name, np. trophy, star, zap', 'neoweaver' ); ?></span>
                            </div>

                            <div class="nw-field">
                                <label for="nw-field-bg-color"><?php esc_html_e( 'BG Color', 'neoweaver' ); ?></label>
                                <div class="nw-color-row">
                                    <input type="color" id="nw-field-bg-color-picker" class="nw-color-picker" value="#2c3e50">
                                    <input type="text"  id="nw-field-bg-color" value="#2c3e50" maxlength="7" style="width:100px;">
                                </div>
                            </div>

                        </div>

                        <div class="nw-section-label"><?php esc_html_e( 'Rules', 'neoweaver' ); ?></div>
                        <div class="nw-form-grid">

                            <div class="nw-field">
                                <label for="nw-field-scope"><?php esc_html_e( 'Scope', 'neoweaver' ); ?></label>
                                <select id="nw-field-scope" class="nw-select">
                                    <option value=""><?php esc_html_e( '— none —', 'neoweaver' ); ?></option>
                                    <option value="account"><?php esc_html_e( 'Account', 'neoweaver' ); ?></option>
                                    <option value="character"><?php esc_html_e( 'Character', 'neoweaver' ); ?></option>
                                </select>
                            </div>

                            <div class="nw-field">
                                <label for="nw-field-category"><?php esc_html_e( 'Category', 'neoweaver' ); ?></label>
                                <select id="nw-field-category" class="nw-select">
                                    <option value=""><?php esc_html_e( '— none —', 'neoweaver' ); ?></option>
                                    <?php foreach ( [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ] as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( ucfirst( $cat ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nw-field">
                                <label for="nw-field-goal"><?php esc_html_e( 'Goal (count)', 'neoweaver' ); ?></label>
                                <input type="number" id="nw-field-goal" value="1" min="1" max="99999">
                            </div>

                            <div class="nw-field nw-field-toggles" style="grid-column:1/-1;">
                                <div class="nw-toggle-row">
                                    <label class="nw-toggle-label">
                                        <label class="nw-toggle">
                                            <input type="checkbox" id="nw-field-hidden" value="1">
                                            <span class="nw-toggle-slider"></span>
                                        </label>
                                        <?php esc_html_e( 'Hidden until earned', 'neoweaver' ); ?>
                                    </label>
                                    <label class="nw-toggle-label">
                                        <label class="nw-toggle">
                                            <input type="checkbox" id="nw-field-is-active" value="1" checked>
                                            <span class="nw-toggle-slider"></span>
                                        </label>
                                        <?php esc_html_e( 'Active', 'neoweaver' ); ?>
                                    </label>
                                </div>
                            </div>

                        </div>
                    </form>
                </div>

                <div class="nw-modal-footer">
                    <button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto;">
                        <i data-lucide="trash-2" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
                        <?php esc_html_e( 'Delete', 'neoweaver' ); ?>
                    </button>
                    <button id="nw-cancel-btn" class="nw-btn nw-btn-ghost"><?php esc_html_e( 'Cancel', 'neoweaver' ); ?></button>
                    <button id="nw-save-btn" class="nw-btn nw-btn-primary">
                        <span id="nw-save-label"><?php esc_html_e( 'Create Achievement', 'neoweaver' ); ?></span>
                    </button>
                </div>

            </div>
        </div>
        <?php
        // Lucide icons init — wywołujemy po załadowaniu DOM
        wp_add_inline_script( 'lucide', 'document.addEventListener("DOMContentLoaded",function(){if(window.lucide)lucide.createIcons();});' );
    }
}

new NW_Achievements_Admin();
