/**
 * Order-Management: Queue-Overlay + Verarbeitung.
 *
 * Wird auf den vier Aktionsseiten geladen (Konfiguration via wp_localize_script -> window.omQueue).
 * Solange Einträge offen sind, wird pro Poll-Zyklus ein Batch serverseitig verarbeitet,
 * damit die Abarbeitung nicht von WP-Cron abhängt.
 */
(function () {
	var cfg = window.omQueue;
	if (!cfg) return;

	var POLL_MS = 1500;
	var RETRY_MS = 2500;
	var STALL_LIMIT = 20; // ~30s ohne Fortschritt -> Hinweis anzeigen

	var processing = false;
	var hidden = false;
	var lastPending = -1;
	var stalls = 0;

	function post(data, cb) {
		var body = [];
		for (var k in data) {
			if (data.hasOwnProperty(k)) body.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
		}
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.onload = function () {
			var res = null;
			try { res = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
			cb && cb(res);
		};
		xhr.onerror = function () { cb && cb(null); };
		xhr.send(body.join('&'));
	}

	function getPending(cb) {
		post({ action: cfg.statusAction }, function (res) {
			cb(res && res.success && res.data ? (parseInt(res.data.pending, 10) || 0) : null);
		});
	}

	function processBatch() {
		if (processing) return;
		processing = true;
		post({ action: cfg.processAction, nonce: cfg.nonce }, function () { processing = false; });
	}

	function setMessage(text) {
		var el = document.getElementById('om_queue_msg');
		if (el) el.textContent = text;
	}

	function showHint(html) {
		var el = document.getElementById('om_queue_hint');
		if (el) { el.innerHTML = html; el.style.display = ''; }
	}

	function createOverlay(initial) {
		if (document.getElementById('om_queue_overlay')) return;
		var style = document.createElement('style');
		style.appendChild(document.createTextNode(
			'@keyframes om-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
			'#om_queue_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
			'#om_queue_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;max-width:90vw;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
			'#om_queue_msg{text-align:center;font-size:14px;color:#222;}' +
			'#om_queue_hint{text-align:center;font-size:13px;color:#8a6d3b;margin-top:10px;}' +
			'#om_queue_hide{margin-top:14px;}' +
			'.om-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:om-spin 1s linear infinite;margin-bottom:12px;}'
		));
		document.head.appendChild(style);

		var overlay = document.createElement('div');
		overlay.id = 'om_queue_overlay';
		overlay.innerHTML =
			'<div id="om_queue_box">' +
				'<div class="om-spinner"></div>' +
				'<div id="om_queue_msg"></div>' +
				'<div id="om_queue_hint" style="display:none;"></div>' +
				'<button type="button" id="om_queue_hide" class="button">Ausblenden</button>' +
			'</div>';
		document.body.appendChild(overlay);
		setMessage('Verarbeite ' + (initial || 0) + ' Einträge...');

		document.getElementById('om_queue_hide').addEventListener('click', function () {
			hidden = true;
			overlay.parentNode && overlay.parentNode.removeChild(overlay);
		});
	}

	function removeQueryParams() {
		try {
			var u = new URL(window.location.href);
			u.searchParams.delete('om_queued');
			u.searchParams.delete('om_complete_queued');
			history.replaceState && history.replaceState(null, '', u.toString());
		} catch (e) { /* ignore */ }
	}

	function poll() {
		getPending(function (pending) {
			if (pending === null) {
				setTimeout(poll, RETRY_MS);
				return;
			}
			if (pending > 0) {
				setMessage('Verbleibend: ' + pending);
				stalls = (pending === lastPending) ? stalls + 1 : 0;
				lastPending = pending;
				if (stalls === STALL_LIMIT) {
					showHint('Die Verarbeitung kommt nicht voran. Bitte unter <a href="' + cfg.jobsUrl + '">Offene Jobs</a> prüfen.');
				}
				processBatch();
				setTimeout(poll, POLL_MS);
				return;
			}
			setMessage('Abarbeitung abgeschlossen.');
			// Seite nur neu laden, wenn das Overlay noch sichtbar ist (sonst Arbeit des Nutzers nicht stören)
			if (!hidden) setTimeout(function () { window.location.reload(); }, 1000);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		removeQueryParams();
		getPending(function (pending) {
			if (!pending) return;
			createOverlay(pending);
			lastPending = pending;
			processBatch();
			setTimeout(poll, 700);
		});
	});
})();
