// RFQMS Admin Panel — plain JS, no build step.
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggleBtn = document.getElementById('sidebarToggle');

    function closeSidebar() {
        sidebar && sidebar.classList.remove('show');
        backdrop && backdrop.classList.remove('show');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    // Confirm before destructive actions (delete forms).
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // Role picker cards (create/edit user modals, RFQ assignment) — clicking
    // a card toggles its hidden checkbox/radio; keep the "selected" class in
    // sync with it so the highlighted state works even on browsers without
    // CSS :has() support.
    function syncRolePickCard(input) {
        var card = input.closest('.role-pick-card');
        if (card) card.classList.toggle('selected', input.checked);
    }

    document.querySelectorAll('.role-pick-input').forEach(function (input) {
        syncRolePickCard(input);
        input.addEventListener('change', function () {
            if (input.type === 'radio') {
                // Selecting a radio silently deselects its sibling without
                // firing a change event on it — resync the whole group so
                // the old card doesn't stay stuck highlighted.
                var form = input.closest('form');
                var group = form ? form.querySelectorAll('.role-pick-input[name="' + input.name + '"]') : [input];
                group.forEach(syncRolePickCard);
            } else {
                syncRolePickCard(input);
            }
        });
    });

    // Assign Sourcing modal's per-person workload button — it lives inside
    // a <label for="..."> associated with that card's checkbox, so without
    // this, clicking it would *also* toggle the checkbox: the label's
    // native behavior forwards any click it doesn't see prevented to its
    // control. preventDefault() alone is what suppresses that (checked
    // once the whole dispatch finishes, regardless of phase timing) — no
    // stopPropagation() here, since that would risk blocking Bootstrap's
    // own collapse data-api handler from ever seeing this same click.
    document.querySelectorAll('.js-role-pick-stats-btn').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
        });
    });

    // Populate the shared "Edit user" modal from the clicked row's data-* attributes.
    document.querySelectorAll('.js-edit-user').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('editUserForm');
            if (!form) return;

            form.action = button.dataset.action;
            form.querySelector('[name="user_id"]').value = button.dataset.id || '';
            form.querySelector('#edit-name').value = button.dataset.name || '';
            form.querySelector('#edit-email').value = button.dataset.email || '';
            form.querySelector('#edit-password').value = '';
            form.querySelector('#edit-password_confirmation').value = '';

            var roles = (button.dataset.roles || '').split(',').filter(Boolean);
            form.querySelectorAll('input[name="roles[]"]').forEach(function (checkbox) {
                checkbox.checked = roles.indexOf(checkbox.value) !== -1;
                syncRolePickCard(checkbox);
            });
        });
    });

    // Populate the shared "Edit RFQ" modal from the clicked row's data-* attributes.
    document.querySelectorAll('.js-edit-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('editRfqForm');
            if (!form) return;

            form.action = button.dataset.action;
            form.querySelector('[name="rfq_id"]').value = button.dataset.id || '';
            form.querySelector('#edit-wc_number').value = button.dataset.wcNumber || '';
            form.querySelector('#edit-rfq_number').value = button.dataset.rfqNumber || '';
            form.querySelector('#edit-priority_level').value = button.dataset.priorityLevel || 'Medium';
            form.querySelector('#edit-status').value = button.dataset.status || 'Pending';
            form.querySelector('#edit-subject').value = button.dataset.subject || '';
            form.querySelector('#edit-description').value = button.dataset.description || '';
        });
    });

    // Assign Sourcing wizard (see _assign_modal.blade.php). Two steps —
    // job category, then keep-whole-or-split and who takes which part — on
    // one form that only actually submits from the last step. Opened from
    // any .js-assign-rfq button, which carries the RFQ's number, its
    // planned split (empty until one's been made), and who already holds
    // which part. With a split already planned it skips straight to the
    // parts list and only offers the still-empty ones, since category and
    // split size are settled by then. One person can hold several parts —
    // they're worked and completed together, as one share — but once
    // someone's finished their share they can't be given more, so they're
    // disabled in every dropdown. See RfqController::assign() for the
    // server side.
    (function () {
        var modalEl = document.getElementById('assignRfqModal');
        var form = document.getElementById('assignRfqForm');
        var nextBtn = document.getElementById('assign-wizard-next');
        if (!modalEl || !form || !nextBtn) return; // no Sourcing users to pick from

        var NEW_OPTION = '__new__';
        var backBtn = document.getElementById('assign-wizard-back');
        var finishBtn = document.getElementById('assign-wizard-finish');
        var subtitle = document.getElementById('assign-wizard-subtitle');
        var stepsEl = document.getElementById('assign-wizard-steps');
        var panels = {
            1: form.querySelector('[data-step-panel="1"]'),
            2: form.querySelector('[data-step-panel="2"]'),
        };
        var errorEls = {
            1: document.getElementById('assign-step-1-error'),
            2: document.getElementById('assign-step-2-error'),
        };
        var categorySelect = document.getElementById('assign-category');
        var newCategoryWrap = document.getElementById('assign-new-category-wrap');
        var newCategoryInput = document.getElementById('assign-new-category');
        var splitInput = document.getElementById('assign-split-input');
        var choiceWrap = document.getElementById('assign-split-choice');
        var partsCountWrap = document.getElementById('assign-parts-count-wrap');
        var partsCountInput = document.getElementById('assign-parts-count');
        var partsList = document.getElementById('assign-parts-list');
        var partsHint = document.getElementById('assign-parts-hint');
        var userOptions = document.getElementById('assign-user-options');
        var maxParts = parseInt(partsCountInput.max, 10) || 20;

        var state = null;

        function isSplitChosen() {
            return form.querySelector('input[name="split_choice"]:checked').value === 'split';
        }

        // Part count while typing may be blank or out of range — fall back
        // to something renderable rather than clearing the list.
        function partsCount() {
            if (state.remaining) return state.total;
            if (!isSplitChosen()) return 1;
            var n = parseInt(partsCountInput.value, 10);
            return isNaN(n) ? 2 : Math.max(2, Math.min(maxParts, n));
        }

        function partLabel(part, total) {
            return total > 1 ? state.rfqNumber + '-P' + part + ' of P' + total : state.rfqNumber;
        }

        function showError(step, message) {
            errorEls[step].textContent = message || '';
            errorEls[step].classList.toggle('d-none', !message);
        }

        function showStep(step) {
            state.step = step;
            panels[1].classList.toggle('d-none', step !== 1);
            panels[2].classList.toggle('d-none', step !== 2);
            stepsEl.querySelectorAll('[data-step-tab]').forEach(function (tab) {
                var n = parseInt(tab.dataset.stepTab, 10);
                tab.classList.toggle('is-active', n === step);
                tab.classList.toggle('is-done', n < step);
            });
            backBtn.classList.toggle('d-none', step !== 2 || state.remaining);
            nextBtn.classList.toggle('d-none', step !== 1);
            finishBtn.classList.toggle('d-none', step !== 2);
            finishBtn.textContent = state.remaining ? 'Assign' : 'Finish';
            showError(1, '');
            showError(2, '');
        }

        function toggleNewCategory() {
            var isNew = categorySelect.value === NEW_OPTION;
            newCategoryWrap.classList.toggle('d-none', !isNew);
            if (isNew) newCategoryInput.focus();
        }

        // Disable, in every part's dropdown, anyone who's already finished
        // their share of this RFQ — see the note up top.
        function refreshUserOptions() {
            var finishedIds = Object.keys(state.assigned).filter(function (part) {
                return state.assigned[part].done;
            }).map(function (part) {
                return String(state.assigned[part].id);
            });
            partsList.querySelectorAll('select').forEach(function (select) {
                select.querySelectorAll('option[value]').forEach(function (option) {
                    if (!option.value) return;
                    option.disabled = finishedIds.indexOf(option.value) !== -1;
                });
            });
        }

        function buildPartRow(part, total, previousValue) {
            var row = document.createElement('div');
            row.className = 'wizard-part-row';

            var label = document.createElement('span');
            label.className = 'wizard-part-number';
            label.textContent = partLabel(part, total);
            row.appendChild(label);

            if (state.assigned[part]) {
                row.classList.add('is-assigned');
                var who = document.createElement('span');
                who.className = 'wizard-part-assignee';
                who.innerHTML = '<i class="bi bi-check-circle-fill"></i> ';
                who.appendChild(document.createTextNode(state.assigned[part].name));
                row.appendChild(who);
                return row;
            }

            var select = document.createElement('select');
            select.className = 'form-select form-select-sm wizard-part-select';
            select.name = 'assignments[' + part + ']';
            select.dataset.part = part;
            select.setAttribute('aria-label', 'Sourcing member for ' + partLabel(part, total));
            select.appendChild(userOptions.content.cloneNode(true));
            // A whole, unsplit RFQ needs someone; a part of a split can wait.
            select.querySelector('option[value=""]').textContent =
                !state.remaining && !isSplitChosen() ? 'Select a Sourcing member' : 'Leave unassigned';
            select.value = previousValue || '';
            select.addEventListener('change', refreshUserOptions);
            row.appendChild(select);
            return row;
        }

        function renderParts() {
            var total = partsCount();
            var previous = {};
            partsList.querySelectorAll('select').forEach(function (select) {
                previous[select.dataset.part] = select.value;
            });

            partsList.innerHTML = '';
            for (var part = 1; part <= total; part++) {
                partsList.appendChild(buildPartRow(part, total, previous[part]));
            }
            refreshUserOptions();

            if (state.remaining) {
                partsHint.textContent = 'Parts left on "Leave unassigned" can be assigned later. A person can take more than one part; they\'re worked and marked complete together. The RFQ moves on to Data Entry once every part is assigned and done.';
            } else if (isSplitChosen()) {
                partsHint.textContent = 'The same Sourcing member can take more than one part — they\'re worked and marked complete together. Parts left on "Leave unassigned" can be assigned later; the RFQ waits for every part before it moves on.';
            } else {
                partsHint.textContent = '';
            }
        }

        function validateStep1() {
            categorySelect.classList.remove('is-invalid');
            newCategoryInput.classList.remove('is-invalid');

            if (!categorySelect.value) {
                categorySelect.classList.add('is-invalid');
                showError(1, 'Pick a job category to continue.');
                return false;
            }
            if (categorySelect.value === NEW_OPTION && !newCategoryInput.value.trim()) {
                newCategoryInput.classList.add('is-invalid');
                showError(1, 'Type a name for the new category.');
                return false;
            }
            showError(1, '');
            return true;
        }

        function validateStep2() {
            var selects = partsList.querySelectorAll('select');
            var anyPicked = Array.prototype.some.call(selects, function (select) {
                return select.value !== '';
            });

            if (!state.remaining && isSplitChosen()) {
                var n = parseInt(partsCountInput.value, 10);
                if (isNaN(n) || n < 2 || n > maxParts) {
                    showError(2, 'Enter a number of parts between 2 and ' + maxParts + '.');
                    return false;
                }
            }
            if (!state.remaining && !isSplitChosen() && !anyPicked) {
                showError(2, 'Pick a Sourcing member to take this RFQ, or split it into parts.');
                return false;
            }
            if (state.remaining && !anyPicked) {
                showError(2, 'Pick someone for at least one part, or cancel to leave them for later.');
                return false;
            }
            showError(2, '');
            return true;
        }

        function parseAssigned(raw) {
            try {
                var parsed = JSON.parse(raw || '{}');
                return Array.isArray(parsed) ? {} : parsed;
            } catch (e) {
                return {};
            }
        }

        document.querySelectorAll('.js-assign-rfq').forEach(function (button) {
            button.addEventListener('click', function () {
                var splitCount = button.dataset.splitCount || '';
                state = {
                    step: 1,
                    remaining: splitCount !== '',
                    total: parseInt(splitCount, 10) || 1,
                    rfqNumber: button.dataset.rfqNumber || '',
                    assigned: parseAssigned(button.dataset.assigned),
                };

                form.action = button.dataset.action;
                categorySelect.value = '';
                newCategoryInput.value = '';
                categorySelect.classList.remove('is-invalid');
                newCategoryInput.classList.remove('is-invalid');
                newCategoryWrap.classList.add('d-none');
                form.querySelector('input[name="split_choice"][value="single"]').checked = true;
                partsCountInput.value = 2;
                partsCountWrap.classList.add('d-none');

                subtitle.textContent = state.rfqNumber + (state.remaining ? ' — assign the remaining parts' : '');
                choiceWrap.classList.toggle('d-none', state.remaining);
                stepsEl.classList.toggle('d-none', state.remaining);

                partsList.innerHTML = '';
                renderParts();
                showStep(state.remaining ? 2 : 1);
            });
        });

        categorySelect.addEventListener('change', function () {
            categorySelect.classList.remove('is-invalid');
            toggleNewCategory();
        });

        form.querySelectorAll('input[name="split_choice"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                partsCountWrap.classList.toggle('d-none', !isSplitChosen());
                renderParts();
            });
        });

        partsCountInput.addEventListener('input', renderParts);
        partsCountInput.addEventListener('change', function () {
            var n = parseInt(partsCountInput.value, 10);
            partsCountInput.value = isNaN(n) ? 2 : Math.max(2, Math.min(maxParts, n));
            renderParts();
        });

        nextBtn.addEventListener('click', function () {
            if (validateStep1()) showStep(2);
        });
        backBtn.addEventListener('click', function () {
            showStep(1);
        });

        form.addEventListener('submit', function (event) {
            // Enter in the new-category box would otherwise submit the whole
            // wizard from step 1 — treat it as "Next" instead.
            if (state && state.step === 1 && !state.remaining) {
                event.preventDefault();
                nextBtn.click();
                return;
            }
            if (!state || !validateStep2()) {
                event.preventDefault();
                return;
            }
            splitInput.value = !state.remaining && isSplitChosen() ? '1' : '0';
        });
    })();

    // Populate the shared "Assign Operations" modal from the clicked row's data-* attributes.
    document.querySelectorAll('.js-assign-operations-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('assignOperationsForm');
            if (!form) return;

            form.action = button.dataset.action;
            var currentId = button.dataset.operationsUserId || '';
            form.querySelectorAll('input[name="operations_user"]').forEach(function (radio) {
                radio.checked = radio.value === currentId;
                syncRolePickCard(radio);
            });
        });
    });

    // Head of Business Development's Reject modal — one shared modal,
    // populated per-row with which RFQ it's actually rejecting.
    document.querySelectorAll('.js-reject-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('rejectRfqForm');
            if (!form) return;

            form.action = button.dataset.action;
            var rfqIdField = form.querySelector('[name="reject_rfq_id"]');
            if (rfqIdField) rfqIdField.value = button.dataset.rfqId || '';
        });
    });

    // GM Assistant's Client Details / Payment Terms modal — same
    // shared-modal-populated-per-row pattern as the Reject modal above.
    document.querySelectorAll('.js-gm-assistant-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('gmAssistantForm');
            if (!form) return;

            form.action = button.dataset.action;
            var rfqIdField = form.querySelector('[name="gm_assistant_rfq_id"]');
            if (rfqIdField) rfqIdField.value = button.dataset.rfqId || '';
        });
    });

    // "By Sourcing" rows (Ready for Data Entry) — clicking anywhere on the
    // row opens that RFQ's quick-detail modal (subject, description,
    // Sourcing team, comments grouped by who wrote them). Our own listener
    // rather than data-bs-toggle directly on the row, because Bootstrap's
    // delegated modal/collapse handler runs in the capture phase and would
    // call preventDefault() on the nested View link before it ever gets a
    // chance to navigate.
    document.querySelectorAll('.js-de-sourcing-row').forEach(function (row) {
        var modalEl = document.querySelector(row.dataset.bsTarget);
        if (!modalEl) return;

        function open() {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form')) return;
            open();
        });

        row.addEventListener('keydown', function (e) {
            if (e.target.closest('a, button, form')) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });

    // "@mention" suggestions in comment/reply boxes — typing "@" opens a
    // dropdown of Sourcing team members (data from the JSON island in
    // show.blade.php); picking one inserts "@Full Name " at the cursor.
    // Rendered mentions are highlighted server-side, see
    // RfqComment::bodyWithMentions().
    (function () {
        var dataEl = document.getElementById('rfq-mention-users');
        var inputs = document.querySelectorAll('.js-mention-input');
        if (!dataEl || !inputs.length) return;

        var mentionUsers = [];
        try {
            mentionUsers = JSON.parse(dataEl.textContent || '[]');
        } catch (e) {
            mentionUsers = [];
        }
        if (!mentionUsers.length) return;

        var activeTextarea = null;
        var mentionStart = -1;

        var dropdown = document.createElement('div');
        dropdown.className = 'mention-dropdown';
        dropdown.style.display = 'none';
        document.body.appendChild(dropdown);

        function closeDropdown() {
            dropdown.style.display = 'none';
            activeTextarea = null;
            mentionStart = -1;
        }

        function insertMention(name) {
            if (!activeTextarea || mentionStart === -1) return;

            var value = activeTextarea.value;
            var caret = activeTextarea.selectionStart;
            var before = value.slice(0, mentionStart);
            var after = value.slice(caret);
            var inserted = '@' + name + ' ';

            activeTextarea.value = before + inserted + after;
            var newCaret = (before + inserted).length;
            activeTextarea.setSelectionRange(newCaret, newCaret);
            activeTextarea.focus();

            closeDropdown();
        }

        function renderSuggestions(textarea, query) {
            var q = query.toLowerCase();

            // Names starting with the query rank above ones that merely
            // contain it (so "Ri" favors "Riley Chen" over "Priya Desai").
            var matches = mentionUsers
                .filter(function (u) { return u.name.toLowerCase().indexOf(q) !== -1; })
                .sort(function (a, b) {
                    var aStarts = a.name.toLowerCase().indexOf(q) === 0 ? 0 : 1;
                    var bStarts = b.name.toLowerCase().indexOf(q) === 0 ? 0 : 1;
                    return aStarts !== bStarts ? aStarts - bStarts : a.name.localeCompare(b.name);
                })
                .slice(0, 6);

            if (!matches.length) {
                closeDropdown();
                return;
            }

            dropdown.innerHTML = '';
            matches.forEach(function (u, index) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'mention-dropdown-item' + (index === 0 ? ' is-active' : '');
                item.textContent = u.name;
                item.dataset.name = u.name;
                // mousedown (not click) fires before the textarea blurs and
                // closes the dropdown out from under it.
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    insertMention(u.name);
                });
                dropdown.appendChild(item);
            });

            var rect = textarea.getBoundingClientRect();
            dropdown.style.left = (rect.left + window.scrollX) + 'px';
            dropdown.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            dropdown.style.minWidth = Math.min(rect.width, 260) + 'px';
            dropdown.style.display = 'block';
        }

        inputs.forEach(function (textarea) {
            textarea.addEventListener('input', function () {
                var caret = textarea.selectionStart;
                var value = textarea.value;

                // Walk back from the caret for an "@" that starts the
                // current word (not glued to another non-space character).
                var at = -1;
                for (var i = caret - 1; i >= 0; i--) {
                    var ch = value[i];
                    if (ch === '@') {
                        at = i;
                        break;
                    }
                    if (/\s/.test(ch)) break;
                }

                if (at === -1 || (at > 0 && !/\s/.test(value[at - 1]))) {
                    closeDropdown();
                    return;
                }

                activeTextarea = textarea;
                mentionStart = at;
                renderSuggestions(textarea, value.slice(at + 1, caret));
            });

            textarea.addEventListener('blur', function () {
                // Delay so a mousedown pick on the dropdown still registers.
                setTimeout(closeDropdown, 150);
            });

            textarea.addEventListener('keydown', function (e) {
                if (dropdown.style.display !== 'block') return;

                var items = dropdown.querySelectorAll('.mention-dropdown-item');
                if (!items.length) return;

                var activeIndex = Array.prototype.findIndex.call(items, function (el) {
                    return el.classList.contains('is-active');
                });

                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items[activeIndex]) items[activeIndex].classList.remove('is-active');
                    var next = e.key === 'ArrowDown'
                        ? (activeIndex + 1) % items.length
                        : (activeIndex - 1 + items.length) % items.length;
                    items[next].classList.add('is-active');
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    e.preventDefault();
                    var current = items[activeIndex] || items[0];
                    insertMention(current.dataset.name);
                } else if (e.key === 'Escape') {
                    closeDropdown();
                }
            });
        });
    })();

    // Clear stale input/validation state when a modal is closed.
    document.querySelectorAll('.modal').forEach(function (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            var form = modalEl.querySelector('form');
            if (form) form.reset();
            modalEl.querySelectorAll('.is-invalid').forEach(function (el) {
                el.classList.remove('is-invalid');
            });
            modalEl.querySelectorAll('.role-pick-input').forEach(function (checkbox) {
                syncRolePickCard(checkbox);
            });
        });
    });

    // Role card enable/disable switch — flips status in place, no page reload.
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.content : '';

    document.querySelectorAll('.js-role-toggle').forEach(function (input) {
        input.addEventListener('change', function () {
            var card = input.closest('.role-card');
            var statusBadge = card ? card.querySelector('.js-role-status') : null;
            var previousChecked = !input.checked;

            input.disabled = true;

            fetch(input.dataset.url, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Request failed');
                    return response.json();
                })
                .then(function (data) {
                    input.checked = data.is_active;
                    if (statusBadge) {
                        statusBadge.textContent = data.is_active ? 'Active' : 'Inactive';
                        statusBadge.classList.toggle('badge-soft-primary', data.is_active);
                        statusBadge.classList.toggle('badge-soft-secondary', !data.is_active);
                    }
                })
                .catch(function () {
                    input.checked = previousChecked;
                    window.alert('Could not update the role status. Please try again.');
                })
                .finally(function () {
                    input.disabled = false;
                });
        });
    });
});

