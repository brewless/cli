<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function in_array;
use function is_array;
use function is_string;

final class EdgeRotateSecretCommand extends Command
{
    use TalksToBrewless;

    /** What each step says once it is done. */
    private const array STEPS = [
        'support' => 'The application can hold two secrets at once',
        'mint' => 'New secret made in your Secret Manager',
        'both' => 'Containers hold the new secret and the one before it',
        'edge' => 'The edge sends the new secret',
        'only' => 'Containers hold the new secret alone',
        'retire' => 'The secret before it deleted',
    ];

    protected $signature = 'edge:rotate-secret {environment : The environment whose edge secret to replace}
        {--force : Do not ask for confirmation}';

    protected $description = 'Replace the secret with which the edge proves to your containers that a request came through it';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $this->newLine();
            $this->line('  Replacing the edge secret of <options=bold>'.$project->application.'</> on <options=bold>'.$slug.'</>.');
            $this->line('  With that secret the edge proves to your containers that a request came through it, and');
            $this->line('  without it they believe neither the hostname of the visitor nor their address.');
            $this->line('  It lives on two sides that cannot change at the same moment, so your containers hold both');
            $this->line('  the new secret and the one before it until the edge has moved over. That takes two releases,');
            $this->line('  which put the image that is live now live again: nothing is built and nothing is rolled back.');
            $this->line('  Your site keeps answering throughout, and every release empties the edge cache.');

            if (! $this->option('force') && ! $this->confirm('Replace the edge secret of '.$slug.'?')) {
                return self::SUCCESS;
            }

            $operation = $api->post('/api/environments/'.$environment['id'].'/edge/secret', ['key' => 'cli-'.Str::lower(Str::random(24))])['data'] ?? [];
            $printed = [];

            while (true) {
                foreach ($operation['steps'] ?? [] as $step) {
                    $name = (string) ($step['name'] ?? '');

                    if (($step['status'] ?? '') === 'succeeded' && ! isset($printed[$name])) {
                        $printed[$name] = true;
                        $this->line('  <fg=green>✓</> '.(self::STEPS[$name] ?? $name));
                    }
                }

                if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                    break;
                }

                Sleep::for(5)->seconds();

                $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
            }

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->newLine();
                $this->components->error(is_string($operation['error_message'] ?? null) ? $operation['error_message'] : 'The secret was not replaced.');

                return self::FAILURE;
            }

            $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];

            $this->newLine();
            $this->components->info('The edge secret of '.$slug.' is replaced'.($result['revision'] ?? null ? ' (version '.$result['revision'].' in your Secret Manager)' : '').'.');

            return self::SUCCESS;
        });
    }
}
