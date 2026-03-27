<?php
function cyber_deck_builder_shortcode() {
    // 1. Pobierz ID gracza (zintegrowane z WP)
    $user_id = get_current_user_id();
    if (!$user_id) return "Zaloguj się, aby zarządzać taliami.";

    // 2. Pobranie kart z bazy (używając Twoich nowych tabel)
    // UWAGA: Tutaj należy użyć Twojej funkcji łączącej się z Supabase
    $all_cards = fetch_cyber_cards_from_supabase($user_id); 

    ob_start();
    ?>
    <div class="deck-builder-container" style="font-family: 'Chakra Petch', sans-serif; color: #adff00;">
        <div class="deck-section">
            <h3>ACTIVE DECK (50 MAX)</h3>
            <div id="active-deck" class="card-slot-container" ondrop="drop(event)" ondragover="allowDrop(event)">
                <?php foreach ($all_cards as $card): 
                    if ($card->location == 'pile'): ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)" id="card-<?php echo $card->instance_id; ?>" data-instance-id="<?php echo $card->instance_id; ?>">
                            <img src="<?php echo $card->img_url; ?>" alt="">
                            <div class="card-info">
                                <span class="card-name"><?php echo $card->name; ?></span>
                                <span class="card-lvl">LVL <?php echo $card->level; ?></span>
                            </div>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        </div>

        <div class="deck-section">
            <h3>LIBRARY (REPOSITORY)</h3>
            <div id="library-deck" class="card-slot-container" ondrop="drop(event)" ondragover="allowDrop(event)">
                <?php foreach ($all_cards as $card): 
                    if ($card->location == 'library'): ?>
                        <div class="cyber-card" draggable="true" ondragstart="drag(event)" id="card-<?php echo $card->instance_id; ?>" data-instance-id="<?php echo $card->instance_id; ?>">
                            <img src="<?php echo $card->img_url; ?>" alt="">
                            <div class="card-info">
                                <span class="card-name"><?php echo $card->name; ?></span>
                                <span class="card-lvl">LVL <?php echo $card->level; ?></span>
                            </div>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        </div>
        
        <button id="save-deck-btn" onclick="saveDeckState()">SYNC WITH TERMINAL</button>
    </div>

    <style>
        .deck-builder-container { display: flex; gap: 20px; background: #000; padding: 20px; border: 1px solid #adff00; }
        .deck-section { flex: 1; }
        .card-slot-container { 
            min-height: 400px; background: rgba(173, 255, 0, 0.05); 
            border: 1px dashed #adff00; display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; padding: 10px;
        }
        .cyber-card { 
            background: #111; border: 1px solid #adff00; padding: 5px; cursor: grab; font-size: 10px; text-align: center;
        }
        .cyber-card img { width: 100%; height: auto; filter: grayscale(1) sepia(1) hue-rotate(50deg); }
        .card-info { display: flex; flex-direction: column; }
        #save-deck-btn { margin-top: 20px; background: #adff00; color: #000; border: none; padding: 10px 20px; cursor: pointer; font-weight: bold; }
    </style>

    <script>
        function allowDrop(ev) { ev.preventDefault(); }
        function drag(ev) { ev.dataTransfer.setData("text", ev.target.id); }
        
        function drop(ev) {
            ev.preventDefault();
            var data = ev.dataTransfer.getData("text");
            var draggedElement = document.getElementById(data);
            var dropTarget = ev.target.closest('.card-slot-container');
            
            if (dropTarget) {
                dropTarget.appendChild(draggedElement);
            }
        }

        function saveDeckState() {
            const activeCards = Array.from(document.getElementById('active-deck').children).map(c => c.dataset.instanceId);
            const libraryCards = Array.from(document.getElementById('library-deck').children).map(c => c.dataset.instanceId);

            // Tutaj wysyłasz AJAX do Supabase, który aktualizuje kolumnę 'location'
            console.log("Saving Active Deck:", activeCards);
            // Wywołanie Twojej funkcji API...
            alert("Buffer Synced!");
        }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('cyber_deck_builder', 'cyber_deck_builder_shortcode');

// Rejestracja akcji AJAX dla zalogowanych użytkowników
add_action('wp_ajax_save_cyber_deck', 'handle_save_cyber_deck');

function handle_save_cyber_deck() {
    // 1. Bezpieczeństwo
    check_ajax_referer('cyber_deck_nonce', 'nonce');
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('Unauthorized access.');
    }

    // 2. Pobranie danych z POST
    $active_ids = json_decode(stripslashes($_POST['active_ids']), true);
    $library_ids = json_decode(stripslashes($_POST['library_ids']), true);

    if (!is_array($active_ids) || !is_array($library_ids)) {
        wp_send_json_error('Invalid data format.');
    }

    // 3. Logika aktualizacji Supabase
    // Założenie: Masz funkcję update_supabase_card_location($instance_id, $location)
    
    try {
        // Karty przeniesione do talii (Active Deck) ustawiamy na 'pile' (do dociągnięcia)
        foreach ($active_ids as $id) {
            cyber_update_card_location($id, 'pile');
        }

        // Karty przeniesione do repozytorium ustawiamy na 'library'
        foreach ($library_ids as $id) {
            cyber_update_card_location($id, 'library');
        }

        wp_send_json_success('Deck updated successfully.');
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Przykładowa funkcja pomocnicza wywołująca API Supabase (musisz ją dostosować do swojej klasy API)
function cyber_update_card_location($instance_id, $location) {
    // Przykład użycia Twoich danych z wp-config (host, key itp.)
    // Wykonujesz UPDATE public.cyber_character_buffer SET location = $location WHERE id = $instance_id
    
    /* TU TWÓJ KOD CURL LUB KLASY SUPABASE:
       $supabase->from('cyber_character_buffer')->update(['location' => $location])->eq('id', $instance_id);
    */
}
function saveDeckState() {
    // Pobieramy kontenery
    const activeContainer = document.getElementById('active-deck');
    const libraryContainer = document.getElementById('library-deck');
    const saveBtn = document.getElementById('save-deck-btn');

    // Wyciągamy ID instancji (z data-instance-id)
    const activeIds = Array.from(activeContainer.querySelectorAll('.cyber-card'))
        .map(card => card.dataset.instanceId);
    
    const libraryIds = Array.from(libraryContainer.querySelectorAll('.cyber-card'))
        .map(card => card.dataset.instanceId);

    // Wizualny feedback
    saveBtn.innerText = "UPLOADING TO TERMINAL...";
    saveBtn.disabled = true;

    // Przygotowanie danych do wysyłki
    const formData = new FormData();
    formData.append('action', 'save_cyber_deck'); // Nazwa akcji dla WP
    formData.append('active_ids', JSON.stringify(activeIds));
    formData.append('library_ids', JSON.stringify(libraryIds));
    formData.append('nonce', '<?php echo wp_create_nonce("cyber_deck_nonce"); ?>');

    // Wysyłka AJAX
    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("DECK SYNCED: Buffer updated successfully.");
        } else {
            alert("SYNC ERROR: " + (data.data || "Unknown error"));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("CRITICAL ERROR: Terminal Connection Lost.");
    })
    .finally(() => {
        saveBtn.innerText = "SYNC WITH TERMINAL";
        saveBtn.disabled = false;
    });
}
