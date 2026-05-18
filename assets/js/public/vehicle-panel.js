(function () {
	function allowDrop(ev) {
		ev.preventDefault();
	}

	function drag(ev) {
		if (!ev.dataTransfer || !ev.target) {
			return;
		}

		ev.dataTransfer.setData('text/plain', ev.target.id);
	}

	function findVehiclePanelRoot(element) {
		return element ? element.closest('[data-vehicle-panel-root]') : null;
	}

	function isCompatibleSlot(itemType, slotName) {
		if (!slotName || slotName === 'garage') {
			return true;
		}

		if (!itemType) {
			return false;
		}

		return slotName.includes(itemType);
	}

	function updateVehicleInSupabase(moduleId, slotName, panelRoot) {
		const root = panelRoot || document.querySelector('[data-vehicle-panel-root]');
		if (!root) {
			console.error('Vehicle panel root not found');
			return Promise.resolve();
		}

		const vehicleId = root.dataset.vehicleId || '';
		const characterId = root.dataset.characterId || '';
		const ajaxUrl = window.neoweaveVehicle?.ajaxurl || '';
		const nonce = window.neoweaveVehicle?.nonce || '';

		if (!vehicleId) {
			console.error('Missing vehicle_id');
			return Promise.resolve();
		}

		if (!ajaxUrl) {
			console.error('Missing AJAX URL');
			return Promise.resolve();
		}

		const data = new FormData();
		data.append('action', 'update_vehicle_module');
		data.append('nonce', nonce);
		data.append('vehicle_id', vehicleId);
		data.append('character_id', characterId);
		data.append('module_id', moduleId);
		data.append('target_slot', slotName || '');

		return fetch(ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (res) {
				if (res.success) {
					console.log('Sync Complete: ' + (res.data?.message || 'OK'));

					if (res.data?.stats) {
						refreshVehicleStats(root, res.data.stats);
					}
				} else {
					console.error('Vehicle sync failed', res);
				}

				return res;
			})
			.catch(function (error) {
				console.error('Vehicle sync error:', error);
			});
	}

	function refreshVehicleStats(panelRoot, stats) {
		if (!panelRoot || !stats) {
			return;
		}

		const hpBar = panelRoot.querySelector('.v-hp-bar');
		const hpText = panelRoot.querySelector('.v-hp-text');
		const fuelBar = panelRoot.querySelector('.v-fuel-bar');
		const fuelText = panelRoot.querySelector('.v-fuel-text');
		const cargoWeight = panelRoot.querySelector('.cargo-weight');
		const cargoMax = panelRoot.querySelector('.cargo-max');

		if (typeof stats.hp_percent !== 'undefined' && hpBar) {
			hpBar.style.width = String(stats.hp_percent) + '%';
		}

		if (typeof stats.hp_text !== 'undefined' && hpText) {
			hpText.textContent = String(stats.hp_text);
		}

		if (typeof stats.fuel_percent !== 'undefined' && fuelBar) {
			fuelBar.style.width = String(stats.fuel_percent) + '%';
		}

		if (typeof stats.fuel_text !== 'undefined' && fuelText) {
			fuelText.textContent = String(stats.fuel_text);
		}

		if (typeof stats.cargo_weight !== 'undefined' && cargoWeight) {
			cargoWeight.textContent = String(stats.cargo_weight);
		}

		if (typeof stats.cargo_max !== 'undefined' && cargoMax) {
			cargoMax.textContent = String(stats.cargo_max);
		}
	}

	function moveDraggedElementToDropTarget(draggedElement, dropTarget) {
		if (!draggedElement || !dropTarget) {
			return;
		}

		if (dropTarget.classList.contains('v-slot')) {
			const occupant = dropTarget.querySelector('.slot-occupant');
			if (occupant) {
				occupant.innerHTML = '';
				occupant.appendChild(draggedElement);
				return;
			}
		}

		dropTarget.appendChild(draggedElement);
	}

	function handleDrop(ev) {
		ev.preventDefault();

		const data = ev.dataTransfer?.getData('text/plain');
		if (!data) {
			return;
		}

		const draggedElement = document.getElementById(data);
		const dropTarget = ev.target.closest('.v-slot, .garage-container');

		if (!draggedElement || !dropTarget) {
			return;
		}

		const panelRoot = findVehiclePanelRoot(dropTarget);
		const itemType = draggedElement.getAttribute('data-type') || '';
		const slotName = dropTarget.getAttribute('data-slot') || '';

		if (!isCompatibleSlot(itemType, slotName)) {
			console.error('Incompatible Slot!');
			return;
		}

		moveDraggedElementToDropTarget(draggedElement, dropTarget);
		updateVehicleInSupabase(data, slotName, panelRoot);
	}

	function bindDraggableItem(item) {
		if (!item || item.dataset.twBound === '1') {
			return;
		}

		item.addEventListener('dragstart', drag);
		item.dataset.twBound = '1';
	}

	function bindDropZone(zone) {
		if (!zone || zone.dataset.twBound === '1') {
			return;
		}

		zone.addEventListener('dragover', allowDrop);
		zone.addEventListener('drop', handleDrop);
		zone.dataset.twBound = '1';
	}

	function bindVehiclePanel(panel) {
		if (!panel || panel.dataset.twPanelBound === '1') {
			return;
		}

		panel.querySelectorAll('.module-item[draggable="true"]').forEach(function (item) {
			bindDraggableItem(item);
		});

		panel.querySelectorAll('.v-slot, .garage-container').forEach(function (zone) {
			bindDropZone(zone);
		});

		panel.dataset.twPanelBound = '1';
	}

	function initVehiclePanels() {
		document.querySelectorAll('[data-vehicle-panel-root]').forEach(function (panel) {
			bindVehiclePanel(panel);
		});
	}

	window.updateVehicleInSupabase = updateVehicleInSupabase;
	window.neoweaveVehiclePanelInit = initVehiclePanels;

	document.addEventListener('DOMContentLoaded', initVehiclePanels, { once: true });
})();
