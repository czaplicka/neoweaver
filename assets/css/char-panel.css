/* =============================================================================
   TW CHARACTER PANEL – tw-char-panel.css
   All rules scoped under #charPanel to prevent collision with any other
   component that uses generic class names like .tw-progress-fill.
   ============================================================================= */

/* ---------------------------------------------------------------------------
   1. SIDE NAV
   --------------------------------------------------------------------------- */
#twSideNav.tw-side-nav {
    position: fixed;
    right: 0;
    bottom: 80px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    z-index: 1100;
    padding: 6px 4px;
    background: rgba(3, 7, 18, 0.85);
    border: 1px solid rgba(173, 255, 0, 0.25);
    border-right: none;
    border-radius: 8px 0 0 8px;
    backdrop-filter: blur(8px);
}

#twSideNav .tw-nav-btn {
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 18px;
    line-height: 1;
    transition: all 0.2s;
}
#twSideNav .tw-nav-btn:hover,
#twSideNav .tw-nav-btn.active {
    background: rgba(173, 255, 0, 0.12);
    color: #adff00;
    box-shadow: 0 0 8px rgba(173, 255, 0, 0.3);
}

/* ---------------------------------------------------------------------------
   2. PANEL CONTAINER
   --------------------------------------------------------------------------- */
#charPanel.tw-character-panel-container {
    position: fixed;
    right: 44px;          /* sits left of the side nav */
    bottom: 20px;
    width: 340px;
    max-height: calc(100vh - 100px);
    display: none;        /* hidden until .is-visible */
    flex-direction: column;
    z-index: 1099;
    border-radius: 14px;
    border: 1px solid rgba(173, 255, 0, 0.35);
    background: rgba(3, 7, 18, 0.94);
    backdrop-filter: blur(16px);
    box-shadow:
        0 0 30px rgba(0, 0, 0, 0.7),
        0 0 20px rgba(173, 255, 0, 0.08);
    font-family: 'Chakra Petch', sans-serif;
    color: #f0f0f0;
    overflow: hidden;
}

#charPanel.tw-character-panel-container.is-visible {
    display: flex;
}

/* ---------------------------------------------------------------------------
   3. CHARACTER CARD INNER
   --------------------------------------------------------------------------- */
#charPanel .tw-character-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

/* Header: avatar + name/class */
#charPanel .tw-char-header {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 14px 14px 10px;
    border-bottom: 1px solid rgba(173, 255, 0, 0.15);
    flex-shrink: 0;
}

#charPanel .tw-char-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    flex-shrink: 0;
    background-size: cover;
    background-position: center;
    border: 2px solid #adff00;
    box-shadow: 0 0 12px rgba(173, 255, 0, 0.4);
    background-color: #0a0f0a;
}

#charPanel .tw-char-info {
    flex: 1;
    min-width: 0;
}

#charPanel .tw-lvl-frame {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #adff00;
    background: rgba(173, 255, 0, 0.1);
    border: 1px solid rgba(173, 255, 0, 0.3);
    padding: 2px 8px;
    border-radius: 3px;
    margin-bottom: 4px;
}

#charPanel .tw-char-name {
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

#charPanel .tw-char-class-line {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#charPanel .tw-char-class-line .highlight {
    color: #adff00;
}

#charPanel .tw-char-gold-line {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 3px;
}

#charPanel .tw-gold-label {
    color: rgba(173, 255, 0, 0.6);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-right: 4px;
}

/* ---------------------------------------------------------------------------
   4. SCROLL AREA + TABS
   --------------------------------------------------------------------------- */
#charPanel .tw-panel-scroll-area {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(173, 255, 0, 0.3) transparent;
}
#charPanel .tw-panel-scroll-area::-webkit-scrollbar { width: 4px; }
#charPanel .tw-panel-scroll-area::-webkit-scrollbar-thumb {
    background: rgba(173, 255, 0, 0.3);
    border-radius: 4px;
}

