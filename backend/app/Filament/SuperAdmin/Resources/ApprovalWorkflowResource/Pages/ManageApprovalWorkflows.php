<?php

namespace App\Filament\SuperAdmin\Resources\ApprovalWorkflowResource\Pages;

use App\Filament\SuperAdmin\Resources\ApprovalWorkflowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ManageApprovalWorkflows extends ListRecords
{
    protected static string $resource = ApprovalWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
