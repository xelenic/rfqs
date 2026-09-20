@extends('layouts.app')

@section('title', $other->name)

@section('content')
    <div class="mb-3">
        <a href="{{ route('admin.messages.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> All messages
        </a>
    </div>

    <div class="card message-thread">
        <div class="card-header d-flex align-items-center gap-3">
            <span class="assignee-avatar conversation-avatar">{{ strtoupper(substr($other->name, 0, 1)) }}</span>
            <div>
                <div class="fw-bold">{{ $other->name }}
                    @foreach ($other->roles as $role)
                        <span class="badge badge-soft-secondary rfq-comment-role">{{ $role->name }}</span>
                    @endforeach
                </div>
                <div class="text-muted-soft small fw-normal">{{ $other->email }}</div>
            </div>
            <span class="ms-auto text-muted-soft small fw-normal"><i class="bi bi-lock"></i> Private</span>
        </div>

        <div class="card-body message-thread-body" id="messageThread">
            @forelse ($messages as $message)
                @php $mine = $message->sender_id === auth()->id(); @endphp
                <div class="message-bubble-row {{ $mine ? 'is-mine' : '' }}">
                    <div class="message-bubble">
                        <p class="mb-1" style="white-space: pre-line;">{{ $message->body }}</p>
                        <div class="message-time">{{ $message->created_at->format('M d, g:i A') }}</div>
                    </div>
                </div>
            @empty
                <p class="text-muted-soft text-center my-4">No messages with {{ $other->name }} yet — say hello.</p>
            @endforelse
        </div>

        <div class="card-footer bg-white">
            <form method="POST" action="{{ route('admin.messages.store') }}" novalidate>
                @csrf
                <input type="hidden" name="recipient_id" value="{{ $other->id }}">
                <textarea name="body" rows="2" maxlength="{{ \App\Models\PrivateMessage::MAX_LENGTH }}"
                          class="form-control @error('body') is-invalid @enderror"
                          placeholder="Write a private message to {{ $other->name }}…">{{ old('body') }}</textarea>
                @error('body')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="text-muted-soft small"><i class="bi bi-lock"></i> Only you and {{ $other->name }} can read this.</span>
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
