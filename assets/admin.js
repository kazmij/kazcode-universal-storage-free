(function () {
	'use strict';

	if (typeof s3msAdmin === 'undefined') {
		return;
	}

	var stopRequested = false;

	function headers() {
		return {
			'Content-Type': 'application/json',
			'X-WP-Nonce': s3msAdmin.nonce
		};
	}

	function appendLog(el, text) {
		if (!el) {
			return;
		}
		el.textContent += text + '\n';
		el.scrollTop = el.scrollHeight;
	}

	function renderSteps(stepsEl, steps) {
		if (!stepsEl) {
			return;
		}
		stepsEl.innerHTML = '';
		(steps || []).forEach(function (step) {
			var li = document.createElement('li');
			li.className = step.ok ? 'is-ok' : 'is-error';
			li.textContent = (step.ok ? 'OK' : 'FAIL') + ': ' + step.name + ' — ' + step.detail;
			stepsEl.appendChild(li);
		});
	}

	function setResultState(el, ok, text) {
		if (!el) {
			return;
		}
		el.textContent = text;
		el.classList.remove('is-ok', 'is-error');
		el.classList.add(ok ? 'is-ok' : 'is-error');
	}

	// restUrl is permalink-structure-dependent: pretty permalinks give a clean
	// path (".../wp-json/kazcode-storage/v1/", no query string yet), but plain
	// permalinks give ".../index.php?rest_route=/kazcode-storage/v1/" — already
	// a query string. Naively concatenating a path that has its own "?query"
	// (e.g. "failed?page=1&per_page=50") produced a second "?" that WordPress
	// can't parse under plain permalinks: everything after it became part of
	// the rest_route value itself, so the route never matched and every such
	// call silently 404'd — reproduced live on the Failed Items list and the
	// Health page's "Refresh cache" button. Split the route from its query and
	// join the query with "&" whenever restUrl already put a "?" in the URL.
	function buildRestUrl(path) {
		var qIndex = path.indexOf('?');
		var route = qIndex === -1 ? path : path.slice(0, qIndex);
		var extraQuery = qIndex === -1 ? '' : path.slice(qIndex + 1);
		var url = s3msAdmin.restUrl + route;
		if (extraQuery) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + extraQuery;
		}
		return url;
	}

	async function post(path, body) {
		var res = await fetch(buildRestUrl(path), {
			method: 'POST',
			headers: headers(),
			credentials: 'same-origin',
			body: JSON.stringify(body || {})
		});
		var data = await res.json().catch(function () {
			return {};
		});
		if (!res.ok) {
			var err = new Error((data && (data.message || data.code)) || s3msAdmin.i18n.failed);
			err.payload = data;
			err.status = res.status;
			throw err;
		}
		return data;
	}

	async function get(path) {
		var res = await fetch(buildRestUrl(path), {
			method: 'GET',
			headers: headers(),
			credentials: 'same-origin'
		});
		return res.json();
	}

	async function testConnectionAjax() {
		var body = new FormData();
		body.append('action', 'kazus_test_connection');
		body.append('nonce', s3msAdmin.ajaxNonce);

		var res = await fetch(s3msAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		});
		var data = await res.json().catch(function () {
			return {};
		});

		if (data && data.data && typeof data.data.success !== 'undefined') {
			return data.data;
		}
		if (data && typeof data.success !== 'undefined' && data.steps) {
			return data;
		}
		throw new Error((data && data.data && data.data.message) || data.message || s3msAdmin.i18n.failed);
	}

	// --- Provider-aware field visibility (Settings page) --------------------
	(function () {
		var providerSelect = document.getElementById('s3ms_provider_preset');
		var credentialRadios = document.querySelectorAll('input[name$="[credential_mode]"]');

		if (!providerSelect && !credentialRadios.length) {
			return;
		}

		function currentProvider() {
			return providerSelect ? providerSelect.value : 'aws';
		}

		function currentCredentialMode() {
			var checked = document.querySelector('input[name$="[credential_mode]"]:checked');
			return checked ? checked.value : 'keys';
		}

		function applyVisibility() {
			var provider = currentProvider();
			var credentialMode = currentCredentialMode();

			document.querySelectorAll('[data-s3ms-hide-for-provider]').forEach(function (el) {
				el.hidden = el.getAttribute('data-s3ms-hide-for-provider') === provider;
			});
			document.querySelectorAll('[data-s3ms-show-only-for-provider]').forEach(function (el) {
				el.hidden = el.getAttribute('data-s3ms-show-only-for-provider') !== provider;
			});
			document.querySelectorAll('[data-s3ms-hide-for-credential-mode]').forEach(function (el) {
				el.hidden = el.getAttribute('data-s3ms-hide-for-credential-mode') === credentialMode;
			});
		}

		if (providerSelect) {
			providerSelect.addEventListener('change', function () {
				// IAM role only exists for real AWS S3 — snap back to "keys" so a
				// now-hidden, stale radio choice can't silently stay selected.
				if (providerSelect.value !== 'aws') {
					var iamRadio = document.querySelector('input[name$="[credential_mode]"][value="iam_role"]');
					var keysRadio = document.querySelector('input[name$="[credential_mode]"][value="keys"]');
					if (iamRadio && iamRadio.checked && keysRadio) {
						keysRadio.checked = true;
					}
				}
				applyVisibility();
			});
		}
		credentialRadios.forEach(function (radio) {
			radio.addEventListener('change', applyVisibility);
		});

		applyVisibility();
	})();

	var testBtn = document.getElementById('s3ms-test-connection');
	if (testBtn) {
		testBtn.addEventListener('click', async function () {
			var resultEl = document.getElementById('s3ms-test-result');
			var stepsEl = document.getElementById('s3ms-test-steps');
			resultEl.classList.remove('is-ok', 'is-error');
			resultEl.textContent = s3msAdmin.i18n.testing;
			if (stepsEl) {
				stepsEl.innerHTML = '';
			}
			testBtn.disabled = true;
			try {
				var data;
				if (s3msAdmin.ajaxUrl && s3msAdmin.ajaxNonce) {
					data = await testConnectionAjax();
				} else {
					data = await post('test-connection', {});
				}
				setResultState(resultEl, !!data.success, data.success ? s3msAdmin.i18n.success : s3msAdmin.i18n.failed);
				renderSteps(stepsEl, data.steps || []);
			} catch (e) {
				setResultState(resultEl, false, s3msAdmin.i18n.failed);
				var payload = e && e.payload ? e.payload : null;
				if (payload && payload.steps) {
					renderSteps(stepsEl, payload.steps);
				} else if (payload && payload.data && payload.data.steps) {
					renderSteps(stepsEl, payload.data.steps);
				} else if (stepsEl) {
					var li = document.createElement('li');
					li.className = 'is-error';
					li.textContent = String(e && e.message ? e.message : e);
					stepsEl.appendChild(li);
				}
			} finally {
				testBtn.disabled = false;
			}
		});
	}

	var stopBtn = document.getElementById('s3ms-batch-stop');
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			stopRequested = true;
		});
	}

	var log = document.getElementById('s3ms-migration-log');
	document.querySelectorAll('[data-s3ms-action]').forEach(function (btn) {
		btn.addEventListener('click', async function () {
			var action = btn.getAttribute('data-s3ms-action');
			var batchSize = parseInt((document.getElementById('s3ms-batch-size') || {}).value || '20', 10);
			var buttons = document.querySelectorAll('[data-s3ms-action]');
			stopRequested = false;
			if (stopBtn) {
				stopBtn.hidden = false;
			}
			buttons.forEach(function (b) {
				b.disabled = true;
			});
			appendLog(log, s3msAdmin.i18n.running + ' (' + action + ')');

			try {
				var afterId = 0;
				var rounds = 0;
				var data;
				do {
					if (stopRequested) {
						appendLog(log, s3msAdmin.i18n.stop || 'Stopped.');
						break;
					}
					rounds += 1;
					if (action === 'dry-run') {
						data = await post('migrate-batch', { batch_size: batchSize, dry_run: true, after_id: afterId });
					} else if (action === 'migrate') {
						data = await post('migrate-batch', { batch_size: batchSize, dry_run: false, after_id: afterId });
					} else if (action === 'retry') {
						data = await post('migrate-batch', { batch_size: batchSize, retry_failed: true, after_id: afterId });
					} else if (action === 'verify') {
						data = await post('verify-batch', { batch_size: batchSize, after_id: afterId });
					} else if (action === 'restore') {
						data = await post('restore-batch', { batch_size: batchSize, after_id: afterId });
					} else if (action === 'adopt') {
						data = await post('adopt-batch', { batch_size: batchSize, after_id: afterId });
					} else {
						return;
					}
					appendLog(log, 'Round ' + rounds + ': ' + JSON.stringify(data));
					afterId = data && data.next_after_id ? data.next_after_id : afterId;
					if (!data || !data.processed || data.processed < batchSize || action === 'dry-run' || action === 'retry') {
						break;
					}
				} while (rounds < 500);

				appendLog(log, s3msAdmin.i18n.done);

				var stats = await get('stats');
				var statsRoot = document.getElementById('s3ms-stats');
				if (statsRoot && stats) {
					var keys = ['total', 'offloaded', 'pending', 'failed', 'verified'];
					var nodes = statsRoot.querySelectorAll('.s3ms-stat strong');
					keys.forEach(function (k, i) {
						if (nodes[i] && typeof stats[k] !== 'undefined') {
							nodes[i].textContent = String(stats[k]);
						}
					});
				}
			} catch (e) {
				appendLog(log, String(e && e.message ? e.message : e));
			} finally {
				buttons.forEach(function (b) {
					b.disabled = false;
				});
				if (stopBtn) {
					stopBtn.hidden = true;
				}
			}
		});
	});

	async function loadFailed() {
		var tbody = document.getElementById('s3ms-failed-tbody');
		var meta = document.getElementById('s3ms-failed-meta');
		var filterEl = document.getElementById('s3ms-failed-filter');
		if (!tbody) {
			return;
		}
		var filter = filterEl ? filterEl.value : 'all';
		tbody.innerHTML = '<tr><td colspan="6">Loading…</td></tr>';
		try {
			var data = await get('failed?page=1&per_page=50&filter=' + encodeURIComponent(filter));
			var items = (data && data.items) || [];
			if (!items.length) {
				tbody.innerHTML = '<tr><td colspan="6">No items</td></tr>';
			} else {
				tbody.innerHTML = items
					.map(function (row) {
						var flags = [];
						if (row.missing_local) {
							flags.push('missing local');
						}
						if (row.ignored) {
							flags.push('ignored');
						}
						return (
							'<tr>' +
							'<td><input type="checkbox" class="s3ms-failed-cb" value="' +
							row.id +
							'" /></td>' +
							'<td><a href="' +
							(row.edit_link || '#') +
							'">' +
							row.id +
							'</a></td>' +
							'<td>' +
							escapeHtml(row.title || '') +
							'</td>' +
							'<td><code>' +
							escapeHtml(row.file || '') +
							'</code></td>' +
							'<td>' +
							escapeHtml(row.error || '') +
							'</td>' +
							'<td>' +
							escapeHtml(flags.join(', ')) +
							'</td>' +
							'</tr>'
						);
					})
					.join('');
			}
			if (meta) {
				meta.textContent = 'Showing ' + items.length + ' of ' + (data.total || 0);
			}
		} catch (e) {
			tbody.innerHTML = '<tr><td colspan="6">' + escapeHtml(String(e && e.message ? e.message : e)) + '</td></tr>';
		}
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function selectedFailedIds() {
		return Array.prototype.slice
			.call(document.querySelectorAll('.s3ms-failed-cb:checked'))
			.map(function (el) {
				return parseInt(el.value, 10);
			})
			.filter(Boolean);
	}

	if (document.getElementById('s3ms-failed-tbody')) {
		loadFailed();
		var refresh = document.getElementById('s3ms-failed-refresh');
		var filter = document.getElementById('s3ms-failed-filter');
		if (refresh) {
			refresh.addEventListener('click', loadFailed);
		}
		if (filter) {
			filter.addEventListener('change', loadFailed);
		}
		var checkAll = document.getElementById('s3ms-failed-check-all');
		if (checkAll) {
			checkAll.addEventListener('change', function () {
				document.querySelectorAll('.s3ms-failed-cb').forEach(function (cb) {
					cb.checked = checkAll.checked;
				});
			});
		}
		var ignoreBtn = document.getElementById('s3ms-failed-ignore-selected');
		var unignoreBtn = document.getElementById('s3ms-failed-unignore-selected');
		if (ignoreBtn) {
			ignoreBtn.addEventListener('click', async function () {
				await post('failed/ignore', { ids: selectedFailedIds(), ignored: true });
				loadFailed();
			});
		}
		if (unignoreBtn) {
			unignoreBtn.addEventListener('click', async function () {
				await post('failed/ignore', { ids: selectedFailedIds(), ignored: false });
				loadFailed();
			});
		}
		var exportBtn = document.getElementById('s3ms-failed-export');
		if (exportBtn) {
			exportBtn.addEventListener('click', async function (ev) {
				ev.preventDefault();
				try {
					var data = await get('failed/export');
					var csv = data && data.csv ? data.csv : '';
					var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
					var url = URL.createObjectURL(blob);
					var a = document.createElement('a');
					a.href = url;
					a.download = 's3ms-failed-items.csv';
					a.click();
					URL.revokeObjectURL(url);
				} catch (e) {
					window.alert(String(e && e.message ? e.message : e));
				}
			});
		}
	}

	document.querySelectorAll('[data-s3ms-bg]').forEach(function (btn) {
		btn.addEventListener('click', async function () {
			var action = btn.getAttribute('data-s3ms-bg');
			try {
				await post('background/start', { job: action });
				window.location.reload();
			} catch (e) {
				window.alert(String(e && e.message ? e.message : e));
			}
		});
	});
	var bgStop = document.getElementById('s3ms-bg-stop');
	if (bgStop) {
		bgStop.addEventListener('click', async function () {
			try {
				await post('background/stop', {});
				window.location.reload();
			} catch (e) {
				window.alert(String(e && e.message ? e.message : e));
			}
		});
	}

	function appendToLog(el, text) {
		if (!el) {
			return;
		}
		el.textContent += text + '\n';
		el.scrollTop = el.scrollHeight;
	}

	var refreshStats = document.getElementById('s3ms-refresh-stats');
	if (refreshStats) {
		refreshStats.addEventListener('click', async function () {
			try {
				var data = await get('health/objects?refresh=1');
				var stats = data && data.object_stats ? data.object_stats : data;
				var root = document.getElementById('s3ms-object-stats');
				if (root && stats) {
					var map = {
						total_objects: stats.total_objects,
						present: stats.present,
						pending: stats.pending,
						missing: stats.missing,
						failed: stats.failed,
						stale: stats.stale
					};
					var nodes = root.querySelectorAll('.s3ms-stat strong');
					Object.keys(map).forEach(function (k, i) {
						if (nodes[i] && typeof map[k] !== 'undefined') {
							nodes[i].textContent = String(map[k]);
						}
					});
				}
				var meta = document.getElementById('s3ms-object-stats-meta');
				if (meta && stats && stats.generated_at) {
					meta.textContent = 'Cache generated: ' + stats.generated_at;
				}
			} catch (e) {
				window.alert(String(e && e.message ? e.message : e));
			}
		});
	}

	var healthScan = document.getElementById('s3ms-health-scan');
	if (healthScan) {
		healthScan.addEventListener('click', async function () {
			var logEl = document.getElementById('s3ms-health-scan-log');
			if (logEl) {
				logEl.textContent = '';
			}
			healthScan.disabled = true;
			try {
				var data = await post('health/scan', { limit: 500, after_id: 0 });
				appendToLog(logEl, JSON.stringify(data, null, 2));
			} catch (e) {
				appendToLog(logEl, String(e && e.message ? e.message : e));
			} finally {
				healthScan.disabled = false;
			}
		});
	}

	var orphanScan = document.getElementById('s3ms-orphan-scan');
	if (orphanScan) {
		orphanScan.addEventListener('click', async function () {
			var logEl = document.getElementById('s3ms-orphan-log');
			if (logEl) {
				logEl.textContent = '';
			}
			orphanScan.disabled = true;
			try {
				var data = await post('health/orphan-scan', {});
				appendToLog(logEl, JSON.stringify(data, null, 2));
			} catch (e) {
				appendToLog(logEl, String(e && e.message ? e.message : e));
			} finally {
				orphanScan.disabled = false;
			}
		});
	}

	var orphanAsync = document.getElementById('s3ms-orphan-async');
	if (orphanAsync) {
		orphanAsync.addEventListener('click', async function () {
			var logEl = document.getElementById('s3ms-orphan-log');
			if (logEl) {
				logEl.textContent = '';
			}
			orphanAsync.disabled = true;
			try {
				var data = await post('health/orphan-scan', { async: true });
				appendToLog(logEl, JSON.stringify(data, null, 2));
			} catch (e) {
				appendToLog(logEl, String(e && e.message ? e.message : e));
			} finally {
				orphanAsync.disabled = false;
			}
		});
	}

	function wizardPayload(extra) {
		var source = parseInt((document.getElementById('s3ms-wizard-source') || {}).value || '0', 10);
		var dest = parseInt((document.getElementById('s3ms-wizard-dest') || {}).value || '0', 10);
		var attachment = parseInt((document.getElementById('s3ms-wizard-attachment') || {}).value || '0', 10);
		var body = {
			source_profile_id: source,
			dest_profile_id: dest
		};
		if (attachment > 0) {
			body.attachment_id = attachment;
		}
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				body[k] = extra[k];
			});
		}
		return body;
	}

	var wizardLog = document.getElementById('s3ms-wizard-log');
	['s3ms-wizard-status', 's3ms-wizard-dry-run', 's3ms-wizard-migrate', 's3ms-wizard-switch'].forEach(function (id) {
		var btn = document.getElementById(id);
		if (!btn) {
			return;
		}
		btn.addEventListener('click', async function () {
			if (wizardLog) {
				wizardLog.textContent = '';
			}
			btn.disabled = true;
			try {
				var data;
				if (id === 's3ms-wizard-status') {
					data = await get('storage-migrate');
				} else if (id === 's3ms-wizard-dry-run') {
					data = await post('storage-migrate-batch', wizardPayload({ dry_run: true }));
				} else if (id === 's3ms-wizard-migrate') {
					data = await post('storage-migrate-batch', wizardPayload({ dry_run: false }));
				} else {
					data = await post('storage-migrate-switch', {
						dest_profile_id: parseInt((document.getElementById('s3ms-wizard-dest') || {}).value || '0', 10)
					});
				}
				appendToLog(wizardLog, JSON.stringify(data, null, 2));
			} catch (e) {
				appendToLog(wizardLog, String(e && e.message ? e.message : e));
			} finally {
				btn.disabled = false;
			}
		});
	});

	var profilesJsonEl = document.getElementById('s3ms-profiles-json');
	var profileEditor = document.getElementById('s3ms-profile-editor');
	if (profilesJsonEl && profileEditor) {
		var profilesById = {};
		try {
			(JSON.parse(profilesJsonEl.textContent || '[]') || []).forEach(function (p) {
				if (p && p.id) {
					profilesById[p.id] = p;
				}
			});
		} catch (e) {
			profilesById = {};
		}

		var form = document.getElementById('s3ms-profile-form');
		var idField = document.getElementById('s3ms-profile-id');
		var resultEl = document.getElementById('s3ms-profile-form-result');
		var lockedNote = document.getElementById('s3ms-profile-location-locked');
		var titleEl = document.getElementById('s3ms-profile-editor-title');

		function profilePayload() {
			var delivery = document.querySelector('input[name="s3ms_profile_delivery"]:checked');
			return {
				id: parseInt(idField.value || '0', 10) || undefined,
				name: (document.getElementById('s3ms-profile-name') || {}).value || '',
				provider_type: (document.getElementById('s3ms-profile-provider') || {}).value || 'aws',
				bucket: (document.getElementById('s3ms-profile-bucket') || {}).value || '',
				region: (document.getElementById('s3ms-profile-region') || {}).value || '',
				endpoint: (document.getElementById('s3ms-profile-endpoint') || {}).value || '',
				prefix: (document.getElementById('s3ms-profile-prefix') || {}).value || '',
				path_style: !!(document.getElementById('s3ms-profile-path-style') || {}).checked,
				delivery_type: delivery ? delivery.value : 'storage',
				delivery_base_url: (document.getElementById('s3ms-profile-delivery-url') || {}).value || '',
				cdn_includes_prefix: !!(document.getElementById('s3ms-profile-cdn-prefix') || {}).checked
			};
		}

		function setLocationEditable(editable) {
			document.querySelectorAll('.s3ms-profile-location-field input, .s3ms-profile-location-field select').forEach(function (el) {
				el.disabled = !editable;
			});
			if (lockedNote) {
				lockedNote.hidden = editable;
			}
		}

		function fillForm(profile) {
			idField.value = profile && profile.id ? String(profile.id) : '';
			(document.getElementById('s3ms-profile-name') || {}).value = profile && profile.name ? profile.name : '';
			(document.getElementById('s3ms-profile-provider') || {}).value = profile && profile.provider_type ? profile.provider_type : 'aws';
			(document.getElementById('s3ms-profile-bucket') || {}).value = profile && profile.bucket ? profile.bucket : '';
			(document.getElementById('s3ms-profile-region') || {}).value = profile && profile.region ? profile.region : 'us-east-1';
			(document.getElementById('s3ms-profile-endpoint') || {}).value = profile && profile.endpoint ? profile.endpoint : '';
			(document.getElementById('s3ms-profile-prefix') || {}).value = profile && profile.prefix ? profile.prefix : '';
			(document.getElementById('s3ms-profile-path-style') || {}).checked = !!(profile && profile.path_style);
			(document.getElementById('s3ms-profile-delivery-url') || {}).value = profile && profile.delivery_base_url ? profile.delivery_base_url : '';
			(document.getElementById('s3ms-profile-cdn-prefix') || {}).checked = !!(profile && profile.cdn_includes_prefix);
			var delivery = profile && profile.delivery_type === 'cdn' ? 'cdn' : 'storage';
			document.querySelectorAll('input[name="s3ms_profile_delivery"]').forEach(function (el) {
				el.checked = el.value === delivery;
			});
			setLocationEditable(!profile || profile.location_editable !== false);
		}

		function openEditor(profile) {
			fillForm(profile || null);
			if (titleEl) {
				titleEl.textContent = profile && profile.id ? 'Edit storage profile' : 'Add storage profile';
			}
			if (resultEl) {
				resultEl.textContent = '';
				resultEl.classList.remove('is-ok', 'is-error');
			}
			profileEditor.hidden = false;
			profileEditor.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		function closeEditor() {
			profileEditor.hidden = true;
		}

		document.querySelectorAll('.s3ms-profile-edit').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = parseInt(btn.getAttribute('data-profile-id') || '0', 10);
				openEditor(profilesById[id] || null);
			});
		});

		var addBtn = document.getElementById('s3ms-profile-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				openEditor(null);
			});
		}

		var cancelBtn = document.getElementById('s3ms-profile-cancel');
		if (cancelBtn) {
			cancelBtn.addEventListener('click', closeEditor);
		}

		document.querySelectorAll('.s3ms-profile-default').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				var id = parseInt(btn.getAttribute('data-profile-id') || '0', 10);
				if (!id || !window.confirm('Set this profile as the default upload target?')) {
					return;
				}
				btn.disabled = true;
				try {
					await post('storage-profiles/default', { id: id });
					window.location.reload();
				} catch (e) {
					window.alert(String(e && e.message ? e.message : e));
				} finally {
					btn.disabled = false;
				}
			});
		});

		document.querySelectorAll('.s3ms-profile-delete').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				var id = parseInt(btn.getAttribute('data-profile-id') || '0', 10);
				if (!id || !window.confirm('Delete this storage profile? This cannot be undone.')) {
					return;
				}
				btn.disabled = true;
				try {
					await post('storage-profiles/delete', { id: id });
					window.location.reload();
				} catch (e) {
					window.alert(String(e && e.message ? e.message : e));
				} finally {
					btn.disabled = false;
				}
			});
		});

		if (form) {
			form.addEventListener('submit', async function (ev) {
				ev.preventDefault();
				var saveBtn = document.getElementById('s3ms-profile-save');
				if (saveBtn) {
					saveBtn.disabled = true;
				}
				if (resultEl) {
					resultEl.textContent = s3msAdmin.i18n.running || 'Saving…';
				}
				try {
					var data = await post('storage-profiles/save', profilePayload());
					setResultState(resultEl, true, s3msAdmin.i18n.success || 'Saved.');
					window.setTimeout(function () {
						window.location.reload();
					}, 400);
				} catch (e) {
					setResultState(resultEl, false, String(e && e.message ? e.message : e));
				} finally {
					if (saveBtn) {
						saveBtn.disabled = false;
					}
				}
			});
		}

		// Allow editing the sole default profile on Free (no Add button).
		if (!addBtn && Object.keys(profilesById).length === 1) {
			var only = profilesById[Object.keys(profilesById)[0]];
			if (only) {
				var editOnly = document.querySelector('.s3ms-profile-edit');
				if (editOnly) {
					editOnly.focus();
				}
			}
		}
	}

	// --- Onboarding tour ---------------------------------------------------
	(function () {
		var steps = Array.prototype.slice
			.call(document.querySelectorAll('[data-s3ms-tour-step]'))
			.sort(function (a, b) {
				return parseInt(a.getAttribute('data-s3ms-tour-step'), 10) - parseInt(b.getAttribute('data-s3ms-tour-step'), 10);
			});

		if (!steps.length) {
			return;
		}

		var current = -1;
		var root, tooltip;

		function dismissOnServer() {
			if (!s3msAdmin.ajaxUrl || !s3msAdmin.ajaxNonce) {
				return;
			}
			var body = new FormData();
			body.append('action', 'kazus_dismiss_tour');
			body.append('nonce', s3msAdmin.ajaxNonce);
			body.append('page', (window.s3msTour && window.s3msTour.page) || '');
			fetch(s3msAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
		}

		function clearHighlight() {
			document.querySelectorAll('.s3ms-tour-target').forEach(function (el) {
				el.classList.remove('s3ms-tour-target');
			});
		}

		function endTour(persist) {
			clearHighlight();
			if (root) {
				root.remove();
				root = null;
				tooltip = null;
			}
			current = -1;
			if (persist) {
				dismissOnServer();
			}
		}

		function positionTooltip(target) {
			var rect = target.getBoundingClientRect();
			var top = rect.bottom + window.scrollY + 12;
			var left = Math.min(rect.left + window.scrollX, window.innerWidth - 340);
			tooltip.style.top = top + 'px';
			tooltip.style.left = Math.max(16, left) + 'px';
		}

		function renderStep() {
			var step = steps[current];
			clearHighlight();
			step.classList.add('s3ms-tour-target');
			step.scrollIntoView({ behavior: 'smooth', block: 'center' });

			var title = step.getAttribute('data-s3ms-tour-title') || '';
			var text = step.getAttribute('data-s3ms-tour-text') || '';
			var isLast = current === steps.length - 1;

			tooltip.innerHTML =
				'<p class="s3ms-tour__progress"></p>' +
				'<h3 class="s3ms-tour__title"></h3>' +
				'<p class="s3ms-tour__text"></p>' +
				'<div class="s3ms-tour__actions">' +
				'<span>' +
				(current > 0 ? '<button type="button" class="button" data-tour-back>' + 'Back' + '</button>' : '') +
				'</span>' +
				'<span class="s3ms-tour__actions-right">' +
				'<button type="button" class="button" data-tour-skip>Skip</button>' +
				'<button type="button" class="button button-primary" data-tour-next>' +
				(isLast ? 'Finish' : 'Next') +
				'</button>' +
				'</span>' +
				'</div>';

			tooltip.querySelector('.s3ms-tour__progress').textContent = current + 1 + ' / ' + steps.length;
			tooltip.querySelector('.s3ms-tour__title').textContent = title;
			tooltip.querySelector('.s3ms-tour__text').textContent = text;

			window.setTimeout(function () {
				if (tooltip) {
					positionTooltip(step);
				}
			}, 260);

			var backBtn = tooltip.querySelector('[data-tour-back]');
			if (backBtn) {
				backBtn.addEventListener('click', function () {
					current -= 1;
					renderStep();
				});
			}
			tooltip.querySelector('[data-tour-skip]').addEventListener('click', function () {
				endTour(true);
			});
			tooltip.querySelector('[data-tour-next]').addEventListener('click', function () {
				if (isLast) {
					endTour(true);
				} else {
					current += 1;
					renderStep();
				}
			});
		}

		function startTour() {
			if (root) {
				return;
			}
			root = document.createElement('div');
			root.className = 's3ms-tour-overlay';
			tooltip = document.createElement('div');
			tooltip.className = 's3ms-tour-tooltip';
			root.appendChild(tooltip);
			document.body.appendChild(root);
			current = 0;
			renderStep();
		}

		var replayBtn = document.getElementById('s3ms-tour-replay');
		if (replayBtn) {
			replayBtn.addEventListener('click', function () {
				startTour();
			});
		}

		if (window.s3msTour && !window.s3msTour.dismissed) {
			window.setTimeout(startTour, 500);
		}
	})();

	// --- Pro info modal -----------------------------------------------------
	(function () {
		var modal = document.getElementById('kazus-pro-modal');
		if (!modal) {
			return;
		}
		var dialog = modal.querySelector('.kazus-modal__dialog');
		var lastFocused = null;

		function focusableEls() {
			return Array.prototype.slice.call(
				dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
			);
		}

		function onKeydown(ev) {
			if (ev.key === 'Escape') {
				closeModal();
				return;
			}
			if (ev.key !== 'Tab') {
				return;
			}
			var items = focusableEls();
			if (!items.length) {
				return;
			}
			var first = items[0];
			var last = items[items.length - 1];
			if (ev.shiftKey && document.activeElement === first) {
				ev.preventDefault();
				last.focus();
			} else if (!ev.shiftKey && document.activeElement === last) {
				ev.preventDefault();
				first.focus();
			}
		}

		function openModal() {
			lastFocused = document.activeElement;
			modal.hidden = false;
			modal.setAttribute('aria-hidden', 'false');
			document.addEventListener('keydown', onKeydown);
			var items = focusableEls();
			if (items.length) {
				items[0].focus();
			}
		}

		function closeModal() {
			modal.hidden = true;
			modal.setAttribute('aria-hidden', 'true');
			document.removeEventListener('keydown', onKeydown);
			if (lastFocused && typeof lastFocused.focus === 'function') {
				lastFocused.focus();
			}
		}

		document.querySelectorAll('[data-kazus-pro-modal-open]').forEach(function (btn) {
			btn.addEventListener('click', openModal);
		});
		modal.querySelectorAll('[data-kazus-pro-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', closeModal);
		});
	})();
})();
