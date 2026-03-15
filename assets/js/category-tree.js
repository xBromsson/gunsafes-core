(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('.gsct-tree .gsct-toggle');

		if (!toggle) {
			return;
		}

		event.preventDefault();

		var panelId = toggle.getAttribute('data-panel-id');
		if (!panelId) {
			return;
		}

		var panel = document.getElementById(panelId);
		if (!panel) {
			return;
		}

		var isOpen = toggle.getAttribute('aria-expanded') === 'true';
		var nextState = !isOpen;

		toggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
		panel.hidden = !nextState;

		var icon = toggle.querySelector('.gsct-icon');
		if (icon) {
			icon.textContent = nextState ? '-' : '+';
		}

		var item = toggle.closest('.gsct-item');
		if (item) {
			item.classList.toggle('is-open', nextState);
		}
	});
})();