#charPanel .tw-tab-content {
    display: none;
    padding: 12px 14px 16px;
}
#charPanel .tw-tab-content.active {
    display: block;
}

/* ---------------------------------------------------------------------------
   5. STAT BARS BLOCK
   --------------------------------------------------------------------------- */
#charPanel .tw-bars-block {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 12px;
}

#charPanel .tw-stat-bar-container {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

#charPanel .tw-stat-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #94a3b8;
}
#charPanel .tw-stat-label.main-label {
    font-size: 0.72rem;
    color: #cbd5e1;
}
#charPanel .tw-stat-label.small-label {
    font-size: 0.62rem;
    color: #64748b;
}

/* Progress bar track */
#charPanel .tw-progress-bg {
    width: 100%;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
    /* Height defined per size variant below — never inherits a screen-filling default */
}

#charPanel .tw-progress-bg.big-bar {
    height: 10px;
}

#charPanel .tw-progress-bg.small-bar {
    height: 6px;
}

#charPanel .tw-progress-bg.bordered {
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* The fill — constrained to 100% of its parent track, never the viewport */
#charPanel .tw-progress-fill {
    height: 100%;
    width: 0;                    /* start at 0; inline style sets actual value */
    max-width: 100%;             /* hard cap: can never escape the track */
    border-radius: 4px;
    transition: width 0.6s ease;
    display: block;              /* block, not a screen-filler */
    position: relative;
}

/* HP colours */
#charPanel .tw-progress-fill.hp-green  { background: linear-gradient(90deg, #22c55e, #16a34a); box-shadow: 0 0 8px rgba(34,197,94,0.5); }
#charPanel .tw-progress-fill.hp-yellow { background: linear-gradient(90deg, #eab308, #ca8a04); box-shadow: 0 0 8px rgba(234,179,8,0.5); }
#charPanel .tw-progress-fill.hp-red    { background: linear-gradient(90deg, #ef4444, #b91c1c); box-shadow: 0 0 8px rgba(239,68,68,0.6); }

/* MP */
#charPanel .tw-progress-fill.mp-blue   { background: linear-gradient(90deg, #3b82f6, #1d4ed8); box-shadow: 0 0 8px rgba(59,130,246,0.5); }

/* Sync-rate */
#charPanel .tw-progress-fill.sync-stable   { background: linear-gradient(90deg, #adff00, #7ec800); box-shadow: 0 0 8px rgba(173,255,0,0.5); }
#charPanel .tw-progress-fill.sync-warning  { background: linear-gradient(90deg, #f59e0b, #d97706); box-shadow: 0 0 8px rgba(245,158,11,0.5); }
#charPanel .tw-progress-fill.sync-critical { background: linear-gradient(90deg, #ef4444, #991b1b); box-shadow: 0 0 8px rgba(239,68,68,0.7); animation: tw-sync-pulse 1s infinite; }

@keyframes tw-sync-pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.5; }
}

/* Survival bars */
#charPanel .tw-progress-fill.satiety-orange  { background: linear-gradient(90deg, #f97316, #ea580c); }
#charPanel .tw-progress-fill.hydration-cyan  { background: linear-gradient(90deg, #06b6d4, #0891b2); }
#charPanel .tw-progress-fill.rest-purple     { background: linear-gradient(90deg, #a855f7, #7c3aed); }

/* Glitch border on sync track */
#charPanel .tw-progress-bg.glitch-border {
    border-color: rgba(173, 255, 0, 0.3);
}

/* Survival bars row */
#charPanel .tw-survival-bars {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 4px;
}

/* ---------------------------------------------------------------------------
   6. ACCORDION (attributes / skills / bio)
   --------------------------------------------------------------------------- */
#charPanel .tw-accordion-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

#charPanel .tw-accordion-group details {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(173, 255, 0, 0.12);
    border-radius: 6px;
    overflow: hidden;
}

#charPanel .tw-accordion-group details > div,
#charPanel .tw-accordion-group details > p {
    padding: 10px 12px 12px;
}

