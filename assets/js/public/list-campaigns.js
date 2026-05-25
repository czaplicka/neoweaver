jQuery(document).ready(function($) {

    const GLOBAL_NONCE      = twCampaignData.nonce;
    const REST_NONCE        = twCampaignData.restNonce;
    const REST_URL          = twCampaignData.restUrl;
    const SESSION_START_URL = twCampaignData.sessionUrl;

    function resetBtn(btn, label) {
        btn.prop('disabled', false).text(label).css('opacity', '1');
    }

    /* ── Delete campaign via WP REST (nie Supabase bezpośrednio) ── */
    $('.tw-delete-campaign-btn').on('click', async function(e) {
        e.preventDefault();
        const btn      = $(this);
        const campId   = btn.data('id');
        const campName = btn.data('name');
        if (!confirm('CONFIRM TERMINATION OF DEPLOYMENT: ' + campName + ' ?')) return;
        btn.prop('disabled', true).text('TERMINATING...');

        try {
            const res = await fetch(REST_URL + 'campaigns/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   REST_NONCE
                },
                body: JSON.stringify({ campaign_id: campId }),
                credentials: 'same-origin'
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                const msg = (json.data && json.data.message) || json.message || 'Grid Denied.';
                alert('TERMINATION FAILED: ' + msg);
                resetBtn(btn, 'TERMINATE');
                return;
            }
            $('#campaign-card-' + campId).css({ opacity: '0', 'pointer-events': 'none' });
            setTimeout(() => window.location.reload(), 1200);
        } catch (err) {
            alert('TERMINATION FAILED: CLIENT EXCEPTION');
            resetBtn(btn, 'TERMINATE');
        }
    });

    /* ── Enter Matrix ── */
    $('.enter-matrix').on('click', function(e) {
        e.preventDefault();
        const btn         = $(this);
        const campId      = btn.data('id');
        const characterId = btn.data('character') || null;
        const mode        = String(btn.data('mode') || 'SOLO').toUpperCase();
        if (!campId) { alert('DEPLOYMENT ERROR: Missing campaign ID.'); return; }

        if (mode === 'SOLO') {
            btn.text('INITIALIZING...').css('opacity', '0.7');
            const fd = new FormData();
            fd.append('campaign_id',  campId);
            fd.append('character_id', characterId || '');
            fd.append('security',     GLOBAL_NONCE);
            fetch(SESSION_START_URL, {
                method: 'POST', headers: { 'X-WP-Nonce': REST_NONCE }, body: fd, credentials: 'same-origin',
            })
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(response => {
                if (response.success) {
                    window.location.href = twCampaignData.terminalUrl;
                } else {
                    const data = response.data || {};
                    if (data.message === 'no_character') {
                        window.location.href = twCampaignData.agentsUrl + campId;
                    } else {
                        alert('SESSION INIT FAILED: ' + (data.message || 'Unknown interference'));
                        resetBtn(btn, 'ENTER MATRIX');
                    }
                }
            })
            .catch(err => { alert('SESSION INIT FAILED: network error'); resetBtn(btn, 'ENTER MATRIX'); });

        } else {
            /* MULTIPLAYER: signup przez WP REST, nie bezpośrednio Supabase */
            btn.text('LINKING...').css('opacity', '0.7');
            if (!characterId) { window.location.href = twCampaignData.agentsUrl + campId; return; }

            fetch(REST_URL + 'campaigns/signup', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   REST_NONCE
                },
                body: JSON.stringify({ campaign_id: campId, character_id: characterId }),
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(json => {
                if (json.success) {
                    window.location.href = twCampaignData.lobbyUrl + campId;
                } else {
                    const msg = (json.data && json.data.message) || json.message || 'Unknown';
                    alert('SIGNUP FAILED: ' + msg);
                    resetBtn(btn, 'ENTER MATRIX');
                }
            })
            .catch(err => { alert('SIGNUP FAILED: EXCEPTION'); resetBtn(btn, 'ENTER MATRIX'); });
        }
    });

    /* ── Copy join hash ── */
    $('.tw-copy-join-btn').on('click', async function(e) {
        e.preventDefault();
        const btn  = $(this);
        const code = btn.data('code');
        if (!code) { alert('NO HASH DETECTED.'); return; }
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(code);
            } else {
                const temp = $('<input>'); $('body').append(temp); temp.val(code).select(); document.execCommand('copy'); temp.remove();
            }
            btn.text('HASH COPIED');
            setTimeout(() => btn.text('COPY HASH'), 2000);
        } catch (err) { alert('COPY FAILED.'); }
    });
});
