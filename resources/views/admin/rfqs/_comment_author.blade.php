{{--
    The name on a comment, with the person's role beside it, small, and a hover
    card of their details with a private-message button behind the name (see
    layouts/_user_card.blade.php and admin.js, .js-user-card). Roles should be
    eager-loaded (comments.author.roles) — this reads them.

    Expects: $author (the comment's User — null once their account is deleted).
--}}
@if ($author)
    @php $roleNames = $author->roles->pluck('name'); @endphp
    <span class="fw-semibold rfq-comment-author js-user-card" tabindex="0" role="button"
          data-user-id="{{ $author->id }}"
          data-name="{{ $author->name }}"
          data-email="{{ $author->email }}"
          data-roles="{{ $roleNames->implode('|') }}"
          data-since="{{ $author->created_at?->format('M Y') }}"
          data-self="{{ $author->is(auth()->user()) ? '1' : '0' }}"
          data-conversation="{{ route('admin.messages.show', $author) }}">{{ $author->name }}</span>
    @if ($roleNames->isNotEmpty())
        <span class="badge badge-soft-secondary rfq-comment-role">{{ $roleNames->implode(', ') }}</span>
    @endif
@else
    <span class="fw-semibold">Deleted user</span>
@endif
