<?php
/**
 * LAYOUT CONTRACT (mandatory for all current and future shortcodes):
 *   Every shortcode return value MUST be wrapped in:
 *     <div class="neoweaver-screen">…</div>
 *   The shared layout rules in assets/css/neoweaver-public.css rely on this
 *   wrapper to control z-index, margin and overflow relative to the theme's
 *   CTA section and footer.
 *
 * CSS SCOPING RULE:
 *   Inline <style> blocks MUST scope all rules under both the shared wrapper
 *   AND the screen's unique root ID, e.g.:
 *     .neoweaver-screen #tw-char-creator .tw-screen-bezel { … }
 *   This prevents collisions between different shortcodes that share generic
 *   class names (.tw-monitor-outer, .tw-screen-bezel, etc.).
 *
 *   Rules already handled by the shared stylesheet (overflow, height, z-index,
 *   loading overlay) must NOT be duplicated here.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Public {

	/** @var Neoweaver_Agents_List */
	protected Neoweaver_Agents_List $agents_list;

	/** @var Neoweaver_Agents_Creator */
	protected Neoweaver_Agents_Creator $agents_creator;

	/** @var Neoweaver_Deployments_Creator */
	protected Neoweaver_Deployments_Creator $deployments_creator;

	/** @var Neoweaver_Nodes_Creator */
	protected Neoweaver_Nodes_Creator $nodes_creator;

	public function __construct(
		Neoweaver_Agents_List $agents_list,
		Neoweaver_Agents_Creator $agents_creator,
		Neoweaver_Deployments_Creator $deployments_creator,
		Neoweaver_Nodes_Creator $nodes_creator
	) {
		$this->agents_list          = $agents_list;
		$this->agents_creator       = $agents_creator;
		$this->deployments_creator  = $deployments_creator;
		$this->nodes_creator        = $nodes_creator;

		add_shortcode( 'tw_list_characters',            [ $this, 'shortcode_list_characters' ] );
		add_shortcode( 'tale_weaver_character_creator', [ $this, 'shortcode_character_creator' ] );
		add_shortcode( 'tw_create_campaign',            [ $this, 'shortcode_campaign_creator' ] );
		add_shortcode( 'tw_world_creator',              [ $this, 'shortcode_world_creator' ] );
add_action( 'wp_footer', [ $this, 'enqueue_quick_actions_bridge' ] );

	}

	// =========================================================================
	// PRIVATE HELPER
	// =========================================================================

	/**
	 * Wrap any shortcode HTML in the mandatory .neoweaver-screen container.
	 *
	 * All layout rules (z-index, margin, overflow) are in neoweaver-public.css
	 * and target this wrapper. Call this as the final step in every shortcode.
	 *
	 * @param string $html
	 * @return string
	 */
	private function screen( string $html ): string {
		return '<div class="neoweaver-screen">' . $html . '</div>';
	}

	// =========================================================================
	// SHORTCODE: character list
	// =========================================================================

	/**
	 * [tw_list_characters]
	 * Renders the full agent roster for the currently logged-in Operator.
	 */
	public function shortcode_list_characters(): string {
		if ( is_admin() ) {
			return '';
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->screen( '<p>Please log in.</p>' );
		}
		return $this->screen( $this->agents_list->render_roster( $user_id ) );
	}

	// =========================================================================
	// SHORTCODE: character creator
	// =========================================================================

	/**
	 * [tale_weaver_character_creator]
	 *
	 * Renders the 9-step character creation wizard.
	 *
	 * RENDER-ONLY. The form submits via fetch() to the theme endpoint at
	 * {stylesheet_dir}/endpoint/tw-endpoint-character.php.
	 *
	 * CSS scope: .neoweaver-screen #tw-char-creator
	 * Loading overlay CSS lives in assets/css/neoweaver-public.css (shared).
	 */
	public function shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return $this->screen( '<div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div>' );
		}

		ob_start();
		$nonce = wp_create_nonce( 'tw_character_nonce' );
		?>
<div id="tw-char-creator" class="tw-monitor-outer">
	<div class="tw-screen-bezel">
		<div class="tw-scanlines"></div>
		<div class="tw-static-noise"></div>
		<div class="tw-glitch-overlay" id="tw-char-glitch"></div>

		<div class="tw-monitor-header">
			<div class="tw-header-left"><span class="tw-blink"></span> FIELD_AGENT//NEOWEAVER</div>
			<div class="tw-header-right">CHARACTER PROTOCOL <span id="tw-step-counter">01</span>/9</div>
		</div>

		<div id="tw-progress-bar"><div id="tw-progress-fill"></div></div>

		<div id="tw-creator-wrapper">
			<form id="tw-character-form" enctype="multipart/form-data">

				<!-- STEP 1: Name -->
				<div class="tw-step active" data-step="1">
					<h2>// IDENTITY_PROTOCOL</h2>
					<div class="tw-question-text">System Initialized. Enter Subject Name:</div>
					<input type="text" name="character_name" id="tw-name" placeholder="> Type name here..." autocomplete="off">
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()" style="visibility:hidden;">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">CONFIRM [ENTER]</button>
					</div>
				</div>

				<!-- STEP 2: Pronouns -->
				<div class="tw-step" data-step="2">
					<h2>// ADDRESSING_PARAMETERS</h2>
					<div class="tw-question-text">Select verbal identification tags:</div>
					<div class="tw-radio-grid">
						<label class="tw-card-label"><input type="radio" name="pronouns" value="M"><div class="tw-card-visual" style="min-height:150px;justify-content:center;"><strong>He/Him</strong></div></label>
						<label class="tw-card-label"><input type="radio" name="pronouns" value="F"><div class="tw-card-visual" style="min-height:150px;justify-content:center;"><strong>She/Her</strong></div></label>
						<label class="tw-card-label"><input type="radio" name="pronouns" value="NB"><div class="tw-card-visual" style="min-height:150px;justify-content:center;"><strong>They/Them</strong></div></label>
						<label class="tw-card-label"><input type="radio" name="pronouns" value="S"><div class="tw-card-visual" style="min-height:150px;justify-content:center;"><strong>It/Its</strong></div></label>
					</div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 3: Greeting -->
				<div class="tw-step" data-step="3">
					<h2>// LINK_ESTABLISHED</h2>
					<div class="tw-question-text" id="tw-greeting-text"></div>
					<p style="font-size:1.4rem;color:#aaa;line-height:1.6;">Prepare for neural synchronization.<br>We will now define your physical and metaphysical attributes.</p>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">INITIALIZE</button>
					</div>
				</div>

				<!-- STEP 4: Race (DYNAMIC) -->
				<div class="tw-step" data-step="4">
					<h2>// BIOLOGICAL_ORIGIN</h2>
					<div class="tw-question-text">Analyze DNA sequence. Choose origin:</div>
					<div id="tw-race-grid" class="tw-radio-grid"><div style="grid-column:1/-1;text-align:center;">Loading DNA Database...</div></div>
					<input type="hidden" name="race_final" id="tw-race-final">
					<div id="tw-subrace-container" style="display:none;margin-top:50px;border-top:1px dashed #444;padding-top:30px;">
						<div class="tw-question-text" style="font-size:1.2rem;border:none;color:var(--twcc-neon);">>> SELECT LINEAGE:</div>
						<div id="tw-subrace-grid" class="tw-radio-grid"></div>
					</div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 5: Class (DYNAMIC) -->
				<div class="tw-step" data-step="5">
					<h2>// CLASS_SELECTION</h2>
					<div class="tw-question-text">Define combat role and operational capability:</div>
					<div id="tw-class-grid" class="tw-radio-grid"><div style="grid-column:1/-1;text-align:center;">Loading Class Protocols...</div></div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 6: Attributes -->
				<div class="tw-step" data-step="6">
					<h2>// ATTRIBUTE_CALIBRATION</h2>
					<div class="tw-question-text">Allocate power to core systems. (Max Total: 12)</div>
					<div class="tw-presets">
						<span class="tw-presets-label">Load Preset:</span>
						<button type="button" class="tw-preset-btn tw-btn-nav" data-preset="body_builder">Body builder</button>
						<button type="button" class="tw-preset-btn tw-btn-nav" data-preset="gunslinger">Gunslinger</button>
						<button type="button" class="tw-preset-btn tw-btn-nav" data-preset="genius">Genius</button>
						<button type="button" class="tw-preset-btn tw-btn-nav" data-preset="warlock">Warlock</button>
					</div>
					<div id="tw-attr-container">
						<?php
						$attrs = [
							'body'   => [ 'BODY (STR+CON)',    'Brute force, health pool, heavy lifting.' ],
							'reflex' => [ 'REFLEX (DEX)',       'Speed, evasion, precision aiming.' ],
							'mind'   => [ 'MIND (INT+WIS)',     'Logic, repair, investigation, awareness.' ],
							'spirit' => [ 'SPIRIT (CHA+WILL)', 'Magic power, persuasion, willpower.' ],
						];
						foreach ( $attrs as $key => $info ) : ?>
						<div class="tw-attr-row">
							<div class="tw-attr-info">
								<h4><?php echo esc_html( $info[0] ); ?></h4>
								<p style="color:#888;"><?php echo esc_html( $info[1] ); ?></p>
							</div>
							<div style="margin-left:auto;display:flex;align-items:center;gap:20px;">
								<button type="button" class="tw-btn-nav" style="padding:5px 20px;" onclick="changeAttr('<?php echo esc_js( $key ); ?>', -1)">-</button>
								<input type="text" name="attr_<?php echo esc_attr( $key ); ?>" id="attr_<?php echo esc_attr( $key ); ?>" class="tw-attr-val" value="3" readonly>
								<button type="button" class="tw-btn-nav" style="padding:5px 20px;" onclick="changeAttr('<?php echo esc_js( $key ); ?>', 1)">+</button>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 7: Skills (DYNAMIC) -->
				<div class="tw-step" data-step="7">
					<h2>// SKILL_TRAINING</h2>
					<div class="tw-question-text">
						Select Expertise.
						<span id="tw-skill-limit-text" style="color:var(--twcc-neon);font-weight:bold;border:1px solid var(--twcc-neon);padding:5px 15px;border-radius:4px;margin-left:15px;background:rgba(173,255,0,0.1);">LIMIT: 3</span>
					</div>
					<div id="tw-skill-grid" class="tw-skill-grid"><div style="grid-column:1/-1;text-align:center;">Loading Skill Modules...</div></div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 8: Equipment (STATIC) -->
				<div class="tw-step" data-step="8">
					<h2>// EQUIPMENT_LOADOUT</h2>
					<div class="tw-question-text">Select your starting gear pack.</div>
					<div id="tw-eq-mercenary" class="tw-eq-list" style="display:none;grid-template-columns:1fr;gap:20px;">
						<label class="tw-eq-label"><input type="radio" name="equipment" value="250d2514-c035-4b80-a0b8-3622757d9dfd"><div class="tw-eq-tile"><b>Frontline Breacher backpack</b><span>Breaking enemy lines and crowd control.</span></div></label>
						<label class="tw-eq-label"><input type="radio" name="equipment" value="3029a9da-64e9-46d7-8712-391fbf9755d0"><div class="tw-eq-tile"><b>Surgical Striker bag</b><span>Precision over power.</span></div></label>
						<label class="tw-eq-label"><input type="radio" name="equipment" value="a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d"><div class="tw-eq-tile"><b>Mercenary Tactical kit</b><span>Survival and close-quarters combat.</span></div></label>
					</div>
					<div id="tw-eq-psychic" class="tw-eq-list" style="display:none;grid-template-columns:1fr;gap:20px;">
						<label class="tw-eq-label"><input type="radio" name="equipment" value="594675e6-89fe-4791-9e56-35c2d45367ff"><div class="tw-eq-tile"><b>Techno-Occultist kit</b><span>Manipulating technology &amp; anti-mech.</span></div></label>
						<label class="tw-eq-label"><input type="radio" name="equipment" value="f0757195-d2f5-4679-aa21-b5cbdde8e91f"><div class="tw-eq-tile"><b>Wandering Hermit bag</b><span>Resource regeneration &amp; detection.</span></div></label>
						<label class="tw-eq-label"><input type="radio" name="equipment" value="b2c3d4e5-f6a7-4b6c-9d0e-1f2a3b4c5d6e"><div class="tw-eq-tile"><b>Psychic Insight Bundle</b><span>Mental focus &amp; low profile.</span></div></label>
					</div>
					<div id="tw-eq-placeholder" style="text-align:center;color:#666;padding:40px;">> SELECT CLASS IN STEP 5 TO VIEW GEAR</div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="nextStep()">NEXT</button>
					</div>
				</div>

				<!-- STEP 9: Summary & Avatar -->
				<div class="tw-step" data-step="9">
					<h2>// FINAL_PROTOCOL</h2>
					<div class="tw-question-text">Bio-data complete. Upload physical representation.</div>
					<div style="margin-bottom:40px;">
						<label style="color:var(--twcc-neon);margin-bottom:15px;display:block;font-size:1.3rem;">> ACCESSING ARCHIVES... ENTER BACKSTORY:</label>
						<textarea name="backstory" placeholder="Write your legend here..."></textarea>
					</div>
					<div class="tw-upload-box" onclick="document.getElementById('tw-avatar').click()">
						<div style="font-size:5rem;color:#555;margin-bottom:20px;">📷</div>
						<div style="font-size:1.5rem;color:#fff;font-weight:700;margin-bottom:10px;">UPLOAD AVATAR</div>
						<div style="font-size:1rem;color:#888;">Formats: JPG, PNG. Max: 2MB.</div>
						<div id="tw-file-name" style="color:var(--twcc-neon);margin-top:20px;font-weight:bold;font-size:1.2rem;"></div>
						<input type="file" name="avatar" id="tw-avatar" accept=".jpg, .jpeg, .png" style="display:none;" onchange="updateFileName()">
					</div>
					<div class="tw-nav-row">
						<button type="button" class="tw-btn-nav tw-btn-back" onclick="prevStep()">BACK</button>
						<button type="button" class="tw-btn-nav" onclick="submitCharacter()">LAUNCH</button>
					</div>
				</div>

			</form>
		</div>

		<audio id="tw-glitch-sfx" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/dragon-studio-glitch-sound-effect-443130.mp3" preload="auto"></audio>
	</div>
