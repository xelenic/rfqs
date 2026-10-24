{{--
    A job category's name and description, in the Add or Edit popup
    (categories/index): the name the Assign Sourcing wizard lists it by, and
    the description it shows under it.

    Expects: $prefix (the fields' id prefix), $bag (the popup's error bag),
    $name, $description (what to fill them with).
--}}
<div class="mb-3">
    <label for="{{ $prefix }}-category-name" class="form-label">Name</label>
    <input type="text" name="name" id="{{ $prefix }}-category-name" maxlength="100" required
           class="form-control @error('name', $bag) is-invalid @enderror" value="{{ $name }}" placeholder="e.g. Electrical Works">
    @error('name', $bag)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
<div>
    <label for="{{ $prefix }}-category-description" class="form-label">Description <span class="text-muted-soft fw-normal">(optional)</span></label>
    <textarea name="description" id="{{ $prefix }}-category-description" rows="3" maxlength="500"
              class="form-control @error('description', $bag) is-invalid @enderror"
              placeholder="What it covers — shown under it in the Assign Sourcing wizard">{{ $description }}</textarea>
    @error('description', $bag)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
