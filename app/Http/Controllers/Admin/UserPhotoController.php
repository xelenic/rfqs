<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profile photos: shown to anyone signed in, wherever the person appears; put
 * up or taken down by the person themselves on Settings → Profile, or by
 * whoever changes people's details (User::canChangePhotoOf()) on their page.
 * Kept on the private disk (User::AVATAR_DISK), one per person — a new one
 * replaces the last, file and all.
 */
class UserPhotoController extends Controller
{
    /**
     * Their photo, for an <img>. Its address changes with the photo
     * (User::avatarUrl()), so the browser may keep it as long as it likes.
     */
    public function show(User $user): StreamedResponse
    {
        $disk = Storage::disk(User::AVATAR_DISK);

        abort_unless($user->avatar_path !== null && $disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canChangePhotoOf($user), 403, 'You can only change your own photo.');

        $request->validateWithBag('photo', [
            'photo' => ['required', 'file', 'mimes:'.implode(',', User::AVATAR_EXTENSIONS), 'max:'.User::AVATAR_MAX_KILOBYTES],
        ], [
            'photo.required' => 'Choose a photo.',
            'photo.file' => 'Choose a photo — JPG, PNG, WebP or GIF.',
            'photo.uploaded' => 'The photo didn\'t upload — keep it under '.(User::AVATAR_MAX_KILOBYTES / 1024).' MB and try again.',
            'photo.mimes' => 'Choose a photo — JPG, PNG, WebP or GIF.',
            'photo.max' => 'Keep the photo under '.(User::AVATAR_MAX_KILOBYTES / 1024).' MB.',
        ]);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $request->file('photo')->store('avatars', User::AVATAR_DISK)])->save();

        if ($previous !== null) {
            Storage::disk(User::AVATAR_DISK)->delete($previous);
        }

        return $this->backFrom($request, $user, 'Photo updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canChangePhotoOf($user), 403, 'You can only change your own photo.');
        abort_if($user->avatar_path === null, 404);

        Storage::disk(User::AVATAR_DISK)->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();

        return $this->backFrom($request, $user, 'Photo removed.');
    }

    /**
     * Back to where the photo was changed from: Settings → Profile (return_to
     * "settings"), or the person's page.
     */
    private function backFrom(Request $request, User $user, string $status): RedirectResponse
    {
        return $request->input('return_to') === 'settings'
            ? redirect()->route('admin.settings.edit')->with(['status' => $status, 'settings_tab' => 'profile'])
            : redirect()->route('admin.users.show', $user)->with('status', $status);
    }
}
