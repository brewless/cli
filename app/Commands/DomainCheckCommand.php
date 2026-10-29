<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DomainCheckCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'domain:check {environment : The environment the domain belongs to}
        {hostname : The domain, such as shop.example.eu}';

    protected $description = 'Look up the DNS record of a domain you keep at your own DNS provider now';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));
            $hostname = strtolower(trim((string) $this->argument('hostname'), " \t\n\r\0\x0B."));

            $domains = $api->get('/api/environments/'.$environment['id'].'/domains')['data']['domains'] ?? [];
            $tracked = null;

            foreach (is_array($domains) ? $domains : [] as $domain) {
                if (($domain['hostname'] ?? null) === $hostname && is_array($domain['tracked'] ?? null)) {
                    $tracked = $domain['tracked'];
                }
            }

            if ($tracked === null || ($tracked['kept_elsewhere'] ?? false) !== true) {
                $this->components->error($hostname.' is not a domain of '.$this->argument('environment').' whose DNS you keep at your own provider.');

                return self::FAILURE;
            }

            $domain = $api->post('/api/domains/'.$tracked['id'].'/check')['data'] ?? [];
            $record = is_array($domain['record'] ?? null) ? $domain['record'] : [];

            match (true) {
                ($domain['reason'] ?? null) === 'certificate_pending' => $this->components->info('The record of '.$hostname.' points the right way. The certificate is being issued; that takes a few minutes.'),
                ($domain['status'] ?? '') === 'active' => $this->components->info('The record of '.$hostname.' points the right way.'),
                ($domain['status'] ?? '') === 'dns_changed' => $this->components->error('The record of '.$hostname.' no longer points at '.$this->argument('environment').'.'),
                default => $this->components->warn('The record of '.$hostname.' does not point the right way yet. Brewless keeps looking and goes on by itself.'),
            };

            if (($domain['status'] ?? '') !== 'active' && ($domain['reason'] ?? null) !== 'certificate_pending') {
                $this->line('    Wanted: '.($record['type'] ?? '').'  '.($record['name'] ?? $hostname).'  ->  '.($record['value'] ?? '?'));
                $this->line('    Found:  '.(is_string($domain['found'] ?? null) ? $domain['found'] : 'nothing'));
            }

            return self::SUCCESS;
        });
    }
}