</div>

<!-- Loading overlay — position:fixed, outside the monitor, sits at body level -->
<div id="tw-character-loading-overlay">
	<div class="tw-loading-core">
		<div class="tw-loading-ring"></div>
		<div class="tw-loading-ring tw-loading-ring-2"></div>
		<div class="tw-loading-text">STITCHING OPERATIVE...<br><span class="tw-loading-sub">Syncing agent with NeoWeave clusters</span></div>
	</div>
</div>

<script>
let currentStep = 1;
let currentClassLimit = 3;
let sb = null;
let skillsLoaded = false;
const maxTotal = 12;
let twSubraceOpenedOnce = false;

function twPlayGlitch() {
    const a = document.getElementById('tw-glitch-sfx');
    if (!a) return;
    a.currentTime = 0;
    a.play().catch(() => {});
}

document.addEventListener("DOMContentLoaded", function() {
    if (window.twSupabase) { sb = window.twSupabase; }
    else { document.addEventListener('twSupabaseReady', function() { sb = window.twSupabase; }, { once: true }); }
    showStep(currentStep);
});

function showStep(step) {
    document.querySelectorAll('.tw-step').forEach(s => s.classList.remove('active'));
    const target = document.querySelector(`.tw-step[data-step="${step}"]`);
    if (target) target.classList.add('active');
    const total = document.querySelectorAll('.tw-step').length;
    const counter = document.getElementById('tw-step-counter');
    const fill = document.getElementById('tw-progress-fill');
    if (counter) counter.textContent = String(step).padStart(2, '0');
    if (fill) fill.style.width = (step / total * 100) + '%';
    if (step === 3) { const name = document.getElementById('tw-name').value; document.getElementById('tw-greeting-text').innerText = `Subject Identified: ${name}`; }
    if (step === 4) loadRaces();
    if (step === 5) loadClasses();
    if (step === 7) loadSkills();
}

