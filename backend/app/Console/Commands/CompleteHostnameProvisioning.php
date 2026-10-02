<?php

namespace App\Console\Commands;

use App\PublicSites\Provisioning\ProvisioningResultService;
use Illuminate\Console\Command;

final class CompleteHostnameProvisioning extends Command
{
    protected $signature = 'website-domains:complete-provisioning
                            {operation : Provisioning operation UUID}
                            {--certificate-order= : ACME provider order identifier}
                            {--certificate-expires= : Certificate expiry timestamp}
                            {--failed= : Sanitized stable failure message}';

    protected $description = 'Apply a constrained result from the privileged website-domain provisioning worker';

    public function handle(ProvisioningResultService $results): int
    {
        $id = (string) $this->argument('operation');
        if ($failure = $this->option('failed')) {
            $results->fail($id, (string) $failure);
            $this->info('Provisioning operation failed.');

            return self::SUCCESS;
        }
        $results->complete($id, $this->option('certificate-order'), $this->option('certificate-expires'));
        $this->info('Provisioning operation completed.');

        return self::SUCCESS;
    }
}
