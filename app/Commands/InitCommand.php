<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\SetsEnvironmentsUp;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\Credentials;
use App\Support\Framework;
use App\Support\Project;
use App\Support\Vapor;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class InitCommand extends Command
{
    use SetsEnvironmentsUp;
    use TalksToBrewless;

    private const string NEW_APPLICATION = '+ a new application';

    private const array KINDS = ['production', 'staging', 'development'];

    private const array REGIONS = ['fr-par' => 'Paris', 'nl-ams' => 'Amsterdam', 'pl-waw' => 'Warsaw', 'it-mil' => 'Milan'];

    protected $signature = 'init
        {--organisation= : The organisation the application belongs to}
        {--application= : The slug of an application that exists already}
        {--framework= : laravel, symfony or generic; detected from composer.json when left out}
        {--php= : The PHP version to build for, such as 8.4; left out, Brewless builds for its default}
        {--environments= : The environments to add, such as production,staging; "none" adds none}
        {--region= : Where new environments stand: fr-par, nl-ams, pl-waw or it-mil}
        {--database= : For every new environment: new, existing or none}
        {--database-resource= : The name of the existing database to use}
        {--queue= : For every new environment: database, sqs or none}
        {--bucket : Make a private bucket for the files of every new environment}
        {--from-vapor : Start from the vapor.yml of this project without asking}
        {--yes : Set the new environments up without asking first}
        {--force : Overwrite an existing brewless.yml}';

    protected $description = 'Connect this project to an application at Brewless and set its environments up';

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
            $vapor = $this->vapor($directory);

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
                $application = $api->post('/api/applications', ['name' => $name, 'framework' => $framework])['data'] ?? [];
                $slug = (string) ($application['slug'] ?? '');

                $this->components->info('Created the application '.$slug.'.');
            } else {
                $application = array_find($applications, static fn (array $candidate): bool => ($candidate['slug'] ?? null) === $slug)
                    ?? throw new RuntimeException('The organisation '.$organisation.' has no application "'.$slug.'".');
            }

            $php = $this->option('php') ?? $vapor?->php;

            (new Project($organisation, (string) $slug, $framework, is_string($php) && $php !== '' ? $php : null))->write($directory);

            $this->components->info('Wrote brewless.yml. Commit it; it holds no secrets.');

            $added = $this->addEnvironments($api, $application, $vapor);
            $ready = $added === [] ? [] : $this->setEnvironmentsUp($api, $added, $vapor);

            if ($vapor instanceof Vapor && $added !== []) {
                $this->leftFromVapor($vapor, array_column($added, 'slug'));
            }

            $first = $ready[0] ?? $added[0]['slug'] ?? $application['environments'][0]['slug'] ?? '<environment>';
            $this->line('  Next: brewless '.($added !== [] && $ready === [] ? 'provision ' : 'deploy ').$first);

            return self::SUCCESS;
        });
    }

    /**
     * The vapor.yml of the project, when there is one and it may be the starting point.
     */
    private function vapor(string $directory): ?Vapor
    {
        $vapor = Vapor::read($directory);

        if (! $vapor instanceof Vapor) {
            return null;
        }

        if ($this->option('from-vapor') === true) {
            return $vapor;
        }

        return $this->input->isInteractive() && confirm('There is a vapor.yml here ('.implode(', ', array_keys($vapor->environments)).'). Start from it, to move this application from Vapor to Brewless?')
            ? $vapor
            : null;
    }

    /**
     * Add the environments the application should have and does not have yet.
     *
     * @param  array<string, mixed>  $application
     * @return list<array<string, mixed>> the environments that were added
     */
    private function addEnvironments(Api $api, array $application, ?Vapor $vapor): array
    {
        $existing = array_column($application['environments'] ?? [], 'slug');
        $given = $this->option('environments');

        $suggested = array_values(array_diff($vapor instanceof Vapor ? array_keys($vapor->environments) : ['production', 'staging'], $existing));

        if (is_string($given)) {
            $wanted = $given === 'none' ? [] : array_values(array_filter(array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $given))));
        } elseif (! $this->input->isInteractive()) {
            // A script that names no environments gets none, unless it said to follow vapor.yml.
            $wanted = $vapor instanceof Vapor ? $suggested : [];
        } else {
            $options = array_values(array_diff(array_unique([...$suggested, ...self::KINDS]), $existing));

            /** @var list<string> $wanted */
            $wanted = $options === [] ? [] : multiselect(
                label: 'Which environments should it have?',
                options: $options,
                default: $suggested,
                hint: $existing === [] ? 'Space to pick, enter to go on. None is fine: add them later in the console.' : 'It has already: '.implode(', ', $existing).'.',
            );
        }

        $wanted = array_values(array_diff($wanted, $existing));

        if ($wanted === []) {
            return [];
        }

        $region = $this->option('region') ?? ($this->input->isInteractive() ? select(label: 'In which region?', options: self::REGIONS, default: 'fr-par') : 'fr-par');

        if (! isset(self::REGIONS[$region])) {
            throw new RuntimeException('Unknown region "'.$region.'". Use one of: '.implode(', ', array_keys(self::REGIONS)).'.');
        }

        $added = [];

        foreach ($wanted as $name) {
            $added[] = $api->post('/api/applications/'.$application['id'].'/environments', [
                'name' => ucfirst($name),
                // A name that is not a kind itself, such as "preview", is a place to try things.
                'kind' => in_array($name, self::KINDS, true) ? $name : 'development',
                'region' => $region,
            ])['data'] ?? [];
        }

        $this->components->info('Added '.implode(' and ', array_column($added, 'slug')).' in '.self::REGIONS[$region].'.');

        return $added;
    }

    /**
     * Ask what each new environment should have, say it back, and make it
     * once that is agreed to.
     *
     * @param  list<array<string, mixed>>  $environments
     * @return list<string> the environments that are set up now
     */
    private function setEnvironmentsUp(Api $api, array $environments, ?Vapor $vapor): array
    {
        if (! $this->input->isInteractive() && ! $this->setupWasGiven() && ! $vapor instanceof Vapor) {
            return [];
        }

        $plans = [];
        $before = null;

        foreach ($environments as $environment) {
            $slug = (string) ($environment['slug'] ?? '');

            $plan = match (true) {
                $this->setupWasGiven() => $this->setupFromOptions($api, $environment),
                $vapor?->setup($slug) !== null => $vapor->setup($slug),
                default => $this->askSetup($api, $environment, $before),
            };

            if ($plan === null) {
                return [];
            }

            $plans[$slug] = $before = $plan;
        }

        $this->newLine();
        $this->line('  This is made in your own Scaleway project:');

        foreach ($plans as $slug => $plan) {
            $this->line('  <options=bold>'.$slug.'</>  containers, registry and first variables, '.$this->describeSetup($plan));
        }

        $this->newLine();

        if ($this->option('yes') !== true && ! ($this->input->isInteractive() && confirm('Make this now?'))) {
            foreach ($plans as $slug => $plan) {
                $this->line('  Later: brewless provision '.$slug.' '.$this->setupAsOptions($plan));
            }

            return [];
        }

        $ready = [];

        foreach ($environments as $environment) {
            $slug = (string) ($environment['slug'] ?? '');

            $this->newLine();
            $this->line('  <options=bold>'.$slug.'</>');

            if ($this->setUp($api, $environment, $plans[$slug])) {
                $ready[] = $slug;
            } else {
                // What failed says why; the next one would meet the same, so it stops here.
                $this->line('  When that is settled: brewless provision '.$slug.' '.$this->setupAsOptions($plans[$slug]));

                break;
            }
        }

        return $ready;
    }

    /**
     * What a move from Vapor still asks of a person: Brewless does not read AWS.
     *
     * @param  list<string>  $environments
     */
    private function leftFromVapor(Vapor $vapor, array $environments): void
    {
        $this->newLine();
        $this->line('  Still yours to move from Vapor:');
        $this->line('  - the variables: `vapor env:pull <environment>`, then `brewless env:push <environment>`');

        if (array_any($environments, static fn (string $name): bool => $vapor->environments[$name]['database'] ?? false)) {
            $this->line('  - the data in your databases: the new ones are empty');
        }

        if (array_any($environments, static fn (string $name): bool => $vapor->environments[$name]['bucket'] ?? false)) {
            $this->line('  - the files in your S3 buckets');
        }

        foreach ($environments as $name) {
            foreach ($vapor->environments[$name]['domains'] ?? [] as $domain) {
                $this->line('  - brewless domain:add '.$name.' '.$domain);
            }
        }

        $this->newLine();
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
