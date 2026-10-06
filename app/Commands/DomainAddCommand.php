<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class DomainAddCommand extends Command
{
    use TalksToBrewless;

    private const array STEP_LABELS = [
        'attach_domain' => 'Domain added at your provider',
        'dns' => 'DNS',
        'certificate' => 'Certificate issued',
    ];

    protected $signature = 'domain:add {environment : The environment that should answer on the domain}
        {hostname : The domain, such as shop.example.eu}';

    protected $description = 'Make an environment answer on a domain of your own, with a certificate';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));
            $hostname = strtolower(trim((string) $this->argument('hostname')));

            // What the DNS of the domain has to point at, in case it is kept where Brewless does not reach.
            $target = $api->get('/api/environments/'.$environment['id'].'/domains')['data']['target'] ?? null;

            $operation = $api->post('/api/environments/'.$environment['id'].'/domains', [
                'hostname' => $hostname,
                'key' => 'cli-'.Str::lower(Str::random(24)),
            ])['data'] ?? [];

            $this->newLine();
            $this->line('  Adding <options=bold>'.$hostname.'</> to '.$this->argument('environment'));

            $printed = [];
            $waiting = false;

            while (true) {
                foreach ($operation['steps'] ?? [] as $step) {
                    $name = (string) $step['name'];

                    if (($printed[$name] ?? null) !== $step['status'] && in_array($step['status'], ['succeeded', 'failed'], true)) {
                        $printed[$name] = $step['status'];
                        $this->line(($step['status'] === 'succeeded' ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').(self::STEP_LABELS[$name] ?? $name));
                    }

                    // Said once: the certificate only comes when the DNS points the right way.
                    if (! $waiting && $name === 'certificate' && $step['status'] === 'pending' && is_string($step['error_message'] ?? null)) {
                        $waiting = true;
                        $this->line('  <fg=yellow>…</> '.$step['error_message']);

                        if (is_string($target)) {
                            $this->line('    If the DNS of the domain is not managed through Brewless, make this record: CNAME '.$hostname.' -> '.$target);
                        }
                    }
                }

                if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                    break;
                }

                Sleep::for(3)->seconds();

                $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
            }

            $this->newLine();

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->components->error($operation['error_message'] ?? 'The domain was not added.');

                return self::FAILURE;
            }

            $this->components->info('https://'.$hostname.' is live on '.$this->argument('environment').'.');

            return self::SUCCESS;
        });
    }
}
