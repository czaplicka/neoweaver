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
