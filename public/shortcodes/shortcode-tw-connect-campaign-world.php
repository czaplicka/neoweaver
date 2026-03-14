<?php
/**
 * SHORTCODE: [tw_connect_campaign_world]
 * Wersja: v13+ - AUDIO & VISUAL SYNC (Glitch & Live Stats Edition)
 */
function tw_connect_campaign_world_final() {
    if ( ! is_user_logged_in() ) {
        return '<p class="tw-message">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
    }

    $user_id      = get_current_user_id();
    $supabase_url = trailingslashit( tw_supabase_url() );
    $anon_key     = tw_supabase_anon_key();

    ob_start(); ?>
    <div id="tw-deployment-root" class="tw-deployment-main-container">
        
        <audio id="tw-glitch-sound" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/soundreality-glitch-177348.mp3" preload="auto"></audio>

        <section class="tw-briefing-hero">
            <div class="tw-hero-overlay"></div>
            <div class="tw-hero-content">
                <div class="tw-hero-text">
                    <span class="tw-label-alt">MISSION PARAMETERS</span>
                    <h1>ANCHORING THE SPLOT</h1>
                    <p>Field Agent, you are about to merge a narrative thread with a physical reality node. This deployment will stabilize the local sector for multiplayer synchronization.</p>
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
                            <label><i class="dashicons dashicons-backup"></i> SOURCE: DEPLOYMENT (Campaign)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-camp" class="tw-input-cyber" placeholder="Search deployments...">
                                <select id="s-camp" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>

                        <div class="tw-field-box">
                            <label><i class="dashicons dashicons-networking"></i> DESTINATION: THE NODE (World)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-world" class="tw-input-cyber" placeholder="Locate node...">
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
                    <p>Once anchored, the <strong>Deployment</strong> consumes the <strong>Node's</strong> resources. Other Field Agents can then synchronize via the Multiplayer Frequency.</p>
                </div>
            </aside>
        </div>
    </div>

    <script>
    (function() {
        const cfg = {
            url: "<?php echo esc_js( $supabase_url ); ?>",
            key: "<?php echo esc_js( $anon_key ); ?>",
            uid: <?php echo (int) $user_id; ?>
        };

        const selC  = document.getElementById('s-camp');
        const selW  = document.getElementById('s-world');
        const btn   = document.getElementById('b-connect');
        const log   = document.getElementById('tw-world-console');
        const form  = document.getElementById('tw-anchor-form');
        const audio = document.getElementById('tw-glitch-sound');
        const root  = document.getElementById('tw-deployment-root');

        let dataStore = { camps: [], worlds: [] };

        async function init() {
            log.innerText = "> System: Calibrating Uplink with The Weave...";
            const h = { "apikey": cfg.key, "Authorization": `Bearer ${cfg.key}` };

            try {
                const [rC, rW] = await Promise.all([
                    fetch(
                        cfg.url +
                        "rest/v1/cyber_campaign_without_world" +
                        "?select=id,name,world_type,wp_user_id" +
                        "&wp_user_id=eq." + cfg.uid +
                        "&order=created_at.desc",
                        { headers: h }
                    ),
                    fetch(
                        cfg.url +
                        "rest/v1/cyber_worlds" +
                        "?select=id,name,wp_user_id" +
                        "&wp_user_id=eq." + cfg.uid +
                        "&order=created_at.desc",
                        { headers: h }
                    )
                ]);

                if (!rC.ok || !rW.ok) {
                    const cTxt = await rC.text();
                    const wTxt = await rW.text();
                    console.error('Campaign fetch error:', rC.status, cTxt);
                    console.error('Worlds fetch error:',   rW.status, wTxt);
                    log.innerText = "> Error: Supabase HTTP " + rC.status + " / " + rW.status;
                    log.style.color = "#ff0055";
                    return;
                }

                dataStore.camps  = await rC.json();
                dataStore.worlds = await rW.json();

                renderList('camp', dataStore.camps);
                renderList('world', dataStore.worlds);

                log.innerText = "> System: Field Agent authorized. Scan complete.";
                simulateLiveStats();
            } catch (e) {
                console.error('INIT ERROR', e);
                log.innerText = "> Error: Uplink failed.";
                log.style.color = "#ff0055";
            }
        }

        function renderList(type, list, filter = "") {
            const el = (type === 'camp') ? selC : selW;
            el.innerHTML = "";

            const filtered = list.filter(i =>
                (i.name || "").toLowerCase().includes(filter.toLowerCase())
            );

            if (filtered.length === 0) {
                el.appendChild(new Option("-- NO DATA --", ""));
                return;
            }

            filtered.forEach(i => {
                let label = (i.name || "").toUpperCase();
                if (type === 'camp' && typeof i.world_type !== "undefined" && i.world_type !== null) {
                    label += " [TYPE " + i.world_type + "]";
                }
                el.appendChild(new Option(label, i.id));
            });
        }

        function simulateLiveStats() {
            const latencyEl = document.getElementById('stat-latency');
            setInterval(() => {
                if (!latencyEl) return;
                const newVal = (0.020 + Math.random() * 0.01).toFixed(3);
                latencyEl.innerText = newVal;
                if (Math.random() > 0.8) {
                    latencyEl.classList.add('n-glitch');
                    setTimeout(() => latencyEl.classList.remove('n-glitch'), 150);
                }
            }, 1200);
        }

        document.getElementById('f-camp').oninput  = (e) => renderList('camp',  dataStore.camps,  e.target.value);
        document.getElementById('f-world').oninput = (e) => renderList('world', dataStore.worlds, e.target.value);

        form.onchange = () => {
            btn.disabled = !(selC.value && selW.value);
            if (!btn.disabled) {
                log.style.color = "#00e5ff";
                log.innerText = "> System: Link established. Ready for Anchor.";
            }
        };

        form.onsubmit = async (e) => {
            e.preventDefault();
            btn.disabled = true;
            log.style.color = "#00e5ff";
            log.innerText = "> System: Weaving Splot threads...";

            const payload = {
                campaign_id: parseInt(selC.value),
                world_id:    parseInt(selW.value),
                creator_wp_id: cfg.uid
            };

            try {
                const res = await fetch(cfg.url + "rest/v1/cyber_campaign_worlds", {
                    method: "POST",
                    headers: {
                        "apikey": cfg.key,
                        "Authorization": `Bearer ${cfg.key}`,
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(payload)
                });

                if (res.ok) {
                    if (audio) audio.play();
                    root.classList.add('tw-glitch-shake');
                    log.style.color = "#adff00";
                    log.innerText = "> System: ANCHOR SUCCESSFUL. REALITY SYNCED.";
                    setTimeout(() => window.location.href = '/deployments/', 1500);
                } else {
                    const txt = await res.text();
                    console.error('Anchor error:', res.status, txt);
                    throw new Error("Supabase rejection");
                }
            } catch (err) {
                console.error('ANCHOR SUBMIT ERROR', err);
                log.style.color = "#ff0055";
                log.innerText = "> Error: Deployment failed.";
                btn.disabled = false;
            }
        };

        init();
    })();
    </script>

    <style>
        .tw-deployment-main-container { max-width: 1200px; margin: 40px auto; font-family: 'Chakra Petch', sans-serif; background: #000; border: 1px solid #1a1a1a; position: relative; overflow: hidden; }
        .tw-briefing-hero { position: relative; height: 250px; background: #111 url('https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&q=80&w=1200') center/cover; display: flex; align-items: center; padding: 0 40px; border-bottom: 2px solid #adff00; }
        .tw-hero-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to right, #000 40%, transparent 100%); }
        .tw-hero-content { position: relative; z-index: 5; display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .tw-hero-text h1 { color: #adff00; font-size: 2.5rem; margin: 0; line-height: 1; letter-spacing: 2px; }
        .tw-hero-text p { color: #888; max-width: 450px; font-size: 0.9rem; margin-top: 10px; }
        .tw-label-alt { color: #ff0055; font-size: 0.7rem; font-weight: bold; letter-spacing: 2px; display: block; margin-bottom: 5px; }
        .tw-hero-stats { display: flex; gap: 30px; }
        .tw-hero-stat-item { display: flex; flex-direction: column; align-items: flex-end; }
        .tw-hero-stat-item .n { color: #adff00; font-size: 1.6rem; font-weight: 900; line-height: 1; font-family: monospace; transition: 0.1s; }
        .tw-hero-stat-item .l { color: #444; font-size: 0.6rem; letter-spacing: 1px; font-weight: bold; margin-top: 4px; }
        .tw-pulse-stat .n { color: #00e5ff; text-shadow: 0 0 10px rgba(0, 229, 255, 0.4); animation: tw-status-pulse 2s infinite; }
        @keyframes tw-status-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .n-glitch { filter: brightness(2) contrast(1.5); transform: scale(1.05); }
        .tw-deploy-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 40px; padding: 40px; }
        .tw-console-box { background: #050505; border-left: 3px solid #00e5ff; padding: 15px; font-family: monospace; font-size: 0.8rem; color: #00e5ff; margin-bottom: 30px; box-shadow: inset 0 0 10px rgba(0,229,255,0.05); }
        .tw-selection-group { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .tw-field-box label { display: block; color: #adff00; font-size: 0.75rem; margin-bottom: 10px; font-weight: bold; text-transform: uppercase; }
        .tw-input-cyber { width: 100%; background: #111; border: 1px solid #333; color: #fff; padding: 10px; font-size: 0.8rem; margin-bottom: 5px; border-radius: 0; outline: none; }
        .tw-input-cyber:focus { border-color: #adff00; }
        .tw-select-cyber { width: 100%; background: #080808; border: 1px solid #222; color: #00e5ff; padding: 10px; font-size: 0.9rem; outline: none; border-radius: 0; cursor: pointer; }
        .tw-select-cyber option { padding: 8px; }
        .tw-btn-deploy { width: 100%; padding: 20px; background: #adff00; color: #000; border: none; font-weight: 900; font-size: 1rem; cursor: pointer; clip-path: polygon(0 0, 98% 0, 100% 20%, 100% 100%, 2% 100%, 0 80%); transition: 0.3s; letter-spacing: 2px; }
        .tw-btn-deploy:hover:not(:disabled) { background: #fff; transform: translateY(-2px); }
        .tw-btn-deploy:disabled { background: #1a1a1a; color: #333; cursor: not-allowed; }
        .tw-deploy-sidebar { }
        .tw-sidebar-card { background: #0a0a0a; border: 1px solid #1a1a1a; padding: 25px; border-radius: 2px; }
        .tw-sidebar-card h4 { color: #adff00; margin-top: 0; font-size: 0.9rem; letter-spacing: 1px; }
        .tw-sidebar-card p { color: #666; font-size: 0.85rem; line-height: 1.6; }
        .tw-glitch-shake { animation: tw-shake 0.3s cubic-bezier(.36,.07,.19,.97) both; border-color: #ff0055 !important; box-shadow: 0 0 40px rgba(255, 0, 85, 0.4); }
        @keyframes tw-shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
            40%, 60% { transform: translate3d(4px, 0, 0); }
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('tw_connect_campaign_world', 'tw_connect_campaign_world_final');
