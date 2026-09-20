<?php

namespace App\Models;

use Database\Factories\JobCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable job category for RFQs — the dropdown Operations picks from in
 * the Assign Sourcing wizard, with its description shown beneath so the
 * right one is easy to pick. A category typed in there that isn't listed
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

    /**
     * Bootstrap Icons for the wizard's category card, matched on a keyword
     * in the category's name — so a category typed in by hand ("Electrical
     * repairs") still gets a fitting one. Anything else gets a plain tag.
     *
     * @var array<string, string>
     */
    private const ICONS = [
        'electric' => 'bi-lightning-charge',
        'plumb' => 'bi-droplet',
        'sanitary' => 'bi-droplet',
        'hvac' => 'bi-snow',
        'air condition' => 'bi-snow',
        'civil' => 'bi-bricks',
        'structur' => 'bi-bricks',
        'fire' => 'bi-fire',
        'clean' => 'bi-stars',
        'housekeep' => 'bi-stars',
        'landscap' => 'bi-flower1',
        'pest' => 'bi-bug',
        'secur' => 'bi-shield-lock',
        'network' => 'bi-hdd-network',
        'paint' => 'bi-palette',
        'suppl' => 'bi-box-seam',
    ];

    protected $fillable = [
        'name',
        'description',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Bootstrap Icons class that goes with this category.
     */
    public function icon(): string
    {
        $name = mb_strtolower($this->name);

        foreach (self::ICONS as $keyword => $icon) {
            if (str_contains($name, $keyword)) {
                return $icon;
            }
        }

        return 'bi-tag';
    }

    /**
     * Finds an existing category by name, ignoring case and surrounding
     * spaces, or creates it — so "electrical" typed by hand doesn't end up
     * alongside an existing "Electrical". The description only goes on a
     * category that's being created; an existing one is left as it is.
     */
    public static function findOrCreateByName(string $name, ?User $createdBy = null, ?string $description = null): self
    {
        $name = trim($name);

        return static::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create([
                'name' => $name,
                'description' => filled($description) ? trim($description) : null,
                'created_by' => $createdBy?->id,
            ]);
    }
}
