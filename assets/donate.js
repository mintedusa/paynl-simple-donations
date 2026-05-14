/* PAY.nl Simple Donations — frontend interactions */
(function () {
	'use strict';

	function init(wrap) {
		const form        = wrap.querySelector('.paynl-donate-form');
		const amtButtons  = wrap.querySelectorAll('.paynl-amt-btn');
		const customWrap  = wrap.querySelector('.paynl-custom-wrap');
		const customInput = wrap.querySelector('input[name="custom_amount"]');
		const emailInput  = wrap.querySelector('input[name="email"]');
		const nameInput   = wrap.querySelector('input[name="name"]');
		const submitBtn   = wrap.querySelector('.paynl-submit');
		const errorBox    = wrap.querySelector('.paynl-error');
		const descInput   = wrap.querySelector('input[name="description"]');

		let selectedAmount = null;

		// Pre-select the 2nd preset if it exists (visual default)
		const preselected = wrap.querySelector('.paynl-amt-btn.is-selected');
		if (preselected && preselected.dataset.amount !== 'custom') {
			selectedAmount = parseFloat(preselected.dataset.amount);
		}

		// Amount button selection
		amtButtons.forEach(btn => {
			btn.addEventListener('click', () => {
				amtButtons.forEach(b => {
					b.classList.remove('is-selected');
					b.setAttribute('aria-pressed', 'false');
				});
				btn.classList.add('is-selected');
				btn.setAttribute('aria-pressed', 'true');

				if (btn.dataset.amount === 'custom') {
					if (customWrap) {
						customWrap.hidden = false;
						setTimeout(() => customInput && customInput.focus(), 50);
					}
					selectedAmount = null; // will be read from input on submit
				} else {
					if (customWrap) customWrap.hidden = true;
					if (customInput) customInput.value = '';
					selectedAmount = parseFloat(btn.dataset.amount);
				}
				hideError();
			});
		});

		function getAmount() {
			const customBtn = wrap.querySelector('.paynl-amt-btn[data-amount="custom"].is-selected');
			if (customBtn && customInput) {
				const v = parseFloat(customInput.value);
				return isFinite(v) && v > 0 ? v : null;
			}
			return selectedAmount;
		}

		function showError(msg) {
			if (!errorBox) return;
			errorBox.textContent = msg;
			errorBox.hidden = false;
		}

		function hideError() {
			if (!errorBox) return;
			errorBox.hidden = true;
			errorBox.textContent = '';
		}

		// Form submit
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			hideError();

			const amount = getAmount();
			if (!amount || amount <= 0) {
				showError(paynlDonate.i18n.invalidAmount);
				return;
			}

			if (emailInput && emailInput.required) {
				const email = emailInput.value.trim();
				if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
					showError(paynlDonate.i18n.invalidEmail);
					emailInput.focus();
					return;
				}
			}

			if (nameInput && nameInput.required && !nameInput.value.trim()) {
				showError(paynlDonate.i18n.invalidName);
				nameInput.focus();
				return;
			}

			submitBtn.disabled = true;
			const originalLabel = submitBtn.textContent;
			submitBtn.textContent = paynlDonate.i18n.processing;

			const body = new URLSearchParams();
			body.set('action', 'paynl_create_order');
			body.set('nonce', paynlDonate.nonce);
			body.set('amount', amount);
			if (emailInput) body.set('email', emailInput.value.trim());
			if (nameInput)  body.set('name',  nameInput.value.trim());
			if (descInput)  body.set('description', descInput.value);

			try {
				const res = await fetch(paynlDonate.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				});

				const data = await res.json();

				if (data && data.success && data.data && data.data.checkoutUrl) {
					window.location.href = data.data.checkoutUrl;
					return; // do not re-enable button — we're navigating away
				}

				const msg = (data && data.data && data.data.message) || paynlDonate.i18n.genericError;
				showError(msg);
			} catch (err) {
				console.error('PayNL donation error:', err);
				showError(paynlDonate.i18n.genericError);
			}

			submitBtn.disabled = false;
			submitBtn.textContent = originalLabel;
		});
	}

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('.paynl-donate-wrap').forEach(init);
	});
})();
