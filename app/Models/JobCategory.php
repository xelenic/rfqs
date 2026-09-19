<?php

namespace App\Models;

use Database\Factories\JobCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable job category for RFQs — the dropdown Operations picks from in
 * the Assign Sourcing wizard. A category typed in there that isn't listed
 * yet is stored here when the wizard finishes, so it's in the dropdown next
 * time. The RFQ itself keeps the category's name (rfqs.category), so old
 * RFQs read correctly even if a category is later renamed or removed.
 */
class JobCategory extends Model
{
    /** @use HasFactory<JobCategoryFactory> */
    use HasFactory;

    /**
     * The dropdown value that means "none of these — I'll type a new one"
     * in the Assign Sourcing wizard. Can't collide with a real category
     * name.
     */
    public const NEW_OPTION = '__new__';

    protected $fillable = [
        'name',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Finds an existing category by name, ignoring case and surrounding
     * spaces, or creates it — so "electrical" typed by hand doesn't end up
     * alongside an existing "Electrical".
     */
    public static function findOrCreateByName(string $name, ?User $createdBy = null): self
    {
        $name = trim($name);

        return static::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['name' => $name, 'created_by' => $createdBy?->id]);
    }
}
