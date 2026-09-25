/**
 * CRM admin: számlatételek (sor hozzáadás/törlés, összegek élőben, ár a katalógusból).
 */
(function () {
	'use strict';

	var table = document.querySelector('[data-hpv-items]');
	if (!table) {
		return;
	}
	var body = table.querySelector('tbody');
	var taxInput = document.getElementById('hpv-f-tax_rate');

	function cents(value) {
		var n = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
		return isNaN(n) ? 0 : Math.round(n * 100);
	}

	function money(c) {
		return (c < 0 ? '-$' : '$') + (Math.abs(c) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function renumber() {
		Array.prototype.forEach.call(body.rows, function (row, i) {
			row.querySelectorAll('input').forEach(function (input) {
				input.name = input.name.replace(/items\[\d+\]/, 'items[' + i + ']');
			});
		});
	}

	function recalc() {
		var subtotal = 0;
		Array.prototype.forEach.call(body.rows, function (row) {
			var amount = Math.round(cents(row.querySelector('[data-qty]').value) * cents(row.querySelector('[data-price]').value) / 100);
			row.querySelector('[data-amount]').textContent = money(amount);
			subtotal += amount;
		});
		var tax = Math.round(subtotal * (parseFloat(taxInput ? taxInput.value : 0) || 0) / 100);
		table.querySelector('[data-subtotal]').textContent = money(subtotal);
		table.querySelector('[data-tax]').textContent = money(tax);
		table.querySelector('[data-total]').textContent = money(subtotal + tax);
	}

	table.addEventListener('click', function (e) {
		if (e.target.closest('[data-add-item]')) {
			var row = body.rows[body.rows.length - 1].cloneNode(true);
			row.querySelectorAll('input').forEach(function (input) {
				input.value = input.hasAttribute('data-qty') ? '1' : '';
			});
			body.appendChild(row);
			renumber();
			recalc();
			row.querySelector('input').focus();
		}
		if (e.target.closest('[data-remove]')) {
			if (body.rows.length > 1) {
				e.target.closest('tr').remove();
			} else {
				body.rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
			}
			renumber();
			recalc();
		}
	});

	// Ha a tétel neve egy katalógus-szolgáltatás, az egységár kitöltődik.
	table.addEventListener('change', function (e) {
		if (e.target.name && /\[description\]$/.test(e.target.name)) {
			var option = document.querySelector('#hpv-services option[value="' + CSS.escape(e.target.value) + '"]');
			var price = e.target.closest('tr').querySelector('[data-price]');
			if (option && !price.value) {
				price.value = option.getAttribute('data-price');
			}
		}
		recalc();
	});
	table.addEventListener('input', recalc);
	if (taxInput) {
		taxInput.addEventListener('input', recalc);
	}
	recalc();
})();
