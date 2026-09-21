<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
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
 * An Admin holding the rfqs.delete permission — what used to be enough to
 * delete an RFQ, and still is to delete other people's comments.
 */
function adminWhoCouldDelete(): User
{
    $admin = userWithRole('Admin');
    Permission::findOrCreate('rfqs.delete');

    return $admin->givePermissionTo('rfqs.delete');
}

it('has no way to delete an RFQ', function () {
    expect(Route::has('admin.rfqs.destroy'))->toBeFalse();
});

it('refuses a request to delete an RFQ, whoever it comes from', function (string $role) {
    $rfq = Rfq::factory()->create();
    $user = userWithRole($role);
    Permission::findOrCreate('rfqs.delete');
    $user->givePermissionTo('rfqs.delete');

    test()->actingAs($user)->delete(route('admin.rfqs.show', $rfq))->assertStatus(405);

    expect(Rfq::find($rfq->id))->not->toBeNull();
})->with(['Admin', 'Head of Business Development', 'Business Development']);

it('offers no delete button on the list or on the RFQ itself', function () {
    $rfq = Rfq::factory()->create(['status' => 'Pending']);
    $admin = adminWhoCouldDelete();

    foreach ([route('admin.rfqs.index', ['status' => 'Pending']), route('admin.rfqs.show', $rfq)] as $url) {
        test()->actingAs($admin)->get($url)->assertOk()
            ->assertDontSee('Delete this RFQ?', false)
            ->assertDontSee('value="DELETE"', false);
    }
});

it('still lets a comment be deleted by its author, and by whoever holds rfqs.delete', function () {
    $rfq = Rfq::factory()->create();
    $author = userWithRole('Sourcing');
    $own = $rfq->comments()->create(['user_id' => $author->id, 'body' => 'Mine']);
    $theirs = $rfq->comments()->create(['user_id' => $author->id, 'body' => 'Theirs']);

    test()->actingAs($author)->delete(route('admin.rfqs.comments.destroy', [$rfq, $own]))->assertRedirect();
    test()->actingAs(adminWhoCouldDelete())->delete(route('admin.rfqs.comments.destroy', [$rfq, $theirs]))->assertRedirect();

    expect($rfq->comments()->count())->toBe(0);
});
