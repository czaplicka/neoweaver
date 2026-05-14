<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * TALE WEAVER - FIELD AGENT COMMAND CENTER
 * Shortcode: [tw_list_campaigns]
 */

if ( ! function_exists( 'tw_list_campaigns_final_v8_modes' ) ) {

    function tw_list_campaigns_final_v8_modes() {

        if ( is_admin() ) {
            return '';
        }

        // Enqueue tw-core.css if not already loaded (e.g. on non-adventure pages)
        if ( ! wp_style_is( 'neoweaver-tw-core', 'enqueued' ) ) {
            wp_enqueue_style(
                'neoweaver-tw-core',
                NEOWEAVER_PLUGIN_URL . 'assets/css/public/core.css',
                [],
                NEOWEAVER_VERSION
            );
        };
            	wp_enqueue_script(
			'list-campaigns-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/list-campaigns.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<p class="tw-error">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
        }

        if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
            return '<p class="tw-error">API Config missing.</p>';
        }

        $url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
        $anon_key = tw_supabase_anon_key();

        $select = '*,cyber_campaign_worlds(world_id,cyber_worlds(name,difficulty)),cyber_campaign_characters(character_id,cyber_characters(name,cyber_races(name),cyber_classes(name)))';

        $url = $url_base . 'cyber_campaign'
            . '?wp_user_id=eq.' . (int) $user_id
            . '&select=' . $select
            . '&order=created_at.desc';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return '<p class="tw-error">CRITICAL ERROR: ' . esc_html( $response->get_error_message() ) . '</p>';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            error_log( '[NeoWeaver v18] Supabase HTTP ' . $code . ' URL: ' . $url . ' BODY: ' . $body );
            return '<p class="tw-error">CRITICAL ERROR: Matrix Synchronization Failed [HTTP ' . (int) $code . ']. Check your Uplink.</p>';
        }

        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            error_log( '[NeoWeaver v18] JSON decode failed. Raw: ' . $raw );
            return '<p class="tw-error">CRITICAL ERROR: Invalid payload received from Matrix.</p>';
        }
        $active_campaigns = $decoded;
        if ( empty( $active_campaigns ) ) {
            return '
            <div class="tw-campaigns-empty">
                <div class="tw-campaigns-empty-icon">⚠️</div>
                <p class="tw-campaigns-empty-main">NO DEPLOYMENTS DETECTED IN YOUR GRID.</p>
                <small class="tw-campaigns-empty-sub">Create a new Deployment to begin the weaving process.</small>
                <div class="tw-campaigns-empty-actions">
                    <a href="/new-deployment/" class="tw-btn-sync">NEW DEPLOYMENT</a>
                    <a href="/new-node/" class="tw-btn-outline">NEW NODE</a>
                </div>
            </div>';
        }

        $game_nonce       = wp_create_nonce( 'tw_game_nonce' );
        $rest_nonce       = wp_create_nonce( 'wp_rest' );
        $session_rest_url = get_rest_url( null, 'neoweaver/v1/session/start' );

        ob_start();
        ?>

        <div class="tw-char-wrapper" style="font-family:'Chakra Petch', sans-serif;">
            <div class="tw-char-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:25px;">
                <?php foreach ( $active_campaigns as $c ) :

                    $c_id_safe = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $c['id'] ?? '' ) );
                    $c_name    = esc_html( $c['name'] ?: 'UNNAMED_THREAD_' . $c_id_safe );

                    /*
                     * v15 FIX: PostgREST może zwrócić obiekt (jeden rekord) lub tablicę.
                     * Obsługujemy oba przypadki dla junction tables.
                     */

                    /* Świat przez junction */
                    $cw_raw         = $c['cyber_campaign_worlds'] ?? null;
                    $world_junction = null;
                    if ( ! empty( $cw_raw ) ) {
                        $world_junction = isset( $cw_raw[0] ) ? $cw_raw[0] : $cw_raw;
                    }
                    $world_rel = $world_junction ? ( $world_junction['cyber_worlds'] ?? null ) : null;
                    $world_id  = $world_junction ? ( $world_junction['world_id'] ?? null ) : null;

                    /* Postać przez junction */
                    $cc_raw        = $c['cyber_campaign_characters'] ?? null;
                    $char_junction = null;
                    if ( ! empty( $cc_raw ) ) {
                        $char_junction = isset( $cc_raw[0] ) ? $cc_raw[0] : $cc_raw;
                    }
                    $char_rel = $char_junction ? ( $char_junction['cyber_characters'] ?? null ) : null;
                    $char_id  = $char_junction ? ( $char_junction['character_id'] ?? null ) : null;

                    $is_active = ! empty( $c['is_active'] );
                    $game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
                    $mode_str  = ( $game_mode === 2 ) ? 'TEAM' : 'SOLO';
                    $is_team   = ( $game_mode === 2 );
                    $join_code = isset( $c['join_code'] ) ? (string) $c['join_code'] : '';

                    $operative_name = 'PENDING ASSIGNMENT';
                    if ( $char_rel ) {
                        $race  = isset( $char_rel['cyber_races']['name'] )   ? $char_rel['cyber_races']['name']   : 'Unknown';
                        $class = isset( $char_rel['cyber_classes']['name'] ) ? $char_rel['cyber_classes']['name'] : 'Agent';
                        $operative_name = esc_html( $char_rel['name'] )
                            . " <small style='color:#666; font-size:0.7rem;'>[" . esc_html( $race ) . ' | ' . esc_html( $class ) . "]</small>";
                    }

                    // v17: /nodes/ anchor
                    $nodes_url  = '/nodes/?campaign_id=' . esc_attr( $c_id_safe ) . '#tw-deployment-root';
                    // v18: /agents/ anchor
                    $agents_url = '/agents/?campaign_id=' . esc_attr( $c_id_safe ) . '#tw-deployment-root';

                    if ( ! $world_rel ) {
                        $main_btn = '<a href="' . $nodes_url . '" class="tw-action-btn pulse-red">ANCHOR WORLD NODE</a>';
                    } elseif ( ! $char_rel ) {
                        $main_btn = '<a href="' . $agents_url . '" class="tw-action-btn">INJECT FIELD AGENT</a>';
                    } else {
                        $main_btn = '<button class="tw-action-btn enter-matrix"'
                            . ' data-id="'        . esc_attr( $c_id_safe ) . '"'
                            . ' data-character="' . esc_attr( (string) $char_id ) . '"'
                            . ' data-mode="'      . esc_attr( $mode_str ) . '"'
                            . ' data-join="'      . esc_attr( strtoupper( $join_code ) ) . '"'
                            . ' data-world="'     . esc_attr( (string) $world_id ) . '">'
                            . 'ENTER MATRIX'
                            . '</button>';
                    }
                ?>

                    <div id="campaign-card-<?php echo esc_attr( $c_id_safe ); ?>" class="tw-char-card"
                         style="background:#0a0a0a; border:1px solid #1a1a1a; padding:25px; position:relative; transition:0.3s; <?php echo ! $is_active ? 'opacity:0.3; filter:grayscale(1);' : ''; ?>">

                        <div class="tw-card-header" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                            <div>
                                <div class="tw-id-tag" style="font-family:monospace; font-size:0.6rem; color:#444; letter-spacing:1px;">UPLINK_ID: <?php echo esc_html( $c_id_safe ); ?></div>
                                <h3 style="color:#adff00; margin:5px 0; font-size:1.4rem; text-transform:uppercase;"><?php echo $c_name; ?></h3>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span class="status-dot" style="width:7px; height:7px; border-radius:50%; background:<?php echo $is_active ? '#adff00' : '#ff0055'; ?>; box-shadow:0 0 5px <?php echo $is_active ? '#adff00' : '#ff0055'; ?>;"></span>
                                    <span style="font-size:0.65rem; color:#888; font-weight:bold; letter-spacing:1px;"><?php echo $is_active ? 'CONNECTION STABLE' : 'LINK SEVERED'; ?></span>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span class="tw-badge-cyber" style="border:1px solid #adff00; color:#adff00; font-size:0.6rem; padding:3px 8px; font-weight:bold;"><?php echo esc_html( $mode_str ); ?></span>
                            </div>
                        </div>

                        <div class="tw-card-body" style="border-top:1px solid #111; padding-top:15px; margin-bottom:25px;">
                            <div class="tw-data-row" style="display:flex; justify-content:space-between; margin-bottom:10px; gap:8px; align-items:center;">
                                <span style="font-size:0.7rem; color:#444; font-weight:bold;">REALITY_NODE:</span>
                                <span style="font-size:0.85rem; color:<?php echo $world_rel ? '#fff' : '#ff0055'; ?>; font-weight:bold; text-align:right;">
                                    <?php echo $world_rel ? esc_html( $world_rel['name'] ) : 'MISSING ANCHOR'; ?>
                                    <?php if ( ! $world_rel ) : ?>
                                        <a href="<?php echo $nodes_url; ?>" class="tw-mini-btn" style="margin-left:8px; font-size:0.65rem; padding:2px 8px; border:1px solid #adff00; color:#adff00; text-decoration:none;">LINK NODE</a>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="tw-data-row" style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
                                <span style="font-size:0.7rem; color:#444; font-weight:bold;">OPERATIVE_LINK:</span>
                                <span style="font-size:0.85rem; color:#adff00; font-weight:bold; text-align:right;">
                                    <?php echo $operative_name; ?>
                                    <?php if ( ! $char_rel ) : ?>
                                        <a href="<?php echo $agents_url; ?>" class="tw-mini-btn" style="margin-left:8px; font-size:0.65rem; padding:2px 8px; border:1px solid #adff00; color:#adff00; text-decoration:none;">ASSIGN AGENT</a>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <?php if ( $is_team ) : ?>
                                <div class="tw-data-row" style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <span style="font-size:0.7rem; color:#444; font-weight:bold;">DEPLOYMENT HASH:</span>
                                    <span style="font-size:0.85rem; color:#adff00; font-weight:bold; text-align:right;">
                                        <?php echo $join_code ? esc_html( strtoupper( $join_code ) ) : 'NOT INITIALIZED'; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tw-card-footer" style="display:flex; gap:12px; align-items:center;">
                            <div style="flex-grow:1;"><?php echo $main_btn; ?></div>

                            <?php if ( $is_team && $join_code ) : ?>
                                <button class="tw-copy-join-btn" data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>" style="background:transparent; border:1px solid #adff00; color:#adff00; font-family:'Chakra Petch'; font-size:0.65rem; padding:0 15px; cursor:pointer; transition:0.3s; font-weight:bold;">COPY HASH</button>
                            <?php endif; ?>

                            <button class="tw-delete-campaign-btn" data-id="<?php echo esc_attr( $c_id_safe ); ?>" data-name="<?php echo esc_attr( $c_name ); ?>" style="background:transparent; border:1px solid #222; color:#333; font-family:'Chakra Petch'; font-size:0.65rem; padding:0 15px; cursor:pointer; transition:0.3s; font-weight:bold;">TERMINATE</button>
                        </div>

                        <?php if ( ! $world_rel && $is_active ) : ?>
                            <div class="world-error-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%; border:1px solid #ff0055; pointer-events:none; box-shadow:inset 0 0 20px rgba(255,0,85,0.15); animation:tw-pulse-border 2s infinite;"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode( 'tw_list_campaigns', 'tw_list_campaigns_final_v8_modes' );
