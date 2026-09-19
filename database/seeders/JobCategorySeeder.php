<?php

namespace Database\Seeders;

use App\Models\JobCategory;
use Illuminate\Database\Seeder;

class JobCategorySeeder extends Seeder
{
    /**
     * Starter categories for the Assign Sourcing wizard's dropdown —
     * idempotent, so re-running never duplicates or overwrites anything.
     * Operations can add more from the wizard itself.
     */
    public function run(): void
    {
        $categories = [
            'Electrical Works',
            'Plumbing & Sanitary',
            'HVAC & Air Conditioning',
            'Civil & Structural',
            'Fire Safety',
            'Housekeeping & Cleaning',
            'Landscaping',
            'Pest Control',
            'Security Systems',
            'IT & Networking',
            'Painting & Finishing',
            'Supplies & Procurement',
        ];

        foreach ($categories as $name) {
            JobCategory::findOrCreateByName($name);
        }
    }
}
