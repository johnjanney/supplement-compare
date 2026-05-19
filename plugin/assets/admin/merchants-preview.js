/**
 * Affiliate URL template tester — calls admin-ajax.php with the current
 * template + the operator's example URLs, renders the engine's output as a
 * small table. Engine logic stays in PHP so what you preview is what /out/
 * generates at click time.
 */
(function () {
	'use strict';

	var config = window.supcompMerchantsPreview;
	if (!config) {
		return;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var btn = document.getElementById('supcomp-preview-btn');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', onPreview);
	});

	function onPreview(e) {
		e.preventDefault();

		var templateInput = document.getElementById('supcomp-template');
		var urlsInput = document.getElementById('supcomp-test-urls');
		var out = document.getElementById('supcomp-preview-results');
		if (!templateInput || !urlsInput || !out) {
			return;
		}

		var template = templateInput.value.trim();
		var urls = urlsInput.value
			.split('\n')
			.map(function (s) { return s.trim(); })
			.filter(function (s) { return s.length > 0; });

		if (template === '') {
			renderError(out, config.i18n.noTemplate);
			return;
		}
		if (urls.length === 0) {
			renderError(out, config.i18n.noUrls);
			return;
		}

		out.innerHTML = '<p><em>' + escapeHtml(config.i18n.loading) + '</em></p>';

		var body = new URLSearchParams();
		body.append('action', 'supcomp_preview_affiliate_url');
		body.append('_ajax_nonce', config.nonce);
		body.append('template', template);
		urls.forEach(function (u) { body.append('urls[]', u); });

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (!data.success) {
					renderError(out, typeof data.data === 'string' ? data.data : 'Unknown error.');
					return;
				}
				renderResults(out, data.data.results || []);
			})
			.catch(function (err) {
				renderError(out, config.i18n.networkError + ' ' + (err && err.message ? err.message : ''));
			});
	}

	function renderResults(out, results) {
		if (!results.length) {
			out.innerHTML = '';
			return;
		}
		var html = '<table class="widefat striped" style="margin-top:0.5em">';
		html += '<thead><tr><th>' + escapeHtml(config.i18n.header_input) +
			'</th><th>' + escapeHtml(config.i18n.header_out) + '</th></tr></thead><tbody>';
		results.forEach(function (r) {
			html += '<tr><td><code>' + escapeHtml(r.input) + '</code></td>';
			if (r.error) {
				html += '<td><em style="color:#a00">' + escapeHtml(r.error) + '</em></td>';
			} else {
				html += '<td><code>' + escapeHtml(r.output) + '</code></td>';
			}
			html += '</tr>';
		});
		html += '</tbody></table>';
		out.innerHTML = html;
	}

	function renderError(out, msg) {
		out.innerHTML = '<div class="notice notice-error inline" style="margin:0.5em 0"><p>' +
			escapeHtml(msg) + '</p></div>';
	}

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = s == null ? '' : String(s);
		return div.innerHTML;
	}
}());
