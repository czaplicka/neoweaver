(function () {
	function playEquipSound(url) {
		if (!url || url === 'null') return;

		try {
			const audio = new Audio(url);
			audio.volume = 0.4;
			audio.play();
		} catch (e) {
			console.warn('Audio playback failed:', e);
		}
	}

	async function refreshInventoryUI() {
		const config = window.twAdventureData;
		if (!config?.active_character_id || !config?.supabase_url || !config?.supabase_anon_key) {
			return;
		}

		const url =
			`${config.supabase_url}/rest/v1/cyber_character_inventory` +
			`?character_id=eq.${config.active_character_id}` +
			`&select=id,is_equipped,equipped_slot,quantity,info:cyber_items(*)`;

		try {
			const response = await fetch(url, {
				headers: {
					apikey: config.supabase_anon_key,
					Authorization: `Bearer ${config.supabase_anon_key}`
				}
			});

			const data = await response.json();
			if (!Array.isArray(data)) {
				console.error('Inventory refresh: invalid data format', data);
				return;
			}

			const equipped = data.filter(function (item) {
				return item.is_equipped === true;
			});

			const carried = data.filter(function (item) {
				return item.is_equipped === false;
			});

			renderEquippedPaperdoll(equipped);
			renderCarriedItems(carried);
			initDragAndDrop();
			initItemTooltips();
		} catch (err) {
			console.error('Inventory refresh error:', err);
		}
	}

	function renderCarriedItems(items) {
		const listContainer = document.getElementById('tw-inventory-list');
		if (!listContainer) return;

		listContainer.innerHTML = '';

		if (!items || items.length === 0) {
			listContainer.innerHTML = '<p style="opacity:0.3; font-size:0.7rem; padding:10px;">Empty...</p>';
			return;
		}

		items.forEach(function (entry) {
			const it = entry.info;
			if (!it) return;

			const card = document.createElement('div');
			card.className = 'tw-item-card';
			card.draggable = true;
			card.dataset.inventoryId = entry.id;
			card.dataset.itemSlot = it.slot || '';
			card.dataset.soundUrl = it.sound_url || '';
			card.style.cssText = 'background: rgba(255,255,255,0.05); margin-bottom: 2px; padding: 5px; cursor: grab;';

			const rarity = it.rarity ? `[${it.rarity.toUpperCase()}]` : '[COMMON]';
			const tags = it.tags && Array.isArray(it.tags) ? it.tags.map(function (t) { return `#${t}`; }).join(' ') : '';
			const description = it.description || 'No description available.';
			const mass = it.mass || 0;

			card.title = `${it.name.toUpperCase()} ${rarity}\n${tags}\n\n${description}\n\nMass: ${mass}kg`;

			card.innerHTML = `
				<span class="tw-item-name" style="font-size: 0.85rem;">
					${it.name}
					<small style="opacity: 0.6;"> x${entry.quantity}</small>
					<small style="float: right; color: #adff00;">${mass} kg</small>
				</span>
			`;

			listContainer.appendChild(card);
		});
	}

	function renderEquippedPaperdoll(equippedData) {
		let totalPower = 0;
		const slots = document.querySelectorAll('.inv-slot');

		slots.forEach(function (slot) {
			const iconContainer = slot.querySelector('.item-icon');
			if (iconContainer) {
				iconContainer.innerHTML = '';
			}
			slot.style.borderColor = 'rgba(173, 255, 0, 0.4)';
			slot.style.boxShadow = 'none';
		});

		(equippedData || []).forEach(function (entry) {
			const item = entry.info;
			if (!item) return;

			totalPower += item.power_value || 0;

			let targetSlot = document.querySelector(`[data-slot="${entry.equipped_slot}"]`);

			if (!targetSlot) {
				targetSlot = Array.from(slots).find(function (s) {
					return (
						s.dataset.slot &&
						s.dataset.slot.startsWith(item.slot) &&
						s.querySelector('.item-icon') &&
						s.querySelector('.item-icon').innerHTML === ''
					);
				});
			}

			if (targetSlot) {
				renderItemInSlot(targetSlot, item, entry.id);
			}
		});

		const powerEl = document.getElementById('total-power-value');
		if (powerEl) {
			powerEl.innerText = totalPower;
		}
	}

	function renderItemInSlot(slotElement, item, inventoryId) {
		const iconContainer = slotElement.querySelector('.item-icon');
		if (!iconContainer) return;

		const img = document.createElement('img');
		img.src = item.img_url || 'https://via.placeholder.com/50';
		img.className = 'item-img-fluid';
		img.draggable = true;
		img.dataset.inventoryId = inventoryId;
		img.dataset.itemSlot = item.slot || '';
		img.dataset.soundUrl = item.sound_url || '';

		const tags = item.tags && Array.isArray(item.tags) ? item.tags.map(function (t) { return `#${t}`; }).join(' ') : '';
		img.title = `${item.name.toUpperCase()} [${item.rarity || 'Common'}]\n${tags}\n\n${item.description || ''}`;

		const colors = {
			legendary: '#ff8000',
			epic: '#a335ee',
			rare: '#0070dd'
		};

		const color = colors[(item.rarity || '').toLowerCase()] || 'rgba(173, 255, 0, 0.4)';

		slotElement.style.borderColor = color;
		slotElement.style.boxShadow = `inset 0 0 8px ${color}`;

		iconContainer.appendChild(img);
	}

	function initDragAndDrop() {
		const draggables = document.querySelectorAll('.tw-item-card, .item-img-fluid');
		const dropZones = document.querySelectorAll('.inv-slot, #tw-inventory-list');

		draggables.forEach(function (d) {
			d.addEventListener('dragstart', function () {
				d.classList.add('dragging');
			});

			d.addEventListener('dragend', function () {
				d.classList.remove('dragging');
			});
		});

		dropZones.forEach(function (zone) {
			zone.addEventListener('dragover', function (e) {
				e.preventDefault();
			});

			zone.addEventListener('drop', async function (e) {
				e.preventDefault();

				const dragging = document.querySelector('.dragging');
				if (!dragging) return;

				const invId = dragging.dataset.inventoryId;
				const itemType = dragging.dataset.itemSlot;
				const itemSound = dragging.dataset.soundUrl || '';
				const isToEquip = zone.classList.contains('inv-slot');
				const targetSlotName = isToEquip ? zone.dataset.slot : null;

				if (!invId) return;

				if (isToEquip && targetSlotName) {
					const isBeltSlot = targetSlotName.startsWith('belt');
					const isRingSlot = targetSlotName.startsWith('ring');

					if (itemType !== targetSlotName && !isBeltSlot && !isRingSlot) {
						alert(`You cannot equip ${itemType} in ${targetSlotName} slot!`);
						return;
					}
				}

				playEquipSound(itemSound);
				await updateItemEquipmentStatus(invId, isToEquip, targetSlotName);
			});
		});
	}

	async function updateItemEquipmentStatus(inventoryId, isEquipped, slotName) {
		const config = window.twAdventureData;
		const ajaxUrl = config?.ajax_url || '/wp-admin/admin-ajax.php';
		const nonce = config?.nonce || '';

		const fd = new FormData();
		fd.append('action', 'tw_update_inventory_slot');
		fd.append('nonce', nonce);
		fd.append('inventory_id', inventoryId);
		fd.append('is_equipped', isEquipped ? '1' : '0');
		fd.append('slot_name', slotName || '');

		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			});

			const data = await response.json();

			if (data.success) {
				await refreshInventoryUI();
			} else {
				console.error('Inventory update failed', data);
			}
		} catch (err) {
			console.error('Inventory update error:', err);
		}
	}

	function initItemTooltips() {
		if (!window.tippy) return;

		window.tippy('.tw-item-card, .item-img-fluid', {
			content(reference) {
				return reference.getAttribute('title');
			},
			theme: 'cyberpunk'
		});
	}

	function parseInventoryTags(text) {
		if (typeof text !== 'string') return text;

		const itemRegex = /\[ITEM:(\d+)\]/g;

		return text.replace(itemRegex, function (match, itemId) {
			const safeId = Number.parseInt(itemId, 10);
			if (Number.isNaN(safeId)) return match;

			return `
				<button class="tw-loot-button"
						data-item-id="${safeId}"
						onclick="window.handleLootAction(${safeId}, this)">
					<span class="tw-btn-text">TAKE ITEM</span>
					<span class="tw-btn-id">#${safeId}</span>
				</button>
			`;
		});
	}

	window.handleLootAction = function (itemId, buttonElement) {
		if (!buttonElement) return;

		const ajaxUrl = window.twAdventureData?.ajax_url || '/wp-admin/admin-ajax.php';
		const nonce = window.twAdventureData?.nonce || '';
		const characterId =
			window.twAdventureData?.active_character_id ||
			window.currentPlayerId ||
			'';

		buttonElement.disabled = true;
		buttonElement.classList.add('syncing');
		const originalContent = buttonElement.innerHTML;
		buttonElement.innerText = 'SYNCING...';

		const formData = new FormData();
		formData.append('action', 'loot_item');
		formData.append('nonce', nonce);
		formData.append('character_id', characterId);
		formData.append('item_id', itemId);

		fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('Network error');
				}
				return response.json().catch(function () {
					return {};
				});
			})
			.then(function (data) {
				if (data.success) {
					buttonElement.innerHTML = '&#10003; ADDED';
					buttonElement.style.borderColor = '#adff00';
					buttonElement.style.color = '#adff00';

					if (typeof refreshInventoryUI === 'function') {
						refreshInventoryUI();
					}
				} else {
					const message = data.message || data.data?.message || 'Action rejected';

					if (typeof window.twHandleActionError === 'function') {
						window.twHandleActionError(message);
					} else {
						alert(message);
					}

					buttonElement.disabled = false;
					buttonElement.innerHTML = originalContent;
					buttonElement.classList.remove('syncing');
				}
			})
			.catch(function (error) {
				console.error('Loot Error:', error);
				buttonElement.disabled = false;
				buttonElement.innerHTML = 'RETRY';
				buttonElement.classList.remove('syncing');
			});
	};

	window.twHandleActionError = function (rawMessage) {
		if (!rawMessage) rawMessage = 'Unknown error';

		const msg = String(rawMessage);
		const lower = msg.toLowerCase();
		let narrativeError = 'SYSTEM REFUSAL: ';

		if (lower.includes('too weak')) {
			narrativeError += 'The item is too heavy for your current physical state.';
		} else if (lower.includes('too large')) {
			narrativeError += "You have no room for this. It's too bulky for your gear.";
		} else if (lower.includes('full')) {
			narrativeError += 'Your containers are at maximum capacity.';
		} else if (lower.includes('not equipped')) {
			narrativeError += 'You are trying to stow this in a bag you are not wearing.';
		} else {
			narrativeError += msg;
		}

		const existing = document.querySelector('.tw-terminal-error');
		if (existing) {
			existing.remove();
		}

		const errorDiv = document.createElement('div');
		errorDiv.className = 'tw-terminal-error';
		errorDiv.innerHTML = `
			<div style="
				background: rgba(20, 0, 0, 0.9);
				color: #ff4444;
				border: 1px solid #ff4444;
				padding: 15px;
				font-family: 'Chakra Petch', sans-serif;
				position: fixed;
				bottom: 10%;
				left: 50%;
				transform: translateX(-50%);
				z-index: 10000;
				box-shadow: 0 0 20px rgba(255, 68, 68, 0.3);
				text-align: center;
			">
				<span style="color: #adff00;">[LOG_ERROR]</span><br>${narrativeError}
			</div>
		`;

		document.body.appendChild(errorDiv);

		setTimeout(function () {
			if (errorDiv && errorDiv.parentNode) {
				errorDiv.remove();
			}
		}, 4000);
	};

	window.parseInventoryTags = parseInventoryTags;
	window.refreshInventory = function () {
		refreshInventoryUI();
	};

	document.addEventListener('DOMContentLoaded', refreshInventoryUI);
})();
