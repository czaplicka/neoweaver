/**
 * Deck Panel — tab switching, toggle and keyboard interactions.
 *
 * Safe for repeated window.twInitDeckPanel() calls after dynamic DOM updates.
 * Uses event delegation to avoid duplicate listeners on re-init.
 *
 * Runtime config:
 *   window.twDeckPanelConfig.soundBase — trailing-slash URL to uploads/sounds/
 *
 * @package Neoweaver
 */
(function () {
    'use strict';

    var config = window.twDeckPanelConfig || {};
    var soundBase = config.soundBase || '';

    var SOUND_URLS = {
        tab: soundBase ? soundBase + 'ui-click.mp3' : null,
        glitch: soundBase ? soundBase + 'glitch-static.mp3' : null
    };

    var sounds = {};
    var listenersBound = false;
    var skillsLoaded = false;

    function playSound(name, volume) {
        var url = SOUND_URLS[name];
        var playPromise;
        var benign;

        if (!url) return;

        if (!sounds[name]) {
            sounds[name] = new Audio(url);
        }

        sounds[name].volume = (typeof volume === 'number') ? volume : 0.2;
        sounds[name].currentTime = 0;

        playPromise = sounds[name].play();

        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function (err) {
                benign = ['NotAllowedError', 'AbortError'];
                if (!err || benign.indexOf(err.name) === -1) {
                    console.warn('[DeckPanel] Audio error (' + name + '):', err);
                }
            });
        }
    }

    function getDeckPanel() {
        return document.getElementById('deck-panel');
    }

    function getPanelTabs(deckPanel) {
        return deckPanel ? deckPanel.querySelectorAll('.panel-tab[data-tab]') : [];
    }

    function getTabContents(deckPanel) {
        return deckPanel ? deckPanel.querySelectorAll('.deck-tab-content') : [];
    }

    function openPanel(deckPanel) {
        if (!deckPanel) return;

        deckPanel.classList.add('is-open');
        deckPanel.classList.remove('is-collapsed');
        playSound('glitch');
    }

    function closePanel(deckPanel) {
        if (!deckPanel) return;

        deckPanel.classList.add('is-collapsed');
        deckPanel.classList.remove('is-open');
        playSound('glitch');
    }

    function togglePanel(deckPanel) {
        if (!deckPanel) return;

        if (deckPanel.classList.contains('is-open')) {
            closePanel(deckPanel);
        } else {
            openPanel(deckPanel);
        }
    }

    function switchTab(deckPanel, targetId) {
        var panelTabs;
        var tabContents;
        var targetContent;
        var i;
        var isTarget;

        if (!deckPanel || !targetId) return;

        targetContent = document.getElementById(targetId);
        if (!targetContent) return;

        if (targetContent.classList.contains('is-active') &&
                !deckPanel.classList.contains('is-collapsed')) {
            return;
        }

        playSound('tab');

        if (deckPanel.classList.contains('is-collapsed')) {
            openPanel(deckPanel);
        }

        panelTabs = getPanelTabs(deckPanel);
        tabContents = getTabContents(deckPanel);

        for (i = 0; i < panelTabs.length; i++) {
            isTarget = panelTabs[i].getAttribute('data-tab') === targetId;
            panelTabs[i].classList.toggle('is-active', isTarget);
        }

        for (i = 0; i < tabContents.length; i++) {
            tabContents[i].classList.toggle('is-active', tabContents[i].id === targetId);
        }

        if (targetId === 'tab-skills' &&
                !skillsLoaded &&
                typeof window.twLoadSkillsAndAbilities === 'function') {
            window.twLoadSkillsAndAbilities();
            skillsLoaded = true;
        }
    }

    function handleClick(e) {
        var deckPanel = getDeckPanel();
        var toggleBtn;
        var tabBtn;
        var wrapper;

        if (!deckPanel) return;
        if (!deckPanel.contains(e.target)) return;

        toggleBtn = e.target.closest('#toggle-deck');
        if (toggleBtn) {
            e.stopPropagation();
            e.preventDefault();
            togglePanel(deckPanel);
            return;
        }

        tabBtn = e.target.closest('.panel-tab[data-tab]');
        if (tabBtn && deckPanel.contains(tabBtn)) {
            e.stopPropagation();
            e.preventDefault();
            switchTab(deckPanel, tabBtn.getAttribute('data-tab'));
            return;
        }

        wrapper = e.target.closest('.deck-tabs-wrapper');
        if (wrapper &&
                e.target === wrapper &&
                deckPanel.classList.contains('is-open')) {
            closePanel(deckPanel);
        }
    }

    function handleEscape(e) {
        var deckPanel;

        if (e.key !== 'Escape') return;

        deckPanel = getDeckPanel();
        if (!deckPanel) return;

        if (deckPanel.classList.contains('is-open')) {
            closePanel(deckPanel);
        }
    }

    function initDeckPanel() {
        var deckPanel = getDeckPanel();
        var tabContents;
        var i;
        var hasActive = false;

        if (!deckPanel) return;

        tabContents = getTabContents(deckPanel);

        for (i = 0; i < tabContents.length; i++) {
            if (tabContents[i].classList.contains('is-active')) {
                hasActive = true;
                break;
            }
        }

        if (!hasActive && tabContents.length) {
            tabContents[0].classList.add('is-active');
            syncActiveTabFromContent(deckPanel);
        }

        if (!listenersBound) {
            document.addEventListener('click', handleClick);
            document.addEventListener('keydown', handleEscape);
            listenersBound = true;
        }
    }

    function syncActiveTabFromContent(deckPanel) {
        var panelTabs;
        var tabContents;
        var activeId = null;
        var i;

        if (!deckPanel) return;

        panelTabs = getPanelTabs(deckPanel);
        tabContents = getTabContents(deckPanel);

        for (i = 0; i < tabContents.length; i++) {
            if (tabContents[i].classList.contains('is-active')) {
                activeId = tabContents[i].id;
                break;
            }
        }

        for (i = 0; i < panelTabs.length; i++) {
            panelTabs[i].classList.toggle(
                'is-active',
                panelTabs[i].getAttribute('data-tab') === activeId
            );
        }
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initDeckPanel();
    } else {
        document.addEventListener('DOMContentLoaded', initDeckPanel);
    }

    window.twInitDeckPanel = initDeckPanel;
}());
