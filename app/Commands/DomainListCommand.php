<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DomainListCommand extends Command
{
    use TalksToBrewless;

    private const array STATUSES = [
        'awaiting_dns' => '<fg=yellow>Waiting for DNS</>',
        'active' => '<fg=green>Live</>',
        'dns_changed' => '<fg=red>DNS changed</>',
        'failed' => '<fg=red>Stopped waiting</>',
    ];

    protected $signature = 'domain:list {environment : The environment whose domains to list}';

    protected $description = 'List the domains of an environment, with the records you keep at your own DNS provider';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $answer = $api->get('/api/environments/'.$environment['id'].'/domains')['data'] ?? [];

            if (is_string($answer['unavailable'] ?? null)) {
                $this->components->error($answer['unavailable']);

                return self::FAILURE;
            }

            $domains = is_array($answer['domains'] ?? null) ? $answer['domains'] : [];

            if ($domains === []) {
                $this->components->info($this->argument('environment').' has no domain of its own yet. Add one with: brewless domain:add '.$this->argument('environment').' shop.example.eu');

                return self::SUCCESS;
            }

            $this->table(['Domain', 'State', 'DNS', 'Certificate'], array_map(fn (array $domain): array => [
                $domain['hostname'],
                $this->state($domain),
                ($domain['tracked']['kept_elsewhere'] ?? false) === true ? 'your own provider' : 'through Brewless',
                ($domain['certificate'] ?? false) === true ? 'yes' : 'not yet',
            ], $domains));

            foreach ($domains as $domain) {
                $this->keptElsewhere(is_array($domain['tracked'] ?? null) ? $domain['tracked'] : null);
            }

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $domain
     */
    private function state(array $domain): string
    {
        $status = $domain['tracked']['status'] ?? null;

        return is_string($status) ? (self::STATUSES[$status] ?? $status) : (($domain['certificate'] ?? false) === true ? self::STATUSES['active'] : '');
    }

    /**
     * The record to make for a domain that is not live, as `vapor record:list` showed it.
     *
     * @param  array<string, mixed>|null  $tracked
     */
    private function keptElsewhere(?array $tracked): void
    {
        if ($tracked === null || ($tracked['kept_elsewhere'] ?? false) !== true || ($tracked['status'] ?? '') === 'active' || ! is_array($tracked['record'] ?? null)) {
            return;
        }

        $record = $tracked['record'];

        $this->newLine();
        $this->line('  <options=bold>'.$tracked['hostname'].'</>: make this record at your DNS provider');
        $this->line('    '.$record['type'].'  '.$record['name'].'  ->  '.$record['value']);
        $this->line('    Found the last time: '.(is_string($tracked['found'] ?? null) ? $tracked['found'] : 'nothing'));

        if (($tracked['status'] ?? '') === 'awaiting_dns' && is_string($tracked['gives_up_at'] ?? null)) {
            $this->line('    Brewless looks it up by itself until '.date('j M Y, H:i', (int) strtotime($tracked['gives_up_at'])).'.');
        }

        if (($tracked['status'] ?? '') === 'failed') {
            $this->line('    Brewless stopped waiting. Make the record, then run: brewless domain:check '.$this->argument('environment').' '.$tracked['hostname']);
        }
    }
}
