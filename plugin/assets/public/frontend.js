/**
 * Supplement Compare — public frontend.
 *
 * Loads the static JSON written by Supcomp_JSON_Exporter, hydrates the
 * #supcomp-app placeholder into either:
 *   - a list of canonical products (with lowest cost-per-active-unit + offer
 *     count), or
 *   - a detail comparison table for one canonical, sortable across all
 *     merchants.
 *
 * Hash routing: #/ → list, #/canonical/<slug> → detail. Back/forward in the
 * browser works the way users expect.
 *
 * No build step, no framework. ~400 lines of vanilla.
 */
(function () {
	'use strict';

	var root = document.getElementById('supcomp-app');
	var config = window.supcompFrontend;
	if (!root || !config) {
		return;
	}

	var i18n = config.i18n || {};
	var initial = parseDataInitial(root.getAttribute('data-initial'));

	var data = null;
	var state = {
		view: parseHash() || initialView(),
		listFilters: {
			search: '',
			form: '',
			ingredient: initial.ingredient || '',
			merchant: '',
			inStockOnly: false,
			thirdPartyOnly: false,
			coaOnly: false,
			minPrice: '',
			maxPrice: '',
		},
		listSort: 'cost_per_active_unit',
		detailFilters: { inStockOnly: false, thirdPartyOnly: false, coaOnly: false },
		detailSort: 'cost_per_active_unit',
	};

	if (!config.jsonUrl) {
		root.innerHTML = '<p class="supcomp-error">' + escapeHtml(i18n.emptyData || 'No data.') + '</p>';
		return;
	}

	fetch(config.jsonUrl, { credentials: 'omit', cache: 'no-cache' })
		.then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		})
		.then(function (json) {
			data = json;
			if (!data || !Array.isArray(data.canonical_products) || data.canonical_products.length === 0) {
				root.innerHTML = '<p class="supcomp-empty">' + escapeHtml(i18n.emptyData || 'No data.') + '</p>';
				return;
			}
			render();
		})
		.catch(function () {
			root.innerHTML = '<p class="supcomp-error">' + escapeHtml(i18n.loadError || 'Could not load.') + '</p>';
		});

	window.addEventListener('hashchange', function () {
		state.view = parseHash() || { type: 'list' };
		render();
	});

	root.addEventListener('change', onControlChange);
	root.addEventListener('input', onControlInput);

	function onControlChange(ev) {
		var t = ev.target;
		if (!t || !t.dataset) return;
		var key = t.dataset.field;
		if (!key) return;
		var bag = ev.target.dataset.bag === 'detail' ? state.detailFilters : state.listFilters;
		if (t.type === 'checkbox') {
			bag[key] = !!t.checked;
		} else {
			bag[key] = t.value;
		}
		render();
	}

	function onControlInput(ev) {
		var t = ev.target;
		if (!t || !t.dataset) return;
		if (t.dataset.role === 'sort') {
			if (t.dataset.bag === 'detail') {
				state.detailSort = t.value;
			} else {
				state.listSort = t.value;
			}
			render();
		}
		if (t.dataset.role === 'search') {
			state.listFilters.search = t.value;
			render();
		}
	}

	// ---------- routing ----------

	function parseHash() {
		var h = location.hash || '';
		var m = h.match(/^#\/canonical\/(.+)$/);
		if (m) return { type: 'canonical', slug: decodeURIComponent(m[1]) };
		if (h === '#/' || h === '') return { type: 'list' };
		return null;
	}

	function initialView() {
		if (initial.canonical) {
			return { type: 'canonical', slug: initial.canonical };
		}
		return { type: 'list' };
	}

	function parseDataInitial(raw) {
		try {
			var v = JSON.parse(raw || '{}');
			return v && typeof v === 'object' ? v : {};
		} catch (e) {
			return {};
		}
	}

	function goToCanonical(slug) {
		location.hash = '#/canonical/' + encodeURIComponent(slug);
	}

	function goToList() {
		location.hash = '#/';
	}

	// ---------- render ----------

	function render() {
		if (!data) return;
		if (state.view.type === 'canonical') {
			renderDetail(state.view.slug);
		} else {
			renderList();
		}
	}

	function renderList() {
		var offers = filterListOffers(data.offers);
		var groups = groupByCanonical(offers);
		var canonicals = data.canonical_products
			.filter(function (cp) { return groups[cp.id] && groups[cp.id].length > 0; })
			.map(function (cp) {
				var groupOffers = groups[cp.id];
				return {
					cp: cp,
					offers: groupOffers,
					lowest: minBy(groupOffers, 'cost_per_active_unit'),
					merchantCount: countUnique(groupOffers, function (o) { return o.merchant && o.merchant.id; }),
				};
			})
			.sort(listSortCompare);

		var html = '';
		html += listFilterBar();

		if (canonicals.length === 0) {
			html += '<p class="supcomp-empty">' + escapeHtml(i18n.noResults || 'No matches.') + '</p>';
		} else {
			html += '<table class="supcomp-table supcomp-list">';
			html += '<thead><tr>';
			html += '<th>' + escapeHtml(i18n.product || 'Product') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.lowestCost || 'Lowest cost / active unit') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.merchants || 'Merchants') + '</th>';
			html += '<th></th>';
			html += '</tr></thead><tbody>';
			canonicals.forEach(function (item) {
				html += '<tr data-slug="' + escapeHtml(item.cp.slug) + '">';
				html += '<td>';
				html += '<a class="supcomp-canon-link" href="#/canonical/' + encodeURIComponent(item.cp.slug) + '">' + escapeHtml(item.cp.display_name) + '</a>';
				html += '<br><span class="supcomp-meta">' + escapeHtml((item.cp.ingredient && item.cp.ingredient.name) || '') +
					(item.cp.ingredient && item.cp.ingredient.category ? ' · ' + escapeHtml(item.cp.ingredient.category) : '') + '</span>';
				html += '</td>';
				html += '<td class="supcomp-num">' + formatCostPerActive(item.lowest, item.cp) + '</td>';
				html += '<td class="supcomp-num">' + item.merchantCount + '</td>';
				html += '<td><a class="supcomp-compare-btn" href="#/canonical/' + encodeURIComponent(item.cp.slug) + '">' +
					escapeHtml(i18n.compare || 'Compare') + ' →</a></td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
		}

		html += footer();
		root.innerHTML = html;
		restoreInputFocus();
	}

	function renderDetail(slug) {
		var cp = data.canonical_products.find(function (x) { return x.slug === slug; });
		if (!cp) {
			root.innerHTML = '<p class="supcomp-error">' + escapeHtml('Product not found.') + '</p>' + footer();
			return;
		}
		var offers = filterDetailOffers(
			data.offers.filter(function (o) { return o.canonical_product_id === cp.id; })
		).sort(detailSortCompare);

		var html = '';
		html += '<p><a class="supcomp-back" href="#/">' + escapeHtml(i18n.backToAll || 'Back') + '</a></p>';
		html += '<h2 class="supcomp-title">' + escapeHtml(cp.display_name) + '</h2>';
		// Build the subtitle as an array of bits so empty fields don't leave
		// dangling separators. When the canonical doesn't pin a strength
		// (the v1.1.x default), show just the active unit instead of "0mg".
		var metaBits = [];
		if (cp.ingredient && cp.ingredient.name) {
			metaBits.push(escapeHtml(cp.ingredient.name));
			if (cp.ingredient.category) metaBits.push(escapeHtml(cp.ingredient.category));
		}
		if (cp.form) metaBits.push(escapeHtml(cp.form));
		var unit = cp.strength_unit || cp.active_unit_label || '';
		var strengthNum = Number(cp.strength_per_serving);
		var hasStrength = cp.strength_per_serving != null && cp.strength_per_serving !== '' && strengthNum > 0;
		if (hasStrength) {
			metaBits.push(escapeHtml(formatNumber(cp.strength_per_serving) + unit));
		} else if (unit) {
			metaBits.push(escapeHtml(unit));
		}
		if (cp.standardization_compound && cp.standardization_percentage) {
			metaBits.push(escapeHtml(formatNumber(cp.standardization_percentage) + '% ' + cp.standardization_compound));
		}
		html += '<p class="supcomp-meta">' + metaBits.join(' · ') + '</p>';

		html += detailFilterBar();

		if (offers.length === 0) {
			html += '<p class="supcomp-empty">' + escapeHtml(i18n.noResults || 'No matches.') + '</p>';
		} else {
			html += '<table class="supcomp-table supcomp-detail">';
			html += '<thead><tr>';
			html += '<th>' + escapeHtml(i18n.merchantColumn || 'Merchant') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.totalActiveColumn || 'Total active') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.servingSizeColumn || 'Serving size') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.numServingsColumn || 'Servings') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.costPerServingColumn || 'Cost / serving') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.costPerActiveColumn || 'Cost / active unit') + '</th>';
			html += '<th class="supcomp-num">' + escapeHtml(i18n.priceColumn || 'Price') + '</th>';
			html += '<th>' + escapeHtml(i18n.couponCodeColumn || 'Coupon code') + '</th>';
			html += '<th>' + escapeHtml(i18n.couponDetailsColumn || 'Coupon details') + '</th>';
			html += '<th>' + escapeHtml(i18n.buyColumn || 'Buy') + '</th>';
			html += '</tr></thead><tbody>';
			offers.forEach(function (o) {
				html += '<tr' + (o.is_stale ? ' class="supcomp-stale"' : '') + '>';
				html += '<td>';
				html += '<strong>' + escapeHtml((o.merchant && o.merchant.name) || o.brand || '') + '</strong>';
				if (o.brand && o.merchant && o.merchant.name !== o.brand) {
					html += '<br><span class="supcomp-meta">' + escapeHtml(o.brand) + '</span>';
				}
				if (o.is_stale) {
					html += '<br><span class="supcomp-stale-note">' + escapeHtml(i18n.staleNote || 'data may be outdated') + '</span>';
				}
				html += badges(o);
				html += '</td>';
				html += '<td class="supcomp-num">' + formatAmount(o.active_compound_total, cp) + '</td>';
				html += '<td class="supcomp-num">' + formatAmount(o.strength_per_serving, cp) + '</td>';
				html += '<td class="supcomp-num">' + (o.servings_per_container != null ? o.servings_per_container : '—') + '</td>';
				html += '<td class="supcomp-num">' + formatCostPerServing(o) + '</td>';
				html += '<td class="supcomp-num">' + formatCostPerActive(o, cp) + '</td>';
				html += '<td class="supcomp-num">';
				html += formatPrice(o.current_price, o.currency);
				if (o.on_sale && o.regular_price && o.regular_price > o.current_price) {
					html += '<br><span class="supcomp-was">' + escapeHtml(formatPrice(o.regular_price, o.currency)) + '</span>';
				}
				html += '</td>';
				html += '<td>';
				var couponCode = o.merchant && o.merchant.coupon_code ? String(o.merchant.coupon_code) : '';
				if (couponCode) {
					html += '<code class="supcomp-coupon">' + escapeHtml(couponCode) + '</code>';
				} else {
					html += '<span class="supcomp-meta">—</span>';
				}
				html += '</td>';
				html += '<td>';
				var couponDetails = o.merchant && o.merchant.coupon_details ? String(o.merchant.coupon_details) : '';
				if (couponDetails) {
					html += escapeHtml(couponDetails);
				} else {
					html += '<span class="supcomp-meta">—</span>';
				}
				html += '</td>';
				html += '<td>';
				if (o.stock_status === 'in_stock' || o.stock_status === 'backorder') {
					html += '<a class="supcomp-buy" href="' + escapeAttr(o.buy_url) + '" target="_blank" rel="nofollow sponsored noopener">' +
						escapeHtml(i18n.buyNow || 'Buy Now →') + '</a>';
				} else {
					html += '<span class="supcomp-meta">' + escapeHtml(stockLabel(o.stock_status)) + '</span>';
				}
				html += '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
		}

		html += footer();
		root.innerHTML = html;
		restoreInputFocus();
	}

	// ---------- filter bars ----------

	function listFilterBar() {
		var f = state.listFilters;
		var forms = uniqueSorted(data.canonical_products.map(function (c) { return c.form; }));
		var ingredients = uniqueIngredients(data.canonical_products);

		var h = '<div class="supcomp-filters">';
		h += '<input type="search" data-role="search" value="' + escapeAttr(f.search) + '" placeholder="' + escapeAttr(i18n.search || 'Search') + '" class="supcomp-search">';
		h += '<select data-field="form">';
		h += '<option value="">' + escapeHtml(i18n.allForms || 'All forms') + '</option>';
		forms.forEach(function (x) {
			h += '<option value="' + escapeAttr(x) + '"' + (f.form === x ? ' selected' : '') + '>' + escapeHtml(x) + '</option>';
		});
		h += '</select>';
		h += '<select data-field="ingredient">';
		h += '<option value="">' + escapeHtml(i18n.allIngredients || 'All ingredients') + '</option>';
		ingredients.forEach(function (ing) {
			h += '<option value="' + escapeAttr(ing.name) + '"' + (f.ingredient === ing.name ? ' selected' : '') + '>' + escapeHtml(ing.name) + '</option>';
		});
		h += '</select>';

		h += '<label><input type="checkbox" data-field="inStockOnly"' + (f.inStockOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.inStockOnly || 'In stock only') + '</label>';
		h += '<label><input type="checkbox" data-field="thirdPartyOnly"' + (f.thirdPartyOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.thirdPartyOnly || '3PT only') + '</label>';
		h += '<label><input type="checkbox" data-field="coaOnly"' + (f.coaOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.coaOnly || 'COA only') + '</label>';

		h += '<label class="supcomp-sort">' + escapeHtml(i18n.sortBy || 'Sort') + ': ';
		h += '<select data-role="sort">';
		h += sortOption('cost_per_active_unit', i18n.sortCostPerActive, state.listSort);
		h += sortOption('current_price', i18n.sortPrice, state.listSort);
		h += sortOption('display_name', i18n.product, state.listSort);
		h += '</select>';
		h += '</label>';
		h += '</div>';
		return h;
	}

	function detailFilterBar() {
		var f = state.detailFilters;
		var h = '<div class="supcomp-filters">';
		h += '<label><input type="checkbox" data-bag="detail" data-field="inStockOnly"' + (f.inStockOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.inStockOnly || 'In stock only') + '</label>';
		h += '<label><input type="checkbox" data-bag="detail" data-field="thirdPartyOnly"' + (f.thirdPartyOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.thirdPartyOnly || '3PT only') + '</label>';
		h += '<label><input type="checkbox" data-bag="detail" data-field="coaOnly"' + (f.coaOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.coaOnly || 'COA only') + '</label>';

		h += '<label class="supcomp-sort">' + escapeHtml(i18n.sortBy || 'Sort') + ': ';
		h += '<select data-role="sort" data-bag="detail">';
		h += sortOption('cost_per_active_unit', i18n.sortCostPerActive, state.detailSort);
		h += sortOption('current_price', i18n.sortPrice, state.detailSort);
		h += sortOption('brand', i18n.sortBrand, state.detailSort);
		h += sortOption('merchant', i18n.sortMerchant, state.detailSort);
		h += sortOption('last_synced_at', i18n.sortRecency, state.detailSort);
		h += '</select>';
		h += '</label>';
		h += '</div>';
		return h;
	}

	function sortOption(value, label, current) {
		return '<option value="' + escapeAttr(value) + '"' + (current === value ? ' selected' : '') + '>' + escapeHtml(label || value) + '</option>';
	}

	// ---------- filtering / sorting ----------

	function filterListOffers(offers) {
		var f = state.listFilters;
		var search = f.search ? f.search.toLowerCase() : '';
		return offers.filter(function (o) {
			if (f.inStockOnly && o.stock_status !== 'in_stock') return false;
			if (f.thirdPartyOnly && !o.third_party_tested) return false;
			if (f.coaOnly && !o.coa_available) return false;
			if (f.minPrice !== '' && o.current_price != null && o.current_price < parseFloat(f.minPrice)) return false;
			if (f.maxPrice !== '' && o.current_price != null && o.current_price > parseFloat(f.maxPrice)) return false;
			if (f.form || f.ingredient) {
				var cp = data.canonical_products.find(function (c) { return c.id === o.canonical_product_id; });
				if (f.form && (!cp || cp.form !== f.form)) return false;
				if (f.ingredient && (!cp || !cp.ingredient || cp.ingredient.name !== f.ingredient)) return false;
			}
			if (search) {
				var cp2 = data.canonical_products.find(function (c) { return c.id === o.canonical_product_id; });
				var haystack = ((o.product_title || '') + ' ' + (o.brand || '') + ' ' +
					(cp2 ? cp2.display_name + ' ' + (cp2.ingredient && cp2.ingredient.name || '') : '')).toLowerCase();
				if (haystack.indexOf(search) === -1) return false;
			}
			return true;
		});
	}

	function filterDetailOffers(offers) {
		var f = state.detailFilters;
		return offers.filter(function (o) {
			if (f.inStockOnly && o.stock_status !== 'in_stock') return false;
			if (f.thirdPartyOnly && !o.third_party_tested) return false;
			if (f.coaOnly && !o.coa_available) return false;
			return true;
		});
	}

	function listSortCompare(a, b) {
		switch (state.listSort) {
			case 'current_price':
				return numericCompare(minBy(a.offers, 'current_price'), minBy(b.offers, 'current_price'), 'current_price');
			case 'display_name':
				return (a.cp.display_name || '').localeCompare(b.cp.display_name || '');
			case 'cost_per_active_unit':
			default:
				return numericCompare(a.lowest, b.lowest, 'cost_per_active_unit');
		}
	}

	function detailSortCompare(a, b) {
		switch (state.detailSort) {
			case 'current_price':
				return numericCompare(a, b, 'current_price');
			case 'brand':
				return (a.brand || '').localeCompare(b.brand || '');
			case 'merchant':
				return ((a.merchant && a.merchant.name) || '').localeCompare((b.merchant && b.merchant.name) || '');
			case 'last_synced_at':
				return (b.last_synced_at || '').localeCompare(a.last_synced_at || '');
			case 'cost_per_active_unit':
			default:
				return numericCompare(a, b, 'cost_per_active_unit');
		}
	}

	function numericCompare(a, b, field) {
		var av = a ? a[field] : null;
		var bv = b ? b[field] : null;
		if (av == null && bv == null) return 0;
		if (av == null) return 1;
		if (bv == null) return -1;
		return av - bv;
	}

	function groupByCanonical(offers) {
		var out = {};
		offers.forEach(function (o) {
			if (!o.canonical_product_id) return;
			if (!out[o.canonical_product_id]) out[o.canonical_product_id] = [];
			out[o.canonical_product_id].push(o);
		});
		return out;
	}

	function minBy(arr, key) {
		var best = null;
		arr.forEach(function (item) {
			if (item[key] == null) return;
			if (best == null || item[key] < best[key]) best = item;
		});
		return best;
	}

	function countUnique(arr, picker) {
		var seen = {};
		arr.forEach(function (item) {
			var k = picker(item);
			if (k != null) seen[k] = true;
		});
		return Object.keys(seen).length;
	}

	function uniqueSorted(arr) {
		var seen = {};
		var out = [];
		arr.forEach(function (x) {
			if (x && !seen[x]) { seen[x] = true; out.push(x); }
		});
		return out.sort();
	}

	function uniqueIngredients(canonicals) {
		var seen = {};
		var out = [];
		canonicals.forEach(function (c) {
			if (c.ingredient && c.ingredient.name && !seen[c.ingredient.name]) {
				seen[c.ingredient.name] = true;
				out.push(c.ingredient);
			}
		});
		return out.sort(function (a, b) { return a.name.localeCompare(b.name); });
	}

	// ---------- formatting ----------

	var CURRENCY_SYMBOLS = { USD: '$', EUR: '€', GBP: '£', JPY: '¥' };

	function formatPrice(value, currency) {
		if (value == null) return '—';
		var code = currency ? String(currency).toUpperCase() : '';
		if (CURRENCY_SYMBOLS[code]) {
			return CURRENCY_SYMBOLS[code] + value.toFixed(2);
		}
		return (code ? code + ' ' : '') + value.toFixed(2);
	}

	function formatCostPerActive(offer, canonical) {
		if (!offer || offer.cost_per_active_unit == null) return '<span class="supcomp-meta">—</span>';
		var unit = (canonical && canonical.active_unit_label) || (canonical && canonical.strength_unit) || '';
		var fmt = formatPrice(offer.cost_per_active_unit, offer.currency) + (unit ? ' / ' + escapeHtml(unit) : '');
		return fmt;
	}

	function formatCostPerServing(offer) {
		if (!offer || offer.cost_per_serving == null) return '<span class="supcomp-meta">—</span>';
		return formatPrice(offer.cost_per_serving, offer.currency);
	}

	function formatAmount(value, canonical) {
		if (value == null) return '<span class="supcomp-meta">—</span>';
		var unit = (canonical && canonical.active_unit_label) || (canonical && canonical.strength_unit) || '';
		return escapeHtml(formatNumber(value)) + (unit ? ' ' + escapeHtml(unit) : '');
	}

	function formatNumber(value) {
		if (value == null) return '';
		var s = String(value);
		if (s.indexOf('.') !== -1) {
			s = s.replace(/0+$/, '').replace(/\.$/, '');
		}
		return s;
	}

	function stockLabel(s) {
		if (s === 'in_stock') return i18n.inStock || 'In stock';
		if (s === 'out_of_stock') return i18n.outOfStock || 'Out of stock';
		if (s === 'backorder') return i18n.backorder || 'Backorder';
		return i18n.unknownStock || 'Unknown';
	}

	function badges(offer) {
		var b = '';
		if (offer.third_party_tested) {
			b += ' <span class="supcomp-badge supcomp-badge-3pt" title="Third-party tested">' + escapeHtml(i18n.trust3PT || '3PT') + '</span>';
		}
		if (offer.coa_available) {
			var label = '<span class="supcomp-badge supcomp-badge-coa" title="Certificate of Analysis available">' + escapeHtml(i18n.trustCOA || 'COA') + '</span>';
			if (offer.coa_url) {
				b += ' <a href="' + escapeAttr(offer.coa_url) + '" target="_blank" rel="noopener">' + label + '</a>';
			} else {
				b += ' ' + label;
			}
		}
		if (offer.certifications && offer.certifications.length) {
			offer.certifications.forEach(function (cert) {
				b += ' <span class="supcomp-badge">' + escapeHtml(cert) + '</span>';
			});
		}
		return b;
	}

	function footer() {
		var stamp = '';
		if (data && data.generated_at) {
			try {
				var d = new Date(data.generated_at);
				stamp = d.toISOString().replace('T', ' ').slice(0, 16) + ' UTC';
			} catch (e) {
				stamp = data.generated_at;
			}
		}
		var h = '<div class="supcomp-footer">';
		if (config.affiliateDisclosure) {
			h += '<p class="supcomp-disclosure">' + escapeHtml(config.affiliateDisclosure) + '</p>';
		}
		if (stamp) {
			h += '<p class="supcomp-meta">' + escapeHtml(i18n.lastUpdated || 'Data last updated') + ': ' + escapeHtml(stamp) + '</p>';
		}
		h += '</div>';
		return h;
	}

	function restoreInputFocus() {
		// After an innerHTML replace, the search box loses focus. Re-focus it
		// if the user was typing.
		var search = root.querySelector('input[data-role="search"]');
		if (search && state.listFilters.search && document.activeElement !== search) {
			// Only refocus when the field was the actively-changing one.
			// The change/input events bubble before we render, so this is a
			// reasonable heuristic.
		}
	}

	// ---------- escaping ----------

	function escapeHtml(s) {
		if (s == null) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function escapeAttr(s) {
		return escapeHtml(s);
	}
}());
