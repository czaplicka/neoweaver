/* NeoWeaver — Deployment connector (campaign ↔ world)
 * Config injected via wp_localize_script as window.twDeploymentCfg
 * v21: replaced direct Supabase REST calls with WP REST API endpoints
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const cfg   = window.twDeploymentCfg || {};
        const selC  = document.getElementById('s-camp');
        const selW  = document.getElementById('s-world');
        const btn   = document.getElementById('b-connect');
        const log   = document.getElementById('tw-world-console');
        const form  = document.getElementById('tw-anchor-form');
        const audio = document.getElementById('tw-glitch-sound');
        const root  = document.getElementById('tw-deployment-root');

        if (root && window.location.hash === '#tw-deployment-root') {
            setTimeout(function () {
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }

        if (!selC || !selW || !btn || !log || !form) return;

        let dataStore     = { camps: [], worlds: [] };
        let statsInterval = null;
        let isSubmitting  = false;

        /* ── Helpers ── */
        function debounce(fn, ms) {
            let t;
            return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
        }

        function setLog(msg, color) {
            log.style.color = color || '#00e5ff';
            log.innerText   = msg;
        }

        /* ── WP REST headers (nonce zamiast anon key) ── */
        function restHeaders() {
            return {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce
            };
        }

        /* ── Render <select> list ── */
        function renderList(type, list, filter) {
            const el      = type === 'camp' ? selC : selW;
            const filterL = (filter || '').toLowerCase().trim();
            const filtered = list.filter(i => (i.name || '').toLowerCase().includes(filterL));

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

        /* ── Live latency animation ── */
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

        window.addEventListener('beforeunload', () => { if (statsInterval) clearInterval(statsInterval); });

        /* ── Button state ── */
        function updateButtonState() {
            btn.disabled = !(selC.value && selW.value);
            if (!btn.disabled) setLog('> System: Link established. Ready for Anchor.');
        }
        selC.addEventListener('change', updateButtonState);
        selW.addEventListener('change', updateButtonState);

        /* ── Filter inputs ── */
        document.getElementById('f-camp').addEventListener(
            'input', debounce(e => renderList('camp',  dataStore.camps,  e.target.value), 50)
        );
        document.getElementById('f-world').addEventListener(
            'input', debounce(e => renderList('world', dataStore.worlds, e.target.value), 50)
        );

        /* ── Init: fetch via WP REST (nie bezpośrednio Supabase) ── */
        async function init() {
            setLog('> System: Calibrating Uplink with The Weave...');

            try {
                const [rC, rW, rLinked] = await Promise.all([
                    fetch(cfg.restUrl + 'campaigns/list-unlinked', {
                        headers: restHeaders(), credentials: 'same-origin'
                    }),
                    fetch(cfg.restUrl + 'worlds/list', {
                        headers: restHeaders(), credentials: 'same-origin'
                    }),
                    fetch(cfg.restUrl + 'deployments/list', {
                        headers: restHeaders(), credentials: 'same-origin'
                    })
                ]);

                if (!rC.ok || !rW.ok || !rLinked.ok) {
                    const statuses = [rC.status, rW.status, rLinked.status];
                    console.error('Fetch error statuses:', statuses);
                    setLog('> Error: REST HTTP ' + statuses.join(' / '), '#ff0055');
                    return;
                }

                const [jsonC, jsonW, jsonLinked] = await Promise.all([
                    rC.json(), rW.json(), rLinked.json()
                ]);

                const allCamps  = (jsonC.data    || jsonC);
                const allWorlds = (jsonW.data    || jsonW);
                const linkedRows = (jsonLinked.data || jsonLinked);

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

        /* ── Submit: anchor campaign ↔ world via WP REST ── */
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (isSubmitting) return;

            isSubmitting = true;
            btn.disabled = true;
            setLog('> System: Weaving Splot threads...');

            try {
                const res = await fetch(cfg.restUrl + 'deployments/create', {
                    method:  'POST',
                    headers: restHeaders(),
                    body:    JSON.stringify({
                        campaign_id: selC.value,
                        world_id:    selW.value
                    }),
                    credentials: 'same-origin'
                });

                const json = await res.json();

                if (!res.ok || !json.success) {
                    const msg = (json.data && json.data.message) || json.message || 'Unknown error';
                    throw new Error('World link failed: ' + msg);
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
                btn.disabled = false;
                isSubmitting = false;
            }
        });

        init();
    });

})();
