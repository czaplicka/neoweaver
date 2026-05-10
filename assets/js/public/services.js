// 1. GLOBALNY KONTEKST - Sercem systemu, tu trzymamy dane o obecnej sesji interakcji
let currentContext = { 
    npc: null, 
    kingdom: null, 
    services: [] 
};

// 2. INICJALIZACJA - Wywoływana gdy gracz klika w ikonę dymka lub NPC
function setupNPCServices(npcData, kingdomData, services) {
    const trigger = document.getElementById('neo-service-trigger');
    
    // Zapisujemy dane do kontekstu, by były dostępne w każdym pod-module
    currentContext.npc = npcData;
    currentContext.kingdom = kingdomData;
    currentContext.services = services;

    if (services && services.length > 0) {
        renderMainMenu(document.getElementById('neo-services-list'));
        trigger.style.display = 'flex'; 
    } else {
        trigger.style.display = 'none';
    }
}

// 3. MENU GŁÓWNE
function renderMainMenu(container) {
    container.innerHTML = '';
    
    // Ustaw nagłówek (jeśli masz te elementy w HTML)
    const nameEl = document.getElementById('neo-npc-name');
    if(nameEl) nameEl.innerText = currentContext.npc.name;

    currentContext.services.forEach(service => {
        // Opcjonalne filtrowanie po lokacji
        if (service.required_location_type && service.required_location_type !== 'any') {
            if (service.required_location_type !== currentContext.npc.current_location_type) return;
        }

        const btn = document.createElement('button');
        btn.className = 'service-btn';
        btn.innerHTML = `> [${service.name.toUpperCase()}]`;
        btn.onclick = () => handleServiceClick(service.slug);
        container.appendChild(btn);
    });
}

// 4. GŁÓWNY ROUTER USŁUG
function handleServiceClick(slug) {
    const body = document.getElementById('neo-services-list');
    body.style.opacity = '0';
    
    setTimeout(() => {
        body.innerHTML = '';
        switch(slug) {
            case 'quests':       renderQuestsModule(body); break;
            case 'engrave':      renderEngraveModule(body); break;
            case 'disassemble':  renderDisassembleModule(body); break;
            case 'gambling':     renderGamblingModule(body); break;
            case 'learn_magic':  renderLearnSpellsModule(body); break;
            case 'credit':       renderCreditModule(body); break;
            default:
                body.innerHTML = `<div class="glitch-text">ERROR: MODULE_${slug.toUpperCase()}_OFFLINE</div>
                                  <button class="service-btn btn-back" onclick="refreshServices()">BACK</button>`;
        }
        body.style.opacity = '1';
    }, 150);
}

// 5. FUNKCJA WSTECZ (BACK)
function refreshServices() {
    const body = document.getElementById('neo-services-list');
    body.style.opacity = '0';
    setTimeout(() => {
        renderMainMenu(body);
        body.style.opacity = '1';
    }, 150);
}

// --- PRZYKŁADOWY MODUŁ (CREDIT) Z POPRAWNYM DOSTĘPEM DO DANYCH ---
function renderCreditModule(container) {
    const k = currentContext.kingdom;
    const maxLoan = k.wealth * 1000;
    
    container.innerHTML = `
        <div class="service-module">
            <h3 class="glitch-text">LOAN_AUTHORIZATION</h3>
            <p>Available: ${maxLoan} Cr</p>
            <p>Interest: ${10 - k.wealth}%</p>
            <input type="range" min="100" max="${maxLoan}" step="100" id="loan-amount" style="width:100%">
            <button class="service-btn" onclick="takeLoan()">INITIALIZE_TRANSFER</button>
            <button class="service-btn btn-back" onclick="refreshServices()">BACK</button>
        </div>
    `;
}

// Funkcje pomocnicze modala
function toggleNeoModal() {
    const modal = document.getElementById('neo-service-modal');
    if(!modal) return;
    modal.style.display = (modal.style.display === 'flex') ? 'none' : 'flex';
}

function closeServiceModal() {
    document.getElementById('neo-service-modal').style.display = 'none';
}
