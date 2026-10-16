// RFQMS Admin Panel — plain JS, no build step.

// Wires up the page. Runs once it has loaded, and again (as
// window.rfqmsRebind) each time live.js swaps a fresh page body in, since the
// new elements have none of it — so whatever is tied to the page's chrome or
// to the document itself, rather than to the body, is only set up the first
// time (`first`).
function rfqmsBoot() {
    var first = !window.__rfqmsBooted;
    window.__rfqmsBooted = true;

    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggleBtn = document.getElementById('sidebarToggle');

    function closeSidebar() {
        sidebar && sidebar.classList.remove('show');
        backdrop && backdrop.classList.remove('show');
    }

    if (first && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });
    }

    if (first && backdrop) {
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

    // Add user: an Admin chooses how they'll sign in — the set-password
    // email, or a password set now. Only the chosen one's fields show, and
    // the password fields are only sent (and required) for the second.
    document.querySelectorAll('.js-sign-in-choice').forEach(function (choice) {
        var form = choice.closest('form');
        var sync = function () {
            var setsPassword = !!form.querySelector('input[name="sign_in"][value="password"]:checked');
            form.querySelectorAll('.js-sign-in-password').forEach(function (field) {
                field.classList.toggle('d-none', !setsPassword);
                field.querySelectorAll('input').forEach(function (input) {
                    input.disabled = !setsPassword;
                    input.required = setsPassword;
                });
            });
            form.querySelectorAll('.js-sign-in-email, .js-sign-in-email-label').forEach(function (note) {
                note.classList.toggle('d-none', setsPassword);
            });
            form.querySelectorAll('.js-sign-in-password-label').forEach(function (label) {
                label.classList.toggle('d-none', !setsPassword);
            });
        };

        choice.querySelectorAll('input[name="sign_in"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();
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
            // Only an Admin's form has a password (see users/_form.blade.php).
            form.querySelectorAll('#edit-password, #edit-password_confirmation').forEach(function (field) { field.value = ''; });

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
            form.querySelector('#edit-number_of_items').value = button.dataset.numberOfItems || '';
            // Business Development gets a hidden, fixed "Pending" here instead
            // of the status picker everyone else does — see _form.blade.php.
            var statusField = form.querySelector('#edit-status');
            if (statusField) statusField.value = button.dataset.status || 'Pending';
            form.querySelector('#edit-subject').value = button.dataset.subject || '';
            form.querySelector('#edit-description').value = button.dataset.description || '';
            // Business Development's Returns: offer to send it straight back
            // to whoever sent it once it's saved.
            var forwardBack = document.getElementById('editForwardBack');
            if (forwardBack) {
                forwardBack.classList.toggle('d-none', !button.dataset.forwardBack);
                forwardBack.querySelector('[name="forward_back"]').checked = false;
                forwardBack.querySelector('[name="forward_back_to"]').value = button.dataset.forwardBack || '';
                document.getElementById('editForwardBackTo').textContent = button.dataset.forwardBack || '';
            }
        });
    });

    // Assign Sourcing wizard (see _assign_modal.blade.php). Four steps —
    // job category; keep whole or split into parts; who takes each part;
    // then a summary of it all — on one form that only actually submits
    // from the last one, where Finish appears. Opened from any
    // .js-assign-rfq button, which carries the RFQ's number, subject and
    // category, its planned split (empty until one's been made), and who
    // already holds which part. With a split already planned it skips
    // straight to assigning, and only offers the still-empty parts, since
    // category and split size are settled by then. One person can hold several parts — each becomes its own
    // assignment, shown, worked and completed on its own. See
    // RfqController::assign() for the server side.
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
            3: form.querySelector('[data-step-panel="3"]'),
            4: form.querySelector('[data-step-panel="4"]'),
        };
        var errorEls = {
            1: document.getElementById('assign-step-1-error'),
            2: document.getElementById('assign-step-2-error'),
            3: document.getElementById('assign-step-3-error'),
            4: document.getElementById('assign-step-4-error'),
        };
        var categorySelect = document.getElementById('assign-category');
        var newCategoryWrap = document.getElementById('assign-new-category-wrap');
        var newCategoryInput = document.getElementById('assign-new-category');
        var newCategoryDescription = document.getElementById('assign-new-category-description');
        var newCategoryCount = document.getElementById('assign-new-category-count');
        var categoryCard = document.getElementById('assign-category-card');
        var categoryIcon = document.getElementById('assign-category-icon');
        var categoryName = document.getElementById('assign-category-name');
        var categoryDesc = document.getElementById('assign-category-desc');
        var categoryBadge = document.getElementById('assign-category-badge');
        var rfqContext = document.getElementById('assign-rfq-context');
        var splitInput = document.getElementById('assign-split-input');
        var partsCountWrap = document.getElementById('assign-parts-count-wrap');
        var partsCountInput = document.getElementById('assign-parts-count');
        var partsMinus = document.getElementById('assign-parts-minus');
        var partsPlus = document.getElementById('assign-parts-plus');
        var partsQuickPicks = document.querySelectorAll('#assign-parts-count-wrap .parts-quick-btn');
        var partsPreview = document.getElementById('assign-parts-preview');
        var partsChips = document.getElementById('assign-parts-chips');
        var partsList = document.getElementById('assign-parts-list');
        var leadTitle = document.getElementById('assign-lead-title');
        var leadText = document.getElementById('assign-lead-text');
        var summary = document.getElementById('assign-summary');
        var reviewParts = document.getElementById('review-parts');
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
            [1, 2, 3, 4].forEach(function (n) {
                panels[n].classList.toggle('d-none', step !== n);
                showError(n, '');
            });
            stepsEl.querySelectorAll('[data-step-tab]').forEach(function (tab) {
                var n = parseInt(tab.dataset.stepTab, 10);
                tab.classList.toggle('is-active', n === step);
                tab.classList.toggle('is-done', n < step);
            });
            backBtn.classList.toggle('d-none', step <= state.firstStep);
            nextBtn.classList.toggle('d-none', step === 4);
            nextBtn.innerHTML = (step === 3 ? 'Review' : 'Next') + ' <i class="bi bi-arrow-right"></i>';
            // Finish only shows on the summary — and, being shown then,
            // plays its entrance animation (see .wizard-finish).
            finishBtn.classList.toggle('d-none', step !== 4);
            finishBtn.innerHTML = '<i class="bi bi-check2-circle"></i> ' + (state.remaining ? 'Assign' : 'Finish');

            // The button just pressed may be hidden now (Back, Next and Finish
            // swap places) — keep keyboard focus in the modal, on the modal
            // itself rather than on Finish so a held-down Enter can't save it.
            var focused = document.activeElement;
            if (!modalEl.contains(focused) || focused.offsetParent === null) {
                modalEl.focus();
            }
        }

        function toggleNewCategory() {
            var isNew = categorySelect.value === NEW_OPTION;
            newCategoryWrap.classList.toggle('d-none', !isNew);
            if (isNew) newCategoryInput.focus();
        }

        // The card under the picker: what the chosen category covers — its
        // description — or, for a new one, a live preview of what's being
        // typed. With nothing chosen it says so, rather than sitting empty.
        function renderCategoryCard(animate) {
            var chosen = categorySelect.value;
            var isNew = chosen === NEW_OPTION;
            var option = categorySelect.options[categorySelect.selectedIndex];
            var name, description, icon, isPlaceholder;

            if (!chosen) {
                name = 'No category selected yet';
                description = 'Choose one above and what it covers will show up here.';
                icon = 'bi-tag';
                isPlaceholder = true;
            } else if (isNew) {
                name = newCategoryInput.value.trim() || 'New category';
                description = newCategoryDescription.value.trim() || 'Add a short description so everyone knows what it covers.';
                icon = 'bi-plus-lg';
                isPlaceholder = !newCategoryDescription.value.trim();
            } else {
                name = chosen;
                description = option.dataset.description || 'No description has been added for this category yet.';
                icon = option.dataset.icon || 'bi-tag';
                isPlaceholder = !option.dataset.description;
            }

            categoryName.textContent = name;
            categoryDesc.textContent = description;
            categoryDesc.classList.toggle('is-placeholder', isPlaceholder);
            categoryIcon.className = 'bi ' + icon;
            categoryBadge.classList.toggle('d-none', !isNew);
            categoryCard.classList.toggle('is-empty', !chosen);

            if (animate) {
                categoryCard.classList.remove('is-changing');
                void categoryCard.offsetWidth; // restart the animation
                categoryCard.classList.add('is-changing');
            }
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
            renderAssignLead();
        }

        // The part-count stepper: − and + stop at the limits, and the quick
        // pick matching the current number lights up.
        function syncPartsCounter() {
            var n = parseInt(partsCountInput.value, 10);
            partsMinus.disabled = !isNaN(n) && n <= 2;
            partsPlus.disabled = !isNaN(n) && n >= maxParts;
            partsQuickPicks.forEach(function (pick) {
                pick.classList.toggle('is-active', parseInt(pick.dataset.parts, 10) === n);
            });
        }

        function setPartsCount(n) {
            partsCountInput.value = isNaN(n) ? 2 : Math.max(2, Math.min(maxParts, n));
            syncPartsCounter();
            renderPartsPreview();
            renderParts();
        }

        // Step 2's "This makes …" — the parts a split will create, so the
        // count isn't just a number.
        function renderPartsPreview() {
            var splitting = isSplitChosen();
            partsPreview.classList.toggle('d-none', !splitting);
            partsChips.innerHTML = '';
            if (!splitting) return;

            var total = partsCount();
            for (var part = 1; part <= total; part++) {
                var chip = document.createElement('span');
                chip.className = 'wizard-chip';
                chip.textContent = partLabel(part, total);
                partsChips.appendChild(chip);
            }
        }

        // Step 3's lead-in and summary, worded for what was chosen: the
        // whole task, a fresh split, or the parts still left on a split.
        // The category being assigned: picked from the list, typed in as a new
        // one, or — when only the remaining parts are being filled — already
        // on the RFQ. The list's options carry each category's description
        // and icon.
        function currentCategory() {
            var name = state.remaining ? state.category : categorySelect.value;

            if (!state.remaining && name === NEW_OPTION) {
                return {
                    name: newCategoryInput.value.trim(),
                    description: newCategoryDescription.value.trim(),
                    icon: 'bi-tag',
                    isNew: true,
                };
            }

            var option = Array.prototype.find.call(categorySelect.options, function (candidate) {
                return candidate.value === name;
            });

            return {
                name: name,
                description: option ? option.dataset.description || '' : '',
                icon: option ? option.dataset.icon || 'bi-tag' : 'bi-tag',
                isNew: false,
            };
        }

        function renderAssignLead() {
            var total = partsCount();
            var whole = !state.remaining && !isSplitChosen();
            var category = currentCategory().name;
            var open = total - Object.keys(state.assigned).length;

            leadTitle.textContent = state.remaining ? 'Assign the remaining parts' : (whole ? 'Who takes this task?' : 'Who takes which part?');
            leadText.textContent = whole
                ? 'Pick the Sourcing member who takes the whole RFQ.'
                : 'Pick a Sourcing member for each part — the same person can take more than one, and each part is completed on its own. Parts left on "Leave unassigned" can be assigned later; the RFQ moves on to Data Entry once every part is assigned and done.';

            var chips = [];
            if (category) chips.push(['bi-tag', category]);
            chips.push(whole
                ? ['bi-person', 'Whole task']
                : ['bi-diagram-3', state.remaining ? open + ' of ' + total + ' parts still to assign' : total + ' parts']);

            summary.innerHTML = '';
            chips.forEach(function (chip) {
                var el = document.createElement('span');
                el.className = 'wizard-chip';
                el.innerHTML = '<i class="bi ' + chip[0] + '"></i> ';
                el.appendChild(document.createTextNode(chip[1]));
                summary.appendChild(el);
            });
        }

        // Step 4: the whole picture, laid out from what the earlier steps
        // hold — the category, how it's split, and who takes each part.
        function renderReview() {
            var total = partsCount();
            var whole = !state.remaining && !isSplitChosen();
            var category = currentCategory();

            document.getElementById('review-rfq-number').textContent = state.rfqNumber;
            document.getElementById('review-rfq-subject').textContent = state.subject;
            document.getElementById('review-rfq').classList.toggle('d-none', !state.subject);
            document.getElementById('review-finish-name').textContent = state.remaining ? 'Assign' : 'Finish';

            document.getElementById('review-category-icon').className = 'bi ' + category.icon;
            document.getElementById('review-category-name').textContent = category.name;
            var categoryDescription = document.getElementById('review-category-desc');
            categoryDescription.textContent = category.description || 'No description has been added for this category yet.';
            categoryDescription.classList.toggle('is-placeholder', !category.description);
            document.getElementById('review-category-new').classList.toggle('d-none', !category.isNew);

            // Who takes what — one row per part.
            var already = 0;
            var assignedNow = 0;
            var unassigned = 0;
            reviewParts.innerHTML = '';
            for (var part = 1; part <= total; part++) {
                var row = document.createElement('li');
                row.className = 'review-part';

                var label = document.createElement('span');
                label.className = 'review-part-number';
                label.textContent = partLabel(part, total);
                row.appendChild(label);

                var who = document.createElement('span');
                who.className = 'review-part-who';
                var select = partsList.querySelector('select[data-part="' + part + '"]');
                var picked = select && select.value ? select.options[select.selectedIndex] : null;

                if (state.assigned[part]) {
                    already++;
                    who.innerHTML = '<i class="bi bi-check-circle-fill"></i> ';
                    who.appendChild(document.createTextNode(state.assigned[part].name));
                    var tag = document.createElement('span');
                    tag.className = 'review-part-tag';
                    tag.textContent = 'already assigned';
                    who.appendChild(tag);
                } else if (picked) {
                    assignedNow++;
                    who.innerHTML = '<i class="bi bi-person-check-fill"></i> ';
                    who.appendChild(document.createTextNode(picked.dataset.name || picked.textContent));
                } else {
                    unassigned++;
                    who.classList.add('is-empty');
                    who.textContent = 'Unassigned — assign later';
                }
                row.appendChild(who);
                reviewParts.appendChild(row);
            }

            document.getElementById('review-split-title').textContent = whole ? 'Kept as one task' : 'Split into ' + total + ' parts';
            document.getElementById('review-split-text').textContent = whole
                ? 'One Sourcing member takes the whole RFQ.'
                : 'Each part is tracked and completed on its own' + (already ? ' — ' + already + ' already assigned.' : '.');

            var note;
            if (whole) {
                note = 'Once they mark it complete, the RFQ moves on to Data Entry.';
            } else if (unassigned) {
                note = (already + assignedNow) + ' of ' + total + ' parts have someone. ' + unassigned
                    + (unassigned === 1 ? ' stays' : ' stay') + ' unassigned for now — assign '
                    + (unassigned === 1 ? 'it' : 'them') + ' later; the RFQ waits for every part before it moves on to Data Entry.';
            } else {
                note = 'Every part has someone. Once they\'re all completed, the RFQ moves on to Data Entry.';
            }
            document.getElementById('review-note').textContent = note;

            // A split that's already been planned can't be re-categorised or
            // re-split here.
            form.querySelectorAll('[data-goto-step]').forEach(function (button) {
                button.classList.toggle('d-none', state.remaining && parseInt(button.dataset.gotoStep, 10) < 3);
            });
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
            if (isSplitChosen()) {
                var n = parseInt(partsCountInput.value, 10);
                if (isNaN(n) || n < 2 || n > maxParts) {
                    showError(2, 'Enter a number of parts between 2 and ' + maxParts + '.');
                    return false;
                }
            }
            showError(2, '');
            return true;
        }

        function validateStep3() {
            var selects = partsList.querySelectorAll('select');
            var anyPicked = Array.prototype.some.call(selects, function (select) {
                return select.value !== '';
            });

            if (!state.remaining && !isSplitChosen() && !anyPicked) {
                showError(3, 'Pick a Sourcing member to take this RFQ, or go back and split it into parts.');
                return false;
            }
            if (state.remaining && !anyPicked) {
                showError(3, 'Pick someone for at least one part, or cancel to leave them for later.');
                return false;
            }
            showError(3, '');
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
                    firstStep: splitCount !== '' ? 3 : 1,
                    rfqNumber: button.dataset.rfqNumber || '',
                    subject: button.dataset.rfqSubject || '',
                    category: button.dataset.category || '',
                    assigned: parseAssigned(button.dataset.assigned),
                };

                form.action = button.dataset.action;
                categorySelect.value = '';
                newCategoryInput.value = '';
                newCategoryDescription.value = '';
                newCategoryCount.textContent = '0 / ' + newCategoryDescription.maxLength;
                categorySelect.classList.remove('is-invalid');
                newCategoryInput.classList.remove('is-invalid');
                newCategoryWrap.classList.add('d-none');
                renderCategoryCard(false);

                // Which RFQ is being categorised — its subject helps pick.
                document.getElementById('assign-rfq-context-number').textContent = state.rfqNumber;
                document.getElementById('assign-rfq-context-subject').textContent = state.subject;
                rfqContext.classList.toggle('d-none', !state.subject);
                form.querySelector('input[name="split_choice"][value="single"]').checked = true;
                partsCountInput.value = 2;
                syncPartsCounter();
                partsCountWrap.classList.add('d-none');

                subtitle.textContent = state.rfqNumber + (state.remaining ? ' — assign the remaining parts' : '');
                stepsEl.classList.toggle('d-none', state.remaining);

                partsList.innerHTML = '';
                renderPartsPreview();
                renderParts();
                finishBtn.disabled = false;
                showStep(state.firstStep);
            });
        });

        categorySelect.addEventListener('change', function () {
            categorySelect.classList.remove('is-invalid');
            toggleNewCategory();
            renderCategoryCard(true);
        });

        newCategoryInput.addEventListener('input', function () {
            newCategoryInput.classList.remove('is-invalid');
            renderCategoryCard(false);
        });
        newCategoryDescription.addEventListener('input', function () {
            newCategoryCount.textContent = newCategoryDescription.value.length + ' / ' + newCategoryDescription.maxLength;
            renderCategoryCard(false);
        });

        form.querySelectorAll('input[name="split_choice"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                partsCountWrap.classList.toggle('d-none', !isSplitChosen());
                renderPartsPreview();
                renderParts();
            });
        });

        partsCountInput.addEventListener('input', function () {
            syncPartsCounter();
            renderPartsPreview();
            renderParts();
        });
        partsCountInput.addEventListener('change', function () {
            setPartsCount(parseInt(partsCountInput.value, 10));
        });
        partsMinus.addEventListener('click', function () {
            setPartsCount((parseInt(partsCountInput.value, 10) || 2) - 1);
        });
        partsPlus.addEventListener('click', function () {
            setPartsCount((parseInt(partsCountInput.value, 10) || 2) + 1);
        });
        partsQuickPicks.forEach(function (pick) {
            pick.addEventListener('click', function () {
                setPartsCount(parseInt(pick.dataset.parts, 10));
            });
        });

        nextBtn.addEventListener('click', function () {
            if (state.step === 1 && validateStep1()) {
                showStep(2);
            } else if (state.step === 2 && validateStep2()) {
                renderParts(); // the rows reflect what was just chosen
                showStep(3);
            } else if (state.step === 3 && validateStep3()) {
                renderReview();
                showStep(4);
            }
        });
        backBtn.addEventListener('click', function () {
            showStep(state.step - 1);
        });

        // The summary's "Change" buttons jump back to the step each card
        // came from.
        form.querySelectorAll('[data-goto-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                showStep(parseInt(button.dataset.gotoStep, 10));
            });
        });

        form.addEventListener('submit', function (event) {
            // Enter in the new-category box, the part count and the like would
            // otherwise submit the whole wizard from an earlier step — treat
            // it as "Next" instead.
            if (state && state.step < 4) {
                event.preventDefault();
                nextBtn.click();
                return;
            }
            if (!state || !validateStep3()) {
                event.preventDefault();
                if (state) {
                    showStep(3);
                    validateStep3();
                }
                return;
            }
            splitInput.value = !state.remaining && isSplitChosen() ? '1' : '0';

            // One click, one save.
            finishBtn.disabled = true;
            finishBtn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving…';
        });
    })();

    // Operations' Assigned tab — each RFQ's parts fold away under its row
    // (see _rfq_parts.blade.php), one at a time or all together.
    (function () {
        var allToggle = document.querySelector('.js-toggle-all-parts');

        function setCollapsed(group, collapsed) {
            group.classList.toggle('is-collapsed', collapsed);
            group.querySelector('.js-toggle-parts').setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        function syncAllToggle() {
            if (!allToggle) return;
            var groups = document.querySelectorAll('.rfq-group');
            if (!groups.length) return;
            var allCollapsed = Array.prototype.every.call(groups, function (group) {
                return group.classList.contains('is-collapsed');
            });
            allToggle.dataset.collapsed = allCollapsed ? '1' : '';
            allToggle.querySelector('span').textContent = allCollapsed ? 'Expand all' : 'Collapse all';
            allToggle.querySelector('i').className = 'bi ' + (allCollapsed ? 'bi-arrows-expand' : 'bi-arrows-collapse');
        }

        document.querySelectorAll('.js-toggle-parts').forEach(function (button) {
            button.addEventListener('click', function () {
                var group = button.closest('.rfq-group');
                setCollapsed(group, !group.classList.contains('is-collapsed'));
                syncAllToggle();
            });
        });

        if (allToggle) {
            allToggle.addEventListener('click', function () {
                var collapse = !allToggle.dataset.collapsed;
                document.querySelectorAll('.rfq-group').forEach(function (group) {
                    setCollapsed(group, collapse);
                });
                syncAllToggle();
            });
        }

        // After a live update the groups may not all be as the page starts.
        syncAllToggle();
    })();

    // Mark Complete and Return to Sourcing ask for a comment first (see
    // _complete_modal.blade.php) — Sourcing's on their own part, Data Entry's
    // on a Sourcing part (to complete it, or to send it back with a reason as
    // the comment): every such button (.js-complete) opens the one prompt,
    // which is pointed at the right route and part, dressed for the action and
    // worded for whoever's next, when it opens. From inside a quick-detail
    // modal — where Bootstrap swaps one modal for the other — Back goes back
    // to it.
    (function () {
        var modalEl = document.getElementById('completeModal');
        if (!modalEl) return;

        // What differs by action; the wording that depends on the RFQ comes
        // from the button's own data-* attributes.
        var KINDS = {
            complete: {
                title: 'Assign to Data Entry', icon: 'bi-check2-circle', field: 'comment', label: 'Comment', max: 2000,
                submit: 'Assign to Data Entry', submitClass: 'btn-success', missing: 'Add a comment to assign this part to Data Entry.',
            },
            // Data Entry's — it goes back to the part's Sourcing member to finalize.
            data_entry: {
                title: 'Send to Finalize', icon: 'bi-send-check', field: 'comment', label: 'Comment', max: 2000,
                submit: 'Send to Finalize', submitClass: 'btn-success', missing: 'Add a comment to send this part to finalize.',
            },
            'return': {
                title: 'Return to Sourcing', icon: 'bi-arrow-counterclockwise', field: 'reason', label: 'Reason', max: 1000,
                submit: 'Return to Sourcing', submitClass: 'btn-danger', missing: 'Add a reason to send this part back.',
            },
            // The Sourcing member's, on a part Data Entry sent to finalize.
            return_data_entry: {
                title: 'Return to Data Entry', icon: 'bi-arrow-counterclockwise', field: 'reason', label: 'Reason', max: 1000,
                submit: 'Return to Data Entry', submitClass: 'btn-danger', missing: 'Add a reason to send this part back to Data Entry.',
            },
            // Sourcing's on their own part: back to Senior Operations to
            // assign again.
            sourcing_to_ops: {
                title: 'Return to Senior Operations', icon: 'bi-arrow-return-left', field: 'reason', label: 'Reason', max: 1000,
                submit: 'Return to Senior Operations', submitClass: 'btn-danger', missing: 'Add a reason to send this part back to Senior Operations.',
            },
        };

        var form = document.getElementById('completeForm');
        var comment = document.getElementById('complete-comment');
        var count = document.getElementById('complete-count');
        var error = document.getElementById('complete-error');
        var cancel = document.getElementById('complete-cancel');
        var submit = document.getElementById('complete-submit');
        // Data Entry's Send to Finalize also asks how many entries they made.
        var entriesBox = document.getElementById('complete-entries');
        var entries = document.getElementById('complete-entries-input');
        var entriesHint = document.getElementById('complete-entries-hint');
        var entriesError = document.getElementById('complete-entries-error');
        // Assign to Data Entry asks how many line items Data Entry has to add.
        var lineItemsBox = document.getElementById('complete-line-items');
        var lineItems = document.getElementById('complete-line-items-input');
        var lineItemsError = document.getElementById('complete-line-items-error');
        var kind = KINDS.complete;

        function asksLineItems() {
            return lineItems && !lineItems.disabled;
        }

        // A whole number, 1 or more, and at most the input's max.
        function lineItemsGiven() {
            var value = lineItems.value.trim();
            return /^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= Number(lineItems.max);
        }

        function showLineItemsError(message) {
            if (!lineItems) return;
            lineItemsError.textContent = message;
            lineItemsError.classList.toggle('d-none', !message);
            lineItems.classList.toggle('is-invalid', !!message);
        }

        function asksEntries() {
            return entries && !entries.disabled;
        }

        // A whole number, 0 or more, and at most the input's max.
        function entriesGiven() {
            var value = entries.value.trim();
            return /^\d+$/.test(value) && Number(value) <= Number(entries.max);
        }

        // Said under whichever field it's about: the number of entries, or the comment.
        function showError(message, field) {
            var aboutEntries = !!message && field === 'entries';
            error.textContent = aboutEntries ? '' : message;
            error.classList.toggle('d-none', !message || aboutEntries);
            comment.classList.toggle('is-invalid', !!message && !aboutEntries);
            if (entries) {
                entriesError.textContent = aboutEntries ? message : '';
                entriesError.classList.toggle('d-none', !aboutEntries);
                entries.classList.toggle('is-invalid', aboutEntries);
            }
        }

        function resetSubmit() {
            submit.disabled = false;
            submit.className = 'btn ' + kind.submitClass;
            submit.innerHTML = '<i class="bi ' + kind.icon + '"></i> ' + kind.submit;
        }

        modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button || !button.classList.contains('js-complete')) return;

            kind = KINDS[button.dataset.kind] || KINDS.complete;

            form.action = button.dataset.action;
            form.elements.part.value = button.dataset.part;
            form.elements.return_to.value = button.dataset.returnTo || '';
            form.elements.redirect_status.value = button.dataset.redirectStatus || '';
            form.elements.redirect_view.value = button.dataset.redirectView || '';
            form.elements.redirect_role.value = button.dataset.redirectRole || '';

            // Admin's "Done by" (only in the page for Admin): Data Entry's
            // actions pick from its members, a Sourcing Mark Complete names the
            // part's assigned member. Whichever isn't wanted is hidden and
            // disabled, so it isn't sent.
            var actor = document.getElementById('complete-actor');
            if (actor) {
                var namesActor = button.dataset.actorRole === 'Data Entry';
                var actorSelect = actor.querySelector('select');
                actor.classList.toggle('d-none', !namesActor);
                if (actorSelect) actorSelect.disabled = !namesActor;
            }

            var assignee = document.getElementById('complete-assignee');
            if (assignee) {
                var namesAssignee = button.dataset.actorRole === 'Sourcing' && !!button.dataset.assigneeId;
                var assigneeSelect = assignee.querySelector('select');
                assigneeSelect.innerHTML = '';
                if (namesAssignee) {
                    assigneeSelect.add(new Option(button.dataset.assigneeName, button.dataset.assigneeId, true, true));
                }
                assignee.classList.toggle('d-none', !namesAssignee);
                assigneeSelect.disabled = !namesAssignee;
            }

            document.getElementById('completeModalLabel').textContent = kind.title;
            document.getElementById('complete-subtitle').textContent =
                (button.dataset.who ? button.dataset.who + '\'s part \u00b7 ' : '') + button.dataset.rfqNumber + ' \u2014 ' + button.dataset.subject;
            document.getElementById('complete-lead-icon').className = 'bi ' + (kind.field === 'reason' ? kind.icon : 'bi-chat-left-text');
            document.getElementById('complete-lead-title').textContent = button.dataset.heading;
            document.getElementById('complete-hint').textContent = button.dataset.hint;
            document.getElementById('complete-label').textContent = kind.label;
            document.getElementById('complete-audience').textContent = button.dataset.audience;

            // Assign to Data Entry: whom there it's for — who it was given
            // to before, if it's been with them.
            var dataEntryBox = document.getElementById('complete-data-entry');
            if (dataEntryBox) {
                var picksDataEntry = (button.dataset.kind || 'sourcing') === 'sourcing';
                var dataEntrySelect = document.getElementById('complete-data-entry-select');
                dataEntryBox.classList.toggle('d-none', !picksDataEntry);
                dataEntrySelect.disabled = !picksDataEntry;
                dataEntrySelect.value = picksDataEntry ? (button.dataset.deAssignee || '') : '';
                if (dataEntrySelect.selectedIndex === -1) dataEntrySelect.value = '';
            }

            if (lineItemsBox) {
                var wantsLineItems = (button.dataset.kind || 'sourcing') === 'sourcing';
                lineItemsBox.classList.toggle('d-none', !wantsLineItems);
                lineItems.disabled = !wantsLineItems;
                // What was given the last time it went to Data Entry, if it has.
                lineItems.value = wantsLineItems ? (button.dataset.lineItems || '') : '';
                showLineItemsError('');
            }

            if (entriesBox) {
                var wantsEntries = button.dataset.kind === 'data_entry';
                entriesBox.classList.toggle('d-none', !wantsEntries);
                entries.disabled = !wantsEntries;
                entries.value = '';
                entriesHint.textContent = 'How many entries you made on this part.'
                    + (button.dataset.lineItems ? ' Sourcing asked for ' + button.dataset.lineItems + (button.dataset.lineItems === '1' ? ' line item.' : ' line items.') : '')
                    + (button.dataset.items ? ' The RFQ has ' + button.dataset.items + (button.dataset.items === '1' ? ' item.' : ' items.') : '');
            }

            comment.name = kind.field;
            comment.maxLength = kind.max;
            comment.placeholder = button.dataset.placeholder || '';
            comment.value = '';
            count.textContent = '0 / ' + kind.max;
            showError('');
            resetSubmit();

            if (button.dataset.backModal) {
                cancel.removeAttribute('data-bs-dismiss');
                cancel.setAttribute('data-bs-toggle', 'modal');
                cancel.setAttribute('data-bs-target', '#' + button.dataset.backModal);
                cancel.textContent = 'Back';
            } else {
                cancel.removeAttribute('data-bs-toggle');
                cancel.removeAttribute('data-bs-target');
                cancel.setAttribute('data-bs-dismiss', 'modal');
                cancel.textContent = 'Cancel';
            }
        });

        modalEl.addEventListener('shown.bs.modal', function () {
            (asksEntries() ? entries : (asksLineItems() && !lineItems.value ? lineItems : comment)).focus();
        });

        if (entries) {
            entries.addEventListener('input', function () {
                if (entriesGiven()) showError('');
            });
        }

        comment.addEventListener('input', function () {
            count.textContent = comment.value.length + ' / ' + comment.maxLength;
            if (comment.value.trim()) showError('');
        });

        if (lineItems) {
            lineItems.addEventListener('input', function () {
                if (lineItemsGiven()) showLineItemsError('');
            });
        }

        form.addEventListener('submit', function (event) {
            if (asksLineItems() && !lineItemsGiven()) {
                event.preventDefault();
                showLineItemsError('Add how many line items Data Entry has to add — a whole number, 1 or more.');
                lineItems.focus();
                return;
            }

            if (asksEntries() && !entriesGiven()) {
                event.preventDefault();
                showError('Add how many entries you made on this part — a whole number, 0 or more.', 'entries');
                entries.focus();
                return;
            }

            if (!comment.value.trim()) {
                event.preventDefault();
                showError(kind.missing);
                comment.focus();
                return;
            }

            // One click, one save.
            submit.disabled = true;
            submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving\u2026';
        });
    })();

    // Data Entry's Pause asks why first, and for the document it's waiting
    // on, if any (_pause_data_entry_modal.blade.php): every Pause button
    // (.js-pause-data-entry) opens the one popup, pointed at its part. From
    // inside a quick-detail modal, Cancel goes back to it.
    (function () {
        var modalEl = document.getElementById('pauseDataEntryModal');
        if (!modalEl || modalEl.dataset.bound) return;
        modalEl.dataset.bound = '1';

        var form = document.getElementById('pauseDataEntryForm');
        var reason = document.getElementById('pause-reason');
        var reference = document.getElementById('pause-reference');
        var count = document.getElementById('pause-count');
        var error = document.getElementById('pause-error');
        var cancel = document.getElementById('pause-cancel');
        var submit = document.getElementById('pause-submit');

        function showError(message) {
            error.textContent = message;
            error.classList.toggle('d-none', !message);
            reason.classList.toggle('is-invalid', !!message);
        }

        modalEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button || !button.classList.contains('js-pause-data-entry')) return;

            form.action = button.dataset.action;
            form.elements.part.value = button.dataset.part;
            document.getElementById('pause-subtitle').textContent = button.dataset.rfqNumber + ' \u2014 ' + button.dataset.subject;
            reason.value = '';
            reference.value = '';
            count.textContent = '0 / ' + reason.maxLength;
            showError('');
            submit.disabled = false;
            submit.innerHTML = '<i class="bi bi-pause-fill"></i> Pause';

            if (button.dataset.backModal) {
                cancel.removeAttribute('data-bs-dismiss');
                cancel.setAttribute('data-bs-toggle', 'modal');
                cancel.setAttribute('data-bs-target', '#' + button.dataset.backModal);
            } else {
                cancel.removeAttribute('data-bs-toggle');
                cancel.removeAttribute('data-bs-target');
                cancel.setAttribute('data-bs-dismiss', 'modal');
            }
        });

        modalEl.addEventListener('shown.bs.modal', function () {
            reason.focus();
        });

        reason.addEventListener('input', function () {
            count.textContent = reason.value.length + ' / ' + reason.maxLength;
            if (reason.value.trim()) showError('');
        });

        form.addEventListener('submit', function (event) {
            if (!reason.value.trim()) {
                event.preventDefault();
                showError('Say why you\'re pausing it.');
                reason.focus();
                return;
            }

            // One click, one pause.
            submit.disabled = true;
            submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Pausing\u2026';
        });
    })();

    // The hover card behind every name marked .js-user-card (see
    // layouts/_user_card.blade.php): hover — or focus, or tap — a person's name
    // on a comment for their details and a Private message button, which opens
    // a little box to write them one right there. Sent as JSON, so the page
    // (or the modal the name was in) stays put. The one card is moved into
    // whichever modal the name is in: Bootstrap's focus trap would otherwise
    // pull the cursor back out of the message box.
    (function () {
        var card = document.getElementById('userCard');
        if (!card || !first) return;

        var els = {
            avatar: document.getElementById('userCardAvatar'),
            name: document.getElementById('userCardName'),
            roles: document.getElementById('userCardRoles'),
            email: document.getElementById('userCardEmail'),
            since: document.getElementById('userCardSince'),
            actions: document.getElementById('userCardActions'),
            you: document.getElementById('userCardYou'),
            message: document.getElementById('userCardMessage'),
            conversation: document.getElementById('userCardConversation'),
            composer: document.getElementById('userCardComposer'),
            recipientId: document.querySelector('#userCardComposer [name="recipient_id"]'),
            recipientName: document.getElementById('userCardRecipient'),
            body: document.querySelector('#userCardComposer [name="body"]'),
            cancel: document.getElementById('userCardCancel'),
            send: document.getElementById('userCardSend'),
            status: document.getElementById('userCardStatus'),
        };
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var current = null;
        var showTimer = null;
        var hideTimer = null;
        var holdTimer = null;
        var holding = false;

        function setStatus(message, kind) {
            els.status.textContent = message || '';
            els.status.className = 'user-card-status' + (kind ? ' is-' + kind : '');
        }

        function composerOpen() {
            return !els.composer.classList.contains('d-none');
        }

        function closeComposer() {
            els.composer.classList.add('d-none');
            card.classList.remove('is-composing');
            els.body.value = '';
        }

        function fill(trigger) {
            var data = trigger.dataset;

            // Their photo, or their initial without one.
            els.avatar.textContent = '';
            els.avatar.classList.toggle('has-photo', !!data.photo);
            if (data.photo) {
                var photo = document.createElement('img');
                photo.src = data.photo;
                photo.alt = '';
                els.avatar.appendChild(photo);
            } else {
                els.avatar.textContent = (data.name || '?').charAt(0).toUpperCase();
            }
            els.name.textContent = data.name;
            els.roles.innerHTML = '';
            (data.roles ? data.roles.split('|') : []).forEach(function (role) {
                var badge = document.createElement('span');
                badge.className = 'badge badge-soft-secondary';
                badge.textContent = role;
                els.roles.appendChild(badge);
            });
            els.email.textContent = data.email;
            els.since.textContent = data.since ? 'Member since ' + data.since : '';

            // Nobody messages themselves.
            var isSelf = data.self === '1';
            els.actions.classList.toggle('d-none', isSelf);
            els.you.classList.toggle('d-none', !isSelf);

            els.recipientId.value = data.userId;
            els.recipientName.textContent = data.name;
            els.conversation.href = data.conversation;
            closeComposer();
            setStatus('');
        }

        function place(trigger) {
            var rect = trigger.getBoundingClientRect();
            var margin = 8;
            var left = Math.max(margin, Math.min(rect.left, window.innerWidth - card.offsetWidth - margin));
            var top = rect.bottom + 6;

            // Flip above the name when there's no room below.
            if (top + card.offsetHeight > window.innerHeight - margin && rect.top - card.offsetHeight - 6 > margin) {
                top = rect.top - card.offsetHeight - 6;
            }
            card.style.left = left + 'px';
            card.style.top = top + 'px';
        }

        function show(trigger) {
            clearTimeout(hideTimer);

            var host = trigger.closest('.modal') || document.body;
            if (card.parentNode !== host) host.appendChild(card);

            if (current !== trigger) {
                fill(trigger);
                current = trigger;
            }
            card.classList.add('is-open');
            place(trigger);
        }

        function hide() {
            clearTimeout(showTimer);
            clearTimeout(hideTimer);
            clearTimeout(holdTimer);
            holding = false;
            card.classList.remove('is-open');
            current = null;
            closeComposer();
            setStatus('');
        }

        // Moving from the name to the card mustn't close it — and it stays put
        // while a message is being written, and for a moment after one's sent so
        // "Message sent" can be read.
        function hideSoon() {
            clearTimeout(hideTimer);
            if (composerOpen() || holding) return;
            hideTimer = setTimeout(hide, 250);
        }

        function triggerOf(event) {
            return event.target.closest ? event.target.closest('.js-user-card') : null;
        }

        document.addEventListener('mouseover', function (event) {
            var trigger = triggerOf(event);
            if (!trigger) return;

            clearTimeout(hideTimer);
            if (trigger === current && card.classList.contains('is-open')) return;
            clearTimeout(showTimer);
            showTimer = setTimeout(function () { show(trigger); }, 180);
        });

        document.addEventListener('mouseout', function (event) {
            if (!triggerOf(event)) return;
            clearTimeout(showTimer);
            hideSoon();
        });

        card.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
        card.addEventListener('mouseleave', hideSoon);

        // Keyboard and touch, where there's no hover.
        document.addEventListener('focusin', function (event) {
            var trigger = triggerOf(event);
            if (trigger) show(trigger);
        });
        document.addEventListener('click', function (event) {
            var trigger = triggerOf(event);
            if (trigger) show(trigger);
        });
        document.addEventListener('keydown', function (event) {
            var trigger = triggerOf(event);
            if (trigger && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                show(trigger);
            }
        });

        // Clicking away, or Escape, closes it — and only it, not the modal
        // it's sitting in.
        document.addEventListener('mousedown', function (event) {
            if (card.classList.contains('is-open') && !card.contains(event.target) && !triggerOf(event)) hide();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && card.classList.contains('is-open')) {
                event.stopPropagation();
                hide();
            }
        }, true);
        window.addEventListener('scroll', function () {
            if (card.classList.contains('is-open') && !composerOpen()) hide();
        }, true);

        els.message.addEventListener('click', function () {
            card.classList.add('is-composing');
            els.composer.classList.remove('d-none');
            setStatus('');
            place(current);
            els.body.focus();
        });

        els.cancel.addEventListener('click', function () {
            closeComposer();
            place(current);
        });

        els.composer.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!els.body.value.trim()) {
                setStatus('Write a message to send.', 'error');
                els.body.focus();
                return;
            }

            els.send.disabled = true;
            setStatus('Sending…');

            fetch(els.composer.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf ? csrf.content : '',
                },
                body: new FormData(els.composer),
            })
                .then(function (response) {
                    // Signed out (a redirect to the login page) or too many in a minute
                    // don't come back as JSON.
                    if (response.redirected || response.status === 419) {
                        throw new Error('Your session has expired — reload the page and sign in again.');
                    }
                    if (response.status === 429) {
                        throw new Error('You\'re sending messages too quickly — wait a moment.');
                    }

                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        var errors = result.data.errors || {};
                        var first = Object.keys(errors).length ? errors[Object.keys(errors)[0]][0] : result.data.message;
                        throw new Error(first);
                    }
                    closeComposer();
                    setStatus(result.data.message, 'ok');
                    els.conversation.href = result.data.conversation;
                    place(current);

                    holding = true;
                    clearTimeout(holdTimer);
                    holdTimer = setTimeout(function () {
                        holding = false;
                        if (!card.matches(':hover')) hideSoon();
                    }, 2500);
                })
                .catch(function (error) {
                    setStatus(error.message || 'Couldn\'t send that — try again.', 'error');
                })
                .then(function () {
                    els.send.disabled = false;
                });
        });
    })();

    // Senior Operations' Unassigned/Assigned filters (see _ops_filters.blade.php):
    // the form applies itself as you change it, after a short pause so a few
    // quick changes — two priorities, say — go through as one request. The
    // Custom range waits for its dates, and the tab you're on is remembered
    // so applying a filter doesn't drop you back on the first one.
    (function () {
        var form = document.getElementById('opsFilterForm');
        if (!form) return;

        var customRange = document.getElementById('opsCustomRange');
        var tabInput = document.getElementById('opsFilterTab');
        var from = form.elements.from;
        var to = form.elements.to;
        var today = new Date().toISOString().slice(0, 10);
        var timer = null;

        // Goes to the filtered page — with only what's actually set in the
        // URL, not a string of empty fields.
        function applyFilters() {
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, key) {
                var isDefault = value === '' || (key === 'range' && value === 'all') || (key === 'sort' && value === 'newest');
                if (!isDefault) params.append(key, value);
            });

            var card = form.closest('.card');
            if (card) card.classList.add('is-loading');
            window.location.assign(form.action + '?' + params.toString());
        }

        function submitSoon(delay) {
            clearTimeout(timer);
            timer = setTimeout(applyFilters, delay);
        }

        // Coming back to the page with the Back button shouldn't leave it
        // dimmed (on the window, so once only).
        if (first) {
            window.addEventListener('pageshow', function (event) {
                var filterForm = document.getElementById('opsFilterForm');
                var card = filterForm ? filterForm.closest('.card') : null;
                if (event.persisted && card) card.classList.remove('is-loading');
            });
        }

        // The two dates can't cross, or go past today.
        function limitCustomDates() {
            from.max = to.value || today;
            to.min = from.value || '';
            to.max = today;
        }

        form.addEventListener('change', function (event) {
            var field = event.target;

            if (field.name === 'range') {
                var isCustom = field.value === 'custom';
                customRange.classList.toggle('d-none', !isCustom);
                if (isCustom) {
                    limitCustomDates();
                    (from.value ? to : from).focus();
                    return; // nothing to filter on until a date's picked
                }
            }
            if (field.name === 'from' || field.name === 'to') {
                limitCustomDates();
                if (!from.value && !to.value) return;
            }

            submitSoon(field.type === 'checkbox' ? 500 : 150);
        });

        // The tab's kept in the address too (?tab=), so a reload, a live
        // update, or coming back after an action on one of its rows (they
        // redirect back) opens it again.
        document.querySelectorAll('[data-ops-tab]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                tabInput.value = tab.dataset.opsTab;

                var url = new URL(window.location.href);
                if (tab.dataset.opsTab === 'assigned') {
                    url.searchParams.set('tab', 'assigned');
                } else {
                    url.searchParams.delete('tab');
                }
                window.history.replaceState(null, '', url);
            });
        });

        limitCustomDates();

        // A live update puts back the tab you were on without firing its
        // event, so pick it up from what's showing.
        var activeTab = document.querySelector('[data-ops-tab].active');
        if (activeTab) tabInput.value = activeTab.dataset.opsTab;
    })();

    // The period filters — Business Development's dashboard summary
    // (?period=) and the bar above the RFQ lists (?range=, _list_filters):
    // a preset goes straight there; Custom shows its two dates and goes once
    // both are set, or on Enter / Apply; a priority after a short pause, so a
    // few quick clicks go as one. Only what's set goes in the address, so the
    // default period, empty fields and the dates of a preset stay out.
    document.querySelectorAll('.js-period-filter').forEach(function (form) {
        var field = form.dataset.periodField;
        var fallback = form.dataset.periodDefault;
        var customRange = form.querySelector('.js-custom-range');
        var from = form.elements.from;
        var to = form.elements.to;
        var today = form.dataset.today;
        var timer = null;

        function chosen() {
            var picked = form.querySelector('input[name="' + field + '"]:checked');
            return picked ? picked.value : fallback;
        }

        function applyPeriod() {
            var isCustom = chosen() === 'custom';
            var hasDates = from && to && (from.value || to.value);
            var params = new URLSearchParams();

            new FormData(form).forEach(function (value, key) {
                var isDate = key === 'from' || key === 'to';
                if (value === '' || (isDate && !isCustom)) return;
                // A custom range with no dates is the default.
                if (key === field && (value === fallback || (value === 'custom' && !hasDates))) return;
                params.append(key, value);
            });

            var card = form.closest('.card');
            if (card) card.classList.add('is-loading');
            var query = params.toString();
            window.location.assign(form.action + (query ? '?' + query : ''));
        }

        // The two dates can't cross, or go past today.
        function limitDates() {
            from.max = to.value || today;
            to.min = from.value || '';
            to.max = today;
        }

        form.addEventListener('change', function (event) {
            var target = event.target;

            if (target.name === field) {
                var isCustom = target.value === 'custom';
                if (customRange) customRange.classList.toggle('d-none', !isCustom);
                if (isCustom) {
                    limitDates();
                    (from.value ? to : from).focus();
                    return; // nothing to filter on until a date's picked
                }
                applyPeriod();
            }

            if (target.name === 'from' || target.name === 'to') {
                limitDates();
                if (from.value && to.value) applyPeriod();
            }

            if (target.type === 'checkbox') {
                clearTimeout(timer);
                timer = setTimeout(applyPeriod, 500);
            }

            // A choice from a menu — the list's grouping — goes straight there.
            if (target.tagName === 'SELECT') applyPeriod();
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            applyPeriod();
        });
    });

    // Coming back with the browser's Back button shows the page as it was
    // left, the period filter's card dimmed and all.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        document.querySelectorAll('.js-period-filter').forEach(function (form) {
            var card = form.closest('.card');
            if (card) card.classList.remove('is-loading');
        });
    });

    // A list's tabs — Data Entry's Ready and Completed, Sourcing's Pending for
    // Sourcing, With Data Entry and Finalized: the one open is kept in the address (?tab=, left
    // off for the first, data-tab-default), so a reload, a live update or
    // coming back after an action opens it again — and so is the search.
    document.querySelectorAll('[data-de-tab]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            var isDefault = tab.hasAttribute('data-tab-default');
            var url = new URL(window.location.href);
            if (isDefault) {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tab.dataset.deTab);
            }
            window.history.replaceState(null, '', url);

            var searchTab = document.getElementById('deTabInput');
            if (searchTab) {
                searchTab.value = tab.dataset.deTab;
                searchTab.disabled = isDefault;
            }
        });
    });

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
    // populated per-row with which RFQ it's actually rejecting, and which part
    // of it when it's one part that's being sent back (none for a whole RFQ).
    document.querySelectorAll('.js-reject-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('rejectRfqForm');
            if (!form) return;

            var isPart = !!button.dataset.part;
            form.action = button.dataset.action;
            form.querySelector('[name="reject_rfq_id"]').value = button.dataset.rfqId || '';
            form.querySelector('[name="part"]').value = button.dataset.part || '';
            form.querySelector('[name="reject_label"]').value = button.dataset.label || '';
            document.getElementById('rejectRfqTarget').textContent = button.dataset.label || '';
            form.dataset.confirm = isPart
                ? 'Send this part back? Any approval already given for it is undone.'
                : 'Send this RFQ back? Any approval already given for it is undone.';
        });
    });

    // Senior Operations putting an RFQ on hold, or cancelling it: the button
    // that opens the popup says which, and the popup asks why. On a list, the
    // popup is shared by the rows, so the button says which RFQ too.
    document.querySelectorAll('.js-rfq-status').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('rfqStatusForm');
            if (!form) return;

            var cancelling = button.dataset.status === 'Cancelled';
            var part = button.dataset.part || '';
            var reason = form.querySelector('[name="reason"]');
            var submit = document.getElementById('rfqStatusSubmit');
            var title = part
                ? (cancelling ? 'Cancel part' : 'Put part on hold')
                : (cancelling ? 'Cancel RFQ' : 'Put on hold');

            if (button.dataset.action) {
                form.action = button.dataset.action;
                form.querySelector('[name="status_rfq_id"]').value = button.dataset.rfqId || '';
                form.querySelector('[name="status_label"]').value = button.dataset.label || '';
                document.getElementById('rfqStatusTarget').textContent = button.dataset.label || '';
            }

            form.querySelector('[name="status"]').value = button.dataset.status;
            form.querySelector('[name="part"]').value = part;
            document.getElementById('rfqStatusModalLabel').textContent = title;
            document.getElementById('rfqStatusHint').textContent = part
                ? (cancelling
                    ? 'Just this part comes out of the RFQ — the rest carries on without it — until Senior Operations reopens it.'
                    : 'Just this part comes out of every queue, and its time stops counting, until Senior Operations resumes it. The rest carries on, but the RFQ doesn\'t move on past it meanwhile.')
                : (cancelling
                    ? 'It comes out of the workflow — every queue — until Senior Operations reopens it.'
                    : 'It comes out of every queue, and its time stops counting, until Senior Operations resumes it — just where it was.');
            submit.querySelector('i').className = 'bi ' + (cancelling ? 'bi-x-circle' : 'bi-pause-circle');
            submit.querySelector('span').textContent = title;
            reason.value = '';
            reason.classList.remove('is-invalid');
        });
    });

    // Data Entry's Start is on at any hour, but off while they've another
    // part running (data-busy-hint). At lunch or out of hours — checked
    // against the off-hours windows the page brought (#off-hours: [from, to,
    // why], Unix seconds) every half minute — hovering (or focusing) it says
    // their time won't count till working hours are back, and when. From a
    // wrapper, since a disabled button takes no hover. One delegated tooltip
    // on the document, made once, covers every Start however often the page
    // is swapped. The server checks the running part again on Start.
    (function () {
        if (!window.__rfqmsStartHints && window.bootstrap) {
            window.__rfqmsStartHints = new bootstrap.Tooltip(document.body, {
                selector: '.js-start-hint',
                title: function (wrapper) { return wrapper.dataset.startHint; },
                placement: 'top',
                trigger: 'hover focus'
            });
        }

        // A hint left showing for a Start the page swapped out goes with it.
        document.querySelectorAll('body > .tooltip').forEach(function (tip) {
            if (!document.querySelector('[aria-describedby="' + tip.id + '"]')) tip.remove();
        });

        var source = document.getElementById('off-hours');
        if (!source) return;

        var windows = JSON.parse(source.textContent || '[]');
        var sync = function () {
            var now = Date.now() / 1000;
            var off = windows.find(function (span) { return now >= span[0] && now < span[1]; });

            document.querySelectorAll('.js-start-hint').forEach(function (wrapper) {
                // One running part elsewhere keeps it off whatever the hour;
                // out of hours it's on, saying time won't count yet.
                var busy = wrapper.dataset.busyHint;
                var hint = busy || (off
                    ? 'Start \u2014 outside working hours, your time on it counts once they\'re back. ' + off[2]
                    : wrapper.dataset.onHint);
                var button = wrapper.querySelector('.js-start-button');

                if (button) button.disabled = !!busy;
                wrapper.classList.toggle('is-off', !!busy);

                if (wrapper.dataset.startHint === hint) return;
                wrapper.dataset.startHint = hint;

                var tooltip = window.bootstrap && bootstrap.Tooltip.getInstance(wrapper);
                if (tooltip) tooltip.setContent({ '.tooltip-inner': hint });
            });
        };

        sync();
        clearInterval(window.__rfqmsWorkingHoursTimer);
        window.__rfqmsWorkingHoursTimer = setInterval(sync, 30000);
    })();

    // A popup opened from a button starts with no files picked — the photos or
    // files for another part, or another RFQ, aren't carried over. Bound
    // once, on the document, however often the page is rebound.
    if (!window.__rfqmsAttachmentReset) {
        window.__rfqmsAttachmentReset = true;
        document.addEventListener('show.bs.modal', function (event) {
            if (!event.relatedTarget) return;
            event.target.querySelectorAll('input[type="file"]').forEach(function (input) {
                input.value = '';
            });
        });
    }

    // Senior Operations' Reassign on a part still with Sourcing: the popup is
    // pointed at the part, says who has it, and won't offer them again.
    var reassignModal = document.getElementById('reassignModal');
    if (reassignModal) {
        reassignModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button || !button.classList.contains('js-reassign-part')) return;

            var form = document.getElementById('reassignForm');
            var select = form.querySelector('[name="user_id"]');
            var target = button.dataset.label + ' \u2014 with ' + button.dataset.currentName;

            form.action = button.dataset.action;
            form.querySelector('[name="reassign_rfq_id"]').value = button.dataset.rfqId || '';
            form.querySelector('[name="part"]').value = button.dataset.part || '';
            form.querySelector('[name="reassign_target"]').value = target;
            document.getElementById('reassignTarget').textContent = target;

            select.value = '';
            select.classList.remove('is-invalid');
            var reason = form.querySelector('[name="reason"]');
            if (reason) reason.value = '';
            Array.prototype.forEach.call(select.options, function (option) {
                option.disabled = option.value !== '' && option.value === button.dataset.currentId;
            });
        });
    }

    // Business Development's Close popup — same shared-modal-populated-per-row
    // pattern as the Reject modal: which RFQ, and which part of it (none for a
    // whole RFQ), with the reference code it asks for left empty to fill in.
    document.querySelectorAll('.js-close-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('closeRfqForm');
            if (!form) return;

            var code = form.querySelector('[name="reference_code"]');
            form.action = button.dataset.action;
            form.querySelector('[name="close_rfq_id"]').value = button.dataset.rfqId || '';
            form.querySelector('[name="part"]').value = button.dataset.part || '';
            form.querySelector('[name="close_label"]').value = button.dataset.label || '';
            document.getElementById('closeRfqTarget').textContent = button.dataset.label || '';
            document.getElementById('closeRfqHint').textContent = button.dataset.hint || '';
            code.value = '';
            code.classList.remove('is-invalid');
        });
    });

    var closeRfqModal = document.getElementById('closeRfqModal');
    if (closeRfqModal) {
        closeRfqModal.addEventListener('shown.bs.modal', function () {
            closeRfqModal.querySelector('[name="reference_code"]').focus();
        });
    }

    // Senior Operations' "Get Details Again" modal — same shared-modal-
    // populated-per-row pattern as the Reject modal above.
    document.querySelectorAll('.js-request-details').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('requestDetailsForm');
            if (!form) return;

            form.action = button.dataset.action;
            form.querySelector('[name="request_details_rfq_id"]').value = button.dataset.rfqId || '';
            form.querySelector('[name="request_details_label"]').value = button.dataset.label || '';
            document.getElementById('requestDetailsTarget').textContent = button.dataset.label || '';
        });
    });

    // GM Assistant's Submit prompt — pointed at the part (none for a whole
    // RFQ) of the Submit button that opened it, with a fresh comment box.
    document.querySelectorAll('.js-gm-assistant-submit').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('gmAssistantSubmitForm');
            if (!form) return;

            form.action = button.dataset.action;
            form.elements.part.value = button.dataset.part || '';
            form.elements.comment.value = '';
            document.getElementById('gmAssistantSubmitTarget').textContent = button.dataset.label || '';
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

        // One dropdown for the page, kept across live updates.
        var dropdown = document.querySelector('.mention-dropdown');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'mention-dropdown';
            dropdown.style.display = 'none';
            document.body.appendChild(dropdown);
        }

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

    // Clear stale input/validation state when a modal is closed. One listener
    // on the document (Bootstrap's modal events bubble), so it covers the
    // modals a live update brings in, too.
    if (first) {
        document.addEventListener('hidden.bs.modal', function (event) {
            var modalEl = event.target;
            if (!modalEl.classList || !modalEl.classList.contains('modal')) return;

            var form = modalEl.querySelector('form');
            if (form) form.reset();
            modalEl.querySelectorAll('.is-invalid').forEach(function (el) {
                el.classList.remove('is-invalid');
            });
            modalEl.querySelectorAll('.role-pick-input').forEach(function (checkbox) {
                syncRolePickCard(checkbox);
            });
        });
    }

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

    // Sourcing's Pending list and Data Entry's: each part counts down the
    // working time its priority allows (Data Entry's from their Start). The
    // server gives where each one stood when the page was made, and the
    // working periods ahead (#countdown-schedule); from there only working
    // time is taken off, so outside working hours a countdown is paused —
    // and so is a running row's glow (.js-running-row). One timer for the
    // page — a live refresh brings a fresh schedule and starts it over.
    // Labels and colours as in Setting::countdownLabel() /
    // countdownBadgeClass().
    // How much working time has passed since the schedule was made, and
    // whether it's paused now — by $worker's own hours where they work extra
    // ones (schedule.people, ExtraWorkingHour), else everyone's.
    var workingClock = function (schedule, worker, now) {
        var periods = (worker && schedule.people && schedule.people[worker]) || schedule.periods;
        var worked = 0;
        var paused = true;

        periods.forEach(function (period) {
            worked += Math.max(0, Math.min(now, period[1]) - Math.max(schedule.now, period[0]));
            if (now >= period[0] && now < period[1]) paused = false;
        });

        return { worked: worked, paused: paused };
    };

    clearInterval(window.__rfqmsCountdownTimer);
    var countdownScheduleEl = document.getElementById('countdown-schedule');
    if (countdownScheduleEl) {
        var countdownSchedule = JSON.parse(countdownScheduleEl.textContent);
        // The server's clock, not the browser's, which may be set wrong.
        var clockOffset = countdownSchedule.now - Date.now() / 1000;
        var countdownHours = function (minutes) {
            var hours = Math.floor(minutes / 60);
            var rest = minutes % 60;
            return hours === 0 ? rest + 'm' : hours + 'h' + (rest > 0 ? ' ' + rest + 'm' : '');
        };
        var countdownLabel = function (remaining) {
            if (remaining >= 60) return countdownHours(Math.floor(remaining / 60)) + ' left';
            if (remaining > 0) return 'Under 1m left';
            if (remaining > -60) return 'Due now';
            return 'Overdue by ' + countdownHours(Math.floor(-remaining / 60));
        };

        var tickCountdowns = function () {
            var now = Date.now() / 1000 + clockOffset;

            document.querySelectorAll('[data-countdown]').forEach(function (badge) {
                var clock = workingClock(countdownSchedule, badge.dataset.worker, now);
                var worked = clock.worked;
                var paused = clock.paused;
                var remaining = Math.round(Number(badge.dataset.remaining) - worked);
                var target = Number(badge.dataset.target);

                badge.classList.toggle('badge-soft-danger', remaining <= 0);
                badge.classList.toggle('badge-soft-warning', remaining > 0 && remaining <= target / 4);
                badge.classList.toggle('badge-soft-success', remaining > target / 4);
                badge.classList.toggle('is-paused', paused);
                badge.title = paused ? 'Paused — outside working hours' : (badge.dataset.runningTitle || 'Working time left to mark it complete');
                badge.querySelector('i').className = 'bi ' + (paused ? 'bi-pause-circle' : 'bi-stopwatch');
                badge.querySelector('.sourcing-countdown-label').textContent = countdownLabel(remaining);
            });

            document.querySelectorAll('.js-running-row').forEach(function (row) {
                var paused = workingClock(countdownSchedule, row.dataset.worker, now).paused;
                row.classList.toggle('is-paused', paused);
                var label = row.querySelector('.running-pill-label');
                if (label) label.textContent = paused ? 'Off hours' : 'Running';
            });
        };

        tickCountdowns();
        window.__rfqmsCountdownTimer = setInterval(tickCountdowns, 15000);
    }

    // Data Entry's countdown to Senior Operations hearing they haven't started
    // (_data_entry_idle_banner): like the countdowns above, only working time
    // comes off — from the same #countdown-schedule — but to the second, with
    // a bar running down. At zero it says the message is on its way; a live
    // refresh brings in "sent" once it is. The clock reads as
    // Setting::clockLabel() has it.
    clearInterval(window.__rfqmsIdleTimer);
    (function () {
        var banner = document.querySelector('.js-idle-countdown');
        var scheduleEl = document.getElementById('countdown-schedule');
        if (!banner || !scheduleEl) return;

        var schedule = JSON.parse(scheduleEl.textContent);
        var offset = schedule.now - Date.now() / 1000;
        var startRemaining = Number(banner.dataset.remaining);
        var alertAfter = Number(banner.dataset.alertAfter) || 1;
        var clock = banner.querySelector('.js-idle-clock');
        var text = banner.querySelector('.js-idle-text');
        var bar = banner.querySelector('.js-idle-bar');
        var pad = function (number) { return (number < 10 ? '0' : '') + number; };
        var clockLabel = function (seconds) {
            seconds = Math.max(0, seconds);
            var hours = Math.floor(seconds / 3600);
            var rest = pad(Math.floor((seconds % 3600) / 60)) + ':' + pad(seconds % 60);
            return hours > 0 ? hours + ':' + rest : rest;
        };

        var tick = function () {
            var now = Date.now() / 1000 + offset;
            var clock = workingClock(schedule, banner.dataset.worker, now);
            var paused = clock.paused;

            var remaining = Math.round(startRemaining - clock.worked);
            var state = remaining <= 0 ? 'due' : (paused ? 'paused' : 'running');

            clock.textContent = clockLabel(remaining);
            bar.style.width = Math.max(0, Math.min(100, remaining / alertAfter * 100)) + '%';
            banner.classList.toggle('is-due', state === 'due');
            banner.classList.toggle('is-paused', state === 'paused');
            banner.classList.toggle('is-urgent', state === 'running' && remaining <= alertAfter / 4);

            if (text.dataset.state !== state) {
                text.dataset.state = state;
                text.textContent = banner.dataset[state + 'Text'];
            }
        };

        tick();
        window.__rfqmsIdleTimer = setInterval(tick, 1000);
    })();

    // The attendance popup (admin/attendance): marking someone on leave, or
    // on a half day, tints their row and opens up why — and, for a half day,
    // which half they were off; present greys it out again — it's only kept
    // for time off (see AttendanceController). The button that opens it
    // says whether it's a new day's sheet or a submitted one to correct, and
    // with what marks; one with no button (a refused submission coming back)
    // is left as the server filled it.
    var syncAttendanceRow = function (row) {
        var checked = row.querySelector('.js-attendance-status:checked');
        var status = checked ? checked.value : 'present';
        var halfDay = status === 'half_day';
        row.classList.toggle('is-absent', status === 'absent');
        row.classList.toggle('is-half-day', halfDay);
        row.querySelectorAll('.attendance-absence select, .attendance-absence input').forEach(function (field) {
            field.disabled = field.classList.contains('attendance-half') ? !halfDay : status === 'present';
        });
        var half = row.querySelector('.attendance-half');
        if (half) half.classList.toggle('d-none', !halfDay);
    };

    document.querySelectorAll('.js-attendance-status').forEach(function (radio) {
        radio.addEventListener('change', function () {
            syncAttendanceRow(radio.closest('tr'));
        });
    });
    document.querySelectorAll('[data-attendance-user]').forEach(syncAttendanceRow);

    // One sheet a day: a new sheet for a day that already has one is turned
    // away as soon as the day's picked, rather than on submitting.
    var checkAttendanceDate = function () {
        var form = document.getElementById('attendanceForm');
        if (!form) return;

        var date = form.querySelector('[name="date"]');
        var isNew = form.querySelector('[name="_method"]').disabled;
        var taken = isNew && JSON.parse(form.dataset.sheetDates || '[]').indexOf(date.value) !== -1;

        date.classList.toggle('is-invalid', taken);
        document.getElementById('attendance-date-taken').classList.toggle('d-block', taken);
        document.getElementById('attendance-submit').disabled = taken || !form.querySelector('[data-attendance-user]');
    };

    var attendanceDate = document.getElementById('attendance-date');
    if (attendanceDate) {
        attendanceDate.addEventListener('change', checkAttendanceDate);
        attendanceDate.addEventListener('input', checkAttendanceDate);
    }

    var attendanceModal = document.getElementById('attendanceModal');
    if (attendanceModal) {
        attendanceModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button || !button.classList.contains('js-attendance-open')) return;

            var form = document.getElementById('attendanceForm');
            var editing = !!button.dataset.sheetId;
            var marks = button.dataset.marks ? JSON.parse(button.dataset.marks) : {};
            var date = form.querySelector('[name="date"]');

            form.action = button.dataset.action;
            form.querySelector('[name="_method"]').disabled = !editing;
            form.querySelector('[name="sheet_id"]').value = button.dataset.sheetId || '';
            date.value = button.dataset.date || date.dataset.today;
            date.readOnly = editing;
            document.getElementById('attendanceModalLabel').textContent = editing
                ? 'Edit attendance — ' + button.dataset.dateLabel
                : 'New attendance sheet';
            document.querySelector('#attendance-submit span').textContent = editing ? 'Save attendance' : 'Submit attendance';

            form.querySelectorAll('.is-invalid').forEach(function (field) { field.classList.remove('is-invalid'); });
            form.querySelectorAll('.invalid-feedback:not(#attendance-date-taken), .attendance-error').forEach(function (message) { message.remove(); });

            checkAttendanceDate();

            form.querySelectorAll('[data-attendance-user]').forEach(function (row) {
                var mark = marks[row.dataset.attendanceUser] || { status: 'present' };
                row.querySelector('.js-attendance-status[value="' + mark.status + '"]').checked = true;
                row.querySelector('.attendance-half').value = mark.half_off || '';
                row.querySelector('.attendance-absence input').value = mark.note || '';
                syncAttendanceRow(row);
            });
        });
    }

    // HR Manager's Return on a submitted sheet: the popup asks what needs
    // correcting, pointed at the sheet whose button opened it.
    var attendanceReturnModal = document.getElementById('attendanceReturnModal');
    if (attendanceReturnModal) {
        attendanceReturnModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button || !button.classList.contains('js-attendance-return')) return;

            var form = document.getElementById('attendanceReturnForm');
            var reason = form.querySelector('[name="reason"]');

            form.action = button.dataset.action;
            form.querySelector('[name="return_sheet_id"]').value = button.dataset.sheetId || '';
            document.getElementById('attendanceReturnDay').textContent = button.dataset.dateLabel || '';
            reason.value = '';
            reason.classList.remove('is-invalid');
        });
    }

    // Settings: keep the open tab in the address (?tab=), so a reload — or
    // coming back to the page — opens it again. See settings/edit.blade.php.
    document.querySelectorAll('[data-settings-tab]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab.dataset.settingsTab);
            window.history.replaceState(null, '', url);
        });
    });

    // A person's page (users/show): the tab open is kept in the address
    // (?tab=), so a reload — or a live update — opens it again. Overview,
    // the first, needs none.
    document.querySelectorAll('[data-user-tab]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            var url = new URL(window.location.href);
            if (tab.dataset.userTab === 'overview') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tab.dataset.userTab);
            }
            window.history.replaceState(null, '', url);
        });
    });

    // On a narrow screen the tabs are a row that scrolls: bring the open one
    // into view, so it's plain which one you're on.
    var settingsTabs = document.querySelector('.settings-tabs');
    var openSettingsTab = settingsTabs && settingsTabs.querySelector('.nav-link.active');
    if (openSettingsTab && settingsTabs.scrollWidth > settingsTabs.clientWidth) {
        settingsTabs.scrollLeft = openSettingsTab.offsetLeft - (settingsTabs.clientWidth - openSettingsTab.offsetWidth) / 2;
    }

    // A working week to fill in (settings/_week_table) — the company's on
    // Settings → Working Hours, a role's, or one person's: a day switched off
    // greys out and locks its times (they're kept, and come back when it's
    // switched on again), and each day's hours — and the week's — follow
    // what's typed. Same sum as Setting::workingMinutes(): working time less
    // whatever part lunch takes.
    var toMinutes = function (value) {
        var match = /^(\d{2}):(\d{2})$/.exec(value || '');
        return match ? Number(match[1]) * 60 + Number(match[2]) : null;
    };
    var hoursLabel = function (minutes) {
        var hours = Math.floor(minutes / 60);
        var rest = minutes % 60;
        return hours === 0 ? rest + 'm' : hours + 'h' + (rest > 0 ? ' ' + rest + 'm' : '');
    };
    var timeOf = function (row, field) {
        return toMinutes(row.querySelector('[data-time="' + field + '"]').value);
    };

    document.querySelectorAll('.js-week-table').forEach(function (weekTable) {
        if (weekTable.dataset.weekBound) return;
        weekTable.dataset.weekBound = '1';

        var refreshWeek = function () {
            var week = 0;

            weekTable.querySelectorAll('[data-working-day]').forEach(function (row) {
                var working = row.querySelector('.js-working-day').checked;
                row.classList.toggle('is-off', !working);
                row.querySelectorAll('input[type="time"]').forEach(function (input) {
                    input.disabled = !working;
                });

                var minutes = 0;
                var start = timeOf(row, 'start');
                var end = timeOf(row, 'end');
                if (working && start !== null && end !== null && end > start) {
                    minutes = end - start;
                    var lunchStart = timeOf(row, 'lunch_start');
                    var lunchEnd = timeOf(row, 'lunch_end');
                    if (lunchStart !== null && lunchEnd !== null) {
                        minutes -= Math.max(0, Math.min(end, lunchEnd) - Math.max(start, lunchStart));
                    }
                }

                week += minutes;
                row.querySelector('.js-day-hours').textContent = working ? hoursLabel(minutes) : 'Day off';
            });

            weekTable.querySelector('.js-week-total').textContent = hoursLabel(week);
        };

        weekTable.addEventListener('input', refreshWeek);
        weekTable.addEventListener('change', refreshWeek);
    });
}

