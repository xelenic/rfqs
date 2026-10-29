{{--
    A status badge that says, on hovering (or focusing) it, why the RFQ — or
    one part of it — is still pending, as its Sourcing members last said
    (Rfq::pendingReasons()): marked with a speech bubble, and a popup (admin.js,
    [data-hover-card]) with each part's reason, who gave it, their role and
    when. A viewer who mustn't see who's assigned ($hideNames — Business
    Development) gets the role without the name or picture. The popup is kept
    in a <template>, so it's only drawn on hover. With no reason to show, the
    plain badge.

    Expects: $rfq, $id (the popup's id, unique on the page), $reasons
    (Rfq::pendingReasons(), or one part's), $badgeClass, $label.
    Optional: $hideNames.
--}}
@if ($reasons->isEmpty())
    <span class="badge {{ $badgeClass }}">{{ $label }}</span>
@else
    <span class="pending-reason-trigger" tabindex="0" role="button" data-hover-card="{{ $id }}" aria-label="{{ $label }} — why it's pending">
        <span class="badge {{ $badgeClass }}">{{ $label }} <i class="bi bi-chat-left-text" aria-hidden="true"></i></span>
    </span>
    <template id="{{ $id }}">
        <div class="tb">
            <div class="tb-head">
                <strong>Why it's pending</strong>
                <span class="tb-meta">{{ $rfq->rfq_number }}</span>
            </div>

            <ul class="tb-stretches">
                @foreach ($reasons as $pending)
                    @php $roleNames = $pending['by']?->roles->pluck('name')->implode(', '); @endphp
                    <li>
                        @if ($rfq->isSplit())
                            <div class="tb-stretch-head"><strong>{{ $pending['label'] }}</strong></div>
                        @endif
                        <div class="pending-reason-text">{{ $pending['reason'] }}</div>
                        <div class="pending-reason-by">
                            @unless ($hideNames ?? false)
                                <x-avatar :user="$pending['by']" class="assignee-avatar" />
                                <strong>{{ $pending['by']?->name ?? 'Deleted user' }}</strong>
                            @endunless
                            @if (filled($roleNames))
                                <span class="badge badge-soft-secondary">{{ $roleNames }}</span>
                            @endif
                        </div>
                        @if ($pending['at'])
                            <div class="tb-meta">{{ $pending['at']->toBusinessTimezone()->format('M d, Y g:i A') }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </template>
@endif
