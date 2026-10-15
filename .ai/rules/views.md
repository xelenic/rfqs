---
paths:
  - 'resources/views/**'
---

# Views

## Show a person's circle with <x-avatar>, not their initial
Since 2026-10-15, people can have a profile photo (users.avatar_path, stored on the private local disk). Any round avatar for a person is drawn with `<x-avatar :user="$person" class="assignee-avatar" />`. It shows the photo (User::avatarUrl()) or falls back to the initial. Never hand-roll `strtoupper(substr($name, 0, 1))`. Photos are served only through UserPhotoController::show(), to anyone signed in, at a URL that changes with every upload. Use avatarUrl(); don't build the URL by hand. To add a photo to a JS-built avatar, pass avatarUrl() in a data attribute, as _comment_author does with data-photo.
