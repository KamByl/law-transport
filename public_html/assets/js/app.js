/* LAW Transport – wspólne skrypty frontendu */
(function () {
    'use strict';

    const LT = window.LT = window.LT || {};

    LT.csrf = function () {
        const shell = document.querySelector('[hx-headers]');
        try {
            return JSON.parse(shell.getAttribute('hx-headers'))['X-CSRF-Token'] || '';
        } catch (e) {
            return '';
        }
    };

    LT.toast = function (message, type, timeout) {
        const stack = document.getElementById('ltToasts');
        if (!stack) {
            return;
        }
        const el = document.createElement('div');
        el.className = 'lt-toast' + (type ? ' is-' + type : '');
        const icon = type === 'error' ? 'bi-x-octagon' : (type === 'warning' ? 'bi-exclamation-triangle' : 'bi-check2-circle');
        el.innerHTML = '<i class="bi ' + icon + '"></i><div></div>';
        el.querySelector('div').textContent = message;
        stack.appendChild(el);
        setTimeout(function () { el.remove(); }, timeout || (type === 'error' ? 7000 : 4500));
    };

    LT.escape = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    };

    // Klikalne wiersze tabel
    document.addEventListener('click', function (event) {
        const row = event.target.closest('[data-href]');
        if (!row || event.target.closest('a, button, input, select, label, form')) {
            return;
        }
        window.location.href = row.getAttribute('data-href');
    });

    // Potwierdzenie przed wysłaniem formularza
    document.addEventListener('submit', function (event) {
        const form = event.target;
        const message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Zegar w pasku górnym
    const clock = document.querySelector('[data-clock]');
    if (clock) {
        const days = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
        const tick = function () {
            const d = new Date();
            const pad = function (n) { return String(n).padStart(2, '0'); };
            clock.textContent = days[d.getDay()] + ', ' + pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        };
        tick();
        setInterval(tick, 20000);
    }

    // Sesja wygasła w trakcie żądania htmx
    document.addEventListener('htmx:responseError', function (event) {
        if (event.detail.xhr.status === 401) {
            window.location.href = '/logowanie?next=' + encodeURIComponent(window.location.pathname + window.location.search);
            return;
        }
        LT.toast('Nie udało się wczytać danych (' + event.detail.xhr.status + ').', 'error');
    });
})();
