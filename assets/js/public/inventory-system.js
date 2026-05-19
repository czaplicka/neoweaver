(function () {
	function safeText(value, fallback = '') {
		if (typeof value === 'string') {
			const trimmed = value.trim();
			return trimmed !== '' ? trimmed : fallback;
		}

		if (value === null || typeof value === 'undefined') {
			return fallback;
		}

		return String(value);
	}

	function safeUrl(value, fallback = '') {
		if (typeof value !== 'string') {
			return fallback;
		}

		const trimmed = value.trim();
		if (!trimmed) {
			return fallback;
		}

		try {
			const url = new URL(trimmed, window.location.origin);
			if (url.protocol === 'http:' || url.protocol === 'https:') {
				return url.href;
			}
		} catch (e) {
			return fallback;
		}

		return fallback;
	}

	function buildItemTooltip(item, quantity) {
		const name = safeText(item.name, 'Unnamed item').toUpperCase();
		const rarity = item.rarity ? `[${safeText(item.rarity).toUpperCase()}]` : '[COMMON]';
		const tags = Array.isArray(item.tags)
			? item.tags.map(function (t) { return `#${safeText(t, '')}`; }).filter(Boolean).join(' ')
			: '';
		const description = safeText(item.description, 'No description available.');
		const mass = Number(item.mass || 0);

		let lines = [`${name} ${rarity}`];

		if (typeof quantity !== 'undefined') {
			lines.push(`Qty: ${quantity}`);
		}

		if (tags) {
			lines.push(tags);
		}

		lines.push('');
		lines.push(description);
		lines.push('');
		lines.push(`Mass: ${mass}kg`);

		return lines.join('\n');
	}

	function playEquipSound(url) {
		const safeSoundUrl = safeUrl(url, '');
		if (!safeSoundUrl || safeSoundUrl === 'null') return;

		try {
			const audio = new Audio(safeSoundUrl);
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
			const empty = document.createElement('p');
			empty.style.opacity = '0.3';
			empty.style.fontSize = '0.7rem';
			empty.style.padding = '10px';
			empty.textContent = 'Empty...';
			listContainer.appendChild(empty);
			return;
		}

		items.forEach(function (entry) {
			const it = entry.info;
			if (!it) return;

			const card = document.createElement('div');
			card.className = 'tw-item-card';
			card.draggable = true;
			card.dataset.inventoryId = safeText(entry.id, '');
			card.dataset.itemSlot = safeText(it.slot, '');
			card.dataset.soundUrl = safeUrl(it.sound_url, '');
			card.style.cssText = 'background: rgba(255,255,255,0.05); margin-bottom: 2px; padding: 5px; cursor: grab;';
			card.title = buildItemTooltip(it, entry.quantity);

			const nameWrap = document.createElement('span');
			nameWrap.className = 'tw-item-name';
			nameWrap.style.fontSize = '0.85rem';

			const nameNode = document.createTextNode(safeText(it.name, 'Unnamed item'));
			nameWrap.appendChild(nameNode);

			const qty = document.createElement('small');
			qty.style.opacity = '0.6';
			qty.textContent = ` x${Number(entry.quantity || 0)}`;

			const mass = document.createElement('small');
			mass.style.float = 'right';
			mass.style.color = '#adff00';
			mass.textContent = `${Number(it.mass || 0)} kg`;

			nameWrap.appendChild(qty);
			nameWrap.appendChild(mass);
			card.appendChild(nameWrap);

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

			let targetSlot = document.querySelector(`[data-slot="${CSS.escape(safeText(entry.equipped_slot, ''))}"]`);

			if (!targetSlot) {
				targetSlot = Array.from(slots).find(function (s) {
					return (
						s.dataset.slot &&
						s.dataset.slot.startsWith(safeText(item.slot, '')) &&
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
		img.src = safeUrl(item.img_url, 'https://via.placeholder.com/50');
		img.className = 'item-img-fluid';
		img.draggable = true;
		img.dataset.inventoryId = safeText(inventoryId, '');
		img.dataset.itemSlot = safeText(item.slot, '');
		img.dataset.soundUrl = safeUrl(item.sound_url, '');
		img.title = buildItemTooltip(item);

		const colors = {
			legendary: '#ff8000',
			epic: '#a335ee',
			rare: '#0070dd'
		};

		const color = colors[safeText(item.rarity, '').toLowerCase()] || 'rgba(173, 255, 0, 0.4)';

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
				return reference.getAttribute('title') || '';
			},
			allowHTML: false,
			theme: 'cyberpunk'
		});
	}

	function parseInventoryTags(text) {
	if (typeof text !== 'string') return text;

	const container = document.createElement('div');
	const itemRegex = /\[ITEM:\s*([0-9a-fA-F-]{36})\s*\]/g;
	let lastIndex = 0;
	let match;

	while ((match = itemRegex.exec(text)) !== null) {
		const before = text.slice(lastIndex, match.index);
		if (before) {
			container.appendChild(document.createTextNode(before));
		}

		const itemId = String(match[1] || '').trim().toLowerCase();

		const button = document.createElement('button');
		button.className = 'tw-loot-button';
		button.dataset.itemId = itemId;

		const textSpan = document.createElement('span');
		textSpan.className = 'tw-btn-text';
		textSpan.textContent = 'TAKE ITEM';

		const idSpan = document.createElement('span');
		idSpan.className = 'tw-btn-id';
		idSpan.textContent = `#${itemId.slice(0, 8)}`;

		button.appendChild(textSpan);
		button.appendChild(idSpan);

		button.addEventListener('click', function () {
			window.handleLootAction(itemId, button);
		});

		container.appendChild(button);
		lastIndex = itemRegex.lastIndex;
	}

	const after = text.slice(lastIndex);
	if (after) {
		container.appendChild(document.createTextNode(after));
	}

	return container.innerHTML;
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

		const box = document.createElement('div');
		box.style.background = 'rgba(20, 0, 0, 0.9)';
		box.style.color = '#ff4444';
		box.style.border = '1px solid #ff4444';
		box.style.padding = '15px';
		box.style.fontFamily = "'Chakra Petch', sans-serif";
		box.style.position = 'fixed';
		box.style.bottom = '10%';
		box.style.left = '50%';
		box.style.transform = 'translateX(-50%)';
		box.style.zIndex = '10000';
		box.style.boxShadow = '0 0 20px rgba(255, 68, 68, 0.3)';
		box.style.textAlign = 'center';

		const label = document.createElement('span');
		label.style.color = '#adff00';
		label.textContent = '[LOG_ERROR]';

		box.appendChild(label);
		box.appendChild(document.createElement('br'));
		box.appendChild(document.createTextNode(narrativeError));

		errorDiv.appendChild(box);
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
