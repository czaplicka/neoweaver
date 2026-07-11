jQuery(document).ready(function($) {

    const GLOBAL_NONCE      = window.twCampaignData?.nonce || '';
    const REST_NONCE        = window.twCampaignData?.restNonce || '';
    const REST_URL          = window.twCampaignData?.restUrl || '';
    const SESSION_START_URL = window.twCampaignData?.sessionUrl || '';
    const TERMINAL_URL      = window.twCampaignData?.terminalUrl || '/terminal/';
    const AGENTS_URL        = window.twCampaignData?.agentsUrl || '/agents/?campaign_id=';
    const LOBBY_URL         = window.twCampaignData?.lobbyUrl || '/lobby/?campaign_id=';

    function resetBtn(btn, label) {
        btn.prop('disabled', false).text(label).css('opacity', '1');
    }

    function safeJson(response) {
        return response.text().then(function(text) {
            try {
                return text ? JSON.parse(text) : {};
            } catch (e) {
                return {};
            }
        });
    }

    /* DELETE CAMPAIGN */
    $('.tw-delete-campaign-btn').on('click', async function(e) {
        e.preventDefault();

        const btn      = $(this);
        const campId   = btn.data('id');
        const campName = btn.data('name');

        if (!campId) {
            alert('TERMINATION FAILED: Missing campaign ID');
            return;
        }

        if (!REST_URL) {
            alert('TERMINATION FAILED: Missing REST URL');
            return;
        }

        if (!confirm('CONFIRM TERMINATION OF DEPLOYMENT: ' + campName + ' ?')) {
            return;
        }

        btn.prop('disabled', true).text('TERMINATING...').css('opacity', '0.7');

        try {
            const res = await fetch(REST_URL + 'campaigns/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': REST_NONCE
                },
                body: JSON.stringify({ campaign_id: campId }),
                credentials: 'same-origin'
            });

            const json = await safeJson(res);

            if (!res.ok || !json.success) {
                const msg = (json.data && json.data.message) || json.message || ('HTTP ' + res.status);
                alert('TERMINATION FAILED: ' + msg);
                resetBtn(btn, 'TERMINATE');
                return;
            }

            $('#campaign-card-' + campId).css({
                opacity: '0',
                pointerEvents: 'none'
            });

            setTimeout(function() {
                window.location.reload();
            }, 1200);

        } catch (err) {
            alert('TERMINATION FAILED: CLIENT EXCEPTION');
            resetBtn(btn, 'TERMINATE');
        }
    });

    /* ENTER MATRIX */
    $('.enter-matrix').on('click', function(e) {
        e.preventDefault();

        const btn         = $(this);
        const campId      = btn.data('id');
        const characterId = btn.data('character') || null;
        const mode        = String(btn.data('mode') || 'SOLO').toUpperCase();
        const defaultLabel = btn.data('label') || 'ENTER MATRIX';

        if (!campId) {
            alert('DEPLOYMENT ERROR: Missing campaign ID.');
            return;
        }

        if (mode === 'SOLO') {
            if (!SESSION_START_URL) {
                alert('SESSION INIT FAILED: Missing session endpoint');
                return;
            }

            btn.prop('disabled', true).text('INITIALIZING...').css('opacity', '0.7');

            const fd = new FormData();
            fd.append('campaign_id', campId);
            fd.append('character_id', characterId || '');
            fd.append('security', GLOBAL_NONCE);

            fetch(SESSION_START_URL, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': REST_NONCE
                },
                body: fd,
                credentials: 'same-origin'
            })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function(response) {
                if (response.success) {
                    window.location.href = TERMINAL_URL;
                    return;
                }

                const data = response.data || {};
                if (data.message === 'no_character') {
                    window.location.href = AGENTS_URL + encodeURIComponent(campId);
                    return;
                }

                alert('SESSION INIT FAILED: ' + (data.message || 'Unknown interference'));
                resetBtn(btn, defaultLabel);
            })
            .catch(function() {
                alert('SESSION INIT FAILED: network error');
                resetBtn(btn, defaultLabel);
            });

            return;
        }

        /* MULTIPLAYER */
        btn.prop('disabled', true).text('LINKING...').css('opacity', '0.7');

        if (!characterId) {
            window.location.href = AGENTS_URL + encodeURIComponent(campId);
            return;
        }

        /* 
         * FIX:
         * Nie wołamy REST_URL + 'campaigns/signup',
         * bo ten endpoint nie istnieje w repo.
         * Multiplayer flow idzie do lobby.
         */
        window.location.href = LOBBY_URL + encodeURIComponent(campId);
    });

    /* COPY JOIN HASH */
    $('.tw-copy-join-btn').on('click', async function(e) {
        e.preventDefault();

        const btn  = $(this);
        const code = btn.data('code');

        if (!code) {
            alert('NO HASH DETECTED.');
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

            setTimeout(function() {
                btn.text('COPY HASH');
            }, 2000);

        } catch (err) {
            alert('COPY FAILED.');
        }
    });
});
