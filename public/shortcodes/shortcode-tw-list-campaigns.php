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
        if ( is_admin() ) return '';
        $user_id = get_current_user_id();
        if ( ! $user_id ) return '<p class="tw-error">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';

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

        $campaigns = json_decode( wp_remote_retrieve_body( $response ), true );
        $active_campaigns = (array) $campaigns;

        if ( empty( $active_campaigns ) ) {
            return '
            <div class="tw-deployments-empty-wrap">
                <div class="tw-deployments-empty-screen">
                    <div class="tw-deployments-empty-inner">
                        <div class="tw-deployments-empty-icon">!</div>
                        <p class="tw-deployments-empty-main">NO DEPLOYMENTS DETECTED IN YOUR GRID.</p>
                        <p class="tw-deployments-empty-sub">CREATE A NEW DEPLOYMENT TO BEGIN THE WEAVING PROCESS.</p>
                        <div class="tw-deployments-empty-actions">
                            <a href="/new-deployment/" class="tw-btn-sync terminal-btn">
                                NEW DEPLOYMENT
                            </a>
                            <a href="/new-node/" class="tw-btn-outline">
                                NEW NODE
                            </a>
                        </div>
                    </div>
                </div>
            </div>';
        }

        $game_nonce = wp_create_nonce( 'tw_game_nonce' );

        ob_start(); ?>
        
        <div class="tw-char-wrapper" style="font-family:'Chakra Petch', sans-serif;">
            <div class="tw-char-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 25px;">
                <?php foreach ( $active_campaigns as $c ) :
                    $c_id   = (int) $c['id'];
                    $c_name = esc_html( $c['name'] ?: 'UNNAMED_THREAD_' . $c_id );

                    $world_rel = ! empty( $c['cyber_campaign_worlds'] )
                        ? ( $c['cyber_campaign_worlds'][0]['cyber_worlds'] ?? null )
                        : null;
                    $char_rel  = ! empty( $c['cyber_campaign_characters'] )
                        ? ( $c['cyber_campaign_characters'][0]['cyber_characters'] ?? null )
                        : null;

                    $is_active = !isset($c['is_active']) || $c['is_active'] !== false;

                    $game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
                    $mode_str  = ( $game_mode === 2 ) ? 'TEAM' : 'SOLO';

                    $join_code = $c['join_code'] ?? '';
                    $is_team   = ( $game_mode === 2 );

                    $operative_name = 'PENDING ASSIGNMENT';
                    if ( $char_rel ) {
                        $race  = $char_rel['cyber_races']['name']   ?? 'Human';
                        $class = $char_rel['cyber_classes']['name'] ?? 'Agent';
                        $operative_name = esc_html( $char_rel['name'] ) . " <small style='color:#666; font-size:0.7rem;'>[{$race} | {$class}]</small>";
                    }

                    if ( ! $world_rel ) {
                        $main_btn = '<a href="/nodes/?campaign_id=' . $c_id . '" class="tw-action-btn pulse-red">ANCHOR WORLD NODE</a>';
                    } elseif ( ! $char_rel ) {
                        $main_btn = '<a href="/agents/?campaign_id=' . $c_id . '" class="tw-action-btn">INJECT FIELD AGENT</a>';
                    } else {
                        $main_btn = '<button class="tw-action-btn enter-matrix" 
                            data-id="' . $c_id . '" 
                            data-mode="' . esc_attr( $mode_str ) . '" 
                            data-join="' . esc_attr( strtoupper( $join_code ) ) . '">
                            ENTER MATRIX
                        </button>';
                    }
                    ?>
                    
                    <div id="campaign-card-<?php echo $c_id; ?>" class="tw-char-card"
                         style="background:#0a0a0a; border:1px solid #1a1a1a; padding:25px; position:relative; transition:0.3s; <?php echo ! $is_active ? 'opacity:0.3; filter:grayscale(1);' : ''; ?>">
                        
                        <div class="tw-card-header" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                            <div>
                                <div class="tw-id-tag" style="font-family:monospace; font-size:0.6rem; color:#444; letter-spacing:1px;">UPLINK_ID: <?php echo $c_id; ?></div>
                                <h3 style="color:#adff00; margin:5px 0; font-size:1.4rem; text-transform:uppercase;"><?php echo $c_name; ?></h3>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span class="status-dot" style="width:7px; height:7px; border-radius:50%; background:<?php echo $is_active ? '#adff00' : '#ff0055'; ?>; box-shadow: 0 0 5px <?php echo $is_active ? '#adff00' : '#ff0055'; ?>;"></span>
                                    <span style="font-size:0.65rem; color:#888; font-weight:bold; letter-spacing:1px;"><?php echo $is_active ? 'CONNECTION STABLE' : 'LINK SEVERED'; ?></span>
                                </div>
                            </div>
                            <div style="text-align:right%;">
                                <span class="tw-badge-cyber" style="border:1px solid #adff00; color:#adff00; font-size:0.6rem; padding:3px 8px; font-weight:bold;"><?php echo esc_html( $mode_str ); ?></span>
                            </div>
                        </div>

                        <div class="tw-card-body" style="border-top:1px solid #111; padding-top:15px; margin-bottom:25px;">
                            <div class="tw-data-row" style="display:flex; justify-content:space-between; margin-bottom:10px; gap:8px; align-items:center;">
                                <span style="font-size:0.7rem; color:#444; font-weight:bold;">REALITY_NODE:</span>
                                <span style="font-size:0.85rem; color:<?php echo $world_rel ? '#fff' : '#ff0055'; ?>; font-weight:bold; text-align:right;">
                                    <?php echo $world_rel ? esc_html( $world_rel['name'] ) : 'MISSING ANCHOR'; ?>
                                    <?php if ( ! $world_rel ) : ?>
                                        <a href="/nodes/?campaign_id=<?php echo $c_id; ?>"
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
                                        <a href="/agents/?campaign_id=<?php echo $c_id; ?>"
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
                                    data-id="<?php echo esc_attr( $c_id ); ?>"
                                    data-name="<?php echo esc_js( $c_name ); ?>"
                                    style="background:transparent; border:1px solid #222; color:#333; font-family:'Chakra Petch'; font-size:0.65rem; padding:0 15px; cursor:pointer; transition:0.3s; font-weight:bold;">
                                TERMINATE
                            </button>
                        </div>

                        <?php if ( ! $world_rel && $is_active ) : ?>
                            <div class="world-error-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%; border:1px solid #ff0055; pointer-events:none; box-shadow: inset 0 0 20px rgba(255,0,85,0.15); animation: tw-pulse-border 2s infinite;"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const GLOBAL_NONCE = '<?php echo esc_js($game_nonce); ?>';

            // 1. HARD DELETE via Supabase RPC
            $('.tw-delete-campaign-btn').on('click', async function(e) {
                e.preventDefault();
                
                const btn = $(this);
                const campId = btn.data('id');
                const campName = btn.data('name');

                if (!confirm('CONFIRM TERMINATION OF DEPLOYMENT: ' + campName + ' ?')) {
                    return;
                }

                btn.prop('disabled', true).text('TERMINATING...');

                if (!window.twSupabase) {
                    alert('SUPABASE CLIENT OFFLINE. CANNOT TERMINATE DEPLOYMENT.');
                    btn.prop('disabled', false).text('TERMINATE');
                    return;
                }

                const client = window.twSupabase;

                try {
                    const { data, error } = await client.rpc('fn_delete_campaign', { 
                        p_campaign_id: campId 
                    });

                    if (error) {
                        console.error('SUPABASE RPC DELETE ERROR', error);
                        alert('TERMINATION FAILED: ' + (error.message || 'Grid Denied Execution.'));
                        btn.prop('disabled', false).text('TERMINATE');
                    } else {
                        const card = $('#campaign-card-' + campId);
                        if (card.length) {
                            card.css({ 'opacity': '0.3', 'pointer-events': 'none' });
                        }
                        setTimeout(() => window.location.reload(), 500);
                    }
                } catch (err) {
                    console.error('DELETE EXCEPTION', err);
                    alert('TERMINATION FAILED: CLIENT EXCEPTION');
                    btn.prop('disabled', false).text('TERMINATE');
                }
            });

            // 2. ENTER MATRIX – SOLO vs TEAM
            $('.enter-matrix').on('click', function(e) {
                e.preventDefault();
                const btn    = $(this);
                const campId = btn.data('id');
                const mode   = (btn.data('mode') || 'SOLO').toUpperCase();

                if (!campId) {
                    alert('DEPLOYMENT ERROR: Missing campaign ID.');
                    return;
                }

                if (mode === 'SOLO') {
                    btn.text('INITIALIZING...').css('opacity', '0.7');

                    const fd = new FormData();
                    fd.append('campaign_id', campId);
                    fd.append('security', GLOBAL_NONCE);

                    const endpointUrl = '<?php echo esc_js( get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-start-game-session.php' ); ?>';

                    fetch(endpointUrl, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(response => {
                        if (response.success) {
                            window.location.href = '<?php echo home_url("/terminal/"); ?>';
                        } else {
                            const data = response.data || {};
                            if (data.message === 'no_character') {
                                window.location.href = '/agents/?campaign_id=' + campId;
                            } else {
                                alert('SESSION INIT FAILED: ' + (data.message || 'Unknown interference'));
                                btn.text('ENTER MATRIX').css('opacity', '1');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('SESSION INIT ERROR', err);
                        alert('SESSION INIT FAILED: network error');
                        btn.text('ENTER MATRIX').css('opacity', '1');
                    });

                } else {
                    btn.text('LINKING...').css('opacity', '0.7');

                    if (!window.twSupabase) {
                        alert('SUPABASE CLIENT OFFLINE. CANNOT LINK SQUAD.');
                        btn.text('ENTER MATRIX').css('opacity', '1');
                        return;
                    }

                    const client = window.twSupabase;

                    (async () => {
                        try {
                            const { data: linkRows, error: linkError } = await client
                                .from('cyber_campaign_characters')
                                .select('character_id')
                                .eq('campaign_id', campId)
                                .limit(1);

                            if (linkError) {
                                console.error('TEAM LINK ERROR', linkError);
                                alert('DEPLOYMENT ERROR: cannot read campaign character link.');
                                btn.text('ENTER MATRIX').css('opacity', '1');
                                return;
                            }

                            if (!linkRows || !linkRows.length || !linkRows[0].character_id) {
                                window.location.href = '/agents/?campaign_id=' + campId;
                                return;
                            }

                            const characterId = linkRows[0].character_id;

                            const currentWpUserId = (window.twAdventureData && (window.twAdventureData.wp_user_id || window.twAdventureData.userid)) || null;

                            if (!currentWpUserId) {
                                alert('SIGNUP FAILED: cannot detect current operator ID.');
                                btn.text('ENTER MATRIX').css('opacity', '1');
                                return;
                            }

                            const { data: existingSignups, error: existingError } = await client
                                .from('cyber_campaign_signups')
                                .select('id, campaign_id, wp_user_id, character_id')
                                .eq('campaign_id', campId)
                                .eq('wp_user_id', currentWpUserId)
                                .limit(1);

                            if (existingError) {
                                console.error('TEAM SIGNUP CHECK ERROR', existingError);
                                alert('SIGNUP FAILED: cannot verify existing deployment link.');
                                btn.text('ENTER MATRIX').css('opacity', '1');
                                return;
                            }

                            if (!existingSignups || !existingSignups.length) {
                                const { error: signupError } = await client
                                    .from('cyber_campaign_signups')
                                    .insert({
                                        campaign_id: campId,
                                        character_id: characterId,
                                        wp_user_id: currentWpUserId
                                    });

                                if (signupError) {
                                    console.error('TEAM SIGNUP ERROR', signupError);
                                    alert('SIGNUP FAILED: ' + (signupError.message || 'Unknown interference'));
                                    btn.text('ENTER MATRIX').css('opacity', '1');
                                    return;
                                }
                            }

                            window.location.href = '<?php echo home_url("/lobby/?campaign_id="); ?>' + campId;
                        } catch (e) {
                            console.error('TEAM SIGNUP EXCEPTION', e);
                            alert('SIGNUP FAILED: CLIENT EXCEPTION');
                            btn.text('ENTER MATRIX').css('opacity', '1');
                        }
                    })();
                }
            });

            // 3. COPY HASH
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