function nextStep() {
    if (currentStep === 1) { const v = (document.getElementById('tw-name').value || '').trim(); if (!v) { alert("ERROR: Name Field Empty."); return; } }
    if (currentStep === 2 && !document.querySelector('input[name="pronouns"]:checked')) { alert("ERROR: Pronouns Not Selected."); return; }
    if (currentStep === 4) {
        if (!document.querySelector('input[name="race"]:checked')) { alert("ERROR: Race Not Selected."); return; }
        const sc = document.getElementById('tw-subrace-container');
        if (sc.style.display === 'block' && !document.querySelector('input[name="subrace"]:checked')) { alert("ERROR: Lineage Required."); return; }
    }
    if (currentStep === 5 && !document.querySelector('input[name="class"]:checked')) { alert("ERROR: Class Not Selected."); return; }
    if (currentStep === 7 && document.querySelectorAll('input[name="skills[]"]:checked').length === 0) { alert("ERROR: Select at least ONE skill module."); return; }
    if (currentStep === 8 && !document.querySelector('input[name="equipment"]:checked')) { alert("ERROR: Equipment Not Selected."); return; }
    if (currentStep < 9) {
        currentStep++;
        twPlayGlitch();
        showStep(currentStep);
        if (currentStep === 5 || currentStep === 7) window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function prevStep() {
    if (currentStep > 1) { currentStep--; twPlayGlitch(); showStep(currentStep); window.scrollTo({ top: 0, behavior: 'smooth' }); }
}

async function loadRaces() {
    if (!sb && window.twSupabase) sb = window.twSupabase;
    const grid = document.getElementById('tw-race-grid');
    if (!sb) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#f66;">NO SUPABASE CLIENT</div>'; return; }
    if (grid.dataset.loaded === '1') return;
    const { data: races, error } = await sb.from('cyber_races').select('*').order('name');
    if (error) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#f66;">ERROR LOADING RACES</div>'; return; }
    if (!races || !races.length) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#ccc;">NO RACES FOUND</div>'; return; }
    const main = races.filter(r => !r.parent_race || String(r.parent_race).trim() === '');
    if (!main.length) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#ccc;">NO ROOT RACES</div>'; return; }
    grid.innerHTML = main.map(r => `
        <label class="tw-card-label">
            <input type="radio" name="race" value="${r.id}" onclick="onRaceSelected('${r.id}','${(r.name||'').replace(/'/g,"\\'")}')">
            <div class="tw-card-visual">
                <img src="${r.img_url||'https://via.placeholder.com/200'}" alt="${r.name||''}">
                <strong>${r.name||''}</strong>
                <span>${formatTags(r.tags)}</span>
            </div>
        </label>`).join('');
    grid.dataset.loaded = '1';
}

function onRaceSelected(parentId, parentName) {
    document.getElementById('tw-race-final').value = parentId;
    const container = document.getElementById('tw-subrace-container');
    const wasVisible = container && container.style.display === 'block';
    toggleSubrace(parentName).then(() => {
        const nowVisible = container && container.style.display === 'block';
        if (!wasVisible && nowVisible && !twSubraceOpenedOnce) { twSubraceOpenedOnce = true; container.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
}

async function toggleSubrace(parentName) {
    const { data: subs, error } = await sb.from('cyber_races').select('*').eq('parent_race', parentName);
    const container = document.getElementById('tw-subrace-container');
    if (error) { if (container) container.style.display = 'none'; return; }
    if (subs && subs.length > 0) {
        container.style.display = 'block';
        document.getElementById('tw-subrace-grid').innerHTML = subs.map(s => `
            <label class="tw-card-label">
                <input type="radio" name="subrace" value="${s.id}" onclick="document.getElementById('tw-race-final').value='${s.id}'">
                <div class="tw-card-visual">
                    <img src="${s.img_url||'https://via.placeholder.com/200'}" alt="${s.name||''}">
                    <strong>${s.name||''}</strong><span>${formatTags(s.tags)}</span>
                </div>
            </label>`).join('');
    } else {
        container.style.display = 'none';
        document.querySelectorAll('input[name="subrace"]').forEach(r => r.checked = false);
    }
}

async function loadClasses() {
    if (!sb) return;
    const grid = document.getElementById('tw-class-grid');
    if (grid.dataset.loaded === '1') return;
    const { data: classes, error } = await sb.from('cyber_classes').select('*').order('name');
    if (error) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#f66;">ERROR LOADING CLASSES</div>'; return; }
    grid.innerHTML = classes.map(c => `
        <label class="tw-card-label">
            <input type="radio" name="class" value="${c.id}" onchange="selectClass(this)">
            <div class="tw-card-visual">
                <img src="${c.img_url||'https://via.placeholder.com/200'}" alt="${c.name||''}">
                <strong>${c.name||''}</strong><span>${formatTags(c.tags)}</span>
            </div>
        </label>`).join('');
    grid.dataset.loaded = '1';
}

function selectClass(input) {
    const cn = input.nextElementSibling.querySelector('strong').innerText.trim().toUpperCase();
    currentClassLimit = (cn === 'PSYCHIC') ? 5 : 3;
    document.getElementById('tw-skill-limit-text').innerText = "LIMIT: " + currentClassLimit;
    loadEquipment();
}

async function loadSkills() {
    if (!sb || skillsLoaded) return;
    const grid = document.getElementById('tw-skill-grid');
    const { data: skills, error } = await sb.from('cyber_skills').select('*').order('category, name');
    if (error) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#f66;">ERROR LOADING SKILLS</div>'; return; }
    let lastCat = '';
    grid.innerHTML = skills.map(s => {
        let catHTML = '';
        if (s.category !== lastCat) { catHTML = `<div class="tw-skill-category">>> ${s.category||'GENERAL'} MODULES</div>`; lastCat = s.category; }
        return catHTML + `<label class="tw-skill-item"><input type="checkbox" name="skills[]" value="${s.id}" onchange="checkSkillLimit(this)"><div class="tw-skill-card"><img src="${s.img_url||'https://cyber.nieodparady.pl/wp-content/uploads/2026/01/1.svg'}"><div class="tw-skill-text"><h5>${s.name}</h5><span>${formatTags(s.tags)}</span></div></div></label>`;
    }).join('');
    skillsLoaded = true;
}

function checkSkillLimit(cb) {
    if (document.querySelectorAll('input[name="skills[]"]:checked').length > currentClassLimit) { cb.checked = false; alert("MEMORY OVERFLOW. Max skills: " + currentClassLimit); }
}

function loadEquipment() {
    document.getElementById('tw-eq-mercenary').style.display = 'none';
    document.getElementById('tw-eq-psychic').style.display = 'none';
    document.getElementById('tw-eq-placeholder').style.display = 'none';
    const sel = document.querySelector('input[name="class"]:checked');
    if (!sel) { document.getElementById('tw-eq-placeholder').style.display = 'block'; return; }
    const cn = sel.nextElementSibling.querySelector('strong').innerText.trim().toUpperCase();
    document.getElementById(cn === 'PSYCHIC' ? 'tw-eq-psychic' : 'tw-eq-mercenary').style.display = 'grid';
}

function getAttrTotal() { return ['body','reflex','mind','spirit'].reduce((s,id) => s + parseInt(document.getElementById('attr_'+id).value||0), 0); }
function changeAttr(id, delta) {
    const el = document.getElementById('attr_'+id); let val = parseInt(el.value);
    if (delta > 0 && getAttrTotal() >= maxTotal) return;
    if (delta < 0 && val <= 1) return;
    if (delta > 0 && val >= 5) return;
    el.value = val + delta;
}

const presets = { body_builder:{body:5,reflex:2,mind:2,spirit:3}, gunslinger:{body:2,reflex:5,mind:2,spirit:3}, genius:{body:2,reflex:2,mind:5,spirit:3}, warlock:{body:2,reflex:2,mind:3,spirit:5} };
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.tw-preset-btn'); if (!btn) return;
    const set = presets[btn.dataset.preset]; if (!set) return;
    for (let k in set) document.getElementById('attr_'+k).value = set[k];
});

function formatTags(tags) { if (!tags) return ''; if (Array.isArray(tags)) return tags.map(t => t.replace(/_/g,' ')).join(' • '); return tags; }
function updateFileName() { const i = document.getElementById('tw-avatar'); if (i.files&&i.files.length>0) document.getElementById('tw-file-name').innerText = "FILE: "+i.files[0].name; }

function submitCharacter() {
    const form = document.getElementById('tw-character-form');
    const nameVal = document.getElementById('tw-name').value.trim();
    if (!nameVal) { alert("ERROR: Name Field Empty."); currentStep = 1; showStep(1); return; }
    const formData = new FormData(form);
    formData.set('character_name', nameVal);
    const finalRace = formData.get('race_final'); if (finalRace) formData.set('race', finalRace);
    formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
    if (!document.getElementById('tw-avatar').files.length) { if (!confirm("WARNING: No Avatar. Proceed?")) return; }
    const overlay = document.getElementById('tw-character-loading-overlay');
    const btn = document.querySelector('.tw-step[data-step="9"] .tw-btn-nav:last-child');
    const orig = btn.innerText;
    btn.innerText = 'TRANSMITTING TO THE WEAVE...'; btn.disabled = true;
    if (overlay) overlay.classList.add('active');
    fetch('<?php echo esc_js( get_stylesheet_directory_uri() ); ?>/endpoint/tw-endpoint-character.php', { method:'POST', body:formData })
        .then(r => r.json())
        .then(d => {
            if (d.success) { twPlayGlitch(); window.location.href = '/agents/'; }
            else { const msg = d.data&&d.data.message ? d.data.message : (d.message||'Unknown error'); alert('Error: '+msg); btn.innerText=orig; btn.disabled=false; if(overlay)overlay.classList.remove('active'); }
        })
        .catch(e => { console.error(e); alert('System Failure'); btn.innerText=orig; btn.disabled=false; if(overlay)overlay.classList.remove('active'); });
}
</script>

<!-- =====================================================================
     SCOPED STYLES for character creator.
     Scope: .neoweaver-screen #tw-char-creator
     Rules already in neoweaver-public.css (overflow, height, loading
     overlay) are NOT repeated here.
     ===================================================================== -->
<style>
.neoweaver-screen #tw-char-creator {
    --twcc-neon:   #adff00;
    --twcc-glow:   rgba(173,255,0,0.6);
    --twcc-text:   #e0e0e0;
}
.neoweaver-screen #tw-char-creator.tw-monitor-outer {
    max-width: 1200px;
    margin: 0 auto;
    background: #000;
    padding: 12px;
    border-radius: 15px;
    border: 4px solid #1a1a1a;
    box-shadow: 0 0 40px rgba(0,0,0,1);
}
.neoweaver-screen #tw-char-creator .tw-screen-bezel {
    background: #000b14;
    padding: 60px;
    border-radius: 12px;
    border: 1px solid #111;
    box-shadow: inset 0 0 80px rgba(0,0,0,0.9);
}
.neoweaver-screen #tw-char-creator .tw-scanlines {
    position: absolute; top:0; left:0; right:0; bottom:0;
    background: linear-gradient(rgba(18,16,16,0) 50%,rgba(0,0,0,0.1) 50%);
    background-size: 100% 3px; z-index: 2; pointer-events: none;
}
.neoweaver-screen #tw-char-creator .tw-static-noise {
    position: absolute; inset:0; opacity:0.08; z-index:1; pointer-events:none;
    background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}
