// RFQMS live updates — plain JS, no build step, no websocket server.
//
// Every few seconds the page asks /admin/live for the data's version: a token
// the server changes whenever an RFQ, an assignment, a comment or a message is
// written (see App\LiveVersion). When it isn't the one the page was built
// from, the page is fetched again in the background and brought up to date in
// place — no reload, so nothing you're doing is lost:
//
//   - the sidebar's counts, and the top bar's bell (alerts), always — and
//     anything new in the bell pops up as a toast, counts in the tab's title,
//     and (if they've let it) shows as a desktop notification while the tab's
//     in the background, which keeps checking too, if less often;
//   - the page body (<main>), unless someone's in the middle of something in
//     it (a dialog open, something typed, text selected), in which case the
//     update waits — for the dialog to close, or for a click on the notice —
//     rather than pull the page out from under them.
//
// The pages for users, roles and permissions keep their body as it is
// (main[data-live="off"]); their sidebar still updates.
(function () {
    var versionMeta = document.querySelector('meta[name="live-version"]');
    var pulseMeta = document.querySelector('meta[name="live-pulse"]');
    var main = document.getElementById('live-main');
    var indicator = document.getElementById('liveIndicator');
    if (!versionMeta || !pulseMeta || !main || !window.fetch || !window.DOMParser) return;

    // How often to ask — the Admin's setting, given by the page; kept within reason.
    var intervalMeta = document.querySelector('meta[name="live-interval"]');
    var INTERVAL = Math.max(2000, Math.min(60000, parseInt(intervalMeta && intervalMeta.content, 10) || 3000));
    var RETRY_INTERVAL = 10000; // after a failed check
    var COOL_DOWN = 10000; // when refreshes are coming thick and fast
    var HIDDEN_INTERVAL = 15000; // while the tab's in the background — still told of messages
    var baseTitle = document.title.replace(/^\(\d+\)\s+/, '');

    var pulseUrl = pulseMeta.content;
    // Without admin.js's rfqmsRebind (an old copy still cached) a swapped-in body
    // would have dead buttons, so leave the body be and only keep the sidebar live.
    var bodyIsLive = main.dataset.live === 'on' && typeof window.rfqmsRebind === 'function';
    var version = versionMeta.content; // what the page (or its last refresh) is built from
    var seen = version; // the latest the server has reported
    var pending = null; // a fetched page body not yet put in place
    var timer = null;
    var busy = false;
    var failures = 0;
    var holdUntil = 0;
    var refreshedAt = [];
    var notice = null;

    // ---------------------------------------------------------------- polling

    function schedule(delay) {
        clearTimeout(timer);
        timer = setTimeout(tick, delay);
    }

    // Quicker to back off when refreshes come one after another (something
    // rewriting the data on every visit, say) than to hammer the server.
    function nextDelay() {
        if (failures) return RETRY_INTERVAL;
        if (document.hidden) return Math.max(INTERVAL, HIDDEN_INTERVAL);

        var now = Date.now();
        refreshedAt = refreshedAt.filter(function (at) { return now - at < COOL_DOWN; });
        return refreshedAt.length >= 4 ? COOL_DOWN : INTERVAL;
    }

    function tick() {
        clearTimeout(timer);
        // A tab in the background keeps checking (less often — nextDelay()),
        // so a message reaches them wherever they are.
        if (busy) return;
        if (Date.now() < holdUntil) {
            schedule(1000);
            return;
        }

        busy = true;
        pulse()
            .then(function (latest) {
                seen = latest;
                failures = 0;
                setState('live');

                if (latest !== version) return refresh();
                if (pending) applyPending();
            })
            .catch(handleError)
            .then(function () {
                busy = false;
                schedule(nextDelay());
            });
    }

    // Signed out (or sent elsewhere) comes back as a redirect: reload and let
    // the server say where to.
    function request(url, accept) {
        return fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': accept, 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (response) {
            if (response.redirected || response.status === 401 || response.status === 419) {
                throw { signedOut: true };
            }
            return response;
        });
    }

    function pulse() {
        return request(pulseUrl, 'application/json')
            .then(function (response) {
                if (!response.ok) throw new Error('pulse failed: ' + response.status);
                return response.json();
            })
            .then(function (data) {
                return String(data.version);
            });
    }

    function handleError(error) {
        if (error && error.signedOut) {
            holdUntil = Date.now() + 60000;
            window.location.reload();
            return;
        }
        if (error && error.gone) {
            // The page can't be built any more — the RFQ was deleted, say.
            version = seen;
            showNotice('gone', 'This page has changed or is no longer available.', 'Reload', function () {
                window.location.reload();
            });
            return;
        }
        failures++;
        setState('offline');
    }

    // Once the page has gone off to somewhere else, don't poll on the way.
    document.addEventListener('submit', function (event) {
        if (!event.defaultPrevented) holdUntil = Date.now() + 15000;
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) tick();
    });
    window.addEventListener('online', tick);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) tick();
    });
    // Whatever waited for a dialog to close can go ahead (after the dialog's
    // own tidying-up).
    document.addEventListener('hidden.bs.modal', function () {
        if (pending) setTimeout(applyPending, 250);
    });

    // -------------------------------------------------------------- indicator

    function setState(state) {
        if (!indicator || indicator.dataset.state === state) return;

        indicator.dataset.state = state;
        indicator.querySelector('.live-label').textContent = state === 'live' ? 'Live' : 'Reconnecting…';
        indicator.title = state === 'live'
            ? 'This page updates by itself'
            : 'Can\'t reach the server — trying again';
    }

    // ---------------------------------------------------------------- refresh

    function refresh() {
        refreshedAt.push(Date.now());

        return request(window.location.href, 'text/html')
            .then(function (response) {
                if (response.status === 403 || response.status === 404) throw { gone: true };
                if (!response.ok) throw new Error('refresh failed: ' + response.status);
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var freshMain = doc.getElementById('live-main');
                var freshVersion = doc.querySelector('meta[name="live-version"]');
                if (!freshMain || !freshVersion) throw new Error('refresh failed: not a page');

                version = freshVersion.content;
                patchSidebar(doc);
                patchBell(doc);
                patchCritical(doc);

                if (bodyIsLive) {
                    pending = freshMain;
                    applyPending();
                }
            });
    }

    // The sidebar's counts — the red badges, and a collapsed group's total.
    // Only those: which groups are open, and what's highlighted, stay as they
    // are. The two sidebars are the same page's, so their links line up.
    function patchSidebar(doc) {
        var current = document.querySelector('.sidebar-nav');
        var fresh = doc.querySelector('.sidebar-nav');
        if (!current || !fresh) return;

        var selector = 'a.nav-link, .sidebar-group-title';
        var currentItems = current.querySelectorAll(selector);
        var freshItems = fresh.querySelectorAll(selector);
        if (currentItems.length !== freshItems.length) return;

        Array.prototype.forEach.call(freshItems, function (item, index) {
            syncCount(currentItems[index], item);
        });
    }

    function syncCount(target, source) {
        var wanted = source.querySelector('.nav-link-count');
        var badge = target.querySelector('.nav-link-count');

        if (!wanted) {
            if (badge) badge.remove();
            return;
        }
        if (!badge) {
            badge = document.importNode(wanted, true);
            target.appendChild(badge);
            bump(badge);
            return;
        }

        badge.title = wanted.title;
        if (badge.textContent !== wanted.textContent) {
            badge.textContent = wanted.textContent;
            bump(badge);
        }
    }

    // The top bar's bell (alerts): its count, and what's in its menu — even
    // while it's open, since the button and the menu themselves stay put.
    function patchBell(doc) {
        var current = document.getElementById('notificationBell');
        var fresh = doc.getElementById('notificationBell');
        if (!current || !fresh) return;

        var button = current.querySelector('.notification-bell-btn');
        var freshButton = fresh.querySelector('.notification-bell-btn');
        var menu = current.querySelector('.notification-menu');
        var freshMenu = fresh.querySelector('.notification-menu');
        if (!button || !freshButton || !menu || !freshMenu) return;

        var count = button.querySelector('.notification-bell-count');
        var wanted = freshButton.querySelector('.notification-bell-count');
        var grew = wanted && (!count || Number(wanted.textContent) > Number(count.textContent));

        // What's newly unread: an unread line the menu didn't have before.
        var known = {};
        menu.querySelectorAll('.notification-item.is-unread').forEach(function (item) { known[itemKey(item)] = true; });
        var arrived = Array.prototype.filter.call(freshMenu.querySelectorAll('.notification-item.is-unread'), function (item) {
            return !known[itemKey(item)];
        });

        button.innerHTML = freshButton.innerHTML;
        menu.innerHTML = freshMenu.innerHTML;
        if (grew) bump(button.querySelector('.notification-bell-count'));
        showUnreadInTitle();
        syncDesktopAlertsButton();
        if (grew) arrived.slice(0, 3).forEach(announce);
    }

    // A bell line, told apart by where it leads and what it says.
    function itemKey(item) {
        var text = item.querySelector('.notification-item-text');
        return item.getAttribute('href') + '|' + (text ? text.textContent.trim() : '');
    }

    // ----------------------------------------------------------- new arrivals

    // The tab's title leads with how many are unread — "(2) Dashboard" — so a
    // tab in the background shows it too.
    function showUnreadInTitle() {
        var badge = document.querySelector('#notificationBell .notification-bell-count');
        var unread = badge ? parseInt(badge.textContent, 10) || 0 : 0;
        document.title = unread > 0 ? '(' + unread + ') ' + baseTitle : baseTitle;
    }

    // Something new in the bell — a message, an alert: a toast on the page,
    // and a desktop notification too while the tab's in the background (if
    // they've allowed them — the bell's "Desktop alerts").
    function announce(item) {
        var textEl = item.querySelector('.notification-item-text');
        var text = textEl ? textEl.textContent.trim() : 'Something new';
        var isMessage = item.classList.contains('is-message');
        var heading = isMessage ? 'New message' : 'New alert';
        var url = item.getAttribute('href');

        showToast(heading, text, url, isMessage);

        if (document.hidden && window.Notification && Notification.permission === 'granted') {
            try {
                var desktop = new Notification(heading, { body: text, tag: url, renotify: true });
                desktop.onclick = function () {
                    window.focus();
                    window.location.href = url;
                    desktop.close();
                };
            } catch (e) {
                // Some browsers only notify from a service worker — the toast still shows.
            }
        }
    }

    function showToast(heading, text, url, isMessage) {
        var holder = document.getElementById('liveToasts');
        if (!holder) {
            holder = document.createElement('div');
            holder.id = 'liveToasts';
            holder.className = 'live-toasts';
            holder.setAttribute('aria-live', 'polite');
            document.body.appendChild(holder);
        }

        var toast = document.createElement('div');
        toast.className = 'live-toast' + (isMessage ? ' is-message' : '');
        toast.setAttribute('role', 'status');
        toast.innerHTML = '<span class="live-toast-icon"><i class="bi" aria-hidden="true"></i></span>'
            + '<span class="live-toast-body"><strong></strong><span class="live-toast-text"></span></span>'
            + '<a class="btn btn-sm btn-primary live-toast-open">Open</a>'
            + '<button type="button" class="btn-close live-toast-close" aria-label="Dismiss"></button>';
        toast.querySelector('.bi').classList.add(isMessage ? 'bi-chat-dots-fill' : 'bi-bell-fill');
        toast.querySelector('strong').textContent = heading;
        toast.querySelector('.live-toast-text').textContent = text;
        toast.querySelector('.live-toast-open').href = url;

        var close = function () {
            toast.classList.add('is-leaving');
            setTimeout(function () { toast.remove(); }, 250);
        };
        toast.querySelector('.live-toast-close').addEventListener('click', close);
        holder.appendChild(toast);

        // Long enough to read; kept while the pointer's on it.
        var timer = setTimeout(close, 9000);
        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () { timer = setTimeout(close, 4000); });
    }

    // The bell's "Desktop alerts": asks the browser, on their click, to let
    // the page notify them while it's in the background.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-desktop-alerts');
        if (!button || !window.Notification) return;
        event.preventDefault();
        Notification.requestPermission().then(function () { syncDesktopAlertsButton(); });
    });

    function syncDesktopAlertsButton() {
        document.querySelectorAll('.js-desktop-alerts').forEach(function (button) {
            button.classList.toggle('d-none', !window.Notification || Notification.permission !== 'default');
        });
    }

    // The top bar's Critical monitor button: its count and whether it glows.
    function patchCritical(doc) {
        var current = document.getElementById('criticalMonitorBtn');
        var fresh = doc.getElementById('criticalMonitorBtn');
        if (!current || !fresh) return;

        var count = current.querySelector('.critical-btn-count');
        var wanted = fresh.querySelector('.critical-btn-count');
        var grew = wanted && (!count || Number(wanted.textContent) > Number(count.textContent));

        current.className = fresh.className;
        current.title = fresh.title;
        current.innerHTML = fresh.innerHTML;
        if (grew) bump(current.querySelector('.critical-btn-count'));
    }

    function bump(badge) {
        badge.classList.remove('is-bumped');
        void badge.offsetWidth; // restart the animation
        badge.classList.add('is-bumped');
    }

    // ------------------------------------------------------------- swap in

    function applyPending() {
        if (!pending) return;

        if (pending.dataset.liveHash === main.dataset.liveHash) {
            // Something changed, but not anything this page shows.
            pending = null;
            hideNotice();
            return;
        }

        var reason = blocker();
        if (reason === 'dialog') {
            hideNotice(); // it goes ahead once the dialog closes
        } else if (reason) {
            showNotice('updates', 'New updates on this page.', 'Refresh', applyNow);
        } else {
            applyNow();
        }
    }

    function applyNow() {
        if (!pending || blocker() === 'dialog') return;

        var fresh = pending;
        pending = null;
        hideNotice();
        swapBody(fresh);
    }

    // What makes it a bad moment to replace the page body, if anything.
    function blocker() {
        if (document.querySelector('.modal.show, .offcanvas.show')) return 'dialog';
        if (main.querySelector('.dropdown-menu.show')) return 'menu';
        if (hasUnsavedInput()) return 'typing';

        var selection = window.getSelection ? window.getSelection() : null;
        if (selection && !selection.isCollapsed && main.contains(selection.anchorNode)) return 'selection';

        return null;
    }

    // Something typed or picked that isn't what the page came with.
    function hasUnsavedInput() {
        return Array.prototype.some.call(main.querySelectorAll('input, textarea, select'), function (field) {
            if (field.disabled || field.type === 'hidden' || field.type === 'submit' || field.type === 'button') return false;

            if (field.tagName === 'SELECT') {
                if (field.multiple) {
                    return Array.prototype.some.call(field.options, function (option) {
                        return option.selected !== option.defaultSelected;
                    });
                }
                var initial = Array.prototype.findIndex.call(field.options, function (option) {
                    return option.defaultSelected;
                });
                return field.selectedIndex !== Math.max(initial, 0);
            }
            if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;

            return field.value !== field.defaultValue;
        });
    }

    function swapBody(fresh) {
        var ui = captureUi();

        // Messages like "Saved." are on the page but not in what the server
        // sends now — keep them until they're dismissed.
        var flashes = Array.prototype.filter.call(main.children, function (el) {
            return el.classList.contains('alert');
        });

        main.innerHTML = fresh.innerHTML;
        main.dataset.liveHash = fresh.dataset.liveHash;
        main.classList.add('is-live'); // no entrance animations from here on

        flashes.reverse().forEach(function (el) {
            main.insertBefore(el, main.firstChild);
        });

        restoreState(ui);
        if (window.rfqmsRebind) window.rfqmsRebind();
        if (window.renderRfqCharts) window.renderRfqCharts();
        if (window.renderRfqProgressChart && document.getElementById('rfq-progress-chart')) window.renderRfqProgressChart();
        restorePositions(ui);

        document.dispatchEvent(new CustomEvent('rfqms:live-updated'));
    }

    // ------------------------------------------------- what a swap must keep

    // The parts of the page's state that live in the browser rather than the
    // server's HTML — which tab, what's folded away, where things are
    // scrolled — noted before the swap and put back after it.
    function captureUi() {
        var ui = { x: window.scrollX, y: window.scrollY, tabs: [], collapses: {}, groups: {}, scrollers: {}, tables: [], focus: '' };

        main.querySelectorAll('[data-bs-toggle="tab"].active, [data-bs-toggle="pill"].active').forEach(function (tab) {
            var target = tab.getAttribute('data-bs-target') || tab.getAttribute('href');
            if (target) ui.tabs.push(target);
        });
        main.querySelectorAll('.collapse[id]').forEach(function (el) {
            if (!el.closest('.modal')) ui.collapses[el.id] = el.classList.contains('show');
        });
        main.querySelectorAll('.rfq-group[data-rfq-id]').forEach(function (el) {
            ui.groups[el.dataset.rfqId] = el.classList.contains('is-collapsed');
        });
        main.querySelectorAll('[data-live-scroll]').forEach(function (el) {
            ui.scrollers[el.dataset.liveScroll] = {
                top: el.scrollTop,
                left: el.scrollLeft,
                atBottom: el.scrollHeight > el.clientHeight && el.scrollHeight - el.scrollTop - el.clientHeight < 8,
            };
        });
        main.querySelectorAll('.js-toggle-table[data-target]').forEach(function (button) {
            var table = document.getElementById(button.dataset.target);
            if (table && !table.classList.contains('d-none')) ui.tables.push(button.dataset.target);
        });

        var active = document.activeElement;
        if (active && active.id && main.contains(active)) ui.focus = active.id;

        return ui;
    }

    // Before the page's own wiring runs again, so that it sees things as they
    // now are.
    function restoreState(ui) {
        ui.tabs.forEach(function (target) {
            var trigger = main.querySelector(
                ['tab', 'pill'].map(function (kind) {
                    return '[data-bs-toggle="' + kind + '"][data-bs-target="' + target + '"], [data-bs-toggle="' + kind + '"][href="' + target + '"]';
                }).join(', ')
            );
            if (trigger && !trigger.classList.contains('active')) showTab(trigger, target);
        });

        Object.keys(ui.collapses).forEach(function (id) {
            var el = document.getElementById(id);
            var open = ui.collapses[id];
            if (!el || el.classList.contains('show') === open) return;

            el.classList.toggle('show', open);
            main.querySelectorAll('[data-bs-target="#' + id + '"], [href="#' + id + '"]').forEach(function (toggle) {
                toggle.classList.toggle('collapsed', !open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        Object.keys(ui.groups).forEach(function (id) {
            var group = main.querySelector('.rfq-group[data-rfq-id="' + id + '"]');
            if (!group) return;

            group.classList.toggle('is-collapsed', ui.groups[id]);
            var toggle = group.querySelector('.js-toggle-parts');
            if (toggle) toggle.setAttribute('aria-expanded', ui.groups[id] ? 'false' : 'true');
        });
    }

    // Bootstrap's own tab switch would fire its events (the Progress chart
    // highlights a step when its tab is shown) — this only sets the classes.
    function showTab(trigger, target) {
        var list = trigger.closest('[role="tablist"], .nav');
        if (list) {
            list.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]').forEach(function (other) {
                other.classList.remove('active');
                other.setAttribute('aria-selected', 'false');
            });
        }
        trigger.classList.add('active');
        trigger.setAttribute('aria-selected', 'true');

        var pane = main.querySelector(target);
        if (pane && pane.parentElement) {
            Array.prototype.forEach.call(pane.parentElement.children, function (sibling) {
                sibling.classList.remove('active', 'show');
            });
            pane.classList.add('active', 'show');
        }
    }

    // After the page's own wiring and its charts are back, since these need
    // their buttons working and their sizes known.
    function restorePositions(ui) {
        ui.tables.forEach(function (id) {
            var table = document.getElementById(id);
            var button = main.querySelector('.js-toggle-table[data-target="' + id + '"]');
            if (table && button && table.classList.contains('d-none')) button.click();
        });

        Object.keys(ui.scrollers).forEach(function (key) {
            var el = main.querySelector('[data-live-scroll="' + key + '"]');
            if (!el) return;

            var saved = ui.scrollers[key];
            el.scrollLeft = saved.left;
            el.scrollTop = saved.atBottom ? el.scrollHeight : saved.top; // a conversation stays at its latest
        });

        if (ui.focus) {
            var focused = document.getElementById(ui.focus);
            if (focused && focused !== document.activeElement) focused.focus({ preventScroll: true });
        }

        window.scrollTo(ui.x, ui.y);
    }

    // ----------------------------------------------------------------- notice

    // "New updates" (or "gone"), for when the body can't be swapped right now.
    function showNotice(key, message, label, action) {
        if (notice && notice.dataset.key === key) return;
        hideNotice();

        notice = document.createElement('div');
        notice.className = 'live-notice';
        notice.dataset.key = key;
        notice.setAttribute('role', 'status');
        notice.innerHTML = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i><span></span><button type="button" class="btn btn-sm btn-light"></button>';
        notice.querySelector('span').textContent = message;

        var button = notice.querySelector('button');
        button.textContent = label;
        button.addEventListener('click', action);

        document.body.appendChild(notice);
    }

    function hideNotice() {
        if (notice) notice.remove();
        notice = null;
    }

    showUnreadInTitle();
    syncDesktopAlertsButton();
    schedule(INTERVAL);
})();
