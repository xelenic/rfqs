<?php

use App\Http\Middleware\CaptureLiveVersion;
use App\LiveVersion;
use App\Models\JobCategory;
use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

// The RFQ list looks up its Sourcing and Senior Operations members, so every
// workflow role has to exist whether or not anyone holds it.
beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An Admin who can open the pages for users, roles and permissions.
 */
function liveAdmin(): User
{
    $admin = userWithRole('Admin');

    foreach (['users.view', 'roles.view', 'permissions.view'] as $permission) {
        Permission::findOrCreate($permission);
        $admin->givePermissionTo($permission);
    }

    return $admin;
}

function liveHashOf(string $html): string
{
    preg_match('/<main [^>]*data-live-hash="([0-9a-f]{32})"/', $html, $matches);

    return $matches[1] ?? '';
}

// ---- the pulse ---------------------------------------------------------------

it('answers the pulse with the data\'s version, to signed-in users only', function () {
    test()->get(route('admin.live'))->assertRedirect(route('login'));

    test()->actingAs(userWithRole('Sourcing'))
        ->getJson(route('admin.live'))
        ->assertOk()
        ->assertExactJson(['version' => LiveVersion::current()])
        ->assertHeader('Cache-Control', 'no-store, private');
});

// ---- what moves the version --------------------------------------------------

it('moves the version on when what the pages show is written', function (Closure $write) {
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $before = LiveVersion::current();

    $write($rfq);

    expect(LiveVersion::current())->not->toBe($before);
})->with([
    'an RFQ created' => [fn () => Rfq::factory()->create()],
    'an RFQ changed' => [fn (Rfq $rfq) => $rfq->update(['subject' => 'Something else'])],
    'an RFQ deleted' => [fn (Rfq $rfq) => $rfq->delete()],
    // The part's own progress is written straight to the pivot, which fires no
    // model events — the version still has to move.
    'a part completed' => [fn (Rfq $rfq) => $rfq->completeSourcingPart(1)],
    'a raw pivot update' => [fn (Rfq $rfq) => DB::table('rfq_user')->where('rfq_id', $rfq->id)->update(['returned_at' => now()])],
    'a comment posted' => [fn (Rfq $rfq) => $rfq->comments()->create(['user_id' => User::factory()->create()->id, 'body' => 'Hello'])],
    'a message sent' => [fn () => PrivateMessage::factory()->create()],
    'a job category added' => [fn () => JobCategory::factory()->create()],
]);

it('leaves the version alone for users, roles, permissions and reading', function () {
    $user = userWithRole('Sourcing');
    Rfq::factory()->create();
    $before = LiveVersion::current();

    Role::findOrCreate('Something new');
    Permission::findOrCreate('something.new');
    $user->assignRole('Something new');
    $user->update(['name' => 'Someone Else']);
    Rfq::query()->count();

    test()->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    test()->actingAs($user)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk();

    expect(LiveVersion::current())->toBe($before);
});