.neoweaver-screen #tw-char-creator .tw-glitch-overlay { position:absolute; top:0; left:0; width:100%; height:100%; background:var(--twcc-neon); opacity:0; z-index:3; pointer-events:none; }
.neoweaver-screen #tw-char-creator .tw-monitor-header { display:flex; justify-content:space-between; color:var(--twcc-neon); font-size:0.8rem; margin-bottom:25px; border-bottom:1px solid rgba(173,255,0,0.2); padding-bottom:5px; font-weight:bold; }
.neoweaver-screen #tw-char-creator #tw-progress-bar { height:2px; background:rgba(255,255,255,0.05); margin-bottom:30px; }
.neoweaver-screen #tw-char-creator #tw-progress-fill { height:100%; background:var(--twcc-neon); width:0; box-shadow:0 0 15px var(--twcc-neon); transition:0.4s; }
.neoweaver-screen #tw-char-creator #tw-creator-wrapper { font-family:'Chakra Petch',sans-serif; color:var(--twcc-text); min-height:600px; position:relative; z-index:5; }
.neoweaver-screen #tw-char-creator .tw-step { display:none; opacity:0; }
.neoweaver-screen #tw-char-creator .tw-step.active { display:block; animation:twcc-fade 0.5s cubic-bezier(0.25,0.46,0.45,0.94) both; }
@keyframes twcc-fade { 0%{opacity:0;transform:scale(0.98);filter:blur(4px);} 100%{opacity:1;transform:scale(1);filter:blur(0);} }
.neoweaver-screen #tw-char-creator h2 { color:var(--twcc-neon); text-transform:uppercase; font-weight:700; font-size:2.0rem; letter-spacing:3px; margin-bottom:20px; text-shadow:0 0 15px var(--twcc-glow); }
.neoweaver-screen #tw-char-creator .tw-question-text { font-size:1.3rem; color:#ccc; margin-bottom:40px; font-weight:300; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:18px; }
.neoweaver-screen #tw-char-creator input[type=text], .neoweaver-screen #tw-char-creator textarea { background:rgba(0,0,0,0.6); border:1px solid #444; border-left:5px solid #444; color:#fff; padding:18px; font-size:1.3rem; font-family:'Chakra Petch',sans-serif; width:100%; outline:none; transition:0.3s; }
.neoweaver-screen #tw-char-creator input[type=text]:focus, .neoweaver-screen #tw-char-creator textarea:focus { border-color:var(--twcc-neon); border-left-color:var(--twcc-neon); box-shadow:0 0 20px var(--twcc-glow); background:rgba(0,20,0,0.4); }
.neoweaver-screen #tw-char-creator .tw-nav-row { display:flex; justify-content:space-between; margin-top:40px; border-top:1px solid rgba(255,255,255,0.1); padding-top:25px; }
.neoweaver-screen #tw-char-creator .tw-btn-nav { background:transparent; border:2px solid var(--twcc-neon); color:var(--twcc-neon); padding:12px 40px; font-family:'Chakra Petch',sans-serif; font-weight:700; font-size:1.05rem; cursor:pointer; text-transform:uppercase; letter-spacing:2px; transition:0.3s; }
.neoweaver-screen #tw-char-creator .tw-btn-nav:hover { background:var(--twcc-neon); color:#000; box-shadow:0 0 40px var(--twcc-neon); }
.neoweaver-screen #tw-char-creator .tw-radio-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:20px; }
.neoweaver-screen #tw-char-creator .tw-card-label input { display:none; }
.neoweaver-screen #tw-char-creator .tw-card-visual { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.12); padding:18px; text-align:center; transition:all 0.3s ease; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; overflow:hidden; min-height:220px; }
.neoweaver-screen #tw-char-creator .tw-card-visual img { width:160px; height:auto; margin-bottom:14px; transition:0.35s; filter:drop-shadow(0 10px 10px rgba(0,0,0,0.8)); }
.neoweaver-screen #tw-char-creator .tw-card-visual strong { display:block; font-size:1.1rem; color:#fff; margin-bottom:4px; text-transform:uppercase; letter-spacing:1px; }
.neoweaver-screen #tw-char-creator .tw-card-visual span { font-size:0.8rem; color:#aaa; }
.neoweaver-screen #tw-char-creator .tw-card-label:hover .tw-card-visual { border-color:rgba(173,255,0,0.7); background:rgba(255,255,255,0.05); transform:translateY(-4px); }
.neoweaver-screen #tw-char-creator .tw-card-label:hover .tw-card-visual img { transform:scale(1.05) translateY(-4px); }
.neoweaver-screen #tw-char-creator .tw-card-label input:checked + .tw-card-visual { border-color:var(--twcc-neon); background:rgba(173,255,0,0.08); box-shadow:0 0 30px rgba(173,255,0,0.18),inset 0 0 16px rgba(173,255,0,0.25); }
.neoweaver-screen #tw-char-creator .tw-presets { margin-bottom:40px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.neoweaver-screen #tw-char-creator .tw-presets-label { color:#888; text-transform:uppercase; margin-right:10px; white-space:nowrap; }
.neoweaver-screen #tw-char-creator .tw-preset-btn { padding:10px 20px; font-size:0.9rem; border:1px solid #555; background:none; color:#fff; cursor:pointer; }
.neoweaver-screen #tw-char-creator .tw-attr-row { display:flex; align-items:center; margin-bottom:22px; background:rgba(0,0,0,0.4); padding:22px; border-left:5px solid #333; transition:0.3s; }
.neoweaver-screen #tw-char-creator .tw-attr-row:hover { border-left-color:var(--twcc-neon); background:rgba(255,255,255,0.03); }
.neoweaver-screen #tw-char-creator .tw-attr-info h4 { margin:0; color:#fff; font-size:1.3rem; letter-spacing:1px; }
.neoweaver-screen #tw-char-creator .tw-attr-val { width:70px; text-align:center; background:transparent; border:none; color:var(--twcc-neon); font-size:2.3rem; font-weight:700; }
.neoweaver-screen #tw-char-creator .tw-skill-grid { display:grid; grid-template-columns:repeat(3,minmax(260px,1fr)); gap:20px; max-height:550px; overflow-y:auto; padding:10px; }
.neoweaver-screen #tw-char-creator .tw-skill-item input { display:none; }
.neoweaver-screen #tw-char-creator .tw-skill-card { display:flex; align-items:center; background:rgba(0,0,0,0.5); border:1px solid #333; padding:18px; transition:0.2s; cursor:pointer; }
.neoweaver-screen #tw-char-creator .tw-skill-card img { width:54px; height:54px; margin-right:20px; opacity:0.7; transition:0.3s; }
.neoweaver-screen #tw-char-creator .tw-skill-item:hover .tw-skill-card { border-color:#777; background:rgba(255,255,255,0.08); }
.neoweaver-screen #tw-char-creator .tw-skill-item input:checked + .tw-skill-card { border-color:var(--twcc-neon); background:rgba(173,255,0,0.08); }
.neoweaver-screen #tw-char-creator .tw-skill-item input:checked + .tw-skill-card img { opacity:1; filter:drop-shadow(0 0 8px var(--twcc-neon)); }
.neoweaver-screen #tw-char-creator .tw-skill-text h5 { margin:0; font-size:1.05rem; color:#fff; }
.neoweaver-screen #tw-char-creator .tw-skill-text span { font-size:0.9rem; color:#888; }
.neoweaver-screen #tw-char-creator .tw-skill-category { grid-column:1/-1; margin-top:30px; margin-bottom:15px; font-size:1.1rem; color:var(--twcc-neon); text-transform:uppercase; border-bottom:1px solid #444; padding-bottom:8px; }
.neoweaver-screen #tw-char-creator .tw-eq-label input { display:none; }
.neoweaver-screen #tw-char-creator .tw-eq-tile { background:rgba(0,0,0,0.4); border:1px solid #333; padding:26px; transition:0.3s; cursor:pointer; display:block; margin-bottom:15px; }
.neoweaver-screen #tw-char-creator .tw-eq-tile b { font-size:1.15rem; color:#fff; display:block; margin-bottom:5px; }
.neoweaver-screen #tw-char-creator .tw-eq-tile span { font-size:0.9rem; color:#aaa; }
.neoweaver-screen #tw-char-creator .tw-eq-tile:hover { border-color:#666; background:rgba(255,255,255,0.05); }
.neoweaver-screen #tw-char-creator .tw-eq-label input:checked + .tw-eq-tile { border-color:var(--twcc-neon); background:rgba(173,255,0,0.05); box-shadow:0 0 20px rgba(173,255,0,0.1); }
.neoweaver-screen #tw-char-creator .tw-upload-box { border:3px dashed #444; padding:50px; text-align:center; background:rgba(0,0,0,0.4); border-radius:8px; cursor:pointer; transition:0.3s; }
.neoweaver-screen #tw-char-creator .tw-upload-box:hover { border-color:var(--twcc-neon); background:rgba(173,255,0,0.03); }
@media (max-width:900px) { .neoweaver-screen #tw-char-creator .tw-radio-grid { grid-template-columns:repeat(3,1fr); } .neoweaver-screen #tw-char-creator .tw-skill-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:600px) { .neoweaver-screen #tw-char-creator .tw-radio-grid { grid-template-columns:repeat(2,1fr); } .neoweaver-screen #tw-char-creator .tw-skill-grid { grid-template-columns:1fr; } .neoweaver-screen #tw-char-creator .tw-screen-bezel { padding:24px; } }
</style>
		<?php
		$html = ob_get_clean();
		return $this->screen( $html );
	}

	// =========================================================================
	// SHORTCODE: campaign / deployment creator
	// =========================================================================

	/**
	 * [tw_create_campaign]
	 *
	 * Renders the 8-step deployment (campaign) creation wizard.
	 * CSS scope: .neoweaver-screen #tw-campaign-creator-container
	 *
	 * BUG-FIX 7a: Removed duplicate sendCampaign() definition. The first
	 *             definition referenced wrong radio names (gamemode, worldtype,
	 *             gmstyle, gamelength) and tried to fetch a non-existent
	 *             campaign-form element. Only one clean definition remains.
	 * BUG-FIX 7b: All radio name= attributes in the HTML now match what
	 *             sendCampaign reads: game_mode, world_type, gm_style,
	 *             game_length, priority.
	 * BUG-FIX 7c: world/character skip guard changed from
	 *             !== 'SELECT NODE or skip' to !== '' so the empty <option
	 *             value=""> placeholder is correctly detected as "skip".
	 */
	public function shortcode_campaign_creator(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->screen( '<p class="tw-error">UPLINK REQUIRED. LOG IN.</p>' );
		}

		$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key = tw_supabase_anon_key();
		$headers  = [ 'apikey' => $anon_key, 'Authorization' => 'Bearer ' . $anon_key ];

		$worlds_res = wp_remote_get( add_query_arg( [ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ], $url_base . 'cyber_worlds' ), [ 'headers' => $headers ] );
		$chars_res  = wp_remote_get( add_query_arg( [ 'wp_user_id' => 'eq.' . $user_id, 'select' => 'id,name' ], $url_base . 'cyber_characters' ), [ 'headers' => $headers ] );

		$worlds     = json_decode( wp_remote_retrieve_body( $worlds_res ), true );
		$characters = json_decode( wp_remote_retrieve_body( $chars_res ), true );

		$campaign_nonce = wp_create_nonce( 'tw_campaign_nonce' );

		ob_start();
		?>
