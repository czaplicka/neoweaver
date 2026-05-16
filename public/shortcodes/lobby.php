<?php
/**
 * NEOWEAVE LOBBY SHORTCODE + AJAX USER LABELS + AVATARS + ONLINE DOT + LAUNCH/READY + AUTO-JOIN
 *
 * SECURITY FIX (Bug #4):
 *   - neoweave_launch_campaign : check_ajax_referer( 'neoweave_launch', 'nonce' )
 *   - neoweave_user_labels     : check_ajax_referer( 'neoweave_labels', 'nonce' )
 *                                + removed wp_ajax_nopriv_ (no reason to expose display
 *                                  names to unauthenticated users)
 *   Both nonces are injected into the lobby element as data-* attributes by the
 *   shortcode so the JS never needs a hard-coded value.
 *
 * BUG #5 FIX — online dot now uses last_seen_at heartbeat:
 *   - JS sends POST neoweave_lobby_heartbeat every 20 s (see ajax-lobby-heartbeat.php)
 *   - enrichSignups() reads s.last_seen_at instead of s.created_at
 *   - Online threshold: last_seen_at within the last 90 s (3 missed heartbeats)
 *   - fetchSignups() selects last_seen_at from cyber_campaign_signups
 *   - Nonce neoweave_heartbeat injected as data-nonce-heartbeat
 *
 * BUG-FIX (UUID campaign_id): cyber_campaign.id is a UUID string.
 *   The previous code fetched $_GET['campaign_id'] with intval(), which collapses
 *   any UUID to 0, making every Supabase query return empty results.
 *   Fixed: use sanitize_text_field + preg_replace to preserve the UUID.
 *
 * BUG-FIX (missing return after wp_send_json_error):
 *   neoweave_launch_campaign() had 5 early-exit calls to wp_send_json_error()
 *   with no `return` after them. PHP continued executing the rest of the
 *   function after the error response was sent (e.g., checking host ID even
 *   when not logged in, inserting sessions even when world_id was invalid).
 *   Fixed: `return` added after every wp_send_json_error() call.
 *
 * BUG-FIX (UUID character_id / world_id in session insert):
 *   The sessions payload used intval() on character_id and world_id from
 *   Supabase, both of which are UUID strings. intval() on a UUID returns 0,
 *   causing the FK constraints to reject the insert.
 *   Fixed: UUIDs are preserved as strings via sanitize_text_field().
 *
 * BUG-FIX (missing nonce in leave-lobby FormData):
 *   The neoweave_leave_lobby JS handler built a FormData with action +
 *   campaign_id but omitted the nonce field. The PHP handler calls
 *   check_ajax_referer( 'neoweave_heartbeat', 'nonce' ), so every leave
 *   attempt failed with a nonce error.
 *   Fixed: formData.append('nonce', nonceHeartbeat) added.
 */

add_shortcode( 'neoweave_lobby', 'neoweave_lobby_terminal' );

