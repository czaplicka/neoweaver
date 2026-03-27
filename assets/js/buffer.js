/**
 * NEOWEAVE CORE INTERFACE - JS BUNDLE
 */

// --- 1. INICJALIZACJA SLIDERA (HAND/BUFFER) ---
const bufferSwiper = new Swiper('.buffer-slider', {
    slidesPerView: 'auto',
    centeredSlides: true,
    spaceBetween: 20,
    grabCursor: true,
    pagination: { el: '.swiper-pagination', clickable: true }
});

// --- 2. LOGIKA HAND (UŻYWANIE KART I ZOOM) ---

function zoomCard(el) {
    const overlay = document.getElementById('card-zoom-overlay');
    const content = document.getElementById('zoom-content');
    
    // Klonujemy zawartość karty do modala
    content.innerHTML = el.outerHTML;
    overlay.style.display = 'flex';
    
    // Ukrywamy przycisk INJECT w podglądzie zoom, by uniknąć przypadkowego kliknięcia
    const zoomBtn = content.querySelector('.inject-btn');
    if (zoomBtn) zoomBtn.style.display = 'none';
}

function closeZoom() {
    document.getElementById('card-zoom-overlay').style.display = 'none';
}

function useBufferCard(instanceId, name, event) {
    if (event) event.stopPropagation(); // Zapobiega otwarciu zoomu przy kliknięciu w przycisk
    
    if(!confirm("Execute protocol: " + name + "?")) return;

    // Znajdź slajd i kartę w DOM dla animacji
    const cardElement = document.querySelector(`[data-instance-id="${instanceId}"]`);
    const slideElement = cardElement ? cardElement.closest('.swiper-slide') : null;

    if (cardElement) {
        // Efekt wizualny "wypalania" karty
        cardElement.style.transition = "all 0.4s ease";
        cardElement.style.filter = "brightness(3) blur(10px)";
        cardElement.style.transform = "translateY(-100px) scale(0.5)";
        cardElement.style.opacity = "0";
    }

    // AJAX do Supabase (via WP)
    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type: application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'use_buffer_card',
            instance_id: instanceId,
            nonce: config_nonces.use_card // Zakładamy obiekt z noncami przekazany z PHP
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            const newCard = data.data;
            
            // Budujemy HTML nowej karty do wstrzyknięcia w slider
            const newCardHTML = `
                <div class="cyber-card-css ${newCard.category.toLowerCase()} shadow-spawn" 
                     onclick="zoomCard(this)"
                     data-instance-id="${newCard.instance_id}">
                    <div class="card-glitch-overlay"></div>
                    <div class="card-header">
                        <span class="card-cat">${newCard.category.toUpperCase()}</span>
                        <span class="card-lvl">v.${newCard.level}</span>
                    </div>
                    <div class="card-content">
                        <h3 class="card-title">${newCard.name}</h3>
                        <p class="card-desc">${newCard.description}</p>
                    </div>
                    <div class="card-footer">
                        <button class="inject-btn" onclick="useBufferCard('${newCard.instance_id}', '${newCard.name}', event)">
                            INJECT PROTOCOL
                        </button>
                    </div>
                </div>
            `;

            // Jeśli mamy slider, podmieniamy slajd bez przeładowania
            if (slideElement) {
                setTimeout(() => {
                    slideElement.innerHTML = newCardHTML;
                    bufferSwiper.update();
                    
                    // Aktualizujemy liczniki HUD, jeśli istnieją (Punkt 4)
                    updateHUD(data.data.pile_count, data.data.discard_count);
                }, 400);
            } else {
                location.reload(); // Fallback
            }
        } else {
            alert("UPLINK ERROR: " + data.data);
            if (cardElement) { // Reset animacji przy błędzie
                cardElement.style.opacity = "1";
                cardElement.style.transform = "none";
                cardElement.style.filter = "none";
            }
        }
    });
}

