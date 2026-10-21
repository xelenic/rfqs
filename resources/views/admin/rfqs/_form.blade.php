{{--
    $mode: 'create' | 'edit'. $idPrefix: unique DOM id/error-bag prefix so the
    two modals (create + edit) never collide, since both include this partial
    on the same page. Fields are populated client-side (admin.js) when opening
    the edit modal, or by old() — scoped to whichever modal actually failed
    validation — when redisplaying errors.

    Status is edit-only: a new RFQ is always Pending (RfqController::store()),
    so creating has nothing to choose. Expects $statuses in edit mode.
--}}
@php
    $showOld = old('rfq_id') ? $idPrefix === 'edit' : ($errors->create->any() && $idPrefix === 'create');
@endphp

<div class="row g-3">
    <div class="col-md-6">
        {{-- In the WC0000 format while Settings has it on (Setting::wcFormatRequired()).
             Adding one, WC is fixed in front and only its four digits are typed
             (digits alone, admin.js .js-wc-digits) — saved as WC0123
             (RfqController::wcNumberChecked()). An edit has the whole number,
             checked only if it's changed, so an older RFQ's number can stay. --}}
        @php $wcFormatRequired = \App\Models\Setting::wcFormatRequired(); @endphp
        <label for="{{ $idPrefix }}-wc_number" class="form-label">WC Number</label>
        @if ($wcFormatRequired && $mode === 'create')
            <div class="input-group has-validation">
                <span class="input-group-text fw-semibold">WC</span>
                <input type="text" name="wc_number" id="{{ $idPrefix }}-wc_number" class="form-control js-wc-digits @error('wc_number', $idPrefix) is-invalid @enderror"
                       value="{{ $showOld ? preg_replace('/^\s*wc/i', '', (string) old('wc_number')) : '' }}" required
                       placeholder="0000" inputmode="numeric" pattern="[0-9]{4}" autocomplete="off"
                       title="The WC number's four digits, like 0123" aria-describedby="{{ $idPrefix }}-wc_number-hint">
                @error('wc_number', $idPrefix)
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-text" id="{{ $idPrefix }}-wc_number-hint">Type the four digits — it's saved as WC0123.</div>
        @else
            <input type="text" name="wc_number" id="{{ $idPrefix }}-wc_number" class="form-control @error('wc_number', $idPrefix) is-invalid @enderror"
                   value="{{ $showOld ? old('wc_number') : '' }}" required
                   @if ($wcFormatRequired) placeholder="WC0000" autocapitalize="characters" @endif>
            @error('wc_number', $idPrefix)
                <div class="invalid-feedback">{{ $message }}</div>
            @else
                @if ($wcFormatRequired)
                    <div class="form-text">WC and four digits, like WC0123.</div>
                @endif
            @enderror
        @endif
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-rfq_number" class="form-label">RFQ Number</label>
        @if ($mode === 'create')
            <input type="text" id="{{ $idPrefix }}-rfq_number" class="form-control" value="{{ $nextRfqNumber ?? '' }}" disabled readonly>
            <div class="form-text">Auto-generated when the RFQ is created.</div>
        @else
            <input type="text" name="rfq_number" id="{{ $idPrefix }}-rfq_number" class="form-control @error('rfq_number', $idPrefix) is-invalid @enderror"
                   value="{{ $showOld ? old('rfq_number') : '' }}" required>
            @error('rfq_number', $idPrefix)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        @endif
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-priority_level" class="form-label">Priority level</label>
        <select name="priority_level" id="{{ $idPrefix }}-priority_level" class="form-select @error('priority_level', $idPrefix) is-invalid @enderror" required>
            {{-- P1 Critical first, down to P7 Trivial — each with its colour, which an option can't be drawn in. --}}
            @foreach (array_reverse($priorities) as $priority)
                <option value="{{ $priority }}" {{ ($showOld ? old('priority_level', 'Medium') : 'Medium') === $priority ? 'selected' : '' }}>
                    {{ \App\Models\Rfq::PRIORITY_SYMBOLS[$priority] ?? '' }} {{ \App\Models\Rfq::priorityLabelOf($priority) }}
                </option>
            @endforeach
        </select>
        @error('priority_level', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-number_of_items" class="form-label">Number of items</label>
        <input type="number" name="number_of_items" id="{{ $idPrefix }}-number_of_items" class="form-control @error('number_of_items', $idPrefix) is-invalid @enderror"
               value="{{ $showOld ? old('number_of_items') : '' }}" min="1" max="100000" step="1" inputmode="numeric" required>
        @error('number_of_items', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @else
            <div class="form-text">Please add the number of items for this RFQ.</div>
        @enderror
    </div>

    @if ($mode === 'edit')
        {{-- Only an Admin changes the status from here (RfqController::update()):
             Senior Operations stops and resumes one from the RFQ's own page,
             and Business Development only opens this while fixing an RFQ sent
             back to them — always Pending. Whatever they send, it's kept as it
             is, so there's nothing to choose: a hidden field instead of the
             picker. --}}
        @unless (auth()->user()->hasRole('Admin'))
            <input type="hidden" name="status" value="Pending">
        @else
            <div class="col-md-6">
                <label for="{{ $idPrefix }}-status" class="form-label">Status</label>
                <select name="status" id="{{ $idPrefix }}-status" class="form-select @error('status', $idPrefix) is-invalid @enderror" required>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" {{ ($showOld ? old('status', 'Pending') : 'Pending') === $status ? 'selected' : '' }}>
                            {{ $status === 'Completed' ? 'Closed' : $status }}
                        </option>
                    @endforeach
                    {{-- Shown for an RFQ Senior Operations has stopped, but not
                         choosable — it's resumed from the RFQ's own page, and
                         an edit leaves it as it is (RfqController::update()). --}}
                    @foreach ([\App\Models\Rfq::ON_HOLD, \App\Models\Rfq::CANCELLED] as $stoppedStatus)
                        <option value="{{ $stoppedStatus }}" disabled>{{ $stoppedStatus }} — change it from the RFQ's page</option>
                    @endforeach
                </select>
                @error('status', $idPrefix)
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        @endunless
    @endif

    <div class="col-12">
        <label for="{{ $idPrefix }}-subject" class="form-label">Subject</label>
        <input type="text" name="subject" id="{{ $idPrefix }}-subject" class="form-control @error('subject', $idPrefix) is-invalid @enderror"
               value="{{ $showOld ? old('subject') : '' }}" required>
        @error('subject', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="{{ $idPrefix }}-description" class="form-label">
            {{ $mode === 'create' ? 'Note' : 'Description' }} <span class="text-muted-soft fw-normal">(optional)</span>
        </label>
        <textarea name="description" id="{{ $idPrefix }}-description" rows="3" class="form-control @error('description', $idPrefix) is-invalid @enderror">{{ $showOld ? old('description') : '' }}</textarea>
        @error('description', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