document.addEventListener('DOMContentLoaded', rfqmsBoot);
window.rfqmsRebind = rfqmsBoot;

// A profile photo picked (users/_photo.blade.php): squared from its middle
// and shrunk to PHOTO_SIZE in the browser — which also turns it the right
// way up and drops what the camera wrote into it, like where it was taken —
// shown in the circle at once, and sent. If the browser can't do that, the
// photo goes as it is and the server checks it all the same.
(function () {
    var PHOTO_SIZE = 512;

    function squared(file) {
        return new Promise(function (resolve) {
            if (!window.URL || !window.DataTransfer || !/^image\//.test(file.type)) {
                resolve(null);
                return;
            }

            var source = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
                var side = Math.min(image.naturalWidth, image.naturalHeight);
                var size = Math.min(PHOTO_SIZE, side);
                var canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;

                var context = canvas.getContext('2d');
                // A see-through photo goes on white, as JPEG has no see-through.
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, size, size);
                context.drawImage(image, (image.naturalWidth - side) / 2, (image.naturalHeight - side) / 2, side, side, 0, 0, size, size);
                URL.revokeObjectURL(source);

                canvas.toBlob(function (blob) {
                    resolve(blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : null);
                }, 'image/jpeg', 0.88);
            };
            image.onerror = function () {
                URL.revokeObjectURL(source);
                resolve(null);
            };
            image.src = source;
        });
    }

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.classList || !input.classList.contains('js-photo-input') || !input.files.length) return;

        var form = input.closest('.js-photo-form');
        var holder = input.closest('.photo-edit');

        squared(input.files[0]).then(function (photo) {
            if (photo) {
                var files = new DataTransfer();
                files.items.add(photo);
                input.files = files.files;
            }

            // Shown straight away, while it's sent.
            var avatar = holder && holder.querySelector('.has-photo, [class*="avatar"]');
            if (avatar) {
                avatar.classList.add('has-photo');
                avatar.innerHTML = '';
                var preview = document.createElement('img');
                preview.src = URL.createObjectURL(input.files[0]);
                preview.alt = '';
                avatar.appendChild(preview);
            }
            if (holder) holder.classList.add('is-uploading');

            form.submit();
        });
    });
})();

