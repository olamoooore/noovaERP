<?php

namespace App\Filament\SuperAdmin\Resources\FiscalPeriodResource\Pages;

use App\Filament\SuperAdmin\Resources\FiscalPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ManageFiscalPeriods extends ListRecords
{
    protected static string $resource = FiscalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
