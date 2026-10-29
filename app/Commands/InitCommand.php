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
use Illuminate\Support\Sleep;
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
        {--name= : The name of a new application to create}
        {--project= : For a new application, with several Scaleway projects connected: the name or ID of the one it runs in}
        {--framework= : laravel, symfony or generic; detected from composer.json when left out}
        {--php= : The PHP version to build for, such as 8.4; left out, Brewless builds for its default}
        {--environments= : The environments to add, such as production,staging; "none" adds none}
        {--region= : Where new environments stand: fr-par, nl-ams, pl-waw or it-mil}
        {--database= : For every new environment: new, existing or none}
        {--database-resource= : The name of the existing database to use}
        {--queue= : For every new environment: sqs (the default), database (keeps the worker awake, costs more) or none}
        {--bucket : Make a private bucket for the files of every new environment}
        {--mail= : The mailer of a new production environment: scaleway (Transactional Email, the default), lettermint (your own account) or none}
        {--edge-cache= : How long the edge may keep the public pages of every new environment: 1h (the default), 1d, 0 for not at all, or a number of seconds, minutes (30m), hours or days, up to 7d}
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

            $listing = $api->get('/api/applications');
            $applications = $listing['data'] ?? [];
            $slug = $this->option('application');
            $newName = $this->option('name');

            if (! is_string($slug) && is_string($newName) && $newName !== '') {
                $slug = self::NEW_APPLICATION;
            }

            // Without a terminal nobody can pick from the list: say which option answers it.
            if (! is_string($slug) && ! $this->input->isInteractive()) {
                throw new RuntimeException('Say which application this is: --application=<slug> for one that exists, or --name=<name> to create a new one.');
            }

            if (! is_string($slug)) {
                $slug = select(
                    label: 'Which application is this?',
                    options: [...array_column($applications, 'slug'), self::NEW_APPLICATION],
                );
            }

            if ($slug === self::NEW_APPLICATION) {
                $name = is_string($newName) && $newName !== ''
                    ? $newName
                    : text(label: 'What is the application called?', default: basename($directory), required: true);
                $project = $this->project($listing['options']['projects'] ?? []);
                $application = $api->post('/api/applications', ['name' => $name, 'framework' => $framework, ...($project === null ? [] : ['provider_connection_id' => $project])])['data'] ?? [];
                $slug = (string) ($application['slug'] ?? '');

                $this->components->info('Created the application '.$slug.'.');
                $this->awaitProject($api, $application);
            } else {
                $application = array_find($applications, static fn (array $candidate): bool => ($candidate['slug'] ?? null) === $slug)
                    ?? throw new RuntimeException('The organisation '.$organisation.' has no application "'.$slug.'".');
            }

            $php = $this->option('php') ?? $vapor?->php;
            $project = new Project($organisation, (string) $slug, $framework, is_string($php) && $php !== '' ? $php : null);

            $project->write($directory, $vapor?->manifest($framework) ?? []);

            $this->components->info('Wrote brewless.yml. Commit it; it holds no secrets.');

            if ($vapor instanceof Vapor) {
                $this->carriedFromVapor($vapor, $framework);
            }

            $added = $this->addEnvironments($api, $application, $vapor);

            if ($vapor instanceof Vapor && $added !== []) {
                // The environments that were added stand in a region now: the file says which.
                $regions = array_column(array_filter($added, static fn (array $environment): bool => is_string($environment['region'] ?? null)), 'region', 'slug');
                $project->write($directory, $vapor->manifest($framework, $regions));
            }

            $mail = $this->mail($added);
            $edge = $this->edgeCache($framework, $added);

            if ($mail !== [] || $edge !== []) {
                $manifest = $vapor?->manifest($framework, array_column(array_filter($added, static fn (array $environment): bool => is_string($environment['region'] ?? null)), 'region', 'slug')) ?? [];

                foreach ($mail as $slug => $mailer) {
                    $manifest['environments'][$slug]['mail'] = $mailer;
                }

                foreach ($edge as $slug => $cache) {
                    $manifest['environments'][$slug]['edge'] = ['cache' => $cache];
                }

                $project->write($directory, $manifest);

                if ($mail !== []) {
                    $this->components->info('brewless.yml sets the mailer of '.implode(' and ', array_keys($mail)).'; the first deploy sets it up.');
                }

                if ($edge !== []) {
                    $this->edgeCacheSaid($directory, $edge);
                }
            }

            $ready = $added === [] ? [] : $this->setEnvironmentsUp($api, $added, $vapor);

            if ($vapor instanceof Vapor && $added !== []) {
                $this->leftFromVapor($vapor, array_column($added, 'slug'), $ready);
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
     * The mailer each new production environment gets in brewless.yml:
     * Scaleway Transactional Email unless asked otherwise, Lettermint with
     * the customer's own account, or none (`mail: false`), which keeps a
     * MAIL_MAILER of the application's own. Other kinds get no mailer.
     *
     * @param  list<array<string, mixed>>  $added
     * @return array<string, string|false> environment => mailer
     */
    private function mail(array $added): array
    {
        $production = array_values(array_filter(array_column(array_filter($added, static fn (array $environment): bool => ($environment['kind'] ?? null) === 'production'), 'slug'), is_string(...)));

        if ($production === []) {
            return [];
        }

        $choice = $this->option('mail') ?? ($this->input->isInteractive() ? select(
            label: 'Which mailer should '.implode(' and ', $production).' send with?',
            options: [
                'scaleway' => 'Scaleway Transactional Email, in your Scaleway project (the default)',
                'lettermint' => 'Lettermint, with your own account',
                'none' => 'None: keep the MAIL_MAILER of the application',
            ],
            default: 'scaleway',
        ) : 'scaleway');

        if (! in_array($choice, ['scaleway', 'lettermint', 'none'], true)) {
            throw new RuntimeException('Unknown mailer "'.$choice.'". Use one of: scaleway, lettermint, none.');
        }

        return array_fill_keys($production, $choice === 'none' ? false : $choice);
    }

    /**
     * How long the edge may keep the public pages of each new environment
     * (`edge.cache` in brewless.yml): what --edge-cache says, else asked
     * once for all of them, else an hour. Only a Laravel application is
     * asked, because brewless/laravel is what reads it; another framework
     * gets it only when --edge-cache names it.
     *
     * @param  list<array<string, mixed>>  $added
     * @return array<string, int|string> environment => cache time as brewless.yml writes it
     */
    private function edgeCache(string $framework, array $added): array
    {
        $environments = array_values(array_filter(array_column($added, 'slug'), is_string(...)));
        $given = $this->option('edge-cache');

        if ($environments === [] || (! is_string($given) && $framework !== 'laravel')) {
            return [];
        }

        $choice = is_string($given) ? $given : ($this->input->isInteractive() ? (string) select(
            label: 'May the edge keep public pages, and for how long?',
            options: [
                '1h' => '1 hour (recommended): every deploy empties the edge, so a page is at most an hour old between deploys',
                '1d' => '1 day: the container sleeps most, a page from the database can be a day old',
                'none' => 'Not at all',
            ],
            default: '1h',
            hint: 'Only pages that are the same for every visitor: no session, no cookie, nobody signed in.',
        ) : '1h');

        return array_fill_keys($environments, self::cacheTime($choice === 'none' ? '0' : $choice));
    }

    /**
     * A cache time as brewless.yml takes it: `0`, a number of seconds, or a
     * number with m, h or d. Brewless refuses more than seven days.
     */
    private static function cacheTime(string $given): int|string
    {
        $value = strtolower(trim($given));

        if (preg_match('/\A(\d+)([mhd]?)\z/', $value, $parts) !== 1) {
            throw new RuntimeException('Unknown edge cache time "'.$given.'". Use 0, a number of seconds, or a number with m, h or d, such as 1h or 1d.');
        }

        $seconds = (int) $parts[1] * match ($parts[2]) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 1,
        };

        if ($seconds > 7 * 86400) {
            throw new RuntimeException('The edge keeps a page at most 7 days; "'.$given.'" is longer.');
        }

        return $parts[2] === '' ? $seconds : $value;
    }

    /**
     * Say what brewless.yml now says about the edge, and that an older
     * brewless/laravel still sends a session cookie on every page.
     *
     * @param  array<string, int|string>  $edge
     */
    private function edgeCacheSaid(string $directory, array $edge): void
    {
        $cache = array_values($edge)[0];

        if ($cache === 0) {
            $this->components->info('brewless.yml keeps the public pages of '.implode(' and ', array_keys($edge)).' out of the edge.');

            return;
        }

        $this->components->info('brewless.yml lets the edge keep the public pages of '.implode(' and ', array_keys($edge)).' for '.$cache.(is_int($cache) ? ' seconds' : '').'.');

        $version = self::lockedVersion($directory, 'brewless/laravel');

        if ($version !== null && version_compare(ltrim($version, 'v'), '0.18.0', '<')) {
            $this->line('  brewless/laravel '.$version.' sends a session cookie on every page in the web group, so the edge keeps none of them. From 0.18 a page that needs no session goes without: composer require brewless/laravel:^0.18');
        }
    }

    /**
     * The version composer.lock holds of a package, or null.
     */
    private static function lockedVersion(string $directory, string $package): ?string
    {
        $lock = json_decode((string) @file_get_contents($directory.'/composer.lock'), true);

        foreach (is_array($lock) ? [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $package && is_string($candidate['version'] ?? null)) {
                return $candidate['version'];
            }
        }

        return null;
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
     * What brewless.yml says now that it starts from vapor.yml, and what of
     * vapor.yml it could not say, each once.
     */
    private function carriedFromVapor(Vapor $vapor, string $framework): void
    {
        $this->line('  It holds the environments of vapor.yml and their settings. See them next to what stands: brewless plan');

        $warnings = $vapor->warnings($framework);

        if ($warnings === []) {
            return;
        }

        $this->newLine();
        $this->line('  Not carried over from vapor.yml as it was:');

        foreach ($warnings as $warning) {
            $this->line('  - '.$warning);
        }

        $this->newLine();
    }

    /**
     * What a move from Vapor still asks of a person: Brewless does not read AWS.
     *
     * @param  list<string>  $environments
     * @param  list<string>  $ready  the environments that are set up now
     */
    private function leftFromVapor(Vapor $vapor, array $environments, array $ready): void
    {
        $this->newLine();
        $this->line('  Still yours to move from Vapor:');
        // What Brewless wrote points at the new database, queue and bucket: the variables from Vapor are added to it, not put over it.
        $this->line('  - the variables: `brewless env:pull <environment>`, add yours from Vapor, then `brewless env:push <environment>`');

        if (array_any($ready, static fn (string $name): bool => ($vapor->environments[$name]['database'] ?? false) || ($vapor->environments[$name]['bucket'] ?? false))) {
            $this->line('  - brewless.yml names the databases and buckets as vapor.yml does; the ones made now have names of their own. `brewless plan` shows both: write the names that stand in brewless.yml');
        }

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

    /**
     * A key that may make projects gives a new application a Scaleway project
     * of its own: wait for it and say which. Init goes on either way; when it
     * did not work, setting the first environment up makes it.
     *
     * @param  array<string, mixed>  $application
     */
    private function awaitProject(Api $api, array $application): void
    {
        $operation = is_array($application['project_operation'] ?? null) ? $application['project_operation'] : null;

        if ($operation === null || ! is_string($operation['id'] ?? null)) {
            return;
        }

        $this->line('  Making its Scaleway project…');

        for ($look = 0; $look < 60 && in_array($operation['status'] ?? '', ['queued', 'running'], true); $look++) {
            Sleep::for(2)->seconds();

            $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
        }

        $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];

        if (($operation['status'] ?? '') === 'succeeded' && is_string($result['project_id'] ?? null)) {
            $name = is_string($result['project_name'] ?? null) ? $result['project_name'] : $result['project_id'];
            $this->components->info('Made the Scaleway project '.$name.': everything of this application runs there.');

            return;
        }

        $this->components->warn('Its Scaleway project is not made yet.');

        if (is_string($operation['error_message'] ?? null)) {
            $this->line('  '.$operation['error_message']);
        }

        $this->line('  Setting up its first environment tries again.');
    }

    /**
     * The Scaleway project a new application runs in, when the organisation
     * connected several: the one named with --project, or the one chosen.
     * With one or none the server knows, and nothing is asked.
     *
     * @param  array<array-key, mixed>  $projects
     */
    private function project(array $projects): ?string
    {
        $names = [];

        foreach ($projects as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && is_string($project['name'] ?? null)) {
                $names[$project['id']] = $project['name'];
            }
        }

        if (count($names) < 2) {
            return null;
        }

        $given = $this->option('project');

        if (is_string($given) && $given !== '') {
            foreach ($names as $id => $name) {
                if ($given === $id || strcasecmp($given, $name) === 0) {
                    return $id;
                }
            }

            throw new RuntimeException('There is no Scaleway project "'.$given.'". Connected: '.implode(', ', $names).'.');
        }

        return (string) select(label: 'Which Scaleway project does it run in?', options: $names);
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
