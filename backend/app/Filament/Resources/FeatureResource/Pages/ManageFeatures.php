<?php

namespace App\Filament\Resources\FeatureResource\Pages;

use App\Filament\Resources\FeatureResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeatures extends ManageRecords
{
    protected static string $resource = FeatureResource::class;

    protected function getHeaderActions(): array
    {
        // Keep the UI minimal: no create/edit/delete; features are derived from module.json.
        return [];
    }
}
