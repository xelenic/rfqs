<?php

use App\Models\Rfq;
use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * The form as it posts with every day as Setting::workingHours() has it now,
 * with $changes laid over the days they name.
 *
 * @param  array<string, array<string, string|null>>  $changes
 * @return array{days: array<string, array<string, string|null>>, timezone: string}
 */
function workingWeekPayload(array $changes = []): array
{
    $days = collect(Setting::workingHours())->map(fn (array $day, string $name) => array_merge([
        'working' => $day['working'] ? '1' : '0',
        'start' => $day['start'],
        'end' => $day['end'],
        'lunch_start' => $day['lunch_start'],
        'lunch_end' => $day['lunch_end'],
    ], $changes[$name] ?? []))->all();

    return ['days' => $days, 'timezone' => Setting::timezone()];
}

function saveWorkingWeek(array $payload, ?string $role = 'Admin')
{
    return test()->actingAs(userWithRole($role))->patch(route('admin.settings.working-hours'), $payload);
}

it('starts Monday to Friday, 08:30 to 17:00 with lunch 12:30 to 13:30, the weekend off', function () {
    $week = Setting::workingHours();

    expect(array_keys($week))->toBe(Setting::WEEKDAYS)
        ->and($week['monday'])->toBe(['working' => true, 'start' => '08:30', 'end' => '17:00', 'lunch_start' => '12:30', 'lunch_end' => '13:30'])
        ->and($week['sunday']['working'])->toBeFalse()
        ->and($week['saturday']['working'])->toBeFalse()
        ->and(Setting::workingMinutes($week['monday']))->toBe(450)
        ->and(Setting::workingMinutes($week['sunday']))->toBe(0)
        ->and(Setting::hoursLabel(450))->toBe('7h 30m')
        ->and(Setting::hoursLabel(480))->toBe('8h');
});

it('shows an Admin the working week on the Settings page, every day from Sunday, and nobody else', function () {
    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Working Hours')
        ->assertSee('action="'.route('admin.settings.working-hours').'"', false)
        ->assertSee('id="working-hours-week">37h 30m', false)
        ->getContent();

    $positions = collect(Setting::WEEKDAYS)->map(fn (string $day) => strpos($html, 'data-working-day="'.$day.'"'));
    expect($positions->every(fn ($position) => $position !== false))->toBeTrue()
        ->and($positions->all())->toBe($positions->sort()->values()->all())
        ->and($html)->toContain('<tr data-working-day="sunday" class="is-off">')
        ->toContain('<tr data-working-day="monday" class="">');

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.settings.edit'))->assertOk()
        ->assertDontSee('Working Hours')
        ->assertDontSee(route('admin.settings.working-hours'));
});

it('saves each day\'s working hours and lunch', function () {
    saveWorkingWeek(workingWeekPayload([
        'saturday' => ['working' => '1', 'start' => '09:00', 'end' => '13:00', 'lunch_start' => null, 'lunch_end' => null],
        'friday' => ['start' => '08:00', 'end' => '16:00', 'lunch_start' => '12:00', 'lunch_end' => '12:45'],
    ]))->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Working hours saved.');

    $week = Setting::workingHours();

    expect($week['saturday'])->toBe(['working' => true, 'start' => '09:00', 'end' => '13:00', 'lunch_start' => null, 'lunch_end' => null])
        ->and($week['friday'])->toBe(['working' => true, 'start' => '08:00', 'end' => '16:00', 'lunch_start' => '12:00', 'lunch_end' => '12:45'])
        ->and(Setting::workingMinutes($week['saturday']))->toBe(240)
        ->and(Setting::workingMinutes($week['friday']))->toBe(435)
        ->and($week['monday']['start'])->toBe('08:30');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('value="09:00"', false)
        ->assertSee('value="12:45"', false);
});

