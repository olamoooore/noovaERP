<?php
$file = __DIR__ . '/../modules/EventManagement/Services/EventOperationsService.php';
$src  = file_get_contents($file);

// 1. Replace all getCurrentTenantId() calls
$src = str_replace(
    '$this->tenantManager->getCurrentTenantId()',
    '$this->resolveTenantId()',
    $src
);

// 2. Make sub-method tenantId params nullable (?string instead of string)
$signatures = [
    'public function getCheckInMetrics(int $eventId, string $tenantId)' =>
    'public function getCheckInMetrics(int $eventId, ?string $tenantId)',

    'public function getZoneStatus(int $eventId, string $tenantId)' =>
    'public function getZoneStatus(int $eventId, ?string $tenantId)',

    'public function getStaffStatus(int $eventId, string $tenantId)' =>
    'public function getStaffStatus(int $eventId, ?string $tenantId)',

    'public function getQueueStatus(int $eventId, string $tenantId)' =>
    'public function getQueueStatus(int $eventId, ?string $tenantId)',

    'public function getRecentIncidents(int $eventId, string $tenantId,' =>
    'public function getRecentIncidents(int $eventId, ?string $tenantId,',

    'public function getCurrentEventStatus(int $eventId, string $tenantId)' =>
    'public function getCurrentEventStatus(int $eventId, ?string $tenantId)',
];

foreach ($signatures as $from => $to) {
    $src = str_replace($from, $to, $src);
}

file_put_contents($file, $src);
echo "Done. getCurrentTenantId replaced: " . substr_count($src, 'getCurrentTenantId') . " remaining\n";
echo "resolveTenantId usages: " . substr_count($src, 'resolveTenantId') . "\n";
