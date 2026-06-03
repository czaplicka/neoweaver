<?php
/**
 * NeoWeaver — Admin: Class Starting Cards
 *
 * Plik:    includes/admin/class-starting-cards.php
 * Dołącz:  require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-starting-cards.php';
 *
 * Zależy od: tw_supabase_get(), tw_supabase_get_admin(),
 *            tw_supabase_request(), tw_supabase_rpc() (compat).
 */

defined( 'ABSPATH' ) || exit;

/* ════════════════════════════════════════════════════════════════════════════
   MENU
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_menu', 'nw_csc_register_menu' );
function nw_csc_register_menu(): void {
    add_submenu_page(
        'neoweaver',                            // parent slug – Twoje główne menu NW
        'Class Starting Cards',
        'Starting Cards',
        'manage_options',
        'nw-class-starting-cards',
        'nw_csc_render_page'
    );
}

/* ════════════════════════════════════════════════════════════════════════════
   ASSETS
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_enqueue_scripts', 'nw_csc_enqueue' );
function nw_csc_enqueue( string $hook ): void {
    if ( $hook !== 'neoweaver_page_nw-class-starting-cards' ) {
        return;
    }

    $base = plugin_dir_url( dirname( __DIR__ ) ); // katalog plugina

    wp_enqueue_style(
        'nw-class-starting-cards',
        $base . 'assets/css/admin/class-starting-cards.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'nw-class-starting-cards',
        $base . 'assets/js/admin/class-starting-cards.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );

    wp_localize_script( 'nw-class-starting-cards', 'NWCards', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'nw_csc_nonce' ),
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX — LOAD
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_nw_csc_load', 'nw_csc_ajax_load' );
function nw_csc_ajax_load(): void {
    nw_csc_verify_nonce();

    // Wiersze ze startkartami + joiny do klas i talii
    $rows = tw_supabase_get_admin(
        'cyber_class_starting_cards',
        [
            'select'   => '*,cyber_classes(id,name),cyber_deck(id,name)',
            'order'    => 'sort_order.asc,created_at.asc',
        ]
    );

    if ( is_wp_error( $rows ) ) {
        wp_send_json_error( $rows->get_error_message() );
    }

    // Klasy
    $classes = tw_supabase_get_admin( 'cyber_classes', [ 'select' => 'id,name', 'order' => 'name.asc' ] );
    if ( is_wp_error( $classes ) ) {
        wp_send_json_error( $classes->get_error_message() );
    }

    // Talia kart
    $deck = tw_supabase_get_admin( 'cyber_deck', [ 'select' => 'id,name', 'order' => 'name.asc' ] );
    if ( is_wp_error( $deck ) ) {
        wp_send_json_error( $deck->get_error_message() );
    }

    wp_send_json_success( [
        'rows'    => $rows,
        'classes' => $classes,
        'deck'    => $deck,
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX — SAVE (INSERT / UPDATE)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_nw_csc_save', 'nw_csc_ajax_save' );
function nw_csc_ajax_save(): void {
    nw_csc_verify_nonce();

    $id         = sanitize_text_field( $_POST['id']          ?? '' );
    $class_id   = sanitize_text_field( $_POST['class_id']    ?? '' );
    $card_id    = absint( $_POST['card_id']                  ?? 0  );
    $qty        = (int) ( $_POST['qty']                      ?? 1  );
    $pick_group = sanitize_text_field( $_POST['pick_group']  ?? '' );
    $pick_count = $_POST['pick_count'] !== '' ? (int) $_POST['pick_count'] : null;
    $sort_order = (int) ( $_POST['sort_order']               ?? 0  );
    $notes      = sanitize_textarea_field( $_POST['notes']   ?? '' );
    $is_optional= (bool) ( $_POST['is_optional']             ?? 0  );
    $is_active  = (bool) ( $_POST['is_active']               ?? 1  );

    /* Walidacje ----------------------------------------------------------- */
    if ( ! $class_id || ! $card_id ) {
        wp_send_json_error( 'Klasa i karta są wymagane.' );
    }

    if ( $qty < 1 || $qty > 10 ) {
        wp_send_json_error( 'Qty musi być między 1 a 10.' );
    }

    if ( $pick_count !== null ) {
        if ( $pick_group === '' ) {
            wp_send_json_error( 'Pick count wymaga ustawionej Pick Group.' );
        }
        if ( $pick_count < 1 ) {
            wp_send_json_error( 'Pick count musi być > 0.' );
        }
    }

    /* Payload dla Supabase ------------------------------------------------ */
    $payload = [
        'class_id'    => $class_id,
        'card_id'     => $card_id,
        'qty'         => $qty,
        'pick_group'  => $pick_group !== '' ? $pick_group : null,
        'pick_count'  => $pick_count,
        'sort_order'  => $sort_order,
        'notes'       => $notes !== '' ? $notes : null,
        'is_optional' => $is_optional,
        'is_active'   => $is_active,
    ];

    if ( $id ) {
        /* UPDATE ---------------------------------------------------------- */
        $res = tw_supabase_request(
            'PATCH',
            'cyber_class_starting_cards',
            array_merge( $payload, [ 'id' => 'eq.' . $id ] ),
            [ 'Prefer' => 'return=representation', 'select' => '*' ]
        );
    } else {
        /* INSERT ---------------------------------------------------------- */
        $res = tw_supabase_request(
            'POST',
            'cyber_class_starting_cards',
            $payload,
            [ 'Prefer' => 'return=representation', 'select' => '*' ]
        );
    }

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    // Supabase zwraca tablicę; bierz pierwszy element
    $saved = is_array( $res ) && isset( $res[0] ) ? $res[0] : ( $res['data'][0] ?? [] );
    wp_send_json_success( $saved );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX — QTY (szybka zmiana bez modala)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_nw_csc_qty', 'nw_csc_ajax_qty' );
