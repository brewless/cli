<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The vapor.yml of a project that runs on Laravel Vapor, read for what a move
 * to Brewless should start from: which environments there are, what each one
 * had (a database, a queue, file storage, domains), and the brewless.yml that
 * says the same wherever Brewless has a setting for it. What cannot be carried
 * over is said in plain words (warnings()). Nothing at Vapor or AWS is read or
 * touched.
 *
 * The names and bounds below are those of the app's ManifestSchema, so the
 * file that is written passes `brewless plan`.
 */
final class Vapor
{
    public const string FILE = 'vapor.yml';

    private const string ENVIRONMENT_NAME = '/^[a-z][a-z0-9-]{1,29}$/';

    private const string QUEUE_NAME = '/^[a-z0-9][a-z0-9_-]{0,79}$/';

    private const string DOMAIN = '/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    private const string DATABASE_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/';

    private const string BUCKET_NAME = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    /** An environment named after a kind gets that kind; any other name is a place to try things. */
    private const array KINDS = ['production', 'staging', 'development'];

    /** The oldest PHP version Brewless builds for. */
    private const string OLDEST_PHP = '8.3';

    private const array MEMORY = [128, 12288];

    private const array TIMEOUT = [10, 3600];

    private const array TMP_STORAGE = [1000, 24456];

    /** Local disk is at most this many megabytes per megabyte of memory. */
    private const int TMP_STORAGE_PER_MEGABYTE = 4;

    private const int MOST_REPLICAS = 50;

    /** What the web container gets when brewless.yml does not say. */
    private const int WEB_MEMORY = 512;

    private const int WEB_MAX = 2;

    /** Requests one replica takes at once before another one starts (`web.concurrency`'s default); a Lambda container takes one. */
    private const int REQUESTS_PER_REPLICA = 50;

    /** Release commands get this long per release, whatever the timeout of the release container. */
    private const int RELEASE_SECONDS = 90;

    /** Keys of an environment that are read below, or that say nothing a move needs. */
    private const array READ = [
        'runtime', 'memory', 'timeout', 'warm', 'concurrency', 'tmp-storage', 'cli-memory', 'cli-timeout',
        'queues', 'queue-memory', 'queue-timeout', 'queue-concurrency', 'scheduler', 'build', 'deploy',
        'domain', 'database', 'storage', 'docker-build-args', 'docker-build-options', 'dockerfile', 'firewall',
    ];

    /** Vapor counts a rate limit per address over five minutes; Brewless over ten seconds, Shield Basic's longest window. */
    private const int VAPOR_RATE_WINDOWS = 30;

    /** Keys of an environment Brewless has no setting for, with what to do instead. */
    private const array NOT_CARRIED = [
        'cache' => 'Not carried over: add `cache: <name>` under the environment in brewless.yml for a Scaleway Managed Database for Redis, reached over the Private Network.',
        'network' => 'Not carried over: add `network: private` under the environment in brewless.yml to put its containers on the Private Network of your Scaleway project.',
        'balancer' => 'Brewless has no load balancer to name: visitors reach the web container through bunny.net, or its own address without it.',
        'gateway-version' => 'There is no API Gateway at Brewless: visitors reach the web container through bunny.net, or its own address without it.',
        'octane' => 'Brewless serves the application with FrankenPHP in its classic mode, without Octane.',
        'octane-database-session-persist' => 'Brewless serves the application with FrankenPHP in its classic mode, without Octane.',
        'octane-database-session-ttl' => 'Brewless serves the application with FrankenPHP in its classic mode, without Octane.',
        'database-user' => 'The user the application signs in to its database with is in its variables (`DB_USERNAME`), not in brewless.yml.',
        'database-proxy' => 'Brewless has no database proxy.',
        'layers' => 'Brewless has no Lambda layers: the image is built on Brewless\'s public base image.',
        'separate-vendor' => 'There is no package size to stay under: the image holds the vendor directory.',
    ];