// RFQ show page — the Progress chart: one card per stage, left to right —
// RFQ Created starts the row, and Sourcing splits into one lane per part,
// each lane keeping its own copy of everything after it (Data Entry,
// Finalize, Senior Operations Approval … Closed) rather than merging back.
// Each card is HTML laid over its place in the flow — its person's round
// picture (photo, initial, a lock when it's not theirs to know, or the
// stage's icon when there's nobody yet) overlapping its left edge, and the
// stage, who and when on the card — joined by curved SVG lines, coloured
// by how far the step they lead to has got. Laid out here rather than by a
// charting library: columns by depth, a row per lane, a parent centred on
// its children. Called from show.blade.php on load, and by live.js after
// it swaps in a fresh page body. Global (not inside the DOMContentLoaded
// listener above) so that late call can reach it.
window.renderRfqProgressChart = function () {
    // Called again whenever live.js swaps a fresh page body in: the previous
    // chart is put away first, and its zoom kept.
    var previous = window.rfqProgressChart || null;
    if (previous) {
        previous.dispose();
        window.rfqProgressChart = null;
    }

    var dataEl = document.getElementById('rfq-progress-data');
    var chartEl = document.getElementById('rfq-progress-chart');
    if (!dataEl || !chartEl) {
        return;
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    var SVG_NS = 'http://www.w3.org/2000/svg';
    // At 100%: a card, the round picture half over its left edge, and the
    // gaps between columns and lanes. Zoom scales every one of them, and
    // the type with it (--pc-zoom), so it stays crisp at every size.
    var BASE = { card: 220, height: 106, avatar: 56, columnGap: 56, rowGap: 24, pad: 24 };
    var STATE_LABELS = { done: 'Done', current: 'Current', pending: 'Pending', returned: 'Returned' };
    var STATE_ICONS = { done: 'bi-check-lg', current: 'bi-hourglass-split', returned: 'bi-arrow-counterclockwise' };

    var rootNode = data.tree;
    var stepNodesMap = {};
    var leaves = 0;
    var depth = 0;

    // Columns by depth (`_level`, 0 = RFQ Created), a row per lane, and a
    // parent centred between its first and last child (`_row`). Every node
    // of a step (several, once Sourcing splits) is kept by `step`, for the
    // Step Details tabs below to highlight together.
    (function place(node, level) {
        node._level = level;
        depth = Math.max(depth, level + 1);
        (stepNodesMap[node.step] = stepNodesMap[node.step] || []).push(node);

        var children = node.children || [];
        if (children.length) {
            children.forEach(function (child) { place(child, level + 1); });
            node._row = (children[0]._row + children[children.length - 1]._row) / 2;
        } else {
            node._row = leaves++;
        }
    })(rootNode, 0);

    function el(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = text;
        return element;
    }

    // The round picture: whoever did the step (or is on it) — their photo,
    // else their initial — a lock when they're not this viewer's to know,
    // or the stage's own icon when there's nobody yet. A small badge on it
    // says how far the step has got.
    function avatarFor(node) {
        var person = node.person;
        var avatar = el('span', 'pc-avatar');

        if (person && person.masked) {
            avatar.classList.add('is-icon');
            avatar.appendChild(el('i', 'bi bi-lock-fill'));
            avatar.title = 'Restricted';
        } else if (person && person.photo) {
            var img = el('img');
            img.src = person.photo;
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';
            avatar.appendChild(img);
            avatar.title = person.name;
        } else if (person) {
            avatar.classList.add('is-initial');
            avatar.textContent = person.initial;
            avatar.title = person.name;
        } else {
            avatar.classList.add('is-icon');
            avatar.appendChild(el('i', 'bi ' + (node.icon || 'bi-circle')));
        }

        if (STATE_ICONS[node.state]) {
            var badge = el('span', 'pc-badge');
            badge.appendChild(el('i', 'bi ' + STATE_ICONS[node.state]));
            avatar.appendChild(badge);
        }

        return avatar;
    }

    // The card: the stage it is (a role's kicker over a person's name on a
    // lane, or the stage's own name), how far it's got, which part, then
    // who/what and when — each its own line, cut short with … when long,
    // all of it on hover.
    function cardFor(node) {
        var state = STATE_LABELS[node.state] ? node.state : 'pending';
        var wrap = el('div', 'pc-node is-' + state);
        wrap.dataset.step = node.step || '';
        wrap.setAttribute('role', 'listitem');

        var card = el('div', 'pc-card');
        var head = el('div', 'pc-head');
        head.appendChild(el('span', 'pc-kicker', node.role || ''));
        head.appendChild(el('span', 'pc-chip', STATE_LABELS[state]));
        card.appendChild(head);
        card.appendChild(el('div', 'pc-title', node.name));

        if (node.rfq_number) {
            card.appendChild(el('div', 'pc-number', node.rfq_number));
        }

        var meta = node.meta || [];
        if (meta[0]) {
            card.appendChild(el('div', 'pc-meta', meta[0]));
        }
        if (meta[1]) {
            var when = el('div', 'pc-meta pc-when');
            when.appendChild(el('i', 'bi bi-clock'));
            when.appendChild(document.createTextNode(' ' + meta[1]));
            card.appendChild(when);
        }

        wrap.title = [node.role, node.name, node.rfq_number].concat(meta).filter(Boolean).join('\n');
        wrap.appendChild(avatarFor(node));
        wrap.appendChild(card);

        return wrap;
    }

    // Fade whichever edge still has more content to scroll to, so a card
    // sitting mid-scroll at the boundary reads as "scroll for more" instead
    // of looking cut off — only that edge, and only while it's scrollable
    // that way; recomputed on scroll and after every zoom change.
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

    // Zoom re-lays the chart bigger or smaller — every size and the type
    // together — in discrete stops, so repeated clicks land on predictable
    // sizes.
    var ZOOM_LEVELS = [0.6, 0.75, 1, 1.25, 1.5, 1.75, 2];
    var zoomIndex = previous ? previous.zoomIndex() : ZOOM_LEVELS.indexOf(1);
    var zoomInBtn = document.getElementById('rfq-progress-zoom-in');
    var zoomOutBtn = document.getElementById('rfq-progress-zoom-out');
    var zoomResetBtn = document.getElementById('rfq-progress-zoom-reset');
    var geometry = null;
    // A chart put back by a live update doesn't play its entrance again.
    var entering = !previous;

    function render() {
        var zoom = ZOOM_LEVELS[zoomIndex];
        var g = {
            card: BASE.card * zoom,
            height: BASE.height * zoom,
            avatar: BASE.avatar * zoom,
            columnGap: BASE.columnGap * zoom,
            rowGap: BASE.rowGap * zoom,
            pad: BASE.pad * zoom,
        };
        g.overhang = g.avatar / 2;
        g.node = g.card + g.overhang;
        g.pitch = g.node + g.columnGap;
        geometry = g;

        var width = g.pad * 2 + (depth - 1) * g.pitch + g.node;
        var contentHeight = g.pad * 2 + leaves * (g.height + g.rowGap) - g.rowGap;
        var height = Math.max(contentHeight, 140);
        var top = (height - contentHeight) / 2 + g.pad;
        var xOf = function (node) { return g.pad + node._level * g.pitch; };
        var yOf = function (node) { return top + node._row * (g.height + g.rowGap) + g.height / 2; };

        chartEl.innerHTML = '';
        chartEl.style.width = width + 'px';
        chartEl.style.height = height + 'px';
        chartEl.style.setProperty('--pc-zoom', zoom);
        chartEl.style.setProperty('--pc-avatar', g.avatar + 'px');
        chartEl.style.setProperty('--pc-overhang', g.overhang + 'px');
        chartEl.classList.toggle('is-entering', entering);
        chartEl.setAttribute('role', 'list');
        chartEl.setAttribute('aria-label', 'RFQ progress');

        // The lines first, under the cards: from a card's right edge into
        // the next one's picture.
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'pc-lines');
        svg.setAttribute('width', width);
        svg.setAttribute('height', height);
        svg.setAttribute('aria-hidden', 'true');
        chartEl.appendChild(svg);

        (function draw(node) {
            var card = cardFor(node);
            card.style.left = xOf(node) + 'px';
            card.style.top = (yOf(node) - g.height / 2) + 'px';
            card.style.width = g.node + 'px';
            card.style.height = g.height + 'px';
            card.style.animationDelay = (entering ? node._level * 45 : 0) + 'ms';
            node._el = card;
            chartEl.appendChild(card);

            (node.children || []).forEach(function (child) {
                var x1 = xOf(node) + g.node;
                var y1 = yOf(node);
                var x2 = xOf(child);
                var y2 = yOf(child);
                var bend = (x2 - x1) * 0.55;
                var path = document.createElementNS(SVG_NS, 'path');
                path.setAttribute('d', 'M' + x1 + ' ' + y1 + ' C' + (x1 + bend) + ' ' + y1 + ' ' + (x2 - bend) + ' ' + y2 + ' ' + x2 + ' ' + y2);
                path.setAttribute('class', 'pc-line is-' + (STATE_LABELS[child.state] ? child.state : 'pending'));
                svg.appendChild(path);
                draw(child);
            });
        })(rootNode);

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
        entering = false;
        render();
    }

    var onZoomIn = function () { setZoomIndex(zoomIndex + 1); };
    var onZoomOut = function () { setZoomIndex(zoomIndex - 1); };
    var onZoomReset = function () { setZoomIndex(ZOOM_LEVELS.indexOf(1)); };
    var onResize = function () { entering = false; render(); };

    if (zoomInBtn) zoomInBtn.addEventListener('click', onZoomIn);
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', onZoomOut);
    if (zoomResetBtn) zoomResetBtn.addEventListener('click', onZoomReset);

    render();
    window.addEventListener('resize', onResize);
    if (wrapEl) {
        wrapEl.addEventListener('scroll', updateEdgeFade);
    }

    // Selecting a Step Details tab (below the chart) briefly highlights every
    // card for that stage — a bright ring and a slight lift — and scrolls its
    // column into view, so the tab and its place in the flow visibly connect;
    // most useful for Sourcing and Data Entry, whose tab covers a card per lane.
    var highlightTimer = null;

    function scrollToStep(step) {
        if (!wrapEl || !geometry) {
            return;
        }
        var maxLevel = stepNodesMap[step].reduce(function (max, node) {
            return Math.max(max, node._level);
        }, 0);
        var columnCenter = geometry.pad + maxLevel * geometry.pitch + geometry.node / 2;
        var maxScroll = wrapEl.scrollWidth - wrapEl.clientWidth;
        wrapEl.scrollTo({ left: Math.max(0, Math.min(maxScroll, columnCenter - wrapEl.clientWidth / 2)), behavior: 'smooth' });
    }

    function highlightStep(step) {
        if (!stepNodesMap[step]) {
            return;
        }
        clearTimeout(highlightTimer);
        chartEl.querySelectorAll('.pc-node.is-highlighted').forEach(function (card) {
            card.classList.remove('is-highlighted');
        });
        stepNodesMap[step].forEach(function (node) {
            if (node._el) node._el.classList.add('is-highlighted');
        });
        scrollToStep(step);
        highlightTimer = setTimeout(function () {
            stepNodesMap[step].forEach(function (node) {
                if (node._el) node._el.classList.remove('is-highlighted');
            });
        }, 1400);
    }

    var tabListeners = [];
    Object.keys(stepNodesMap).forEach(function (step) {
        var tabBtn = document.getElementById('step-tab-' + step);
        if (tabBtn) {
            var onShown = function () { highlightStep(step); };
            tabBtn.addEventListener('shown.bs.tab', onShown);
            tabListeners.push([tabBtn, onShown]);
        }
    });

    window.rfqProgressChart = {
        zoomIndex: function () {
            return zoomIndex;
        },
        dispose: function () {
            clearTimeout(highlightTimer);
            window.removeEventListener('resize', onResize);
            if (zoomInBtn) zoomInBtn.removeEventListener('click', onZoomIn);
            if (zoomOutBtn) zoomOutBtn.removeEventListener('click', onZoomOut);
            if (zoomResetBtn) zoomResetBtn.removeEventListener('click', onZoomReset);
            if (wrapEl) wrapEl.removeEventListener('scroll', updateEdgeFade);
            tabListeners.forEach(function (pair) { pair[0].removeEventListener('shown.bs.tab', pair[1]); });
            chartEl.innerHTML = '';
        },
    };
};

