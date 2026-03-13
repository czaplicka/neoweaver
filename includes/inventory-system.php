<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - INVENTORY SYSTEM
 * Drag & drop, paperdoll, ekwipunek postaci.
 * Hook: wp_footer, priorytet 40 (po skills-loader.php który ma 35).
 */
add_action( 'wp_footer', function () {
	if ( ! is_page( 2857 ) || ! get_current_user_id() ) {
		return;
	}
	?>
	<script>
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
	    if (!config?.active_character_id) return;

	    const url =
	        `${config.supabase_url}/rest/v1/cyber_character_inventory` +
	        `?character_id=eq.${config.active_character_id}` +
	        `&select=id,is_equipped,equipped_slot,quantity,info:cyber_items(*)`;

	    try {
	        const response = await fetch(url, {
	            headers: {
	                apikey: config.supabase_anon_key,
	                Authorization: `Bearer ${config.supabase_anon_key}`,
	            },
	        });

	        const data = await response.json();
	        if (!Array.isArray(data)) {
	            console.error('Inventory refresh: invalid data format', data);
	            return;
	        }

	        const equipped = data.filter((item) => item.is_equipped === true);
	        const carried  = data.filter((item) => item.is_equipped === false);

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

	    items.forEach((entry) => {
	        const it = entry.info;
	        if (!it) return;

	        const card = document.createElement('div');
	        card.className = 'tw-item-card';
	        card.draggable = true;
	        card.dataset.inventoryId = entry.id;
	        card.dataset.itemSlot    = it.slot || '';
	        card.dataset.soundUrl    = it.sound_url || '';
	        card.style.cssText = 'background: rgba(255,255,255,0.05); margin-bottom: 2px; padding: 5px; cursor: grab;';

	        const rarity      = it.rarity ? `[${it.rarity.toUpperCase()}]` : '[COMMON]';
	        const tags        = it.tags && Array.isArray(it.tags) ? it.tags.map((t) => `#${t}`).join(' ') : '';
	        const description = it.description || 'No description available.';
	        const mass        = it.mass || 0;

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

	    slots.forEach((slot) => {
	        const iconContainer = slot.querySelector('.item-icon');
	        if (iconContainer) iconContainer.innerHTML = '';
	        slot.style.borderColor = 'rgba(173, 255, 0, 0.4)';
	        slot.style.boxShadow   = 'none';
	    });

	    (equippedData || []).forEach((entry) => {
	        const item = entry.info;
	        if (!item) return;

	        totalPower += item.power_value || 0;

	        let targetSlot = document.querySelector(`[data-slot="${entry.equipped_slot}"]`);

	        if (!targetSlot) {
	            targetSlot = Array.from(slots).find(
	                (s) =>
	                    s.dataset.slot &&
	                    s.dataset.slot.startsWith(item.slot) &&
	                    s.querySelector('.item-icon') &&
	                    s.querySelector('.item-icon').innerHTML === ''
	            );
	        }

	        if (targetSlot) renderItemInSlot(targetSlot, item, entry.id);
	    });

	    const powerEl = document.getElementById('total-power-value');
	    if (powerEl) powerEl.innerText = totalPower;
	}

	function renderItemInSlot(slotElement, item, inventoryId) {
	    const iconContainer = slotElement.querySelector('.item-icon');
	    if (!iconContainer) return;

	    const img = document.createElement('img');
	    img.src                 = item.img_url || 'https://via.placeholder.com/50';
	    img.className           = 'item-img-fluid';
	    img.draggable           = true;
	    img.dataset.inventoryId = inventoryId;
	    img.dataset.itemSlot    = item.slot || '';
	    img.dataset.soundUrl    = item.sound_url || '';

	    const tags = item.tags && Array.isArray(item.tags) ? item.tags.map((t) => `#${t}`).join(' ') : '';
	    img.title = `${item.name.toUpperCase()} [${item.rarity || 'Common'}]\n${tags}\n\n${item.description || ''}`;

	    const colors = { legendary: '#ff8000', epic: '#a335ee', rare: '#0070dd' };
	    const color  = colors[(item.rarity || '').toLowerCase()] || 'rgba(173, 255, 0, 0.4)';

	    slotElement.style.borderColor = color;
	    slotElement.style.boxShadow   = `inset 0 0 8px ${color}`;

	    iconContainer.appendChild(img);
	}

	function initDragAndDrop() {
	    const draggables = document.querySelectorAll('.tw-item-card, .item-img-fluid');
	    const dropZones  = document.querySelectorAll('.inv-slot, #tw-inventory-list');

	    draggables.forEach((d) => {
	        d.addEventListener('dragstart', () => d.classList.add('dragging'));
	        d.addEventListener('dragend',   () => d.classList.remove('dragging'));
	    });

	    dropZones.forEach((zone) => {
	        zone.addEventListener('dragover', (e) => e.preventDefault());
	        zone.addEventListener('drop', async (e) => {
	            e.preventDefault();
	            const dragging = document.querySelector('.dragging');
	            if (!dragging) return;

	            const invId        = dragging.dataset.inventoryId;
	            const itemType     = dragging.dataset.itemSlot;
	            const itemSound    = dragging.dataset.soundUrl || '';
	            const isToEquip    = zone.classList.contains('inv-slot');
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

	async function updateItemEquipmentStatus(inventoryId, isEquipped, slotName = null) {
	    const config = window.twAdventureData;
	    if (!config) return;

	    const url = `${config.supabase_url}/rest/v1/cyber_character_inventory?id=eq.${inventoryId}`;

	    try {
	        const response = await fetch(url, {
	            method: 'PATCH',
	            headers: {
	                apikey: config.supabase_anon_key,
	                Authorization: `Bearer ${config.supabase_anon_key}`,
	                'Content-Type': 'application/json',
	            },
	            body: JSON.stringify({ is_equipped: isEquipped, equipped_slot: slotName }),
	        });

	        if (response.ok) {
	            await refreshInventoryUI();
	        } else {
	            console.error('Inventory update failed', await response.text());
	        }
	    } catch (err) {
	        console.error('Inventory update error:', err);
	    }
	}

	function initItemTooltips() {
	    if (!window.tippy) return;
	    window.tippy('.tw-item-card, .item-img-fluid', {
	        content(reference) { return reference.getAttribute('title'); },
	        theme: 'cyberpunk',
	    });
	}

	document.addEventListener('DOMContentLoaded', refreshInventoryUI);
	</script>
	<?php
}, 40 );
