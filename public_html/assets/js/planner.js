/* LAW Transport – graficzny planer wyjazdów */
(function () {
    'use strict';

    const root = document.getElementById('planner');
    if (!root) {
        return;
    }

    const LT = window.LT;
    const esc = LT.escape;
    const canEdit = root.dataset.canEdit === '1';
    const canCreate = root.dataset.canCreate === '1';
    const LABEL_WIDTH = 210;
    const EDITABLE = ['planowany', 'zatwierdzony'];
    const DAY_NAMES = ['nd', 'pn', 'wt', 'śr', 'cz', 'pt', 'sb'];
    const MONTHS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    const el = {
        grid: root.querySelector('[data-pl-grid]'),
        scroll: root.querySelector('[data-pl-scroll]'),
        range: root.querySelector('[data-pl-range]'),
        date: root.querySelector('[data-pl-date]'),
        kind: root.querySelector('[data-pl-kind]'),
        search: root.querySelector('[data-pl-search]'),
        cancelled: root.querySelector('[data-pl-cancelled]'),
        pool: root.querySelector('[data-pl-pool]'),
        poolCount: root.querySelector('[data-pl-pool-count]'),
        chips: root.querySelector('[data-pl-chips]'),
        chipsTitle: root.querySelector('[data-pl-chips-title]'),
        chipsHint: root.querySelector('[data-pl-chips-hint]'),
        chipFilter: root.querySelector('[data-pl-chip-filter]'),
        panel: document.getElementById('plPanel'),
        panelBody: document.querySelector('[data-pl-panel-body]'),
        panelTitle: document.getElementById('plPanelTitle'),
    };

    const params = new URLSearchParams(window.location.search);
    const state = {
        from: startOfDay(parseDate(params.get('od')) || mondayOf(new Date())),
        days: [1, 3, 7, 14].includes(Number(params.get('dni'))) ? Number(params.get('dni')) : 7,
        view: params.get('widok') === 'kierowcy' ? 'kierowcy' : 'pojazdy',
        kind: '',
        q: '',
        cancelled: false,
        data: null,
        dayWidth: 200,
        busy: false,
        drag: null,
    };

    /* ---------- Daty ---------- */

    function pad(n) { return String(n).padStart(2, '0'); }

    function parseDate(value) {
        if (!value) {
            return null;
        }
        const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
        if (!m) {
            return null;
        }
        return new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0);
    }

    function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

    function mondayOf(d) {
        const day = (d.getDay() + 6) % 7;
        return new Date(d.getFullYear(), d.getMonth(), d.getDate() - day);
    }

    function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }

    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function ymdhm(d) { return ymd(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }

    function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

    function dayIndex(d) {
        return Math.round((startOfDay(d) - state.from) / 86400000);
    }

    function minutesPerPx() { return 1440 / state.dayWidth; }

    function xOf(d) {
        return dayIndex(d) * state.dayWidth + (d.getHours() * 60 + d.getMinutes()) / minutesPerPx();
    }

    function endX(d, totalWidth) {
        return d >= addDays(state.from, state.days) ? totalWidth : Math.min(totalWidth, xOf(d));
    }

    function dateAt(x, step) {
        const totalMinutes = Math.round((x * minutesPerPx()) / step) * step;
        return new Date(state.from.getFullYear(), state.from.getMonth(), state.from.getDate(), 0, totalMinutes);
    }

    function snapStep() {
        return state.days === 1 ? 15 : (state.days <= 3 ? 30 : (state.days <= 7 ? 60 : 120));
    }

    function rangeLabel() {
        const end = addDays(state.from, state.days - 1);
        const f = state.from;
        if (state.days === 1) {
            return DAY_NAMES[f.getDay()] + ', ' + f.getDate() + ' ' + MONTHS[f.getMonth()] + ' ' + f.getFullYear();
        }
        return f.getDate() + ' ' + MONTHS[f.getMonth()] + (f.getFullYear() !== end.getFullYear() ? ' ' + f.getFullYear() : '')
            + ' – ' + end.getDate() + ' ' + MONTHS[end.getMonth()] + ' ' + end.getFullYear();
    }

    /* ---------- Dane ---------- */

    function syncUrl() {
        const p = new URLSearchParams();
        p.set('od', ymd(state.from));
        p.set('dni', String(state.days));
        if (state.view === 'kierowcy') {
            p.set('widok', 'kierowcy');
        }
        history.replaceState(null, '', '/planer?' + p.toString());
    }

    function load(silent) {
        if (state.drag) {
            return Promise.resolve();
        }
        syncUrl();
        const url = '/planer/dane?od=' + ymd(state.from) + '&dni=' + state.days + (state.cancelled ? '&anulowane=1' : '');
        if (!silent) {
            showLoading(true);
        }
        return fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) {
                if (r.status === 401) {
                    window.location.href = '/logowanie?next=' + encodeURIComponent(location.pathname + location.search);
                    throw new Error('auth');
                }
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                state.data = data;
                render();
            })
            .catch(function (e) {
                if (e.message !== 'auth') {
                    LT.toast('Nie udało się wczytać planu: ' + e.message, 'error');
                }
            })
            .finally(function () { showLoading(false); });
    }

    function showLoading(on) {
        let overlay = el.scroll.querySelector('.pl-loading');
        if (on && !overlay) {
            overlay = document.createElement('div');
            overlay.className = 'pl-loading';
            overlay.innerHTML = '<div class="spinner-border text-secondary" role="status"></div>';
            el.scroll.appendChild(overlay);
        } else if (!on && overlay) {
            overlay.remove();
        }
    }

    function save(trip, changes) {
        const bar = el.grid.querySelector('.pl-trip[data-id="' + trip.id + '"]');
        if (bar) {
            bar.classList.add('is-saving');
        }
        return fetch('/planer/wyjazd/' + trip.id, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': LT.csrf()},
            body: JSON.stringify(changes),
        })
            .then(function (r) { return r.json().then(function (body) { return {ok: r.ok, body: body}; }); })
            .then(function (res) {
                if (!res.ok) {
                    LT.toast(res.body.error || 'Nie udało się zapisać zmiany.', 'error');
                    render();
                    return;
                }
                const idx = state.data.trips.findIndex(function (t) { return t.id === trip.id; });
                if (idx >= 0) {
                    state.data.trips[idx] = res.body.trip;
                }
                render();
                LT.toast('Zapisano: ' + (res.body.trip.numer || res.body.trip.cel));
                if (res.body.reapproval) {
                    LT.toast('Wyjazd był zatwierdzony. Po zmianie wrócił do statusu „Planowany” i wymaga ponownego zatwierdzenia.', 'warning', 7000);
                }
                (res.body.warnings || []).forEach(function (w) { LT.toast(w, 'warning', 8000); });
            })
            .catch(function () {
                LT.toast('Błąd połączenia. Zmiana nie została zapisana.', 'error');
                render();
            });
    }

    /* ---------- Render ---------- */

    function rows() {
        const data = state.data;
        const result = [];
        if (state.view === 'pojazdy') {
            const vehicles = data.vehicles.filter(function (v) { return !state.kind || v.rodzaj === state.kind; });
            const groups = [['wojskowy', 'Pojazdy wojskowe'], ['cywilny', 'Pojazdy cywilne'], ['', 'Pozostałe']];
            groups.forEach(function (g) {
                const list = vehicles.filter(function (v) { return g[0] ? v.rodzaj === g[0] : (v.rodzaj !== 'wojskowy' && v.rodzaj !== 'cywilny'); });
                if (list.length) {
                    result.push({group: g[1]});
                    list.forEach(function (v) {
                        result.push({
                            id: v.id,
                            html: '<span class="lt-plate' + (v.rodzaj === 'wojskowy' ? ' is-military' : '') + '">' + esc(v.nr) + '</span>'
                                + '<span class="sub">' + esc([v.name, v.typ].filter(Boolean).join(' · ')) + (v.kategoria ? ' · kat. ' + esc(v.kategoria) : '') + '</span>',
                            off: v.status !== 'sprawny',
                            title: v.status === 'w_obsludze' ? 'Pojazd w obsłudze / naprawie' : '',
                        });
                    });
                }
            });
        } else {
            result.push({group: 'Kierowcy'});
            data.drivers.forEach(function (d) {
                result.push({
                    id: d.id,
                    html: '<span class="d-flex align-items-center gap-2"><span class="lt-avatar" style="width:26px;height:26px;font-size:.66rem">' + esc(d.initials) + '</span><strong class="text-truncate">' + esc(d.name) + '</strong></span>'
                        + '<span class="sub">' + (d.status !== 'dostepny' ? '<span class="text-danger">' + esc(d.statusLabel) + '</span> · ' : '') + (d.kategorie ? 'kat. ' + esc(d.kategorie) : '') + '</span>',
                    off: d.status !== 'dostepny',
                    title: d.telefon ? 'tel. ' + d.telefon : '',
                });
            });
        }
        return result;
    }

    function rowKey(trip) { return state.view === 'pojazdy' ? trip.pojazd : trip.kierowca; }

    function visibleTrips() {
        const end = addDays(state.from, state.days);
        return state.data.trips.filter(function (t) {
            const s = parseDate(t.od);
            const e = parseDate(t.do);
            return s < end && e > state.from;
        });
    }

    function matches(trip) {
        if (!state.q) {
            return true;
        }
        const hay = [trip.numer, trip.cel, trip.skad, trip.dokad, trip.pojazdNr, trip.kierowcaName].join(' ').toLowerCase();
        return hay.indexOf(state.q) !== -1;
    }

    function computeDayWidth() {
        const available = Math.max(400, el.scroll.clientWidth - LABEL_WIDTH - 2);
        const min = {1: 1100, 3: 320, 7: 240, 14: 120}[state.days];
        state.dayWidth = Math.max(min, Math.floor(available / state.days));
    }

    function render() {
        if (!state.data) {
            return;
        }
        computeDayWidth();
        el.range.textContent = rangeLabel();
        el.date.value = ymd(state.from);

        const totalWidth = state.dayWidth * state.days;
        const today = ymd(new Date());
        const trips = visibleTrips();
        let html = '<div class="pl-head"><div class="pl-corner">' + (state.view === 'pojazdy' ? 'Pojazd' : 'Kierowca') + '</div><div class="pl-days" style="width:' + totalWidth + 'px">';

        const tick = state.days === 1 ? 1 : (state.days <= 3 ? 3 : (state.days <= 7 ? 6 : 24));
        for (let i = 0; i < state.days; i++) {
            const d = addDays(state.from, i);
            const count = trips.filter(function (t) { return ymd(parseDate(t.od)) === ymd(d); }).length;
            html += '<div class="pl-day-head' + (ymd(d) === today ? ' is-today' : '') + '" style="flex-basis:' + state.dayWidth + 'px">'
                + '<div class="t">' + DAY_NAMES[d.getDay()] + ' ' + d.getDate() + '.' + pad(d.getMonth() + 1) + (count ? '<small>' + count + ' wyj.</small>' : '') + '</div>';
            if (tick < 24) {
                html += '<div class="pl-hours">';
                for (let h = 0; h < 24; h += tick) {
                    html += '<span>' + pad(h) + (state.days === 1 ? ':00' : '') + '</span>';
                }
                html += '</div>';
            } else {
                html += '<div class="pl-hours"><span>&nbsp;</span></div>';
            }
            html += '</div>';
        }
        html += '</div></div>';

        const rowDefs = rows();
        const unassigned = trips.filter(function (t) { return !rowKey(t); });
        const lineEvery = state.days === 1 ? state.dayWidth / 24 : state.dayWidth;

        function lane(rowId, list, extraClass) {
            const lanes = [];
            const placed = list.slice().sort(function (a, b) { return a.od < b.od ? -1 : 1; }).map(function (t) {
                const s = parseDate(t.od);
                const e = parseDate(t.do);
                let idx = lanes.findIndex(function (endAt) { return endAt <= s; });
                if (idx === -1) {
                    idx = lanes.length;
                    lanes.push(e);
                } else {
                    lanes[idx] = e;
                }
                return {trip: t, lane: idx, s: s, e: e};
            });
            const height = Math.max(1, lanes.length) * 50 + 8;
            let out = '<div class="pl-lane' + (extraClass || '') + '" data-row="' + (rowId || '') + '" style="width:' + totalWidth + 'px;height:' + height + 'px;background-size:' + lineEvery + 'px 100%">';
            for (let i = 0; i < state.days; i++) {
                const d = addDays(state.from, i);
                if (d.getDay() === 0 || d.getDay() === 6) {
                    out += '<div class="pl-weekend" style="left:' + i * state.dayWidth + 'px;width:' + state.dayWidth + 'px"></div>';
                }
                if (state.days <= 3) {
                    out += '<div class="pl-night" style="left:' + i * state.dayWidth + 'px;width:' + state.dayWidth * 6 / 24 + 'px"></div>';
                    out += '<div class="pl-night" style="left:' + (i * state.dayWidth + state.dayWidth * 22 / 24) + 'px;width:' + state.dayWidth * 2 / 24 + 'px"></div>';
                }
            }
            placed.forEach(function (p) { out += tripBar(p.trip, p.s, p.e, p.lane, totalWidth); });
            out += '</div>';
            return out;
        }

        if (unassigned.length) {
            html += '<div class="pl-group"><div>' + (state.view === 'pojazdy' ? 'Bez przydzielonego pojazdu' : 'Bez przydzielonego kierowcy') + '</div></div>';
            html += '<div class="pl-row"><div class="pl-label"><strong class="text-warning-emphasis"><i class="bi bi-inbox me-1"></i>Nieprzydzielone</strong><span class="sub">' + unassigned.length + ' wyj.</span></div>' + lane(null, unassigned) + '</div>';
        }

        rowDefs.forEach(function (r) {
            if (r.group) {
                html += '<div class="pl-group"><div>' + esc(r.group) + '</div></div>';
                return;
            }
            const list = trips.filter(function (t) { return rowKey(t) === r.id; });
            html += '<div class="pl-row' + (r.off ? ' is-off' : '') + '"' + (r.title ? ' title="' + esc(r.title) + '"' : '') + '><div class="pl-label">' + r.html + '</div>' + lane(r.id, list) + '</div>';
        });

        if (!rowDefs.length) {
            html += '<div class="lt-empty"><i class="bi bi-truck"></i>' + (state.view === 'pojazdy' ? 'Brak pojazdów. Dodaj je w zakładce Pojazdy.' : 'Brak kierowców. Dodaj ich w zakładce Kierowcy.') + '</div>';
        }

        el.grid.innerHTML = html;
        el.grid.style.width = (LABEL_WIDTH + totalWidth) + 'px';
        renderNow();
        renderSide(trips);
    }

    function tripBar(t, s, e, laneIdx, totalWidth) {
        const left = Math.max(0, xOf(s));
        const width = Math.max(18, endX(e, totalWidth) - left);
        const editable = canEdit && EDITABLE.indexOf(t.status) !== -1;
        const sub = state.view === 'pojazdy'
            ? [t.kierowcaName || 'brak kierowcy', t.dokad].filter(Boolean).join(' · ')
            : [t.pojazdNr || 'brak pojazdu', t.dokad].filter(Boolean).join(' · ');
        const tip = [
            (t.numer ? t.numer + ' · ' : '') + t.cel,
            hm(s) + ' ' + ymd(s) + ' → ' + hm(e) + ' ' + ymd(e),
            (t.skad || t.dokad) ? (t.skad || '?') + ' → ' + (t.dokad || '?') : '',
            'Pojazd: ' + (t.pojazdNr || '—') + ', kierowca: ' + (t.kierowcaName || '—'),
            'Status: ' + t.statusLabel,
        ].concat(t.errors.map(function (x) { return '⛔ ' + x; }), t.warnings.map(function (x) { return '⚠ ' + x; })).filter(Boolean).join('\n');
        const cls = ['pl-trip'];
        const compact = width < 110;
        if (compact) { cls.push('is-compact'); }
        if (t.errors.length) { cls.push('has-error'); } else if (t.warnings.length) { cls.push('has-warning'); }
        if (!matches(t)) { cls.push('is-dim'); }
        const color = state.view === 'kierowcy' && t.kolor ? ';--veh:' + t.kolor : '';
        return '<div class="' + cls.join(' ') + '" data-id="' + t.id + '" data-status="' + esc(t.status) + '"' + (editable ? ' data-editable="1"' : '')
            + ' style="left:' + left + 'px;width:' + width + 'px;top:' + (6 + laneIdx * 50) + 'px' + color + '" title="' + esc(tip) + '">'
            + (compact
                ? '<strong>' + esc(t.cel) + '</strong><span class="sub">' + esc(hm(s)) + '</span>'
                : '<strong>' + esc(hm(s)) + ' ' + esc(t.cel) + '</strong><span class="sub">' + esc(sub) + '</span>')
            + (editable ? '<span class="pl-resize" data-resize></span>' : '') + '</div>';
    }

    function renderNow() {
        const now = new Date();
        const idx = dayIndex(now);
        if (idx < 0 || idx >= state.days) {
            return;
        }
        const x = xOf(now);
        el.grid.querySelectorAll('.pl-lane').forEach(function (laneEl) {
            const line = document.createElement('div');
            line.className = 'pl-now';
            line.style.left = x + 'px';
            laneEl.appendChild(line);
        });
    }

    function renderSide(trips) {
        if (!canEdit) {
            return;
        }
        const pool = trips.filter(function (t) { return (!t.pojazd || !t.kierowca) && t.status !== 'anulowany'; });
        el.poolCount.textContent = String(pool.length);
        el.pool.innerHTML = pool.length ? pool.map(function (t) {
            const s = parseDate(t.od);
            const missing = [!t.pojazd ? 'pojazdu' : '', !t.kierowca ? 'kierowcy' : ''].filter(Boolean).join(' i ');
            return '<div class="pl-pool-trip" data-pool-id="' + t.id + '"' + (EDITABLE.indexOf(t.status) !== -1 ? ' data-editable="1"' : '') + '>'
                + '<strong>' + esc(t.cel) + '</strong>'
                + '<span class="text-muted">' + DAY_NAMES[s.getDay()] + ' ' + s.getDate() + '.' + pad(s.getMonth() + 1) + ' ' + hm(s) + (t.dokad ? ' · ' + esc(t.dokad) : '') + '</span>'
                + '<div class="text-warning-emphasis small">brak ' + missing + '</div></div>';
        }).join('') : '<div class="text-muted small py-2"><i class="bi bi-check2-circle me-1"></i>Wszystkie wyjazdy mają pojazd i kierowcę.</div>';

        const filter = (el.chipFilter.value || '').toLowerCase();
        if (state.view === 'pojazdy') {
            el.chipsTitle.innerHTML = '<i class="bi bi-people me-2 text-secondary"></i>Kierowcy';
            el.chipsHint.textContent = 'Przeciągnij kierowcę na wyjazd, aby go przydzielić.';
            el.chips.innerHTML = state.data.drivers.filter(function (d) {
                return !filter || (d.name + ' ' + d.kategorie).toLowerCase().indexOf(filter) !== -1;
            }).map(function (d) {
                return '<div class="pl-chip' + (d.status !== 'dostepny' ? ' is-unavailable' : '') + '" data-chip="kierowca" data-chip-id="' + d.id + '" title="' + esc(d.name + (d.telefon ? ', tel. ' + d.telefon : '')) + '">'
                    + '<span class="lt-avatar">' + esc(d.initials) + '</span><div class="min-w-0"><div class="text-truncate fw-semibold">' + esc(d.short) + '</div>'
                    + '<small>' + (d.status !== 'dostepny' ? esc(d.statusLabel) + ' · ' : '') + (d.kategorie ? 'kat. ' + esc(d.kategorie) : 'brak kategorii') + '</small></div></div>';
            }).join('');
        } else {
            el.chipsTitle.innerHTML = '<i class="bi bi-truck me-2 text-secondary"></i>Pojazdy';
            el.chipsHint.textContent = 'Przeciągnij pojazd na wyjazd, aby go przydzielić.';
            el.chips.innerHTML = state.data.vehicles.filter(function (v) {
                return (!state.kind || v.rodzaj === state.kind) && (!filter || (v.nr + ' ' + v.name).toLowerCase().indexOf(filter) !== -1);
            }).map(function (v) {
                return '<div class="pl-chip' + (v.status !== 'sprawny' ? ' is-unavailable' : '') + '" data-chip="pojazd" data-chip-id="' + v.id + '">'
                    + '<span class="lt-plate' + (v.rodzaj === 'wojskowy' ? ' is-military' : '') + '">' + esc(v.nr) + '</span>'
                    + '<div class="min-w-0"><div class="text-truncate small fw-semibold">' + esc(v.name) + '</div><small>' + (v.kategoria ? 'kat. ' + esc(v.kategoria) : '') + (v.miejsca ? ' · ' + v.miejsca + ' miejsc' : '') + '</small></div></div>';
            }).join('');
        }
    }

    function tripById(id) {
        return state.data.trips.find(function (t) { return t.id === Number(id); });
    }

    /* ---------- Przeciąganie ---------- */

    function laneAt(x, y) {
        const target = document.elementFromPoint(x, y);
        return target ? target.closest('.pl-lane') : null;
    }

    function poolAt(x, y) {
        const target = document.elementFromPoint(x, y);
        return target ? target.closest('[data-pl-pool]') : null;
    }

    function clearHighlights() {
        el.grid.querySelectorAll('.pl-lane.is-over').forEach(function (n) { n.classList.remove('is-over'); });
        el.grid.querySelectorAll('.pl-trip.is-drop-target').forEach(function (n) { n.classList.remove('is-drop-target'); });
        el.pool.classList.remove('is-over');
        el.grid.querySelectorAll('.pl-ghost').forEach(function (n) { n.remove(); });
    }

    function showGhost(laneEl, start, end) {
        let ghost = laneEl.querySelector('.pl-ghost');
        if (!ghost) {
            clearHighlights();
            ghost = document.createElement('div');
            ghost.className = 'pl-ghost';
            laneEl.appendChild(ghost);
            laneEl.classList.add('is-over');
        }
        const left = Math.max(0, xOf(start));
        ghost.style.left = left + 'px';
        ghost.style.width = Math.max(18, endX(end, state.dayWidth * state.days) - left) + 'px';
        ghost.textContent = hm(start) + '–' + hm(end) + (ymd(start) !== ymd(end) ? ' (' + end.getDate() + '.' + pad(end.getMonth() + 1) + ')' : '');
    }

    function floating(html) {
        const f = document.createElement('div');
        f.className = 'pl-chip shadow';
        f.style.position = 'fixed';
        f.style.zIndex = '2000';
        f.style.pointerEvents = 'none';
        f.style.maxWidth = '240px';
        f.innerHTML = html;
        document.body.appendChild(f);
        return f;
    }

    function laneOffsetX(laneEl, clientX) {
        return clientX - laneEl.getBoundingClientRect().left;
    }

    function onPointerDown(event) {
        if (event.button !== 0 || !state.data) {
            return;
        }
        const resize = event.target.closest('[data-resize]');
        const bar = event.target.closest('.pl-trip');
        const chip = event.target.closest('[data-chip]');
        const poolTrip = event.target.closest('[data-pool-id]');

        if (bar) {
            const trip = tripById(bar.dataset.id);
            state.drag = {
                type: resize ? 'resize' : 'move',
                trip: trip,
                bar: bar,
                startX: event.clientX,
                startY: event.clientY,
                grab: event.clientX - bar.getBoundingClientRect().left,
                moved: false,
                editable: bar.dataset.editable === '1',
            };
        } else if (chip && canEdit) {
            state.drag = {type: 'chip', kind: chip.dataset.chip, id: Number(chip.dataset.chipId), html: chip.innerHTML, startX: event.clientX, startY: event.clientY, moved: false};
        } else if (poolTrip && canEdit && poolTrip.dataset.editable === '1') {
            state.drag = {type: 'pool', trip: tripById(poolTrip.dataset.poolId), html: poolTrip.innerHTML, startX: event.clientX, startY: event.clientY, moved: false};
        } else {
            return;
        }
        if (state.drag.type !== 'move' || state.drag.editable) {
            event.preventDefault();
        }
        document.addEventListener('pointermove', onPointerMove);
        document.addEventListener('pointerup', onPointerUp, {once: true});
    }

    function onPointerMove(event) {
        const d = state.drag;
        if (!d) {
            return;
        }
        if (!d.moved && Math.abs(event.clientX - d.startX) + Math.abs(event.clientY - d.startY) < 5) {
            return;
        }
        if ((d.type === 'move' || d.type === 'resize') && !d.editable) {
            return;
        }
        if (!d.moved) {
            d.moved = true;
            if (d.bar) {
                d.bar.classList.add('is-dragging');
            }
            if (d.type === 'chip' || d.type === 'pool') {
                d.float = floating(d.html);
            }
            document.body.style.cursor = d.type === 'resize' ? 'ew-resize' : 'grabbing';
        }
        if (d.float) {
            d.float.style.left = (event.clientX + 12) + 'px';
            d.float.style.top = (event.clientY + 8) + 'px';
        }

        const step = snapStep();
        if (d.type === 'resize') {
            const laneEl = d.bar.parentElement;
            const s = parseDate(d.trip.od);
            let end = dateAt(laneOffsetX(laneEl, event.clientX), step);
            if (end <= s) {
                end = new Date(s.getTime() + step * 60000);
            }
            d.target = {end: end};
            showGhost(laneEl, s, end);
            return;
        }

        if (d.type === 'chip') {
            clearHighlights();
            const target = document.elementFromPoint(event.clientX, event.clientY);
            const bar = target ? target.closest('.pl-trip[data-editable="1"]') : null;
            d.target = bar ? Number(bar.dataset.id) : null;
            if (bar) {
                bar.classList.add('is-drop-target');
            }
            return;
        }

        // move / pool
        const pool = d.type === 'move' ? poolAt(event.clientX, event.clientY) : null;
        if (pool) {
            clearHighlights();
            pool.classList.add('is-over');
            d.target = {pool: true};
            return;
        }
        const laneEl = laneAt(event.clientX, event.clientY);
        if (!laneEl) {
            clearHighlights();
            d.target = null;
            return;
        }
        const s0 = parseDate(d.trip.od);
        const e0 = parseDate(d.trip.do);
        const duration = e0 - s0;
        const start = dateAt(laneOffsetX(laneEl, event.clientX) - (d.type === 'move' ? d.grab : 0), step);
        const end = new Date(start.getTime() + duration);
        if (!laneEl.classList.contains('is-over')) {
            clearHighlights();
        }
        showGhost(laneEl, start, end);
        d.target = {row: laneEl.dataset.row ? Number(laneEl.dataset.row) : null, start: start, end: end};
    }

    function onPointerUp(event) {
        document.removeEventListener('pointermove', onPointerMove);
        document.body.style.cursor = '';
        const d = state.drag;
        state.drag = null;
        if (!d) {
            return;
        }
        if (d.float) {
            d.float.remove();
        }
        if (d.bar) {
            d.bar.classList.remove('is-dragging');
        }
        clearHighlights();

        if (!d.moved) {
            if (d.type === 'move' || d.type === 'resize') {
                openTrip(d.trip.id);
            } else if (d.type === 'pool') {
                openTrip(d.trip.id);
            }
            return;
        }
        if (!d.target) {
            return;
        }

        if (d.type === 'resize') {
            save(d.trip, {data_do: ymdhm(d.target.end)});
            return;
        }
        if (d.type === 'chip') {
            const trip = tripById(d.target);
            if (trip) {
                const change = {};
                change[d.kind] = d.id;
                save(trip, change);
            }
            return;
        }
        if (d.target.pool) {
            const change = {};
            change[state.view === 'pojazdy' ? 'pojazd' : 'kierowca'] = null;
            save(d.trip, change);
            return;
        }
        const change = {data_od: ymdhm(d.target.start), data_do: ymdhm(d.target.end)};
        const key = state.view === 'pojazdy' ? 'pojazd' : 'kierowca';
        if ((d.target.row || null) !== (d.trip[key] || null)) {
            change[key] = d.target.row;
        }
        if (change.data_od === d.trip.od.slice(0, 16) && change.data_do === d.trip.do.slice(0, 16) && !(key in change)) {
            return;
        }
        save(d.trip, change);
    }

    /* ---------- Panel szczegółów ---------- */

    function panel() {
        return window.bootstrap ? window.bootstrap.Offcanvas.getOrCreateInstance(el.panel) : null;
    }

    function openPanel(url, title) {
        el.panelTitle.textContent = title;
        el.panelBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary"></div></div>';
        const p = panel();
        if (p) {
            p.show();
        }
        fetch(url, {credentials: 'same-origin', headers: {'HX-Request': 'true'}})
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.text();
            })
            .then(function (html) {
                el.panelBody.innerHTML = html;
                if (window.htmx) {
                    window.htmx.process(el.panelBody);
                }
            })
            .catch(function (e) {
                el.panelBody.innerHTML = '<div class="alert alert-danger">Nie udało się wczytać: ' + esc(e.message) + '</div>';
            });
    }

    function returnPath() {
        return '/planer?od=' + ymd(state.from) + '&dni=' + state.days + (state.view === 'kierowcy' ? '&widok=kierowcy' : '');
    }

    function openTrip(id) {
        const trip = tripById(id);
        openPanel('/wyjazdy/' + id + '?panel=1&powrot=' + encodeURIComponent(returnPath()), trip ? (trip.numer ? 'Wyjazd ' + trip.numer : 'Wyjazd') : 'Wyjazd');
    }

    function openNew(start, rowId) {
        if (!canCreate) {
            return;
        }
        const s = start || new Date(state.from.getFullYear(), state.from.getMonth(), state.from.getDate(), 7, 0);
        const e = new Date(s.getTime() + 8 * 3600000);
        let url = '/wyjazdy/nowy?panel=1&od=' + encodeURIComponent(ymdhm(s)) + '&do=' + encodeURIComponent(ymdhm(e)) + '&powrot=' + encodeURIComponent(returnPath());
        if (rowId) {
            url += '&' + (state.view === 'pojazdy' ? 'pojazd' : 'kierowca') + '=' + rowId;
        }
        openPanel(url, 'Nowy wyjazd');
    }

    /* ---------- Zdarzenia ---------- */

    el.grid.addEventListener('pointerdown', onPointerDown);
    el.chips.addEventListener('pointerdown', onPointerDown);
    el.pool.addEventListener('pointerdown', onPointerDown);

    el.grid.addEventListener('dblclick', function (event) {
        const laneEl = event.target.closest('.pl-lane');
        if (!laneEl || event.target.closest('.pl-trip')) {
            return;
        }
        const start = dateAt(laneOffsetX(laneEl, event.clientX), snapStep());
        openNew(start, laneEl.dataset.row ? Number(laneEl.dataset.row) : null);
    });

    document.querySelectorAll('[data-pl-new]').forEach(function (btn) {
        btn.addEventListener('click', function () { openNew(null, null); });
    });

    root.querySelectorAll('[data-pl-nav]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            state.from = addDays(state.from, Number(btn.dataset.plNav) * state.days);
            load();
        });
    });

    root.querySelector('[data-pl-today]').addEventListener('click', function () {
        state.from = state.days === 7 || state.days === 14 ? mondayOf(new Date()) : startOfDay(new Date());
        load();
    });

    el.date.addEventListener('change', function () {
        const d = parseDate(el.date.value);
        if (d) {
            state.from = state.days >= 7 ? mondayOf(d) : d;
            load();
        }
    });

    root.querySelectorAll('input[name="plDays"]').forEach(function (input) {
        input.checked = Number(input.value) === state.days;
        input.addEventListener('change', function () {
            state.days = Number(input.value);
            if (state.days >= 7) {
                state.from = mondayOf(state.from);
            }
            load();
        });
    });

    root.querySelectorAll('input[name="plView"]').forEach(function (input) {
        input.checked = input.value === state.view;
        input.addEventListener('change', function () {
            state.view = input.value;
            syncUrl();
            render();
        });
    });

    el.kind.addEventListener('change', function () { state.kind = el.kind.value; render(); });
    el.search.addEventListener('input', function () { state.q = el.search.value.trim().toLowerCase(); render(); });
    el.cancelled.addEventListener('change', function () { state.cancelled = el.cancelled.checked; load(); });
    el.chipFilter.addEventListener('input', function () { if (state.data) { renderSide(visibleTrips()); } });

    document.addEventListener('keydown', function (event) {
        if (event.target.closest('input, textarea, select, .offcanvas.show')) {
            return;
        }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            state.from = addDays(state.from, (event.key === 'ArrowLeft' ? -1 : 1) * state.days);
            load();
        } else if (event.key === 't' || event.key === 'T') {
            root.querySelector('[data-pl-today]').click();
        }
    });

    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 120);
    });

    // Odświeżanie, żeby widzieć zmiany innych dyspozytorów.
    setInterval(function () {
        if (!document.hidden && !state.drag && !el.panel.classList.contains('show')) {
            load(true);
        }
    }, 60000);

    load().then(function () {
        // Przewiń do bieżącej chwili, jeśli jest w widocznym okresie.
        const idx = dayIndex(new Date());
        if (idx >= 0 && idx < state.days) {
            el.scroll.scrollLeft = Math.max(0, xOf(new Date()) - (state.days === 1 ? 200 : state.dayWidth / 2));
        }
    });
})();
