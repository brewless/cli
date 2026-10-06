<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class CommandCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'command {environment : The environment to run it in}
        {line : The command, as you would give it to the framework\'s console: "migrate:status"}
        {--force : Do not ask for confirmation on production}';

    protected $description = 'Run one console command in an environment and show what it prints';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);
            $line = trim((string) $this->argument('line'));

            // It runs against real data: on production, ask first.
            if (($environment['kind'] ?? '') === 'production' && ! $this->option('force') && ! $this->confirm('Run "'.$line.'" on production?')) {
                return self::SUCCESS;
            }

            // It runs in your own container, next to your database. Brewless passes it on and keeps neither it nor the output.
            $result = $api->post('/api/environments/'.$environment['id'].'/commands', ['command' => $line])['data'] ?? [];

            $this->output->write((string) ($result['output'] ?? ''));

            if (! str_ends_with((string) ($result['output'] ?? ''), "\n")) {
                $this->newLine();
            }

            // The exit code of this command is the exit code of the one that ran.
            return (int) ($result['exit_code'] ?? 1);
        });
    }
}
