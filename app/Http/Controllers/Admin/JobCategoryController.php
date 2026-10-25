<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\JobCategoryImport;
use App\Models\JobCategory;
use App\Models\Rfq;
use App\Spreadsheet;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Configuration → Job Categories: the categories Senior Operations picks from in the
 * Assign Sourcing wizard (JobCategory) — each with its description, how many
 * RFQs have it, and who added it when — added, edited and deleted here.
 * Renaming one renames it on the RFQs that have it too (rfqs.category keeps
 * the name); deleting one takes it off the wizard's list, and its RFQs keep
 * it as their category. Categories RFQs have that aren't on the list — one
 * deleted, say — are listed, to put back, or to move their RFQs to one
 * that is (reassign()). Many can be added at once from an
 * Excel workbook or CSV (JobCategoryImport), starting from the list itself,
 * downloaded as one. For User::JOB_CATEGORY_ROLES
 * (Admin, Senior Operations, GM Assistant).
 */
class JobCategoryController extends Controller
{
    /**
     * How many categories the page lists at once.
     */
    private const PER_PAGE = 20;

    /**
     * How many skipped rows an import lists by name; the rest are counted.
     */
    private const SKIPPED_SHOWN = 50;

    /**
     * The categories, by name — searched by name or description — with their
     * RFQs, all and still open.
     */
    public function index(Request $request): View
    {
        $this->abortUnlessCanManage($request);

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
            // What an unlisted one's RFQs can be moved to (reassign()).
            'listedNames' => $unlisted->isNotEmpty() ? JobCategory::query()->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    /**
     * Adds a category — a name no other has, whatever its case, and a
     * description if it has one.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortUnlessCanManage($request);

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
        $this->abortUnlessCanManage($request);

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
     * it keep it as their category — listed under it to put back, or move to
     * another (reassign()).
     */
    public function destroy(Request $request, JobCategory $category): RedirectResponse
    {
        $this->abortUnlessCanManage($request);

        $kept = Rfq::query()->where('category', $category->name)->count();
        $category->delete();

        return redirect()->route('admin.categories.index', $request->only('search', 'page'))
            ->with('status', "Deleted \"{$category->name}\"".($kept > 0 ? " — its {$kept} ".($kept === 1 ? 'RFQ keeps' : 'RFQs keep').' it as their category.' : '.'));
    }

    /**
     * Moves the RFQs that have a category not on the list ($from — deleted
     * since, or from before the list) to one that is: they take its name,
     * and $from is no longer on any RFQ. Refused for a name on the list —
     * that's renamed with Edit instead — or one no RFQ has.
     */
    public function reassign(Request $request): RedirectResponse
    {
        $this->abortUnlessCanManage($request);

        $validated = $request->validateWithBag('reassignCategory', [
            'from' => [
                'required', 'string',
                function (string $attribute, string $value, Closure $fail) {
                    if (JobCategory::query()->where('name', $value)->exists()) {
                        $fail('That one\'s on the list — rename it with Edit instead.');
                    } elseif (! Rfq::query()->where('category', $value)->exists()) {
                        $fail('No RFQ has that category any more.');
                    }
                },
            ],
            'to' => ['required', 'integer', 'exists:job_categories,id'],
        ], [
            'to.required' => 'Choose the category to move them to.',
            'to.exists' => 'That category isn\'t on the list any more.',
        ]);

        $to = JobCategory::query()->findOrFail($validated['to']);
        $moved = DB::transaction(fn () => Rfq::query()->where('category', $validated['from'])->update(['category' => $to->name]));

        return redirect()->route('admin.categories.index')
            ->with('status', 'Moved '.($moved === 1 ? 'the RFQ' : "the {$moved} RFQs")." with \"{$validated['from']}\" to \"{$to->name}\".");
    }

    /**
     * Adds the categories in an uploaded Excel workbook (.xlsx) or CSV —
     * see JobCategoryImport — and says what came of it, with the rows it
     * skipped and why. A file that can't be read, or has no category in it,
     * reopens the Import popup saying so.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->abortUnlessCanManage($request);

        $request->validateWithBag('importCategories', [
            'file' => ['required', 'file', 'max:5120', 'extensions:'.implode(',', Spreadsheet::EXTENSIONS)],
            'update_descriptions' => ['boolean'],
        ], [
            'file.required' => 'Choose the file to import.',
            'file.max' => 'Keep the file under 5 MB.',
            'file.extensions' => 'Choose an Excel workbook (.xlsx) or a CSV file. An older .xls? Open it in Excel and save it as .xlsx.',
        ]);

        $file = $request->file('file');

        try {
            $rows = Spreadsheet::rows($file->getRealPath(), $file->getClientOriginalExtension(), JobCategoryImport::MAX_ROWS);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()])->errorBag('importCategories');
        }

        $import = JobCategoryImport::run($rows, $request->user(), $request->boolean('update_descriptions'));

        if (! $import->foundAny()) {
            throw ValidationException::withMessages(['file' => 'There\'s no category in that file — put the names in column A, one a row.'])->errorBag('importCategories');
        }

        return redirect()->route('admin.categories.index')
            ->with('status', 'Imported "'.$file->getClientOriginalName().'": '.lcfirst($import->summary()))
            ->with('importSkipped', [
                'rows' => array_slice($import->skipped, 0, self::SKIPPED_SHOWN),
                'more' => max(count($import->skipped) - self::SKIPPED_SHOWN, 0),
            ]);
    }

    /**
     * The list as an Excel workbook — Name, Description — to fill in and
     * import: a template, with the categories there already in it.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $this->abortUnlessCanManage($request);

        $rows = JobCategory::query()->orderBy('name')->get(['name', 'description'])
            ->map(fn (JobCategory $category) => [$category->name, $category->description])
            ->prepend(['Name', 'Description'])
            ->all();

        return response()->download(Spreadsheet::xlsx($rows, 'Job Categories', [36, 90]), 'job-categories.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
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

    private function abortUnlessCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageJobCategories(), 403, 'Only Admin, Senior Operations and GM Assistant can manage the job categories.');
    }
}
