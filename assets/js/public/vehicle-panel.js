function updateVehicleInSupabase(moduleId, slotName) {
    const data = new FormData();
    data.append('action', 'update_vehicle_module');
    data.append('vehicle_id', document.getElementById('neoweave-vehicle-interface').dataset.vehicleId);
    data.append('module_id', moduleId);
    data.append('target_slot', slotName); // np. 'slot_lateral_l'

    fetch(ajaxurl, { // ajaxurl jest predefiniowany w WP
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(res => {
        if(res.success) {
            console.log("Sync Complete: " + res.data.message);
            // Tutaj możesz odświeżyć statystyki udźwigu
        }
    });
}
