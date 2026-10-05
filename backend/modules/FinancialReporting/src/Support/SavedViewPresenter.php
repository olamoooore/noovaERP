<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Support;

use Modules\FinancialReporting\Models\ReportSavedView;

/**
 * Issue #598/#603 — one serialisation of a saved report view shared by the
 * saved-views controller and the dashboard-widget controller (SOP §4), so a
 * view looks identical in the picker, the manage dialog and a cockpit widget.
 */
class SavedViewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ReportSavedView $view, int $userId, bool $isPinned = false): array
    {
        return [
            'id' => (int) $view->id,
            'user_id' => (int) $view->user_id,
            'name' => (string) $view->name,
            'report_key' => (string) $view->report_key,
            'tab' => (string) $view->tab,
            'filters' => $view->filters ?? [],
            'grouping' => $view->grouping,
            'visibility' => (string) $view->visibility,
            'is_default' => (bool) $view->is_default,
            'is_favourite' => (bool) $view->is_favourite,
            'is_pinned' => $isPinned,
            'is_owner' => $view->isOwnedBy($userId),
            'created_at' => $view->created_at?->toISOString(),
            'updated_at' => $view->updated_at?->toISOString(),
        ];
    }
}
