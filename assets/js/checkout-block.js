console.log('[NeoWeaver] checkout-block.js loaded', window.wc);

( function() {
    if ( ! window.wc?.blocksCheckout ) {
        console.error( '[NeoWeaver] wc.blocksCheckout not available' );
        return;
    }
    if ( ! window.wp?.plugins ) {
        console.error( '[NeoWeaver] wp.plugins not available' );
        return;
    }

    const { registerPlugin }        = window.wp.plugins;
    const { extensionCartUpdate }   = window.wc.blocksCheckout;
    const { useState }              = window.wp.element;
    const { SelectControl, Notice } = window.wp.components;
    const el                        = window.wp.element.createElement;

    const { characters, hasNeoweaver, createUrl } = window.neoweaverCheckout || {};

    const NeoWeaverAgentSelect = () => {
        const [ characterId, setCharacterId ] = useState( '' );

        if ( ! hasNeoweaver ) return null;

        if ( ! characters || characters.length === 0 ) {
            return el( 'div', { className: 'neoweaver-no-agent', style: { margin: '16px 0' } },
                el( Notice, { status: 'warning', isDismissible: false },
                    '⚠️ You have no active Field Agents. ',
                    el( 'a', { href: createUrl, style: { marginLeft: '6px' } }, 'Create a character' ),
                    ' before purchasing this item.'
                )
            );
        }

        const options = [
            { value: '', label: '— Select your Field Agent —' },
            ...( characters || [] ).map( char => ( { value: String( char.id ), label: char.name } ) ),
        ];

        return el( 'div', { className: 'neoweaver-agent-select', style: { margin: '16px 0' } },
            el( SelectControl, {
                label:    '⚔️ Which Field Agent receives this item?',
                value:    characterId,
                options:  options,
                onChange: ( value ) => {
                    setCharacterId( value );
                    extensionCartUpdate( {
                        namespace: 'neoweaver-character-select',
                        data:      { character_id: value },
                    } );
                },
            } )
        );
    };

    registerPlugin( 'neoweaver-agent-select', {
        render: NeoWeaverAgentSelect,
        scope:  'woocommerce-checkout',
    } );

} )();
