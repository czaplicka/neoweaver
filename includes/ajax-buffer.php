<?php
function saveDeckState() {
    // 1. Zbieramy ID instancji kart z kontenera Active Deck
    const activeDeckIds = Array.from(document.querySelectorAll('#active-deck .cyber-card'))
        .map(card => card.dataset.instanceId);

    // 2. Zbieramy ID instancji kart z kontenera Library
    const libraryIds = Array.from(document.querySelectorAll('#library-deck .cyber-card'))
        .map(card => card.dataset.instanceId);

    const saveBtn = document.getElementById('save-deck-btn');
    saveBtn.innerText = "UPLOADING TO TERMINAL...";
    saveBtn.disabled = true;

    // 3. Wywołanie AJAX
    const formData = new FormData();
    formData.append('action', 'save_cyber_deck');
    formData.append('active_ids', JSON.stringify(activeDeckIds));
    formData.append('library_ids', JSON.stringify(libraryIds));
    formData.append('nonce', '<?php echo wp_create_nonce("cyber_deck_nonce"); ?>');

    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("Buffer Synced! Connection Stable.");
        } else {
            alert("Sync Failed: " + data.data);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("CRITICAL ERROR: Connection Lost.");
    })
    .finally(() => {
        saveBtn.innerText = "SYNC WITH TERMINAL";
        saveBtn.disabled = false;
    });
}
