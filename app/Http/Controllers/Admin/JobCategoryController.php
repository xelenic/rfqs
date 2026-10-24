<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobCategory;
use App\Models\Rfq;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → Job Categories: the categories Senior Operations picks from in the
 * Assign Sourcing wizard (JobCategory) — each with its description, how many
 * RFQs have it, and who added it when — added, edited and deleted here.
 * Renaming one renames it on the RFQs that have it too (rfqs.category keeps
 * the name); deleting one takes it off the wizard's list, and its RFQs keep
 * it as their category. Categories RFQs have that aren't on the list — one
 * deleted, say — are listed, to put back. Admin only.
 */
class JobCategoryController extends Controller
{
    /**
     * How many categories the page lists at once.
     */
    private const PER_PAGE = 20;

    /**
     * The categories, by name — searched by name or description — with their
     * RFQs, all and still open.
     */
    public function index(Request $request): View
    {
        $this->abortUnlessAdmin($request);

        $search = $request->string('search')->trim()->toString();

        $categories = JobCategory::query()
            ->with('creator')
            ->addSelect([
                'rfqs_count' => Rfq::query()->selectRaw('count(*)')->whereColumn('rfqs.category', 'job_categories.name'),
                'open_rfqs_count' => Rfq::query()->selectRaw('count(*)')->whereColumn('rfqs.category', 'job_categories.name')->where('rfqs.status', 'Pending'),
            ])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // On RFQs, but not on the list — deleted since, or from before the list.
        $unlisted = Rfq::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->whereNotIn('category', JobCategory::query()->select('name'))
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('total', 'category');

        return view('admin.categories.index', [
            'categories' => $categories,
            'search' => $search,
            'total' => JobCategory::query()->count(),
            'unlisted' => $unlisted,
        ]);
    }

    /**
     * Adds a category — a name no other has, whatever its case, and a
     * description if it has one.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $this->validated($request, 'createCategory');

        $category = JobCategory::query()->create($validated + ['created_by' => $request->user()->id]);

        return redirect()->route('admin.categories.index')
            ->with('status', "Added \"{$category->name}\" — it's in the Assign Sourcing wizard's list now.");
    }

    /**
     * Changes a category's name or description. A new name goes on the RFQs
     * that had the old one too.
     */
    public function update(Request $request, JobCategory $category): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $this->validated($request, 'editCategory', $category);
        $oldName = $category->name;
        $renamed = $validated['name'] !== $oldName;

        $moved = DB::transaction(function () use ($category, $validated, $oldName, $renamed) {
            $category->update($validated);

            return $renamed ? Rfq::query()->where('category', $oldName)->update(['category' => $validated['name']]) : 0;
        });

        return redirect()->route('admin.categories.index', $request->only('search', 'page'))
            ->with('status', $renamed
                ? "Renamed \"{$oldName}\" to \"{$category->name}\"".($moved > 0 ? ' — and on its '.$moved.' '.($moved === 1 ? 'RFQ' : 'RFQs').'.' : '.')
                : "Saved \"{$category->name}\".");
    }

    /**
     * Deletes a category: it's off the wizard's list, and the RFQs that have
     * it keep it as their category.
     */
    public function destroy(Request $request, JobCategory $category): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $kept = Rfq::query()->where('category', $category->name)->count();
        $category->delete();

        return redirect()->route('admin.categories.index', $request->only('search', 'page'))
            ->with('status', "Deleted \"{$category->name}\"".($kept > 0 ? " — its {$kept} ".($kept === 1 ? 'RFQ keeps' : 'RFQs keep').' it as their category.' : '.'));
    }

    /**
     * The name — up to 100 characters, as the wizard takes, and no other
     * category's, whatever its case — and description sent, validated into
     * error bag $bag.
     *
     * @return array{name: string, description: ?string}
     */
    private function validated(Request $request, string $bag, ?JobCategory $category = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'description' => filled($request->input('description')) ? trim((string) $request->input('description')) : null,
        ]);

        $validated = $request->validateWithBag($bag, [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, string $value, Closure $fail) use ($category) {
                    $taken = JobCategory::query()
                        ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                        ->when($category, fn ($query) => $query->whereKeyNot($category->id))
                        ->exists();

                    if ($taken || $value === JobCategory::NEW_OPTION) {
                        $fail('There\'s already a category called that.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => 'Give the category a name.',
            'name.max' => 'Keep the name under 100 characters.',
            'description.max' => 'Keep the description under 500 characters.',
        ]);

        return ['name' => $validated['name'], 'description' => $validated['description'] ?? null];
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can manage the job categories.');
    }
}
