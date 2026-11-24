<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class TinkerCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'tinker {environment : The environment to run it in}
        {code : The PHP to run, as you would type it in tinker: "App\\Models\\User::count()"}
        {--force : Do not ask for confirmation on production}';

    protected $description = 'Run a line of PHP in an environment with tinker and show what it prints';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);
            $code = trim((string) $this->argument('code'));

            if ($code === '') {
                $this->components->error('Give the PHP to run: brewless tinker '.$slug.' "App\\Models\\User::count()"');

                return self::FAILURE;
            }

            // It runs against real data: on production, ask first, and show what will run.
            if (($environment['kind'] ?? '') === 'production' && ! $this->option('force') && ! $this->confirm('Run `'.$code.'` on production?')) {
                return self::SUCCESS;
            }

            // It runs in your own container, next to your database. Brewless passes it on and keeps neither the code nor the output.
            $result = $api->post('/api/environments/'.$environment['id'].'/commands', ['command' => self::consoleLine($code)])['data'] ?? [];

            $this->output->write((string) ($result['output'] ?? ''));

            if (! str_ends_with((string) ($result['output'] ?? ''), "\n")) {
                $this->newLine();
            }

            // 0 when the code ran, 1 when it threw: tinker's own exit code.
            return (int) ($result['exit_code'] ?? 1);
        });
    }

    /**
     * The console line for the code: one tinker --execute, quoted the way
     * the framework's console reads a line, so quotes, dollar signs and
     * backslashes in the code arrive as typed.
     */
    public static function consoleLine(string $code): string
    {
        return 'tinker --execute="'.addcslashes(str_replace(["\r\n", "\n", "\r"], ' ', $code), '"\\').'"';
    }
}
