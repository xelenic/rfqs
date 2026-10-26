---
paths:
  - 'resources/views/**'
---

# Views

## Show a person's circle with <x-avatar>, not their initial
Since 2026-10-15, people can have a profile photo (users.avatar_path, stored on the private local disk). Any round avatar for a person is drawn with `<x-avatar :user="$person" class="assignee-avatar" />`. It shows the photo (User::avatarUrl()) or falls back to the initial. Never hand-roll `strtoupper(substr($name, 0, 1))`. Photos are served only through UserPhotoController::show(), to anyone signed in, at a URL that changes with every upload. Use avatarUrl(); don't build the URL by hand. To add a photo to a JS-built avatar, pass avatarUrl() in a data attribute, as _comment_author does with data-photo.

## No yellow (warning) buttons
Since 2026-10-16, at the user's request, don't use btn-warning or btn-outline-warning: yellow buttons don't read well in this UI. Use btn-outline-secondary for neutral actions like Pause or Put on hold, btn-primary or btn-outline-primary for go-ahead actions, and btn-danger for confirming something that replaces or deletes data (Restore, Clear all RFQ data). Amber is still fine for small status pills and badges.

## Show date and time, never "… ago"
At the user's request (2026-10-17 for the thread, 2026-10-19 everywhere), past times are shown as a date and time in the business time zone, for example $at->toBusinessTimezone()->format('M d, Y g:i A'). Never use diffForHumans(), not even in a title tooltip, and not as a "waiting N hours" figure: label such a column "Waiting since" and show the date. Future countdowns such as the next backup ("in 3 hours") are the only exception.

## Don't put a comma inside @json(...)
Blade's @json splits its argument on commas, and the second piece becomes the json_encode options. So @json($items->map(fn ($x) => ['a' => 1, 'b' => 2])) compiles to json_encode(..., 512) without JSON_HEX_TAG. A name containing </script> can then break out of a <script type="application/json"> island. When the expression has a comma, write {!! json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}, as the mention directory does. Plain @json($variable) is fine.
