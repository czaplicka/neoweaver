(function () {
    function clearEl(el) {
        if (el) el.replaceChildren();
    }

    function createEl(tag, className, text) {
        const el = document.createElement(tag);

        if (className) {
            el.className = className;
        }

        if (text !== undefined && text !== null) {
            el.textContent = String(text);
        }

        return el;
    }

    function normalizeTags(rawTags) {
        if (Array.isArray(rawTags)) {
            return rawTags.map(t => String(t).trim()).filter(Boolean);
        }

        if (typeof rawTags === 'string' && rawTags) {
            return rawTags.split(',').map(t => t.trim()).filter(Boolean);
        }

        return [];
    }

    function renderMessage(type, text) {
        return createEl('p', type ? `tw-${type}` : '', text);
    }

    function renderLoader(text) {
        return createEl('div', 'tw-loader', text);
    }

    function renderTags(tags) {
        if (!tags.length) return null;

        const wrap = createEl('div', 'card-tags');

        tags.forEach(tag => {
            wrap.appendChild(createEl('span', 'tag', `#${tag}`));
        });

        return wrap;
    }

    function renderSkillCard(row) {
        const s = row?.skill || {};
        const tags = normalizeTags(s.tags);

        const article = createEl('article', 'deck-card skill-card');
        const inner = createEl('div', 'deck-card-inner');
        const header = createEl('header', 'deck-card-header');
        const title = createEl('h3', 'deck-card-title', s.name || 'Skill');
        const subtitle = createEl('div', 'deck-card-subtitle');
        const body = createEl('div', 'card-body');

        header.appendChild(title);

        if (row?.proficiency) {
            header.appendChild(createEl('div', 'deck-card-cost', row.proficiency));
        }

        if (s.category) {
            subtitle.appendChild(createEl('span', 'card-type', s.category));
        }

        if (s.description) {
            body.appendChild(createEl('p', 'deck-card-desc', s.description));
        }

        inner.appendChild(header);
        inner.appendChild(subtitle);
        inner.appendChild(body);

        const tagsEl = renderTags(tags);
        if (tagsEl) {
            inner.appendChild(tagsEl);
        }

        article.appendChild(inner);
        return article;
    }

    function renderAbilityCard(row) {
        const a = row?.ability || {};
        const abilityType = a.ability_type || '';
        const tags = normalizeTags(a.tags);

        const article = createEl('article', 'deck-card ability-card');
        const inner = createEl('div', 'deck-card-inner');
        const header = createEl('header', 'deck-card-header');
        const title = createEl('h3', 'deck-card-title', a.name || 'Ability');
        const subtitle = createEl('div', 'deck-card-subtitle');
        const body = createEl('div', 'card-body');

        header.appendChild(title);

        if (abilityType) {
            subtitle.appendChild(createEl('span', 'card-type', abilityType));
        }

        if (a.cost) {
            subtitle.appendChild(createEl('span', 'card-mechanic', a.cost));
        }

        if (a.description) {
            body.appendChild(createEl('p', 'deck-card-desc', a.description));
        }

        inner.appendChild(header);
        inner.appendChild(subtitle);
        inner.appendChild(body);

        const tagsEl = renderTags(tags);
        if (tagsEl) {
            inner.appendChild(tagsEl);
        }

        article.appendChild(inner);
        return article;
    }

    function renderCards(container, rows, renderFn, emptyText) {
        clearEl(container);

        if (!Array.isArray(rows) || !rows.length) {
            container.appendChild(renderMessage('', emptyText));
            return;
        }

        const fragment = document.createDocumentFragment();

        rows.forEach(row => {
            fragment.appendChild(renderFn(row));
        });

        container.appendChild(fragment);
    }

    async function twLoadSkillsAndAbilities(charId) {
        const skillsWrap = document.querySelector('#tab-skills .deck-cards-skills');
        const abilitiesWrap = document.querySelector('#tab-skills .deck-cards-abilities');

        if (!skillsWrap || !abilitiesWrap) return;

        clearEl(skillsWrap);
        clearEl(abilitiesWrap);
        skillsWrap.appendChild(renderLoader('Searching character sheet...'));

        if (!window.twSupabase) {
            clearEl(skillsWrap);
            skillsWrap.appendChild(renderMessage('error', 'Error: No database connection.'));
            return;
        }

        const supa = window.twSupabase;

        if (!charId) {
            const wpUserId = window.twAdventureData?.wp_user_id;

            if (!wpUserId) {
                clearEl(skillsWrap);
                skillsWrap.appendChild(renderMessage('error', 'Error: You are not logged in.'));
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
                clearEl(skillsWrap);
                skillsWrap.appendChild(renderMessage('', 'No active character found. Start the game first.'));
                return;
            }

            charId = sessionData.character_id;
        }

        clearEl(skillsWrap);
        skillsWrap.appendChild(renderLoader('Loading skills...'));

        try {
            const { data: charSkills, error: skillsErr } = await supa
                .from('cyber_character_skills')
                .select('proficiency, skill:cyber_skills (name, description, category, tags, img_url)')
                .eq('character_id', charId);

            if (skillsErr) throw skillsErr;

            const { data: charAbilities, error: abilitiesErr } = await supa
                .from('cyber_character_abilities')
                .select('ability:cyber_abilities (name, description, ability_type, cost, tags)')
                .eq('character_id', charId);

            if (abilitiesErr) throw abilitiesErr;

            renderCards(skillsWrap, charSkills || [], renderSkillCard, 'No skills found.');
            renderCards(abilitiesWrap, charAbilities || [], renderAbilityCard, 'No abilities found.');
        } catch (e) {
            console.error('❌ Skills load error:', e);
            clearEl(skillsWrap);
            clearEl(abilitiesWrap);
            skillsWrap.appendChild(renderMessage('error', 'An error occurred while loading data.'));
        }
    }

    window.twLoadSkillsAndAbilities = twLoadSkillsAndAbilities;

    if (document.readyState === 'complete') {
        setTimeout(() => window.twLoadSkillsAndAbilities(), 1000);
    } else {
        window.addEventListener('load', () => {
            setTimeout(() => window.twLoadSkillsAndAbilities(), 1000);
        });
    }
})();
