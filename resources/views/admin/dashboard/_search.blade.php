{{--
    The WC number search on every dashboard, for those who can open RFQs: as
    it's typed, the RFQs whose WC number has it are suggested beneath
    (RfqController::suggest(), public/js/wc-search.js) — Up/Down to move
    through them, Enter to open one, Escape to close; "/" anywhere puts the
    cursor here. Sent as it stands, it opens the RFQ list searched for it.
--}}
<form method="GET" action="{{ route('admin.rfqs.index') }}" class="dash-search" role="search" id="wcSearchForm"
      data-suggest-url="{{ route('admin.rfqs.suggest') }}">
    <i class="bi bi-search dash-search-icon" aria-hidden="true"></i>
    <input type="search" name="search" id="wcSearch" class="form-control" placeholder="Search WC number…" autocomplete="off" spellcheck="false"
           role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="wcSearchList" aria-label="Search RFQs by WC number">
    <kbd class="dash-search-key" aria-hidden="true">/</kbd>
    <div class="dash-search-menu d-none" id="wcSearchList" role="listbox" aria-label="RFQs with this WC number"></div>
</form>

@push('scripts')
    <script src="{{ asset('js/wc-search.js') }}?v={{ filemtime(public_path('js/wc-search.js')) }}"></script>
@endpush