    /**
     * @param  array<string, array{database: bool, queue: bool, bucket: bool, domains: list<string>}>  $environments  by name, in the file's order
     * @param  array<string, mixed>  $data  the whole file, as it was read
     */
    public function __construct(
        public readonly array $environments,
        public readonly ?string $php = null,
        private readonly array $data = [],
        private readonly string $directory = '',
    ) {}

    public static function read(string $directory): ?self
    {
        if (! is_file($directory.'/'.self::FILE)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($directory.'/'.self::FILE);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($data) || ! is_array($data['environments'] ?? null)) {
            return null;
        }

        $environments = [];
        $php = null;

        foreach (self::usableEnvironments($data) as $name => $settings) {
            $version = self::phpOf($settings);

            // A version Brewless does not build for is said by warnings(), not written.
            if ($version !== null && version_compare($version, self::OLDEST_PHP, '>=')) {
                $php = $name === 'production' ? $version : ($php ?? $version);
            }

            $environments[$name] = [
                'database' => is_string($settings['database'] ?? null) && $settings['database'] !== '',
                // Vapor gives every environment a queue unless it says `queues: false`.
                'queue' => ($settings['queues'] ?? true) !== false,
                'bucket' => is_string($settings['storage'] ?? null) && $settings['storage'] !== '',
                'domains' => self::domainsOf($settings),
            ];
        }

        /** @var array<string, mixed> $data */
        return $environments === [] ? null : new self($environments, $php, $data, $directory);
    }

    /**
     * What an environment had at Vapor, as a plan for setting it up at
     * Brewless. A database is made new: moving its data is not done here.
     *
     * @return array{database: string, database_resource: null, database_name: null, queue: string, bucket: bool}|null
     */
    public function setup(string $environment): ?array
    {
        $settings = $this->environments[$environment] ?? null;

        if ($settings === null) {
            return null;
        }

        return [
            'database' => $settings['database'] ? 'new' : 'none',
            'database_resource' => null,
            'database_name' => null,
            'queue' => $settings['queue'] ? 'sqs' : 'none',
            'bucket' => $settings['bucket'],
        ];
    }

    /**
     * What brewless.yml says besides which application it is: the build and
     * release steps and every environment of vapor.yml, with what Brewless has
     * a setting for.
     *
     * @param  array<string, string>  $regions  environment => the region it was added in
     * @return array<string, mixed>
     */
    public function manifest(string $framework, array $regions = []): array
    {
        return $this->translate($framework, $regions)['manifest'];
    }

