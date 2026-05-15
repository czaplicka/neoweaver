<?php
if ( ! function_exists( 'tw_connect_campaign_world_enqueue_assets' ) ) {
	function tw_connect_campaign_world_enqueue_assets() {

    wp_register_style(
        'tw-deployment',
        NEOWEAVER_PLUGIN_URL . 'assets/css/public/deployment.css',
        [],
        NEOWEAVER_VERSION
    );

    wp_register_script(
        'tw-deployment',
        NEOWEAVER_PLUGIN_URL . 'assets/js/public/deployment.js',
        [],
        NEOWEAVER_VERSION,
        true
    );
}
}
add_action( 'wp_enqueue_scripts', 'tw_connect_campaign_world_enqueue_assets' );

/**
 * SHORTCODE: [tw_connect_campaign_world]
 * Version: v19 — added #connector alias anchor + NEW DEPLOYMENT button
 *
 * CHANGES vs v18:
 * 1. Added id="connector" span above root so list-worlds #connector scroll works
 * 2. Added NEW DEPLOYMENT button in sidebar
 */
function tw_connect_campaign_world_final() {
    if ( ! is_user_logged_in() ) {
        return '<p class="tw-message">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
    }

    wp_enqueue_style( 'tw-deployment' );
    wp_enqueue_script( 'tw-deployment' );

    wp_localize_script( 'tw-deployment', 'twDeploymentCfg', [
        'url' => trailingslashit( tw_supabase_url() ),
        'key' => tw_supabase_anon_key(),
        'uid' => get_current_user_id(),
    ] );

    ob_start(); ?>
    <span id="connector" style="display:block; height:0; overflow:hidden;" aria-hidden="true"></span>
    <div id="tw-deployment-root" class="tw-deployment-main-container">

        <audio id="tw-glitch-sound"
               src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/soundreality-glitch-177348.mp3"
               preload="auto"></audio>

        <section class="tw-briefing-hero">
            <div class="tw-hero-overlay"></div>
            <div class="tw-hero-content">
                <div class="tw-hero-text">
                    <span class="tw-label-alt">MISSION PARAMETERS</span>
                    <h1>ANCHORING THE SPLOT</h1>
                    <p>Field Agent, you are about to merge a narrative thread with a physical reality node.
                       This deployment will stabilize the local sector for multiplayer synchronization.</p>
                </div>
                <div class="tw-hero-stats">
                    <div class="tw-hero-stat-item">
                        <span class="n" id="stat-latency">0.024</span>
                        <span class="l">LATENCY</span>
                    </div>
                    <div class="tw-hero-stat-item">
                        <span class="n">STABLE</span>
                        <span class="l">NODE FLUX</span>
                    </div>
                    <div class="tw-hero-stat-item tw-pulse-stat">
                        <span class="n">ACTIVE</span>
                        <span class="l">UPLINK</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="tw-deploy-grid">
            <div class="tw-deploy-controls">
                <div id="tw-world-console" class="tw-console-box">
                    > System: Initializing Deployment Interface...
                </div>

                <form id="tw-anchor-form" class="tw-form-layout">
                    <div class="tw-selection-group">

                        <div class="tw-field-box">
                            <label>
                                <i class="dashicons dashicons-backup"></i>
                                SOURCE: DEPLOYMENT (Campaign)
                            </label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-camp" class="tw-input-cyber"
                                       placeholder="Search deployments...">
                                <select id="s-camp" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>

                        <div class="tw-field-box">
                            <label>
                                <i class="dashicons dashicons-networking"></i>
                                DESTINATION: THE NODE (World)
                            </label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-world" class="tw-input-cyber"
                                       placeholder="Locate node...">
                                <select id="s-world" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>

                    </div>

                    <button type="submit" id="b-connect" class="tw-btn-deploy" disabled>
                        EXECUTE DEPLOYMENT [ENTER]
                    </button>
                </form>
            </div>

            <aside class="tw-deploy-sidebar">
                <div class="tw-sidebar-card">
                    <h4><i class="dashicons dashicons-info"></i> PROTOCOL BINDING</h4>
                    <p>Once anchored, the <strong>Deployment</strong> consumes the <strong>Node's</strong>
                       resources. Other Field Agents can then synchronize via the Multiplayer Frequency.</p>
                </div>
                <div class="tw-sidebar-card" style="margin-top:16px;">
                    <h4><i class="dashicons dashicons-admin-users"></i> AGENT BINDING</h4>
                    <p>You can assign a <strong>Field Agent</strong> to this Deployment later from the
                       Deployment management panel via <code>cyber_campaign_characters</code>.</p>
                </div>
                <div class="tw-sidebar-card" style="margin-top:16px;">
                    <h4><i class="dashicons dashicons-plus-alt"></i> NEW DEPLOYMENT</h4>
                    <p>No deployment yet? Initialize a new mission thread first.</p>
                    <a href="/new-deployment/" class="tw-btn-deploy" style="display:inline-block; margin-top:10px; text-align:center; text-decoration:none; font-size:0.75rem; padding:10px 20px;">
                        + NEW DEPLOYMENT
                    </a>
                </div>
            </aside>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'tw_connect_campaign_world', 'tw_connect_campaign_world_final' );