it('keeps a day\'s times when it\'s switched off, for when it\'s switched back on', function () {
    // Off, with its time fields left out the way a browser leaves out disabled ones.
    $payload = workingWeekPayload(['wednesday' => ['working' => '0']]);
    $payload['days']['wednesday'] = ['working' => '0'];

    saveWorkingWeek($payload)->assertSessionHasNoErrors();

    expect(Setting::workingHours()['wednesday'])->toBe(['working' => false, 'start' => '08:30', 'end' => '17:00', 'lunch_start' => '12:30', 'lunch_end' => '13:30']);

    saveWorkingWeek(workingWeekPayload(['wednesday' => ['working' => '1']]))->assertSessionHasNoErrors();

    expect(Setting::workingHours()['wednesday']['working'])->toBeTrue();
});

it('doesn\'t check the times of a day off', function () {
    saveWorkingWeek(workingWeekPayload(['sunday' => ['working' => '0', 'start' => 'nonsense', 'end' => '']]))
        ->assertSessionHasNoErrors();
});

it('refuses times that don\'t make sense, saying which day, and saves nothing', function (array $changes, string $field, string $message) {
    saveWorkingWeek(workingWeekPayload(['tuesday' => $changes]))
        ->assertSessionHasErrorsIn('working_hours', ["days.tuesday.{$field}" => $message]);

    expect(Setting::get('working_hours'))->toBeNull();
})->with([
    'no start' => [['start' => null], 'start', 'Give Tuesday\'s start.'],
    'not a time' => [['end' => '5pm'], 'end', 'Tuesday\'s finish has to be a time, like 08:30.'],
    'finishing before it starts' => [['start' => '17:00', 'end' => '09:00'], 'end', 'Tuesday has to finish after it starts.'],
    'finishing as it starts' => [['start' => '09:00', 'end' => '09:00'], 'end', 'Tuesday has to finish after it starts.'],
    'only half a lunch' => [['lunch_start' => '12:00', 'lunch_end' => null], 'lunch_end', 'Give both ends of Tuesday\'s lunch, or neither.'],
    'lunch backwards' => [['lunch_start' => '13:00', 'lunch_end' => '12:00'], 'lunch_end', 'Tuesday\'s lunch has to end after it starts.'],
    'lunch before work starts' => [['lunch_start' => '07:00', 'lunch_end' => '09:00'], 'lunch_start', 'Tuesday\'s lunch has to fall within its working hours.'],
    'lunch after work ends' => [['lunch_start' => '16:30', 'lunch_end' => '17:30'], 'lunch_start', 'Tuesday\'s lunch has to fall within its working hours.'],
]);

it('shows the refused times and the reason with the day they belong to', function () {
    test()->actingAs(userWithRole('Admin'))
        ->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.working-hours'), workingWeekPayload(['tuesday' => ['start' => '17:00', 'end' => '09:00']]))
        ->assertRedirect(route('admin.settings.edit'));

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Tuesday has to finish after it starts.')
        ->assertSee('value="17:00"', false);
});

it('refuses the working hours to anyone but an Admin', function (string $role) {
    saveWorkingWeek(workingWeekPayload(['saturday' => ['working' => '1']]), $role)->assertForbidden();

    expect(Setting::workingHours()['saturday']['working'])->toBeFalse();
})->with(['Business Development', 'Senior Operations', 'General Manager']);

it('falls back to the defaults for anything never saved or saved wrong', function () {
    Setting::put('working_hours', json_encode(['monday' => ['start' => '07:00'], 'funday' => ['working' => true]]));

    $week = Setting::workingHours();

    expect(array_keys($week))->toBe(Setting::WEEKDAYS)
        ->and($week['monday'])->toBe(['working' => true, 'start' => '07:00', 'end' => '17:00', 'lunch_start' => '12:30', 'lunch_end' => '13:30']);

    Setting::put('working_hours', 'not json');

    expect(Setting::workingHours()['monday']['start'])->toBe('08:30');
});