<div id="tw-campaign-creator-container" class="tw-monitor-outer">
	<audio id="tw-campaign-audio-click" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/dragon-studio-glitch-sound-effect-450447.mp3" preload="auto"></audio>

	<div class="tw-screen-bezel">
		<div class="tw-glitch-overlay"></div>
		<div class="tw-scanlines"></div>
		<div class="tw-static-noise"></div>

		<div class="tw-monitor-header">
			<div class="tw-header-left"><span class="tw-blink">●</span> NEO_WEAVER_WAR_ROOM_OS</div>
			<div class="tw-header-right">PLANNING_DEPLOYMENT_STEP: <span id="tw-camp-step-counter">01</span>/08</div>
		</div>

		<div id="tw-camp-progress-bar"><div id="tw-camp-progress-fill"></div></div>

		<div class="tw-terminal-interface">
			<div id="tw-steps-container">

				<!-- STEP 1 -->
				<div class="tw-terminal-step active" data-step="1">
					<div class="tw-prompt">>  OPERATION CODE NAME:</div>
					<input type="text" id="c-name" class="tw-term-input" placeholder="Type name..." autocomplete="off">
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back" style="visibility:hidden;">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">NEXT [ENTER]</button>
					</div>
				</div>

				<!-- STEP 2: game_mode -->
				<div class="tw-terminal-step" data-step="2">
					<div class="tw-prompt">> CHOOSE DEPLOYMENT MODE:</div>
					<div class="tw-big-grid tw-big-grid-2">
						<label class="tw-big-card"><input type="radio" name="game_mode" value="1" checked><div class="tw-card-inner"><span>SOLO_OPERATIVE</span><small>Single-player experience</small></div></label>
						<label class="tw-big-card"><input type="radio" name="game_mode" value="2"><div class="tw-card-inner"><span>STRIKE_TEAM</span><small>Lead a crew of AGENTS</small></div></label>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">PROCEED [ENTER]</button>
					</div>
				</div>

				<!-- STEP 3: world_type -->
				<div class="tw-terminal-step" data-step="3">
					<div class="tw-prompt">> THREAT LEVEL ASSESSMENT:</div>
					<div class="tw-image-selector">
						<?php
						$diff_data = [
							1 => [ 'img' => '1-1.svg', 'name' => 'Super Easy', 'desc' => 'Training simulation. No real risk.' ],
							2 => [ 'img' => '2-1.svg', 'name' => 'Easy',       'desc' => 'Minor resistance. Good for focus on lore.' ],
							3 => [ 'img' => '3-2.svg', 'name' => 'Normal',     'desc' => 'The standard grit of the deployment.' ],
							4 => [ 'img' => '4-2.svg', 'name' => 'Hard',       'desc' => 'High mortality rate. Watch your back.' ],
							5 => [ 'img' => '5-2.svg', 'name' => 'Brutal',     'desc' => 'Suicide mission. Only for the desperate.' ],
						];
						foreach ( $diff_data as $val => $data ) : ?>
						<label class="tw-img-label">
							<input type="radio" name="world_type" value="<?php echo $val; ?>" <?php echo $val === 3 ? 'checked' : ''; ?>>
							<img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/<?php echo esc_attr( $data['img'] ); ?>">
							<div class="tw-hover-label"><strong><?php echo esc_html( $data['name'] ); ?></strong><p><?php echo esc_html( $data['desc'] ); ?></p></div>
						</label>
						<?php endforeach; ?>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">CONFIRM_THREAT [ENTER]</button>
					</div>
				</div>

				<!-- STEP 4: gm_style -->
				<div class="tw-terminal-step" data-step="4">
					<div class="tw-prompt">> TERMINAL CALIBRATION PROTOCOLS:</div>
					<div class="tw-image-selector">
						<label class="tw-img-label"><input type="radio" name="gm_style" value="cinematic_heroic" checked><img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/1-2.svg"><div class="tw-hover-label"><strong>VIVID &amp; ADAPTIVE</strong><p>Expect poetic glitches and high-fidelity sensory data.</p></div></label>
						<label class="tw-img-label"><input type="radio" name="gm_style" value="fast_tactical"><img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/2-2.svg"><div class="tw-hover-label"><strong>CORE &amp; EFFICIENT</strong><p>Minimalistic prose. Direct feedback loops only.</p></div></label>
						<label class="tw-img-label"><input type="radio" name="gm_style" value="harsh_grounded"><img src="https://cyber.nieodparady.pl/wp-content/uploads/2026/01/3-3.svg"><div class="tw-hover-label"><strong>RAW &amp; UNFORGIVING</strong><p>Error-margin is zero. Survival is an anomaly.</p></div></label>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">ENGAGE_STYLE [ENTER]</button>
					</div>
				</div>

				<!-- STEP 5: game_length -->
				<div class="tw-terminal-step" data-step="5">
					<div class="tw-prompt">> DEFINE DEPLOYMENT LENGTH:</div>
					<div class="tw-big-grid tw-big-grid-5">
						<label class="tw-big-card"><input type="radio" name="game_length" value="1" checked><div class="tw-card-inner"><span>SHORT</span><small>2–3 main missions</small></div></label>
						<label class="tw-big-card"><input type="radio" name="game_length" value="2"><div class="tw-card-inner"><span>COMPACT</span><small>5–6 main missions</small></div></label>
						<label class="tw-big-card"><input type="radio" name="game_length" value="3"><div class="tw-card-inner"><span>STANDARD</span><small>7–10 main missions</small></div></label>
						<label class="tw-big-card"><input type="radio" name="game_length" value="4"><div class="tw-card-inner"><span>LONG-RUN</span><small>10+ main missions</small></div></label>
						<label class="tw-big-card"><input type="radio" name="game_length" value="5"><div class="tw-card-inner"><span>INFINITE</span><small>Endless weave</small></div></label>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">LOCK_LENGTH [ENTER]</button>
					</div>
				</div>

				<!-- STEP 6: priority -->
				<div class="tw-terminal-step" data-step="6">
					<div class="tw-prompt">> OPERATIONAL PRIORITY MATRIX:</div>
					<div class="tw-big-grid tw-big-grid-5">
						<label class="tw-big-card"><input type="radio" name="priority" value="1" checked><div class="tw-card-inner"><span>BALANCED</span><small>No main focus. Balanced mix.</small></div></label>
						<label class="tw-big-card"><input type="radio" name="priority" value="2"><div class="tw-card-inner"><span>WEALTH / PROFIT</span><small>More currency &amp; loot quests</small></div></label>
						<label class="tw-big-card"><input type="radio" name="priority" value="3"><div class="tw-card-inner"><span>LORE</span><small>Secrets, mysteries, rare equipment</small></div></label>
						<label class="tw-big-card"><input type="radio" name="priority" value="4"><div class="tw-card-inner"><span>INFLUENCE</span><small>Factions, reputation, politics</small></div></label>
						<label class="tw-big-card"><input type="radio" name="priority" value="5"><div class="tw-card-inner"><span>TACTICAL</span><small>Combat runs, stealth, bosses</small></div></label>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">SET_PRIORITY [ENTER]</button>
					</div>
				</div>

				<!-- STEP 7: optional world + character links -->
				<div class="tw-terminal-step" data-step="7">
					<div class="tw-prompt">> LINKING ASSETS [OPTIONAL]:</div>
					<div class="tw-select-pair">
						<select id="c-world" class="tw-term-select">
							<option value="">SELECT NODE_ (or skip)</option>
							<?php if ( $worlds ) foreach ( $worlds as $w ) : ?><option value="<?php echo esc_attr( $w['id'] ); ?>"><?php echo esc_html( $w['name'] ); ?></option><?php endforeach; ?>
						</select>
						<select id="c-char" class="tw-term-select">
							<option value="">SELECT AGENT_ (or skip)</option>
							<?php if ( $characters ) foreach ( $characters as $c ) : ?><option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button type="button" class="tw-next-btn tw-camp-confirm-btn" data-action="next">SYNC_OR_SKIP [ENTER]</button>
					</div>
				</div>

				<!-- STEP 8: lore notes + submit -->
				<div class="tw-terminal-step" data-step="8">
					<div class="tw-prompt">> ADDITIONAL ENCRYPTION (LORE NOTES):</div>
					<textarea id="c-lore" class="tw-term-textarea" rows="4" placeholder="Inject extra lore data here..."></textarea>
					<div class="tw-nav-btns">
						<button type="button" class="tw-back-btn" data-action="back">_BACK</button>
						<button id="final-send" class="tw-final-btn">[ INITIALIZE DEPLOYMENT ]</button>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&display=swap');
