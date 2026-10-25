{{--
    Messages → Groups (MessageGroupController::index()): the group chats the
    signed-in user is in — for an Admin, every group — the latest said
    first: its name, what it's for, who's in it, the last thing said and
    how many are unread for them, leading to its chat. An Admin sets one up
    (#newGroupModal), changes one (#editGroupModal-{id} — opened from its
    Edit, or by ?edit={id} from its chat) or deletes it; a group they're not
    in is listed for them to manage, its chat left unread. A refused save
    reopens its popup as it was.

    Expects: $groups (with members, members_count, last_message_id), $mine
    (ids of the groups they're in), $lastMessages (id => GroupMessage),
    $unread (group id => count), $isAdmin, $people (everyone active, for an
    Admin).
--}}
@extends('layouts.app')

@section('title', 'Groups')

@section('content')
    @php
        $failedCreate = $errors->createGroup->any();
        $failedEditId = $errors->editGroup->any() ? (int) old('group_id') : null;
        $openEditId = $failedEditId ?: ($isAdmin ? (int) request('edit') : null);
        $peopleButMe = $people->reject(fn ($person) => $person->is(auth()->user()));
    @endphp

    @include('admin.messages._tabs', ['tab' => 'groups'])

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Groups</span>
            <span class="d-flex align-items-center gap-3">
                <span class="text-muted-soft small fw-normal">Only the people in a group can read it</span>
                @if ($isAdmin)
                    <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#newGroupModal">
                        <i class="bi bi-people"></i> New group
                    </button>
                @endif
            </span>
        </div>

        @forelse ($groups as $group)
            @php
                $isMember = in_array($group->id, $mine, true);
                $last = $isMember ? $lastMessages->get($group->last_message_id) : null;
                $unreadCount = $unread[$group->id] ?? 0;
            @endphp
            <div class="conversation-row position-relative {{ $unreadCount > 0 ? 'is-unread' : '' }}" data-group="{{ $group->id }}">
                <span class="assignee-avatar conversation-avatar rfq-thread-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                <span class="conversation-body">
                    <span class="conversation-top">
                        @if ($isMember)
                            <a href="{{ route('admin.messages.group', $group) }}" class="conversation-name stretched-link text-reset text-decoration-none">{{ $group->name }}</a>
                        @else
                            <span class="conversation-name">{{ $group->name }}</span>
                            <span class="badge badge-soft-secondary">You're not in it</span>
                        @endif
                        <span class="text-muted-soft small">{{ $group->members_count }} {{ $group->members_count === 1 ? 'person' : 'people' }}</span>
                    </span>
                    <span class="conversation-preview">
                        @if ($last)
                            <span class="text-muted-soft">{{ $last->user_id === auth()->id() ? 'You' : ($last->sender?->name ?? 'Someone') }}:</span>
                            {{ \Illuminate\Support\Str::limit($last->body, 110) }}
                        @else
                            <span class="text-muted-soft">{{ $group->description ?: $group->members->pluck('name')->join(', ') }}</span>
                        @endif
                    </span>
                </span>
                <span class="conversation-meta">
                    @if ($last)
                        <span class="text-muted-soft small">{{ $last->created_at->toBusinessTimezone()->format('M d, Y g:i A') }}</span>
                    @endif
                    <span class="d-flex align-items-center gap-2 position-relative" style="z-index: 2;">
                        @if ($unreadCount > 0)
                            <span class="nav-link-count" title="{{ $unreadCount }} unread">{{ $unreadCount }}</span>
                        @endif
                        @if ($isAdmin)
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editGroupModal-{{ $group->id }}" title="Edit {{ $group->name }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form action="{{ route('admin.messages.groups.destroy', $group) }}" method="POST" class="d-inline"
                                  data-confirm="Delete the group &quot;{{ $group->name }}&quot;? Everything said in it goes too.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete {{ $group->name }}"><i class="bi bi-trash"></i></button>
                            </form>
                        @endif
                    </span>
                </span>
            </div>
        @empty
            <div class="card-body text-center py-5">
                <i class="bi bi-people conversation-empty-icon"></i>
                <p class="fw-semibold mb-1">No groups yet</p>
                <p class="text-muted-soft small mb-0">{{ $isAdmin ? 'Set one up with New group — pick who\'s in it, and they can all talk there.' : 'When an Admin puts you in a group, its chat shows here.' }}</p>
            </div>
        @endforelse
    </div>

    @if ($isAdmin)
        {{-- Set up a group. --}}
        <div class="modal fade" id="newGroupModal" tabindex="-1" aria-labelledby="newGroupModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('admin.messages.groups.store') }}" class="modal-content" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="newGroupModalLabel">New group</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('admin.messages._group_fields', [
                            'prefix' => 'new-group',
                            'bag' => 'createGroup',
                            'people' => $peopleButMe,
                            'name' => $failedCreate ? old('name') : '',
                            'description' => $failedCreate ? old('description') : '',
                            'members' => $failedCreate ? array_map('intval', (array) old('members', [])) : [],
                            'includeMe' => $failedCreate ? (bool) old('include_me') : true,
                        ])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-people"></i> Set up group</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Change one — its name, what it's for, who's in it. --}}
        @foreach ($groups as $group)
            @php $failedHere = $failedEditId === $group->id; @endphp
            <div class="modal fade" id="editGroupModal-{{ $group->id }}" tabindex="-1" aria-labelledby="editGroupModalLabel-{{ $group->id }}" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <form method="POST" action="{{ route('admin.messages.groups.update', $group) }}" class="modal-content" novalidate>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="group_id" value="{{ $group->id }}">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editGroupModalLabel-{{ $group->id }}">Edit group</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            @include('admin.messages._group_fields', [
                                'prefix' => 'edit-group-'.$group->id,
                                'bag' => $failedHere ? 'editGroup' : 'editGroupUnused',
                                'people' => $people,
                                'name' => $failedHere ? old('name') : $group->name,
                                'description' => $failedHere ? old('description') : $group->description,
                                'members' => $failedHere ? array_map('intval', (array) old('members', [])) : $group->members->pluck('id')->all(),
                                'includeMe' => null,
                            ])
                            <div class="form-text">Anyone added sees what's been said; anyone taken off no longer does.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    @endif
@endsection

@if ($isAdmin && ($failedCreate || $openEditId))
    @push('scripts')
        <script>
            // A refused save — or Edit asked for from a group's chat: its popup open.
            document.addEventListener('DOMContentLoaded', function () {
                var modal = document.getElementById('{{ $failedCreate ? 'newGroupModal' : 'editGroupModal-'.$openEditId }}');
                if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
            });
        </script>
    @endpush
@endif