function neoweave_lobby_terminal() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<div class="neoweave-terminal">ERROR: OPERATOR NOT IDENTIFIED. ACCESS DENIED.</div>';
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return '<div class="neoweave-terminal">ERROR: SUPABASE LINK OFFLINE. CHECK TW_SUPABASE_* IN WP-CONFIG.</div>';
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	// BUG-FIX: cyber_campaign.id is a UUID string. intval() collapses any UUID
	// to 0, so every Supabase query returns empty. Preserve the raw value and
	// sanitize by stripping characters that are illegal in a UUID/ID string.
	$raw_campaign_id = isset( $_GET['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
	}

	// kampania: nazwa + host_id
	$campaign_name    = 'UNKNOWN';
	$campaign_host_id = 0;
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=name,wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $camp_res ) ) {
		$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
		if ( is_array( $camp_data ) && ! empty( $camp_data[0] ) ) {
			$campaign_name    = $camp_data[0]['name']       ?? 'UNKNOWN';
			$campaign_host_id = intval( $camp_data[0]['wp_user_id'] ?? 0 );
		}
	}

	$ajax_url = admin_url( 'admin-ajax.php' );

	// Per-request nonces.
	$nonce_launch    = wp_create_nonce( 'neoweave_launch' );
	$nonce_labels    = wp_create_nonce( 'neoweave_labels' );
	$nonce_heartbeat = wp_create_nonce( 'neoweave_heartbeat' ); // Bug #5

	$user_map      = [];
	$current_user  = wp_get_current_user();
	if ( $current_user && $current_user->ID ) {
		$user_map[ $current_user->ID ] = $current_user->display_name;
	}
	$user_map_json = esc_attr( wp_json_encode( $user_map ) );

	ob_start();
	?>
	<style>
	@import url('https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap');
	.neoweave-terminal {
		background-color: #0a0c00; color: #adff00; font-family: 'Share Tech Mono', monospace;
		padding: 30px; border: 2px solid #adff00; position: relative; max-width: 700px; margin: 20px auto;
		text-transform: uppercase; box-shadow: 0 0 20px rgba(173, 255, 0, 0.2);
	}
	.terminal-header { border-bottom: 1px solid #adff00; margin-bottom: 20px; padding-bottom: 10px; }
	.terminal-title { font-family: 'Chakra Petch', sans-serif; font-size: 1.2rem; font-weight: bold; }
	.terminal-status { margin-top: 5px; font-size: 0.9rem; }
	.blink { animation: blinker 1s linear infinite; }
	@keyframes blinker { 50% { opacity: 0; } }

	.squad-grid {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 15px;
		margin-top: 20px;
	}
	.squad-slot {
		border: 1px solid #adff00;
		padding: 12px;
		min-height: 80px;
		display: flex;
		align-items: center;
		position: relative;
	}
	.slot-body {
		font-size: 0.8rem;
		width: 100%;
		text-align: left;
		display: flex;
		align-items: center;
		gap: 10px;
	}
	.slot-empty { opacity: 0.6; }
	.slot-avatar {
		width: 60px; height: 60px; border-radius: 50%; border: 0px solid #adff00;
		object-fit: cover; background: #050600; flex-shrink: 0;
	}
	.slot-avatar.placeholder {
		display: flex; align-items: center; justify-content: center;
		font-size: 0.5rem; color: #555;
	}
	.slot-text-block { display: flex; flex-direction: column; gap: 2px; }
	.slot-text-line  { line-height: 1.2; }
	.online-dot {
		width: 8px; height: 8px; border-radius: 50%; background: #00ff55;
		box-shadow: 0 0 6px #00ff55; margin-left: auto;
		animation: onlinePulse 1.2s infinite; flex-shrink: 0;
	}
	.online-dot.offline { background: #444; box-shadow: none; animation: none; }
	@keyframes onlinePulse {
		0%   { transform: scale(1);   opacity: 1; }
		50%  { transform: scale(1.4); opacity: 0.4; }
		100% { transform: scale(1);   opacity: 1; }
	}
	.terminal-button {
		background: #adff00; color: #0a0c00; border: none; padding: 12px 20px;
		margin-top: 20px; width: 100%; font-family: 'Chakra Petch', sans-serif; font-weight: bold;
		cursor: pointer; text-align: center; text-decoration: none; display: inline-block;
	}
	.terminal-actions { display: flex; gap: 10px; margin-top: 25px; }
	.terminal-button.secondary {
		background: #0a0c00; color: #adff00; border: 1px solid #adff00;
	}
	</style>

	<div class="neoweave-terminal" id="neoweave-lobby"
		 data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
		 data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
		 data-user-map="<?php echo $user_map_json; ?>"
		 data-current-user="<?php echo esc_attr( get_current_user_id() ); ?>"
		 data-host-id="<?php echo esc_attr( $campaign_host_id ); ?>"
		 data-nonce-launch="<?php echo esc_attr( $nonce_launch ); ?>"
		 data-nonce-labels="<?php echo esc_attr( $nonce_labels ); ?>"
		 data-nonce-heartbeat="<?php echo esc_attr( $nonce_heartbeat ); ?>">
		<div class="terminal-header">
			<div class="terminal-title">SQUAD DEPLOYMENT: ID_<?php echo esc_html( $campaign_id ); ?></div>
			<div class="terminal-status">
				SCANNING FOR AGENT SIGNALS...<span class="blink">_</span><br>
				> NODE: [<?php echo esc_html( $campaign_name ); ?>]<br>
				> PROTOCOL: NEURAL_LINK_4_WAY
			</div>
		</div>

		<div class="squad-grid">
			<div class="squad-slot" id="squad-slot-1"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-2"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-3"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-4"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
		</div>

		<div class="terminal-actions">
			<button type="button" class="terminal-button" id="neoweave-launch-button">LAUNCH DEPLOYMENT</button>
			<button type="button" class="terminal-button secondary" id="neoweave-leave-button">LEAVE LOBBY</button>
		</div>
	</div>

	<script>
	(function() {

		/**
		 * Online threshold: player is considered online if last_seen_at
		 * is within the last 90 seconds (= 3 missed 20-second heartbeats).
		 */
		const ONLINE_THRESHOLD_MS  = 90 * 1000;
		const HEARTBEAT_INTERVAL_MS = 20 * 1000;

		function initLobbyWithClient(client) {
			const lobbyEl = document.getElementById('neoweave-lobby');
			if (!lobbyEl) return;

			const campaignId      = lobbyEl.getAttribute('data-campaign-id');
			const ajaxUrl         = lobbyEl.getAttribute('data-ajax-url');
			const currentUserId   = lobbyEl.getAttribute('data-current-user');
			const hostId          = lobbyEl.getAttribute('data-host-id');
			const nonceLaunch     = lobbyEl.getAttribute('data-nonce-launch');
			const nonceLabels     = lobbyEl.getAttribute('data-nonce-labels');
			const nonceHeartbeat  = lobbyEl.getAttribute('data-nonce-heartbeat');

			const userMapAttr = lobbyEl.getAttribute('data-user-map');
			let userMap = {};
			if (userMapAttr) {
				try { userMap = JSON.parse(userMapAttr); }
				catch (e) { console.error('LOBBY: failed to parse user map', e); }
			}

			const slotEls = [
				document.getElementById('squad-slot-1'),
				document.getElementById('squad-slot-2'),
				document.getElementById('squad-slot-3'),
				document.getElementById('squad-slot-4'),
			];

			// ─────────────────────────────────────────────
			// HEARTBEAT  (Bug #5)
			// Fires immediately on load, then every 20 s.
			// Updates last_seen_at on cyber_campaign_signups
			// so other players see a green dot.
			// ─────────────────────────────────────────────
			function sendHeartbeat() {
				if (!ajaxUrl || !campaignId) return;
				const fd = new FormData();
				fd.append('action',      'neoweave_lobby_heartbeat');
				fd.append('nonce',       nonceHeartbeat);
				fd.append('campaign_id', campaignId);
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.catch(e => console.warn('LOBBY: heartbeat failed', e));
			}
			sendHeartbeat();
			const heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);

			// Stop heartbeat when tab is hidden / closed
			document.addEventListener('visibilitychange', () => {
				if (document.hidden) clearInterval(heartbeatTimer);
				else { sendHeartbeat(); setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS); }
			});

			function renderSlots(signups) {
				signups.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

				for (let i = 0; i < 4; i++) {
					const slot = slotEls[i];
					if (!slot) continue;
					const body = slot.querySelector('.slot-body');
					if (!body) continue;

					const signup = signups[i];
					if (signup) {
						slot.classList.remove('slot-empty');
						body.classList.remove('slot-empty');
						body.innerHTML = '';

						const charName   = signup.character_name || ('#' + signup.character_id);
						const userName   = signup.user_name      || ('USER_' + signup.wp_user_id);
						const readyLabel = signup.is_ready ? ' [READY]' : ' [IDLE]';

						const avatarUrl = signup.character_avatar || '';
						let avatarEl = document.createElement('div');
						avatarEl.className = 'slot-avatar placeholder';
						if (avatarUrl) {
							avatarEl = document.createElement('img');
							avatarEl.className = 'slot-avatar';
							avatarEl.src = avatarUrl;
							avatarEl.alt = charName;
						} else {
							avatarEl.textContent = 'AV';
						}

						const textBlock = document.createElement('div');
						textBlock.className = 'slot-text-block';

						const line1 = document.createElement('div');
						line1.className   = 'slot-text-line';
						line1.textContent = 'AGENT ' + charName + readyLabel;

						const line2 = document.createElement('div');
						line2.className   = 'slot-text-line';
						line2.textContent = 'OPERATOR ' + userName;

						textBlock.appendChild(line1);
						textBlock.appendChild(line2);

						const dot = document.createElement('div');
						dot.className = 'online-dot';
						if (signup._isOnline === false) { dot.classList.add('offline'); }

						body.appendChild(avatarEl);
						body.appendChild(textBlock);
						body.appendChild(dot);
					} else {
						slot.classList.add('slot-empty');
						body.classList.add('slot-empty');
						body.textContent = '// WAITING FOR SIGNAL //';
					}
				}
			}

			async function enrichSignups(rawSignups) {
				if (!Array.isArray(rawSignups) || !rawSignups.length) return [];

				const charIds = [...new Set(rawSignups.map(s => s.character_id).filter(Boolean))];
				const userIds = [...new Set(rawSignups.map(s => s.wp_user_id).filter(Boolean))];

				let charsById = {};

				try {
					if (charIds.length) {
						const { data: chars, error: charErr } = await client
							.from('cyber_characters')
							.select('id,name,avatar')
							.in('id', charIds);
						if (!charErr && Array.isArray(chars)) {
							chars.forEach(c => { charsById[c.id] = c; });
						} else {
							console.error('LOBBY: char lookup error', charErr);
						}
					}
				} catch (e) {
					console.error('LOBBY: enrichSignups char exception', e);
				}

				try {
					if (userIds.length && ajaxUrl) {
						const formData = new FormData();
						formData.append('action', 'neoweave_user_labels');
						formData.append('nonce', nonceLabels);
						userIds.forEach(id => formData.append('ids[]', id));

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success && json.data && json.data.map) {
							Object.assign(userMap, json.data.map);
						} else {
							console.error('LOBBY: user labels response error', json);
						}
					}
				} catch (e) {
					console.error('LOBBY: enrichSignups user exception', e);
				}

				const now = Date.now();

				return rawSignups.map(s => {
					// Bug #5 fix: use last_seen_at heartbeat, NOT created_at.
					// Treat NULL last_seen_at (no heartbeat yet) as offline.
					const seenAt   = s.last_seen_at ? new Date(s.last_seen_at).getTime() : 0;
					const isOnline = seenAt > 0 && (now - seenAt) < ONLINE_THRESHOLD_MS;
					return {
						...s,
						character_name:   charsById[s.character_id]?.name   || null,
						character_avatar: charsById[s.character_id]?.avatar || '',
						user_name:        userMap[String(s.wp_user_id)]    || null,
						_isOnline:        isOnline,
					};
				});
			}

			async function fetchSignups() {
				try {
					const { data, error } = await client
						.from('cyber_campaign_signups')
						// Bug #5: added last_seen_at to the select
						.select('campaign_id, wp_user_id, character_id, created_at, is_ready, last_seen_at')
						.eq('campaign_id', campaignId);
					if (error) { console.error('NEOWEAVE LOBBY: signups fetch error', error); return; }
					renderSlots(await enrichSignups(data || []));
				} catch (e) {
					console.error('NEOWEAVE LOBBY: exception while fetching signups', e);
				}
			}

			fetchSignups();
			setInterval(fetchSignups, 3000);

			async function watchForSessionAndRedirect() {
				try {
					const { data, error } = await client
						.from('cyber_game_sessions')
						.select('id, status')
						.eq('campaign_id', campaignId)
						.eq('wp_user_id', currentUserId)
						.eq('status', 'active')
						.limit(1);
					if (!error && data && data.length) {
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					}
				} catch (e) { console.error('SESSION WATCH ERROR', e); }
			}
			setInterval(watchForSessionAndRedirect, 4000);

			async function handleLaunchAsHost() {
				if (!ajaxUrl) { alert('LAUNCH FAILED: missing AJAX URL.'); return; }

				const formData = new FormData();
				formData.append('action',      'neoweave_launch_campaign');
				formData.append('campaign_id', campaignId);
				formData.append('nonce',       nonceLaunch);

				try {
					const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
					const json = await res.json();
					if (json && json.success) {
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					} else {
						const msg = (json && (json.data?.message || json.message)) || 'UNKNOWN ERROR';
						alert('LAUNCH FAILED: ' + msg);
					}
				} catch (e) {
					console.error('LAUNCH ERROR', e);
					alert('LAUNCH FAILED: CLIENT EXCEPTION');
				}
			}

			const launchBtn = document.getElementById('neoweave-launch-button');
			if (launchBtn) {
				if (String(currentUserId) === String(hostId)) {
					launchBtn.textContent = 'LAUNCH DEPLOYMENT';
					launchBtn.addEventListener('click', handleLaunchAsHost);
				} else {
					launchBtn.textContent = 'READY';
					let localReady = false;
					launchBtn.addEventListener('click', async function() {
						if (!campaignId || !currentUserId) return;
						const newReady = !localReady;
						try {
							const { error } = await client
								.from('cyber_campaign_signups')
								.update({ is_ready: newReady })
								.eq('campaign_id', campaignId)
								.eq('wp_user_id', currentUserId);
							if (error) { console.error('READY TOGGLE ERROR', error); return; }
							localReady = newReady;
							launchBtn.textContent = newReady ? 'READY ✓' : 'READY';
						} catch (e) { console.error('READY TOGGLE EXCEPTION', e); }
					});
				}
			}

			const leaveBtn = document.getElementById('neoweave-leave-button');
			if (leaveBtn && ajaxUrl) {
				leaveBtn.addEventListener('click', async function() {
					try {
						if (campaignId && currentUserId) {
							try {
								const { error: readyErr } = await client
									.from('cyber_campaign_signups')
									.update({ is_ready: false })
									.eq('campaign_id', campaignId)
									.eq('wp_user_id', currentUserId);
								if (readyErr) { console.error('LEAVE: ready reset error', readyErr); }
							} catch (e) { console.error('LEAVE: ready reset exception', e); }
						}

						// BUG-FIX: nonce was not appended — every leave attempt
						// failed with a nonce error from check_ajax_referer().
						const formData = new FormData();
						formData.append('action',      'neoweave_leave_lobby');
						formData.append('nonce',       nonceHeartbeat);
						formData.append('campaign_id', campaignId);

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success) { window.location.href = '/'; }
						else { console.error('NEOWEAVE LOBBY: leave failed', json); }
					} catch (e) { console.error('NEOWEAVE LOBBY: leave exception', e); }
				});
			}
		}

		function waitForTwSupabase() {
			if (window.twSupabase) {
				console.log('NEOWEAVE LOBBY: binding to global Supabase client');
				initLobbyWithClient(window.twSupabase);
			} else {
				setTimeout(waitForTwSupabase, 500);
			}
		}

		waitForTwSupabase();
	})();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * AJAX: mapa wp_user_id -> display_name dla lobby
 *
 * SECURITY: requires a valid nonce (neoweave_labels).
 * nopriv removed — display names must not leak to unauthenticated callers.
 */
