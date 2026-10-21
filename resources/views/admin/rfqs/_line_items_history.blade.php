{{--
    A part's line items as they've gone (Rfq::lineItemsHistory()), oldest
    first, inside a line items popup's part (_senior_ops_approve_modal,
    _head_of_bd_approve_modal): every count Sourcing gave to add and Data
    Entry added — against what was asked, and what came before — every
    stretch either worked on it, from when to when and for how long, each
    return either way with why, and Senior Operations' verified count —
    each with who, and in which role. admin.js fills it in from the button's
    data-parts (history).
--}}
<details class="line-items-history" open>
    <summary>
        <i class="bi bi-clock-history" aria-hidden="true"></i> Line items history
        <span class="line-items-history-count js-li-history-count"></span>
    </summary>
    <ol class="line-items-history-list js-li-history"></ol>
    <p class="text-muted-soft small mb-0 d-none js-li-history-empty">Nothing recorded for this part yet.</p>
</details>