#charPanel .tw-accordion-group summary {
    padding: 8px 12px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #adff00;
    cursor: pointer;
    list-style: none;
    user-select: none;
}
#charPanel .tw-accordion-group summary::-webkit-details-marker { display: none; }
#charPanel .tw-accordion-group summary::before {
    content: '▶ ';
    font-size: 0.6rem;
}
#charPanel .tw-accordion-group details[open] summary::before {
    content: '▼ ';
}

/* Attribute grid */
#charPanel .tw-attr-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

#charPanel .tw-attr-box {
    background: rgba(173, 255, 0, 0.06);
    border: 1px solid rgba(173, 255, 0, 0.18);
    border-radius: 6px;
    padding: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

#charPanel .tw-at-l {
    font-size: 0.6rem;
    letter-spacing: 1px;
    color: #94a3b8;
    text-transform: uppercase;
}

#charPanel .tw-at-v {
    font-size: 1.2rem;
    font-weight: 700;
    color: #adff00;
    line-height: 1;
}

/* Skills list */
#charPanel .tw-skills-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

#charPanel .tw-skill-chip {
    position: relative;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 6px 10px;
    text-align: left;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-family: 'Chakra Petch', sans-serif;
    font-size: 0.72rem;
    color: #e2e8f0;
    transition: all 0.2s;
}
#charPanel .tw-skill-chip:hover {
    border-color: rgba(173, 255, 0, 0.4);
    background: rgba(173, 255, 0, 0.06);
}

#charPanel .tw-skill-chip-name { font-weight: 600; }
#charPanel .tw-skill-chip-cost {
    font-size: 0.6rem;
    color: #adff00;
    background: rgba(173, 255, 0, 0.12);
    border: 1px solid rgba(173, 255, 0, 0.25);
    border-radius: 3px;
    padding: 1px 5px;
    margin-left: 6px;
    flex-shrink: 0;
}

/* Skill tooltip */
#charPanel .tw-skill-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 6px);
    left: 0;
    width: 220px;
    background: rgba(3, 7, 18, 0.97);
    border: 1px solid rgba(173, 255, 0, 0.4);
    border-radius: 8px;
    padding: 10px 12px;
    z-index: 2000;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
}
#charPanel .tw-skill-chip:hover .tw-skill-tooltip {
    display: block;
}
#charPanel .tw-skill-tooltip-header {
    font-size: 0.72rem;
    font-weight: 700;
    color: #adff00;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 6px;
}
#charPanel .tw-skill-tooltip-body {
    font-size: 0.68rem;
    color: #94a3b8;
    line-height: 1.5;
}

/* Bio text */
#charPanel .tw-bio-text {
    font-size: 0.72rem;
    line-height: 1.6;
    color: #94a3b8;
    padding: 4px 0;
}

/* ---------------------------------------------------------------------------
   7. INVENTORY / PAPERDOLL
   --------------------------------------------------------------------------- */
#charPanel .equipment-container {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

#charPanel .paperdoll-wrapper {
    position: relative;
    width: 100%;
    aspect-ratio: 1 / 1.4;
    max-height: 340px;
    margin: 0 auto;
}

#charPanel .char-bg {
    width: 100%;
    height: 100%;
    object-fit: contain;
    opacity: 0.35;
    display: block;
}

#charPanel .inv-slot {
    position: absolute;
    width: 44px;
    height: 44px;
    border: 1px solid rgba(173, 255, 0, 0.4);
    border-radius: 6px;
    background: rgba(3, 7, 18, 0.7);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}
#charPanel .inv-slot.tiny {
    width: 32px;
    height: 32px;
}
#charPanel .inv-slot:hover {
    border-color: #adff00;
    box-shadow: 0 0 8px rgba(173, 255, 0, 0.4);
}

#charPanel .slot-label {
    font-size: 0.45rem;
    letter-spacing: 0.5px;
    color: rgba(173, 255, 0, 0.6);
    text-transform: uppercase;
    line-height: 1;
    margin-bottom: 2px;
}

