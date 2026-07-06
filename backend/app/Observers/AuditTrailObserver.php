<?php

namespace App\Observers;

use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Database\Eloquent\Model;

class AuditTrailObserver
{
    public function __construct(private SystemLogServiceContract $systemLog) {}

    public function created(Model $model): void
    {
        $this->logModelEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->logModelEvent($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->logModelEvent($model, 'deleted');
    }

    private function logModelEvent(Model $model, string $action): void
    {
        $tableName = $model->getTable();
        $module = $this->resolveModuleFromTable($tableName);
        $modelId = $model->getAttribute('id') ?? $model->getKey();

        $changes = $action === 'updated' ? $model->getChanges() : [];

        $this->systemLog->log(
            'info',
            "audit.{$module}.{$action}",
            "{$module}.{$tableName} {$action}: {$modelId}",
            [
                'module' => $module,
                'table' => $tableName,
                'model_id' => $modelId,
                'action' => $action,
                'changes' => $changes,
            ]
        );
    }

    private function resolveModuleFromTable(string $tableName): string
    {
        return match (true) {
            str_starts_with($tableName, 'pos_') => 'pos',
            str_starts_with($tableName, 'inventory') || str_starts_with($tableName, 'stock') || str_starts_with($tableName, 'purchase') || str_starts_with($tableName, 'warehouse') => 'inventory',
            str_starts_with($tableName, 'hrm_') || str_starts_with($tableName, 'hr_') || str_starts_with($tableName, 'employee') || str_starts_with($tableName, 'payroll') || str_starts_with($tableName, 'attendance') || str_starts_with($tableName, 'leave') || str_starts_with($tableName, 'contract') => 'hrm',
            default => 'unknown',
        };
    }
}