function nw_csc_ajax_qty(): void {
    nw_csc_verify_nonce();

    $id  = sanitize_text_field( $_POST['id']  ?? '' );
    $qty = (int) ( $_POST['qty'] ?? 0 );

    if ( ! $id )            { wp_send_json_error( 'Brak ID.' ); }
    if ( $qty < 1 || $qty > 10 ) { wp_send_json_error( 'Qty poza zakresem.' ); }

    $res = tw_supabase_request(
        'PATCH',
        'cyber_class_starting_cards',
        [ 'qty' => $qty, 'id' => 'eq.' . $id ],
        [ 'Prefer' => 'return=minimal' ]
    );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    wp_send_json_success();
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX — DELETE
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_nw_csc_delete', 'nw_csc_ajax_delete' );
function nw_csc_ajax_delete(): void {
    nw_csc_verify_nonce();

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    if ( ! $id ) { wp_send_json_error( 'Brak ID.' ); }

    $res = tw_supabase_request(
        'DELETE',
        'cyber_class_starting_cards',
        [ 'id' => 'eq.' . $id ]
    );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    wp_send_json_success();
}

/* ════════════════════════════════════════════════════════════════════════════
   HELPER: nonce
   ════════════════════════════════════════════════════════════════════════════ */
function nw_csc_verify_nonce(): void {
    if ( ! check_ajax_referer( 'nw_csc_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorised.' );
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   WIDOK HTML
   ════════════════════════════════════════════════════════════════════════════ */
function nw_csc_render_page(): void {
    ?>
    <div class="nw-admin-wrap">

        <!-- Header -->
        <div class="nw-admin-header">
            <h1 class="nw-admin-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#adff00" stroke-width="2" style="vertical-align:middle;margin-right:8px;"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M8 4v16M2 9h20M2 15h20"/></svg>
                Class Starting Cards
            </h1>
            <div class="nw-admin-header-actions">
                <button id="nw-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
                    <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
                </button>
                <button id="nw-add-btn" class="nw-btn nw-btn-primary">
                    <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Card
                </button>
            </div>
        </div>

        <!-- Notice -->
        <div id="nw-notice" class="nw-notice" style="display:none;"></div>

        <!-- Stats -->
        <div class="nw-stats-row">
            <div class="nw-stat-pill">Total <strong id="nw-total">–</strong></div>
            <div class="nw-stat-pill">Active <strong id="nw-active">–</strong></div>
            <div class="nw-stat-pill nw-csc-pill-group">Groups <strong id="nw-groups">–</strong></div>
            <div class="nw-stat-pill">Optional <strong id="nw-optional">–</strong></div>
        </div>

        <!-- Filtry -->
        <div class="nw-filters-row">
            <input id="nw-search" type="search" class="nw-input" placeholder="Search class, card, group…" style="max-width:220px;">
            <select id="nw-filter-class"    class="nw-select"><option value="">All Classes</option></select>
            <select id="nw-filter-status"   class="nw-select">
                <option value="">All Statuses</option>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
            <select id="nw-filter-optional" class="nw-select">
                <option value="">Required + Optional</option>
                <option value="0">Required only</option>
                <option value="1">Optional only</option>
            </select>
            <button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none;">
                <i data-lucide="x" style="width:13px;height:13px;"></i> Clear
            </button>
        </div>

        <!-- Tabela -->
        <div class="nw-table-wrap">
            <table class="nw-table nw-csc-table">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Card</th>
                        <th>Qty</th>
                        <th>Pick Group</th>
                        <th>Pick #</th>
                        <th>Type</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="nw-csc-tbody">
                    <tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading…</td></tr>
                </tbody>
            </table>
        </div>

    </div><!-- .nw-admin-wrap -->

    <!-- ═══════════════════════ MODAL ═══════════════════════ -->
    <div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
        <div class="nw-modal">
            <div class="nw-modal-header">
                <h2 id="nw-modal-title" class="nw-modal-title">Add Starting Card</h2>
                <button id="nw-modal-close" class="nw-btn nw-btn-ghost nw-btn-sm" aria-label="Close">
                    <i data-lucide="x" style="width:16px;height:16px;"></i>
                </button>
            </div>

            <form id="nw-csc-form" novalidate>
                <input type="hidden" id="nw-field-id">

                <div class="nw-form-grid nw-grid-2">

                    <!-- Class -->
                    <div class="nw-field nw-field-full">
                        <label class="nw-label" for="nw-field-class_id">Class <span class="nw-req">*</span></label>
                        <select id="nw-field-class_id" name="class_id" class="nw-select" required>
                            <option value="">— Select class —</option>
                        </select>
                    </div>

                    <!-- Card -->
                    <div class="nw-field nw-field-full">
                        <label class="nw-label" for="nw-field-card_id">Card (cyber_deck) <span class="nw-req">*</span></label>
                        <select id="nw-field-card_id" name="card_id" class="nw-select" required>
                            <option value="">— Select card —</option>
                        </select>
                    </div>

                    <!-- Qty -->
                    <div class="nw-field">
                        <label class="nw-label" for="nw-field-qty">Qty (1–10)</label>
                        <input id="nw-field-qty" name="qty" type="number" min="1" max="10" value="1" class="nw-input" required>
                    </div>

                    <!-- Sort order -->
                    <div class="nw-field">
                        <label class="nw-label" for="nw-field-sort_order">Sort Order</label>
                        <input id="nw-field-sort_order" name="sort_order" type="number" value="0" class="nw-input">
                    </div>

                    <!-- Pick Group -->
                    <div class="nw-field">
                        <label class="nw-label" for="nw-field-pick_group">Pick Group
                            <span class="nw-label-hint">(opcjonalne)</span>
                        </label>
                        <input id="nw-field-pick_group" name="pick_group" type="text" class="nw-input" placeholder="np. starter-weapon">
                    </div>

                    <!-- Pick Count -->
                    <div class="nw-field">
                        <label class="nw-label" for="nw-field-pick_count">Pick Count
                            <span class="nw-label-hint">(wymaga Pick Group)</span>
                        </label>
                        <input id="nw-field-pick_count" name="pick_count" type="number" min="1" class="nw-input" placeholder="np. 2">
                    </div>

                    <p class="nw-field-help nw-field-full">
                        Pick Group + Pick Count = gracz wybiera <em>n</em> kart z tej grupy na start. Zostaw puste, jeśli karta jest zawsze przydzielana.
                    </p>

                    <!-- Notes -->
                    <div class="nw-field nw-field-full">
                        <label class="nw-label" for="nw-field-notes">Notes</label>
                        <textarea id="nw-field-notes" name="notes" class="nw-textarea" rows="2" placeholder="Notatki dla GM…"></textarea>
                    </div>

                    <!-- Toggles -->
                    <div class="nw-field nw-field-toggle">
                        <label class="nw-toggle-label">
                            <input id="nw-field-is_optional" name="is_optional" type="checkbox" class="nw-toggle">
                            <span>Optional</span>
                        </label>
                    </div>
                    <div class="nw-field nw-field-toggle">
                        <label class="nw-toggle-label">
                            <input id="nw-field-is_active" name="is_active" type="checkbox" class="nw-toggle" checked>
                            <span>Active</span>
                        </label>
                    </div>

                </div><!-- .nw-form-grid -->

                <div class="nw-modal-footer">
                    <button id="nw-delete-btn" type="button" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto;">
                        <i data-lucide="trash-2" style="width:14px;height:14px;"></i> Delete
                    </button>
                    <button id="nw-cancel-btn" type="button" class="nw-btn nw-btn-ghost">Cancel</button>
                    <button id="nw-save-btn"   type="submit" class="nw-btn nw-btn-primary">
                        <span id="nw-save-label">Add Card</span>
                    </button>
                </div>
            </form>
        </div><!-- .nw-modal -->
    </div><!-- #nw-modal-overlay -->
    <?php
}
