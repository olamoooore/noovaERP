<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListProductCategories extends Command
{
    protected $signature = 'products:list-categories 
                            {--format=table : Output format (table, json, csv)}
                            {--export= : Export to file path}';

    protected $description = 'List all product categories with their IDs for import reference';

    public function handle(): int
    {
        // Ensure we're in the correct tenant schema
        $searchPath = config('database.connections.pgsql.search_path');
        if ($searchPath && $searchPath !== 'public') {
            DB::statement("SET search_path TO {$searchPath}");
            $this->info("Using schema: {$searchPath}");
        }

        $categories = ProductCategory::orderBy('name')->get(['id', 'name', 'parent_id', 'is_active']);

        if ($categories->isEmpty()) {
            $this->warn('No product categories found in the database.');
            $this->info('You can still import products with category names - they will be created automatically.');

            return self::SUCCESS;
        }

        $format = $this->option('format');
        $exportPath = $this->option('export');

        $output = '';

        switch ($format) {
            case 'json':
                $output = $categories->toJson(JSON_PRETTY_PRINT);
                break;

            case 'csv':
                $output = "ID,Name,Parent ID,Active\n";
                foreach ($categories as $cat) {
                    $output .= "{$cat->id},\"{$cat->name}\",{$cat->parent_id},".($cat->is_active ? 'Yes' : 'No')."\n";
                }
                break;

            case 'table':
            default:
                $this->info("Found {$categories->count()} product categories:");
                $this->newLine();

                $rows = $categories->map(fn ($c) => [
                    $c->id,
                    $c->name,
                    $c->parent_id ?? '-',
                    $c->is_active ? '✓' : '✗',
                ])->toArray();

                $this->table(
                    ['ID', 'Name', 'Parent ID', 'Active'],
                    $rows
                );

                $this->newLine();
                $this->info('Usage in CSV import:');
                $this->line('  - Use the ID number (first column) for exact matching');
                $this->line('  - Or use the exact Name (second column) - case insensitive');
                $this->line('  - New categories will be auto-created if name does not exist');

                if ($exportPath) {
                    $csvOutput = "ID,Name,Parent ID,Active\n";
                    foreach ($categories as $cat) {
                        $csvOutput .= "{$cat->id},\"{$cat->name}\",{$cat->parent_id},".($cat->is_active ? 'Yes' : 'No')."\n";
                    }
                    file_put_contents($exportPath, $csvOutput);
                    $this->newLine();
                    $this->info("Exported to: {$exportPath}");
                }

                return self::SUCCESS;
        }

        if ($exportPath) {
            file_put_contents($exportPath, $output);
            $this->info("Exported to: {$exportPath}");
        } else {
            $this->line($output);
        }

        return self::SUCCESS;
    }
}
