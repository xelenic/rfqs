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

    // Populate the shared "Assign Sourcing" modal from the clicked row's data-* attributes.
    document.querySelectorAll('.js-assign-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('assignRfqForm');
            if (!form) return;

            form.action = button.dataset.action;
            var assignedIds = (button.dataset.assigned || '').split(',').filter(Boolean);
            form.querySelectorAll('input[name="users[]"]').forEach(function (checkbox) {
                checkbox.checked = assignedIds.indexOf(checkbox.value) !== -1;
                syncRolePickCard(checkbox);
            });

            // Already split across more than one person — open with the
            // toggle on so re-saving doesn't silently strip anyone.
            var splitToggle = document.getElementById('assign-split-toggle');
            if (splitToggle) {
                splitToggle.checked = assignedIds.length > 1;
            }
        });
    });

    // "Split this task" toggle in the Assign Sourcing modal — off (the
    // default) keeps the pick to one person: choosing someone new
    // un-picks whoever was previously checked. On allows picking several
    // Sourcing members at once, which is what drives the WP0003-01/-02/-03
    // split numbering (see Rfq::sourcingSplitNumbers()).
    (function () {
        var assignForm = document.getElementById('assignRfqForm');
        var splitToggle = document.getElementById('assign-split-toggle');
        if (!assignForm || !splitToggle) return;

        assignForm.querySelectorAll('input[name="users[]"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (splitToggle.checked || !checkbox.checked) return;

                assignForm.querySelectorAll('input[name="users[]"]').forEach(function (other) {
                    if (other !== checkbox && other.checked) {
                        other.checked = false;
                        syncRolePickCard(other);
                    }
                });
            });
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
