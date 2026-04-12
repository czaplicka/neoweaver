const { registerCheckoutBlock, extensionCartUpdate } = window.wc.blocksCheckout;
const { useState, useEffect }                        = window.wp.element;
const { SelectControl, Notice }                      = window.wp.components;

const { characters, hasNeoweaver, createUrl } = window.neoweaverCheckout || {};

const NeoWeaverAgentSelect = () => {
    const [ characterId, setCharacterId ] = useState( '' );

    // Nie pokazuj jeśli koszyk nie ma itemu NeoWeaver
    if ( ! hasNeoweaver ) return null;

    // Brak postaci — pokaż komunikat
    if ( ! characters || characters.length === 0 ) {
        return (
            <div className="neoweaver-no-agent" style={{ margin: '16px 0' }}>
                <Notice status="warning" isDismissible={ false }>
                    ⚠️ You have no active Field Agents. 
                    <a href={ createUrl } style={{ marginLeft: '6px' }}>
                        Create a character
                    </a>
                    { ' ' }before purchasing this item.
                </Notice>
            </div>
        );
    }

    const options = [
        { value: '', label: '— Select your Field Agent —' },
        ...characters.map( char => ( { value: char.id, label: char.name } ) ),
    ];

    const handleChange = ( value ) => {
        setCharacterId( value );
        // Wyślij do session przez Store API
        extensionCartUpdate( {
            namespace: 'neoweaver-character-select',
            data: { character_id: value },
        } );
    };

    return (
        <div className="neoweaver-agent-select" style={{ margin: '16px 0' }}>
            <SelectControl
                label="⚔️ Which Field Agent receives this item?"
                value={ characterId }
                options={ options }
                onChange={ handleChange }
            />
        </div>
    );
};

registerCheckoutBlock( {
    metadata: {
        name:       'neoweaver/character-select',
        parent:     [ 'woocommerce/checkout-order-information-block' ],
        attributes: {},
    },
    component: NeoWeaverAgentSelect,
} );
