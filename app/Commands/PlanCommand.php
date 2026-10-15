<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\ApiException;
use App\Support\Manifest;
use App\Support\Project;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class PlanCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'plan {environment? : Only this environment; without it, every environment in brewless.yml}';

    protected $description = 'Show what brewless.yml asks for next to what stands now. Changes nothing';

    /** How a line of the plan starts, by what it would do. */
    private const array MARKS = ['same' => '=', 'change' => '~', 'add' => '+', 'kept' => '·', 'unknown' => '?'];

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $manifest = Manifest::read((string) getcwd());
            $api = $this->api($project->organisation);
            $application = $this->application($api, $project);

            try {
                $plan = $api->post('/api/applications/'.$application['id'].'/manifest/plan', ['manifest' => $manifest->data])['data'] ?? [];
            } catch (ApiException $exception) {
                if ($exception->status !== 422 || $exception->errors === []) {
                    throw $exception;
                }

                $this->refused($manifest, $exception->errors);

                return self::FAILURE;
            }

            $only = $this->argument('environment');
            $shown = 0;

            foreach ($plan['environments'] ?? [] as $environment) {
                if (is_string($only) && $environment['name'] !== $only) {
                    continue;
                }

                $this->environment($environment);
                $shown++;
            }

            if (is_string($only) && $shown === 0) {
                throw new RuntimeException(Project::FILE.' has no environment "'.$only.'".');
            }

            if (! is_string($only) && ($plan['unlisted'] ?? []) !== []) {
                $this->line('  Not in '.Project::FILE.', so left as they are: '.implode(', ', $plan['unlisted']).'.');
                $this->newLine();
            }

            $this->line('  <fg=gray>A deploy applies the PHP version. The rest is shown here so you can check it; Brewless does not change it from '.Project::FILE.' yet.</>');

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function environment(array $environment): void
    {
        $this->newLine();
        $this->line('  <options=bold>'.$environment['name'].'</>'.($environment['exists'] ? '' : ' <fg=yellow>(not at Brewless yet)</>'));

        foreach ($environment['lines'] ?? [] as $line) {
            $state = (string) ($line['state'] ?? 'unknown');
            $what = match ($state) {
                'same' => (string) ($line['wanted'] ?? ''),
                'change' => ($line['current'] ?? '–').' → '.($line['wanted'] ?? '–'),
                'add' => ($line['key'] === 'environment' ? 'a new '.$line['wanted'].' environment' : 'add '.($line['wanted'] ?? '')),
                'kept' => ($line['current'] ?? '').' is not in '.Project::FILE.'; it stays',
                default => ($line['wanted'] ?? '').' (what stands cannot be read)',
            };

            $this->line(sprintf('  %s %-24s %s', self::MARKS[$state] ?? '?', $line['key'], $what));
        }

        $this->newLine();
    }

    /**
     * What Brewless refused in the file, each with the line it is on.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function refused(Manifest $manifest, array $errors): void
    {
        $this->components->error(Project::FILE.' cannot be used as it is.');

        foreach ($errors as $field => $messages) {
            $path = preg_replace('/^manifest\.?/', '', $field) ?? $field;
            $line = $path === '' ? null : $manifest->lineOf($path);

            foreach ($messages as $message) {
                $this->line('  '.($line === null ? '' : '<fg=gray>line '.$line.':</> ').$message);
            }
        }
    }

    /**
     * The project's application at Brewless.
     *
     * @return array<string, mixed>
     */
    private function application(Api $api, Project $project): array
    {
        foreach ($api->get('/api/applications')['data'] ?? [] as $application) {
            if (($application['slug'] ?? null) === $project->application) {
                return $application;
            }
        }

        throw new RuntimeException('The organisation '.$project->organisation.' has no application "'.$project->application.'". Check brewless.yml, or run brewless init.');
    }
}