it('waits for the transaction to commit, and does not move on if it rolls back', function () {
    $before = LiveVersion::current();

    DB::transaction(function () use ($before) {
        Rfq::factory()->create();

        // Not yet: a page rebuilt now wouldn't see it.
        expect(LiveVersion::current())->toBe($before);
    });

    $committed = LiveVersion::current();
    expect($committed)->not->toBe($before);

    try {
        DB::transaction(function () {
            Rfq::factory()->create();

            throw new RuntimeException('Rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(LiveVersion::current())->toBe($committed);
});

it('does not rewrite read messages each time a conversation is opened', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Data Entry')];
    PrivateMessage::factory()->create(['sender_id' => $sam->id, 'recipient_id' => $riley->id]);
    $unread = LiveVersion::current();

    // Reading it is a change worth telling everyone about…
    test()->actingAs($riley)->get(route('admin.messages.show', $sam))->assertOk();
    $read = LiveVersion::current();
    expect($read)->not->toBe($unread);

    // …but opening it again isn't, or a live refresh of this page would move
    // the version on every time and never settle.
    test()->actingAs($riley)->get(route('admin.messages.show', $sam))->assertOk();
    expect(LiveVersion::current())->toBe($read);
});

it('recognises writes to the tables it watches, and only those', function (string $sql, bool $watched) {
    expect(LiveVersion::writesWatchedTable($sql))->toBe($watched);
})->with([
    'insert' => ['insert into "rfqs" ("subject") values (?)', true],
    'update' => ['update "rfq_user" set "completed_at" = ? where "rfq_id" = ?', true],
    'delete' => ['delete from "rfq_comments" where "id" = ?', true],
    'insert or ignore' => ['insert or ignore into "private_messages" ("body") values (?)', true],
    'update or ignore' => ['update or ignore "job_categories" set "name" = ?', true],
    'backticks' => ['update `rfqs` set `status` = ?', true],
    'a select' => ['select * from "rfqs" where "id" = ?', false],
    'users' => ['update "users" set "name" = ?', false],
    'the cache itself' => ['insert into "cache" ("key", "value") values (?, ?)', false],
    'a longer name' => ['insert into "rfqs_archive" ("subject") values (?)', false],
]);

it('accounts for every table, so a new one has to be put in or left out of live updates on purpose', function () {
    $tables = collect(Schema::getTableListing())->map(fn (string $table) => Str::afterLast($table, '.'));

    expect($tables->diff([...LiveVersion::WATCHED_TABLES, ...LiveVersion::IGNORED_TABLES])->values()->all())->toBe([])
        ->and(array_intersect(LiveVersion::WATCHED_TABLES, LiveVersion::IGNORED_TABLES))->toBe([])
        ->and($tables->intersect(LiveVersion::WATCHED_TABLES)->count())->toBe(count(LiveVersion::WATCHED_TABLES));
});

// ---- the page ----------------------------------------------------------------

it('carries the version the page was built from, and where to ask for the next', function () {
    $html = test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->toContain('<meta name="live-version" content="'.LiveVersion::current().'">')
        ->toContain('<meta name="live-pulse" content="'.route('admin.live').'">')
        ->toContain('id="liveIndicator"')
        ->toContain('js/live.js');
});

it('notes the version before the page is built, not after', function () {
    $before = LiveVersion::current();
    $request = Request::create('/admin');

    // Something is written while the page is being built.
    (new CaptureLiveVersion)->handle($request, function () {
        LiveVersion::bump();

        return response('built');
    });

    expect($request->attributes->get('live_version'))->toBe($before)
        ->and(LiveVersion::current())->not->toBe($before);
});

it('refreshes the body of the workflow pages, but not the pages for users, roles and permissions', function () {
    $admin = liveAdmin();
    $rfq = Rfq::factory()->create();

    foreach ([
        route('admin.dashboard'),
        route('admin.rfqs.index', ['status' => 'Pending']),
        route('admin.rfqs.show', $rfq),
        route('admin.messages.index'),
    ] as $url) {
        expect(test()->actingAs($admin)->get($url)->assertOk()->getContent())->toContain('id="live-main" data-live="on"');
    }

    foreach ([route('admin.users.index'), route('admin.roles.index'), route('admin.permissions.index')] as $url) {
        expect(test()->actingAs($admin)->get($url)->assertOk()->getContent())->toContain('id="live-main" data-live="off"');
    }
});

it('gives the body a hash that only changes when what it shows does', function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $user = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    // A page rebuilt with nothing changed comes out the same — otherwise a
    // refresh would swap the body every time.
    foreach ([
        route('admin.dashboard'),
        route('admin.rfqs.index', ['status' => 'Pending']),
        route('admin.rfqs.show', $rfq),
        route('admin.messages.index'),
    ] as $url) {
        $first = liveHashOf(test()->actingAs($user)->get($url)->getContent());

        expect($first)->not->toBe('')
            ->and(liveHashOf(test()->actingAs($user)->get($url)->getContent()))->toBe($first);
    }

    $listed = liveHashOf(test()->actingAs($user)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent());
    Rfq::factory()->create(['subject' => 'A brand new request']);

    expect(liveHashOf(test()->actingAs($user)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent()))->not->toBe($listed);

    Carbon::setTestNow();
});

it('keeps what the server says about the sidebar in the page, so its counts can be picked up', function () {
    $html = test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->getContent();

    // The links and their counts the live script matches up between renders.
    expect($html)->toContain('class="sidebar-nav"')->toContain('nav-link');

    Rfq::factory()->create();

    expect(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent())
        ->toContain('class="nav-link-count" title="1 not yet assigned to Sourcing">1</span>');
});
