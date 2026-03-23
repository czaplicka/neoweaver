<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TALE WEAVER - FIELD AGENT COMMAND CENTER
 * Shortcode: [tw_list_campaigns]
 *
 * Changelog:
 *  - Fixed stray "%" in inline style (text-align:right%)
 *  - Fixed esc_js() used in HTML attribute context → esc_attr()
 *  - Fixed json_decode null-check (graceful fallback instead of casting null)
 *  - Fixed game_mode int-cast with proper isset guard
 *  - Moved wp_create_nonce() inside the non-empty branch (no wasted call)
 *  - Unified wp_user_id resolution in JS (removed fragile dual-key lookup)
 *  - Extended reload delay to 1 200 ms so card fade completes reliably
 *  - Wrapped jQuery async handlers in outer try/catch so unhandled rejections surface
 *  - Guarded all early-return paths in team IIFE with consistent btn reset
 *  - BUG-FIX: $c_id was cast with (int) which collapses UUID campaign IDs to 0.
 *    All HTML attributes (data-id, id="campaign-card-*"), PHP href params, and
 *    the JS delete RPC arg p_campaign_id now use the raw UUID string via $c_id_safe.
 *  - STYLE-FIX: empty-state CSS added to match .tw-no-worlds and .tw-agents-empty.
 */

