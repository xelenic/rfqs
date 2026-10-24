{{--
    Admin → Job Categories (JobCategoryController): the categories Senior
    Operations picks from in the Assign Sourcing wizard — each with its icon
    and description, how many RFQs have it (and how many of those are
    open), and who added it when — with Add, Edit and Delete. Add and Edit
    are one popup each (#createCategoryModal, #editCategoryModal — the latter
    filled from the row's Edit, admin.js .js-edit-category); a refused save
    reopens its popup with what was typed. Categories that RFQs have but the
    list doesn't — deleted since, or older than it — are listed under it, to
    put back.

    Expects: $categories (paginated, with creator, rfqs_count,
    open_rfqs_count), $search, $total, $unlisted (name => RFQs).
--}}
@extends('layouts.app')

@section('title', 'Job Categories')

@section('content')
    @php
        $failedCreate = $errors->createCategory->any();
        $failedEdit = $errors->editCategory->any();
        $failedEditCategory = $failedEdit && old('category_id') ? \App\Models\JobCategory::query()->find(old('category_id')) : null;
        // Where a save comes back to: this search, this page.
        $here = array_filter(['search' => $search, 'page' => request('page')]);
    @endphp

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search categories..." style="min-width:220px;" aria-label="Search categories">
                <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
                @if ($search !== '')
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-link">Clear</a>
                @endif
            </form>

            <div class="d-flex align-items-center gap-3">
                <span class="text-muted-soft small">{{ $total }} {{ \Illuminate\Support\Str::plural('category', $total) }} in the Assign Sourcing wizard</span>
                <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                    <i class="bi bi-plus-lg"></i> Add category
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="d-none d-md-table-cell">Description</th>
                        <th class="text-end">RFQs</th>
                        <th>Added</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr data-category="{{ $category->id }}">
                            <td class="fw-semibold">
                                <span class="d-inline-flex align-items-center gap-2">
                                    <span class="category-icon"><i class="bi {{ $category->icon() }}" aria-hidden="true"></i></span>
                                    {{ $category->name }}
                                </span>
                            </td>
                            <td class="small text-muted-soft d-none d-md-table-cell">{{ $category->description ?: '—' }}</td>
                            <td class="text-end text-nowrap">
                                <span class="fw-semibold">{{ $category->rfqs_count }}</span>
                                @if ($category->open_rfqs_count > 0)
                                    <div class="small text-muted-soft">{{ $category->open_rfqs_count }} open</div>
                                @endif
                            </td>
                            <td class="small text-muted-soft text-nowrap">
                                {{ $category->created_at->toBusinessTimezone()->format('M d, Y') }}
                                <div>{{ $category->creator ? 'by '.$category->creator->name : 'Set up with the system' }}</div>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary js-edit-category"
                                        data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                        data-action="{{ route('admin.categories.update', $category) }}"
                                        data-id="{{ $category->id }}"
                                        data-name="{{ $category->name }}"
                                        data-description="{{ $category->description }}"
                                        data-rfqs="{{ $category->rfqs_count }}">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" class="d-inline"
                                      data-confirm="Delete &quot;{{ $category->name }}&quot;? It won't be offered in the Assign Sourcing wizard any more.{{ $category->rfqs_count > 0 ? ' Its '.$category->rfqs_count.($category->rfqs_count === 1 ? ' RFQ keeps' : ' RFQs keep').' it as their category.' : '' }}">
                                    @csrf
                                    @method('DELETE')
                                    @foreach ($here as $key => $value)
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endforeach
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-soft py-4">
                                {{ $search !== '' ? 'No category matches "'.$search.'".' : 'No categories yet — add the first.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($categories->hasPages())
            <div class="card-footer bg-white">{{ $categories->links() }}</div>
        @endif
    </div>

    {{-- Categories RFQs have that the list doesn't — one deleted, say: put it back, to offer it again. --}}
    @if ($unlisted->isNotEmpty())
        <div class="card" id="unlisted-categories">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>On RFQs, but not on the list</span>
                <span class="text-muted-soft small fw-normal">The RFQs keep them; the wizard doesn't offer them</span>
            </div>
            <div class="list-group list-group-flush">
                @foreach ($unlisted as $name => $count)
                    <div class="list-group-item d-flex align-items-center justify-content-between gap-2" data-unlisted-category="{{ $name }}">
                        <span>
                            <span class="fw-semibold">{{ $name }}</span>
                            <span class="text-muted-soft small">· {{ $count }} {{ $count === 1 ? 'RFQ' : 'RFQs' }}</span>
                        </span>
                        <form action="{{ route('admin.categories.store') }}" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="name" value="{{ $name }}">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Put back on the list</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Add a category. --}}
    <div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('admin.categories.store') }}" class="modal-content" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createCategoryModalLabel">Add category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('admin.categories._fields', ['prefix' => 'create', 'bag' => 'createCategory', 'name' => $failedCreate ? old('name') : '', 'description' => $failedCreate ? old('description') : ''])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add category</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit one — pointed at its category by the row's Edit (admin.js). --}}
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" id="editCategoryForm" action="{{ $failedEditCategory ? route('admin.categories.update', $failedEditCategory) : '#' }}" class="modal-content" novalidate>
                @csrf
                @method('PUT')
                <input type="hidden" name="category_id" value="{{ $failedEditCategory?->id }}">
                @foreach ($here as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <div class="modal-header">
                    <h5 class="modal-title" id="editCategoryModalLabel">Edit category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('admin.categories._fields', ['prefix' => 'edit', 'bag' => 'editCategory', 'name' => $failedEdit ? old('name') : '', 'description' => $failedEdit ? old('description') : ''])
                    <div class="form-text" id="edit-category-rename-note">Renaming it renames it on the RFQs that have it, too.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- A refused save: back in its popup, as it was sent. --}}
    @if ($failedCreate || $failedEditCategory)
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('{{ $failedCreate ? 'createCategoryModal' : 'editCategoryModal' }}')).show();
                });
            </script>
        @endpush
    @endif
@endsection
