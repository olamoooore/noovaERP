<?php

namespace App\Filament\SuperAdmin\Resources\AccountingFindingResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountingFindingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ManageAccountingFindings extends ListRecords
{
    protected static string $resource = AccountingFindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
