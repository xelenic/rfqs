{{--
    One group chat (MessageGroupController::show()): its name, what it's for
    and who's in it, what's been said, oldest first — whose, with their role,
    and when — and the box to say something to everyone in it. An Admin can
    go from here to change it (?edit= on Groups).

    Expects: $group (with members.roles), $messages (with sender.roles),
    $isAdmin.
--}}
@extends('layouts.app')

@section('title', $group->name)

@section('content')
    <div class="mb-3">
        <a href="{{ route('admin.messages.groups') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> All groups
        </a>
    </div>

    <div class="card message-thread">
        <div class="card-header d-flex align-items-center gap-3 flex-wrap">
            <span class="assignee-avatar conversation-avatar rfq-thread-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
            <div style="min-width: 0;">
                <div class="fw-bold">{{ $group->name }}</div>
                <div class="text-muted-soft small fw-normal">{{ $group->description ?: $group->members->count().' people' }}</div>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#groupMembers" aria-expanded="false" aria-controls="groupMembers">
                    <span class="assignee-cluster">
                        @foreach ($group->members->take(3) as $member)
                            <x-avatar :user="$member" class="assignee-avatar" />
                        @endforeach
                    </span>
                    {{ $group->members->count() }} {{ $group->members->count() === 1 ? 'person' : 'people' }}
                </button>
                @if ($isAdmin)
                    <a href="{{ route('admin.messages.groups', ['edit' => $group->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit group</a>
                @endif
            </div>
            <div class="collapse w-100" id="groupMembers">
                <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0 pt-2" aria-label="Who's in it">
                    @foreach ($group->members as $member)
                        <li class="group-member" data-group-member="{{ $member->id }}">
                            <x-avatar :user="$member" class="assignee-avatar" />
                            <span>{{ $member->name }}{{ $member->is(auth()->user()) ? ' (you)' : '' }}</span>
                            <span class="text-muted-soft small">{{ $member->roles->pluck('name')->sort()->implode(', ') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="card-body message-thread-body" id="messageThread" data-live-scroll="group-thread">
            @forelse ($messages as $message)
                @php $mine = $message->user_id === auth()->id(); @endphp
                <div class="message-bubble-row {{ $mine ? 'is-mine' : '' }}" data-group-message="{{ $message->id }}">
                    <div class="message-bubble">
                        <div class="rfq-thread-author">
                            @if ($mine)
                                <span class="fw-semibold">You</span>
                            @elseif ($message->sender)
                                <span class="fw-semibold">{{ $message->sender->name }}</span>
                                <span class="text-muted-soft small">{{ $message->sender->roles->pluck('name')->sort()->implode(', ') }}</span>
                            @else
                                <span class="fw-semibold">Deleted user</span>
                            @endif
                        </div>
                        <p class="mb-1" style="white-space: pre-line;">{{ $message->body }}</p>
                        <div class="message-time">{{ $message->created_at->toBusinessTimezone()->format('M d, g:i A') }}</div>
                    </div>
                </div>
            @empty
                <p class="text-muted-soft text-center my-4">Nothing said in {{ $group->name }} yet — say hello.</p>
            @endforelse
        </div>

        <div class="card-footer bg-white">
            <form method="POST" action="{{ route('admin.messages.groups.send', $group) }}" novalidate>
                @csrf
                <textarea name="body" rows="2" maxlength="{{ \App\Models\PrivateMessage::MAX_LENGTH }}"
                          class="form-control @error('body') is-invalid @enderror"
                          placeholder="Write to everyone in {{ $group->name }}…">{{ old('body') }}</textarea>
                @error('body')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="text-muted-soft small"><i class="bi bi-people"></i> Everyone in the group reads it.</span>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Send</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Start at the latest message.
        var thread = document.getElementById('messageThread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    </script>
@endpush
