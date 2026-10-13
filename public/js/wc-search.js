// RFQMS Admin Panel — the dashboards' WC number search (dashboard/_search.blade.php):
// as it's typed — after a short pause, so a word doesn't send a request per
// letter — the RFQs whose WC number has it are fetched (RfqController::suggest())
// and listed beneath it, the part that matched marked. Up/Down move through
// them, Enter opens the one marked (or, with none, the RFQ list searched for
// what's typed), Escape closes them; "/" anywhere else puts the cursor in
// the box. Listened for on the document, since a live update (live.js)
// swaps the dashboard — the box included — for a fresh one.
(function () {
    var DELAY = 180;
    var timer = null;
    var controller = null;
    var latest = 0;
    var active = -1;

    function parts() {
        var form = document.getElementById('wcSearchForm');
        if (!form) return null;
        return { form: form, input: document.getElementById('wcSearch'), menu: document.getElementById('wcSearchList') };
    }

    function escapeHtml(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    // The WC number with the part that matched marked, spaces and case aside.
    function marked(wc, term) {
        var plain = String(wc || '');
        var at = plain.toUpperCase().indexOf(term);
        if (!term || at === -1) return escapeHtml(plain);
        return escapeHtml(plain.slice(0, at)) + '<mark>' + escapeHtml(plain.slice(at, at + term.length)) + '</mark>' + escapeHtml(plain.slice(at + term.length));
    }

    function close(p) {
        p = p || parts();
        if (!p) return;
        p.menu.classList.add('d-none');
        p.menu.innerHTML = '';
        p.input.setAttribute('aria-expanded', 'false');
        p.input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function options(p) {
        return Array.prototype.slice.call(p.menu.querySelectorAll('[role="option"]'));
    }

    function mark(p, index) {
        var all = options(p);
        if (!all.length) return;
        active = (index + all.length) % all.length;
        all.forEach(function (option, i) {
            option.classList.toggle('is-active', i === active);
            option.setAttribute('aria-selected', i === active ? 'true' : 'false');
        });
        p.input.setAttribute('aria-activedescendant', all[active].id);
        all[active].scrollIntoView({ block: 'nearest' });
    }

    function render(p, data) {
        var term = data.term || '';
        var html;

        if (!data.results.length) {
            html = '<div class="dash-search-empty"><i class="bi bi-search"></i> No RFQ has a WC number with “' + escapeHtml(term) + '”.</div>';
        } else {
            html = '<div class="dash-search-head">' + data.total + (data.total === 1 ? ' RFQ' : ' RFQs') + ' with “' + escapeHtml(term) + '” in the WC number</div>';
            html += data.results.map(function (item, i) {
                return '<a href="' + escapeHtml(item.url) + '" class="dash-search-item" role="option" aria-selected="false" id="wcSearchOption' + i + '">'
                    + '<span class="dash-search-wc">' + marked(item.wc, term) + '</span>'
                    + '<span class="dash-search-main"><span class="dash-search-rfq">' + escapeHtml(item.rfq) + '</span>'
                    + '<span class="dash-search-subject">' + escapeHtml(item.subject) + '</span></span>'
                    + '<span class="badge ' + escapeHtml(item.priorityClass) + '">' + escapeHtml(item.priority) + '</span>'
                    + '<span class="badge ' + escapeHtml(item.statusClass) + '">' + escapeHtml(item.status) + '</span>'
                    + '</a>';
            }).join('');
            if (data.total > data.results.length) {
                html += '<a href="' + escapeHtml(data.all) + '" class="dash-search-all">See all ' + data.total + ' in the RFQ list <i class="bi bi-arrow-right"></i></a>';
            }
        }

        p.menu.innerHTML = html;
        p.menu.classList.remove('d-none');
        p.input.setAttribute('aria-expanded', 'true');
        active = -1;
    }

    function suggest(p) {
        var term = p.input.value.trim();
        if (!term) {
            close(p);
            return;
        }

        if (controller) controller.abort();
        controller = window.AbortController ? new AbortController() : null;
        var ticket = ++latest;
        p.form.classList.add('is-loading');

        fetch(p.form.dataset.suggestUrl + '?q=' + encodeURIComponent(term), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
        })
            .then(function (response) {
                if (!response.ok) throw new Error('suggest: ' + response.status);
                return response.json();
            })
            .then(function (data) {
                // Only the answer to what's in the box now.
                if (ticket !== latest || p.input.value.trim() === '') return;
                render(p, data);
            })
            .catch(function () {})
            .then(function () {
                if (ticket === latest) p.form.classList.remove('is-loading');
            });
    }

    document.addEventListener('input', function (event) {
        if (event.target.id !== 'wcSearch') return;
        var p = parts();
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { suggest(p); }, DELAY);
    });

    document.addEventListener('keydown', function (event) {
        var p = parts();
        if (!p) return;

        // "/" from anywhere but a field puts the cursor in the search.
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable;
        if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            p.input.focus();
            p.input.select();
            return;
        }

        if (event.target !== p.input) return;
        var open = !p.menu.classList.contains('d-none');

        if (event.key === 'ArrowDown' && open) {
            event.preventDefault();
            mark(p, active + 1);
        } else if (event.key === 'ArrowUp' && open) {
            event.preventDefault();
            mark(p, active - 1);
        } else if (event.key === 'Enter' && open && active > -1) {
            event.preventDefault();
            window.location.assign(options(p)[active].href);
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            close(p);
        }
    });

    // Clicking anywhere else closes the suggestions; back in the box, they
    // come back for what's there.
    document.addEventListener('click', function (event) {
        var p = parts();
        if (p && !p.form.contains(event.target)) close(p);
    });

    document.addEventListener('focusin', function (event) {
        if (event.target.id !== 'wcSearch') return;
        var p = parts();
        if (p.input.value.trim() && p.menu.classList.contains('d-none')) suggest(p);
    });
})();
