<?php

namespace App\Filament\Tenant\Resources\FeatureResource\Pages;

use App\Filament\Tenant\Resources\FeatureResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeatures extends ManageRecords
{
    protected static string $resource = FeatureResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
