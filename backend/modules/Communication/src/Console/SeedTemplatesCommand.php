<?php

namespace Modules\Communication\Console;

use Illuminate\Console\Command;
use Modules\Communication\Database\Seeders\SystemTemplateSeeder;

/**
 * Artisan command: php artisan communication:seed-templates
 *
 * Seeds or refreshes the default system templates.
 */
class SeedTemplatesCommand extends Command
{
    protected $signature = 'communication:seed-templates';
    protected $description = 'Seed default system communication templates';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => SystemTemplateSeeder::class]);
        return self::SUCCESS;
    }
}
