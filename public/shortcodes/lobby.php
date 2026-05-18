<?php
/**
 * NEOWEAVE LOBBY SHORTCODE + AJAX USER LABELS + AVATARS + ONLINE DOT + LAUNCH/READY + AUTO-JOIN
 *
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

	$raw_campaign_id = isset( $_GET['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
	}

	// [FIX-1] Corrected table name: cyber_campaign (no trailing s)
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

	$nonce_launch    = wp_create_nonce( 'neoweave_launch' );
	$nonce_labels    = wp_create_nonce( 'neoweave_labels' );
	$nonce_heartbeat = wp_create_nonce( 'neoweave_heartbeat' );

	$user_map     = [];
	$current_user = wp_get_current_user();
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
	.terminal-title  { font-family: 'Chakra Petch', sans-serif; font-size: 1.2rem; font-weight: bold; }
	.terminal-status { margin-top: 5px; font-size: 0.9rem; }
	.blink { animation: blinker 1s linear infinite; }
	@keyframes blinker { 50% { opacity: 0; } }

	/* [FIX-4] Inline launch status message */
	#launch-status {
		margin-top: 12px; font-size: 0.85rem; min-height: 1.2em;
		transition: opacity 0.3s;
	}
	#launch-status.error   { color: #ff4444; }
	#launch-status.success { color: #adff00; }
	#launch-status.info    { color: #888; }

	.squad-grid {
		display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 20px;
	}
	.squad-slot {
		border: 1px solid #adff00; padding: 12px; min-height: 80px;
		display: flex; align-items: center; position: relative;
	}
	.slot-body {
		font-size: 0.8rem; width: 100%; text-align: left;
		display: flex; align-items: center; gap: 10px;
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
	.terminal-button:disabled {
		opacity: 0.5; cursor: not-allowed;
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

		<!-- [FIX-4] Inline status message replaces alert() -->
		<div id="launch-status" class="info"></div>

		<div class="terminal-actions">
			<button type="button" class="terminal-button" id="neoweave-launch-button">LAUNCH DEPLOYMENT</button>
			<button type="button" class="terminal-button secondary" id="neoweave-leave-button">LEAVE LOBBY</button>
		</div>
	</div>

	<noscript>
		<div class="neoweave-terminal">ERROR: JAVASCRIPT REQUIRED. ENABLE SCRIPTING TO ACCESS LOBBY.</div>
	</noscript>

	<script>
	(function() {

		const ONLINE_THRESHOLD_MS   = 90 * 1000;
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

			const statusEl = document.getElementById('launch-status');

			// [FIX-4] Show status message inline instead of alert()
			function showStatus(msg, type = 'info') {
				if (!statusEl) return;
				statusEl.textContent = msg;
				statusEl.className   = type; // 'info' | 'error' | 'success'
			}

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
			// [FIX-2] HEARTBEAT — timer stored as let so visibilitychange
			// can clear and re-create without leaking.
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
			// [FIX-2] let (not const) so we can reassign on visibility change
			let heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);

			// [FIX-2] Always clear before creating a new interval
			document.addEventListener('visibilitychange', () => {
				clearInterval(heartbeatTimer);
				if (!document.hidden) {
					sendHeartbeat();
					heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
				}
			});

			// ─────────────────────────────────────────────
			// [FIX-3] Store interval refs for cleanup before redirect
			// ─────────────────────────────────────────────
			let fetchTimer = null;
			let watchTimer = null;

			function stopAllTimers() {
				clearInterval(heartbeatTimer);
				clearInterval(fetchTimer);
				clearInterval(watchTimer);
			}

			function renderSlots(signups) {
				signups.sort((a, b) => {
					// [FIX: null created_at safety] Fall back to epoch so null doesn't NaN-sort
					const ta = a.created_at ? new Date(a.created_at).getTime() : 0;
					const tb = b.created_at ? new Date(b.created_at).getTime() : 0;
					return ta - tb;
				});

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

			// [FIX-7] Skip fetch when tab is in background
			async function fetchSignups() {
				if (document.hidden) return;
				try {
					const { data, error } = await client
						.from('cyber_campaign_signups')
						.select('campaign_id, wp_user_id, character_id, created_at, is_ready, last_seen_at')
						.eq('campaign_id', campaignId);
					if (error) { console.error('NEOWEAVE LOBBY: signups fetch error', error); return; }
					renderSlots(await enrichSignups(data || []));
				} catch (e) {
					console.error('NEOWEAVE LOBBY: exception while fetching signups', e);
				}
			}

			fetchSignups();
			// [FIX-3] Store ref
			fetchTimer = setInterval(fetchSignups, 3000);

			// [FIX-6] Redirect flag — prevents double redirect
			let redirecting = false;

			async function watchForSessionAndRedirect() {
				// [FIX-6] Guard: don't fire again if redirect already in progress
				if (redirecting) return;
				// [FIX-7] Skip when tab hidden
				if (document.hidden) return;
				try {
					const { data, error } = await client
						.from('cyber_game_sessions')
						.select('id, status')
						.eq('campaign_id', campaignId)
						.eq('wp_user_id', currentUserId)
						.eq('status', 'active')
						.limit(1);
					if (!error && data && data.length) {
						redirecting = true;
						// [FIX-3] Stop all timers before navigating away
						stopAllTimers();
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					}
				} catch (e) { console.error('SESSION WATCH ERROR', e); }
			}
			// [FIX-3] Store ref
			watchTimer = setInterval(watchForSessionAndRedirect, 4000);

			async function handleLaunchAsHost() {
				if (!ajaxUrl) { showStatus('LAUNCH FAILED: MISSING AJAX URL.', 'error'); return; }

				const launchBtn = document.getElementById('neoweave-launch-button');

				// [FIX-5] Disable button during request
				if (launchBtn) {
					launchBtn.disabled   = true;
					launchBtn.textContent = 'LAUNCHING...';
				}
				showStatus('> INITIALIZING DEPLOYMENT SEQUENCE...', 'info');

				const formData = new FormData();
				formData.append('action',      'neoweave_launch_campaign');
				formData.append('campaign_id', campaignId);
				formData.append('nonce',       nonceLaunch);

				try {
					const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
					const json = await res.json();
					if (json && json.success) {
						showStatus('> DEPLOYMENT SUCCESSFUL. ENTERING TERMINAL...', 'success');
						redirecting = true;
						stopAllTimers();
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					} else {
						const msg = (json && (json.data?.message || json.message)) || 'UNKNOWN_ERROR';
						showStatus('LAUNCH FAILED: ' + msg, 'error');
						if (launchBtn) {
							launchBtn.disabled    = false;
							launchBtn.textContent = 'LAUNCH DEPLOYMENT';
						}
					}
				} catch (e) {
					console.error('LAUNCH ERROR', e);
					showStatus('LAUNCH FAILED: CLIENT_EXCEPTION', 'error');
					if (launchBtn) {
						launchBtn.disabled    = false;
						launchBtn.textContent = 'LAUNCH DEPLOYMENT';
					}
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
						launchBtn.disabled = true;
						try {
							const { error } = await client
								.from('cyber_campaign_signups')
								.update({ is_ready: newReady })
								.eq('campaign_id', campaignId)
								.eq('wp_user_id', currentUserId);
							if (error) {
								console.error('READY TOGGLE ERROR', error);
								showStatus('READY TOGGLE FAILED.', 'error');
							} else {
								localReady            = newReady;
								launchBtn.textContent = newReady ? 'READY ✓' : 'READY';
							}
						} catch (e) {
							console.error('READY TOGGLE EXCEPTION', e);
							showStatus('READY TOGGLE EXCEPTION.', 'error');
						} finally {
							launchBtn.disabled = false;
						}
					});
				}
			}

			const leaveBtn = document.getElementById('neoweave-leave-button');
			if (leaveBtn && ajaxUrl) {
				leaveBtn.addEventListener('click', async function() {
					leaveBtn.disabled = true;
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

						const formData = new FormData();
						formData.append('action',      'neoweave_leave_lobby');
						formData.append('nonce',       nonceHeartbeat);
						formData.append('campaign_id', campaignId);

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success) {
							stopAllTimers();
							window.location.href = '/';
						} else {
							console.error('NEOWEAVE LOBBY: leave failed', json);
							showStatus('LEAVE FAILED: ' + (json?.data?.message || 'UNKNOWN_ERROR'), 'error');
							leaveBtn.disabled = false;
						}
					} catch (e) {
						console.error('NEOWEAVE LOBBY: leave exception', e);
						showStatus('LEAVE FAILED: CLIENT_EXCEPTION', 'error');
						leaveBtn.disabled = false;
					}
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
 * [FIX-1] All table references verified against live Supabase schema:
 *   cyber_campaign          ✅ (was: cyber_campaigns — fixed)
 *   cyber_campaign_worlds   ✅
 *   cyber_world_map         ✅
 *   cyber_campaign_signups  ✅
 *   cyber_game_sessions     ✅
 */
add_action( 'wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign' );

function neoweave_launch_campaign() {
	check_ajax_referer( 'neoweave_launch', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	$raw_campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $raw_campaign_id );

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return;
	}

	$current_user_id = get_current_user_id();

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	// 1) host check — [FIX-1] correct table name: cyber_campaign
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( is_wp_error( $camp_res ) ) {
		wp_send_json_error( [ 'message' => 'campaign_fetch_error' ] );
		return;
	}
	$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
	$host_id   = isset( $camp_data[0]['wp_user_id'] ) ? intval( $camp_data[0]['wp_user_id'] ) : 0;
	if ( $host_id !== $current_user_id ) {
		wp_send_json_error( [ 'message' => 'not_host' ] );
		return;
	}

	// 2) world_id from cyber_campaign_worlds
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
			$world_id = sanitize_text_field( $world_data[0]['world_id'] );
		}
	}
	if ( ! $world_id ) {
		wp_send_json_error( [ 'message' => 'no_world_linked' ] );
		return;
	}

	// 3) start location (0,0) from cyber_world_map
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
			$location_id = sanitize_text_field( (string) $loc_data[0]['id'] );
		}
	}
	if ( ! $location_id ) {
		wp_send_json_error( [ 'message' => 'no_start_location' ] );
		return;
	}

	// 4) signups from cyber_campaign_signups
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
		return;
	}
	$signups = json_decode( wp_remote_retrieve_body( $signup_res ), true );
	if ( ! is_array( $signups ) || ! count( $signups ) ) {
		wp_send_json_error( [ 'message' => 'no_signups' ] );
		return;
	}

	// 5) pause existing active sessions for these users
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

	// 6) insert new active sessions into cyber_game_sessions
	$sessions_payload = [];
	foreach ( $signups as $s ) {
		$sessions_payload[] = [
			'campaign_id'  => $campaign_id,
			'wp_user_id'   => intval( $s['wp_user_id'] ),
			'character_id' => sanitize_text_field( (string) $s['character_id'] ),
			'world_id'     => $world_id,
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
