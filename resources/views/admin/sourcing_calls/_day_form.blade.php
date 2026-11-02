{{--
    A Sourcing member's supplier calls for one day, for the Sourcing Summary
    report: today's unless they pick an earlier day. On their dashboard and
    their Daily Summary. See SourcingDayFigureController::updateOwnCalls().

    Expects: $callsDay ("Y-m-d"), $callsFigure (the day's SourcingDayFigure, or
    null).
--}}
<div class="card mb-4" id="supplier-calls">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-telephone"></i> Your supplier calls</span>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            @unless (request()->routeIs('admin.sourcing-calls.index'))
                <a href="{{ route('admin.sourcing-calls.index') }}" class="small fw-semibold">All your days <i class="bi bi-arrow-right"></i></a>
            @endunless
            <form method="GET" class="d-flex align-items-center gap-2">
                <label for="calls-date" class="small text-muted-soft mb-0">Day</label>
                <input type="date" name="calls_date" id="calls-date" value="{{ $callsDay }}" max="{{ \App\Models\Setting::today() }}" class="form-control form-control-sm" onchange="this.form.submit()">
            </form>
        </div>
    </div>
    <form method="POST" action="{{ route('admin.sourcing-calls.update') }}" class="card-body">
        @csrf
        @method('PATCH')
        <input type="hidden" name="day" value="{{ $callsDay }}">
        <div class="row g-3 align-items-end">
            @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callField => $callLabel)
                <div class="col-sm-6 col-lg-3">
                    <label for="calls-{{ $callField }}" class="form-label small">{{ $callLabel }}</label>
                    <input type="number" name="{{ $callField }}" id="calls-{{ $callField }}" min="0" max="{{ \App\Models\SourcingDayFigure::MAX_FIGURE }}" step="1" inputmode="numeric"
                           value="{{ old($callField, $callsFigure?->{$callField}) }}" class="form-control @error($callField, 'sourcingCalls') is-invalid @enderror">
                    @error($callField, 'sourcingCalls')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endforeach
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2"></i> Save calls</button>
            </div>
        </div>
        @error('day', 'sourcingCalls')
            <div class="text-danger small mt-2" role="alert">{{ $message }}</div>
        @enderror
    </form>
</div>
