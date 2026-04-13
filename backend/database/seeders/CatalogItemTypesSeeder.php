<?php

namespace Database\Seeders;

use App\SuperAdmin\Domain\CatalogItemType;
use Illuminate\Database\Seeder;

/**
 * Seeds the catalog_item_types table with the standard product taxonomy types.
 * New types (e.g. ai_pack, locale_pack) can be added here or via the admin UI —
 * no schema changes required.
 */
class CatalogItemTypesSeeder extends Seeder
{
    private const TYPES = [
        ['key' => 'module',      'name' => 'Module',      'description' => 'Top-level ERP module (e.g. Accounting, Inventory, POS)',          'sort_order' => 10],
        ['key' => 'submodule',   'name' => 'Sub-module',  'description' => 'Functional area within a module (e.g. Accounts Payable)',          'sort_order' => 20],
        ['key' => 'feature',     'name' => 'Feature',     'description' => 'Specific capability within a sub-module (e.g. OCR Receipt Capture)', 'sort_order' => 30],
        ['key' => 'addon_pack',  'name' => 'Add-on Pack', 'description' => 'Separately purchasable feature bundle (e.g. AI Pack, OCR Pack)',   'sort_order' => 40],
        ['key' => 'ai_pack',     'name' => 'AI Pack',     'description' => 'AI-powered feature bundle',                                         'sort_order' => 50],
        ['key' => 'locale_pack', 'name' => 'Locale Pack', 'description' => 'Region-specific compliance or localisation bundle',                 'sort_order' => 60],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            CatalogItemType::updateOrCreate(
                ['key' => $type['key']],
                [
                    'name'        => $type['name'],
                    'description' => $type['description'],
                    'sort_order'  => $type['sort_order'],
                ],
            );
        }

        $this->command->info('Seeded ' . count(self::TYPES) . ' catalog item types.');
    }
}
