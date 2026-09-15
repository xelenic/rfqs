{{--
    $mode: 'create' | 'edit'. $idPrefix: unique DOM id/error-bag prefix so the
    two modals (create + edit) never collide, since both include this partial
    on the same page. Fields are populated client-side (admin.js) when opening
    the edit modal, or by old() — scoped to whichever modal actually failed
    validation — when redisplaying errors.
--}}
@php
    $showOld = old('rfq_id') ? $idPrefix === 'edit' : ($errors->create->any() && $idPrefix === 'create');
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label for="{{ $idPrefix }}-wc_number" class="form-label">WC Number</label>
        <input type="text" name="wc_number" id="{{ $idPrefix }}-wc_number" class="form-control @error('wc_number', $idPrefix) is-invalid @enderror"
               value="{{ $showOld ? old('wc_number') : '' }}" required>
        @error('wc_number', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
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
            @foreach ($priorities as $priority)
                <option value="{{ $priority }}" {{ ($showOld ? old('priority_level', 'Medium') : 'Medium') === $priority ? 'selected' : '' }}>
                    {{ $priority }}
                </option>
            @endforeach
        </select>
        @error('priority_level', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-status" class="form-label">Status</label>
        <select name="status" id="{{ $idPrefix }}-status" class="form-select @error('status', $idPrefix) is-invalid @enderror" required>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" {{ ($showOld ? old('status', $defaultStatus ?? 'Pending') : ($defaultStatus ?? 'Pending')) === $status ? 'selected' : '' }}>
                    {{ $status === 'Completed' ? 'Closed' : $status }}
                </option>
            @endforeach
        </select>
        @error('status', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

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
