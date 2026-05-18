(function () {
	function allowDrop(ev) {
		ev.preventDefault();
	}

	function drag(ev) {
		ev.dataTransfer.setData('text/plain', ev.target.id);
	}

	function findVehiclePanelRoot(element) {
		return element ? element.closest('[data-vehicle-id][data-character-id]') : null;
	}

	function updateVehicleInSupabase(moduleId, slotName, panelRoot) {
		const root = panelRoot || document.querySelector('[data-vehicle-id][data-character-id]');
		if (!root) {
			console.error('Vehicle panel root not found');
			return Promise.resolve();
		}

		const vehicleId = root.dataset.vehicleId || '';
		if (!vehicleId) {
			console.error('Missing vehicle_id');
			return Promise.resolve();
		}

		const ajaxUrl = window.neoweaveVehicle?.ajaxurl || '';
		const nonce = window.neoweaveVehicle?.nonce || '';

		if (!ajaxUrl) {
			console.error('Missing AJAX URL');
			return Promise.resolve();
		}

		const data = new FormData();
		data.append('action', 'update_vehicle_module');
		data.append('nonce', nonce);
		data.append('vehicle_id', vehicleId);
		data.append('module_id', moduleId);
		data.append('target_slot', slotName);

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
				} else {
					console.error('Vehicle sync failed', res);
				}
				return res;
			})
			.catch(function (error) {
				console.error('Vehicle sync error:', error);
			});
	}

	function drop(ev) {
		ev.preventDefault();

		const data = ev.dataTransfer.getData('text/plain');
		const draggedElement = document.getElementById(data);
		const dropTarget = ev.target.closest('.v-slot, #garage-container');

		if (!draggedElement || !dropTarget) {
			return;
		}

		const panelRoot = findVehiclePanelRoot(dropTarget);
		const itemType = draggedElement.getAttribute('data-type') || '';
		const slotName = dropTarget.getAttribute('data-slot') || '';

		if (slotName !== 'garage' && !slotName.includes(itemType)) {
			console.error('Incompatible Slot!');
			return;
		}

		dropTarget.appendChild(draggedElement);
		updateVehicleInSupabase(data, slotName, panelRoot);
	}

	function bindVehiclePanel(panel) {
		if (!panel || panel.dataset.twBound === '1') {
			return;
		}

		panel.querySelectorAll('.module-item[draggable="true"]').forEach(function (item) {
			item.addEventListener('dragstart', drag);
		});

		panel.querySelectorAll('.v-slot, #garage-container').forEach(function (zone) {
			zone.addEventListener('dragover', allowDrop);
			zone.addEventListener('drop', drop);
		});

		panel.dataset.twBound = '1';
	}

	function initVehiclePanels() {
		document.querySelectorAll('[data-vehicle-id][data-character-id]').forEach(function (panel) {
			bindVehiclePanel(panel);
		});
	}

	window.updateVehicleInSupabase = updateVehicleInSupabase;
	window.neoweaveVehiclePanelInit = initVehiclePanels;

	document.addEventListener('DOMContentLoaded', initVehiclePanels, { once: true });
})();
