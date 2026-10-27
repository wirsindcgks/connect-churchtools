/**
 * Alle Bereiche des Backends: Rueckmeldung „ungespeichert", die Knoepfe, die
 * per AJAX etwas anstossen (Verbindung pruefen, Kalender/Ressourcen/Homepages
 * laden, synchronisieren, Updates pruefen), Farbfelder, Standardbilder,
 * Kalenderkacheln und „Shortcode kopieren".
 *
 * Stand bis 1.38 als Inline-Skript am Ende von SettingsPage::renderPage().
 * Nonces und Beschriftungen kommen jetzt ueber wp_localize_script() als
 * `ctpAdmin` (siehe SettingsPage::enqueueAssets()) - uebersetzt wird weiter
 * nur in PHP.
 */
(function () {
	'use strict';

	var config = window.ctpAdmin || { nonces: {}, labels: {} };
	var nonces = config.nonces;
	var labels = config.labels;

	/*
	 * Sobald ein Feld angefasst wurde, sagt die Leiste am Fuss des
	 * Formulars, dass etwas offen ist. Ohne diesen Hinweis ist der Zustand
	 * nicht zu erkennen: Im Design-Tab schalten die beiden Vorschauen beim
	 * Wechseln der Vorlage sofort um, die Seite sieht also fertig aus,
	 * obwohl noch nichts gespeichert ist.
	 *
	 * Hier und nicht in admin-design.js, weil die Leiste auf jedem Tab mit
	 * einem Einstellungsformular steht und jenes Skript nur im Design-Tab
	 * geladen wird.
	 *
	 * Bewusst kein beforeunload-Dialog: Der ist auf einer
	 * Einstellungsseite mehr Bevormundung als Hilfe, und die Leiste steht
	 * ohnehin immer im Bild.
	 */
	Array.prototype.forEach.call(document.querySelectorAll('.ctp-settings-form'), function (form) {
		var markDirty = function () {
			form.classList.add('ctp-settings-form--dirty');
		};
		form.addEventListener('change', markDirty);
		form.addEventListener('input', markDirty);
		// Die Reihenfolge-Editoren aendern ein verstecktes Feld per Skript,
		// und dabei feuert `change` nicht von selbst - der Zug mit der Maus
		// ist die Aenderung, die man am ehesten vergisst zu speichern.
		form.addEventListener('drop', markDirty);
	});

	// The instance/API-key inputs live on the "Verbindung" tab, but
	// "Kalender laden" sits on the "Kalender" tab — reading .value off a
	// getElementById() that returned null threw a TypeError there and left
	// the button stuck on "Lade…" forever. Both fields are optional to
	// begin with: effectiveConnection() falls back to the stored values
	// whenever a field wasn't submitted.
	function ctpFieldValue(id) {
		var field = document.getElementById(id);

		return field ? field.value : '';
	}

	/*
	 * Die eine Rueckmeldung, die jede Aktion im Backend benutzt. Vorher
	 * setzte jeder der drei Knoepfe nur .textContent auf einem nackten
	 * <span>: „Verbindung erfolgreich“ und „Verbindung fehlgeschlagen“
	 * sahen identisch aus, und ob gerade noch etwas laeuft, war nur am
	 * Wortlaut zu erkennen. state ist '', 'busy', 'success' oder 'error'
	 * und steuert Farbe und Symbol (siehe .ctp-inline-status in admin.css).
	 */
	function ctpSetStatus(element, state, message) {
		if (!element) {
			return;
		}

		element.className = 'ctp-inline-status' + (state ? ' is-' + state : '');
		element.textContent = message;
	}

	document.getElementById('ctp-test-connection')?.addEventListener('click', function () {
		var result = document.getElementById('ctp-test-connection-result');
		ctpSetStatus(result, 'busy', labels.checking);

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'ctp_test_connection',
				nonce: nonces.testConnection,
				instance: ctpFieldValue('ctp-instance'),
				api_key: ctpFieldValue('ctp-api-key'),
			}),
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				var fallback = data.success
					? labels.connectionOk
					: labels.connectionFailed;

				ctpSetStatus(
					result,
					data.success ? 'success' : 'error',
					(data.data && data.data.message) ? data.data.message : fallback
				);
			});
	});

	document.getElementById('ctp-fetch-calendars')?.addEventListener('click', function () {
		var button = this;
		var result = document.getElementById('ctp-fetch-calendars-result');
		button.disabled = true;
		ctpSetStatus(result, 'busy', labels.loading);

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'ctp_fetch_calendars',
				nonce: nonces.fetchCalendars,
				instance: ctpFieldValue('ctp-instance'),
				api_key: ctpFieldValue('ctp-api-key'),
			}),
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (data.success) {
					window.location.reload();
					return;
				}
				button.disabled = false;
				ctpSetStatus(result, 'error', (data.data && data.data.message)
					? data.data.message
					: labels.loadFailed);
			});
	});

	document.getElementById('ctp-fetch-resources')?.addEventListener('click', function () {
		var button = this;
		var result = document.getElementById('ctp-fetch-resources-result');
		button.disabled = true;
		ctpSetStatus(result, 'busy', labels.loading);

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'ctp_fetch_resources',
				nonce: nonces.fetchResources,
			}),
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (data.success) {
					window.location.reload();
					return;
				}
				button.disabled = false;
				ctpSetStatus(result, 'error', (data.data && data.data.message)
					? data.data.message
					: labels.loadFailed);
			});
	});

	/*
	 * Der Tab „Raeume": uebernimmt nach dem Speichern von selbst. Eigener
	 * Handler statt des Knopfs unten, weil der bei Erfolg die Seite neu
	 * laedt - nach einem Speichern truege die Adresse dann weiterhin
	 * `settings-updated`, und der Lauf startete endlos neu. Hier wird
	 * stattdessen die Zeile darunter aktualisiert.
	 */
	(function () {
		var block = document.querySelector('.ctp-rooms-apply');
		var button = document.getElementById('ctp-sync-rooms');

		if (!block || !button) {
			return;
		}

		var result = document.getElementById('ctp-sync-rooms-result');
		var summary = document.getElementById('ctp-rooms-summary');

		function uebernehmen() {
			button.disabled = true;
			ctpSetStatus(result, 'busy', labels.applying);

			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({
					action: 'ctp_run_sync',
					nonce: nonces.runSync,
				}),
			})
				.then(function (response) { return response.json(); })
				.then(function (data) {
					button.disabled = false;

					if (data.success) {
						ctpSetStatus(result, 'ok', labels.applied);

						if (summary && data.data && data.data.location_summary) {
							summary.textContent = data.data.location_summary;
						}

						return;
					}

					ctpSetStatus(result, 'error', (data.data && data.data.message)
						? data.data.message
						: labels.applyFailed);
				})
				.catch(function () {
					button.disabled = false;
					ctpSetStatus(result, 'error', labels.applyFailed);
				});
		}

		button.addEventListener('click', uebernehmen);

		if (block.dataset.auto === '1') {
			uebernehmen();
		}
	})();

	/*
	 * Die beiden Knoepfe des Bereichs „Gruppen" („Homepages" und
	 * „Synchronisation", dazu die Uebersicht). Gleiches Muster wie
	 * „Kalender laden" und „Jetzt synchronisieren", nur ohne Instanz- und
	 * Key-Feld im Formular: Beide nehmen die gespeicherte Verbindung.
	 */
	[
		['ctp-fetch-group-homepages', 'ctp_fetch_group_homepages', nonces.fetchGroupHomepages, labels.loading],
		['ctp-run-group-sync', 'ctp_run_group_sync', nonces.runGroupSync, labels.syncing],
		['ctp-run-post-sync', 'ctp_run_post_sync', nonces.runPostSync, labels.syncing],
	].forEach(function (entry) {
		document.getElementById(entry[0])?.addEventListener('click', function () {
			var button = this;
			var result = document.getElementById(entry[0] + '-result');
			button.disabled = true;
			ctpSetStatus(result, 'busy', entry[3]);

			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({ action: entry[1], nonce: entry[2] }),
			})
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (data.success) {
						window.location.reload();
						return;
					}
					button.disabled = false;
					ctpSetStatus(result, 'error', (data.data && data.data.message)
						? data.data.message
						: labels.failed);
				})
				.catch(function () {
					button.disabled = false;
					ctpSetStatus(result, 'error', labels.failed);
				});
		});
	});

	document.getElementById('ctp-run-sync')?.addEventListener('click', function () {
		var button = this;
		var result = document.getElementById('ctp-run-sync-result');
		button.disabled = true;
		ctpSetStatus(result, 'busy', labels.syncing);

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'ctp_run_sync',
				nonce: nonces.runSync,
			}),
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				button.disabled = false;
				if (data.success) {
					window.location.reload();
					return;
				}
				ctpSetStatus(result, 'error', (data.data && data.data.message)
					? data.data.message
					: labels.syncFailed);
			})
			// Wie bei den Gruppen: Ohne das bliebe der Knopf nach einem
			// Netzwerkfehler gesperrt und „Synchronisiere…" stehen.
			.catch(function () {
				button.disabled = false;
				ctpSetStatus(result, 'error', labels.syncFailed);
			});
	});

	/*
	 * Color fields (calendar rows + the Design tab's accent color): a
	 * native <input type="color"> swatch paired with a plain text field
	 * for the hex code, kept in sync in both directions. Only the swatch
	 * is submitted; the text field is pure input convenience, so an
	 * unparseable value can simply snap back instead of needing its own
	 * server-side validation.
	 *
	 * Delegated on document rather than bound per field so it also covers
	 * the Design tab's single accent field and anything added later,
	 * without either tab needing its own copy.
	 */
	function ctpApplyColor(field, value) {
		var swatch = field.querySelector('.ctp-color-input');
		var hex = field.querySelector('.ctp-color-hex');

		if (swatch) {
			swatch.value = value;
			// The Design tab's live preview listens for "input" on the
			// swatch (see assets/js/admin-design.js) — assigning .value
			// from script doesn't fire one on its own.
			swatch.dispatchEvent(new Event('input', { bubbles: true }));
		}
		if (hex) {
			hex.value = value;
		}
	}

	document.addEventListener('input', function (event) {
		var field = event.target.closest ? event.target.closest('.ctp-color-field') : null;
		if (!field) {
			return;
		}

		if (event.target.classList.contains('ctp-color-input')) {
			var hex = field.querySelector('.ctp-color-hex');
			// Not while the hex field is being typed in: the swatch echoes
			// back a normalized (lowercased) value, and rewriting the input
			// mid-keystroke would jump the caret to the end.
			if (hex && document.activeElement !== hex) {
				hex.value = event.target.value;
			}

			return;
		}

		if (event.target.classList.contains('ctp-color-hex')) {
			var typed = event.target.value.trim();
			if (typed.charAt(0) !== '#') {
				typed = '#' + typed;
			}
			// Only mirror a complete, well-formed value — otherwise every
			// keystroke of a half-typed code would repaint the swatch.
			if (/^#[0-9a-f]{6}$/i.test(typed)) {
				var swatch = field.querySelector('.ctp-color-input');
				if (swatch) {
					swatch.value = typed.toLowerCase();
					swatch.dispatchEvent(new Event('input', { bubbles: true }));
				}
			}
		}
	});

	// Leaving the field with something unparseable in it: restore the hex
	// text from the swatch, which still holds the last valid value.
	document.addEventListener('focusout', function (event) {
		if (!event.target.classList || !event.target.classList.contains('ctp-color-hex')) {
			return;
		}

		var field = event.target.closest('.ctp-color-field');
		var swatch = field ? field.querySelector('.ctp-color-input') : null;
		if (swatch) {
			event.target.value = swatch.value;
		}
	});

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.ctp-color-reset');
		if (!button) {
			return;
		}

		event.preventDefault();
		var field = button.closest('.ctp-color-field');
		if (field) {
			ctpApplyColor(field, button.dataset.defaultColor);
		}
	});

	document.querySelectorAll('.ctp-image-select').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();

			var cell = button.closest('.ctp-image-field');
			var input = cell.querySelector('.ctp-image-id');
			var preview = cell.querySelector('.ctp-image-preview');
			var removeButton = cell.querySelector('.ctp-image-remove');

			var frame = wp.media({
				title: labels.chooseDefaultImage,
				multiple: false,
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				input.value = attachment.id;
				preview.src = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
				preview.hidden = false;
				removeButton.hidden = false;
				button.textContent = labels.replace;
			});

			frame.open();
		});
	});

	document.querySelectorAll('.ctp-image-remove').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();

			var cell = button.closest('.ctp-image-field');
			cell.querySelector('.ctp-image-id').value = '0';
			var preview = cell.querySelector('.ctp-image-preview');
			preview.hidden = true;
			preview.src = '';
			button.hidden = true;

			var selectButton = cell.querySelector('.ctp-image-select');
			if (selectButton) {
				selectButton.textContent = labels.chooseImage;
			}
		});
	});

	document.getElementById('ctp-check-updates')?.addEventListener('click', function () {
		var button = this;
		var result = document.getElementById('ctp-check-updates-result');
		button.disabled = true;
		ctpSetStatus(result, 'busy', labels.checking);

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'ctp_check_updates',
				nonce: nonces.checkUpdates,
			}),
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				button.disabled = false;
				ctpSetStatus(
					result,
					data.success ? 'success' : 'error',
					(data.data && data.data.message)
						? data.data.message
						: labels.checkFailed
				);
				// Neu laden, damit Statuszeile und Hinweis oben denselben
				// Stand zeigen wie die eben eingeholte Auskunft - aber erst,
				// nachdem die Meldung kurz lesbar war.
				if (data.success) {
					setTimeout(function () { window.location.reload(); }, 1200);
				}
			});
	});

	/*
	 * Kalenderkacheln (Tab „Kalender“): der gedimmte Zustand einer
	 * inaktiven Kachel wird beim Klick sofort mitgezogen, statt erst nach
	 * dem Speichern zu erscheinen. Delegiert am Dokument, damit dieselbe
	 * Zeile auch fuer die Sammelschalter unten gilt.
	 */
	function ctpSyncCalendarCard(checkbox) {
		var card = checkbox.closest('.ctp-calendar-card');
		if (card) {
			card.classList.toggle('is-disabled', !checkbox.checked);
		}
	}

	document.addEventListener('change', function (event) {
		if (event.target.classList && event.target.classList.contains('ctp-calendar-enabled')) {
			ctpSyncCalendarCard(event.target);
		}
	});

	document.querySelectorAll('.ctp-calendar-bulk').forEach(function (button) {
		button.addEventListener('click', function () {
			var enable = button.dataset.enable === '1';
			// Nur was gerade sichtbar ist: steht ein Suchbegriff im Filter,
			// bedeutet „Alle aktivieren“ die gefilterte Auswahl - sonst
			// veraendert ein Klick stillschweigend auch Kalender, die man
			// in diesem Moment gar nicht vor sich hat.
			document.querySelectorAll('.ctp-calendar-card:not([hidden]) .ctp-calendar-enabled').forEach(function (checkbox) {
				checkbox.checked = enable;
				ctpSyncCalendarCard(checkbox);
			});
		});
	});

	document.getElementById('ctp-calendar-search')?.addEventListener('input', function () {
		var needle = this.value.trim().toLowerCase();
		var emptyHint = document.getElementById('ctp-calendar-no-match');
		var visible = 0;

		document.querySelectorAll('.ctp-calendar-card').forEach(function (card) {
			// Name *oder* ID: die ID steht im Shortcode und in
			// Fehlermeldungen, danach sucht man genauso.
			var match = needle === ''
				|| card.dataset.name.indexOf(needle) !== -1
				|| card.dataset.id.indexOf(needle) !== -1;
			card.hidden = !match;
			if (match) {
				visible++;
			}
		});

		if (emptyHint) {
			emptyHint.hidden = visible !== 0;
		}
	});

	document.querySelectorAll('.ctp-copy-shortcode').forEach(function (button) {
		// navigator.clipboard needs a secure context (HTTPS, or localhost for
		// local testing) — simply not offering the button's function is a safer
		// degrade than the deprecated document.execCommand('copy') fallback.
		if (!navigator.clipboard) {
			return;
		}

		button.addEventListener('click', function () {
			navigator.clipboard.writeText(button.dataset.shortcode).then(function () {
				var original = button.textContent;
				button.textContent = labels.copied;
				setTimeout(function () {
					button.textContent = original;
				}, 1500);
			});
		});
	});
})();
