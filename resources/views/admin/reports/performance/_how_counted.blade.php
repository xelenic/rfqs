{{--
    How a performance report's figures are worked out (App\PerformanceReports),
    under it.

    Expects: $points (sentences).
--}}
<div class="card report-how">
    <div class="card-body small">
        <div class="fw-semibold mb-1"><i class="bi bi-info-circle"></i> How it's counted</div>
        <ul class="mb-0 text-muted-soft">
            @foreach ($points as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>
    </div>
</div>
