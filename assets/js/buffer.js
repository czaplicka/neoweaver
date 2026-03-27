/**
 * NEOWEAVE CORE INTERFACE - JS BUNDLE
 */

// --- 1. INICJALIZACJA SLIDERA ---
// Dodano sprawdzenie, czy kontener istnieje, by nie pluć błędami na stronach bez slidera
const bufferSliderEl = document.querySelector('.buffer-slider');
let bufferSwiper;

if (bufferSliderEl) {
    bufferSwiper = new Swiper('.buffer-slider', {
        slidesPerView: 'auto',
        centeredSlides: true,
        spaceBetween: 20,
        grabCursor: true,
        pagination: { el: '.swiper-pagination', clickable: true }
    });
}

// --- 2. LOGIKA HAND (UŻYWANIE KART I ZOOM) ---

function zoomCard(el) {
    const overlay = document.getElementById('card-zoom-overlay');
    const content = document.getElementById('zoom-content');
    if (!overlay || !content) return;
    
    content.innerHTML = el.outerHTML;
    overlay.style.display = 'flex';
    
    const zoomBtn = content.querySelector('.inject-btn');
    if (zoomBtn) zoomBtn.style.display = 'none';
}

function closeZoom() {
    const overlay = document.getElementById('card-zoom-overlay');
    if (overlay) overlay.style.display = 'none';
}

function useBufferCard(instanceId, name, event) {
    if (event) event.stopPropagation();
    if(!confirm("Execute protocol: " + name + "?")) return;

    const cardElement = document.querySelector(`[data-instance-id="${instanceId}"]`);
    const slideElement = cardElement ? cardElement.closest('.swiper-slide') : null;

    if (cardElement) {
        cardElement.style.transition = "all 0.4s ease";
        cardElement.style.filter = "brightness(3) blur(10px)";
        cardElement.style.transform = "translateY(-100px) scale(0.5)";
        cardElement.style.opacity = "0";
    }

    // POPRAWKA: Używamy nwApiData zamiast ajaxurl i config_nonces
    fetch(nwApiData.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'use_buffer_card',
            instance_id: instanceId,
            nonce: nwApiData.nonces.use_card 
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            const newCard = data.data;
            
            // Tutaj HTML powinien być identyczny z tym w PHP (klasa cyber-card-css)
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

            if (slideElement && bufferSwiper) {
                setTimeout(() => {
                    slideElement.innerHTML = newCardHTML;
                    bufferSwiper.update();
                    updateHUD(data.data.pile_count, data.data.discard_count);
                }, 400);
            } else {
                location.reload(); 
            }
        } else {
            alert("RPC ERROR: " + data.data);
            if (cardElement) {
                cardElement.style.opacity = "1";
                cardElement.style.transform = "none";
                cardElement.style.filter = "none";
            }
        }
    })
    .catch(err => console.error("Buffer Uplink Lost", err));
}

function updateHUD(pile, discard) {
    const pEl = document.getElementById('count-pile');
    const dEl = document.getElementById('count-discard');
    
    // Dodano rygorystyczne sprawdzanie wartości (0 jest traktowane jako false w JS)
    if(pEl && (pile !== undefined && pile !== null)) {
        pEl.innerText = pile;
        pEl.classList.add('value-update');
        setTimeout(() => pEl.classList.remove('value-update'), 400);
    }
    if(dEl && (discard !== undefined && discard !== null)) {
        dEl.innerText = discard;
        dEl.classList.add('value-update');
        setTimeout(() => dEl.classList.remove('value-update'), 400);
    }
}

// --- 3. LOGIKA DECK BUILDER ---

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
        validateDeck();
    }
}

function validateDeck() {
    const activeContainer = document.getElementById('active-deck');
    const saveBtn = document.getElementById('save-deck-btn');
    const warningEl = document.getElementById('deck-warning');
    if (!activeContainer || !saveBtn) return;

    const activeCount = activeContainer.querySelectorAll('.cyber-card').length;
    const MIN = 20, MAX = 50;

    if (activeCount < MIN) {
        if(warningEl) warningEl.innerText = `ERROR: BUFFER UNDERFLOW. (${activeCount}/${MIN})`;
        saveBtn.disabled = true;
        saveBtn.style.opacity = "0.5";
    } else if (activeCount > MAX) {
        if(warningEl) warningEl.innerText = `ERROR: BUFFER OVERFLOW. (${activeCount}/${MAX})`;
        saveBtn.disabled = true;
        saveBtn.style.opacity = "0.5";
    } else {
        if(warningEl) warningEl.innerText = `SYSTEM STABLE: ${activeCount} CARDS READY.`;
        saveBtn.disabled = false;
        saveBtn.style.opacity = "1";
    }
}

function saveDeckState() {
    const activeContainer = document.getElementById('active-deck');
    const saveBtn = document.getElementById('save-deck-btn');
    if (!activeContainer || !saveBtn) return;

    const activeIds = Array.from(activeContainer.querySelectorAll('.cyber-card'))
        .map(card => card.dataset.instanceId);

    saveBtn.innerText = "EXECUTING RPC SYNC...";
    saveBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'save_cyber_deck_rpc');
    formData.append('active_ids', JSON.stringify(activeIds));
    formData.append('nonce', nwApiData.nonces.deck_sync); // POPRAWKA: nwApiData

    fetch(nwApiData.ajaxurl, { // POPRAWKA: nwApiData
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
    // POPRAWKA: Przekazujemy 'event' bezpośrednio do funkcji, by uniknąć problemów w Firefox/Safari
    const btn = window.event ? window.event.target : null; 
    const originalText = btn ? btn.innerText : "UPGRADE";

    if(!confirm("Initialize Nano-Fusion? This consumes duplicates and credits.")) return;

    if (btn) {
        btn.disabled = true;
        btn.innerText = "FUSING...";
    }

    fetch(nwApiData.ajaxurl, { // POPRAWKA: nwApiData
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'foundry_upgrade',
            instance_id: instanceId,
            nonce: nwApiData.nonces.foundry // POPRAWKA: nwApiData
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            if (btn) {
                btn.style.background = "#fff";
                btn.style.color = "#000";
                btn.innerText = "UPGRADED";
            }
            setTimeout(() => location.reload(), 1000); 
        } else {
            alert("FUSION FAILED: " + data.data);
            if (btn) {
                btn.disabled = false;
                btn.innerText = originalText;
            }
        }
    })
    .catch(() => {
        alert("CRITICAL: Foundry Power Loss.");
        if (btn) {
            btn.disabled = false;
            btn.innerText = originalText;
        }
    });
}

// --- 5. START SYSTEMU ---
document.addEventListener('DOMContentLoaded', () => {
    // Odpalamy tylko jeśli jesteśmy na stronie z Deck Builderem
    if (document.getElementById('active-deck')) {
        validateDeck();
    }
});
