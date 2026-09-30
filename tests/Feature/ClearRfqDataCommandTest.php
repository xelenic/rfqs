<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('deletes every RFQ with its parts and comments, leaving users alone', function () {
    $user = User::factory()->create();
    $rfq = Rfq::factory()->create();
    $rfq->assignees()->attach($user->id, ['part_number' => 1]);
    $rfq->comments()->create(['user_id' => $user->id, 'body' => 'Hello']);

    $this->artisan('rfq:clear', ['--force' => true])->assertSuccessful();

    expect(Rfq::count())->toBe(0)
        ->and(DB::table('rfq_user')->count())->toBe(0)
        ->and(RfqComment::count())->toBe(0)
        ->and(User::whereKey($user->id)->exists())->toBeTrue()
        ->and(Rfq::nextRfqNumber())->toBe('RFQ1001');
});

it('leaves everything in place when the confirmation is declined', function () {
    Rfq::factory()->create();

    $this->artisan('rfq:clear')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(Rfq::count())->toBe(1);
});