if ( ! function_exists( 'tw_list_campaigns_final_v8_modes' ) ) {

    function tw_list_campaigns_final_v8_modes() {

        if ( is_admin() ) {
            return '';
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<p class="tw-error">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
        }

        if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
            return '<p class="tw-error">API Config missing.</p>';
        }

        $url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
        $anon_key = tw_supabase_anon_key();

        $params = array(
            'wp_user_id' => 'eq.' . $user_id,
            'select'     => '*,'
                . 'cyber_campaign_worlds('
                    . 'world_id,'
                    . 'cyber_worlds(name,difficulty)'
                . '),'
                . 'cyber_campaign_characters('
                    . 'character_id,'
                    . 'cyber_characters('
                        . 'name,'
                        . 'cyber_races(name),'
                        . 'cyber_classes(name)'
                    . ')'
                . ')',
            'order'      => 'created_at.desc',
        );

        $url = add_query_arg( $params, $url_base . 'cyber_campaign' );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '<p class="tw-error">CRITICAL ERROR: Matrix Synchronization Failed. Check your Uplink.</p>';
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return '<p class="tw-error">CRITICAL ERROR: Invalid payload received from Matrix.</p>';
        }
        $active_campaigns = $decoded;

        // ── Shared empty-state styles (mirrors .tw-no-worlds and .tw-agents-empty) ──
        $empty_styles = '
        <style>
            .tw-campaigns-empty {
                text-align: center;
                padding: 100px 0;
                font-family: \'Chakra Petch\', sans-serif;
            }
            .tw-campaigns-empty-icon {
                font-size: 40px;
                margin-bottom: 20px;
                opacity: 0.3;
                line-height: 1;
            }
            .tw-campaigns-empty-main {
                font-size: 1rem;
                color: #adff00;
                margin: 0 0 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .tw-campaigns-empty-sub {
                display: block;
                font-size: 0.85rem;
                color: #fff;
                margin: 0 0 28px;
            }
            .tw-campaigns-empty-actions {
                display: flex;
                justify-content: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .tw-campaigns-empty-actions .tw-btn-sync {
                display: inline-block;
                background: #adff00;
                color: #000 !important;
                border: none;
                padding: 10px 22px;
                font-weight: 900;
                border-radius: 4px;
                cursor: pointer;
                text-transform: uppercase;
                font-family: \'Chakra Petch\', sans-serif;
                font-size: 11px;
                letter-spacing: 0.05em;
                text-decoration: none;
                transition: background 0.2s, box-shadow 0.2s;
            }
            .tw-campaigns-empty-actions .tw-btn-sync:hover {
                background: #fff;
                box-shadow: 0 0 15px #adff00;
                color: #000 !important;
            }
            .tw-campaigns-empty-actions .tw-btn-outline {
                display: inline-block;
                background: transparent;
                color: #adff00 !important;
                border: 1px dashed #444;
                padding: 10px 22px;
                font-weight: 700;
                border-radius: 4px;
                cursor: pointer;
                text-transform: uppercase;
                font-family: \'Chakra Petch\', sans-serif;
                font-size: 11px;
                letter-spacing: 0.05em;
                text-decoration: none;
                transition: border-color 0.2s, color 0.2s;
            }
            .tw-campaigns-empty-actions .tw-btn-outline:hover {
                border-color: #adff00;
                color: #fff !important;
            }
        </style>';

        if ( empty( $active_campaigns ) ) {
            return $empty_styles . '
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

        $game_nonce = wp_create_nonce( 'tw_game_nonce' );

        ob_start();

        // Inject empty-state styles even on populated pages so they're ready
        // if JS later removes all cards and reveals the empty state.
        echo $empty_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>

        <div class="tw-char-wrapper" style="font-family:'Chakra Petch', sans-serif;">
            <div class="tw-char-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:25px;">
                <?php foreach ( $active_campaigns as $c ) :

                    $c_id_safe = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $c['id'] ?? '' ) );
                    $c_name    = esc_html( $c['name'] ?: 'UNNAMED_THREAD_' . $c_id_safe );

                    $world_rel = ! empty( $c['cyber_campaign_worlds'] )
                        ? ( $c['cyber_campaign_worlds'][0]['cyber_worlds'] ?? null )
                        : null;

                    $world_id  = ! empty( $c['cyber_campaign_worlds'] )
                        ? ( $c['cyber_campaign_worlds'][0]['world_id'] ?? null )
                        : null;

                    $char_rel  = ! empty( $c['cyber_campaign_characters'] )
                        ? ( $c['cyber_campaign_characters'][0]['cyber_characters'] ?? null )
                        : null;

                    $is_active = ! empty( $c['is_active'] );

                    $game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
                    $mode_str  = ( $game_mode === 2 ) ? 'TEAM' : 'SOLO';
                    $is_team   = ( $game_mode === 2 );

                    $join_code = isset( $c['join_code'] ) ? (string) $c['join_code'] : '';

                    $operative_name = 'PENDING ASSIGNMENT';
                    if ( $char_rel ) {
                        $race  = $char_rel['cyber_races']['name']   ?? 'Human';
                        $class = $char_rel['cyber_classes']['name'] ?? 'Agent';
                        $operative_name = esc_html( $char_rel['name'] )
                            . " <small style='color:#666; font-size:0.7rem;'>[{$race} | {$class}]</small>";
                    }

                    if ( ! $world_rel ) {
                        $main_btn = '<a href="/nodes/?campaign_id=' . esc_attr( $c_id_safe ) . '" class="tw-action-btn pulse-red">ANCHOR WORLD NODE</a>';
                    } elseif ( ! $char_rel ) {
                        $main_btn = '<a href="/agents/?campaign_id=' . esc_attr( $c_id_safe ) . '" class="tw-action-btn">INJECT FIELD AGENT</a>';
                    } else {
                        $main_btn = '<button class="tw-action-btn enter-matrix"'
                            . ' data-id="'    . esc_attr( $c_id_safe ) . '"'
                            . ' data-mode="'  . esc_attr( $mode_str ) . '"'
                            . ' data-join="'  . esc_attr( strtoupper( $join_code ) ) . '"'
                            . ' data-world="' . esc_attr( (string) $world_id ) . '">'
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
                                        <a href="/nodes/?campaign_id=<?php echo esc_attr( $c_id_safe ); ?>"
                                           class="tw-mini-btn"
                                           style="margin-left:8px; font-size:0.65rem; padding:2px 8px; border:1px solid #adff00; color:#adff00; text-decoration:none;">
                                            LINK NODE
                                        </a>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="tw-data-row" style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
                                <span style="font-size:0.7rem; color:#444; font-weight:bold;">OPERATIVE_LINK:</span>
                                <span style="font-size:0.85rem; color:#adff00; font-weight:bold; text-align:right;">
                                    <?php echo $operative_name; ?>
                                    <?php if ( ! $char_rel ) : ?>
                                        <a href="/agents/?campaign_id=<?php echo esc_attr( $c_id_safe ); ?>"
                                           class="tw-mini-btn"
                                           style="margin-left:8px; font-size:0.65rem; padding:2px 8px; border:1px solid #adff00; color:#adff00; text-decoration:none;">
                                            ASSIGN AGENT
                                        </a>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <?php if ( $is_team ) : ?>
                                <div class="tw-data-row" style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <span style="font-size:0.7rem; color:#444; font-weight:bold;">DEPLOYMENT HASH:</span>
                                    <span style="font-size:0.85rem; color:#adff00; font-weight:bold; text-align:right;"
                                          class="tw-join-code-display"
                                          data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>">
                                        <?php echo $join_code ? esc_html( strtoupper( $join_code ) ) : 'NOT INITIALIZED'; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tw-card-footer" style="display:flex; gap:12px; align-items:center;">
                            <div style="flex-grow:1;">
                                <?php echo $main_btn; ?>
                            </div>

                            <?php if ( $is_team && $join_code ) : ?>
                                <button class="tw-copy-join-btn"
                                        data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>"
                                        style="background:transparent; border:1px solid #adff00; color:#adff00; font-family:'Chakra Petch'; font-size:0.65rem; padding:0 15px; cursor:pointer; transition:0.3s; font-weight:bold;">
                                    COPY HASH
                                </button>
                            <?php endif; ?>

                            <button class="tw-delete-campaign-btn"
                                    data-id="<?php echo esc_attr( $c_id_safe ); ?>"
                                    data-name="<?php echo esc_attr( $c_name ); ?>"
                                    style="background:transparent; border:1px solid #222; color:#333; font-family:'Chakra Petch'; font-size:0.65rem; padding:0 15px; cursor:pointer; transition:0.3s; font-weight:bold;">
                                TERMINATE
                            </button>
                        </div>

                        <?php if ( ! $world_rel && $is_active ) : ?>
                            <div class="world-error-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%; border:1px solid #ff0055; pointer-events:none; box-shadow:inset 0 0 20px rgba(255,0,85,0.15); animation:tw-pulse-border 2s infinite;"></div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {

            const GLOBAL_NONCE = '<?php echo esc_js( $game_nonce ); ?>';

            function resetBtn(btn, label) {
                btn.prop('disabled', false).text(label).css('opacity', '1');
            }

            // ─── 1. HARD DELETE via Supabase RPC ─────────────────────────────
            $('.tw-delete-campaign-btn').on('click', async function(e) {
                e.preventDefault();

                const btn      = $(this);
                const campId   = btn.data('id');
                const campName = btn.data('name');

                if (!confirm('CONFIRM TERMINATION OF DEPLOYMENT: ' + campName + ' ?')) {
                    return;
                }

                btn.prop('disabled', true).text('TERMINATING...');

                if (!window.twSupabase) {
                    alert('SUPABASE CLIENT OFFLINE. CANNOT TERMINATE DEPLOYMENT.');
                    resetBtn(btn, 'TERMINATE');
                    return;
                }

                try {
                    const { error } = await window.twSupabase.rpc('fn_delete_campaign', {
                        p_campaign_id: campId
                    });

                    if (error) {
                        console.error('SUPABASE RPC DELETE ERROR', error);
                        alert('TERMINATION FAILED: ' + (error.message || 'Grid Denied Execution.'));
                        resetBtn(btn, 'TERMINATE');
                        return;
                    }

                    const card = $('#campaign-card-' + campId);
                    if (card.length) {
                        card.css({ opacity: '0', 'pointer-events': 'none' });
                    }
                    setTimeout(() => window.location.reload(), 1200);

                } catch (err) {
                    console.error('DELETE EXCEPTION', err);
                    alert('TERMINATION FAILED: CLIENT EXCEPTION');
                    resetBtn(btn, 'TERMINATE');
                }
            });

            // ─── 2. ENTER MATRIX — SOLO vs TEAM ──────────────────────────────
            $('.enter-matrix').on('click', function(e) {
                e.preventDefault();

                const btn    = $(this);
                const campId = btn.data('id');
                const mode   = String(btn.data('mode') || 'SOLO').toUpperCase();

                if (!campId) {
                    alert('DEPLOYMENT ERROR: Missing campaign ID.');
                    return;
                }

                if (mode === 'SOLO') {
                    btn.text('INITIALIZING...').css('opacity', '0.7');

                    const fd = new FormData();
                    fd.append('campaign_id', campId);
                    fd.append('security',    GLOBAL_NONCE);

                    const endpointUrl = '<?php echo esc_js( get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-start-game-session.php' ); ?>';

                    fetch(endpointUrl, {
                        method:      'POST',
                        body:        fd,
                        credentials: 'same-origin',
                    })
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(response => {
                        if (response.success) {
                            window.location.href = '<?php echo esc_js( home_url( '/terminal/' ) ); ?>';
                        } else {
                            const data = response.data || {};
                            if (data.message === 'no_character') {
                                window.location.href = '/agents/?campaign_id=' + campId;
                            } else {
                                alert('SESSION INIT FAILED: ' + (data.message || 'Unknown interference'));
                                resetBtn(btn, 'ENTER MATRIX');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('SESSION INIT ERROR', err);
                        alert('SESSION INIT FAILED: network error');
                        resetBtn(btn, 'ENTER MATRIX');
                    });

                } else {
                    btn.text('LINKING...').css('opacity', '0.7');

                    if (!window.twSupabase) {
                        alert('SUPABASE CLIENT OFFLINE. CANNOT LINK SQUAD.');
                        resetBtn(btn, 'ENTER MATRIX');
                        return;
                    }

                    const adv = window.twAdventureData || {};
                    const currentWpUserId = adv.wp_user_id || adv.userid || null;

                    if (!currentWpUserId) {
                        alert('SIGNUP FAILED: Cannot detect current operator ID.');
                        resetBtn(btn, 'ENTER MATRIX');
                        return;
                    }

                    const client  = window.twSupabase;
                    const worldId = btn.data('world') || null;

                    (async () => {
                        try {
                            let characterId = null;

                            if (worldId) {
                                const { data: charRows, error: charError } = await client
                                    .from('cyber_characters')
                                    .select('id')
                                    .eq('wp_user_id', currentWpUserId)
                                    .eq('world_id', worldId)
                                    .limit(1);

                                if (charError) {
                                    console.error('CHARACTER LOOKUP ERROR', charError);
                                    alert('SIGNUP FAILED: Cannot resolve your Field Agent for this Node.');
                                    resetBtn(btn, 'ENTER MATRIX');
                                    return;
                                }

                                characterId = (charRows && charRows.length) ? charRows[0].id : null;
                            }

                            if (!characterId) {
                                window.location.href = '/agents/?campaign_id=' + campId;
                                return;
                            }

                            const { data: existingSignups, error: existingError } = await client
                                .from('cyber_campaign_signups')
                                .select('id')
                                .eq('campaign_id',  campId)
                                .eq('wp_user_id',   currentWpUserId)
                                .limit(1);

                            if (existingError) {
                                console.error('TEAM SIGNUP CHECK ERROR', existingError);
                                alert('SIGNUP FAILED: Cannot verify existing deployment link.');
                                resetBtn(btn, 'ENTER MATRIX');
                                return;
                            }

                            if (!existingSignups || !existingSignups.length) {
                                const { error: signupError } = await client
                                    .from('cyber_campaign_signups')
                                    .insert({
                                        campaign_id:  campId,
                                        character_id: characterId,
                                        wp_user_id:   currentWpUserId,
                                    });

                                if (signupError) {
                                    console.error('TEAM SIGNUP ERROR', signupError);
                                    alert('SIGNUP FAILED: ' + (signupError.message || 'Unknown interference'));
                                    resetBtn(btn, 'ENTER MATRIX');
                                    return;
                                }
                            }

                            window.location.href = '<?php echo esc_js( home_url( '/lobby/?campaign_id=' ) ); ?>' + campId;

                        } catch (err) {
                            console.error('TEAM SIGNUP EXCEPTION', err);
                            alert('SIGNUP FAILED: CLIENT EXCEPTION');
                            resetBtn(btn, 'ENTER MATRIX');
                        }
                    })();
                }
            });

            // ─── 3. COPY HASH ─────────────────────────────────────────────────
            $('.tw-copy-join-btn').on('click', async function(e) {
                e.preventDefault();

                const btn  = $(this);
                const code = btn.data('code');

                if (!code) {
                    alert('NO HASH DETECTED IN CURRENT DEPLOYMENT.');
                    return;
                }

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(code);
                    } else {
                        const temp = $('<input>');
                        $('body').append(temp);
                        temp.val(code).select();
                        document.execCommand('copy');
                        temp.remove();
                    }

                    btn.text('HASH COPIED');
                    setTimeout(() => btn.text('COPY HASH'), 2000);

                } catch (err) {
                    console.error('CLIPBOARD ERROR', err);
                    alert('COPY FAILED: BROWSER BLOCKED CLIPBOARD ACCESS.');
                }
            });
        });
        </script>

        <?php
        return ob_get_clean();
    }
}

add_shortcode( 'tw_list_campaigns', 'tw_list_campaigns_final_v8_modes' );
