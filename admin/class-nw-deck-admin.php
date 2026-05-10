<?php
/**
 * NeoWeaver — Deck Admin
 *
 * Manages cyber_deck table (cards):
 *   id, name, deck_category, type, rarity, description, effect,
 *   level, action_cost, time_cost, duration, target, range,
 *   hp_cost, mana_cost, stamina_cost, gold_cost, tags, img_url,
 *   gm_notes, is_active.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Deck_Admin {

    /* ---------------------------------------------------------------- */
    /*  Single source of truth for enum values                           */
    /* ---------------------------------------------------------------- */

    private const CATEGORIES = [ 'action', 'spell', 'trap', 'event', 'item', 'special' ];
    private const TYPES      = [ 'attack', 'defense', 'support', 'utility', 'movement', 'other' ];
    private const RARITIES   = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];

    /* ---------------------------------------------------------------- */
    /*  Bootstrap                                                         */
    /* ---------------------------------------------------------------- */

    public function __construct() {
        add_action( 'admin_menu',       [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX — admin-only
        add_action( 'wp_ajax_nw_deck_list',   [ $this, 'ajax_list' ] );
        add_action( 'wp_ajax_nw_deck_get',    [ $this, 'ajax_get' ] );
        add_action( 'wp_ajax_nw_deck_save',   [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_nw_deck_toggle', [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_nw_deck_delete', [ $this, 'ajax_delete' ] );
    }

    /* ---------------------------------------------------------------- */
    /*  Menu                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            'neoweaver',
            __( 'NeoWeaver — Deck', 'neoweaver' ),
            __( 'Deck', 'neoweaver' ),
            'manage_options',
            'neoweaver-deck',
            [ $this, 'render_page' ]
        );
    }

    /* ---------------------------------------------------------------- */
    /*  Assets                                                            */
    /* ---------------------------------------------------------------- */

    public function enqueue_assets( string $hook ): void {
        if ( 'neoweaver_page_neoweaver-deck' !== $hook ) return;

        wp_enqueue_script(
            'nw-deck-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/deck-admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'nw-deck-admin', 'NW_Deck', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'neoweaver_deck' ),
        ] );
    }

    /* ---------------------------------------------------------------- */
    /*  Supabase helper                                                   */
    /* ---------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
        $cfg = NW_Supabase_Config::get();
        $url = rtrim( $cfg['url'], '/' ) . $endpoint;

        $headers = array_merge( [
            'apikey'        => $cfg['key'],
            'Authorization' => 'Bearer ' . $cfg['key'],
            'Content-Type'  => 'application/json',
        ], $extra_headers );

        $args = [
            'method'  => strtoupper( $method ),
            'headers' => $headers,
            'timeout' => 15,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            return [ 'error' => $data['message'] ?? "HTTP $code" ];
        }

        return is_array( $data ) ? $data : [];
    }

    /* ---------------------------------------------------------------- */
    /*  Page render                                                        */
    /* ---------------------------------------------------------------- */

    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'NeoWeaver — Deck', 'neoweaver' ); ?></h1>

            <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
                <select id="nw-deck-filter-category">
                    <option value=""><?php esc_html_e( 'All categories', 'neoweaver' ); ?></option>
                    <?php foreach ( self::CATEGORIES as $c ) : ?>
                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="nw-deck-filter-type">
                    <option value=""><?php esc_html_e( 'All types', 'neoweaver' ); ?></option>
                    <?php foreach ( self::TYPES as $t ) : ?>
                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html(ucfirst($t)); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="nw-deck-filter-rarity">
                    <option value=""><?php esc_html_e( 'All rarities', 'neoweaver' ); ?></option>
                    <?php foreach ( self::RARITIES as $r ) : ?>
                        <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="nw-deck-filter-active">
                    <option value=""><?php esc_html_e( 'All statuses', 'neoweaver' ); ?></option>
                    <option value="1"><?php esc_html_e( 'Active', 'neoweaver' ); ?></option>
                    <option value="0"><?php esc_html_e( 'Inactive', 'neoweaver' ); ?></option>
                </select>

                <input type="text" id="nw-deck-search" placeholder="<?php esc_attr_e( 'Search name…', 'neoweaver' ); ?>" style="width:200px;" class="regular-text">
                <button class="button" id="nw-deck-filter-btn"><?php esc_html_e( 'Filter', 'neoweaver' ); ?></button>
                <button class="button button-primary" id="nw-deck-add-btn"><?php esc_html_e( '+ Add Card', 'neoweaver' ); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="nw-deck-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Level', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Active', 'neoweaver' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'neoweaver' ); ?></th>
                    </tr>
                </thead>
                <tbody id="nw-deck-tbody">
                    <tr><td colspan="7"><?php esc_html_e( 'Loading…', 'neoweaver' ); ?></td></tr>
                </tbody>
            </table>

            <!-- Modal -->
            <div id="nw-deck-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:9999; overflow-y:auto;">
                <div style="background:#fff; margin:40px auto; padding:24px; max-width:700px; border-radius:6px; position:relative;">
                    <button id="nw-deck-modal-close" style="position:absolute; top:12px; right:12px; background:none; border:none; font-size:20px; cursor:pointer;">✕</button>
                    <h2 id="nw-deck-modal-title"><?php esc_html_e( 'Card', 'neoweaver' ); ?></h2>

                    <input type="hidden" id="nw-deck-id">

                    <table class="form-table">
                        <tr>
                            <th><label for="nw-deck-name"><?php esc_html_e( 'Name *', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-category"><?php esc_html_e( 'Category', 'neoweaver' ); ?></label></th>
                            <td>
                                <select id="nw-deck-category">
                                    <?php foreach ( self::CATEGORIES as $c ) : ?>
                                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-type"><?php esc_html_e( 'Type', 'neoweaver' ); ?></label></th>
                            <td>
                                <select id="nw-deck-type">
                                    <?php foreach ( self::TYPES as $t ) : ?>
                                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html(ucfirst($t)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-rarity"><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></label></th>
                            <td>
                                <select id="nw-deck-rarity">
                                    <?php foreach ( self::RARITIES as $r ) : ?>
                                        <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-description"><?php esc_html_e( 'Description', 'neoweaver' ); ?></label></th>
                            <td><textarea id="nw-deck-description" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-effect"><?php esc_html_e( 'Effect', 'neoweaver' ); ?></label></th>
                            <td><textarea id="nw-deck-effect" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-level"><?php esc_html_e( 'Level', 'neoweaver' ); ?></label></th>
                            <td><input type="number" id="nw-deck-level" min="1" value="1" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Costs', 'neoweaver' ); ?></th>
                            <td>
                                <label><?php esc_html_e( 'Action:', 'neoweaver' ); ?> <input type="number" id="nw-deck-action-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
                                <label><?php esc_html_e( 'HP:', 'neoweaver' ); ?> <input type="number" id="nw-deck-hp-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
                                <label><?php esc_html_e( 'Mana:', 'neoweaver' ); ?> <input type="number" id="nw-deck-mana-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
                                <label><?php esc_html_e( 'Stamina:', 'neoweaver' ); ?> <input type="number" id="nw-deck-stamina-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
                                <label><?php esc_html_e( 'Gold:', 'neoweaver' ); ?> <input type="number" id="nw-deck-gold-cost" min="0" value="0" style="width:60px;"></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-time-cost"><?php esc_html_e( 'Time Cost', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-time-cost" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-duration"><?php esc_html_e( 'Duration', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-duration" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-target"><?php esc_html_e( 'Target', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-target" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-range"><?php esc_html_e( 'Range', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-range" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-tags"><?php esc_html_e( 'Tags (comma-separated)', 'neoweaver' ); ?></label></th>
                            <td><input type="text" id="nw-deck-tags" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-img-url"><?php esc_html_e( 'Image URL', 'neoweaver' ); ?></label></th>
                            <td><input type="url" id="nw-deck-img-url" class="large-text"></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-gm-notes"><?php esc_html_e( 'GM Notes', 'neoweaver' ); ?></label></th>
                            <td><textarea id="nw-deck-gm-notes" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="nw-deck-is-active"><?php esc_html_e( 'Active', 'neoweaver' ); ?></label></th>
                            <td><input type="checkbox" id="nw-deck-is-active" checked></td>
                        </tr>
                    </table>

                    <p>
                        <button class="button button-primary" id="nw-deck-save-btn"><?php esc_html_e( 'Save', 'neoweaver' ); ?></button>
                        <button class="button" id="nw-deck-cancel-btn"><?php esc_html_e( 'Cancel', 'neoweaver' ); ?></button>
                        <span id="nw-deck-msg" style="margin-left:12px;"></span>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — list                                                       */
    /* ---------------------------------------------------------------- */

    public function ajax_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $category = sanitize_text_field( $_POST['category'] ?? '' );
        $type     = sanitize_text_field( $_POST['type']     ?? '' );
        $rarity   = sanitize_text_field( $_POST['rarity']   ?? '' );
        $search   = sanitize_text_field( $_POST['search']   ?? '' );
        $active   = sanitize_text_field( $_POST['active'] ?? '' );

        $endpoint = '/rest/v1/cyber_deck?select=*&order=name.asc';
        if ( $category ) $endpoint .= '&deck_category=eq.' . urlencode( $category );
        if ( $type )     $endpoint .= '&type=eq.'          . urlencode( $type );
        if ( $rarity )   $endpoint .= '&rarity=eq.'        . urlencode( $rarity );
        if ( $active !== '' ) $endpoint .= '&is_active=eq.' . ( $active ? 'true' : 'false' );
        if ( $search )   $endpoint .= '&name=ilike.*'      . urlencode( $search ) . '*';

        $result = $this->supa( 'GET', $endpoint, [], [ 'Range' => '0-199' ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( $result );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — get single                                                 */
    /* ---------------------------------------------------------------- */

    public function ajax_get(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }
        check_ajax_referer( 'neoweaver_deck', 'nonce' );

        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) { wp_send_json_error( 'Invalid ID.' ); return; }

        $result = $this->supa( 'GET', '/rest/v1/cyber_deck?id=eq.' . $id . '&select=*' );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] ); return;
        }

        wp_send_json_success( $result[0] ?? null );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX — save (create / update)                                     */
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

        // Validate enum fields against the single source of truth.
        $category = sanitize_text_field( $_POST['deck_category'] ?? '' );
        $type     = sanitize_text_field( $_POST['type']          ?? '' );
        $rarity   = sanitize_text_field( $_POST['rarity']        ?? '' );

        if ( $category && ! in_array( $category, self::CATEGORIES, true ) ) {
            wp_send_json_error( 'Invalid category.' ); return;
        }
        if ( $type && ! in_array( $type, self::TYPES, true ) ) {
            wp_send_json_error( 'Invalid type.' ); return;
        }
        if ( $rarity && ! in_array( $rarity, self::RARITIES, true ) ) {
            wp_send_json_error( 'Invalid rarity.' ); return;
        }

        $payload = [
            'name'          => $name,
            'deck_category' => $category ?: self::CATEGORIES[0],
            'type'          => $type,
            'rarity'        => $rarity ?: self::RARITIES[0],
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
            'is_active'     => filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN ),
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
        $state = filter_var( $_POST['state'] ?? false, FILTER_VALIDATE_BOOLEAN );

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
}
new NeoWeaver_Deck_Admin();
