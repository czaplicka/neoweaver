/**
 * NEOWEAVE SIGNAL INTERFERENCE PROTOCOL
 * Aktywuje się przy base_tech <= 2
 */
const NeoweaveInterference = {
    settings: {
        chars: "01@#$%&§ΔЖΣΩ",
        chance: 0.05, // Szansa na glitch pojedynczej litery
        interval: 150 // Co ile ms następuje odświeżenie efektu
    },

    init: function(techLevel) {
        if (techLevel > 2) return; // Sygnał zbyt stabilny na glitche

        // Zwiększ intensywność przy skrajnie niskim techu
        if (techLevel === 1) {
            this.settings.chance = 0.15;
            this.settings.interval = 80;
        }

        setInterval(() => this.applyGlitch(), this.settings.interval);
    },

    applyGlitch: function() {
        const messages = document.querySelectorAll('.gm-message p');
        
        messages.forEach(msg => {
            if (!msg.dataset.originalText) {
                msg.dataset.originalText = msg.innerText;
            }

            const original = msg.dataset.originalText;
            let glitched = "";

            for (let i = 0; i < original.length; i++) {
                if (Math.random() < this.settings.chance) {
                    glitched += this.settings.chars.charAt(Math.floor(Math.random() * this.settings.chars.length));
                } else {
                    glitched += original[i];
                }
            }

            msg.innerText = glitched;
        });
    }
};

// Inicjalizacja (Wartość przekazana z PHP/Supabase)
// NeoweaveInterference.init(<?php echo $world_tech_level; ?>);