add_action( 'wp_ajax_neoweave_user_labels', 'neoweave_user_labels' );
// add_action( 'wp_ajax_nopriv_neoweave_user_labels', ... ) intentionally omitted.

function neoweave_user_labels() {
	check_ajax_referer( 'neoweave_labels', 'nonce' );

	if ( empty( $_POST['ids'] ) || ! is_array( $_POST['ids'] ) ) {
		wp_send_json_error( [ 'message' => 'NO_IDS' ] );
		return;
	}

	$ids = array_unique( array_filter( array_map( 'intval', $_POST['ids'] ) ) );

	if ( empty( $ids ) ) {
		wp_send_json_success( [ 'map' => [] ] );
		return;
	}

	$users = get_users( [
		'include' => $ids,
		'fields'  => [ 'ID', 'display_name' ],
	] );

	$map = [];
	foreach ( $users as $u ) {
		$map[ $u->ID ] = $u->display_name;
	}

	wp_send_json_success( [ 'map' => $map ] );
}

/**
 * AJAX: host LAUNCH — creates cyber_game_sessions from campaign_signups
 *
 * SECURITY: requires a valid nonce (neoweave_launch).
 * nopriv not registered (login required by is_user_logged_in check below).
 *
 * BUG-FIX: Added `return` after every wp_send_json_error() call.
 * Previously, execution continued past early exits (e.g. the host-ID check
 * ran even when the user wasn't logged in, sessions were inserted even when
 * world_id was missing).
 *
 * BUG-FIX: character_id and world_id are UUID strings from Supabase.
 * Using intval() on them collapsed every UUID to 0. Fixed by keeping them
 * as strings via sanitize_text_field().
 */
