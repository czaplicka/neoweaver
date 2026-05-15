(function() {
    'use strict';

    // P8 FIX: Cast char ID to integer in JS so Supabase receives a number type,
    // not the string "123" — which causes a type mismatch on the .eq() filter.
    const charId = <?php echo intval( $char_id ); ?>;

    async function initLoom() {
        if ( ! window.twSupabase ) {
            await new Promise( resolve =>
                document.addEventListener( 'twSupabaseReady', resolve, { once: true } )
            );
        }
        const sb = window.twSupabase;

        const categories = {
            brutality: ['Attack', 'Fire', 'Melee', 'Physical', 'Lethal', 'Grit'],
            cunning:   ['Stealth', 'Reflex', 'Glitch', 'Escape', 'Thievery'],
            intellect: ['Technology', 'Hacking', 'EMP', 'Logic', 'Analysis'],
            spirit:    ['Magic', 'Chaos', 'Willpower', 'Madness', 'Void'],
            presence:  ['Persuasion', 'Diplomacy', 'Intimidation', 'Social'],
        };

        // P4 FIX: Wrap Supabase call in try/catch so a network or auth failure
        // shows a meaningful message rather than leaving the chart blank/frozen.
        try {
            const { data: deckData, error } = await sb
                .from('cyber_character_deck')
                .select('cyber_deck(tags)')
                .eq('character_id', charId);

            if ( error ) throw error;

            const stats = { brutality: 0, cunning: 0, intellect: 0, spirit: 0, presence: 0 };

            if ( Array.isArray( deckData ) ) {
                deckData.forEach( entry => {
                    const tags = ( entry.cyber_deck?.tags || '' ).toLowerCase();
                    Object.keys( categories ).forEach( cat => {
                        categories[cat].forEach( keyword => {
                            if ( tags.includes( keyword.toLowerCase() ) ) stats[cat]++;
                        } );
                    } );
                } );
            }

            renderChart( stats );
        } catch ( err ) {
            console.error( 'Loom init error:', err );
            // P4 FIX: Surface the failure to the user instead of silently failing.
            const nameEl = document.getElementById( 'archetype-name' );
            if ( nameEl ) nameEl.textContent = 'DATA UNAVAILABLE';
        }
    }

    function renderChart( stats ) {
        const canvas = document.getElementById( 'fateChart' );
        if ( ! canvas ) return;

        new Chart( canvas.getContext( '2d' ), {
            type: 'radar',
            data: {
                labels: ['BRUTALITY', 'CUNNING', 'INTELLECT', 'SPIRIT', 'PRESENCE'],
                datasets: [{
                    data: [ stats.brutality, stats.cunning, stats.intellect, stats.spirit, stats.presence ],
                    backgroundColor: 'rgba(173, 255, 0, 0.2)',
                    borderColor: '#adff00',
                    pointBackgroundColor: '#adff00',
                    borderWidth: 2,
                }],
            },
            options: {
                scales: {
                    r: {
                        min: 0,
                        suggestedMax: 5,
                        grid:        { color: 'rgba(173, 255, 0, 0.1)' },
                        angleLines:  { color: 'rgba(173, 255, 0, 0.1)' },
                        pointLabels: { color: '#adff00', font: { family: 'Chakra Petch', size: 10 } },
                        ticks:       { display: false },
                    },
                },
                plugins: { legend: { display: false } },
            },
        } );

        const sorted  = Object.entries( stats ).sort( ( a, b ) => b[1] - a[1] );
        const titles  = {
            brutality: 'THE JUGGERNAUT',
            cunning:   'THE GHOST',
            intellect: 'THE ARCHITECT',
            spirit:    'THE CONDUIT',
            presence:  'THE ICON',
        };
        const topKey  = sorted[0]?.[0];
        const topVal  = topKey ? stats[topKey] : 0;
        const nameEl  = document.getElementById( 'archetype-name' );
        if ( nameEl ) {
            // P6 FIX: textContent instead of innerText — avoids forced layout reflow.
            nameEl.textContent = topVal > 0 ? ( titles[topKey] || 'UNKNOWN PATTERN' ) : 'VOID SOUL';
        }
    }

    initLoom();
} )();

// Share button
document.addEventListener( 'click', function( e ) {
    const btn = e.target.closest( '.share-btn' );
    if ( ! btn ) return;

    const url = btn.dataset.shareUrl;
    if ( navigator.share ) {
        navigator.share( { title: 'Character Profile', url: url } );
    } else if ( navigator.clipboard ) {
        navigator.clipboard.writeText( url ).then( () => {
            // P6 FIX: textContent instead of innerText.
            btn.textContent = 'COPIED!';
            setTimeout( () => { btn.textContent = 'Share'; }, 2000 );
        } );
    }
} );
