<?php
/**
 * ENQUEUE: tw_connect_campaign_world assets
 * CSS → public/assets/css/tw-deployment.css
 * JS  → public/assets/js/tw-deployment.js
 */
function tw_deployment_enqueue_assets() {

wp_register_style(
    'tw-deployment',
    NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-deployment.css',
    [],
    '1.0.0'
);
wp_register_script(
    'tw-deployment',
    $NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-deployment.js'
    [],
    '1.0.0',
    true
);
add_action( 'wp_enqueue_scripts', 'tw_deployment_enqueue_assets' );

/**
 * SHORTCODE: [tw_connect_campaign_world]
 * Version: v18 — campaign ↔ world link only, no agent assignment
 *
 * CHANGES vs v17:
 * 1. Removed agent/character selection entirely
 * 2. Payload to cyber_campaign_worlds: campaign_id, world_id, creator_wp_id only
 * 3. CSS and JS moved to external files via enqueue
 * 4. Config passed to JS via wp_localize_script
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
            </aside>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'tw_connect_campaign_world', 'tw_connect_campaign_world_final' );