.neoweaver-screen #tw-campaign-creator-container { --twcm-neon:#adff00; --twcm-dim:rgba(173,255,0,0.2); --twcm-bg:#000b14; }
.neoweaver-screen #tw-campaign-creator-container.tw-monitor-outer { max-width:850px; margin:0 auto; background:#000; padding:12px; border-radius:15px; border:4px solid #1a1a1a; box-shadow:0 0 40px rgba(0,0,0,1); }
.neoweaver-screen #tw-campaign-creator-container .tw-screen-bezel { background:var(--twcm-bg); padding:40px; border-radius:5px; border:1px solid #111; box-shadow:inset 0 0 80px rgba(0,0,0,0.9); }
.neoweaver-screen #tw-campaign-creator-container .tw-scanlines { position:absolute; top:0; left:0; width:100%; height:100%; background:linear-gradient(rgba(18,16,16,0) 50%,rgba(0,0,0,0.2) 50%); background-size:100% 4px; z-index:20; pointer-events:none; opacity:0.6; }
.neoweaver-screen #tw-campaign-creator-container .tw-static-noise { position:absolute; inset:0; opacity:0.05; z-index:15; pointer-events:none; background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E"); }
.neoweaver-screen #tw-campaign-creator-container .tw-glitch-overlay { position:absolute; top:0; left:0; width:100%; height:100%; background:var(--twcm-neon); opacity:0; z-index:25; pointer-events:none; }
.neoweaver-screen #tw-campaign-creator-container .tw-monitor-header { display:flex; justify-content:space-between; color:var(--twcm-neon); font-size:0.8rem; margin-bottom:25px; border-bottom:1px solid var(--twcm-dim); padding-bottom:5px; font-weight:bold; }
.neoweaver-screen #tw-campaign-creator-container .tw-header-left .tw-blink { animation:twcm-blink 1s infinite alternate; }
@keyframes twcm-blink { from{opacity:0.3;} to{opacity:1;} }
.neoweaver-screen #tw-campaign-creator-container #tw-camp-progress-bar { height:2px; background:rgba(255,255,255,0.05); margin-bottom:30px; }
.neoweaver-screen #tw-campaign-creator-container #tw-camp-progress-fill { height:100%; background:var(--twcm-neon); width:0%; box-shadow:0 0 15px var(--twcm-neon); transition:0.4s; }
.neoweaver-screen #tw-campaign-creator-container .tw-terminal-interface { color:#adff00; font-family:'Chakra Petch',sans-serif; min-height:400px; }
.neoweaver-screen #tw-campaign-creator-container .tw-terminal-step { display:none; animation:twcm-scanline 0.3s ease-out; }
.neoweaver-screen #tw-campaign-creator-container .tw-terminal-step.active { display:block; }
@keyframes twcm-scanline { from{opacity:0;transform:translateY(10px);} to{opacity:1;transform:translateY(0);} }
.neoweaver-screen #tw-campaign-creator-container .tw-prompt { font-size:1.4rem; margin-bottom:50px; color:#adff00; text-transform:uppercase; letter-spacing:2px; }
.neoweaver-screen #tw-campaign-creator-container .tw-nav-btns { display:flex; justify-content:space-between; gap:20px; margin-top:60px; padding-top:20px; border-top:1px solid #111; }
.neoweaver-screen #tw-campaign-creator-container .tw-next-btn { background:#adff00; color:#000; border:none; padding:15px 40px; cursor:pointer; font-family:inherit; font-weight:bold; font-size:1rem; transition:0.3s; }
.neoweaver-screen #tw-campaign-creator-container .tw-back-btn { background:transparent; color:#444; border:1px solid #222; padding:15px 40px; cursor:pointer; font-family:inherit; transition:0.3s; }
.neoweaver-screen #tw-campaign-creator-container .tw-back-btn:hover { color:#adff00; border-color:#adff00; }
.neoweaver-screen #tw-campaign-creator-container .tw-next-btn:hover { background:#fff; box-shadow:0 0 20px #adff00; }
.neoweaver-screen #tw-campaign-creator-container .tw-term-input,.neoweaver-screen #tw-campaign-creator-container .tw-term-textarea,.neoweaver-screen #tw-campaign-creator-container .tw-term-select { background:#080808; border:1px solid #222; color:#adff00; padding:20px; width:100%; font-family:inherit; font-size:1.2rem; outline:none; }
.neoweaver-screen #tw-campaign-creator-container .tw-term-input:focus { border-color:#adff00; }
.neoweaver-screen #tw-campaign-creator-container .tw-image-selector { display:flex; gap:15px; }
.neoweaver-screen #tw-campaign-creator-container .tw-img-label { flex:1; border:1px solid #222; position:relative; cursor:pointer; height:350px; overflow:hidden; background:#000; }
.neoweaver-screen #tw-campaign-creator-container .tw-img-label img { width:100%; height:100%; object-fit:cover; opacity:0.25; transition:0.5s; }
.neoweaver-screen #tw-campaign-creator-container .tw-img-label input { display:none; }
.neoweaver-screen #tw-campaign-creator-container .tw-img-label:has(input:checked) { border-color:#adff00; box-shadow:0 0 25px rgba(173,255,0,0.2); }
.neoweaver-screen #tw-campaign-creator-container .tw-img-label:has(input:checked) img { opacity:1; }
.neoweaver-screen #tw-campaign-creator-container .tw-hover-label { position:absolute; bottom:0; background:rgba(0,0,0,0.85); color:#fff; width:100%; padding:15px; border-top:1px solid #222; pointer-events:none; }
.neoweaver-screen #tw-campaign-creator-container .tw-hover-label strong { color:#adff00; display:block; margin-bottom:5px; text-transform:uppercase; font-size:0.9rem; }
.neoweaver-screen #tw-campaign-creator-container .tw-hover-label p { font-size:0.75rem; margin:0; color:#ccc; line-height:1.4; }
.neoweaver-screen #tw-campaign-creator-container .tw-big-grid { display:grid; gap:20px; }
.neoweaver-screen #tw-campaign-creator-container .tw-big-grid-5 { grid-template-columns:repeat(5,1fr); }
.neoweaver-screen #tw-campaign-creator-container .tw-big-grid-2 { grid-template-columns:repeat(2,1fr); }
.neoweaver-screen #tw-campaign-creator-container .tw-big-card { cursor:pointer; }
.neoweaver-screen #tw-campaign-creator-container .tw-big-card input { display:none; }
.neoweaver-screen #tw-campaign-creator-container .tw-card-inner { border:1px solid #222; padding:20px; text-align:center; transition:0.3s; background:#050505; min-height:120px; display:flex; flex-direction:column; justify-content:center; }
.neoweaver-screen #tw-campaign-creator-container .tw-card-inner span { display:block; font-size:1.0rem; color:#adff00; margin-bottom:10px; }
.neoweaver-screen #tw-campaign-creator-container .tw-card-inner small { color:#555; font-size:0.8rem; }
.neoweaver-screen #tw-campaign-creator-container .tw-big-card input:checked + .tw-card-inner { border-color:#adff00; background:rgba(173,255,0,0.05); }
.neoweaver-screen #tw-campaign-creator-container .tw-select-pair { display:flex; gap:20px; flex-wrap:wrap; }
.neoweaver-screen #tw-campaign-creator-container .tw-select-pair .tw-term-select { flex:1; min-width:200px; }
.neoweaver-screen #tw-campaign-creator-container .tw-final-btn { background:#adff00; color:#000; border:none; padding:20px 60px; font-weight:bold; font-size:1.2rem; cursor:pointer; flex-grow:1; clip-path:polygon(0 0,100% 0,100% 70%,95% 100%,0 100%); }
.neoweaver-screen #tw-campaign-creator-container .glitch-active { animation:twcm-hard-glitch 0.25s steps(2) forwards; }
@keyframes twcm-hard-glitch { 0%{transform:translate(0);filter:brightness(1);} 20%{transform:translate(-5px,2px) skewX(5deg);filter:brightness(4) hue-rotate(90deg);} 40%{transform:translate(5px,-2px) skewY(-5deg);opacity:0.8;} 60%{transform:translate(-2px,5px);filter:contrast(2);} 80%{transform:translate(2px,-5px);opacity:0.5;} 100%{transform:translate(0);filter:brightness(1);} }
@media (max-width:900px) { .neoweaver-screen #tw-campaign-creator-container .tw-big-grid-5 { grid-template-columns:repeat(2,1fr); } }
@media (max-width:600px) { .neoweaver-screen #tw-campaign-creator-container .tw-big-grid-5 { grid-template-columns:1fr; } }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('tw-campaign-creator-container');
    if (!container) return;
    const bezel      = container.querySelector('.tw-screen-bezel');
    const audioClick = document.getElementById('tw-campaign-audio-click');
    const steps      = container.querySelectorAll('.tw-terminal-step');
    const totalSteps = steps.length;
    let currentStep  = 1;
    const ui = { fill: document.getElementById('tw-camp-progress-fill'), counter: document.getElementById('tw-camp-step-counter') };

    function triggerGlitch() {
        if (audioClick) { try { audioClick.currentTime=0; audioClick.volume=0.6; audioClick.play(); } catch(e){} }
        if (bezel) { bezel.classList.add('glitch-active'); setTimeout(() => bezel.classList.remove('glitch-active'), 250); }
    }
    function updateUI() {
        triggerGlitch();
        steps.forEach(s => s.classList.remove('active'));
        const active = container.querySelector('.tw-terminal-step[data-step="'+currentStep+'"]');
        if (active) { active.classList.add('active'); const inp = active.querySelector('input[type="text"],textarea'); if(inp) setTimeout(()=>inp.focus(),100); }
        if (ui.fill) ui.fill.style.width = (currentStep/totalSteps*100)+'%';
        if (ui.counter) ui.counter.innerText = String(currentStep).padStart(2,'0');
        const back = active ? active.querySelector('.tw-back-btn') : null;
        if (back) back.style.visibility = currentStep===1 ? 'hidden' : 'visible';
    }
    function validateStep(nextStep) {
        // Validate the step we are leaving (nextStep - 1)
        if (nextStep === 2 && !document.getElementById('c-name').value.trim()) {
            alert("DEPLOYMENT NAME REQUIRED_");
            return false;
        }
        return true;
    }
    function goNext() { const t=currentStep+1; if(t>totalSteps||!validateStep(t))return; currentStep=t; updateUI(); }
    function goBack() { if(currentStep<=1)return; currentStep--; updateUI(); }
    container.addEventListener('click', function(e) {
        if (e.target.closest('.tw-camp-confirm-btn')) { e.preventDefault(); goNext(); }
        else if (e.target.closest('.tw-back-btn')) { e.preventDefault(); goBack(); }
    });
    document.addEventListener('keydown', function(e) {
        const active = container.querySelector('.tw-terminal-step.active');
        if (!active) return;
        if (e.key==='Enter' && e.target.tagName!=='TEXTAREA') { e.preventDefault(); if(parseInt(active.getAttribute('data-step'))<totalSteps) goNext(); }
    });

    // ── BUG-FIX 7a: single sendCampaign definition.
    // ── BUG-FIX 7b: reads radio names that match the HTML (game_mode,
    //                world_type, gm_style, game_length, priority).
    // ── BUG-FIX 7c: skip guard uses !== '' (empty value attr) not a
    //                string that never matches the real placeholder text.
    async function sendCampaign() {
        const btn = document.getElementById('final-send');
        btn.disabled = true;
        btn.innerText = 'UPLOADING DATA...';

        const wVal = document.getElementById('c-world').value;
        const cVal = document.getElementById('c-char').value;

        const formData = new FormData();
        formData.append('name',        document.getElementById('c-name').value);
        formData.append('game_mode',   document.querySelector('input[name="game_mode"]:checked').value);
        formData.append('world_type',  document.querySelector('input[name="world_type"]:checked').value);
        formData.append('gm_style',    document.querySelector('input[name="gm_style"]:checked').value);
        formData.append('game_length', document.querySelector('input[name="game_length"]:checked').value);
        formData.append('priority',    document.querySelector('input[name="priority"]:checked').value);
        formData.append('customize',   document.getElementById('c-lore').value);
        formData.append('nonce',       '<?php echo esc_js( $campaign_nonce ); ?>');

        // BUG-FIX 7c: empty string is the placeholder value — treat it as "skip"
        if (wVal !== '') formData.append('world_id',     wVal);
        if (cVal !== '') formData.append('character_id', cVal);

        try {
            const res  = await fetch('/wp-json/neoweaver/v1/campaign/create', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                window.location.href = '/deployments/';
            } else {
                alert('Deployment failed: ' + (data.data?.message || 'Unknown error'));
                btn.disabled = false;
                btn.innerText = '[ INITIALIZE DEPLOYMENT ]';
            }
        } catch (e) {
            alert('CRITICAL ERROR DURING UPLOAD');
            btn.disabled = false;
            btn.innerText = '[ INITIALIZE DEPLOYMENT ]';
        }
    }

    const finalBtn = document.getElementById('final-send');
    if (finalBtn) finalBtn.addEventListener('click', function(e) { e.preventDefault(); sendCampaign(); });
    updateUI();
});
</script>
		<?php
		$html = ob_get_clean();
		return $this->screen( $html );
	}

	// =========================================================================
	// SHORTCODE: node / world creator
	// =========================================================================

	/**
	 * [tw_world_creator]
	 *
	 * Renders the 11-step Node (World) creation wizard.
	 *
	 * RENDER-ONLY. The form submits via fetch() to the theme endpoint at
	 * {stylesheet_dir}/endpoint/tw-endpoint-world.php.
	 *
	 * CSS scope:  .neoweaver-screen #tw-world-creator-container
	 * JS scope:   all querySelector calls rooted at #tw-world-creator-container
	 * Loading overlay (#tw-world-loading-overlay): position:fixed, outside the
	 *   monitor div, styled in assets/css/neoweaver-public.css (shared).
	 */
	public function shortcode_world_creator(): string {
		if ( ! is_user_logged_in() ) {
			return $this->screen( '<div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div>' );
		}

		$tw_world_nonce = wp_create_nonce( 'tw_world_nonce' );
		$tw_endpoint    = get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-world.php';

		ob_start();
		?>
<div id="tw-world-creator-container" class="tw-monitor-outer">
	<audio id="tw-audio-click" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/freesound_community-am-tuning-104200.mp3" preload="auto"></audio>
	<audio id="tw-audio-final" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/alex_kizenkov-aggressive-tech-cyber-logo-452884.mp3" preload="auto"></audio>

	<div class="tw-screen-bezel">
		<div class="tw-glitch-overlay"></div>
		<div class="tw-scanlines"></div>
		<div class="tw-static-noise"></div>

		<div class="tw-monitor-header">
			<div class="tw-header-left"><span class="tw-blink">●</span> NEO_WEAVER_PRO_V2_OS</div>
			<div class="tw-header-right">ARCHITECT_NODE: <span id="tw-world-step-counter">01</span>/11</div>
		</div>

		<div id="tw-world-progress-bar"><div id="tw-world-progress-fill"></div></div>

		<form id="tw-world-creator-form">

			<div class="tw-step active" data-step="1">
				<label class="tw-label">01. NODE_NAME</label>
				<input type="text" name="name" class="tw-terminal-input" placeholder="DESIGNATE NAME..." required autofocus>
				<button type="button" class="tw-confirm-btn" data-action="next">CONFIRM_NAME ↵</button>
			</div>

			<div class="tw-step" data-step="2">
				<label class="tw-label">02. CONCEPT_SEED</label>
				<input type="text" name="description" class="tw-terminal-input" placeholder="DESCRIBE ESSENCE..." required>
				<button type="button" class="tw-confirm-btn" data-action="next">CONFIRM_CONCEPT ↵</button>
			</div>

			<?php
			$world_steps = [
				3  => [ 'WORLD_SIZE',     'Define expansion magnitude',  [ ['Local Node','A single, dense micro-world.'], ['Few Nodes','A vast region.'], ['Multi Nodes','Full nodes simulation.'], ['World','Multiple systems.'], ['Infinite','Infinite reality stream.'] ],          'size'       ],
				4  => [ 'NODE_ECONOMY',   'Resource availability',       [ ['Frayed','Survival is a miracle.'], ['Scarcity','Basic scavenge economy.'], ['Balanced','Stable commerce.'], ['Wealthy','High consumerism.'], ['Abundant','Digital abundance.'] ],                       'wealth'     ],
				5  => [ 'ENTROPY_DANGER', 'Entropy & Threat Rate',       [ ['Coherent','Stable world.'], ['Stable','Manageable threats.'], ['Unstable','Standard risks.'], ['Critical','The Fray is strong.'], ['Catastrophic','Systemic collapse.'] ],                             'difficulty' ],
				6  => [ 'NODE_MAGIC',     'Weave Permeability',          [ ['None','Strict logic.'], ['Glitched','Rare anomalies.'], ['Standard','Standard utility.'], ['High','Reality is soft.'], ['Extreme','Chaos rules.'] ],                                                   'magic'      ],
				7  => [ 'NODE_GODS',      'Higher Protocols / Admins',   [ ['Absent','No entities.'], ['Echoes','Forgotten Admins.'], ['Observers','Silent code.'], ['Active','Demanding data.'], ['Manifested','God-AI active.'] ],                                                'gods'       ],
				8  => [ 'NODE_TECH',      'Technological Anchor',        [ ['Retro','Analog/CRT, late \'90.'], ['Modern','Networked. Today'], ['Advanced','Cybernetics. Tomorrow'], ['Future','Sentient AI. Close future'], ['Transcendent','Post-human. Apocalyptic future'] ],    'technology' ],
				9  => [ 'NODE_SOCIAL',    'Thread interaction',          [ ['Hostile','Tribal survival.'], ['Strained','Faction tension.'], ['Pragmatic','Uneasy peace.'], ['Integrated','Common goals.'], ['Unified','Hive-mind.'] ],                                               'relations'  ],
				10 => [ 'NODE_MORALITY',  'Ethical Framework',           [ ['Chaotic','Fittest survives.'], ['Gray','Ambiguity.'], ['Lawful','Strict codes.'] ],                                                                                                                     'moral'      ],
			];
			foreach ( $world_steps as $num => $s ) :
				$step_num    = sprintf( '%02d', $num );
				$field_name  = $s[3];
				?>
			<div class="tw-step" data-step="<?php echo $num; ?>">
				<label class="tw-label"><?php echo $step_num; ?>. <?php echo esc_html( $s[0] ); ?></label>
				<div class="tw-tiles-container">
					<?php foreach ( $s[2] as $idx => $option ) : ?>
					<label class="tw-tile" data-tooltip="<?php echo esc_attr( $option[1] ); ?>">
						<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo $idx + 1; ?>" required>
						<div class="tw-tile-content">
							<span class="tw-tile-num"><?php echo $idx + 1; ?></span>
							<span class="tw-tile-label"><?php echo esc_html( $option[0] ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>
				<div class="tw-desc-box">SELECT OPTION TO ANALYZE...</div>
				<button type="button" class="tw-confirm-btn" data-action="next" style="display:none;">CONFIRM_SELECTION ↵</button>
			</div>
			<?php endforeach; ?>

			<div class="tw-step" data-step="11">
				<label class="tw-label">11. FINAL_ANOMALIES</label>
				<textarea name="customize" class="tw-terminal-textarea" placeholder="Inject specific secrets..."></textarea>
				<button type="submit" id="tw-submit-world" class="tw-btn-submit">DEPLO_REALITY</button>
			</div>

			<div class="tw-footer">
				<button type="button" id="tw-back-btn" class="tw-nav-btn" style="visibility:hidden;">[ BACK ]</button>
			</div>

		</form>
	</div>
</div>

<!-- Loading overlay — position:fixed, sits at body level outside the monitor -->
<div id="tw-world-loading-overlay">
	<div class="tw-loading-core">
		<div class="tw-loading-ring"></div>
		<div class="tw-loading-ring tw-loading-ring-2"></div>
		<div class="tw-loading-text">
			STITCHING REALITY...<br>
			<span class="tw-loading-sub">Syncing Node with NeoWeave Cluster</span>
		</div>
	</div>
</div>

<!-- =====================================================================
     SCOPED STYLES for Node creator.
     Scope: .neoweaver-screen #tw-world-creator-container
     Shared rules (overflow:visible, height:auto, loading overlay base)
     live in assets/css/neoweaver-public.css and are NOT repeated here.
     The loading overlay (#tw-world-loading-overlay) gets its own block
     below because it is position:fixed at body level.
     ===================================================================== -->
<style>
@import url('https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&display=swap');

/* ── CSS custom properties ── */
.neoweaver-screen #tw-world-creator-container {
    --twnc-neon:     #adff00;
    --twnc-dim:      rgba(173,255,0,0.2);
    --twnc-bg:       #000b14;
}

/* ── Monitor shell ── */
.neoweaver-screen #tw-world-creator-container.tw-monitor-outer {
    max-width: 850px;
    margin: 0 auto;
    background: #000;
    padding: 12px;
    border-radius: 15px;
    border: 4px solid #1a1a1a;
    box-shadow: 0 0 40px rgba(0,0,0,1);
}

/* ── Screen bezel — shared CSS handles overflow:visible & padding-bottom ── */
.neoweaver-screen #tw-world-creator-container .tw-screen-bezel {
    background: var(--twnc-bg);
    padding: 40px;
    border-radius: 5px;
    border: 1px solid #111;
    box-shadow: inset 0 0 80px rgba(0,0,0,0.9);
}

