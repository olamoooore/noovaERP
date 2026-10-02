<?php

namespace App\PublicSites\Provisioning;

use Symfony\Component\Process\Process;

final class PrivilegedIngressClient
{
    public function begin(string $key): void
    {
        $this->run(['begin', $key]);
    }

    public function reload(): void
    {
        $this->run(['reload']);
    }

    public function commit(string $key): void
    {
        $this->run(['commit', $key]);
    }

    public function rollback(string $key): void
    {
        $this->run(['rollback', $key]);
    }

    public function remove(string $key): void
    {
        $this->run(['remove', $key]);
    }

    public function installBootstrap(string $key, string $hostname): void
    {
        $this->run(['bootstrap', $key, $hostname]);
    }

    public function installValidation(string $key, string $hostname, string $certificate, string $token): void
    {
        $this->run(['validation', $key, $hostname, $certificate, $token]);
    }

    public function installServing(string $key, string $hostname, string $certificate): void
    {
        $this->run(['serving', $key, $hostname, $certificate]);
    }

    public function issueCertificate(string $hostname, string $certificate): void
    {
        $email = (string) config('website_domain_worker.acme_email');
        if ($email === '') {
            throw new \RuntimeException('WEBSITE_DOMAIN_ACME_EMAIL is not configured.');
        }
        $this->run(['certificate', $hostname, $certificate, $email]);
    }

    public function certificateExpiry(string $certificate): string
    {
        return trim($this->run(['expiry', $certificate]));
    }

    public function healthCheck(string $hostname, string $token): void
    {
        $process = new Process(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--resolve', "{$hostname}:443:".config('website_domain_worker.health_ip'), "https://{$hostname}/_ace/domain-check/{$token}"]);
        $process->setTimeout(30)->mustRun();
        if (! hash_equals($token, trim($process->getOutput()))) {
            throw new \RuntimeException('Ingress validation returned an unexpected token.');
        }
    }

    private function run(array $arguments): string
    {
        $command = array_merge([(string) config('website_domain_worker.sudo'), (string) config('website_domain_worker.helper')], $arguments);
        $process = new Process($command);
        $process->setTimeout((int) config('website_domain_worker.operation_timeout', 300))->mustRun();

        return $process->getOutput();
    }
}
