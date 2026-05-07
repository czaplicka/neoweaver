window.twCharData = {
			ajaxUrl: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
			nonce: "<?php echo esc_js( wp_create_nonce( 'tw_char_nonce' ) ); ?>"
		};

		/* ── helpers ── */
		function twOpenModal(btn) {
			let data;
			try {
				data = JSON.parse(btn.dataset.char);
			} catch (e) {
				alert('Could not open Agent Dossier.');
				return;
			}

			const body  = document.getElementById('twModalBody');
			const modal = document.getElementById('twCharModal');

			const camp      = data.cyber_campaign_characters && data.cyber_campaign_characters[0]
				? data.cyber_campaign_characters[0].cyber_campaign
				: null;
			const raceName  = data.cyber_races  && data.cyber_races.name  ? data.cyber_races.name  : 'Human';
			const className = data.cyber_classes && data.cyber_classes.name ? data.cyber_classes.name : 'Operative';
			const avatar    = data.avatar ? data.avatar : 'https://neoweaver.nieodparady.pl/wp-content/uploads/Avatar.svg';

			const tags      = Array.isArray(data.tags)      ? data.tags      : [];
			const inventory = Array.isArray(data.inventory) ? data.inventory : [];

			/* tags */
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

			/* inventory */
			let invHtml = '';
			if (inventory.length) {
				inventory.forEach(function(i) {
					const isEquipped = i && i.is_equipped ? '⭐ ' : '';
					const itemName   = i && i.item_name   ? i.item_name   : 'Unknown item';
					const quantity   = i && i.quantity    ? i.quantity    : 0;
					invHtml += '<div style="display:flex;justify-content:space-between;border-bottom:1px solid #222;padding:8px 0;font-size:13px;">';
					invHtml += '<span>' + isEquipped + itemName + '</span>';
					invHtml += '<span style="color:#adff00">x' + quantity + '</span>';
					invHtml += '</div>';
				});
			} else {
				invHtml = 'Inventory empty.';
			}

			const campName  = camp && camp.name ? camp.name : 'None';
			const worldName = camp && camp.cyber_campaign_worlds
				&& camp.cyber_campaign_worlds[0]
				&& camp.cyber_campaign_worlds[0].cyber_worlds
				&& camp.cyber_campaign_worlds[0].cyber_worlds.name
					? camp.cyber_campaign_worlds[0].cyber_worlds.name
					: 'N/A';

			body.innerHTML =
				'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">' +
					'<div>' +
						'<img src="' + avatar + '" style="width:100%;border:1px solid #adff00;margin-bottom:10px;" alt="">' +
						'<h2 style="margin:0;color:#adff00;">' + (data.name || 'Unnamed Agent') + '</h2>' +
						'<p style="opacity:0.6;">Level ' + (data.lvl || 1) + ' | ' + className + '</p>' +
						'<p style="opacity:0.75;font-size:12px;">' + raceName + '</p>' +
						'<div style="font-size:11px;color:#adff00;border:1px solid #adff0033;padding:10px;margin-top:10px;">' +
							'<strong>ACTIVE CAMPAIGN:</strong><br>' +
							campName + ' / ' + worldName +
						'</div>' +
					'</div>' +
					'<div>' +
						'<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#111;padding:15px;margin-bottom:15px;">' +
							'<div>HP: <b>'  + (data.hp     || 0) + '</b></div><div>MP: <b>'  + (data.mp     || 0) + '</b></div>' +
							'<div>BOD: <b>' + (data.body   || 0) + '</b></div><div>REF: <b>' + (data.reflex || 0) + '</b></div>' +
							'<div>MND: <b>' + (data.mind   || 0) + '</b></div><div>SPI: <b>' + (data.spirit || 0) + '</b></div>' +
						'</div>' +
						'<h4>TAGS &amp; SKILLS</h4><div style="margin-bottom:20px;">' + tagsHtml + '</div>' +
						'<h4>INVENTORY</h4><div>' + invHtml + '</div>' +
						'<h4 style="margin-top:20px;">BIO</h4>' +
						'<p style="font-size:12px;opacity:0.8;font-style:italic;">' + (data.bio || 'No data.') + '</p>' +
					'</div>' +
				'</div>';

			/* zablokuj scroll strony gdy modal otwarty */
			document.body.style.overflow = 'hidden';
			modal.classList.add('is-open');
		}

		function twCloseModal() {
			const modal = document.getElementById('twCharModal');
			modal.classList.remove('is-open');
			document.body.style.overflow = ''; /* przywróć scroll strony */
		}

		/* zamknięcie kliknięciem w overlay */
		document.addEventListener('click', function(e) {
			const modal = document.getElementById('twCharModal');
			if (modal && e.target === modal) {
				twCloseModal();
			}
		});

		/* zamknięcie klawiszem Escape */
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				twCloseModal();
			}
		});

		function twConfirmDeleteCharacter(charId, btnEl) {
			if (!confirm('Are you sure you want to delete this operative? This cannot be undone.')) {
				return;
			}

			if (!window.twSupabase) {
				alert('SUPABASE CLIENT OFFLINE. CANNOT TERMINATE OPERATIVE.');
				return;
			}

			btnEl.disabled    = true;
			btnEl.textContent = 'Deleting...';

			const currentUserId = window.twAdventureData && window.twAdventureData.wp_user_id
				? window.twAdventureData.wp_user_id
				: null;

			if (!currentUserId) {
				alert('IDENTITY NOT VERIFIED. CANNOT TERMINATE OPERATIVE.');
				btnEl.disabled    = false;
				btnEl.textContent = 'Delete Operative';
				return;
			}

			window.twSupabase
				.rpc('fn_delete_character', {
					p_character_id: charId,
					p_wp_user_id:   currentUserId
				})
				.then(function(result) {
					if (result.error) {
						console.error('SUPABASE DELETE CHARACTER ERROR', result.error);
						alert('Delete failed: ' + (result.error.message || 'Grid denied execution.'));
						btnEl.disabled    = false;
						btnEl.textContent = 'Delete Operative';
						return;
					}
					window.location.reload();
				})
				.catch(function(e) {
					console.error('DELETE CHARACTER EXCEPTION', e);
					alert('Delete failed: client exception.');
					btnEl.disabled    = false;
					btnEl.textContent = 'Delete Operative';
				});
		}

		document.addEventListener('change', function(e) {
			const cb = e.target.closest('.tw-toggle-public');
			if (!cb || !window.twCharData || !window.twCharData.ajaxUrl) return;

			const fd = new FormData();
			fd.append('action',    'tw_toggle_char_public');
			fd.append('nonce',     window.twCharData.nonce);
			fd.append('char_id',   cb.dataset.charId);
			fd.append('is_public', cb.checked ? '1' : '0');

			fetch(window.twCharData.ajaxUrl, {
				method:      'POST',
				body:        fd,
				credentials: 'same-origin'
			})
			.then(function(r)    { return r.json(); })
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
