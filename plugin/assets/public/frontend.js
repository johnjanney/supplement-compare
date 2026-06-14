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

	var DETAIL_VIEWS = ['cost_per_serving', 'cost_per_active_unit'];
	var defaultDetailView = DETAIL_VIEWS.indexOf(config.defaultCompareView) >= 0
		? config.defaultCompareView
		: 'cost_per_active_unit';
	// wp_localize_script coerces top-level scalars to strings: PHP `true` → "1",
	// `false` → "". Use a truthy check, not `!== false`, or the flag is always on.
	var multiCompareViewEnabled = !!config.multiCompareViewEnabled;

	var filterToggles = (config.filters && typeof config.filters === 'object') ? config.filters : {};
	var showInStock = filterToggles.inStockOnly !== false;
	var showThirdParty = filterToggles.thirdPartyOnly !== false;
	var showCoa = filterToggles.coaOnly !== false;
	var showSearch = filterToggles.search !== false;
	var showForm = filterToggles.form !== false;
	var showIngredient = filterToggles.ingredient !== false;

	var subheadToggles = (config.subheads && typeof config.subheads === 'object') ? config.subheads : {};
	var showDetailSubhead = subheadToggles.detail !== false;
	var showListSubhead = subheadToggles.list !== false;

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
		listSort: { key: 'cost_per_active_unit', dir: 'asc' },
		detailFilters: { inStockOnly: false, thirdPartyOnly: false, coaOnly: false },
		detailSort: { key: 'cost_per_active_unit', dir: 'asc' },
		detailView: defaultDetailView,
	};

	// Each sortable column declares its natural direction so a first-click on
	// (say) "Price" sorts cheapest-first and "Total active" sorts most-first,
	// matching what a buyer probably wants without forcing them to click twice.
	// Subsequent clicks on the same column toggle direction.
	var LIST_SORT_DEFAULT_DIR = {
		display_name: 'asc',
		cost_per_active_unit: 'asc',
		merchant_count: 'desc',
	};
	var DETAIL_SORT_DEFAULT_DIR = {
		merchant: 'asc',
		active_compound_total: 'desc',
		strength_per_serving: 'desc',
		servings_per_container: 'desc',
		cost_per_serving: 'asc',
		cost_per_active_unit: 'asc',
		current_price: 'asc',
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
		// Hash navigation (Compare / Back / browser back-forward) doesn't move
		// the scroll position on its own — bring the app back to the top so the
		// freshly-rendered view starts at the top of the viewport. Tied to
		// navigation only; filter/search/sort re-renders go through render()
		// directly and are intentionally left where the user is scrolled.
		scrollAppToTop();
	});

	root.addEventListener('change', onControlChange);
	root.addEventListener('input', onControlInput);
	root.addEventListener('click', onControlClick);
	root.addEventListener('keydown', onControlKeydown);

	function onControlChange(ev) {
		var t = ev.target;
		if (!t || !t.dataset) return;
		if (t.dataset.role === 'detail-view') {
			if (multiCompareViewEnabled && DETAIL_VIEWS.indexOf(t.value) >= 0) {
				state.detailView = t.value;
				render();
			}
			return;
		}
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

	function onControlClick(ev) {
		if (!ev.target || !ev.target.closest) return;

		var header = ev.target.closest('th[data-sort-key]');
		if (header) {
			ev.preventDefault();
			applyHeaderSort(header);
			return;
		}

		var btn = ev.target.closest('[data-role="copy-coupon"]');
		if (!btn) return;
		ev.preventDefault();
		var code = btn.getAttribute('data-code') || '';
		if (!code) return;
		copyToClipboard(code).then(function () {
			flashCopied(btn);
		}, function () {
			// On failure (no clipboard permission, http context, etc.) select the
			// text so the user can copy it manually — at least give them something.
			selectButtonText(btn);
		});
	}

	function onControlKeydown(ev) {
		if (ev.key !== 'Enter' && ev.key !== ' ') return;
		var header = ev.target && ev.target.closest ? ev.target.closest('th[data-sort-key]') : null;
		if (!header) return;
		ev.preventDefault();
		applyHeaderSort(header);
	}

	function applyHeaderSort(header) {
		var key = header.getAttribute('data-sort-key');
		if (!key) return;
		var isList = !!header.closest('.supcomp-list');
		var stateKey = isList ? 'listSort' : 'detailSort';
		var defaults = isList ? LIST_SORT_DEFAULT_DIR : DETAIL_SORT_DEFAULT_DIR;
		var current = state[stateKey];
		if (current.key === key) {
			state[stateKey] = { key: key, dir: current.dir === 'asc' ? 'desc' : 'asc' };
		} else {
			state[stateKey] = { key: key, dir: defaults[key] || 'asc' };
		}
		render();
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			try {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly', '');
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild(ta);
				ta.select();
				var ok = document.execCommand('copy');
				document.body.removeChild(ta);
				ok ? resolve() : reject(new Error('execCommand returned false'));
			} catch (e) {
				reject(e);
			}
		});
	}

	function flashCopied(btn) {
		if (btn._copyResetTimer) {
			clearTimeout(btn._copyResetTimer);
			delete btn._copyResetTimer;
		}
		var original = btn.getAttribute('data-original-text');
		if (original == null) {
			btn.setAttribute('data-original-text', btn.textContent);
		}
		btn.textContent = i18n.couponCopied || 'Copied!';
		btn.classList.add('supcomp-coupon-copied');
		btn._copyResetTimer = setTimeout(function () {
			var restore = btn.getAttribute('data-original-text');
			if (restore != null) {
				btn.textContent = restore;
				btn.removeAttribute('data-original-text');
			}
			btn.classList.remove('supcomp-coupon-copied');
			delete btn._copyResetTimer;
		}, 1500);
	}

	function selectButtonText(btn) {
		try {
			var range = document.createRange();
			range.selectNodeContents(btn);
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange(range);
		} catch (e) {}
	}

	function onControlInput(ev) {
		var t = ev.target;
		if (!t || !t.dataset) return;
		if (t.dataset.role === 'search') {
			rememberFocus(t);
			state.listFilters.search = t.value;
			render();
		}
	}

	var pendingFocus = null;

	function rememberFocus(el) {
		// Each keystroke triggers a full innerHTML rerender, which destroys the
		// focused input. Capture the field identity + caret position here so
		// restoreInputFocus() can put it back after the render.
		var start = null, end = null;
		try {
			if (typeof el.selectionStart === 'number') start = el.selectionStart;
			if (typeof el.selectionEnd === 'number') end = el.selectionEnd;
		} catch (e) {}
		pendingFocus = {
			role: (el.dataset && el.dataset.role) || null,
			field: (el.dataset && el.dataset.field) || null,
			bag: (el.dataset && el.dataset.bag) || null,
			selStart: start,
			selEnd: end,
		};
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
		html += statsDashboard({
			products: canonicals.length,
			offers: offers.length,
			merchants: countUnique(offers, function (o) { return o.merchant && o.merchant.id; }),
		});
		html += listFilterBar();

		if (canonicals.length === 0) {
			html += '<p class="supcomp-empty">' + escapeHtml(i18n.noResults || 'No matches.') + '</p>';
		} else {
			html += '<table class="supcomp-table supcomp-list">';
			html += '<thead><tr>';
			html += sortHeader('display_name', i18n.product || 'Product', state.listSort);
			html += sortHeader('cost_per_active_unit', i18n.lowestCost || 'Lowest cost / active unit', state.listSort, { numeric: true });
			html += sortHeader('merchant_count', i18n.merchants || 'Merchants', state.listSort, { numeric: true });
			html += '<th></th>';
			html += '</tr></thead><tbody>';
			canonicals.forEach(function (item) {
				html += '<tr data-slug="' + escapeHtml(item.cp.slug) + '">';
				html += '<td>';
				html += '<a class="supcomp-canon-link" href="#/canonical/' + encodeURIComponent(item.cp.slug) + '">' + escapeHtml(item.cp.display_name) + '</a>';
				if (showListSubhead) {
					html += '<br><span class="supcomp-meta">' + escapeHtml((item.cp.ingredient && item.cp.ingredient.name) || '') +
						(item.cp.ingredient && item.cp.ingredient.category ? ' · ' + escapeHtml(item.cp.ingredient.category) : '') + '</span>';
				}
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
		var unitDisp = displayUnit(unit);
		// Multi-word display labels (e.g. "B CFU") get a leading space so the
		// magnitude doesn't run into the unit ("200 B CFU" not "200B CFU").
		// Single-word labels stay compact ("200mg").
		var unitGlue = unitDisp.indexOf(' ') >= 0 ? ' ' : '';
		var strengthNum = Number(cp.strength_per_serving);
		var hasStrength = cp.strength_per_serving != null && cp.strength_per_serving !== '' && strengthNum > 0;
		if (hasStrength) {
			metaBits.push(escapeHtml(formatNumber(cp.strength_per_serving) + unitGlue + unitDisp));
		} else if (unitDisp) {
			metaBits.push(escapeHtml(unitDisp));
		}
		if (cp.standardization_compound && cp.standardization_percentage) {
			metaBits.push(escapeHtml(formatNumber(cp.standardization_percentage) + '% ' + cp.standardization_compound));
		}
		if (showDetailSubhead) {
			html += '<p class="supcomp-meta">' + metaBits.join(' · ') + '</p>';
		}

		// Stats reflect the filtered offers for this one canonical, so the
		// Products box reads 1 while any offer matches and 0 once filters
		// empty the table.
		html += statsDashboard({
			products: offers.length > 0 ? 1 : 0,
			offers: offers.length,
			merchants: countUnique(offers, function (o) { return o.merchant && o.merchant.id; }),
		});

		html += detailFilterBar();

		if (offers.length === 0) {
			html += '<p class="supcomp-empty">' + escapeHtml(i18n.noResults || 'No matches.') + '</p>';
		} else {
			var showActive = state.detailView === 'cost_per_active_unit';
			var showServing = state.detailView === 'cost_per_serving';

			var totalHeader = unitDisp
				? (i18n.totalUnitColumn || 'Total %s').replace('%s', unitDisp)
				: (i18n.totalActiveColumn || 'Total active');
			var costPerActiveHeader = unitDisp
				? (i18n.costPerUnitColumn || 'Cost / %s').replace('%s', unitDisp)
				: (i18n.costPerActiveColumn || 'Cost / active unit');
			// Mobile uses a 2-row CSS Grid; the column count for row A
			// depends on which compare view is showing (4 sortable cols
			// in cost-per-active, 5 in cost-per-serving). frontend.css
			// keys off this class.
			var colsClass = showServing ? 'supcomp-cols-5' : 'supcomp-cols-4';

			html += '<table class="supcomp-table supcomp-detail ' + colsClass + '">';
			html += '<thead><tr>';
			html += sortHeader('merchant', i18n.merchantColumn || 'Merchant', state.detailSort);
			if (showActive) {
				html += sortHeader('active_compound_total', totalHeader, state.detailSort, { numeric: true });
			}
			if (showServing) {
				html += sortHeader('strength_per_serving', i18n.servingSizeColumn || 'Serving size', state.detailSort, { numeric: true });
				html += sortHeader('servings_per_container', i18n.numServingsColumn || 'Servings', state.detailSort, { numeric: true });
				html += sortHeader('cost_per_serving', i18n.costPerServingColumn || 'Cost / serving', state.detailSort, { numeric: true });
			}
			if (showActive) {
				html += sortHeader('cost_per_active_unit', costPerActiveHeader, state.detailSort, { numeric: true });
			}
			html += sortHeader('current_price', i18n.priceColumn || 'Price', state.detailSort, { numeric: true });
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
				if (showActive) {
					html += '<td class="supcomp-num">' + formatAmount(o.active_compound_total, cp) + '</td>';
				}
				if (showServing) {
					html += '<td class="supcomp-num">' + formatAmount(o.strength_per_serving, cp) + '</td>';
					html += '<td class="supcomp-num">' + (o.servings_per_container != null ? o.servings_per_container : '—') + '</td>';
					html += '<td class="supcomp-num">' + formatCostPerServing(o) + '</td>';
				}
				if (showActive) {
					html += '<td class="supcomp-num">' + formatCostPerActive(o, cp) + '</td>';
				}
				html += '<td class="supcomp-num">';
				html += formatPrice(o.current_price, o.currency);
				html += priceMoveIndicator(o);
				if (o.on_sale && o.regular_price && o.regular_price > o.current_price) {
					html += '<br><span class="supcomp-was">' + escapeHtml(formatPrice(o.regular_price, o.currency)) + '</span>';
				}
				html += '</td>';
				html += '<td>';
				var couponCode = o.merchant && o.merchant.coupon_code ? String(o.merchant.coupon_code) : '';
				if (couponCode) {
					html += '<button type="button" class="supcomp-coupon" data-role="copy-coupon" data-code="' + escapeAttr(couponCode) + '" title="' + escapeAttr(i18n.couponCopyHint || 'Click to copy') + '" aria-label="' + escapeAttr((i18n.couponCopyHint || 'Click to copy') + ': ' + couponCode) + '">' + escapeHtml(couponCode) + '</button>';
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

	// ---------- stats dashboard ----------

	// Three at-a-glance stat boxes above the table controls. Values are
	// computed from the currently-filtered offer set by the caller, so they
	// react to search / filters in lockstep with the table.
	function statsDashboard(stats) {
		var items = [
			[i18n.statProducts || 'Products', stats.products],
			[i18n.statOffers || 'Offers', stats.offers],
			[i18n.statMerchants || 'Merchants', stats.merchants],
		];
		var h = '<div class="supcomp-stats">';
		items.forEach(function (it) {
			h += '<div class="supcomp-stat">' +
				'<span class="supcomp-stat-label">' + escapeHtml(it[0]) + '</span>' +
				'<span class="supcomp-stat-value">' + escapeHtml(String(it[1])) + '</span>' +
				'</div>';
		});
		h += '</div>';
		return h;
	}

	// ---------- filter bars ----------

	function listFilterBar() {
		var f = state.listFilters;
		var forms = showForm ? uniqueSorted(data.canonical_products.map(function (c) { return c.form; })) : [];
		var ingredients = showIngredient ? uniqueIngredients(data.canonical_products) : [];

		var h = '<div class="supcomp-filters">';
		if (showSearch) {
			h += '<input type="search" data-role="search" value="' + escapeAttr(f.search) + '" placeholder="' + escapeAttr(i18n.search || 'Search') + '" class="supcomp-search">';
		}
		if (showForm) {
			h += '<select data-field="form">';
			h += '<option value="">' + escapeHtml(i18n.allForms || 'All forms') + '</option>';
			forms.forEach(function (x) {
				h += '<option value="' + escapeAttr(x) + '"' + (f.form === x ? ' selected' : '') + '>' + escapeHtml(x) + '</option>';
			});
			h += '</select>';
		}
		if (showIngredient) {
			h += '<select data-field="ingredient">';
			h += '<option value="">' + escapeHtml(i18n.allIngredients || 'All ingredients') + '</option>';
			ingredients.forEach(function (ing) {
				h += '<option value="' + escapeAttr(ing.name) + '"' + (f.ingredient === ing.name ? ' selected' : '') + '>' + escapeHtml(ing.name) + '</option>';
			});
			h += '</select>';
		}

		if (showInStock) {
			h += '<label><input type="checkbox" data-field="inStockOnly"' + (f.inStockOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.inStockOnly || 'In stock only') + '</label>';
		}
		if (showThirdParty) {
			h += '<label><input type="checkbox" data-field="thirdPartyOnly"' + (f.thirdPartyOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.thirdPartyOnly || '3PT only') + '</label>';
		}
		if (showCoa) {
			h += '<label><input type="checkbox" data-field="coaOnly"' + (f.coaOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.coaOnly || 'COA only') + '</label>';
		}

		h += '</div>';
		return h;
	}

	function detailFilterBar() {
		var f = state.detailFilters;
		var v = state.detailView;
		var h = '';
		if (multiCompareViewEnabled) {
			h += '<div class="supcomp-view-toggle" role="radiogroup" aria-label="' + escapeAttr(i18n.viewModeLabel || 'Show:') + '">';
			h += '<span class="supcomp-view-toggle-label">' + escapeHtml(i18n.viewModeLabel || 'Show:') + '</span>';
			h += '<label><input type="radio" name="supcomp-detail-view" data-role="detail-view" value="cost_per_serving"' + (v === 'cost_per_serving' ? ' checked' : '') + '> ' + escapeHtml(i18n.viewCostPerServing || 'Cost / Serving') + '</label>';
			h += '<label><input type="radio" name="supcomp-detail-view" data-role="detail-view" value="cost_per_active_unit"' + (v === 'cost_per_active_unit' ? ' checked' : '') + '> ' + escapeHtml(i18n.viewCostPerActive || 'Cost / Active Unit') + '</label>';
			h += '</div>';
		}
		h += '<div class="supcomp-filters">';
		if (showInStock) {
			h += '<label><input type="checkbox" data-bag="detail" data-field="inStockOnly"' + (f.inStockOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.inStockOnly || 'In stock only') + '</label>';
		}
		if (showThirdParty) {
			h += '<label><input type="checkbox" data-bag="detail" data-field="thirdPartyOnly"' + (f.thirdPartyOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.thirdPartyOnly || '3PT only') + '</label>';
		}
		if (showCoa) {
			h += '<label><input type="checkbox" data-bag="detail" data-field="coaOnly"' + (f.coaOnly ? ' checked' : '') + '> ' + escapeHtml(i18n.coaOnly || 'COA only') + '</label>';
		}

		h += '</div>';
		return h;
	}

	function sortHeader(key, label, current, opts) {
		opts = opts || {};
		var active = current && current.key === key;
		var dir = active ? current.dir : '';
		var ariaSort = active ? (dir === 'desc' ? 'descending' : 'ascending') : 'none';
		var arrow = active ? (dir === 'desc' ? ' ▼' : ' ▲') : '';
		var classes = 'supcomp-sortable';
		if (opts.numeric) classes += ' supcomp-num';
		if (active) classes += ' supcomp-sorted';
		return '<th class="' + classes + '" data-sort-key="' + escapeAttr(key) + '" aria-sort="' + ariaSort + '" tabindex="0" role="button">' +
			escapeHtml(label || key) +
			'<span class="supcomp-sort-arrow" aria-hidden="true">' + arrow + '</span>' +
			'</th>';
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
		var s = state.listSort;
		switch (s.key) {
			case 'display_name':
				return textCompare(a.cp.display_name, b.cp.display_name, s.dir);
			case 'merchant_count':
				return numericCompareDir(a.merchantCount, b.merchantCount, s.dir);
			case 'cost_per_active_unit':
			default:
				return numericCompareDir(
					a.lowest && a.lowest.cost_per_active_unit,
					b.lowest && b.lowest.cost_per_active_unit,
					s.dir
				);
		}
	}

	function detailSortCompare(a, b) {
		var s = state.detailSort;
		switch (s.key) {
			case 'merchant':
				return textCompare((a.merchant && a.merchant.name) || '', (b.merchant && b.merchant.name) || '', s.dir);
			case 'current_price':
				return numericCompareDir(a.current_price, b.current_price, s.dir);
			case 'active_compound_total':
				return numericCompareDir(a.active_compound_total, b.active_compound_total, s.dir);
			case 'strength_per_serving':
				return numericCompareDir(a.strength_per_serving, b.strength_per_serving, s.dir);
			case 'servings_per_container':
				return numericCompareDir(a.servings_per_container, b.servings_per_container, s.dir);
			case 'cost_per_serving':
				return numericCompareDir(a.cost_per_serving, b.cost_per_serving, s.dir);
			case 'cost_per_active_unit':
			default:
				return numericCompareDir(a.cost_per_active_unit, b.cost_per_active_unit, s.dir);
		}
	}

	// Numeric compare with nulls always at the bottom regardless of direction.
	// Flipping direction reverses the comparison of populated values only —
	// empty cells stay at the end of the table either way.
	function numericCompareDir(av, bv, dir) {
		if (av == null && bv == null) return 0;
		if (av == null) return 1;
		if (bv == null) return -1;
		return dir === 'desc' ? bv - av : av - bv;
	}

	function textCompare(av, bv, dir) {
		var cmp = (av || '').localeCompare(bv || '');
		return dir === 'desc' ? -cmp : cmp;
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

	var UNIT_DISPLAY_OVERRIDES = { billion_cfu: 'B CFU' };

	function displayUnit(rawUnit) {
		if (!rawUnit) return '';
		return UNIT_DISPLAY_OVERRIDES[rawUnit] || rawUnit;
	}

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
		var unit = displayUnit((canonical && canonical.active_unit_label) || (canonical && canonical.strength_unit) || '');
		var fmt = formatPrice(offer.cost_per_active_unit, offer.currency) + (unit ? ' / ' + escapeHtml(unit) : '');
		return fmt;
	}

	// Price-direction indicator: a coloured arrow + % change shown to the right
	// of a merchant's current price. Reflects that offer's most recent
	// effective-price move, but only when it's recent enough — the exporter
	// already applied the operator's drop-off window and omits price_move when
	// the last move is stale (or the feature is disabled), so absence here means
	// "show nothing". Down = green (good for the buyer), up = red — inverted
	// from stock-market convention. The arrow shape carries the meaning; colour
	// only reinforces it.
	function priceMoveIndicator(offer) {
		var pm = offer && offer.price_move;
		if (!pm || (pm.dir !== 'up' && pm.dir !== 'down')) return '';
		var pct = Number(pm.pct);
		if (!isFinite(pct) || pct <= 0) return '';
		var down = pm.dir === 'down';
		var arrow = down ? '▼' : '▲';
		var cls = down ? 'supcomp-pricemove-down' : 'supcomp-pricemove-up';
		var pctNum = formatNumber(pct.toFixed(1));
		// A move under ~0.05% rounds to "0" — show nothing rather than "▼ 0%".
		if (pctNum === '0' || pctNum === '') return '';
		var pctText = pctNum + '%';
		var label = (down ? (i18n.priceDown || 'price down') : (i18n.priceUp || 'price up')) + ' ' + pctText;
		return ' <span class="supcomp-pricemove ' + cls + '" aria-label="' + escapeAttr(label) + '">' +
			arrow + ' ' + escapeHtml(pctText) + '</span>';
	}

	function formatCostPerServing(offer) {
		if (!offer || offer.cost_per_serving == null) return '<span class="supcomp-meta">—</span>';
		return formatPrice(offer.cost_per_serving, offer.currency);
	}

	function formatAmount(value, canonical) {
		if (value == null) return '<span class="supcomp-meta">—</span>';
		var unit = displayUnit((canonical && canonical.active_unit_label) || (canonical && canonical.strength_unit) || '');
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

	// Align the top of the app container with the top of the viewport. Targets
	// the container (not absolute 0) so page chrome above the embedded shortcode
	// isn't scrolled past. Instant jump, like a #top anchor.
	function scrollAppToTop() {
		try {
			root.scrollIntoView({ block: 'start' });
		} catch (e) {
			window.scrollTo(0, 0);
		}
	}

	function restoreInputFocus() {
		if (!pendingFocus) return;
		var sel = '';
		if (pendingFocus.role) {
			sel = '[data-role="' + pendingFocus.role + '"]';
		} else if (pendingFocus.field) {
			sel = '[data-field="' + pendingFocus.field + '"]';
			if (pendingFocus.bag) sel += '[data-bag="' + pendingFocus.bag + '"]';
		}
		if (sel) {
			var el = root.querySelector(sel);
			if (el) {
				try {
					el.focus();
					var s = pendingFocus.selStart;
					var e = pendingFocus.selEnd != null ? pendingFocus.selEnd : s;
					if (s != null && el.setSelectionRange) {
						el.setSelectionRange(s, e);
					}
				} catch (err) {}
			}
		}
		pendingFocus = null;
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
