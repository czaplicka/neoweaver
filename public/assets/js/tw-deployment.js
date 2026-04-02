/* NeoWeaver — Deployment connector (campaign ↔ world)
 * Config injected via wp_localize_script as window.twDeploymentCfg
 * v18: no agent/character logic
 */
(function () {
    'use strict';

    /* Wait for DOM — shortcode renders inline, so DOMContentLoaded is safe */
    document.addEventListener('DOMContentLoaded', function () {

        const cfg   = window.twDeploymentCfg || {};
        const selC  = document.getElementById('s-camp');
        const selW  = document.getElementById('s-world');
        const btn   = document.getElementById('b-connect');
        const log   = document.getElementById('tw-world-console');
        const form  = document.getElementById('tw-anchor-form');
        const audio = document.getElementById('tw-glitch-sound');
        const root  = document.getElementById('tw-deployment-root');

        /* Bail silently if shortcode not on this page */
        if (!selC || !selW || !btn || !log || !form) return;

        let dataStore     = { camps: [], worlds: [] };
        let statsInterval = null;
        let isSubmitting  = false;

        /* ── Helpers ── */
        function debounce(fn, ms) {
            let t;
            return function (...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), ms);
            };
        }

        function setLog(msg, color) {
            log.style.color = color || '#00e5ff';
            log.innerText   = msg;
        }

        /* ── Render a <select> list ── */
        function renderList(type, list, filter) {
            const el      = type === 'camp' ? selC : selW;
            const filterL = (filter || '').toLowerCase().trim();

            const filtered = list.filter(i =>
                (i.name || '').toLowerCase().includes(filterL)
            );

            el.innerHTML = '';

            if (filtered.length === 0) {
                el.appendChild(new Option('-- NO DATA --', ''));
                return;
            }

            const frag = document.createDocumentFragment();
            filtered.forEach(i => {
                let label = (i.name || '').toUpperCase();
                if (type === 'camp' && i.world_type != null) {
                    label += ' [TYPE ' + i.world_type + ']';
                }
                frag.appendChild(new Option(label, i.id));
            });
            el.appendChild(frag);
        }

        /* ── Live latency stat animation ── */
        function simulateLiveStats() {
            const latencyEl = document.getElementById('stat-latency');
            if (!latencyEl) return;
            statsInterval = setInterval(() => {
                latencyEl.innerText = (0.020 + Math.random() * 0.01).toFixed(3);
                if (Math.random() > 0.8) {
                    latencyEl.classList.add('n-glitch');
                    setTimeout(() => latencyEl.classList.remove('n-glitch'), 150);
                }
            }, 1200);
        }

        window.addEventListener('beforeunload', () => {
            if (statsInterval) clearInterval(statsInterval);
        });

        /* ── Button state ── */
        function updateButtonState() {
            btn.disabled = !(selC.value && selW.value);
            if (!btn.disabled) {
                setLog('> System: Link established. Ready for Anchor.');
            }
        }
        selC.addEventListener('change', updateButtonState);
        selW.addEventListener('change', updateButtonState);

        /* ── Filter inputs ── */
        document.getElementById('f-camp').addEventListener(
            'input',
            debounce(e => renderList('camp',  dataStore.camps,  e.target.value), 50)
        );
        document.getElementById('f-world').addEventListener(
            'input',
            debounce(e => renderList('world', dataStore.worlds, e.target.value), 50)
        );

        /* ── Init: fetch campaigns, worlds, already-linked ── */
        async function init() {
            setLog('> System: Calibrating Uplink with The Weave...');

            const h = {
                'apikey':        cfg.key,
                'Authorization': 'Bearer ' + cfg.key
            };

            try {
                const [rC, rW, rLinked] = await Promise.all([
                    fetch(
                        cfg.url + 'rest/v1/cyber_campaign' +
                        '?select=id,name,world_type' +
                        '&wp_user_id=eq.' + cfg.uid +
                        '&order=created_at.desc',
                        { headers: h }
                    ),
                    fetch(
                        cfg.url + 'rest/v1/cyber_worlds' +
                        '?select=id,name' +
                        '&wp_user_id=eq.' + cfg.uid +
                        '&order=created_at.desc',
                        { headers: h }
                    ),
                    fetch(
                        cfg.url + 'rest/v1/cyber_campaign_worlds' +
                        '?select=campaign_id' +
                        '&creator_wp_id=eq.' + cfg.uid,
                        { headers: h }
                    )
                ]);

                if (!rC.ok || !rW.ok || !rLinked.ok) {
                    const statuses = [rC.status, rW.status, rLinked.status];
                    console.error('Fetch error statuses:', statuses);
                    setLog('> Error: Supabase HTTP ' + statuses.join(' / '), '#ff0055');
                    return;
                }

                const [allCamps, allWorlds, linkedRows] = await Promise.all([
                    rC.json(), rW.json(), rLinked.json()
                ]);

                /* Filter out campaigns already assigned to a world */
                const linkedIds = new Set(linkedRows.map(r => String(r.campaign_id)));
                dataStore.camps  = allCamps.filter(c => !linkedIds.has(String(c.id)));
                dataStore.worlds = allWorlds;

                renderList('camp',  dataStore.camps);
                renderList('world', dataStore.worlds);

                setLog('> System: Field Agent authorized. Scan complete.');
                simulateLiveStats();

            } catch (e) {
                console.error('INIT ERROR', e);
                setLog('> Error: Uplink failed.', '#ff0055');
            }
        }

        /* ── Submit: POST to cyber_campaign_worlds only ── */
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (isSubmitting) return;

            isSubmitting = true;
            btn.disabled = true;
            setLog('> System: Weaving Splot threads...');

            const payload = {
                campaign_id:   parseInt(selC.value, 10),
                world_id:      parseInt(selW.value, 10),
                creator_wp_id: parseInt(cfg.uid, 10)
            };

            const apiHeaders = {
                'apikey':        cfg.key,
                'Authorization': 'Bearer ' + cfg.key,
                'Content-Type':  'application/json',
                'Prefer':        'return=minimal'
            };

            try {
                const res = await fetch(cfg.url + 'rest/v1/cyber_campaign_worlds', {
                    method:  'POST',
                    headers: apiHeaders,
                    body:    JSON.stringify(payload)
                });

                if (!res.ok) {
                    const txt = await res.text();
                    console.error('World anchor error:', res.status, txt);
                    throw new Error('World link failed: ' + res.status);
                }

                if (audio) audio.play().catch(() => {});
                root.classList.add('tw-glitch-shake');
                setLog('> System: ANCHOR SUCCESSFUL. REALITY SYNCED.', '#adff00');

                setTimeout(() => {
                    window.location.href = (window.location.origin || '') + '/deployments/';
                }, 1500);

            } catch (err) {
                console.error('ANCHOR SUBMIT ERROR', err);
                setLog('> Error: Deployment failed. ' + err.message, '#ff0055');
                btn.disabled  = false;
                isSubmitting  = false;
            }
        });

        init();
    });

})();