#charPanel .item-icon {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

#charPanel .item-img-fluid {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
    border-radius: 4px;
}

#charPanel .corner-stat {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    font-size: 0.58rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    top: 0;
}
#charPanel .corner-stat.stat-left  { left: 0; }
#charPanel .corner-stat.stat-right { right: 0; }
#charPanel .corner-stat .stat-value {
    font-size: 0.75rem;
    color: #adff00;
    font-weight: 700;
}

#charPanel .belt-section {
    position: absolute;
    bottom: 18%;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 0.45rem;
    color: rgba(173, 255, 0, 0.5);
    letter-spacing: 1px;
    text-transform: uppercase;
}
#charPanel .belt-slots {
    display: flex;
    gap: 4px;
}

/* Carried items list */
#charPanel .tw-inv-title {
    font-size: 0.65rem;
    font-weight: 700;
    color: #adff00;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid rgba(173, 255, 0, 0.2);
    padding-bottom: 4px;
    margin-bottom: 6px;
}

#charPanel .tw-item-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-height: 40px;
}

#charPanel .tw-item-card {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 5px;
    padding: 5px 8px;
    cursor: grab;
    transition: border-color 0.15s;
}
#charPanel .tw-item-card:hover {
    border-color: rgba(173, 255, 0, 0.35);
}
#charPanel .tw-item-card.dragging {
    opacity: 0.5;
    cursor: grabbing;
}

#charPanel .tw-item-name {
    font-size: 0.72rem;
    color: #e2e8f0;
    display: block;
}

/* ---------------------------------------------------------------------------
   8. LOGS
   --------------------------------------------------------------------------- */
#charPanel .tw-logs-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

#charPanel .tw-log-entry {
    background: rgba(255, 255, 255, 0.03);
    border-left: 2px solid rgba(173, 255, 0, 0.3);
    padding: 6px 10px;
    border-radius: 0 4px 4px 0;
}

#charPanel .tw-log-date {
    font-size: 0.6rem;
    color: #64748b;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 3px;
}

#charPanel .tw-log-text {
    font-size: 0.7rem;
    color: #94a3b8;
    line-height: 1.5;
    margin: 0;
}

/* ---------------------------------------------------------------------------
   9. NOTES
   --------------------------------------------------------------------------- */
#charPanel .tw-notes-tab-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
    height: 100%;
}

#charPanel .tw-notes-area {
    width: 100%;
    flex: 1;
    min-height: 200px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(173, 255, 0, 0.2);
    border-radius: 6px;
    color: #e2e8f0;
    font-family: 'Chakra Petch', monospace;
    font-size: 0.72rem;
    padding: 10px;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s;
}
#charPanel .tw-notes-area:focus {
    border-color: rgba(173, 255, 0, 0.5);
}

#charPanel .tw-save-notes-btn {
    background: rgba(173, 255, 0, 0.1);
    border: 1px solid rgba(173, 255, 0, 0.4);
    color: #adff00;
    font-family: 'Chakra Petch', monospace;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 8px 0;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
}
#charPanel .tw-save-notes-btn:hover {
    background: rgba(173, 255, 0, 0.2);
    box-shadow: 0 0 10px rgba(173, 255, 0, 0.3);
}
#charPanel .tw-save-notes-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ---------------------------------------------------------------------------
   10. GLITCH EFFECTS (applied by JS based on sync_rate)
   --------------------------------------------------------------------------- */
#charPanel.glitch-active {
    animation: tw-panel-glitch 0.15s steps(1) infinite;
}

@keyframes tw-panel-glitch {
    0%   { clip-path: inset(0 0 95% 0); }
    25%  { clip-path: inset(30% 0 50% 0); filter: hue-rotate(45deg); }
    50%  { clip-path: inset(60% 0 20% 0); }
    75%  { clip-path: inset(10% 0 80% 0); filter: hue-rotate(-45deg); }
    100% { clip-path: inset(0); }
}
