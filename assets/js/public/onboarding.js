document.addEventListener('DOMContentLoaded', function () {
	const root = document.getElementById('tw-onboarding-slider');
	if (!root) return;

	const toggle = root.querySelector('.tw-onboarding-slider__toggle');
	const panel = root.querySelector('.tw-onboarding-slider__panel');
	const config = window.twOnboardingSlider || {};
	const labels = config.labels || {};

	if (toggle && panel) {
		toggle.addEventListener('click', function () {
			const collapsed = root.classList.toggle('is-collapsed');
			toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			toggle.setAttribute(
				'aria-label',
				collapsed ? (labels.collapsed || 'Open onboarding') : (labels.expanded || 'Collapse onboarding')
			);
		});
	}

	const steps = (config.steps || {});
	Object.keys(steps).forEach(function (key) {
		const stepConfig = steps[key];
		const item = root.querySelector('.tw-onboarding-slider__item[data-step="' + key + '"]');
		if (!item) return;

		if (stepConfig && stepConfig.completed) {
			item.classList.add('is-done');
		}
	});
});
