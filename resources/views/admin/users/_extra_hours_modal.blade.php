{{--
    One person's Extra hours window on the Users page: their ranges still
    counting, each with Remove (from now on — time already worked in it still
    counts), and a form to add one. Admin only, for the people whose time is
    tracked (Sourcing, Data Entry, GM Assistant). Sent to
    SettingsController::storeExtraHours()/destroyExtraHours(), which come
    back here (return_to=users). See ExtraWorkingHour.

    Expects: $user (with extraWorkingHours — the current ones).
--}}
@php
    $failedHere = $errors->extra_hours->any() && old('return_to') === 'users' && (string) old('user_id') === (string) $user->id;
@endphp
<div class="modal fade" id="extraHoursModal-{{ $user->id }}" tabindex="-1" aria-labelledby="extraHoursModalLabel-{{ $user->id }}" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="extraHoursModalLabel-{{ $user->id }}">Extra hours — {{ $user->name }}</h5>
                    <div class="text-muted-soft small">Their time counts in these hours as well as the working hours.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if ($user->extraWorkingHours->isNotEmpty())
                    <ul class="list-unstyled extra-hours-list mb-4">
                        @foreach ($user->extraWorkingHours as $range)
                            <li class="d-flex align-items-center justify-content-between gap-3">
                                <span>
                                    <span class="fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $range->start }} – {{ $range->end }}</span>
                                    · {{ $range->daysLabel() }}
                                    <span class="text-muted-soft small">· counting from {{ $range->starts_on->format('M d, Y') }}</span>
                                </span>
                                <form method="POST" action="{{ route('admin.settings.extra-hours.destroy', $range) }}"
                                      data-confirm="Remove {{ $user->name }}'s extra hours ({{ $range->daysLabel() }}, {{ $range->start }}–{{ $range->end }})? Their time in them stops counting from now on — time already worked in them still counts.">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="return_to" value="users">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Remove</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted-soft mb-4">None yet — they work the working hours alone.</p>
                @endif

                <form method="POST" action="{{ route('admin.settings.extra-hours.store') }}" novalidate>
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $user->id }}">
                    <input type="hidden" name="return_to" value="users">
                    <div class="row g-3 align-items-end">
                        @include('admin.settings._extra_hours_fields', ['idPrefix' => 'user-'.$user->id.'-extra', 'showOld' => $failedHere])
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add extra hours</button>
                            <span class="form-text ms-2">Set an earlier day for time already worked this way.</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
