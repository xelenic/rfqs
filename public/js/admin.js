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
                title: 'Mark complete', icon: 'bi-check2-circle', field: 'comment', label: 'Comment', max: 2000,
                submit: 'Mark Complete', submitClass: 'btn-success', missing: 'Add a comment to mark this part complete.',
            },
            'return': {
                title: 'Return to Sourcing', icon: 'bi-arrow-counterclockwise', field: 'reason', label: 'Reason', max: 1000,
                submit: 'Return to Sourcing', submitClass: 'btn-danger', missing: 'Add a reason to send this part back.',
            },
        };

        var form = document.getElementById('completeForm');
        var comment = document.getElementById('complete-comment');
        var count = document.getElementById('complete-count');
        var error = document.getElementById('complete-error');
        var cancel = document.getElementById('complete-cancel');
        var submit = document.getElementById('complete-submit');
        var kind = KINDS.complete;

        function showError(message) {
            error.textContent = message;
            error.classList.toggle('d-none', !message);
            comment.classList.toggle('is-invalid', !!message);
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

            document.getElementById('completeModalLabel').textContent = kind.title;
            document.getElementById('complete-subtitle').textContent =
                (button.dataset.who ? button.dataset.who + '\'s part \u00b7 ' : '') + button.dataset.rfqNumber + ' \u2014 ' + button.dataset.subject;
            document.getElementById('complete-lead-icon').className = 'bi ' + (kind.field === 'reason' ? kind.icon : 'bi-chat-left-text');
            document.getElementById('complete-lead-title').textContent = button.dataset.heading;
            document.getElementById('complete-hint').textContent = button.dataset.hint;
            document.getElementById('complete-label').textContent = kind.label;
            document.getElementById('complete-audience').textContent = button.dataset.audience;

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
            comment.focus();
        });

        comment.addEventListener('input', function () {
            count.textContent = comment.value.length + ' / ' + comment.maxLength;
            if (comment.value.trim()) showError('');
        });

        form.addEventListener('submit', function (event) {
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

            els.avatar.textContent = (data.name || '?').charAt(0).toUpperCase();
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

        document.querySelectorAll('[data-ops-tab]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                tabInput.value = tab.dataset.opsTab;
            });
        });

        limitCustomDates();

        // A live update puts back the tab you were on without firing its
        // event, so pick it up from what's showing.
        var activeTab = document.querySelector('[data-ops-tab].active');
        if (activeTab) tabInput.value = activeTab.dataset.opsTab;
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

    // GM Assistant's Client Details / Payment Terms modal — same
    // shared-modal-populated-per-row pattern as the Reject modal above: which
    // RFQ, and which part of it when it's one part going on (none for a whole
    // RFQ), with what's already been given for the RFQ to confirm or adjust.
    document.querySelectorAll('.js-gm-assistant-rfq').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById('gmAssistantForm');
            if (!form) return;

            var isPart = !!button.dataset.part;
            form.action = button.dataset.action;
            form.querySelector('[name="gm_assistant_rfq_id"]').value = button.dataset.rfqId || '';
            form.querySelector('[name="part"]').value = button.dataset.part || '';
            form.querySelector('[name="gm_assistant_label"]').value = button.dataset.label || '';
            form.querySelector('[name="client_details"]').value = button.dataset.clientDetails || '';
            form.querySelector('[name="payment_terms"]').value = button.dataset.paymentTerms || '';
            document.getElementById('gmAssistantTarget').textContent = button.dataset.label || '';
            document.getElementById('gmAssistantWholeNote').classList.toggle('d-none', !isPart);
            form.dataset.confirm = isPart
                ? 'Forward this part to the General Manager?'
                : 'Forward this RFQ to the General Manager?';
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
}

document.addEventListener('DOMContentLoaded', rfqmsBoot);
window.rfqmsRebind = rfqmsBoot;

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
    // Called again whenever live.js swaps a fresh page body in: the previous
    // chart is put away first, and its zoom kept.
    var previous = window.rfqProgressChart || null;
    if (previous) {
        previous.dispose();
        window.rfqProgressChart = null;
    }

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
    var zoomIndex = previous ? previous.zoomIndex() : ZOOM_LEVELS.indexOf(1);
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
            // A chart put back by a live update shouldn't play its entrance again.
            animation: !previous,
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

    window.rfqProgressChart = {
        zoomIndex: function () {
            return zoomIndex;
        },
        dispose: function () {
            window.removeEventListener('resize', render);
            chart.dispose();
        },
    };

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