/* ── Atmospheric overlays ── */
.neoweaver-screen #tw-world-creator-container .tw-scanlines {
    position: absolute; top:0; left:0; width:100%; height:100%;
    background: linear-gradient(rgba(18,16,16,0) 50%, rgba(0,0,0,0.2) 50%);
    background-size: 100% 4px; z-index: 20; pointer-events: none; opacity: 0.6;
}
.neoweaver-screen #tw-world-creator-container .tw-static-noise {
    position: absolute; inset:0; opacity:0.05; z-index:15; pointer-events:none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
}
.neoweaver-screen #tw-world-creator-container .tw-glitch-overlay {
    position: absolute; top:0; left:0; width:100%; height:100%;
    background: var(--twnc-neon); opacity:0; z-index:25; pointer-events:none;
}

/* ── Header & progress ── */
.neoweaver-screen #tw-world-creator-container .tw-monitor-header {
    display: flex; justify-content: space-between; color: var(--twnc-neon);
    font-size: 0.8rem; margin-bottom: 25px;
    border-bottom: 1px solid var(--twnc-dim); padding-bottom: 5px; font-weight: bold;
}
.neoweaver-screen #tw-world-creator-container .tw-blink { animation: twnc-blink 1s infinite alternate; }
@keyframes twnc-blink { from{opacity:0.3;} to{opacity:1;} }
.neoweaver-screen #tw-world-creator-container #tw-world-progress-bar {
    height: 2px; background: rgba(255,255,255,0.05); margin-bottom: 30px;
}
.neoweaver-screen #tw-world-creator-container #tw-world-progress-fill {
    height: 100%; background: var(--twnc-neon); width: 0%;
    box-shadow: 0 0 15px var(--twnc-neon); transition: 0.4s;
}

/* ── Step visibility — shared CSS handles .active display:block ── */
.neoweaver-screen #tw-world-creator-container .tw-step { display: none; }

/* ── Labels & inputs ── */
.neoweaver-screen #tw-world-creator-container .tw-label {
    color: var(--twnc-neon); font-size: 1.6rem; display: block; margin-bottom: 20px;
    text-shadow: 0 0 12px var(--twnc-dim);
}
.neoweaver-screen #tw-world-creator-container .tw-terminal-input,
.neoweaver-screen #tw-world-creator-container .tw-terminal-textarea {
    width: 100%; background: rgba(0,0,0,0.8); border: 1px solid var(--twnc-dim);
    padding: 15px; color: #fff; font-family: 'Chakra Petch'; font-size: 1.3rem; outline: none;
}
.neoweaver-screen #tw-world-creator-container .tw-terminal-input:focus { border-color: var(--twnc-neon); }

/* ── Tile grid ── */
.neoweaver-screen #tw-world-creator-container .tw-tiles-container {
    display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 20px;
}
.neoweaver-screen #tw-world-creator-container .tw-tile-content {
    border: 1px solid var(--twnc-dim); padding: 15px 5px; text-align: center;
    background: rgba(0,0,0,0.5); transition: 0.2s;
}
.neoweaver-screen #tw-world-creator-container .tw-tile-num {
    display: block; font-size: 1.6rem; font-weight: bold; color: var(--twnc-dim);
}
.neoweaver-screen #tw-world-creator-container .tw-tile-label {
    font-size: 0.7rem; color: #fff; text-transform: uppercase;
}
.neoweaver-screen #tw-world-creator-container .tw-tile input:checked + .tw-tile-content {
    border-color: var(--twnc-neon); background: rgba(173,255,0,0.1);
    box-shadow: 0 0 20px var(--twnc-dim);
}
.neoweaver-screen #tw-world-creator-container .tw-tile input:checked + .tw-tile-content .tw-tile-num {
    color: var(--twnc-neon);
}
.neoweaver-screen #tw-world-creator-container .tw-tile input { display: none; }

/* ── Description box ── */
.neoweaver-screen #tw-world-creator-container .tw-desc-box {
    font-size: 1rem; color: #00eaff; min-height: 50px; padding: 15px;
    border-left: 3px solid var(--twnc-neon); background: rgba(0,0,0,0.5); margin-bottom: 15px;
}

/* ── Buttons ── */
.neoweaver-screen #tw-world-creator-container .tw-confirm-btn {
    width: 100%; background: none; border: 1px solid var(--twnc-neon);
    color: var(--twnc-neon); padding: 15px; cursor: pointer;
    font-family: 'Chakra Petch'; font-weight: bold; font-size: 1rem;
}
.neoweaver-screen #tw-world-creator-container .tw-confirm-btn:hover { background: var(--twnc-neon); color: #000; }
.neoweaver-screen #tw-world-creator-container .tw-btn-submit {
    width: 100%; background: var(--twnc-neon); color: #000;
    padding: 20px; font-weight: bold; font-size: 1.4rem; border: none; cursor: pointer;
}
.neoweaver-screen #tw-world-creator-container .tw-nav-btn {
    background: none; border: 1px solid var(--twnc-dim); color: var(--twnc-dim);
    cursor: pointer; font-family: 'Chakra Petch'; padding: 5px 15px; margin-top: 15px;
}

