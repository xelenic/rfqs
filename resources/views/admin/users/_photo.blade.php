{{--
    A person's photo (x-avatar) with a camera button on it for whoever can
    change it (User::canChangePhotoOf()): picking a photo squares and shrinks
    it in the browser and sends it straight away (admin.js, .js-photo-form) to
    UserPhotoController::update(), which comes back to where it was sent from.

    Expects: $person, $returnTo ('settings' or 'show'), $avatarClass, and
    optionally $inputId (for a "Change photo" label elsewhere to open it).
--}}
<div class="photo-edit">
    <x-avatar :user="$person" :class="$avatarClass" />
    @if (auth()->user()->canChangePhotoOf($person))
        <form method="POST" action="{{ route('admin.users.photo.update', $person) }}" enctype="multipart/form-data" class="js-photo-form">
            @csrf
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            <label class="photo-edit-button" title="{{ $person->avatar_path ? 'Change photo' : 'Add a photo' }}">
                <i class="bi bi-camera-fill" aria-hidden="true"></i>
                <span class="visually-hidden">{{ $person->avatar_path ? 'Change photo' : 'Add a photo' }}</span>
                <input type="file" name="photo" class="js-photo-input" @isset($inputId) id="{{ $inputId }}" @endisset
                       accept="{{ collect(\App\Models\User::AVATAR_EXTENSIONS)->map(fn (string $extension) => '.'.$extension)->join(',') }}">
            </label>
        </form>
    @endif
</div>
