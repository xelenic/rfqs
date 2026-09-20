{{--
    The fireworks that go off when Business Development closes something — a
    part, or a whole RFQ (a bigger show for that). Only there for the one page
    load after a close: RfqController::close() and closePart() flash
    `celebrate`. The card is the message; public/js/fireworks.js paints the
    fireworks and confetti over it, and takes it all away again after a few
    seconds, on a click or Escape. For anyone who's asked their system for
    less motion it's the card alone.

    Expects: session('celebrate') — title, label, message, grand, url.
--}}
@if ($celebration = session('celebrate'))
    <div class="celebration" id="celebration" data-grand="{{ $celebration['grand'] ? '1' : '0' }}" role="status" aria-live="polite">
        <canvas class="celebration-canvas" aria-hidden="true"></canvas>
        <div class="celebration-confetti-layer" aria-hidden="true"></div>

        <div class="celebration-card">
            <span class="celebration-sparkle" style="--x: -3.4rem; --y: 0.2rem; --d: 0.9s" aria-hidden="true"></span>
            <span class="celebration-sparkle" style="--x: 3.1rem; --y: -0.4rem; --d: 1.3s" aria-hidden="true"></span>
            <span class="celebration-sparkle" style="--x: -2.4rem; --y: 3.2rem; --d: 1.7s" aria-hidden="true"></span>
            <span class="celebration-sparkle" style="--x: 2.6rem; --y: 3rem; --d: 1.1s" aria-hidden="true"></span>

            <div class="celebration-badge">
                <svg class="celebration-check" viewBox="0 0 52 52" aria-hidden="true">
                    <circle class="celebration-check-ring" cx="26" cy="26" r="23" fill="none" />
                    <path class="celebration-check-mark" fill="none" d="M14 27l8 8 16-17" />
                </svg>
            </div>

            <h2 class="celebration-title">{{ $celebration['title'] }}</h2>
            <div class="celebration-label">{{ $celebration['label'] }}</div>
            <p class="celebration-text">{{ $celebration['message'] }}</p>

            <div class="celebration-actions">
                <a href="{{ $celebration['url'] }}" class="btn btn-sm btn-success">
                    <i class="bi bi-check2-circle"></i> View Closed RFQs
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary js-celebration-dismiss">Keep going</button>
            </div>

            <span class="celebration-bar" aria-hidden="true"></span>
        </div>
    </div>
    <script src="{{ asset('js/fireworks.js') }}"></script>
@endif
