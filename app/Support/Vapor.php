<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The vapor.yml of a project that runs on Laravel Vapor, read for what it
 * says a move to Brewless should start from: which environments there are,
 * the PHP version, and whether each one has a database, a queue and file
 * storage. Nothing at Vapor or AWS is read or touched.
 */
final class Vapor
{
    public const string FILE = 'vapor.yml';

    /**
     * @param  array<string, array{database: bool, queue: bool, bucket: bool, domains: list<string>}>  $environments  by name, in the file's order
     */
    public function __construct(public readonly array $environments, public readonly ?string $php = null) {}

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

        foreach ($data['environments'] as $name => $settings) {
            $settings = is_array($settings) ? $settings : [];
            $name = strtolower((string) $name);

            if (preg_match('/^[a-z][a-z0-9-]{1,59}$/', $name) !== 1) {
                continue;
            }

            // `php-8.3:al2`, or `docker` for an image of the project's own.
            if (is_string($settings['runtime'] ?? null) && preg_match('/^php-(\d+\.\d+)/', $settings['runtime'], $match) === 1) {
                $php = $name === 'production' ? $match[1] : ($php ?? $match[1]);
            }

            $domains = $settings['domain'] ?? [];

            $environments[$name] = [
                'database' => is_string($settings['database'] ?? null) && $settings['database'] !== '',
                // Vapor gives every environment a queue unless it says `queues: false`.
                'queue' => ($settings['queues'] ?? true) !== false,
                'bucket' => is_string($settings['storage'] ?? null) && $settings['storage'] !== '',
                'domains' => array_values(array_filter(is_array($domains) ? $domains : [$domains], is_string(...))),
            ];
        }

        return $environments === [] ? null : new self($environments, $php);
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
}
