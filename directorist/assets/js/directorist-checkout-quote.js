(function () {
	'use strict';

	function syncGatewayQuote(form) {
		const selected = form.querySelector('input[name="payment_gateway"]:checked');
		const tokenField = form.elements.namedItem('checkout_quote_token');

		if (!selected || !tokenField) {
			return;
		}

		const gateway = selected.value;
		const quote = Array.from(form.querySelectorAll('[data-quote-gateway]')).find(
			(input) => input.dataset.quoteGateway === gateway
		);
		const conversionRow = form.querySelector('[data-checkout-conversion-row]');

		tokenField.value = quote ? quote.value : '';
		if (conversionRow) {
			conversionRow.hidden = !quote || quote.hasAttribute('data-hide-conversion');
		}

		form.querySelectorAll('[data-checkout-gateway]').forEach((row) => {
			row.hidden = row.dataset.checkoutGateway !== gateway;
		});
	}

	document.addEventListener('change', (event) => {
		const field = event.target;
		if (field.name === 'payment_gateway' && field.form && field.form.id === 'atbdp-checkout-form') {
			syncGatewayQuote(field.form);
		}
	});

	// Refresh immediately before the checkout script serializes the form.
	document.addEventListener('submit', (event) => {
		if (event.target.id === 'atbdp-checkout-form') {
			syncGatewayQuote(event.target);
		}
	}, true);
})();
