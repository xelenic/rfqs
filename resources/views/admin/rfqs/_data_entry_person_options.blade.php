{{--
    The people in Data Entry as a picker's <option>s, each with what's already
    theirs: how many parts are waiting for them by name, and whether they're on
    one now. Shared by Sourcing's Assign to Data Entry (_complete_modal) and
    Senior Operations' Reassign (_reassign_modal).

    Optional: $selectedPerson (the option to select).
--}}
@php
    $dataEntryPeople = \App\Models\User::query()->holdingRole('Data Entry')->orderBy('name')->get();
    $waitingFor = \Illuminate\Support\Facades\DB::table('rfq_user')
        ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
        ->where('rfqs.status', 'Pending')
        ->whereNull('rfq_user.status')
        ->whereNotNull('rfq_user.completed_at')
        ->whereNull('rfq_user.data_entry_completed_at')
        ->whereNull('rfq_user.data_entry_started_at')
        ->whereNotNull('rfq_user.data_entry_assigned_to')
        ->groupBy('rfq_user.data_entry_assigned_to')
        ->selectRaw('rfq_user.data_entry_assigned_to as person, count(*) as total')
        ->pluck('total', 'person');
    $runningFor = \App\Models\RfqStep::query()->where('step', 'data_entry')->whereNull('ended_at')->pluck('worked_by')->countBy();
@endphp
@foreach ($dataEntryPeople as $person)
    @php
        $load = collect([
            ($waitingFor[$person->id] ?? 0) > 0 ? $waitingFor[$person->id].' waiting' : null,
            ($runningFor[$person->id] ?? 0) > 0 ? 'on a part now' : null,
        ])->filter()->join(' · ');
    @endphp
    <option value="{{ $person->id }}" @selected((string) ($selectedPerson ?? '') === (string) $person->id)>{{ $person->name }}{{ $load !== '' ? ' — '.$load : ' — free' }}</option>
@endforeach
