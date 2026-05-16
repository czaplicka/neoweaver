document.addEventListener('DOMContentLoaded', function () {
	const root = document.getElementById('tw-onboarding-slider');
	if (!root) return;

	const toggle = root.querySelector('.tw-onboarding-slider__toggle');
	const panel = root.querySelector('.tw-onboarding-slider__panel');
	const dismiss = root.querySelector('.tw-onboarding-slider__dismiss');

	if (toggle && panel) {
		toggle.addEventListener('click', function () {
			const collapsed = root.classList.toggle('is-collapsed');

			toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			toggle.setAttribute(
				'aria-label',
				collapsed ? 'Open onboarding' : 'Collapse onboarding'
			);
		});
	}

	if (dismiss) {
		dismiss.addEventListener('click', function () {
			root.classList.add('is-dismissed');
		});
	}
});