/* ── Glitch animation ── */
.neoweaver-screen #tw-world-creator-container .glitch-active {
    animation: twnc-hard-glitch 0.25s steps(2) forwards;
}
@keyframes twnc-hard-glitch {
    0%   { transform: translate(0);                         filter: brightness(1); }
    20%  { transform: translate(-5px,2px) skewX(5deg);      filter: brightness(4) hue-rotate(90deg); }
    40%  { transform: translate(5px,-2px) skewY(-5deg);     opacity: 0.8; }
    60%  { transform: translate(-2px,5px);                  filter: contrast(2); }
    80%  { transform: translate(2px,-5px);                  opacity: 0.5; }
    100% { transform: translate(0);                         filter: brightness(1); }
}

/* ── Loading overlay (fixed/full-screen, outside .neoweaver-screen) ── */
#tw-world-loading-overlay {
    position: fixed; inset: 0;
    background: radial-gradient(circle at top, rgba(0,255,128,0.15), rgba(0,0,0,0.95));
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.4s ease; z-index: 9999;
}
#tw-world-loading-overlay.active { opacity: 1; pointer-events: all; }
#tw-world-loading-overlay .tw-loading-core {
    text-align: center; color: var(--twnc-neon, #adff00); font-family: 'Chakra Petch', sans-serif;
}
#tw-world-loading-overlay .tw-loading-ring,
#tw-world-loading-overlay .tw-loading-ring-2 {
    width: 140px; height: 140px; border-radius: 50%;
    border: 3px solid rgba(173,255,0,0.2); border-top-color: #adff00;
    margin: 0 auto 20px; animation: twnc-spin 1.2s linear infinite;
    box-shadow: 0 0 25px rgba(173,255,0,0.4);
}
#tw-world-loading-overlay .tw-loading-ring-2 {
    width: 180px; height: 180px; border-top-color: #00e5ff; border-bottom-color: transparent;
    animation-duration: 1.8s; animation-direction: reverse; margin-bottom: 30px;
}
#tw-world-loading-overlay .tw-loading-text {
    font-size: 1rem; letter-spacing: 0.12em; text-transform: uppercase;
}
#tw-world-loading-overlay .tw-loading-sub {
    display: block; font-size: 0.8rem; color: #00e5ff; margin-top: 6px;
}
@keyframes twnc-spin { from{transform:rotate(0deg);} to{transform:rotate(360deg);} }
</style>

<script>
// Endpoint and nonce — echoed from PHP, consumed by the submit handler below.
const twWorldCreatorData = {
    endpoint: '<?php echo esc_js( $tw_endpoint ); ?>',
    nonce:    '<?php echo esc_js( $tw_world_nonce ); ?>'
};

document.addEventListener('DOMContentLoaded', function () {
    // ── Scope all DOM queries to the world-creator container so they never
    //    accidentally grab elements from other wizards on the same page. ──
    const container  = document.getElementById('tw-world-creator-container');
    if (!container) return;

    const form       = container.querySelector('#tw-world-creator-form');
    const bezel      = container.querySelector('.tw-screen-bezel');
    const audioClick = document.getElementById('tw-audio-click');
    const audioFinal = document.getElementById('tw-audio-final');

    const state = {
        currentStep: 1,
        steps:       form.querySelectorAll('.tw-step'),
        totalSteps:  11
    };

    const ui = {
        fill:    document.getElementById('tw-world-progress-fill'),
        counter: document.getElementById('tw-world-step-counter'),
        backBtn: document.getElementById('tw-back-btn'),
        overlay: document.getElementById('tw-world-loading-overlay')
    };

    // ── Glitch helper ──
    function triggerGlitch() {
        if (audioClick) {
            try { audioClick.currentTime = 0; audioClick.volume = 0.5; audioClick.play(); } catch(e){}
        }
        if (bezel) {
            bezel.classList.add('glitch-active');
            setTimeout(() => bezel.classList.remove('glitch-active'), 250);
        }
    }

    // ── Render current step ──
    function updateUI() {
        triggerGlitch();
        state.steps.forEach(s => s.classList.remove('active'));
        const activeStep = form.querySelector('.tw-step[data-step="' + state.currentStep + '"]');
        if (activeStep) {
            activeStep.classList.add('active');
            const inp = activeStep.querySelector('input[type="text"], textarea');
            if (inp) setTimeout(() => inp.focus(), 100);
        }
        if (ui.fill)    ui.fill.style.width  = (state.currentStep / state.totalSteps * 100) + '%';
        if (ui.counter) ui.counter.innerText = String(state.currentStep).padStart(2, '0');
        if (ui.backBtn) ui.backBtn.style.visibility = state.currentStep > 1 ? 'visible' : 'hidden';
    }

    // ── Validation + advance ──
    function goToNextStep() {
        const activeStep = form.querySelector('.tw-step[data-step="' + state.currentStep + '"]');
        if (!activeStep) return;

        let valid = true;
        const radios = activeStep.querySelectorAll('input[type="radio"][required]');
        if (radios.length > 0 && !activeStep.querySelector('input[type="radio"]:checked')) {
            valid = false;
        }
        activeStep.querySelectorAll('input[type="text"][required], textarea[required]').forEach(inp => {
            if (!inp.value.trim()) valid = false;
        });

        if (valid) {
            if (state.currentStep < state.totalSteps) {
                state.currentStep++;
                updateUI();
            }
        } else {
            activeStep.querySelectorAll('.tw-terminal-input, .tw-terminal-textarea, .tw-tile-content')
                .forEach(el => {
                    el.style.borderColor = 'red';
                    setTimeout(() => el.style.borderColor = '', 500);
                });
        }
    }

    // ── Tile selection: show description + reveal Confirm button ──
    form.addEventListener('change', (e) => {
        const tile = e.target.closest('.tw-tile');
        const step = e.target.closest('.tw-step');
        if (tile && step) {
            const label       = tile.querySelector('.tw-tile-label') ? tile.querySelector('.tw-tile-label').innerText : '';
            const desc        = tile.dataset.tooltip || '';
            const box         = step.querySelector('.tw-desc-box');
            if (box) box.innerHTML = '<strong>' + label + ':</strong> ' + desc;
            const confirmBtn  = step.querySelector('.tw-confirm-btn');
            if (confirmBtn) confirmBtn.style.display = 'block';
        }
    });

    // ── Next button (delegated, scoped to container) ──
    form.addEventListener('click', (e) => {
        const nextBtn = e.target.closest('[data-action="next"]');
        if (nextBtn) { e.preventDefault(); goToNextStep(); }
    });

    // ── Back button ──
    if (ui.backBtn) {
        ui.backBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (state.currentStep > 1) { state.currentStep--; updateUI(); }
        });
    }

    // ── Enter key advances (skip in textarea) ──
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            if (e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();
            if (state.currentStep < state.totalSteps) goToNextStep();
        }
    });

    // ── Submit: POST to tw-endpoint-world.php (no admin-ajax) ──
    form.onsubmit = async (e) => {
        e.preventDefault();
        const btn          = document.getElementById('tw-submit-world');
        const originalText = btn.innerText;
        btn.innerText = 'TRANSMITTING TO THE WEAVE...';
        btn.disabled  = true;
        if (ui.overlay) ui.overlay.classList.add('active');

        const formData = new FormData(form);
        formData.append('nonce', twWorldCreatorData.nonce);

        try {
            const response = await fetch(twWorldCreatorData.endpoint, { method: 'POST', body: formData });

            // Read as text first for easier debugging of non-JSON errors.
            const text = await response.text();
            console.log('RAW TEXT FROM ENDPOINT:', text);

            let json;
            try {
                json = JSON.parse(text);
            } catch (parseErr) {
                console.error('JSON parse error:', parseErr);
                alert('Endpoint returned non-JSON. Check server error logs.');
                btn.innerText = originalText; btn.disabled = false;
                if (ui.overlay) ui.overlay.classList.remove('active');
                return;
            }

            console.log('PARSED JSON FROM ENDPOINT:', json);

            if (json.success) {
                if (audioFinal) {
                    try { audioFinal.currentTime = 0; audioFinal.volume = 0.7; audioFinal.play(); } catch(e){}
                }
                const worldId = json.data && json.data.worldid ? json.data.worldid : '';
                setTimeout(() => {
                    window.location.href = 'https://cyber.nieodparady.pl/nodes/?status=initializing&world_id=' + worldId;
                }, 2000);
            } else {
                const msg = json.data && json.data.message ? json.data.message : 'Unknown error';
                alert('Deployment failed: ' + msg);
                if (json.data && json.data.supabase_body) {
                    console.log('Supabase body:', json.data.supabase_body);
                }
                btn.innerText = originalText; btn.disabled = false;
                if (ui.overlay) ui.overlay.classList.remove('active');
            }
        } catch (err) {
            console.error('Endpoint error:', err);
            alert('Network link severed.');
            btn.innerText = originalText; btn.disabled = false;
            if (ui.overlay) ui.overlay.classList.remove('active');
        }
    };

    updateUI();
});
</script>
		<?php
		$html = ob_get_clean();
		return $this->screen( $html );
	}

public function shortcode_active_node(): string {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return '<span id="node-name-display">NO_UPLINK</span>';

    return '<span id="node-name-display" data-wp-user-id="' . esc_attr( $user_id ) . '">LOADING_NODE...</span>';
}
	// =========================================================================
// FOOTER SCRIPT: Quick Actions Bridge (game page only)
// =========================================================================

/**
 * Outputs the twQuickActionsBridge script in wp_footer,
 * only on the main game page (ID 2857).
 * deck-core calls window.twQuickActionsBridge.updateFromCards(cards)
 * instead of calling twUpdatePlayerTags directly.
 */
public function enqueue_quick_actions_bridge(): void {
    if ( ! is_page( 2857 ) ) {
        return;
    }
    ?>
    <script>
    (function () {
        function updateQuickActionsFromHand(cards) {
            const tags = (cards || []).flatMap((c) =>
                (c.tags || '')
                    .split(',')
                    .map((t) => t.trim())
                    .filter(Boolean)
            );
            if (window.twUpdatePlayerTags) {
                window.twUpdatePlayerTags(tags);
            } else {
                console.warn('twUpdatePlayerTags is not defined – quick actions bridge has nothing to call.');
            }
        }
        window.twQuickActionsBridge = {
            updateFromCards: updateQuickActionsFromHand,
        };
    })();
    </script>
    <?php
}
}
