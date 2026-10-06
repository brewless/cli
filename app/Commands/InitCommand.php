<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Credentials;
use App\Support\Framework;
use App\Support\Project;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class InitCommand extends Command
{
    use TalksToBrewless;

    private const string NEW_APPLICATION = '+ a new application';

    protected $signature = 'init
        {--organisation= : The organisation the application belongs to}
        {--application= : The slug of an application that exists already}
        {--framework= : laravel, symfony or generic; detected from composer.json when left out}
        {--force : Overwrite an existing brewless.yml}';

    protected $description = 'Connect this project to an application at Brewless';

    public function handle(Credentials $credentials): int
    {
        return $this->attempt(function () use ($credentials): int {
            $directory = (string) getcwd();

            if (Project::exists($directory) && ! $this->option('force') && ! $this->confirm('There is a brewless.yml here already. Write a new one?')) {
                return self::SUCCESS;
            }

            $framework = $this->framework($directory);
            $organisation = $this->organisation($credentials);
            $api = $this->api($organisation);

            $applications = $api->get('/api/applications')['data'] ?? [];
            $slug = $this->option('application');

            if (! is_string($slug)) {
                $slug = select(
                    label: 'Which application is this?',
                    options: [...array_column($applications, 'slug'), self::NEW_APPLICATION],
                );
            }

            if ($slug === self::NEW_APPLICATION) {
                $name = text(label: 'What is the application called?', default: basename($directory), required: true);
                $slug = (string) ($api->post('/api/applications', ['name' => $name, 'framework' => $framework])['data']['slug'] ?? '');

                $this->components->info('Created the application '.$slug.'.');
            } elseif (! in_array($slug, array_column($applications, 'slug'), true)) {
                throw new RuntimeException('The organisation '.$organisation.' has no application "'.$slug.'".');
            }

            (new Project($organisation, (string) $slug, $framework))->write($directory);

            $this->components->info('Wrote brewless.yml. Commit it; it holds no secrets.');
            $this->line('  Next: brewless deploy <environment>');

            return self::SUCCESS;
        });
    }

    private function framework(string $directory): string
    {
        $framework = $this->option('framework') ?? Framework::detect($directory);

        if (! in_array($framework, Framework::KNOWN, true)) {
            throw new RuntimeException('Unknown framework "'.$framework.'". Use one of: '.implode(', ', Framework::KNOWN).'.');
        }

        $this->line('  Framework: <options=bold>'.$framework.'</>'.($this->option('framework') ? '' : ' (from composer.json; overrule with --framework=)'));

        return (string) $framework;
    }

    private function organisation(Credentials $credentials): string
    {
        if (is_string($this->option('organisation'))) {
            return strtolower($this->option('organisation'));
        }

        $signedIn = $credentials->organisations((string) config('brewless.host'));

        return match (count($signedIn)) {
            0 => throw new RuntimeException('This machine is not signed in to any organisation. Run: brewless login'),
            1 => $signedIn[0],
            default => (string) select(label: 'Which organisation?', options: $signedIn),
        };
    }
}
