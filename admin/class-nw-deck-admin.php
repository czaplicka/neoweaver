<?php
/**
 * NeoWeaver Admin Panel — Deck Cards (cyber_deck)
 *
 * Columns: id, name, description, deck_category, type, mechanic,
 *          mechanic_goal, cost_label, cost_number, effect, bonus,
 *          ai_instruction, gm, tags, requirement_tags, denied_tags,
 *          required_item_tags, required_location_tags, denied_location_tags,
 *          requirement_description, time_cost_minutes, cooldown_messages,
 *          entropy_on_fail, rarity, level, xp_current, xp_to_next,
 *          is_leveling, is_disposable, is_active, sound_effect, img_url,
 *          created_at, class_id
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
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
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
                        <select id="nw-filter-category" class="nw-select-filter">
                            <option value="">All categories</option>
                            <?php foreach ( $this->categories() as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="nw-filter-rarity" class="nw-select-filter">
                            <option value="">All rarities</option>
                            <?php foreach ( $this->rarities() as $r ) : ?>
                            <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Card</button>
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
                        <th>Name</th>
                        <th>Category / Type</th>
                        <th>Tags</th>
                        <th>Rarity / Level</th>
                        <th>Cost / Time</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-deck-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading cards…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Card</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
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
                                    <textarea id="nw-field-description" name="description" rows="2" placeholder="Card description visible to players…"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Effect</label>
                                    <textarea id="nw-field-effect" name="effect" rows="2" placeholder="What happens when this card is played…"></textarea>
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
                            <div class="nw-section-label">Tags <span class="nw-hint" style="text-transform:none;letter-spacing:0">(comma-separated → JSON array)</span></div>
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
                                    <textarea id="nw-field-requirement_description" name="requirement_description" rows="2" placeholder="Human-readable requirement description…"></textarea>
                                </div>
                            </div>

                            <!-- AI / GM -->
                            <div class="nw-section-label">AI &amp; GM Notes</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>AI Instruction <span class="nw-hint">(sent to the game AI)</span></label>
                                    <textarea id="nw-field-ai_instruction" name="ai_instruction" rows="3" placeholder="Instructions for the AI game engine…"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>GM Note <span class="nw-hint">(visible only to GM)</span></label>
                                    <textarea id="nw-field-gm" name="gm" rows="2" placeholder="Private Game Master note…"></textarea>
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
                                    <input type="url" id="nw-field-img_url" name="img_url" placeholder="https://…">
                                    <div id="nw-img-preview-wrap" style="display:none;margin-top:6px;">
                                        <img id="nw-img-preview" src="" alt="preview" style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Sound Effect URL</label>
                                    <input type="url" id="nw-field-sound_effect" name="sound_effect" placeholder="https://…/card.mp3">
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
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $category = sanitize_text_field( $_POST['filter_category'] ?? '' );
        $rarity   = sanitize_text_field( $_POST['filter_rarity']   ?? '' );

        $qs = 'cyber_deck?select=id,name,description,deck_category,type,mechanic,mechanic_goal,cost_label,cost_number,effect,bonus,ai_instruction,gm,tags,requirement_tags,denied_tags,required_item_tags,required_location_tags,denied_location_tags,requirement_description,time_cost_minutes,cooldown_messages,entropy_on_fail,rarity,level,xp_current,xp_to_next,is_leveling,is_disposable,is_active,sound_effect,img_url,class_id&order=name.asc';
        if ( $category ) $qs .= '&deck_category=eq.' . urlencode( $category );
        if ( $rarity   ) $qs .= '&rarity=eq.'        . urlencode( $rarity );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['card'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        // Parse bonus JSON — accept both raw JSON and empty string
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

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['card_id']   ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_deck?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_deck', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['card_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_deck?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel{font-family:'Chakra Petch',monospace;color:#e0e0e0}.nw-panel *{box-sizing:border-box}
.nw-panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #2a2a2a;margin-bottom:16px}
.nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}.nw-panel-subtitle{color:#555;font-weight:400;font-size:18px;margin-left:4px}
.nw-header-actions{display:flex;align-items:center;gap:8px}
.nw-filter-bar{display:flex;gap:6px}
.nw-select-filter{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#ccc;padding:6px 10px;cursor:pointer}
.nw-select-filter:focus{outline:none;border-color:#adff00}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-btn-danger{background:transparent;color:#ff4444;border-color:#3a1111}.nw-btn-danger:hover{background:#2a0000;border-color:#ff4444}
.nw-stats-bar{display:flex;gap:10px;margin-bottom:16px}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}.nw-pill-inactive strong{color:#ff6b35}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}.nw-col-img{width:50px}
.nw-card-img{width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a}
.nw-card-img-placeholder{width:40px;height:40px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:20px}
.nw-item-name{font-weight:600;color:#fff}.nw-item-sub{font-size:11px;color:#555;margin-top:2px}
.nw-type-badge{font-size:10px;padding:2px 8px;border-radius:3px;background:#1e1e1e;border:1px solid #2e2e2e;color:#aaa;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.nw-cat-action{border-color:#3a5200;color:#adff00}.nw-cat-magic{border-color:#004e60;color:#00d4ff}.nw-cat-equipment{border-color:#5c4300;color:#ffb703}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-rarity-common{color:#aaa}.nw-rarity-uncommon{color:#4fc874}.nw-rarity-rare{color:#4da6ff}
.nw-rarity-epic{color:#b04dff}.nw-rarity-legendary{color:#ff9f00}
.nw-rarity-badge{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.nw-level-badge{font-size:10px;color:#888;margin-top:3px}
.nw-cost-val{color:#adff00;font-weight:700;font-size:12px}.nw-time-val{color:#888;font-size:11px;margin-top:2px}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-toggle-blue.nw-toggle-slider{background:#2a2a2a}
.nw-toggle input:checked+.nw-toggle-blue{background:#001a3a;border-color:#4da6ff}
.nw-toggle input:checked+.nw-toggle-blue::before{background:#4da6ff;transform:translateX(18px)}
.nw-toggle-orange.nw-toggle-slider{background:#2a2a2a}
.nw-toggle input:checked+.nw-toggle-orange{background:#2a1600;border-color:#ffb703}
.nw-toggle input:checked+.nw-toggle-orange::before{background:#ffb703;transform:translateX(18px)}
.nw-row-inactive td:not(:last-child):not(:first-child){opacity:.4}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-loading-row td{text-align:center;padding:32px;color:#555}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:800px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}
.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;align-items:center;gap:10px;position:sticky;bottom:0;background:#111}
.nw-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #1e2e00}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-center{align-items:flex-start}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field input[type="number"],.nw-field input[type="url"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-select option{background:#111}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce=$('#nw-nonce').val(), all=[];
    var rarityColors={common:'#aaa',uncommon:'#4fc874',rare:'#4da6ff',epic:'#b04dff',legendary:'#ff9f00'};

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$('#nw-notice');el.attr('class','nw-notice nw-notice-'+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsArr(t){if(!t)return[];if(Array.isArray(t))return t;try{var a=JSON.parse(t);return Array.isArray(a)?a:[];}catch(e){return[];}}
    function tagsStr(t){return tagsArr(t).join(', ');}
    function updateStats(d){var a=d.filter(function(i){return i.is_active!==false;}).length;$('#nw-total').text(d.length);$('#nw-active').text(a);$('#nw-inactive').text(d.length-a);}

    function renderTable(data){
        var tbody=$('#nw-deck-tbody');
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No cards found.</td></tr>');return;}
        tbody.html(data.map(function(card){
            var tags=tagsArr(card.tags);
            var tagsH=tags.slice(0,3).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>3?'<span class="nw-tag">+'+(tags.length-3)+'</span>':'');
            var active=card.is_active!==false;
            var imgH=card.img_url?'<img src="'+esc(card.img_url)+'" class="nw-card-img" loading="lazy" onerror="this.style.display=\'none\'">':
                '<div class="nw-card-img-placeholder">🃏</div>';
            var rc=rarityColors[card.rarity]||'#aaa';
            var rarityH=card.rarity?'<span class="nw-rarity-badge" style="color:'+rc+'">'+esc(card.rarity)+'</span>':'—';
            var catClass='nw-cat-'+(card.deck_category||'action');
            var catH='<span class="nw-type-badge '+catClass+'">'+esc(card.deck_category||'')+'</span>'+(card.type?'<br><span class="nw-type-badge" style="margin-top:3px;display:inline-block">'+esc(card.type)+'</span>':'');
            var costH='<span class="nw-cost-val">'+(card.cost_label||'cost')+': '+(card.cost_number||0)+'</span>'+(card.time_cost_minutes?'<div class="nw-time-val">⏱ '+card.time_cost_minutes+'m</div>':'');
            return'<tr data-id="'+card.id+'" class="'+(active?'':'nw-row-inactive')+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-item-name">'+esc(card.name)+'</div>'+(card.mechanic?'<div class="nw-item-sub">'+esc(card.mechanic)+'</div>':'')+'</td>'
                +'<td>'+catH+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td>'+rarityH+'<div class="nw-level-badge">Lv '+esc(card.level||1)+'</div></td>'
                +'<td>'+costH+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+card.id+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+card.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function loadAll(){
        var fc=$('#nw-filter-category').val(), fr=$('#nw-filter-rarity').val();
        $('#nw-deck-tbody').html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:'nw_deck_get_all',nonce:nonce,filter_category:fc,filter_rarity:fr},function(res){
            if(!res.success){notice('Error: '+res.data,'error');return;}
            all=res.data||[];renderTable(all);updateStats(all);
        }).fail(function(){notice('Request failed.','error');});
    }

    /* filters */
    $('#nw-filter-category,#nw-filter-rarity').on('change',loadAll);

    /* toggle active */
    $(document).on('change','.nw-active-toggle',function(){
        var id=$(this).data('id'),val=$(this).is(':checked'),row=$(this).closest('tr');
        $.post(ajaxurl,{action:'nw_deck_toggle',nonce:nonce,card_id:id,is_active:val?1:0},function(res){
            if(res.success){row.toggleClass('nw-row-inactive',!val);all=all.map(function(i){if(i.id===id)i.is_active=val;return i;});updateStats(all);notice((val?'Activated':'Deactivated')+'.','success');}
            else{notice('Toggle failed: '+res.data,'error');row.find('.nw-active-toggle').prop('checked',!val);}
        });
    });

    /* image/audio previews */
    $('#nw-field-img_url').on('input',function(){var v=$(this).val().trim();if(v){$('#nw-img-preview').attr('src',v);$('#nw-img-preview-wrap').show();}else{$('#nw-img-preview-wrap').hide();}});
    $('#nw-field-sound_effect').on('input',function(){var v=$(this).val().trim();if(v){$('#nw-audio-preview').attr('src',v);$('#nw-sound-wrap').show();}else{$('#nw-sound-wrap').hide();}});

    function openModal(id){
        $('#nw-deck-form')[0].reset();
        $('#nw-field-id').val('');
        $('#nw-img-preview-wrap,#nw-sound-wrap').hide();
        if(id){
            var c=all.find(function(x){return x.id===id;});
            if(c){
                $('#nw-field-id').val(c.id);
                $('#nw-field-name').val(c.name||'');
                $('#nw-field-description').val(c.description||'');
                $('#nw-field-deck_category').val(c.deck_category||'action');
                $('#nw-field-type').val(c.type||'');
                $('#nw-field-rarity').val(c.rarity||'common');
                $('#nw-field-mechanic').val(c.mechanic||'');
                $('#nw-field-mechanic_goal').val(c.mechanic_goal||'');
                $('#nw-field-cost_label').val(c.cost_label||'');
                $('#nw-field-cost_number').val(c.cost_number||0);
                $('#nw-field-effect').val(c.effect||'');
                $('#nw-field-bonus').val(c.bonus&&Object.keys(c.bonus).length?JSON.stringify(c.bonus):'');
                $('#nw-field-ai_instruction').val(c.ai_instruction||'');
                $('#nw-field-gm').val(c.gm||'');
                $('#nw-field-tags').val(tagsStr(c.tags));
                $('#nw-field-requirement_tags').val(tagsStr(c.requirement_tags));
                $('#nw-field-denied_tags').val(tagsStr(c.denied_tags));
                $('#nw-field-required_item_tags').val(tagsStr(c.required_item_tags));
                $('#nw-field-required_location_tags').val(tagsStr(c.required_location_tags));
                $('#nw-field-denied_location_tags').val(tagsStr(c.denied_location_tags));
                $('#nw-field-requirement_description').val(c.requirement_description||'');
                $('#nw-field-time_cost_minutes').val(c.time_cost_minutes||0);
                $('#nw-field-cooldown_messages').val(c.cooldown_messages||0);
                $('#nw-field-entropy_on_fail').val(c.entropy_on_fail||0);
                $('#nw-field-level').val(c.level||1);
                $('#nw-field-xp_current').val(c.xp_current||0);
                $('#nw-field-xp_to_next').val(c.xp_to_next||10);
                $('#nw-field-class_id').val(c.class_id||'');
                $('#nw-field-is_leveling').prop('checked',c.is_leveling!==false);
                $('#nw-field-is_disposable').prop('checked',c.is_disposable===true);
                $('#nw-field-is_active').prop('checked',c.is_active!==false);
                if(c.img_url){$('#nw-field-img_url').val(c.img_url);$('#nw-img-preview').attr('src',c.img_url);$('#nw-img-preview-wrap').show();}
                if(c.sound_effect){$('#nw-field-sound_effect').val(c.sound_effect);$('#nw-audio-preview').attr('src',c.sound_effect);$('#nw-sound-wrap').show();}
            }
            $('#nw-modal-title').text('Edit Card');
            $('#nw-save-label').text('Save Changes');
            $('#nw-delete-btn').show().data('id',id);
        } else {
            $('#nw-modal-title').text('New Card');
            $('#nw-save-label').text('Create Card');
            $('#nw-delete-btn').hide();
        }
        $('#nw-modal-overlay').fadeIn(150);
    }

    $('#nw-modal-close,#nw-cancel-btn').on('click',function(){$('#nw-modal-overlay').fadeOut(150);});
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))$('#nw-modal-overlay').fadeOut(150);});
    $(document).on('click','.nw-edit-btn',function(){openModal($(this).data('id'));});
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadAll);

    $('#nw-save-btn').on('click',function(){
        if(!$('#nw-field-name').val().trim()){notice('Name is required.','error');return;}
        var btn=$(this);btn.prop('disabled',true);$('#nw-save-label').text('Saving…');
        var fd={action:'nw_deck_save',nonce:nonce,'card':{}};
        $('#nw-deck-form').serializeArray().forEach(function(f){
            if(['is_active','is_leveling','is_disposable'].indexOf(f.name)===-1)
                fd['card'][f.name]=f.value;
        });
        fd['card'].is_active     = $('#nw-field-is_active').is(':checked')     ? 1 : 0;
        fd['card'].is_leveling   = $('#nw-field-is_leveling').is(':checked')   ? 1 : 0;
        fd['card'].is_disposable = $('#nw-field-is_disposable').is(':checked') ? 1 : 0;
        $.post(ajaxurl,fd,function(res){
            btn.prop('disabled',false);$('#nw-save-label').text('Save Card');
            if(res.success){notice('Card saved!','success');$('#nw-modal-overlay').fadeOut(150);loadAll();}
            else{notice('Error: '+(res.data||'Unknown'),'error');}
        }).fail(function(){btn.prop('disabled',false);$('#nw-save-label').text('Save Card');notice('Request failed.','error');});
    });

    $('#nw-delete-btn').on('click',function(){
        var id=$(this).data('id');
        if(!id||!confirm('Delete this card permanently?'))return;
        $.post(ajaxurl,{action:'nw_deck_delete',nonce:nonce,card_id:id},function(res){
            if(res.success){notice('Card deleted.','success');$('#nw-modal-overlay').fadeOut(150);loadAll();}
            else{notice('Delete failed: '+res.data,'error');}
        });
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Deck_Admin();
