{{--
    Time per item, person by person — on Settings → Data Entry Time and GM
    Assistant Time: everyone in the role, each with their own minutes an item
    for someone who works faster or slower than the rest, or left empty for
    the company's (the card above). Setting::perItemPeople(), used by
    Rfq::dataEntryTargetBreakdown() / gmAssistantTargetBreakdown() for the
    parts they do.

    Expects: $id (the card's id), $role ('Data Entry' or 'GM Assistant'),
    $item ('item' or 'line item'), $action (the route it saves to), $bag (its
    error bag), $people (everyone in the role), $own (user id => seconds),
    $companySeconds, $range (DATA_ENTRY_PER_ITEM_RANGE).
--}}
@php
    $failed = $errors->getBag($bag)->any();
    $minutes = fn (int $seconds) => rtrim(rtrim(number_format($seconds / 60, 1), '0'), '.');
    $aItem = ($item === 'item' ? 'an ' : 'a ').$item;
@endphp
<div class="card mb-4" id="{{ $id }}">
    <div class="card-header d-flex align-items-center gap-2">
        Time per {{ $item }}, person by person
        <span class="badge badge-soft-primary">Admin</span>
        @if ($own !== [])
            <span class="badge badge-soft-success">{{ count($own) }} set</span>
        @endif
    </div>
    <div class="card-body">
        @if ($people->isEmpty())
            <p class="text-muted-soft small mb-0">No one's in {{ $role }} yet.</p>
        @else
            <form method="POST" action="{{ $action }}" novalidate>
                @csrf
                @method('PATCH')

                <p class="text-muted-soft small">
                    For someone who works faster or slower than the rest: their own minutes {{ $aItem }}, used for the parts they do.
                    Leave it empty for the time per {{ $item }} above — {{ $minutes($companySeconds) }} min.
                    @if ($role === 'Data Entry')
                        A part goes by whoever's doing it, or who it was given to by name; one left to anyone takes the company's till someone starts it.
                    @else
                        A part goes by whoever started it; till someone does, it shows the company's.
                    @endif
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-3">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th style="width: 240px;">Time per {{ $item }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($people as $person)
                                @php
                                    $field = 'people.'.$person->id;
                                    $value = $failed ? old($field) : (isset($own[$person->id]) ? $minutes($own[$person->id]) : '');
                                @endphp
                                <tr data-per-item-person="{{ $person->id }}">
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <x-avatar :user="$person" class="assignee-avatar" />
                                            <span class="fw-semibold">{{ $person->name }}</span>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="people[{{ $person->id }}]" value="{{ $value }}"
                                                   class="form-control @error($field, $bag) is-invalid @enderror"
                                                   min="{{ $range[0] / 60 }}" max="{{ $range[1] / 60 }}" step="0.5"
                                                   placeholder="{{ $minutes($companySeconds) }}" aria-label="{{ $person->name }}'s minutes {{ $aItem }}">
                                            <span class="input-group-text">minutes</span>
                                            @error($field, $bag)
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save people's times</button>
            </form>
        @endif
    </div>
</div>