function updateHUD(pile, discard) {
    const pEl = document.getElementById('count-pile');
    const dEl = document.getElementById('count-discard');
    
    if(pEl && pile !== undefined) {
        pEl.innerText = pile;
        pEl.classList.add('value-update');
        setTimeout(() => pEl.classList.remove('value-update'), 400);
    }
    if(dEl && discard !== undefined) {
        dEl.innerText = discard;
        dEl.classList.add('value-update');
        setTimeout(() => dEl.classList.remove('value-update'), 400);
    }
}

// --- 3. LOGIKA DECK BUILDER (DRAG & DROP + SYNC) ---

function allowDrop(ev) { ev.preventDefault(); }

function drag(ev) {
    ev.dataTransfer.setData("text", ev.target.id);
}

function drop(ev) {
    ev.preventDefault();
    const data = ev.dataTransfer.getData("text");
    const draggedElement = document.getElementById(data);
    const dropTarget = ev.target.closest('.card-slot-container');
    
    if (dropTarget && draggedElement) {
        dropTarget.appendChild(draggedElement);
        validateDeck(); // Automatyczna walidacja po każdym ruchu
    }
}

function validateDeck() {
    const activeContainer = document.getElementById('active-deck');
    if (!activeContainer) return;

    const activeCount = activeContainer.querySelectorAll('.cyber-card').length;
    const saveBtn = document.getElementById('save-deck-btn');
    const warningEl = document.getElementById('deck-warning');

    const MIN = 20, MAX = 50;

    if (activeCount < MIN) {
        warningEl.innerText = `ERROR: BUFFER UNDERFLOW. (${activeCount}/${MIN})`;
        saveBtn.disabled = true;
        saveBtn.style.opacity = "0.5";
    } else if (activeCount > MAX) {
        warningEl.innerText = `ERROR: BUFFER OVERFLOW. (${activeCount}/${MAX})`;
        saveBtn.disabled = true;
        saveBtn.style.opacity = "0.5";
    } else {
        warningEl.innerText = `SYSTEM STABLE: ${activeCount} CARDS READY.`;
        saveBtn.disabled = false;
        saveBtn.style.opacity = "1";
    }
}

function saveDeckState() {
    const activeContainer = document.getElementById('active-deck');
    const saveBtn = document.getElementById('save-deck-btn');

    const activeIds = Array.from(activeContainer.querySelectorAll('.cyber-card'))
        .map(card => card.dataset.instanceId);

    saveBtn.innerText = "EXECUTING RPC SYNC...";
    saveBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'save_cyber_deck_rpc');
    formData.append('active_ids', JSON.stringify(activeIds));
    formData.append('nonce', config_nonces.deck_sync);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert("TERMINAL SYNCED. Buffer updated.");
        } else {
            alert("SYNC FAILED: " + data.data);
        }
    })
    .catch(() => alert("CRITICAL: Link Interrupted."))
    .finally(() => {
        saveBtn.innerText = "SYNC WITH TERMINAL";
        saveBtn.disabled = false;
    });
}

// --- 4. LOGIKA FOUNDRY (ULEPSZANIE) ---

function upgradeCard(instanceId) {
    const btn = event.target;
    const originalText = btn.innerText;

    if(!confirm("Initialize Nano-Fusion? This consumes duplicates and credits.")) return;

    btn.disabled = true;
    btn.innerText = "FUSING...";

    const formData = new URLSearchParams();
    formData.append('action', 'foundry_upgrade');
    formData.append('instance_id', instanceId);
    formData.append('nonce', config_nonces.foundry);

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type: application/x-www-form-urlencoded' },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            btn.style.background = "#fff";
            btn.style.color = "#000";
            btn.innerText = "UPGRADED";
            setTimeout(() => location.reload(), 1000); 
        } else {
            alert("FUSION FAILED: " + data.data);
            btn.disabled = false;
            btn.innerText = originalText;
        }
    })
    .catch(() => {
        alert("CRITICAL: Foundry Power Loss.");
        btn.disabled = false;
        btn.innerText = originalText;
    });
}

// --- 5. START SYSTEMU ---
document.addEventListener('DOMContentLoaded', () => {
    validateDeck();
});
