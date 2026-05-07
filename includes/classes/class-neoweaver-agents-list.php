<?php
/**
 * Neoweaver_Agents_List
 *
 * Provides data-preparation and rendering logic for any UI surface that
 * lets an Operator browse, filter, and select a Field Agent to play.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_List {

	private Neoweaver_Agents_Repository $repo;

	public function __construct( Neoweaver_Agents_Repository $repo ) {
		$this->repo = $repo;
	}

	public function render_roster( int $wp_user_id ): string {

		$supabase_url = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		if ( empty( $supabase_url ) || empty( $anon_key ) ) {
			return '<p>System Error: Supabase configuration missing.</p>';
		}

		$characters = $this->repo->get_for_wp_user( $wp_user_id );

		ob_start();
		?>
		<style>
			:root { --neon: #adff00; --dark: #0a0a0a; --gray: #151515; }

			.tw-agents-empty {
				text-align: center;
				padding: 100px 0;
				font-family: 'Chakra Petch', sans-serif;
			}
			.tw-agents-empty-icon {
				font-size: 40px;
				margin-bottom: 20px;
				opacity: 0.3;
				line-height: 1;
			}
			.tw-agents-empty-main {
				font-size: 1rem;
				color: #adff00;
				margin: 0 0 10px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.05em;
			}
			.tw-agents-empty-sub {
				display: block;
				font-size: 0.85rem;
				color: #fff;
				margin: 0 0 28px;
			}
			.tw-agents-empty-actions { display: flex; justify-content: center; }
			.tw-agents-empty-actions .tw-btn-sync {
				display: inline-block;
				background: #adff00;
				color: #000 !important;
				border: none;
				padding: 10px 22px;
				font-weight: 900;
				border-radius: 4px;
				cursor: pointer;
				text-transform: uppercase;
				font-family: 'Chakra Petch', sans-serif;
				font-size: 11px;
				letter-spacing: 0.05em;
				text-decoration: none;
				transition: background 0.2s, box-shadow 0.2s;
			}
			.tw-agents-empty-actions .tw-btn-sync:hover {
				background: #fff;
				box-shadow: 0 0 15px #adff00;
				color: #000 !important;
			}

			.tw-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
				gap: 20px;
				font-family: 'Chakra Petch', sans-serif;
			}
			.tw-card {
				background: var(--dark);
				border: 1px solid rgba(173,255,0,0.2);
				padding: 15px;
				color: white;
				position: relative;
				display: flex;
				flex-direction: column;
			}
			.tw-card-header { display: flex; gap: 12px; margin-bottom: 12px; }
			.tw-avatar { width: 64px; height: 64px; border: 1px solid var(--neon); object-fit: cover; }
			.tw-lvl-badge { background: var(--neon); color: black; padding: 2px 6px; font-weight: bold; font-size: 11px; margin-right: 5px; }
			.tw-campaign-info { font-size: 10px; text-transform: uppercase; color: var(--neon); opacity: 0.8; margin-top: 5px; border-top: 1px solid #222; padding-top: 5px; }
.tw-modal {
    display: none; /* domyślnie ukryty */
    position: fixed;
    z-index: 10000;
    inset: 0;
    background: rgba(0,0,0,0.9);
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}

.tw-modal.is-open {
    display: flex; /* widoczny tylko po dodaniu klasy */
}