add_action( 'wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign' );

function neoweave_launch_campaign() {
	check_ajax_referer( 'neoweave_launch', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return; // BUG-FIX: was missing
	}

	// BUG-FIX: campaign_id is a UUID string; intval() collapses it to 0.
	$raw_campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return; // BUG-FIX: was missing
	}

	$current_user_id = get_current_user_id();

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return; // BUG-FIX: was missing
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	// 1) host check
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( is_wp_error( $camp_res ) ) {
		wp_send_json_error( [ 'message' => 'campaign_fetch_error' ] );
		return; // BUG-FIX: was missing
	}
	$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
	$host_id   = isset( $camp_data[0]['wp_user_id'] ) ? intval( $camp_data[0]['wp_user_id'] ) : 0;
	if ( $host_id !== $current_user_id ) {
		wp_send_json_error( [ 'message' => 'not_host' ] );
		return; // BUG-FIX: was missing
	}

	// 1b) world_id — UUID string, must not be cast to int
	$world_id  = null;
	$world_url = $supabase_rest . 'cyber_campaign_worlds?campaign_id=eq.' . $campaign_id . '&select=world_id';
	$world_res = wp_remote_get( $world_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $world_res ) ) {
		$world_data = json_decode( wp_remote_retrieve_body( $world_res ), true );
		if ( is_array( $world_data ) && ! empty( $world_data[0]['world_id'] ) ) {
			// BUG-FIX: world_id is a UUID string; keep it as-is.
			$world_id = sanitize_text_field( $world_data[0]['world_id'] );
		}
	}
	if ( ! $world_id ) {
		wp_send_json_error( [ 'message' => 'no_world_linked' ] );
		return; // BUG-FIX: was missing
	}

	// 1c) start location (0,0) — location_id may be integer or UUID depending on schema
	$location_id = null;
	$loc_url = $supabase_rest
		. 'cyber_world_map?world_id=eq.' . $world_id
		. '&coord_x=eq.0&coord_y=eq.0&select=id&limit=1';
	$loc_res = wp_remote_get( $loc_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $loc_res ) ) {
		$loc_data = json_decode( wp_remote_retrieve_body( $loc_res ), true );
		if ( is_array( $loc_data ) && ! empty( $loc_data[0]['id'] ) ) {
			// Keep as string to handle both integer and UUID column types.
			$location_id = sanitize_text_field( (string) $loc_data[0]['id'] );
		}
	}
	if ( ! $location_id ) {
		wp_send_json_error( [ 'message' => 'no_start_location' ] );
		return; // BUG-FIX: was missing
	}

	// 2) signups
	$signup_url = $supabase_rest . 'cyber_campaign_signups?campaign_id=eq.' . $campaign_id
		. '&select=wp_user_id,character_id';
	$signup_res = wp_remote_get( $signup_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( is_wp_error( $signup_res ) ) {
		wp_send_json_error( [ 'message' => 'signup_fetch_error' ] );
		return; // BUG-FIX: was missing
	}
	$signups = json_decode( wp_remote_retrieve_body( $signup_res ), true );
	if ( ! is_array( $signups ) || ! count( $signups ) ) {
		wp_send_json_error( [ 'message' => 'no_signups' ] );
		return; // BUG-FIX: was missing
	}

	// 2b) pause existing active sessions for these users
	$user_ids = array_filter( array_unique( array_map(
		static function ( $s ) { return intval( $s['wp_user_id'] ); },
		$signups
	) ) );

	if ( ! empty( $user_ids ) ) {
		$ids_list    = implode( ',', $user_ids );
		$cleanup_url = $supabase_rest . 'cyber_game_sessions'
			. '?wp_user_id=in.(' . $ids_list . ')&status=eq.active';
		wp_remote_request( $cleanup_url, [
			'method'  => 'PATCH',
			'headers' => [
				'apikey'        => $supabase_key,
				'Authorization' => 'Bearer ' . $supabase_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'status' => 'paused' ] ),
		] );
	}

	// 3) insert new active sessions
	// BUG-FIX: character_id is a UUID string from cyber_characters.id.
	// Previously intval() was used here which collapses UUIDs to 0 and causes
	// the FK constraint on cyber_game_sessions.character_id to reject the insert.
	// Keep both character_id and world_id as strings.
	$sessions_payload = [];
	foreach ( $signups as $s ) {
		$sessions_payload[] = [
			'campaign_id'  => $campaign_id,
			'wp_user_id'   => intval( $s['wp_user_id'] ),
			'character_id' => sanitize_text_field( (string) $s['character_id'] ), // UUID string
			'world_id'     => $world_id,                                           // UUID string
			'location_id'  => $location_id,
			'status'       => 'active',
		];
	}

	$session_url = $supabase_rest . 'cyber_game_sessions';
	$session_res = wp_remote_post( $session_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $sessions_payload ),
	] );

	if ( is_wp_error( $session_res ) || wp_remote_retrieve_response_code( $session_res ) >= 300 ) {
		wp_send_json_error( [ 'message' => 'session_insert_error' ] );
		return;
	}

	wp_send_json_success( [ 'message' => 'launched' ] );
}
