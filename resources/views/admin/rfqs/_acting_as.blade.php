{{--
    Admin's "Done by" picker: which of the people who hold $role a stage's
    action is recorded as done by, in place of Admin themselves — see
    RfqController::doneBy(). Left as "Myself" it changes nothing. Nothing at
    all for anyone but Admin, and nothing where nobody holds the role, since
    there's no one to pick.

    Expects: $role — the role that stage belongs to.
    Optional: $id (with $label, $disabled) — a labelled field for a modal.
    Without $id it's a compact select to sit beside a button in a row.
--}}
@if (auth()->user()->hasRole('Admin'))
    @php $members = \App\Models\User::roleMembers($role); @endphp
    @if ($members->isNotEmpty())
        @isset($id)
            <div class="mb-3">
                <label for="{{ $id }}" class="form-label">
                    {{ $label ?? 'Done by' }} <span class="text-muted-soft fw-normal">({{ $role }})</span>
                </label>
                <select name="acting_user_id" id="{{ $id }}" class="form-select" {{ ($disabled ?? false) ? 'disabled' : '' }}>
                    <option value="">Myself (Admin)</option>
                    @foreach ($members as $member)
                        <option value="{{ $member->id }}" @selected((string) old('acting_user_id') === (string) $member->id)>{{ $member->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">Recorded as who did it, instead of you.</div>
            </div>
        @else
            <select name="acting_user_id" class="form-select form-select-sm d-inline-block w-auto align-middle"
                    aria-label="Done by ({{ $role }})" title="Recorded as done by… ({{ $role }})">
                <option value="">Myself</option>
                @foreach ($members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        @endisset
    @endif
@endif