    /**
     * What could not be carried over, or is carried differently, each once:
     * the keys and the environments it is about, then what to do instead.
     *
     * @return list<string>
     */
    public function warnings(string $framework): array
    {
        $grouped = [];

        foreach ($this->translate($framework)['warnings'] as $warning) {
            $grouped[$warning['message']]['keys'][$warning['key']] = true;
            $grouped[$warning['message']]['environments'] ??= [];

            if ($warning['environment'] !== null) {
                $grouped[$warning['message']]['environments'][$warning['environment']] = true;
            }
        }

        $lines = [];

        foreach ($grouped as $message => $group) {
            $where = $group['environments'] === [] ? '' : ' ('.implode(', ', array_keys($group['environments'])).')';
            $lines[] = '`'.implode('`, `', array_keys($group['keys'])).'`'.$where.': '.$message;
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $regions
     * @return array{manifest: array<string, mixed>, warnings: list<array{key: string, environment: string|null, message: string}>}
     */
    private function translate(string $framework, array $regions = []): array
    {
        $warnings = [];
        $warn = static function (string $key, ?string $environment, string $message) use (&$warnings): void {
            $warnings[] = ['key' => $key, 'environment' => $environment, 'message' => $message];
        };

        foreach ($this->data as $key => $value) {
            match ((string) $key) {
                'id', 'name', 'environments', 'default-environment' => null,
                'ignore' => $warn('ignore', null, 'Brewless deploys what is committed, packed with `git archive`: leave paths out with `export-ignore` in `.gitattributes`.'),
                default => $warn((string) $key, null, 'Brewless has no such setting.'),
            };
        }

        $usable = self::usableEnvironments($this->data);

        foreach (array_keys(is_array($this->data['environments'] ?? null) ? $this->data['environments'] : []) as $name) {
            if (! isset($usable[strtolower((string) $name)])) {
                $warn((string) $name, null, 'An environment name at Brewless is 2 to 30 lowercase letters, digits and dashes, starting with a letter. This environment is left out.');
            }
        }

        $environments = [];
        $builds = [];
        $releases = [];
        $done = [];

        foreach ($usable as $name => $settings) {
            $environments[$name] = $this->environment($name, $settings, $regions[$name] ?? null, $warn);
            $builds[$name] = $this->buildSteps($settings, $framework, $done);
            $releases[$name] = $this->releaseCommands($name, $settings, $warn);
        }

        if ($done !== []) {
            $warn('build', null, 'Left out, as Brewless does it itself: '.implode(', ', array_keys($done)).'.');
        }

        $manifest = [];
        $default = $this->data['default-environment'] ?? null;

        if (is_string($default) && isset($environments[$default])) {
            $manifest['default-environment'] = $default;
        }

        $build = $this->oneForAll('build', $builds, $warn);
        $release = $this->oneForAll('deploy', $releases, $warn);

        if ($build !== null && $build !== []) {
            $manifest['build'] = $build;
        }

        if ($release !== null && $release !== []) {
            $manifest['release'] = $release;
        }

        $manifest['environments'] = $environments;

        // One PHP version for all: the one read() chose, said when they differ.
        $versions = array_unique(array_filter(array_map(self::phpOf(...), $usable)));

        if (count($versions) > 1 && $this->php !== null) {
            $warn('runtime', null, 'brewless.yml has one PHP version for all environments: '.$this->php.', as '.(isset($usable['production']) ? 'production' : array_key_first($usable)).' has. Change `php` if another one should be built.');
        }

        return ['manifest' => $manifest, 'warnings' => $warnings];
    }

    /**
     * One environment of brewless.yml from its settings at Vapor.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  callable(string, string|null, string): void  $warn
     * @return array<string, mixed>
     */
    private function environment(string $name, array $settings, ?string $region, callable $warn): array
    {
        $environment = ['kind' => in_array($name, self::KINDS, true) ? $name : 'development'];

        if ($region !== null) {
            $environment['region'] = $region;
        }

        $runtime = $settings['runtime'] ?? null;

        if (is_string($runtime)) {
            $version = self::phpOf($settings);

            if (str_starts_with($runtime, 'docker')) {
                $warn('runtime', $name, 'Brewless builds the image itself, on its public base image; a Dockerfile of the project is not used. Set `php` for another PHP version than Brewless\'s default.');
            } elseif ($version !== null && version_compare($version, self::OLDEST_PHP, '<')) {
                $warn('runtime', $name, 'Brewless builds for PHP '.self::OLDEST_PHP.' and later, not for '.$version.'. Set `php` to the version your application can move to.');
            } elseif (str_contains($runtime, '-arm')) {
                $warn('runtime', $name, 'Brewless chooses the processor of the containers itself; the arm part of the runtime is not carried over.');
            }
        }

        $web = [];
        $memory = $this->size($settings, 'memory', self::MEMORY, 'web.memory', $name, $warn);

        if ($memory !== null) {
            $web['memory'] = $memory;
        }

        $timeout = $this->size($settings, 'timeout', self::TIMEOUT, 'web.timeout', $name, $warn);

        if ($timeout !== null) {
            $web['timeout'] = $timeout;
        }

        if (is_int($settings['warm'] ?? null) && $settings['warm'] > 0) {
            $web['min'] = min(self::MOST_REPLICAS, (int) ceil($settings['warm'] / self::REQUESTS_PER_REPLICA));
            $warn('warm', $name, 'Vapor kept '.$settings['warm'].' Lambda containers warm, each taking one request at a time; a replica at Brewless takes up to '.self::REQUESTS_PER_REPLICA.', so `web.min` is '.$web['min'].'. A replica that keeps running is paid for all the time; 0 lets the container sleep.');
        }

        if (is_int($settings['concurrency'] ?? null) && $settings['concurrency'] > 0) {
            $web['max'] = min(self::MOST_REPLICAS, (int) ceil($settings['concurrency'] / self::REQUESTS_PER_REPLICA));
            $warn('concurrency', $name, 'Vapor ran up to '.$settings['concurrency'].' requests at once, one per Lambda container; a replica at Brewless takes up to '.self::REQUESTS_PER_REPLICA.', so `web.max` is '.$web['max'].' replicas.');
        }

        // The most may not be below the least.
        if (isset($web['min']) && $web['min'] > ($web['max'] ?? self::WEB_MAX)) {
            $web['max'] = $web['min'];
        }

        $tmp = $settings['tmp-storage'] ?? null;

        if (is_int($tmp) && $tmp > self::TMP_STORAGE[0]) {
            $most = min(self::TMP_STORAGE[1], ($web['memory'] ?? self::WEB_MEMORY) * self::TMP_STORAGE_PER_MEGABYTE);
            $web['tmp-storage'] = min($tmp, $most);

            if ($tmp > $most) {
                $warn('tmp-storage', $name, 'Local disk is at most four times the memory of the container, so `web.tmp-storage` is '.$most.' instead of '.$tmp.'.');
            }
        }

        if ($web !== []) {
            $environment['web'] = $web;
        }

        $release = [];
        $cliMemory = $this->size($settings, 'cli-memory', self::MEMORY, 'release.memory', $name, $warn);
        $cliTimeout = $this->size($settings, 'cli-timeout', self::TIMEOUT, 'release.timeout', $name, $warn);

        if ($cliMemory !== null) {
            $release['memory'] = $cliMemory;
        }

        if ($cliTimeout !== null) {
            $release['timeout'] = $cliTimeout;

            if ($cliTimeout > self::RELEASE_SECONDS) {
                $warn('cli-timeout', $name, 'Release commands get '.self::RELEASE_SECONDS.' seconds per release at Brewless, whatever `release.timeout` says.');
            }
        }

        if ($release !== []) {
            $environment['release'] = $release;
        }

        $worker = [];
        $queueMemory = $this->size($settings, 'queue-memory', self::MEMORY, 'worker.memory', $name, $warn);
        $queueTimeout = $this->size($settings, 'queue-timeout', self::TIMEOUT, 'worker.timeout', $name, $warn);

        if ($queueMemory !== null) {
            $worker['memory'] = $queueMemory;
        }

        if ($queueTimeout !== null) {
            $worker['timeout'] = $queueTimeout;
        }

        $queues = $this->queues($name, $settings['queues'] ?? null, $warn);

        if ($queues !== []) {
            $worker['queues'] = $queues;
        }

        if ($worker !== []) {
            $environment['worker'] = $worker;
        }

        if (isset($settings['queue-concurrency'])) {
            $warn('queue-concurrency', $name, 'Every minute a trigger calls the worker for the schedule and then the queue; there is no number of jobs at once to set.');
        }

        $scheduler = $settings['scheduler'] ?? null;

        if (is_bool($scheduler)) {
            $environment['scheduler'] = $scheduler;
        } elseif ($scheduler === 'sub-minute') {
            $environment['scheduler'] = true;
            $warn('scheduler', $name, 'Brewless runs the schedule every minute; there is no setting for more often, so `scheduler` is true.');
        }

        $database = $settings['database'] ?? null;

        if (is_string($database) && $database !== '') {
            if (preg_match(self::DATABASE_NAME, $database) === 1) {
                $environment['database'] = $database;
            } else {
                $warn('database', $name, 'A database name at Brewless is up to 63 letters, digits, dots, dashes and underscores. Name one in `database` yourself.');
            }
        }

        $environment['queue'] = ($settings['queues'] ?? true) === false ? false : 'sqs';

        $storage = $settings['storage'] ?? null;

        if (is_string($storage) && $storage !== '') {
            if (preg_match(self::BUCKET_NAME, $storage) === 1) {
                $environment['storage'] = $storage;
            } else {
                $warn('storage', $name, 'A bucket name at Brewless is 3 to 63 lowercase letters, digits, dots and dashes. Name one in `storage` yourself.');
            }
        }

        $domains = [];

        foreach (self::domainsOf($settings) as $domain) {
            if (preg_match(self::DOMAIN, strtolower($domain)) === 1) {
                $domains[] = strtolower($domain);
            } else {
                $warn('domain', $name, 'Not a domain name Brewless takes: '.$domain.'.');
            }
        }

        if ($domains !== []) {
            $environment['domains'] = array_values(array_unique($domains));
        }

        $firewall = is_array($settings['firewall'] ?? null) ? $settings['firewall'] : [];
        $rateLimit = $firewall['rate-limit'] ?? null;

        if (is_int($rateLimit) && $rateLimit > 0) {
            $environment['firewall'] = ['rate_limit' => (int) ceil($rateLimit / self::VAPOR_RATE_WINDOWS)];
            $warn('firewall.rate-limit', $name, 'Carried over as `firewall.rate_limit: '.$environment['firewall']['rate_limit'].'`: Brewless counts requests per address in 10 seconds, Vapor in 5 minutes. It needs bunny.net in front of the environment.');
        }

        if (isset($firewall['bot-control'])) {
            $warn('firewall.bot-control', $name, 'Not carried over: Brewless has `firewall.bot_control: challenge`, without categories. It takes Shield Advanced or higher at bunny.net, a paid plan billed by bunny.net.');
        }

        foreach ($settings as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::READ, true)) {
                continue;
            }

            $warn($key, $name, self::NOT_CARRIED[$key] ?? 'Brewless has no such setting.');
        }

        return $environment;
    }

