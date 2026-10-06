<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Commands\Concerns\WritesExports;
use LaravelZero\Framework\Commands\Command;

final class ExportCommand extends Command
{
    use TalksToBrewless;
    use WritesExports;

    protected $signature = 'export {environment : The environment to export}
        {--output= : The folder to write to; default brewless-export-<application>-<environment>}';

    protected $description = 'Write everything Brewless knows about an environment to a folder, with Terraform and without secrets';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $export = $api->get('/api/environments/'.$environment['id'].'/export')['data'] ?? [];
            $folder = $this->writeExport($export, $this->option('output'));

            $summary = $export['summary'] ?? [];

            $this->components->info('Exported to '.$folder.': '.($summary['resources'] ?? 0).' resources, '.($summary['releases'] ?? 0).' releases, '.($summary['variables'] ?? 0).' variable names. No secret values.');
            $this->line('  Start with '.$folder.'/README.md. `terraform plan` in that folder takes the resources over as they are.');

            return self::SUCCESS;
        });
    }
}