// RFQ show page — Progress chart, rendered with Apache ECharts (tree
// series) rather than hand-rolled CSS, for real connector-line geometry
// and automatic layout instead of fragile pseudo-element math. Left to
// right ('LR' orient) — RFQ Created starts the row on the left, and
// Sourcing splits into one lane per assignee running rightward, each lane
// keeping its own copy of everything after it (Data Entry, Senior
// Operations Approval, Head of Business Development, GM Assistant,
// General Manager, Closed) rather than merging back together — a plain
// fork, which is exactly what a tree series draws natively, no manual
// layout or overlay needed. Called from show.blade.php once the ECharts
// CDN script has loaded. Global (not inside the DOMContentLoaded listener
// above) so that late <script> can call it after everything here has run.
window.renderRfqProgressChart = function () {
    var dataEl = document.getElementById('rfq-progress-data');
    var chartEl = document.getElementById('rfq-progress-chart');
    if (!dataEl || !chartEl || typeof echarts === 'undefined') {
        return;
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    // Soft two-tone gradients rather than flat fills, plus a matching
    // border — gives each node a bit of depth without competing with the
    // text. Pending stays a flat white/dashed outline on purpose, so a
    // lane still reads left-to-right at a glance: solid + tinted means
    // "happened", dashed + white means "not yet".
    var STATE_COLORS = {
        done: { from: '#eafaf1', to: '#d7f2e3', border: '#8fd4ac', text: '#157347' },
        current: { from: '#eef2ff', to: '#dde5ff', border: '#7c93f7', text: '#3b53d1' },
        pending: { from: '#ffffff', to: '#ffffff', border: '#c3cbdc', text: '#6b7280' },
        returned: { from: '#fdf1f2', to: '#fbe0e3', border: '#ee9ca6', text: '#b02a37' },
    };
    var LINE_COLOR = '#a9b4c9';
    var BASE_NODE_WIDTH = 170;
    var BASE_NODE_HEIGHT = 78;
    var BASE_COLUMN_GAP = 64;
    var BASE_ROW_GAP = 26;

    // Walk the nested {name, meta, state, children} data (built server-side
    // in show.blade.php) and attach the per-node itemStyle/label ECharts
    // actually reads, rather than shaping that there. Title color is state-
    // dependent, but a `rich` style block is shared across the whole
    // series rather than settable per node — so rather than one `title`
    // style, there's one per state ("title_done", "title_current", ...)
    // defined once below, and each node's formatter just picks the right
    // one for its own state.
    //
    // The rich styles below also set `overflow: 'truncate'`, but in this
    // ECharts build that only reliably clips the first line of a \n-joined
    // multi-line rich label — later lines can render at full width and
    // bleed past the node's edges instead of being cut off. So every line
    // is pre-truncated here in plain JS first, using rough per-character
    // width budgets for each line's font, rather than trusting ECharts to
    // clip text it's already decided not to reliably clip.
    function truncate(text, maxChars) {
        if (!text || text.length <= maxChars) {
            return text;
        }
        return text.slice(0, maxChars - 1).trim() + '…';
    }

    // The node's normal (non-highlighted) fill/border — its own function
    // so the tab-select highlight (further down) can revert a single node
    // back to this without re-running `decorate`'s label/recursion setup
    // on the rest of the tree.
    function baseItemStyle(node) {
        var colors = STATE_COLORS[node.state] || STATE_COLORS.pending;
        return {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                { offset: 0, color: colors.from },
                { offset: 1, color: colors.to },
            ]),
            borderColor: colors.border,
            borderWidth: node.state === 'current' ? 2.5 : 1.25,
            borderType: node.state === 'pending' ? 'dashed' : 'solid',
        };
    }

    // node.role (its own small "kicker" line above the title) is which
    // role this node belongs to — "Sourcing" or "Data Entry" — for the
    // per-branch nodes where the title is a person's name rather than a
    // stage name, so it isn't otherwise obvious which step that name is
    // standing in for. node.meta is a list of 0-2 lines (built server-side
    // in show.blade.php): who acted / status, then when (date and time) —
    // each its own line under the title, rather than packed together.
    // node.rfq_number (also its own line) is the RFQ or per-split number
    // ("RFQ1005-P1 of P3") this node belongs to. node.step (not rendered)
    // is which Step Details tab below the chart this node belongs to —
    // see highlightStep() further down.
    function decorate(node) {
        node.itemStyle = baseItemStyle(node);
        var roleKicker = node.role ? node.role.toUpperCase() : null;
        var title = truncate(node.name, 28);
        var rfqNumberLine = truncate(node.rfq_number, 26);
        var metaLine1 = truncate((node.meta || [])[0], 30);
        var metaLine2 = truncate((node.meta || [])[1], 30);
        node.label = {
            formatter: function () {
                var titleKey = 'title_' + node.state;
                var lines = [];
                if (roleKicker) {
                    lines.push('{role_kicker|' + roleKicker + '}');
                }
                lines.push('{' + titleKey + '|' + title + '}');
                if (rfqNumberLine) {
                    lines.push('{rfq_number|' + rfqNumberLine + '}');
                }
                if (metaLine1) {
                    lines.push('{meta_role|' + metaLine1 + '}');
                }
                if (metaLine2) {
                    lines.push('{meta_date|' + metaLine2 + '}');
                }
                return lines.join('\n');
            },
        };
        if (node.children && node.children.length) {
            node.children.forEach(decorate);
        } else {
            delete node.children;
        }
        return node;
    }

    function treeDepth(node) {
        if (!node.children || !node.children.length) {
            return 1;
        }
        return 1 + Math.max.apply(null, node.children.map(treeDepth));
    }

    function leafCount(node) {
        if (!node.children || !node.children.length) {
            return 1;
        }
        return node.children.reduce(function (sum, child) {
            return sum + leafCount(child);
        }, 0);
    }

    var rootNode = decorate(data.tree);
    var depth = treeDepth(rootNode);
    var leaves = leafCount(rootNode);
    var chart = echarts.init(chartEl);

    // Every node tagged with a given `step` (built server-side — see
    // show.blade.php) — a Sourcing/Data Entry/Senior Ops/etc. step can be
    // several nodes at once, one per split branch, so selecting its tab
    // needs to highlight (and scroll to) all of them together, not just
    // one. `_level` (column index, 0 = RFQ Created) is stamped in here
    // rather than sent from the server, since it's just this node's depth
    // in the walk — used to know which column to scroll to; where a step
    // spans two columns (e.g. "sourcing" is both the single fan-out node
    // and every branch's own Sourcing node one column further right), the
    // deeper (higher) one wins, since that's the more specific one.
    var stepNodesMap = {};
    (function collectStepNodes(node, level) {
        if (node.step) {
            (stepNodesMap[node.step] = stepNodesMap[node.step] || []).push(node);
        }
        node._level = level;
        if (node.children) {
            node.children.forEach(function (child) {
                collectStepNodes(child, level + 1);
            });
        }
    })(rootNode, 0);

    // Fade whichever edge still has more content to scroll to, so a node
    // sitting mid-scroll at the boundary reads as "scroll for more"
    // instead of looking hard-clipped — but only that edge, and only
    // while it's actually scrollable that way. A fade fixed in CSS also
    // dims the true first/last node once fully scrolled to that end
    // (nothing there to hint at), which looks exactly like the clipping
    // this is meant to avoid, so it's recomputed here against live scroll
    // position instead, and again after every zoom change since that
    // changes whether there's anything left to scroll to.
    var wrapEl = chartEl.parentElement;
    var FADE = 24;
    var updateEdgeFade = function () {
        if (!wrapEl) {
            return;
        }
        var maxScroll = wrapEl.scrollWidth - wrapEl.clientWidth;
        var canScrollLeft = wrapEl.scrollLeft > 1;
        var canScrollRight = wrapEl.scrollLeft < maxScroll - 1;
        var mask = 'none';
        if (canScrollLeft && canScrollRight) {
            mask = 'linear-gradient(to right, transparent, #000 ' + FADE + 'px, #000 calc(100% - ' + FADE + 'px), transparent)';
        } else if (canScrollLeft) {
            mask = 'linear-gradient(to right, transparent, #000 ' + FADE + 'px)';
        } else if (canScrollRight) {
            mask = 'linear-gradient(to right, #000 calc(100% - ' + FADE + 'px), transparent)';
        }
        wrapEl.style.webkitMaskImage = mask;
        wrapEl.style.maskImage = mask;
    };

    // Zoom in/out re-renders the chart bigger/smaller — scaling every
    // size (node, gaps, padding, font) by the same factor — rather than
    // CSS-scaling the finished canvas, which would blur the text. Discrete
    // stops rather than continuous so repeated clicks land on predictable,
    // reproducible sizes. Truncation budgets in `decorate()` above are
    // character counts, not pixels, so they don't need to change here —
    // font size and node width scale together, so the same count of
    // characters keeps fitting at every zoom level.
    var ZOOM_LEVELS = [0.6, 0.75, 1, 1.25, 1.5, 1.75, 2];
    var zoomIndex = ZOOM_LEVELS.indexOf(1);
    var zoomInBtn = document.getElementById('rfq-progress-zoom-in');
    var zoomOutBtn = document.getElementById('rfq-progress-zoom-out');
    var zoomResetBtn = document.getElementById('rfq-progress-zoom-reset');
    var currentNodeWidth = BASE_NODE_WIDTH;
    var currentNodeHeight = BASE_NODE_HEIGHT;
    var currentColumnGap = BASE_COLUMN_GAP;
    var currentPad = 50;

    function render() {
        var zoom = ZOOM_LEVELS[zoomIndex];
        var nodeWidth = BASE_NODE_WIDTH * zoom;
        var nodeHeight = BASE_NODE_HEIGHT * zoom;
        currentNodeWidth = nodeWidth;
        currentNodeHeight = nodeHeight;
        var columnGap = BASE_COLUMN_GAP * zoom;
        var rowGap = BASE_ROW_GAP * zoom;
        var pad = 50 * zoom;
        currentColumnGap = columnGap;
        currentPad = pad;

        var minHeight = chartEl.parentElement ? chartEl.parentElement.clientHeight : 0;
        chartEl.style.width = (depth * (nodeWidth + columnGap) + pad * 2) + 'px';
        chartEl.style.height = Math.max(leaves * (nodeHeight + rowGap) + 40, minHeight, 140) + 'px';
        chart.resize();

        chart.setOption({
            series: [{
                type: 'tree',
                data: [rootNode],
                top: 20 * zoom,
                bottom: 20 * zoom,
                left: pad,
                right: pad,
                orient: 'LR',
                edgeShape: 'curve',
                roam: false,
                expandAndCollapse: false,
                initialTreeDepth: -1,
                symbol: 'rect',
                symbolSize: [nodeWidth, nodeHeight],
                itemStyle: {
                    borderWidth: 1,
                    shadowBlur: 10,
                    shadowColor: 'rgba(15, 23, 42, 0.10)',
                    shadowOffsetY: 3,
                },
                lineStyle: { color: LINE_COLOR, width: 2, curveness: 0.4 },
                emphasis: { disabled: true },
                label: {
                    position: 'inside',
                    verticalAlign: 'middle',
                    align: 'center',
                    rich: {
                        role_kicker: { fontWeight: 700, fontSize: 8 * zoom, color: '#8b98ab', lineHeight: 11 * zoom, width: nodeWidth - 18, overflow: 'truncate' },
                        title_done: { fontWeight: 700, fontSize: 10 * zoom, lineHeight: 13 * zoom, color: STATE_COLORS.done.text, width: nodeWidth - 18, overflow: 'truncate' },
                        title_current: { fontWeight: 700, fontSize: 10 * zoom, lineHeight: 13 * zoom, color: STATE_COLORS.current.text, width: nodeWidth - 18, overflow: 'truncate' },
                        title_pending: { fontWeight: 700, fontSize: 10 * zoom, lineHeight: 13 * zoom, color: STATE_COLORS.pending.text, width: nodeWidth - 18, overflow: 'truncate' },
                        title_returned: { fontWeight: 700, fontSize: 10 * zoom, lineHeight: 13 * zoom, color: STATE_COLORS.returned.text, width: nodeWidth - 18, overflow: 'truncate' },
                        rfq_number: { fontSize: 9 * zoom, fontWeight: 600, color: '#7c8a9e', lineHeight: 12 * zoom, width: nodeWidth - 18, overflow: 'truncate' },
                        meta_role: { fontSize: 9 * zoom, color: '#5b6576', lineHeight: 12 * zoom, width: nodeWidth - 18, overflow: 'truncate' },
                        meta_date: { fontSize: 9 * zoom, color: '#9aa3b2', lineHeight: 12 * zoom, width: nodeWidth - 18, overflow: 'truncate' },
                    },
                },
            }],
        });

        if (zoomOutBtn) {
            zoomOutBtn.disabled = zoomIndex === 0;
        }
        if (zoomInBtn) {
            zoomInBtn.disabled = zoomIndex === ZOOM_LEVELS.length - 1;
        }
        updateEdgeFade();
    }

    function setZoomIndex(nextIndex) {
        zoomIndex = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, nextIndex));
        render();
    }

    if (zoomInBtn) {
        zoomInBtn.addEventListener('click', function () {
            setZoomIndex(zoomIndex + 1);
        });
    }
    if (zoomOutBtn) {
        zoomOutBtn.addEventListener('click', function () {
            setZoomIndex(zoomIndex - 1);
        });
    }
    if (zoomResetBtn) {
        zoomResetBtn.addEventListener('click', function () {
            setZoomIndex(ZOOM_LEVELS.indexOf(1));
        });
    }

    render();
    window.addEventListener('resize', render);
    if (wrapEl) {
        wrapEl.addEventListener('scroll', updateEdgeFade);
    }

    // Selecting a Step Details tab (below the chart) briefly highlights
    // every node for that stage — a bright ring, a bigger shadow, and a
    // slight grow — so the tab and its place in the flow chart visibly
    // connect, especially useful for Sourcing/Data Entry where the tab
    // covers several branch nodes at once. Growing/shrinking symbolSize
    // and fading the itemStyle color are both properties ECharts already
    // animates smoothly on its own when they change via setOption, so the
    // "animation" here is just: set the highlighted style, redraw, wait,
    // set it back, redraw again — no manual tweening needed.
    var HIGHLIGHT_BORDER = '#f5a524';
    var highlightTimer = null;

    function clearHighlight() {
        Object.keys(stepNodesMap).forEach(function (step) {
            stepNodesMap[step].forEach(function (node) {
                node.itemStyle = baseItemStyle(node);
                delete node.symbolSize;
            });
        });
    }

    // Scrolls the (often much wider than its card) chart so the target
    // column is centered in view. Node x-positions aren't exposed by the
    // tree series' API, but they're deterministic — the same column-pitch
    // arithmetic used above to size the canvas — so the column is found
    // by column index (each node's depth, stamped as `_level` while
    // collecting stepNodesMap) rather than reading positions back out of
    // ECharts. Where a step's nodes span two columns (the "sourcing" fan-
    // out node and every branch's own Sourcing node one column past it),
    // the deepest one wins, since that's the more specific column.
    function scrollToStep(step) {
        if (!wrapEl) {
            return;
        }
        var maxLevel = stepNodesMap[step].reduce(function (max, node) {
            return Math.max(max, node._level);
        }, 0);
        var columnCenter = currentPad + maxLevel * (currentNodeWidth + currentColumnGap) + currentNodeWidth / 2;
        var maxScroll = wrapEl.scrollWidth - wrapEl.clientWidth;
        var target = Math.max(0, Math.min(maxScroll, columnCenter - wrapEl.clientWidth / 2));
        wrapEl.scrollTo({ left: target, behavior: 'smooth' });
    }

    function highlightStep(step) {
        if (!stepNodesMap[step]) {
            return;
        }
        if (highlightTimer) {
            clearTimeout(highlightTimer);
        }
        clearHighlight();
        stepNodesMap[step].forEach(function (node) {
            node.itemStyle = Object.assign({}, baseItemStyle(node), {
                borderColor: HIGHLIGHT_BORDER,
                borderWidth: 3.5,
                shadowBlur: 24,
                shadowColor: 'rgba(245, 165, 36, 0.5)',
            });
            node.symbolSize = [currentNodeWidth * 1.12, currentNodeHeight * 1.12];
        });
        chart.setOption({ series: [{ data: [rootNode] }] });
        scrollToStep(step);
        highlightTimer = setTimeout(function () {
            clearHighlight();
            chart.setOption({ series: [{ data: [rootNode] }] });
            highlightTimer = null;
        }, 1200);
    }

    Object.keys(stepNodesMap).forEach(function (step) {
        var tabBtn = document.getElementById('step-tab-' + step);
        if (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', function () {
                highlightStep(step);
            });
        }
    });
};