// The Time Spent report: hovering (or focusing) a role's time shows how it
// came about — reports/_time_breakdown.blade.php, kept in a <template> — as
// a popover that stays while the pointer is on it, so a long one can be
// read; Escape closes it. A live update swaps the table, so any open one is
// cleared and the new figures bound again.
(function () {
    var HIDE_DELAY = 200;
    var open = [];

    function attach(trigger) {
        if (trigger.dataset.breakdownBound || !window.bootstrap || !bootstrap.Popover) return;
        var template = document.getElementById(trigger.dataset.timeBreakdown);
        if (!template) return;
        trigger.dataset.breakdownBound = '1';

        var popover = new bootstrap.Popover(trigger, {
            trigger: 'manual',
            html: true,
            placement: 'auto',
            container: 'body',
            customClass: 'time-breakdown-popover',
            content: function () { return template.innerHTML; },
        });
        var hideTimer = null;

        function hideSoon() {
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(function () { popover.hide(); }, HIDE_DELAY);
        }

        function show() {
            window.clearTimeout(hideTimer);
            open.forEach(function (other) { if (other !== popover) other.hide(); });
            popover.show();
            if (open.indexOf(popover) === -1) open.push(popover);

            var tip = popover.tip;
            if (tip && !tip.dataset.breakdownBound) {
                tip.dataset.breakdownBound = '1';
                tip.addEventListener('mouseenter', function () { window.clearTimeout(hideTimer); });
                tip.addEventListener('mouseleave', hideSoon);
            }
        }

        trigger.addEventListener('mouseenter', show);
        trigger.addEventListener('mouseleave', hideSoon);
        trigger.addEventListener('focus', show);
        trigger.addEventListener('blur', hideSoon);
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') popover.hide();
        });
    }

    function init() {
        open = open.filter(function (popover) {
            if (popover._element && popover._element.isConnected) return true;
            popover.dispose();
            return false;
        });
        document.querySelectorAll('.time-breakdown-popover').forEach(function (tip) {
            if (!open.some(function (popover) { return popover.tip === tip; })) tip.remove();
        });
        document.querySelectorAll('[data-time-breakdown]').forEach(attach);
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('rfqms:live-updated', init);
})();
