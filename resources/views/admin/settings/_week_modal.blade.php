{{--
    A window to set a role's working week, or one person's, in place of the
    company's (WorkingSchedule): the week to fill in (_week_table), starting
    from the hours they work now. Saving counts from today — days before keep
    the hours they had. Admin only.

    Expects: $modalId, $title, $subtitle, $action (a PUT route), $week, $bag,
    $refill (this window's form is the one that failed), $idPrefix, and
    $hidden (name => value, sent along — what to reopen it by if it fails).
--}}
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}-label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ $action }}" novalidate>
                @csrf
                @method('PUT')
                @foreach ($hidden as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="{{ $modalId }}-label">{{ $title }}</h5>
                        <div class="text-muted-soft small">{{ $subtitle }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    @include('admin.settings._week_table', ['week' => $week, 'bag' => $bag, 'refill' => $refill, 'idPrefix' => $idPrefix])
                </div>
                <div class="modal-footer">
                    <span class="form-text me-auto"><i class="bi bi-info-circle"></i> Counts from today — days before keep the hours they had.</span>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save hours</button>
                </div>
            </form>
        </div>
    </div>
</div>