.tw-modal-content {
	background: #050505;
	border: 1px solid var(--neon);
	width: 100%;
	max-width: 800px;
	max-height: 90vh;
	padding: 25px;
	color: white;
	position: relative;
	overflow-y: auto;
}
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

		if ( empty( $characters ) ) {
			return $shared_styles . '
			<div class="tw-agents-empty">
				<div class="tw-agents-empty-icon">⚠️</div>
				<p class="tw-agents-empty-main">NO OPERATIVES DETECTED IN YOUR GRID.</p>
				<small class="tw-agents-empty-sub">Initialize a new Field Agent to start the weaving process.</small>
				<div class="tw-agents-empty-actions">
					<a href="/new-agent/" class="tw-btn-sync">NEW FIELD AGENT</a>
				</div>
			</div>';
		}

		ob_start();
		echo $shared_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<script>
		window.twCharData = {
			ajaxUrl: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
			nonce: "<?php echo esc_js( wp_create_nonce( 'tw_char_nonce' ) ); ?>"
		};
		</script>

		<div class="tw-grid">
			<?php foreach ( $characters as $char ) :
				$avatar       = ! empty( $char['avatar'] ) ? $char['avatar'] : 'https://neoweaver.nieodparady.pl/wp-content/uploads/Avatar.svg';
				$camp_data    = isset( $char['cyber_campaign_characters'][0]['cyber_campaign'] ) ? $char['cyber_campaign_characters'][0]['cyber_campaign'] : null;
				$camp_name    = isset( $camp_data['name'] ) ? $camp_data['name'] : 'Unassigned';
				$world_name   = isset( $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] ) ? $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] : 'Unknown World';
				$is_public    = ! empty( $char['is_public'] );
				$views        = isset( $char['view_count'] ) ? (int) $char['view_count'] : 0;
				$char_id_safe = esc_attr( (string) $char['id'] );
				$legend_url   = add_query_arg( 'char_id', $char_id_safe, home_url( '/legend/' ) );
				$char_json    = wp_json_encode( $char );
			?>
				<div class="tw-card">
					<div class="tw-top-meta">
						<span>Views: <?php echo esc_html( $views ); ?></span>
						<?php if ( $is_public ) : ?>
							<a href="<?php echo esc_url( $legend_url ); ?>" target="_blank" rel="noopener noreferrer">Agent public profile</a>
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
									<input type="checkbox" class="tw-toggle-public" data-char-id="<?php echo $char_id_safe; ?>" <?php checked( $is_public ); ?>>
									<span>Public on /legend</span>
								</label>
							</div>
						</div>
					</div>
					<div class="tw-card-actions">
						<button class="tw-btn" data-char="<?php echo esc_attr( $char_json ); ?>" onclick="twOpenModal(this)">
							Agent Dossier
						</button>
						<button class="tw-btn tw-btn-danger" onclick='twConfirmDeleteCharacter(<?php echo wp_json_encode( (string) $char['id'] ); ?>, this)'>
							Delete Operative
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div id="twCharModal" class="tw-modal" onclick="if(event.target===this)this.style.display='none'">
			<div class="tw-modal-content">
				<span class="tw-close" onclick="document.getElementById('twCharModal').style.display='none'">&times;</span>
				<div id="twModalBody"></div>
			</div>
		</div>

		<script>
		function twOpenModal(btn) {
			let data;
			try {
				data = JSON.parse(btn.dataset.char);
			} catch (e) {
				alert('Could not open Agent Dossier.');
				return;
			}

			const body = document.getElementById('twModalBody');
			const modal = document.getElementById('twCharModal');
			const camp = data.cyber_campaign_characters && data.cyber_campaign_characters[0]
				? data.cyber_campaign_characters[0].cyber_campaign
				: null;
			const raceName = data.cyber_races && data.cyber_races.name ? data.cyber_races.name : 'Human';
			const className = data.cyber_classes && data.cyber_classes.name ? data.cyber_classes.name : 'Operative';
			const avatar = data.avatar ? data.avatar : 'https://neoweaver.nieodparady.pl/wp-content/uploads/Avatar.svg';

			const tags = Array.isArray(data.tags) ? data.tags : [];
			const inventory = Array.isArray(data.inventory) ? data.inventory : [];

			let tagsHtml = '';
			if (tags.length) {
				tags.forEach(function(t) {
					const color = t && t.color ? t.color : '#adff00';
					const label = t && t.label ? t.label : 'Tag';
					tagsHtml += '<span class="tw-tag-pill" style="color:' + color + ';border-color:' + color + '">' + label + '</span>';
				});
			} else {
				tagsHtml = '<span style="opacity:0.7;">No tags.</span>';
			}

			let invHtml = '';
			if (inventory.length) {
				inventory.forEach(function(i) {
					const isEquipped = i && i.is_equipped ? '⭐ ' : '';
					const itemName = i && i.item_name ? i.item_name : 'Unknown item';
					const quantity = i && i.quantity ? i.quantity : 0;
					invHtml += '<div style="display:flex;justify-content:space-between;border-bottom:1px solid #222;padding:8px 0;font-size:13px;">';
					invHtml += '<span>' + isEquipped + itemName + '</span>';
					invHtml += '<span style="color:#adff00">x' + quantity + '</span>';
					invHtml += '</div>';
				});
			} else {
				invHtml = 'Inventory empty.';
			}

			body.innerHTML = '' +
				'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">' +
					'<div>' +
						'<img src="' + avatar + '" style="width:100%;border:1px solid #adff00;margin-bottom:10px;">' +
						'<h2 style="margin:0;color:#adff00;">' + (data.name || 'Unnamed Agent') + '</h2>' +
						'<p style="opacity:0.6;">Level ' + (data.lvl || 1) + ' | ' + className + '</p>' +
						'<p style="opacity:0.75;font-size:12px;">' + raceName + '</p>' +
						'<div style="font-size:11px;color:#adff00;border:1px solid #adff0033;padding:10px;margin-top:10px;">' +
							'<strong>ACTIVE CAMPAIGN:</strong><br>' +
							((camp && camp.name) ? camp.name : 'None') + ' / ' + ((camp && camp.cyber_campaign_worlds && camp.cyber_campaign_worlds[0] && camp.cyber_campaign_worlds[0].cyber_worlds && camp.cyber_campaign_worlds[0].cyber_worlds.name) ? camp.cyber_campaign_worlds[0].cyber_worlds.name : 'N/A') +
						'</div>' +
					'</div>' +
					'<div>' +
						'<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#111;padding:15px;margin-bottom:15px;">' +
							'<div>HP: <b>' + (data.hp || 0) + '</b></div><div>MP: <b>' + (data.mp || 0) + '</b></div>' +
							'<div>BOD: <b>' + (data.body || 0) + '</b></div><div>REF: <b>' + (data.reflex || 0) + '</b></div>' +
							'<div>MND: <b>' + (data.mind || 0) + '</b></div><div>SPI: <b>' + (data.spirit || 0) + '</b></div>' +
						'</div>' +
						'<h4>TAGS & SKILLS</h4><div style="margin-bottom:20px;">' + tagsHtml + '</div>' +
						'<h4>INVENTORY</h4><div>' + invHtml + '</div>' +
						'<h4 style="margin-top:20px;">BIO</h4>' +
						'<p style="font-size:12px;opacity:0.8;font-style:italic;">' + (data.bio || 'No data.') + '</p>' +
					'</div>' +
				'</div>';

			modal.style.display = 'block';
		}

		function twConfirmDeleteCharacter(charId, btnEl) {
			if (!confirm('Are you sure you want to delete this operative? This cannot be undone.')) {
				return;
			}

			if (!window.twSupabase) {
				alert('SUPABASE CLIENT OFFLINE. CANNOT TERMINATE OPERATIVE.');
				return;
			}

			btnEl.disabled = true;
			btnEl.textContent = 'Deleting...';

			const currentUserId = window.twAdventureData && window.twAdventureData.wp_user_id
				? window.twAdventureData.wp_user_id
				: null;

			if (!currentUserId) {
				alert('IDENTITY NOT VERIFIED. CANNOT TERMINATE OPERATIVE.');
				btnEl.disabled = false;
				btnEl.textContent = 'Delete Operative';
				return;
			}

			window.twSupabase
				.rpc('fn_delete_character', {
					p_character_id: charId,
					p_wp_user_id: currentUserId
				})
				.then(function(result) {
					if (result.error) {
						console.error('SUPABASE DELETE CHARACTER ERROR', result.error);
						alert('Delete failed: ' + (result.error.message || 'Grid denied execution.'));
						btnEl.disabled = false;
						btnEl.textContent = 'Delete Operative';
						return;
					}

					window.location.reload();
				})
				.catch(function(e) {
					console.error('DELETE CHARACTER EXCEPTION', e);
					alert('Delete failed: client exception.');
					btnEl.disabled = false;
					btnEl.textContent = 'Delete Operative';
				});
		}

		document.addEventListener('change', function(e) {
			const cb = e.target.closest('.tw-toggle-public');
			if (!cb || !window.twCharData || !window.twCharData.ajaxUrl) return;

			const fd = new FormData();
			fd.append('action', 'tw_toggle_char_public');
			fd.append('nonce', window.twCharData.nonce);
			fd.append('char_id', cb.dataset.charId);
			fd.append('is_public', cb.checked ? '1' : '0');

			fetch(window.twCharData.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
			.then(function(r) {
				return r.json();
			})
			.then(function(json) {
				if (!json.success) {
					alert((json.data && json.data.message) ? json.data.message : 'Toggle failed.');
					cb.checked = !cb.checked;
				}
			})
			.catch(function() {
				alert('Network error.');
				cb.checked = !cb.checked;
			});
		});
		</script>
		<?php

		return ob_get_clean();
	}

	public function get_roster( int $wp_user_id ): array { return []; }
	public function get_selectable_agents( int $wp_user_id ): array { return []; }

	/**
	 * @param string|int $node_id
	 */
	public function get_agents_in_node( $node_id ): array { return []; }

	/**
	 * @param string|int $node_id
	 */
	public function get_data_ghosts_for_node( $node_id, int $wp_user_id ): array { return []; }

	public function render_agent_select( int $wp_user_id ): string { return ''; }
	public function render_active_agent_badge( int $wp_user_id ): string { return ''; }
	public function to_api_payload( int $wp_user_id ): array { return []; }
}
