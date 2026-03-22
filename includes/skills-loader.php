<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - SKILLS LOADER
 * Ładuje umiejętności i zdolności postaci bezpośrednio z Supabase (JS).
 * Hook: wp_footer, priorytet 35 (po scenarios-loader.php który ma 30).
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}
	?>
	<script>
	(function() {
	    window.twLoadSkillsAndAbilities = async function(charId) {
	        const skillsWrap    = document.querySelector('#tab-skills .deck-cards-skills');
	        const abilitiesWrap = document.querySelector('#tab-skills .deck-cards-abilities');

	        if (!skillsWrap || !abilitiesWrap) return;

	        skillsWrap.innerHTML = '<div class="tw-loader">Szukam karty postaci...</div>';
	        abilitiesWrap.innerHTML = '';

	        if (!window.twSupabase) {
	            skillsWrap.innerHTML = '<p class="tw-error">Błąd: Brak połączenia z bazą</p>';
	            return;
	        }

	        const supa = window.twSupabase;

	        if (!charId) {
	            const wpUserId = window.twAdventureData?.wp_user_id;
	            if (!wpUserId) {
	                skillsWrap.innerHTML = '<p class="tw-error">Błąd: Nie jesteś zalogowany</p>';
	                return;
	            }

	            const { data: sessionData, error: sessionError } = await supa
	                .from('cyber_game_sessions')
	                .select('character_id')
	                .eq('wp_user_id', wpUserId)
	                .eq('status', 'active')
	                .limit(1)
	                .maybeSingle();

	            if (sessionError || !sessionData) {
	                skillsWrap.innerHTML = '<p>Nie znaleziono aktywnej postaci. Rozpocznij grę.</p>';
	                return;
	            }

	            charId = sessionData.character_id;
	        }

	        skillsWrap.innerHTML = '<div class="tw-loader">Wczytywanie umiejętności...</div>';

	        try {
	            const { data: charSkills, error: skillsErr } = await supa
	                .from('cyber_character_skills')
	                .select('proficiency, skill:cyber_skills (name, description, category, tags, img_url)')
	                .eq('character_id', charId);

	            if (skillsErr) throw skillsErr;

	            // BUG-FIX: The original query selected 'type' from cyber_abilities,
	            // but the DB column is 'ability_type'. Also selected 'tags' expecting
	            // a comma-delimited string, but cyber_abilities.tags is jsonb — calling
	            // .split(',') on a JS object produces "[object Object]" tokens.
	            // Fixed: select 'ability_type' instead of 'type', and handle tags as
	            // a JSON array (Array.isArray check) rather than splitting a string.
	            const { data: charAbilities, error: abilitiesErr } = await supa
	                .from('cyber_character_abilities')
	                .select('ability:cyber_abilities (name, description, ability_type, cost, tags)')
	                .eq('character_id', charId);

	            if (abilitiesErr) throw abilitiesErr;

	            skillsWrap.innerHTML = (charSkills || []).map(row => {
	                const s = row.skill || {};

	                // cyber_skills.tags may also be jsonb — handle both array and string.
	                let tags = [];
	                if (Array.isArray(s.tags)) {
	                    tags = s.tags.map(t => String(t).trim()).filter(Boolean);
	                } else if (typeof s.tags === 'string' && s.tags) {
	                    tags = s.tags.split(',').map(t => t.trim()).filter(Boolean);
	                }

	                return `
	                <article class="deck-card skill-card">
	                    <div class="deck-card-inner">
	                        <header class="deck-card-header">
	                            <h3 class="deck-card-title">${s.name || 'Skill'}</h3>
	                            ${row.proficiency ? `<div class="deck-card-cost">${row.proficiency}</div>` : ''}
	                        </header>
	                        <div class="deck-card-subtitle">
	                            ${s.category ? `<span class="card-type">${s.category}</span>` : ''}
	                        </div>
	                        <div class="card-body">
	                            ${s.description ? `<p class="deck-card-desc">${s.description}</p>` : ''}
	                        </div>
	                        ${tags.length ? `<div class="card-tags">${tags.map(t => `<span class="tag">#${t}</span>`).join('')}</div>` : ''}
	                    </div>
	                </article>`;
	            }).join('') || '<p>Brak umiejętności.</p>';

	            abilitiesWrap.innerHTML = (charAbilities || []).map(row => {
	                const a = row.ability || {};

	                // BUG-FIX: use ability_type (correct column name) not type.
	                const abilityType = a.ability_type || '';

	                // BUG-FIX: tags is jsonb (array) in cyber_abilities, not a
	                // comma-delimited string. Handle both array and string safely.
	                let tags = [];
	                if (Array.isArray(a.tags)) {
	                    tags = a.tags.map(t => String(t).trim()).filter(Boolean);
	                } else if (typeof a.tags === 'string' && a.tags) {
	                    tags = a.tags.split(',').map(t => t.trim()).filter(Boolean);
	                }

	                return `
	                <article class="deck-card ability-card">
	                    <div class="deck-card-inner">
	                        <header class="deck-card-header">
	                            <h3 class="deck-card-title">${a.name || 'Zdolność'}</h3>
	                        </header>
	                        <div class="deck-card-subtitle">
	                            ${abilityType ? `<span class="card-type">${abilityType}</span>` : ''}
	                            ${a.cost ? `<span class="card-mechanic">${a.cost}</span>` : ''}
	                        </div>
	                        <div class="card-body">
	                            ${a.description ? `<p class="deck-card-desc">${a.description}</p>` : ''}
	                        </div>
	                        ${tags.length ? `<div class="card-tags">${tags.map(t => `<span class="tag">#${t}</span>`).join('')}</div>` : ''}
	                    </div>
	                </article>`;
	            }).join('') || '<p>Brak zdolności.</p>';

	        } catch (e) {
	            console.error('❌ Błąd Skills:', e);
	            skillsWrap.innerHTML = '<p class="tw-error">Wystąpił błąd ładowania danych.</p>';
	        }
	    };

	    if (document.readyState === 'complete') {
	        setTimeout(() => window.twLoadSkillsAndAbilities(), 1000);
	    } else {
	        window.addEventListener('load', () => setTimeout(() => window.twLoadSkillsAndAbilities(), 1000));
	    }
	})();
	</script>
	<?php
}, 35 );
