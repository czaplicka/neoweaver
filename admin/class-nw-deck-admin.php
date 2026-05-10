<?php
/**
 * NeoWeaver Admin Panel — Deck Cards (cyber_deck)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Deck_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-deck';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_deck_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_deck_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_deck_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_deck_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Deck Cards',
            '🃏 Deck Cards',
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

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'nw-deck-admin-style',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/deck-admin.css',
            [ 'chakra-petch' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_script(
            'nw-deck-admin-script',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/deck-admin.js',
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );

        wp_localize_script( 'nw-deck-admin-script', 'NW_Deck', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'neoweaver_deck' ),
        ] );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-deck-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Deck Cards</span>
                </h1>
                <div class="nw-header-actions">
                    <div class="nw-filter-bar">
                        <input type="search" id="nw-search" class="nw-search-input" placeholder="&#128269; Search cards&hellip;" autocomplete="off">
                        <select id="nw-filter-category" class="nw-select-filter">
                            <option value="">All categories</option>
                            <?php foreach ( $this->categories() as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="nw-filter-type" class="nw-select-filter">
                            <option value="">All types</option>
                            <?php foreach ( $this->types() as $t ) : ?>
                            <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="nw-filter-rarity" class="nw-select-filter">
                            <option value="">All rarities</option>
                            <?php foreach ( $this->rarities() as $r ) : ?>
                            <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Card</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">&mdash;</strong></span>
                <span class="nw-stat-pill" id="nw-filtered-pill" style="display:none;">Showing: <strong id="nw-filtered">&mdash;</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <colgroup>
                        <col class="nw-col-img">
                        <col class="nw-col-name">
                        <col class="nw-col-category">
                        <col class="nw-col-type">
                        <col class="nw-col-tags">
                        <col class="nw-col-rarity">
                        <col class="nw-col-cost">
                        <col class="nw-col-active">
                        <col class="nw-col-actions">
                    </colgroup>
                    <thead><tr>
                        <th></th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Tags</th>
                        <th>Rarity / Level</th>
                        <th>Cost / Time</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-deck-tbody">
                        <tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading cards&hellip;</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Card</h2>
                        <button class="nw-modal-close" id="nw-modal-close">&times;</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-deck-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Neural Override">
                                </div>
                                <div class="nw-field">
                                    <label>Category</label>
                                    <select id="nw-field-deck_category" name="deck_category" class="nw-select">
                                        <?php foreach ( $this->categories() as $c ) : ?>
                                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Type</label>
                                    <input type="text" id="nw-field-type" name="type" placeholder="e.g. Action, Spell, Gear">
                                </div>
                                <div class="nw-field">
                                    <label>Rarity</label>
                                    <select id="nw-field-rarity" name="rarity" class="nw-select">
                                        <?php foreach ( $this->rarities() as $r ) : ?>
                                        <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="2" placeholder="Card description visible to players&hellip;"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Effect</label>
                                    <textarea id="nw-field-effect" name="effect" rows="2" placeholder="What happens when this card is played&hellip;"></textarea>
                                </div>
                            </div>

                            <!-- Mechanics -->
                            <div class="nw-section-label">Mechanics</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Level</label>
                                    <input type="number" id="nw-field-level" name="level" min="1" max="20" placeholder="1">
                                </div>
                                <div class="nw-field">
                                    <label>Action Cost</label>
                                    <input type="number" id="nw-field-action_cost" name="action_cost" min="0" placeholder="0">
                                </div>
                                <div class="nw-field">
                                    <label>Time Cost</label>
                                    <input type="text" id="nw-field-time_cost" name="time_cost" placeholder="e.g. 1 turn">
                                </div>
                                <div class="nw-field">
                                    <label>Duration</label>
                                    <input type="text" id="nw-field-duration" name="duration" placeholder="e.g. Instant, 3 rounds">
                                </div>
                                <div class="nw-field">
                                    <label>Target</label>
                                    <input type="text" id="nw-field-target" name="target" placeholder="e.g. Self, Enemy, Area">
                                </div>
                                <div class="nw-field">
                                    <label>Range</label>
                                    <input type="text" id="nw-field-range" name="range" placeholder="e.g. Melee, 10m">
                                </div>
                            </div>

                            <!-- Resources -->
                            <div class="nw-section-label">Resources</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>HP Cost</label>
                                    <input type="number" id="nw-field-hp_cost" name="hp_cost" min="0" placeholder="0">
                                </div>
                                <div class="nw-field">
                                    <label>Mana Cost</label>
                                    <input type="number" id="nw-field-mana_cost" name="mana_cost" min="0" placeholder="0">
                                </div>
                                <div class="nw-field">
                                    <label>Stamina Cost</label>
                                    <input type="number" id="nw-field-stamina_cost" name="stamina_cost" min="0" placeholder="0">
                                </div>
                                <div class="nw-field">
                                    <label>Gold Cost</label>
                                    <input type="number" id="nw-field-gold_cost" name="gold_cost" min="0" placeholder="0">
                                </div>
                            </div>

                            <!-- Tags & Image -->
                            <div class="nw-section-label">Tags &amp; Image</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. combat, magic, tech">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-img_url" name="img_url" placeholder="https://…">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>GM Notes</label>
                                    <textarea id="nw-field-gm_notes" name="gm_notes" rows="2" placeholder="Internal notes for Game Masters…"></textarea>
                                </div>
                                <div class="nw-field">
                                    <label class="nw-toggle-label">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active" value="1" checked>
                                        <span>Active</span>
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Card</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  AJAX — get_all                                                    */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $category = sanitize_text_field( $_POST['category'] ?? '' );
        $type     = sanitize_text_field( $_POST['type']     ?? '' );
        $rarity   = sanitize_text_field( $_POST['rarity']   ?? '' );
        $search   = sanitize_text_field( $_POST['search']   ?? '' );
        $active   = $_POST['active'] ?? '';

        $endpoint = '/rest/v1/cyber_deck?select=*&order=name.asc';
        if ( $category ) $endpoint .= '&deck_category=eq.' . urlencode( $category );
        if ( $type )     $endpoint .= '&type=eq.'          . urlencode( $type );
        if ( $rarity )   $endpoint .= '&rarity=eq.'        . urlencode( $rarity );
        if ( $active !== '' ) $endpoint .= '&is_active=eq.' . ( $active ? 'true' : 'false' );
        if ( $search )   $endpoint .= '&name=ilike.*'      . urlencode( $search ) . '*';

        $result = $this->supa( 'GET', $endpoint );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( $result );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — save                                                       */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $id   = intval( $_POST['id'] ?? 0 );
        $name = sanitize_text_field( $_POST['name'] ?? '' );

        if ( ! $name ) {
            wp_send_json_error( 'Name is required.' ); return;
        }

        $payload = [
            'name'          => $name,
            'deck_category' => sanitize_text_field( $_POST['deck_category'] ?? 'action' ),
            'type'          => sanitize_text_field( $_POST['type']          ?? '' ),
            'rarity'        => sanitize_text_field( $_POST['rarity']        ?? 'common' ),
            'description'   => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'effect'        => sanitize_textarea_field( $_POST['effect']       ?? '' ),
            'level'         => intval( $_POST['level']        ?? 1 ),
            'action_cost'   => intval( $_POST['action_cost']  ?? 0 ),
            'time_cost'     => sanitize_text_field( $_POST['time_cost']  ?? '' ),
            'duration'      => sanitize_text_field( $_POST['duration']   ?? '' ),
            'target'        => sanitize_text_field( $_POST['target']     ?? '' ),
            'range'         => sanitize_text_field( $_POST['range']      ?? '' ),
            'hp_cost'       => intval( $_POST['hp_cost']      ?? 0 ),
            'mana_cost'     => intval( $_POST['mana_cost']    ?? 0 ),
            'stamina_cost'  => intval( $_POST['stamina_cost'] ?? 0 ),
            'gold_cost'     => intval( $_POST['gold_cost']    ?? 0 ),
            'tags'          => sanitize_text_field( $_POST['tags']       ?? '' ),
            'img_url'       => esc_url_raw( $_POST['img_url']            ?? '' ),
            'gm_notes'      => sanitize_textarea_field( $_POST['gm_notes'] ?? '' ),
            'is_active'     => ! empty( $_POST['is_active'] ),
        ];

        if ( $id ) {
            $result = $this->supa( 'PATCH', '/rest/v1/cyber_deck?id=eq.' . $id, $payload );
        } else {
            $result = $this->supa( 'POST', '/rest/v1/cyber_deck', $payload );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — toggle                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $id    = intval( $_POST['id']    ?? 0 );
        $state = ! empty( $_POST['state'] );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' ); return;
        }

        $result = $this->supa( 'PATCH', '/rest/v1/cyber_deck?id=eq.' . $id, [ 'is_active' => $state ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( [ 'toggled' => true, 'state' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — delete                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $id = intval( $_POST['id'] ?? 0 );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' ); return;
        }

        $result = $this->supa( 'DELETE', '/rest/v1/cyber_deck?id=eq.' . $id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( [ 'deleted' => true ] );
    }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE HELPER                                                   */
    /* ---------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [] ): array {
        $url  = $this->supabase_url . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=minimal',
            ],
            'timeout' => 15,
        ];

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        if ( in_array( $method, [ 'PATCH', 'DELETE' ], true ) ) {
            $args['headers']['Prefer'] = 'return=representation';
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code >= 400 ) {
            return [ 'error' => $data['message'] ?? "HTTP $code" ];
        }

        return is_array( $data ) ? $data : [];
    }

    /* ---------------------------------------------------------------- */
    /*  ENUM HELPERS                                                      */
    /* ---------------------------------------------------------------- */

    private function categories(): array {
        return [ 'action', 'spell', 'gear', 'event', 'scenario', 'effect', 'trap', 'support' ];
    }

    private function types(): array {
        return [ 'attack', 'defense', 'utility', 'passive', 'reaction', 'movement', 'social', 'craft' ];
    }

    private function rarities(): array {
        return [ 'common', 'uncommon', 'rare', 'epic', 'legendary', 'unique' ];
    }
}
