{{--
    Hidden fields that bring a form's redirect back to the list it was
    submitted from — the status filter and, on Admin's per-role pages, the
    role and sub-view being looked at. See RfqController::redirectToIndex().

    Optional: $statusFilter, $lensRole (both from RfqController::index()).
--}}
@if ($statusFilter ?? null)
    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
@endif
@if ($lensRole ?? null)
    <input type="hidden" name="redirect_role" value="{{ \Illuminate\Support\Str::slug($lensRole) }}">
    @if (in_array(request('view'), \App\Models\Rfq::QUEUE_VIEWS, true))
        <input type="hidden" name="redirect_view" value="{{ request('view') }}">
    @endif
@endif
