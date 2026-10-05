<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services;

use App\Models\User;
use App\Services\EntityAccessService;
use App\Services\TenantRoleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\FinancialReporting\Mail\ScheduledSavedViewMail;
use Modules\FinancialReporting\Models\ReportSavedView;
use Modules\FinancialReporting\Models\ReportViewSchedule;
use Modules\FinancialReporting\Models\ReportViewScheduleLog;

/**
 * Issue #598 (RPT-UX3) — deliver due scheduled saved views.
 *
 * Permission enforcement happens on EVERY run, not just when the schedule was
 * created: a recipient is emailed only if they are still an active tenant
 * user, still hold reports.view (TenantRoleService maps the central
 * user_company_access role to the tenant Spatie role) and can still access
 * the view's legal entity (when the saved filters scope one). Every
 * recipient — sent, skipped or failed — gets a visible
 * report_view_schedule_logs row with the reason.
 */
class ScheduledSavedViewDeliveryService
{
    public function __construct(
        private readonly SavedViewReportRenderer $renderer,
        private readonly TenantRoleService $roles,
        private readonly EntityAccessService $entityAccess,
    ) {}

    /**
     * Process every due schedule of the current tenant schema.
     *
     * @return array{processed: int, sent: int, skipped: int, failed: int}
     */
    public function runDueSchedules(string $tenantId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $totals = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        $schedules = ReportViewSchedule::query()
            ->due($now)
            ->with('view')
            ->get();

        foreach ($schedules as $schedule) {
            $counts = $this->processSchedule($schedule, $tenantId, $now);
            foreach ($counts as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return $totals;
    }

    /**
     * @return array{processed: int, sent: int, skipped: int, failed: int}
     */
    public function processSchedule(ReportViewSchedule $schedule, string $tenantId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $counts = ['processed' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        /** @var ReportSavedView|null $view */
        $view = $schedule->view;

        if ($view === null) {
            $schedule->forceFill([
                'last_run_at' => $now,
                'next_run_at' => $schedule->calculateNextRunAt($now),
                'run_count' => ((int) $schedule->run_count) + 1,
                'last_error' => 'Saved view no longer exists.',
            ])->save();

            return $counts;
        }

        [$authorized, $skipped] = $this->authorizeRecipients($schedule, $view, $tenantId);

        // Unauthorized recipients are skipped VISIBLY: a logged row, never an
        // email and never silence.
        foreach ($skipped as $skip) {
            $this->log($schedule, $view->id, $skip['user_id'], $skip['email'], ReportViewScheduleLog::STATUS_SKIPPED, $skip['reason']);
            $counts['skipped']++;
        }

        $renderError = null;

        if ($authorized !== []) {
            try {
                $filePath = $this->renderer->exportFile($view, (string) $schedule->format);

                foreach ($authorized as $recipient) {
                    try {
                        Mail::to($recipient['email'])->send(new ScheduledSavedViewMail(
                            $view->name,
                            (string) $schedule->frequency,
                            (string) $schedule->format,
                            $filePath,
                        ));

                        $this->log($schedule, $view->id, $recipient['user_id'], $recipient['email'], ReportViewScheduleLog::STATUS_SENT, null);
                        $counts['sent']++;
                    } catch (\Throwable $e) {
                        $this->log($schedule, $view->id, $recipient['user_id'], $recipient['email'], ReportViewScheduleLog::STATUS_FAILED, $e->getMessage());
                        $counts['failed']++;

                        Log::error('Scheduled saved-view email failed', [
                            'schedule_id' => $schedule->id,
                            'recipient' => $recipient['email'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $renderError = 'Render failed: '.$e->getMessage();

                foreach ($authorized as $recipient) {
                    $this->log($schedule, $view->id, $recipient['user_id'], $recipient['email'], ReportViewScheduleLog::STATUS_FAILED, $renderError);
                    $counts['failed']++;
                }

                Log::error('Scheduled saved-view render failed', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $schedule->markRun($now, $renderError);

        return $counts;
    }

    /**
     * @return array{0: list<array{user_id: int, email: string, name: string}>, 1: list<array{user_id: int, email: ?string, reason: string}>}
     */
    private function authorizeRecipients(ReportViewSchedule $schedule, ReportSavedView $view, string $tenantId): array
    {
        $authorized = [];
        $skipped = [];

        foreach (array_values(array_unique((array) $schedule->recipients)) as $rawId) {
            $userId = (int) $rawId;
            $user = User::query()->find($userId);

            if ($user === null) {
                $skipped[] = ['user_id' => $userId, 'email' => null, 'reason' => 'Recipient user no longer exists.'];

                continue;
            }

            if (! $user->is_active) {
                $skipped[] = ['user_id' => $userId, 'email' => (string) $user->email, 'reason' => 'Recipient is not an active user.'];

                continue;
            }

            $email = trim((string) $user->email);
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = ['user_id' => $userId, 'email' => $email !== '' ? $email : null, 'reason' => 'Recipient has no deliverable email address.'];

                continue;
            }

            $centralId = DB::table('public.users')->where('email', $email)->value('id');
            if ($centralId === null) {
                $skipped[] = ['user_id' => $userId, 'email' => $email, 'reason' => 'Recipient is not a member of this tenant.'];

                continue;
            }

            if (! $this->roles->hasPermission((int) $centralId, $tenantId, 'reports.view')) {
                $skipped[] = ['user_id' => $userId, 'email' => $email, 'reason' => 'Recipient lacks the reports.view permission.'];

                continue;
            }

            $entityId = is_array($view->filters) ? ($view->filters['legal_entity_id'] ?? null) : null;
            if ($entityId !== null && $entityId !== '') {
                $accessible = $this->entityAccess->accessibleEntityIds($userId);

                // Empty grants mean "no per-entity restriction" (matching the
                // platform's entity-scope convention); a non-empty grant list
                // that excludes the view's entity is a real scope violation.
                if ($accessible !== [] && ! in_array((int) $entityId, $accessible, true)) {
                    $skipped[] = ['user_id' => $userId, 'email' => $email, 'reason' => 'Recipient has no access to legal entity '.$entityId.'.'];

                    continue;
                }
            }

            $authorized[] = ['user_id' => $userId, 'email' => $email, 'name' => (string) $user->name];
        }

        return [$authorized, $skipped];
    }

    private function log(
        ReportViewSchedule $schedule,
        int $viewId,
        ?int $userId,
        ?string $email,
        string $status,
        ?string $reason,
    ): ReportViewScheduleLog {
        return ReportViewScheduleLog::create([
            'schedule_id' => $schedule->id,
            'view_id' => $viewId,
            'user_id' => $userId,
            'recipient' => $email,
            'format' => (string) $schedule->format,
            'status' => $status,
            'reason' => $reason,
            'sent_at' => $status === ReportViewScheduleLog::STATUS_SENT ? now() : null,
        ]);
    }
}
