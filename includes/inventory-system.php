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
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
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

	            const invId          = dragging.dataset.inventoryId;
	            const itemType       = dragging.dataset.itemSlot;
	            const itemSound      = dragging.dataset.soundUrl || '';
	            const isToEquip      = zone.classList.contains('inv-slot');
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

	/**
	 * BUG-FIX: The previous implementation patched cyber_character_inventory
	 * directly from the browser via the Supabase anon key with no ownership
	 * check. Any logged-in user who knew another character's inventory row ID
	 * could equip or unequip that character's items.
	 *
	 * Fix: route the mutation through a WordPress AJAX endpoint
	 * (tw_update_inventory_slot) that:
	 *   1. Verifies the nonce.
	 *   2. Resolves the current user's active character_id server-side.
	 *   3. Confirms the inventory row belongs to that character before patching.
	 * The anon key never leaves the server for write operations.
	 */
	async function updateItemEquipmentStatus(inventoryId, isEquipped, slotName = null) {
	    const config  = window.twAdventureData;
	    const ajaxUrl = config?.ajax_url || '/wp-admin/admin-ajax.php';
	    const nonce   = config?.nonce    || '';

	    const fd = new FormData();
	    fd.append('action',       'tw_update_inventory_slot');
	    fd.append('nonce',        nonce);
	    fd.append('inventory_id', inventoryId);
	    fd.append('is_equipped',  isEquipped ? '1' : '0');
	    fd.append('slot_name',    slotName || '');

	    try {
	        const response = await fetch(ajaxUrl, {
	            method:      'POST',
	            body:        fd,
	            credentials: 'same-origin',
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
	        content(reference) { return reference.getAttribute('title'); },
	        theme: 'cyberpunk',
	    });
	}

	document.addEventListener('DOMContentLoaded', refreshInventoryUI);
	</script>
	<?php
}, 40 );

// ─── AJAX handler: tw_update_inventory_slot ──────────────────────────────────
// Server-side ownership-checked inventory equip/unequip endpoint.
// Replaces the previous pattern of patching Supabase directly from JS.
add_action( 'wp_ajax_tw_update_inventory_slot', 'tw_handle_update_inventory_slot' );

if ( ! function_exists( 'tw_handle_update_inventory_slot' ) ) {
	function tw_handle_update_inventory_slot(): void {
		// 1. Nonce verification.
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed' ] );
			return;
		}

		// 2. Must be logged in.
		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in' ] );
			return;
		}

		// 3. Validate and sanitize inputs.
		$inventory_id = isset( $_POST['inventory_id'] )
			? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['inventory_id'] )
			: '';
		if ( empty( $inventory_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing inventory_id' ] );
			return;
		}

		$is_equipped = ! empty( $_POST['is_equipped'] ) && $_POST['is_equipped'] === '1';
		$slot_name   = isset( $_POST['slot_name'] )
			? sanitize_text_field( (string) $_POST['slot_name'] )
			: null;
		if ( $slot_name === '' ) {
			$slot_name = null;
		}

		// 4. Resolve the active character_id for this user.
		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( [ 'message' => 'Game data helper missing' ] );
			return;
		}
		$game_data    = get_user_game_data_from_supabase( $wp_user_id );
		$character_id = $game_data['active_character_id'] ?? '';

		if ( empty( $character_id ) ) {
			wp_send_json_error( [ 'message' => 'No active character' ] );
			return;
		}

		// 5. Ownership check: confirm the inventory row belongs to this character
		//    before allowing any mutation.
		$ownership_rows = tw_supabase_get(
			'cyber_character_inventory',
			[
				'id'           => 'eq.' . $inventory_id,
				'character_id' => 'eq.' . $character_id,
				'select'       => 'id',
				'limit'        => 1,
			]
		);

		if ( empty( $ownership_rows ) ) {
			wp_send_json_error( [ 'message' => 'Inventory item not found or not owned by current character' ] );
			return;
		}

		// 6. Perform the PATCH server-side using the project-wide helper.
		$patch_body = [ 'is_equipped' => $is_equipped, 'equipped_slot' => $slot_name ];

		$result = tw_supabase_request(
			'PATCH',
			'cyber_character_inventory',
			[ 'id' => 'eq.' . $inventory_id ],
			$patch_body
		);

		if ( ! is_wp_error( $result ) ) {
			error_log( 'TW tw_handle_update_inventory_slot: Supabase PATCH failed, code=' . $result['code'] );
			wp_send_json_error( [ 'message' => 'Database update failed', 'code' => $result['code'] ] );
			return;
		}

		wp_send_json_success( [ 'message' => 'Inventory updated' ] );
	}
}

/**
 * TALE WEAVER – LOOT & INVENTORY TAGS
 * Hook: wp_footer, priorytet 41.
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return;
	}
	?>
<script>
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
	?
    if (!buttonElement) return;

    const characterId = window.twAdventureData?.active_character_id
        || window.currentPlayerId
        || 1;

    buttonElement.disabled = true;
    buttonElement.classList.add('syncing');
    const originalContent = buttonElement.innerHTML;
    buttonElement.innerText = 'SYNCING...';

    const payload = {
        action: 'loot_item',
        character_id: characterId,
        item_id: itemId,
    };

    fetch(webhookUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
        .then((response) => {
            if (!response.ok) throw new Error('Network error');
            return response.json().catch(() => ({}));
        })
        .then((data) => {
            if (data.success) {
                buttonElement.innerHTML = '&#10003; ADDED';
                buttonElement.style.borderColor = '#adff00';
                buttonElement.style.color = '#adff00';

                if (typeof refreshInventoryUI === 'function') {
                    refreshInventoryUI();
                }
            } else {
                const message = data.message || 'Action rejected';

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
        .catch((error) => {
            console.error('Loot Error:', error);
            buttonElement.disabled = false;
            buttonElement.innerHTML = 'RETRY';
            buttonElement.classList.remove('syncing');
        });
};

window.refreshInventory = function () {
    if (typeof refreshInventoryUI === 'function') {
        refreshInventoryUI();
    } else {
        console.warn('refreshInventoryUI not available yet.');
    }
};
</script>
	<?php
}, 41 );

/**
 * TALE WEAVER – LOOT ERROR OVERLAY
 * Hook: wp_footer, priorytet 42.
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return;
	}
	?>
<script>
window.twHandleActionError = function (rawMessage) {
    if (!rawMessage) rawMessage = 'Unknown error';
    const msg   = String(rawMessage);
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
    if (existing) existing.remove();

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

    setTimeout(() => {
        if (errorDiv && errorDiv.parentNode) {
            errorDiv.remove();
        }
    }, 4000);
};
</script>
	<?php
}, 42 );
