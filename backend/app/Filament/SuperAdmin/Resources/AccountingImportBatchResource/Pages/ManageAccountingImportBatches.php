<?php

namespace App\Filament\SuperAdmin\Resources\AccountingImportBatchResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountingImportBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ManageAccountingImportBatches extends ListRecords
{
    protected static string $resource = AccountingImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
