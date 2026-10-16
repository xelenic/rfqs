{{--
    An Admin's Deactivate — or, for an account already deactivated, Activate
    — on someone (UserController::deactivate() / activate()), asking first.
    Deactivated, they can't sign in and drop out of every list people are
    picked from; their history stays.

    Expects: $user. Optional: $compact (an icon-only button), $asMenuItem (an
    item in an Options menu).
--}}
@php
    $compact ??= false;
    $asMenuItem ??= false;
@endphp
@if ($user->isDeactivated())
    <form action="{{ route('admin.users.activate', $user) }}" method="POST" @class(['d-inline' => ! $asMenuItem])
          data-confirm="Activate {{ $user->name }}'s account? They'll be able to sign in again, and be picked for work.">
        @csrf
        @method('PATCH')
        <button type="submit" class="{{ $asMenuItem ? 'dropdown-item text-success' : 'btn btn-sm btn-outline-success' }}" title="Activate — let them sign in again">
            <i class="bi bi-person-check"></i>@unless ($compact) Activate @endunless
        </button>
    </form>
@else
    <form action="{{ route('admin.users.deactivate', $user) }}" method="POST" @class(['d-inline' => ! $asMenuItem])
          data-confirm="Deactivate {{ $user->name }}? They'll be signed out and can't sign in until an Admin activates them again. Their history stays.">
        @csrf
        @method('PATCH')
        <button type="submit" class="{{ $asMenuItem ? 'dropdown-item' : 'btn btn-sm btn-outline-secondary' }}" title="Deactivate — stop them signing in">
            <i class="bi bi-person-slash"></i>@unless ($compact) Deactivate @endunless
        </button>
    </form>
@endif
