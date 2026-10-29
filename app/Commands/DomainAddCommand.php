<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
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

            $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];

            // Kept at a DNS provider of the customer's own: nothing to wait for here, Brewless looks the record up by itself.
            if (($result['awaiting_dns'] ?? false) === true) {
                $this->awaitingDns($hostname, $result);
                $this->offerAddress($api, $environment, $result);

                return self::SUCCESS;
            }

            $this->components->info('https://'.$hostname.' is live on '.$this->argument('environment').'.');

            $this->notices($result);
            $this->offerAddress($api, $environment, $result);

            return self::SUCCESS;
        });
    }

    /**
     * The record to make at the customer's own DNS provider, and what happens next.
     *
     * @param  array<string, mixed>  $result
     */
    private function awaitingDns(string $hostname, array $result): void
    {
        $record = is_array($result['record'] ?? null) ? $result['record'] : [];

        $this->components->warn('The DNS of '.$hostname.' is kept where Brewless does not reach. Make this record at your DNS provider:');
        $this->line('    <options=bold>'.($record['type'] ?? 'CNAME').'</>  '.($record['name'] ?? $hostname).'  ->  '.($record['value'] ?? '?'));

        if (($result['apex'] ?? false) === true) {
            $this->line('    '.$hostname.' is the domain itself, where a CNAME may not stand: use ALIAS, ANAME or CNAME flattening if your provider has it,');
            $this->line('    forward it to www.'.$hostname.' at your registrar and add that name instead, or move this zone to Bunny DNS.');
        }

        if (is_string($result['found'] ?? null)) {
            $this->line('    Found there now: '.$result['found']);
        }

        $this->newLine();
        $this->line('  Brewless looks it up every few minutes and issues the certificate by itself once it points the right way.');
        $this->line('  Follow it with <options=bold>brewless domain:list '.$this->argument('environment').'</>, or look now with <options=bold>brewless domain:check '.$this->argument('environment').' '.$hostname.'</>.');
    }

    /**
     * Brewless suggests the address the application should call its own, now
     * that it answers on a domain. It is only set after a yes: an
     * application's own address is the customer's to change.
     *
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $result
     */
    private function offerAddress(Api $api, array $environment, array $result): void
    {
        $address = $result['address'] ?? null;

        if (! is_array($address) || ! is_string($address['variable'] ?? null) || ! is_string($address['suggested'] ?? null) || ! $this->input->isInteractive()) {
            return;
        }

        if (! $this->confirm('Set '.$address['variable'].' to '.$address['suggested'].' now? It takes effect with the next deploy.')) {
            return;
        }

        $api->put('/api/environments/'.$environment['id'].'/secrets/'.$address['variable'], ['value' => $address['suggested']]);

        $this->components->info($address['variable'].' is '.$address['suggested'].' now. Run brewless deploy '.$this->argument('environment').' to put it live.');
    }
}