    /**
     * A size or a timeout, kept within what Brewless takes.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  array{int, int}  $bounds
     * @param  callable(string, string|null, string): void  $warn
     */
    private function size(array $settings, string $key, array $bounds, string $target, string $environment, callable $warn): ?int
    {
        $value = $settings[$key] ?? null;

        if (! is_int($value)) {
            return null;
        }

        $kept = max($bounds[0], min($bounds[1], $value));

        if ($kept !== $value) {
            $warn($key, $environment, '`'.$target.'` is from '.$bounds[0].' to '.$bounds[1].', so it is '.$kept.' instead of '.$value.'.');
        }

        return $kept;
    }

    /**
     * The names of the queues an environment works. Vapor takes a name, or a
     * name with the number of jobs it works at once.
     *
     * @param  callable(string, string|null, string): void  $warn
     * @return list<string>
     */
    private function queues(string $environment, mixed $queues, callable $warn): array
    {
        if (! is_array($queues)) {
            return [];
        }

        $names = [];
        $withConcurrency = false;

        foreach ($queues as $key => $queue) {
            if (is_string($key)) {
                $names[] = $key;
                $withConcurrency = true;
            } elseif (is_string($queue)) {
                $names[] = $queue;
            } elseif (is_array($queue)) {
                foreach (array_keys($queue) as $name) {
                    $names[] = (string) $name;
                    $withConcurrency = true;
                }
            }
        }

        if ($withConcurrency) {
            $warn('queues', $environment, 'The worker works the queues one after the other, the first first; there is no number of jobs at once per queue.');
        }

        $valid = [];

        foreach ($names as $name) {
            if (preg_match(self::QUEUE_NAME, $name) === 1) {
                $valid[] = $name;
            } else {
                $warn('queues', $environment, 'A queue name at Brewless is lowercase letters, digits, dashes and underscores: '.$name.' is left out.');
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * The build steps of an environment that Brewless does not run already.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  array<string, true>  $done  the steps that were left out, added to
     * @return list<string>|null
     */
    private function buildSteps(array $settings, string $framework, array &$done): ?array
    {
        if (! is_array($settings['build'] ?? null)) {
            return null;
        }

        $steps = [];

        foreach ($settings['build'] as $step) {
            if (! is_string($step) || trim($step) === '') {
                continue;
            }

            $kept = [];

            foreach (array_map(trim(...), explode('&&', $step)) as $part) {
                if ($framework === 'laravel' && $this->builtAlready($part)) {
                    $done[$part] = true;
                } else {
                    $kept[] = $part;
                }
            }

            if ($kept !== []) {
                $steps[] = implode(' && ', $kept);
            }
        }

        return $steps;
    }

    /**
     * Whether Brewless does a build step already for a Laravel application:
     * it installs the Composer packages, builds the frontend when there is a
     * package-lock.json, leaves node_modules out of the image and caches the
     * configuration, routes and views when a container starts.
     */
    private function builtAlready(string $step): bool
    {
        $frontend = is_file($this->directory.'/package-lock.json')
            && preg_match('/^npm (ci|install|i|run build)(\s|$)/', $step) === 1;

        return $frontend
            || preg_match('/^composer install(\s|$)/', $step) === 1
            || preg_match('/^rm -rf (\.\/)?node_modules\/?$/', $step) === 1
            || preg_match('/^php artisan (config|route|view):cache$/', $step) === 1;
    }

    /**
     * The deploy hooks of an environment as release commands: a command of
     * the framework's console, without `php artisan` in front.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  callable(string, string|null, string): void  $warn
     * @return list<string>|null
     */
    private function releaseCommands(string $environment, array $settings, callable $warn): ?array
    {
        if (! is_array($settings['deploy'] ?? null)) {
            return null;
        }

        $commands = [];

        foreach ($settings['deploy'] as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            if (preg_match('/^php artisan\s+(.+)$/', trim($command), $match) === 1) {
                $commands[] = trim($match[1]);
            } else {
                $warn('deploy', $environment, 'A release command is a command of the framework\'s console, so this one is left out: '.$command.'. Put it in `build` when it can run without the database.');
            }
        }

        return $commands;
    }

    /**
     * brewless.yml has one list of build steps and one of release commands
     * for all environments: production's, or else the first environment's.
     *
     * @param  array<string, list<string>|null>  $lists
     * @param  callable(string, string|null, string): void  $warn
     * @return list<string>|null
     */
    private function oneForAll(string $key, array $lists, callable $warn): ?array
    {
        $given = array_filter($lists, static fn (?array $list): bool => $list !== null);

        if ($given === []) {
            return null;
        }

        $from = array_key_exists('production', $given) ? 'production' : (string) array_key_first($given);
        $chosen = $given[$from];

        foreach ($given as $name => $list) {
            if ($list !== $chosen) {
                $warn($key, $name, 'brewless.yml has one list for all environments, so '.$from.'\'s is used. Add what this environment had besides, if '.$from.' may run it too.');
            }
        }

        return $chosen;
    }

    /**
     * The environments of the file whose names Brewless takes, by their
     * name in lowercase.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, array<array-key, mixed>>
     */
    private static function usableEnvironments(array $data): array
    {
        $environments = [];

        foreach (is_array($data['environments'] ?? null) ? $data['environments'] : [] as $name => $settings) {
            $name = strtolower((string) $name);

            if (preg_match(self::ENVIRONMENT_NAME, $name) === 1) {
                $environments[$name] = is_array($settings) ? $settings : [];
            }
        }

        return $environments;
    }

    /**
     * The PHP version of a runtime such as `php-8.4:al2023`; null for an
     * image of the project's own (`docker`).
     *
     * @param  array<array-key, mixed>  $settings
     */
    private static function phpOf(array $settings): ?string
    {
        $runtime = $settings['runtime'] ?? null;

        return is_string($runtime) && preg_match('/^php-(\d+\.\d+)/', $runtime, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return list<string>
     */
    private static function domainsOf(array $settings): array
    {
        $domains = $settings['domain'] ?? [];

        return array_values(array_filter(is_array($domains) ? $domains : [$domains], is_string(...)));
    }
}
