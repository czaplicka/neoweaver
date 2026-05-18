(function ($) {
	'use strict';

	let allActions = [];
	let combos = [];
	let userActions = [];
	let currentFilter = 'ALL';
	let deleteMode = false;
	let playerTagSet = new Set();
	let resizeRegistered = false;
	let searchTimer = null;

	let cachedBar = null;
	let cachedList = null;

	function getConfig() {
		return window.twQuickActionsData || {};
	}

	function getCharId() {
		return window.gameState?.activeCharacterId || null;
	}

	function getSupabase() {
		const cfg = getConfig();

		if (window.twSupabase) {
			return window.twSupabase;
		}

		if (typeof window.supabase?.createClient === 'function') {
			window.twSupabase = window.supabase.createClient(
				window.twGlobals?.supabaseUrl || cfg.supabaseUrl || '',
				window.twGlobals?.anonKey || cfg.anonKey || ''
			);
			return window.twSupabase;
		}

		return null;
	}

	function getBar() {
		if (cachedBar && document.body.contains(cachedBar)) {
			return cachedBar;
		}
		cachedBar = document.getElementById('quick-actions-bar');
		return cachedBar;
	}

	function getList() {
		if (cachedList && document.body.contains(cachedList)) {
			return cachedList;
		}
		cachedList = document.getElementById('user-actions-list');
		return cachedList;
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function isActionAvailable(action) {
		if (action.is_permanent) {
			return true;
		}

		const reqTags = (
			action.required_tags
				? action.required_tags.split(',')
				: action.required_tag
					? [action.required_tag]
					: []
		)
			.map((t) => t.trim())
			.filter(Boolean);

		if (!reqTags.length) {
			return true;
		}

		return reqTags.some((tag) => playerTagSet.has(tag));
	}

	function renderQuickActionsUI(actions) {
		const bar = getBar();
		if (!bar) {
			return;
		}

		const available = (actions || [])
			.filter(isActionAvailable)
			.filter((a) => {
				return (
					currentFilter === 'ALL' ||
					(a.category || '').toLowerCase() === currentFilter.toLowerCase()
				);
			});

		const fragment = document.createDocumentFragment();

		available.forEach((action) => {
			const btn = document.createElement('button');
			const category = (
				action.category ||
				(action.type === 'Combo' ? 'combo' : 'universal')
			).toLowerCase();

			btn.className = 'qa-btn qa-' + category;
			btn.type = 'button';
			btn.innerHTML = '<span class="qa-label">' + escapeHtml(action.label) + '</span>';
			btn.addEventListener('click', function () {
				handleQuickActionClick(action.template);
			});

			fragment.appendChild(btn);
		});

		bar.innerHTML = '';
		bar.appendChild(fragment);

		document.querySelectorAll('.filter-btn').forEach(function (btn) {
			const btnFilter = btn.dataset.filter || btn.textContent.trim();
			btn.classList.toggle('active', btnFilter === currentFilter);
		});
	}

	function renderUserActions() {
		const list = getList();
		if (!list) {
			return;
		}

		const fragment = document.createDocumentFragment();

		(userActions || []).forEach(function (action) {
			const wrapper = document.createElement('div');
			wrapper.style.cssText = 'display:flex;gap:6px;align-items:center;';

			const btn = document.createElement('button');
			const category = (action.category || 'universal').toLowerCase();
			btn.className = 'qa-btn qa-' + category;
			btn.type = 'button';
			btn.innerHTML = '<span class="qa-label">' + escapeHtml(action.label) + '</span>';
			btn.addEventListener('click', function () {
				handleQuickActionClick(action.template);
			});
			wrapper.appendChild(btn);

			if (deleteMode) {
				const del = document.createElement('button');
				del.className = 'qa-delete';
				del.type = 'button';
				del.title = 'Delete';
				del.textContent = '[X]';
				del.addEventListener('click', function () {
					deleteUserAction(action.id);
				});
				wrapper.appendChild(del);
			}

			fragment.appendChild(wrapper);
		});

		list.innerHTML = '';
		list.appendChild(fragment);
	}

	async function loadAllData() {
		const sb = getSupabase();
		const charId = getCharId();

		if (!sb || !charId) {
			return;
		}

		try {
			const [{ data: actions }, { data: cmb }, { data: ua }] = await Promise.all([
				sb.from('cyber_quick_actions').select('*').order('display_order'),
				sb.from('cyber_combos').select('*'),
				sb.from('cyber_user_actions').select('*').eq('character_id', charId)
			]);

			allActions = actions || [];
			combos = cmb || [];
			userActions = ua || [];
		} catch (e) {
			console.error('QA Load Error:', e);
			return;
		}

		try {
			renderQuickActionsUI([].concat(allActions, combos));
		} catch (e) {
			console.error('QA Render Error (main):', e);
		}

		try {
			renderUserActions();
		} catch (e) {
			console.error('QA Render Error (user actions):', e);
		}
	}

	function handleQuickActionClick(template) {
		const input =
			window.gameState?.userInput ||
			document.querySelector('#chat-input-field');

		if (!input) {
			return;
		}

		const text = String(template || '').replace(
			/\[WeaponTag\]/g,
			window.twCurrentWeaponTag || '#Unarmed'
		);

		input.value = text;
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
		input.focus();

		const start = text.indexOf('[');
		const end = text.indexOf(']', start);

		if (start !== -1 && end > start) {
			input.setSelectionRange(start, end + 1);
		}
	}

	function applyResponsiveWrap() {
		const bar = getBar();
		if (!bar) {
			return;
		}

		bar.style.flexWrap = window.innerWidth < 768 ? 'wrap' : 'nowrap';
	}

	window.handleQuickActionClick = handleQuickActionClick;

	window.refreshQuickActions = async function () {
		await loadAllData();
	};

	window.twUpdatePlayerTags = function (tags) {
		playerTagSet = new Set(
			Array.isArray(tags) ? tags.map((t) => String(t).trim()).filter(Boolean) : []
		);

		try {
			renderQuickActionsUI([].concat(allActions, combos));
		} catch (e) {
			console.error('QA Tag-refresh Error:', e);
		}
	};

	window.toggleQAManager = function () {
		const panel = document.getElementById('qa-manager-panel');
		const toggle = document.getElementById('qa-manager-toggle');

		if (!panel || !toggle) {
			return;
		}

		const isHidden = panel.style.display === 'none' || !panel.style.display;
		panel.style.display = isHidden ? 'block' : 'none';
		toggle.textContent = isHidden ? '[-] CMD_CENTER' : '[+] CMD_CENTER';
	};

	window.toggleDeleteMode = function () {
		deleteMode = !deleteMode;

		const btn = document.getElementById('toggle-delete-mode-btn');
		if (btn) {
			btn.textContent = deleteMode ? '[✓] DEL_MODE' : '[x] DEL_MODE';
		}

		renderUserActions();
	};

	window.setQAFilter = function (filter, ev) {
		currentFilter = filter;

		const eventObj = ev || window.event;
		document.querySelectorAll('.filter-btn').forEach(function (btn) {
			btn.classList.remove('active');
		});

		if (eventObj?.target?.classList) {
			eventObj.target.classList.add('active');
		}

		renderQuickActionsUI([].concat(allActions, combos));
	};

	window.twLoadQuickActions = function () {
		window.clearTimeout(searchTimer);

		searchTimer = window.setTimeout(function () {
			const input = document.getElementById('qa-search-input');
			const search = (input?.value || '').toLowerCase();

			const filtered = [].concat(allActions, combos).filter(function (a) {
				return (
					(a.label || '').toLowerCase().includes(search) ||
					(a.template || '').toLowerCase().includes(search)
				);
			});

			renderQuickActionsUI(filtered);
		}, Number(getConfig().searchDebounce || 200));
	};

	window.saveCustomAction = async function () {
		const cfg = getConfig();
		const label = document.getElementById('custom-label')?.value || '';
		const template = document.getElementById('custom-template')?.value || '';
		const category = document.getElementById('custom-category')?.value || 'universal';

		if (!label || !template) {
			window.alert(cfg.requiredFieldsMessage || 'Label and Prompt are required!');
			return;
		}

		const sb = getSupabase();
		const charId = getCharId();

		if (!sb || !charId) {
			return;
		}

		const { error } = await sb.from('cyber_user_actions').insert({
			character_id: charId,
			label: label,
			template: template,
			category: category
		});

		if (!error) {
			const labelEl = document.getElementById('custom-label');
			const templateEl = document.getElementById('custom-template');

			if (labelEl) {
				labelEl.value = '';
			}
			if (templateEl) {
				templateEl.value = '';
			}

			await loadAllData();
		} else {
			console.error('Save custom action error:', error);
		}
	};

	window.deleteUserAction = async function (id) {
		const sb = getSupabase();
		const cfg = getConfig();

		if (!sb || !id) {
			return;
		}

		if (!window.confirm(cfg.confirmDeleteCustomAction || 'Delete custom action?')) {
			return;
		}

		const { error } = await sb.from('cyber_user_actions').delete().eq('id', id);

		if (!error) {
			await loadAllData();
		} else {
			console.error('Delete user action error:', error);
		}
	};

	$(document).ready(function () {
		loadAllData();
		applyResponsiveWrap();

		if (!resizeRegistered) {
			resizeRegistered = true;
			$(window).on('resize.qaResize', applyResponsiveWrap);
		}
	});
})(jQuery);
