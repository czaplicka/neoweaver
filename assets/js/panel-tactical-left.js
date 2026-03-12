document.addEventListener('DOMContentLoaded', function () {
    const leftNavButtons   = document.querySelectorAll('#twLeftNavTactical .tw-nav-btn-tactical');
    const leftPanel        = document.getElementById('tacticalPanelLeft');
    const leftTabs         = document.querySelectorAll('#tacticalPanelLeft .tw-tab-content-tactical');
    const leftNavContainer = document.getElementById('twLeftNavTactical');
    const bridge           = document.getElementById('tactical-status-bridge');

    function refreshMapLayout() {
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 100);
    }

    leftNavButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const tabId    = this.getAttribute('data-tab');
            const targetTab = document.getElementById(tabId);

            if (leftPanel.classList.contains('is-visible') && targetTab.classList.contains('active')) {
                // Zamykamy panel
                leftPanel.classList.remove('is-visible');
                leftNavContainer.classList.remove('panel-open');
                this.classList.remove('active');
            } else {
                // Przełączanie / otwieranie

                // 1. Ukryj wszystkie taby
                leftTabs.forEach(t => {
                    t.classList.remove('active');
                    t.style.display = ''; // wraca do CSS (domyślnie display:none)
                });

                leftNavButtons.forEach(b => b.classList.remove('active'));

                // 2. Pokaż wybrany tab
                targetTab.classList.add('active');

                if (tabId === 'left-map-tab') {
                    targetTab.style.display = 'flex';
                } else {
                    targetTab.style.display = 'block';
                }

                this.classList.add('active');
                leftPanel.classList.add('is-visible');
                leftNavContainer.classList.add('panel-open');

                if (tabId === 'left-map-tab') {
                    refreshMapLayout();
                }
            }
        });
    });

    // Auto-open combat tab, jeżeli jest aktywny konflikt
    if (bridge && bridge.dataset.combatActive === 'true' && window.twTacticalData?.has_enemy) {
        const battleTabBtn = document.querySelector('[data-tab="left-battle-tab"]');
        if (battleTabBtn && !leftPanel.classList.contains('is-visible')) {
            setTimeout(() => {
                battleTabBtn.click();
            }, 500);
        }
    }
});
