<?php
/**
 * Neoweaver_Agents_List
 *
 * Provides data-preparation and rendering logic for any UI surface that
 * lets an Operator browse, filter, and select a Field Agent to play.
 *
 * Typical consumers:
 *  - Character-selection screen shown before a session starts.
 *  - "My Agents" dashboard widget (full roster, including dead agents).
 *  - Admin/debug views listing all agents in a Node.
 *
 * ARCHITECTURAL RULES (do not violate):
 *  - This class is read-only; it never writes to Supabase.
 *  - Dead agents (STATUS_DEAD) may be shown but must be clearly
 *    distinguished in the UI.
 *  - Never expose another Operator's private Data Ghost logs; only the
 *    owning wp_user_id may see their own ghost logs.
 *  - The 1 Agent = 1 Node binding must be reflected in all listing /
 *    filter methods.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_List {

	/**
	 * Repository used for all Supabase reads.
	 *
	 * @var Neoweaver_Agents_Repository
	 */
	private Neoweaver_Agents_Repository $repo;

	/**
	 * @param Neoweaver_Agents_Repository $repo
	 */
	public function __construct( Neoweaver_Agents_Repository $repo ) {
		$this->repo = $repo;
	}

	// -------------------------------------------------------------------------
	// Primary render method (used by the tw_list_characters shortcode)
	// -------------------------------------------------------------------------

	/**
	 * Render the full character roster for a WordPress user.
	 *
	 * Fetches characters (with tags + inventory already attached) via
	 * Neoweaver_Agents_Repository::get_for_wp_user() and returns the
	 * complete HTML + inline JS that was previously embedded in the
	 * tw_list_characters_shortcode() function.
	 *
	 * No output is echo'd; the string is returned so WordPress shortcode
	 * infrastructure can place it correctly in the page.
	 *
	 * @param int $wp_user_id
	 * @return string  HTML + JS string ready for output.
	 */
	public function render_roster( int $wp_user_id ): string {

		// Validate Supabase config before attempting any query.
		$supabase_url = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		if ( empty( $supabase_url ) || empty( $anon_key ) ) {
			return '<p>System Error: Supabase configuration missing.</p>';
		}

		$characters = $this->repo->get_for_wp_user( $wp_user_id );

		// ------------------------------------------------------------------
		// Shared styles — output regardless of empty / populated state.
		// Empty-state classes mirror .tw-no-worlds from [tw_list_worlds].
		// ------------------------------------------------------------------
		ob_start();
		?>
		<style>
			:root { --neon: #adff00; --dark: #0a0a0a; --gray: #151515; }

			/* ── Empty state (mirrors .tw-no-worlds in shortcode-tw-list-worlds.php) ── */
			.tw-agents-empty {
				text-align: center;
				padding: 80px 30px 100px;
				border: 1px dashed #222;
				border-radius: 10px;
				font-family: 'Chakra Petch', sans-serif;
			}
			.tw-agents-empty-icon {
				font-size: 48px;
				color: #adff00;
				opacity: 0.25;
				margin-bottom: 20px;
				font-weight: 900;
				line-height: 1;
			}
			.tw-agents-empty-main {
				font-size: 1rem;
				font-weight: 700;
				letter-spacing: 0.12em;
				text-transform: uppercase;
				color: #fff;
				margin: 0 0 10px;
			}
			.tw-agents-empty-sub {
				font-size: 0.75rem;
				color: #555;
				letter-spacing: 0.08em;
				text-transform: uppercase;
				margin: 0 0 30px;
			}
			.tw-agents-empty-actions { display: flex; justify-content: center; }
			.tw-agents-empty-actions .tw-btn-sync {
				display: inline-block;
				background: #adff00;
				color: #000;
				border: none;
				padding: 12px 28px;
				font-weight: 900;
				border-radius: 4px;
				cursor: pointer;
				text-transform: uppercase;
				font-family: 'Chakra Petch', sans-serif;
				font-size: 12px;
				letter-spacing: 0.1em;
				text-decoration: none;
				transition: background 0.2s, box-shadow 0.2s;
			}
			.tw-agents-empty-actions .tw-btn-sync:hover {
				background: #fff;
				box-shadow: 0 0 15px #adff00;
			}

			/* ── Roster grid ── */
			.tw-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; font-family: 'Chakra Petch', sans-serif; }
			.tw-card { background: var(--dark); border: 1px solid rgba(173,255,0,0.2); padding: 15px; color: white; position: relative; display: flex; flex-direction: column; }
			.tw-card-header { display: flex; gap: 12px; margin-bottom: 12px; }
			.tw-avatar { width: 64px; height: 64px; border: 1px solid var(--neon); object-fit: cover; }
			.tw-lvl-badge { background: var(--neon); color: black; padding: 2px 6px; font-weight: bold; font-size: 11px; margin-right: 5px; }
			.tw-campaign-info { font-size: 10px; text-transform: uppercase; color: var(--neon); opacity: 0.8; margin-top: 5px; border-top: 1px solid #222; padding-top: 5px; }
			.tw-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); }
			.tw-modal-content { background: #050505; border: 1px solid var(--neon); width: 95%; max-width: 800px; margin: 20px auto; padding: 25px; color: white; position: relative; max-height: 90vh; overflow-y: auto; }
			.tw-close { position: sticky; top: 0; float: right; color: var(--neon); cursor: pointer; font-size: 24px; z-index: 10; background: #050505; padding: 0 5px; }
			.tw-btn { background: var(--gray); border: 1px solid var(--neon); color: var(--neon); padding: 8px; cursor: pointer; font-family: inherit; font-size: 11px; text-transform: uppercase; transition: 0.2s; }
			.tw-btn:hover { background: var(--neon); color: black; }
			.tw-btn-danger { border-color: #ff3b3b; color: #ff3b3b; }
			.tw-btn-danger:hover { background: #ff3b3b; color: #000; }
			.tw-tag-pill { font-size: 10px; padding: 2px 6px; border: 1px solid; margin: 2px; display: inline-block; }
			.tw-top-meta { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #aaa; margin-bottom: 6px; }
			.tw-top-meta a { color: var(--neon); text-decoration: none; font-weight: 600; }
			.tw-toggle-label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
			.tw-toggle-label input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 32px; height: 16px; border-radius: 16px; background: #333; position: relative; outline: none; cursor: pointer; transition: 0.2s; border: 1px solid #555; }
			.tw-toggle-label input[type="checkbox"]::before { content: ''; position: absolute; width: 12px; height: 12px; border-radius: 50%; background: #888; top: 1px; left: 1px; transition: 0.2s; }
			.tw-toggle-label input[type="checkbox"]:checked { background: var(--neon); border-color: var(--neon); }
			.tw-toggle-label input[type="checkbox"]:checked::before { background: #000; transform: translateX(14px); }
			.tw-card-actions { display: flex; gap: 8px; margin-top: auto; }
			.tw-card-actions .tw-btn { flex: 1 1 50%; }
		</style>
		<?php
		$shared_styles = ob_get_clean();

		// ------------------------------------------------------------------
		// Empty state
		// ------------------------------------------------------------------
		if ( empty( $characters ) ) {
			return $shared_styles . '
			<div class="tw-agents-empty">
				<div class="tw-agents-empty-icon">!</div>
				<p class="tw-agents-empty-main">NO OPERATIVES DETECTED IN YOUR GRID.</p>
				<p class="tw-agents-empty-sub">INITIALIZE A NEW FIELD AGENT TO START THE WEAVING PROCESS.</p>
				<div class="tw-agents-empty-actions">
					<a href="/new-agent/" class="tw-btn-sync">NEW FIELD AGENT</a>
				</div>
			</div>';
		}

		// ------------------------------------------------------------------
		// Render grid + modal + scripts
		// ------------------------------------------------------------------
		ob_start();
		echo $shared_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<script>
		window.twCharData = {
			ajaxUrl: "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>",
			nonce: "<?php echo esc_js( wp_create_nonce('tw_char_nonce') ); ?>"
		};
		</script>

		<div class="tw-grid">
			<?php foreach ( $characters as $char ) :
				$avatar     = ! empty( $char['avatar'] ) ? $char['avatar'] : 'https://cyber.nieodparady.pl/wp-content/uploads/default-avatar.png';
				$camp_data  = isset( $char['cyber_campaign_characters'][0]['cyber_campaign'] ) ? $char['cyber_campaign_characters'][0]['cyber_campaign'] : null;
				$camp_name  = isset( $camp_data['name'] ) ? $camp_data['name'] : 'Unassigned';
				$world_name = isset( $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] ) ? $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] : 'Unknown World';
				$is_public  = ! empty( $char['is_public'] );
				$views      = isset( $char['view_count'] ) ? (int) $char['view_count'] : 0;
				$legend_url = add_query_arg( 'char_id', (int) $char['id'], home_url( '/legend/' ) );
			?>
				<div class="tw-card">
					<div class="tw-top-meta">
						<span>Views: <?php echo $views; ?></span>
						<?php if ( $is_public ) : ?>
							<a href="<?php echo esc_url( $legend_url ); ?>" target="_blank">Agent public profile</a>
						<?php else : ?>
							<span style="opacity:0.5;">Not public</span>
						<?php endif; ?>
					</div>
					<div class="tw-card-header">
						<img src="<?php echo esc_url( $avatar ); ?>" class="tw-avatar" alt="">
						<div>
							<div style="display:flex; align-items:center;">
								<span class="tw-lvl-badge">LVL <?php echo (int) $char['lvl']; ?></span>
								<h3 style="margin:0; font-size:16px;"><?php echo esc_html( $char['name'] ); ?></h3>
							</div>
							<small style="opacity:0.7;"><?php echo esc_html( $char['cyber_races']['name'] ?? 'Human' ); ?> // <?php echo esc_html( $char['cyber_classes']['name'] ?? 'Operative' ); ?></small>
							<div class="tw-campaign-info">
								📍 <?php echo esc_html( $camp_name ); ?><br>
								🌍 <?php echo esc_html( $world_name ); ?>
							</div>
							<div style="margin-top:8px; font-size:11px; color:#aaa;">
								<label class="tw-toggle-label">
									<input type="checkbox" class="tw-toggle-public" data-char-id="<?php echo (int) $char['id']; ?>" <?php checked( $is_public ); ?>>
									<span>Public on /legend</span>
								</label>
							</div>
						</div>
					</div>
					<div class="tw-card-actions">
						<button class="tw-btn" data-char="<?php echo esc_attr( wp_json_encode( $char ) ); ?>" onclick="twOpenModal(this)">
							Agent Dossier
						</button>
						<button class="tw-btn tw-btn-danger" onclick="twConfirmDeleteCharacter(<?php echo (int) $char['id']; ?>, this)">
							Delete Operative
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div id="twCharModal" class="tw-modal" onclick="if(event.target==this)this.style.display='none'">
			<div class="tw-modal-content">
				<span class="tw-close" onclick="document.getElementById('twCharModal').style.display='none'">&times;</span>
				<div id="twModalBody"></div>
			</div>
		</div>

		<script>
		function twOpenModal(btn) {
			let data;
			try { data = JSON.parse(btn.dataset.char); } catch(e) { return; }
			const body = document.getElementById('twModalBody');
			const camp = data.cyber_campaign_characters?.[0]?.cyber_campaign;
			const tagsHtml = (data.tags || []).map(t =>
				`<span class="tw-tag-pill" style="color:${t.color};border-color:${t.color}">${t.label}</span>`
			).join('');
			const invHtml = (data.inventory || []).map(i =>
				`<div style="display:flex;justify-content:space-between;border-bottom:1px solid #222;padding:8px 0;font-size:13px;">
					<span>${i.is_equipped ? '⭐ ' : ''}${i.item_name}</span>
					<span style="color:#adff00">x${i.quantity}</span>
				</div>`
			).join('') || 'Inventory empty.';
			body.innerHTML = `
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
					<div>
						<img src="${data.avatar || ''}" style="width:100%;border:1px solid #adff00;margin-bottom:10px;">
						<h2 style="margin:0;color:#adff00;">${data.name}</h2>
						<p style="opacity:0.6;">Level ${data.lvl} | ${data.cyber_classes?.name || ''}</p>
						<div style="font-size:11px;color:#adff00;border:1px solid #adff0033;padding:10px;margin-top:10px;">
							<strong>ACTIVE CAMPAIGN:</strong><br>
							${camp?.name || 'None'} / ${camp?.cyber_campaign_worlds?.[0]?.cyber_worlds?.name || 'N/A'}
						</div>
					</div>
					<div>
						<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#111;padding:15px;margin-bottom:15px;">
							<div>HP: <b>${data.hp}</b></div><div>MP: <b>${data.mp || 0}</b></div>
							<div>BOD: <b>${data.body}</b></div><div>REF: <b>${data.reflex}</b></div>
							<div>MND: <b>${data.mind}</b></div><div>SPI: <b>${data.spirit}</b></div>
						</div>
						<h4>TAGS & SKILLS</h4><div style="margin-bottom:20px;">${tagsHtml}</div>
						<h4>INVENTORY</h4><div>${invHtml}</div>
						<h4 style="margin-top:20px;">BIO</h4>
						<p style="font-size:12px;opacity:0.8;font-style:italic;">${data.bio || 'No data.'}</p>
					</div>
				</div>`;
			document.getElementById('twCharModal').style.display = 'block';
		}

		function twConfirmDeleteCharacter(charId, btnEl) {
			if (!confirm('Are you sure you want to delete this operative? This cannot be undone.')) return;

			if (!window.twSupabase) {
				alert('SUPABASE CLIENT OFFLINE. CANNOT TERMINATE OPERATIVE.');
				return;
			}

			btnEl.disabled = true;
			btnEl.textContent = 'Deleting...';

			const client = window.twSupabase;

			(async () => {
				try {
					// BUG-FIX 3: Added .eq('wp_user_id', ...) ownership guard.
					// Without it any logged-in user could delete any character
					// by ID by calling this function from the browser console.
					// twAdventureData.wp_user_id is injected server-side via
					// tw_inject_global_data() and cannot be spoofed by the
					// client (Supabase RLS must also enforce this on the DB side).
					const currentUserId = window.twAdventureData?.wp_user_id;
					if ( ! currentUserId ) {
						alert( 'IDENTITY NOT VERIFIED. CANNOT TERMINATE OPERATIVE.' );
						btnEl.disabled = false;
						btnEl.textContent = 'Delete Operative';
						return;
					}
					const { error } = await client
						.from('cyber_characters')
						.delete()
						.eq('id', charId)
						.eq('wp_user_id', currentUserId); // ownership guard

					if (error) {
						console.error('SUPABASE DELETE CHARACTER ERROR', error);
						alert('Delete failed: ' + (error.message || 'Grid denied execution.'));
						btnEl.disabled = false;
						btnEl.textContent = 'Delete Operative';
						return;
					}

					btnEl.closest('.tw-card')?.remove();
					const modal = document.getElementById('twCharModal');
					if (modal) modal.style.display = 'none';
				} catch (e) {
					console.error('DELETE CHARACTER EXCEPTION', e);
					alert('Delete failed: client exception.');
					btnEl.disabled = false;
					btnEl.textContent = 'Delete Operative';
				}
			})();
		}

		document.addEventListener('change', function(e) {
			const cb = e.target.closest('.tw-toggle-public');
			if (!cb || !window.twCharData?.ajaxUrl) return;
			const fd = new FormData();
			fd.append('action', 'tw_toggle_char_public');
			fd.append('nonce', twCharData.nonce);
			fd.append('char_id', cb.dataset.charId);
			fd.append('is_public', cb.checked ? '1' : '0');
			fetch(twCharData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(r => r.json())
				.then(json => { if (!json.success) { alert(json.data?.message || 'Toggle failed.'); cb.checked = !cb.checked; } })
				.catch(() => { alert('Network error.'); cb.checked = !cb.checked; });
		});
		</script>
		<?php

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Data-preparation helpers
	// -------------------------------------------------------------------------

	public function get_roster( int $wp_user_id ): array {
		return [];
	}

	public function get_selectable_agents( int $wp_user_id ): array {
		return [];
	}

	public function get_agents_in_node( int $node_id ): array {
		return [];
	}

	public function get_data_ghosts_for_node( int $node_id, int $wp_user_id ): array {
		return [];
	}

	// -------------------------------------------------------------------------
	// Additional render helpers
	// -------------------------------------------------------------------------

	public function render_agent_select( int $wp_user_id ): string {
		return '';
	}

	public function render_active_agent_badge( int $wp_user_id ): string {
		return '';
	}

	// -------------------------------------------------------------------------
	// API payload helper
	// -------------------------------------------------------------------------

	public function to_api_payload( int $wp_user_id ): array {
		return [];
	}
}