<?php

namespace Database\Seeders;

use App\Models\JobCategory;
use Illuminate\Database\Seeder;

class JobCategorySeeder extends Seeder
{
    /**
     * Starter categories for the Assign Sourcing wizard's dropdown, each
     * with the description shown under it — idempotent, so re-running never
     * duplicates a category, and a description is only filled in where
     * there isn't one, never overwriting one someone has written.
     * Operations can add more from the wizard itself.
     */
    public function run(): void
    {
        $categories = [
            'Electrical Works' => 'Wiring, lighting, distribution boards, generators and other electrical repairs and installations.',
            'Plumbing & Sanitary' => 'Water supply, drainage, pumps, fixtures and sanitary fittings — repairs, replacements and new installs.',
            'HVAC & Air Conditioning' => 'Air conditioners, chillers, ventilation and ducting — servicing, repairs and replacements.',
            'Civil & Structural' => 'Building fabric and structure — masonry, concrete, roofing, waterproofing and general repairs.',
            'Fire Safety' => 'Alarms, extinguishers, sprinklers and emergency lighting — supply, servicing and compliance.',
            'Housekeeping & Cleaning' => 'Day-to-day and deep cleaning, janitorial services and consumables for the premises.',
            'Landscaping' => 'Gardens, lawns, planting and outdoor grounds — upkeep and new landscaping works.',
            'Pest Control' => 'Inspection, treatment and prevention of pests, on a one-off or contract basis.',
            'Security Systems' => 'CCTV, access control, alarms and guarding equipment — supply, installation and maintenance.',
            'IT & Networking' => 'Computers, cabling, Wi-Fi, servers and related equipment — supply, setup and support.',
            'Painting & Finishing' => 'Interior and exterior painting, coatings, flooring and other finishing works.',
            'Supplies & Procurement' => 'General goods and consumables sourced from suppliers, where no specific trade applies.',
        ];

        foreach ($categories as $name => $description) {
            $category = JobCategory::findOrCreateByName($name);

            if (blank($category->description)) {
                $category->update(['description' => $description]);
            }
        }
    }
}
