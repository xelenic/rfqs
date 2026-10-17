{{--
    The Head of Business Development's dashboard — see _approver.blade.php, which it shares
    with the General Manager. Live: it follows changes as they happen
    (live.js), the charts redrawn after each.

    Expects: $stage, $period, $overview, $rfqActivity, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @include('admin.dashboard._approver')
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
    <script src="{{ asset('js/admin-dashboard.js') }}?v={{ filemtime(public_path('js/admin-dashboard.js')) }}"></script>
@endpush
