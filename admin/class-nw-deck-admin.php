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
            'NeoWeaver \u2014 Deck Cards',
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

                            <!-- Mechanic -->
                            <div class="nw-section-label">Mechanic</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Mechanic</label>
                                    <input type="text" id="nw-field-mechanic" name="mechanic" placeholder="e.g. roll, choose, discard">
                                </div>
                                <div class="nw-field">
                                    <label>Mechanic Goal</label>
                                    <input type="text" id="nw-field-mechanic_goal" name="mechanic_goal" placeholder="e.g. score 15 or higher">
                                </div>
                                <div class="nw-field">
                                    <label>Cost Label</label>
                                    <input type="text" id="nw-field-cost_label" name="cost_label" placeholder="e.g. Energy, Credits">
                                </div>
                                <div class="nw-field">
                                    <label>Cost Number</label>
                                    <input type="number" id="nw-field-cost_number" name="cost_number" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Time Cost (minutes)</label>
                                    <input type="number" id="nw-field-time_cost_minutes" name="time_cost_minutes" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Cooldown (messages)</label>
                                    <input type="number" id="nw-field-cooldown_messages" name="cooldown_messages" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Entropy on Fail</label>
                                    <input type="number" id="nw-field-entropy_on_fail" name="entropy_on_fail" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Bonus <span class="nw-hint">(JSON object)</span></label>
                                    <input type="text" id="nw-field-bonus" name="bonus" placeholder='{"hp": 10}'>
                                </div>
                            </div>

                            <!-- Tags -->
                            <div class="nw-section-label">Tags <span class="nw-hint" style="text-transform:none;letter-spacing:0">(comma-separated &rarr; JSON array)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Tags</label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. combat, neural, quick">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Requirement Tags <span class="nw-hint">(player must have ALL)</span></label>
                                    <input type="text" id="nw-field-requirement_tags" name="requirement_tags" placeholder="e.g. hacker, augmented">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Denied Tags <span class="nw-hint">(player must NOT have any)</span></label>
                                    <input type="text" id="nw-field-denied_tags" name="denied_tags" placeholder="e.g. robot, synthetic">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Required Item Tags</label>
                                    <input type="text" id="nw-field-required_item_tags" name="required_item_tags" placeholder="e.g. weapon, ranged">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Required Location Tags</label>
                                    <input type="text" id="nw-field-required_location_tags" name="required_location_tags" placeholder="e.g. urban, indoor">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Denied Location Tags</label>
                                    <input type="text" id="nw-field-denied_location_tags" name="denied_location_tags" placeholder="e.g. underwater, space">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Requirement Description</label>
                                    <textarea id="nw-field-requirement_description" name="requirement_description" rows="2" placeholder="Human-readable requirement description&hellip;"></textarea>
                                </div>
                            </div>

                            <!-- AI / GM -->
                            <div class="nw-section-label">AI &amp; GM Notes</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>AI Instruction <span class="nw-hint">(sent to the game AI)</span></label>
                                    <textarea id="nw-field-ai_instruction" name="ai_instruction" rows="3" placeholder="Instructions for the AI game engine&hellip;"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>GM Note <span class="nw-hint">(visible only to GM)</span></label>
                                    <textarea id="nw-field-gm" name="gm" rows="2" placeholder="Private Game Master note&hellip;"></textarea>
                                </div>
                            </div>

                            <!-- Progression -->
                            <div class="nw-section-label">Progression</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Level</label>
                                    <input type="number" id="nw-field-level" name="level" min="1" max="10" value="1">
                                </div>
                                <div class="nw-field">
                                    <label>XP Current</label>
                                    <input type="number" id="nw-field-xp_current" name="xp_current" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>XP to Next</label>
                                    <input type="number" id="nw-field-xp_to_next" name="xp_to_next" min="0" value="10">
                                </div>
                                <div class="nw-field">
                                    <label>Class ID <span class="nw-hint">(UUID or leave blank)</span></label>
                                    <input type="text" id="nw-field-class_id" name="class_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                                </div>
                                <div class="nw-field nw-field-center">
                                    <label>Is Leveling?</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_leveling" name="is_leveling" checked>
                                        <span class="nw-toggle-slider nw-toggle-blue"></span>
                                    </label>
                                </div>
                                <div class="nw-field nw-field-center">
                                    <label>Is Disposable?</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_disposable" name="is_disposable">
                                        <span class="nw-toggle-slider nw-toggle-orange"></span>
                                    </label>
                                </div>
                            </div>

                            <!-- Media -->
                            <div class="nw-section-label">Media</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-img_url" name="img_url" placeholder="https://&hellip;">
                                    <div id="nw-img-preview-wrap" style="display:none;margin-top:6px;">
                                        <img id="nw-img-preview" src="" alt="preview" style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Sound Effect URL</label>
                                    <input type="url" id="nw-field-sound_effect" name="sound_effect" placeholder="https://&hellip;/card.mp3">
                                    <div id="nw-sound-wrap" style="display:none;margin-top:6px;">
                                        <audio id="nw-audio-preview" controls style="width:100%;height:32px;"></audio>
                                    </div>
                                </div>
                            </div>

                            <!-- Visibility -->
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
                    </div><!-- .nw-modal-body -->
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Card</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_deck' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  DATA LISTS                                                        */
    /* ---------------------------------------------------------------- */

    private function categories(): array {
        return ['action', 'magic', 'equipment'];
    }
    private function types(): array {
        return ['Action', 'Spell', 'Gear', 'Trap', 'Support', 'Reaction', 'Passive'];
    }
    private function rarities(): array {
        return ['common', 'uncommon', 'rare', 'epic', 'legendary'];
    }

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
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_deck', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $category = sanitize_text_field( $_POST['filter_category'] ?? '' );
        $rarity   = sanitize_text_field( $_POST['filter_rarity']   ?? '' );
        $type     = sanitize_text_field( $_POST['filter_type']     ?? '' );

        $qs = 'cyber_deck?select=id,name,description,deck_category,type,mechanic,mechanic_goal,cost_label,cost_number,effect,bonus,ai_instruction,gm,tags,requirement_tags,denied_tags,required_item_tags,required_location_tags,denied_location_tags,requirement_description,time_cost_minutes,cooldown_messages,entropy_on_fail,rarity,level,xp_current,xp_to_next,is_leveling,is_disposable,is_active,sound_effect,img_url,class_id&order=name.asc';
        if ( $category ) $qs .= '&deck_category=eq.' . urlencode( $category );
        if ( $rarity   ) $qs .= '&rarity=eq.'        . urlencode( $rarity );
        if ( $type     ) $qs .= '&type=ilike.'        . urlencode( $type );

        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  HELPERS: parse tags field                                         */
    /* ---------------------------------------------------------------- */

    private function parse_tags( string $raw ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_deck', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

        $raw = $_POST['card'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        $bonus_raw = sanitize_text_field( $raw['bonus'] ?? '' );
        $bonus     = ( $bonus_raw && $bonus_raw !== '{}' ) ? json_decode( $bonus_raw, true ) : [];
        if ( ! is_array( $bonus ) ) $bonus = [];

        $payload = [
            'name'                     => sanitize_text_field(     $raw['name']                     ?? '' ),
            'description'              => sanitize_textarea_field( $raw['description']              ?? '' ) ?: null,
            'deck_category'            => sanitize_text_field(     $raw['deck_category']            ?? 'action' ),
            'type'                     => sanitize_text_field(     $raw['type']                     ?? 'Action' ),
            'mechanic'                 => sanitize_text_field(     $raw['mechanic']                 ?? '' ) ?: null,
            'mechanic_goal'            => sanitize_text_field(     $raw['mechanic_goal']            ?? '' ) ?: null,
            'cost_label'               => sanitize_text_field(     $raw['cost_label']               ?? '' ) ?: null,
            'cost_number'              => (int)  ( $raw['cost_number']            ?? 0 ),
            'effect'                   => sanitize_textarea_field( $raw['effect']                   ?? '' ) ?: null,
            'bonus'                    => $bonus,
            'ai_instruction'           => sanitize_textarea_field( $raw['ai_instruction']           ?? '' ) ?: null,
            'gm'                       => sanitize_textarea_field( $raw['gm']                       ?? '' ) ?: null,
            'tags'                     => $this->parse_tags( sanitize_text_field( $raw['tags']                     ?? '' ) ),
            'requirement_tags'         => $this->parse_tags( sanitize_text_field( $raw['requirement_tags']         ?? '' ) ),
            'denied_tags'              => $this->parse_tags( sanitize_text_field( $raw['denied_tags']              ?? '' ) ),
            'required_item_tags'       => $this->parse_tags( sanitize_text_field( $raw['required_item_tags']       ?? '' ) ),
            'required_location_tags'   => $this->parse_tags( sanitize_text_field( $raw['required_location_tags']   ?? '' ) ),
            'denied_location_tags'     => $this->parse_tags( sanitize_text_field( $raw['denied_location_tags']     ?? '' ) ),
            'requirement_description'  => sanitize_textarea_field( $raw['requirement_description']  ?? '' ) ?: null,
            'time_cost_minutes'        => (int)  ( $raw['time_cost_minutes']      ?? 0 ),
            'cooldown_messages'        => (int)  ( $raw['cooldown_messages']      ?? 0 ),
            'entropy_on_fail'          => (int)  ( $raw['entropy_on_fail']        ?? 0 ),
            'rarity'                   => sanitize_text_field( $raw['rarity']     ?? 'common' ),
            'level'                    => max( 1, min( 10, (int) ( $raw['level']  ?? 1 ) ) ),
            'xp_current'               => (int)  ( $raw['xp_current']             ?? 0 ),
            'xp_to_next'               => (int)  ( $raw['xp_to_next']             ?? 10 ),
            'sound_effect'             => esc_url_raw( $raw['sound_effect']       ?? '' ) ?: null,
            'img_url'                  => esc_url_raw( $raw['img_url']            ?? '' ) ?: null,
            'class_id'                 => sanitize_text_field( $raw['class_id']   ?? '' ) ?: null,
            'is_leveling'              => filter_var( $raw['is_leveling']    ?? true,  FILTER_VALIDATE_BOOLEAN ),
            'is_disposable'            => filter_var( $raw['is_disposable']  ?? false, FILTER_VALIDATE_BOOLEAN ),
            'is_active'                => filter_var( $raw['is_active']      ?? true,  FILTER_VALIDATE_BOOLEAN ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_deck?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_deck', $payload );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: TOGGLE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_deck', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id    = sanitize_text_field( $_POST['card_id']   ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'PATCH', 'cyber_deck?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_deck', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = sanitize_text_field( $_POST['card_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'DELETE', 'cyber_deck?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_Deck_Admin();
