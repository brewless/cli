<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Whether a newer client exists, and how this installation gets it. Looked up
 * at most once a day and never in the way: an unreachable Packagist or a
 * failed upgrade leaves the command that was run untouched.
 */
final class Updates
{
    private const string PACKAGE = 'brewless/cli';

    private const string VERSIONS = 'https://repo.packagist.org/p2/brewless/cli.json';

    private const string DOWNLOAD = 'https://github.com/brewless/cli/releases/download/v%s/%s';

    private const int ONCE_EVERY = 86400;

    public function __construct(private readonly Credentials $credentials) {}

    /**
     * The newest released version when it is newer than the running one and
     * nobody was told about it in the last day.
     */
    public function newerThan(string $current): ?string
    {
        if (! $this->isRelease($current) || $this->checkedRecently()) {
            return null;
        }

        $latest = $this->latest();
        $this->remember($latest);

        return $latest !== null && version_compare($latest, ltrim($current, 'v'), '>') ? $latest : null;
    }

    /**
     * How the client at this path was installed, and with that how it is upgraded.
     *
     * @return array{kind: 'composer'|'file', directory: string, command: list<string>}
     */
    public function plan(string $path, string $version): array
    {
        $marker = '/vendor/'.self::PACKAGE.'/';
        $position = strpos($path, $marker);

        if ($position === false) {
            return ['kind' => 'file', 'directory' => dirname($path), 'command' => []];
        }

        $directory = substr($path, 0, $position);
        $manifest = json_decode((string) @file_get_contents($directory.'/composer.json'), true);
        $isDevelopment = is_array($manifest) && isset($manifest['require-dev'][self::PACKAGE]);

        return [
            'kind' => 'composer',
            'directory' => $directory,
            'command' => array_values(array_filter(['composer', 'require', $isDevelopment ? '--dev' : null, self::PACKAGE.':^'.$version])),
        ];
    }

    /**
     * Put the new version in place of the one at this path.
     *
     * @param  array{kind: 'composer'|'file', directory: string, command: list<string>}  $plan
     * @param  callable(string): void  $write
     */
    public function upgrade(array $plan, string $path, string $version, callable $write): bool
    {
        if ($plan['kind'] === 'composer') {
            return Process::path($plan['directory'])->timeout(300)
                ->run($plan['command'], fn (string $type, string $output) => $write($output))
                ->successful();
        }

        return $this->replace($path, $version);
    }

    /**
     * What to type when the upgrade could not be done for someone.
     *
     * @param  array{kind: 'composer'|'file', directory: string, command: list<string>}  $plan
     */
    public function byHand(array $plan, string $path, string $version): string
    {
        return $plan['kind'] === 'composer'
            ? 'cd '.$plan['directory'].' && '.implode(' ', $plan['command'])
            : 'curl -fsSL -o '.$path.' '.sprintf(self::DOWNLOAD, $version, 'brewless');
    }

    /**
     * A single downloaded file replaces itself, after its checksum matched the release's.
     */
    private function replace(string $path, string $version): bool
    {
        if (! is_writable($path) || ! is_writable(dirname($path))) {
            return false;
        }

        try {
            $build = Http::timeout(120)->get(sprintf(self::DOWNLOAD, $version, 'brewless'))->throw()->body();
            $checksum = Http::timeout(30)->get(sprintf(self::DOWNLOAD, $version, 'brewless.sha256'))->throw()->body();
        } catch (Throwable) {
            return false;
        }

        if (! hash_equals(strtolower(substr(trim($checksum), 0, 64)), hash('sha256', $build))) {
            return false;
        }

        // Written next to the old file and moved over it, so there is never half a client.
        $next = $path.'.new';

        return file_put_contents($next, $build) !== false && chmod($next, 0755) && rename($next, $path);
    }

    private function latest(): ?string
    {
        try {
            $releases = Http::acceptJson()->connectTimeout(2)->timeout(3)->get(self::VERSIONS)->json('packages.'.self::PACKAGE);
        } catch (Throwable) {
            return null;
        }

        $versions = [];

        foreach (is_array($releases) ? $releases : [] as $release) {
            $version = ltrim((string) ($release['version'] ?? ''), 'v');

            if ($this->isRelease($version)) {
                $versions[] = $version;
            }
        }

        usort($versions, version_compare(...));

        return $versions === [] ? null : end($versions);
    }

    /**
     * A build made from a checkout says something like v0.1.1-3-gabc1234: not a release, nothing to compare.
     */
    private function isRelease(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+$/', $version) === 1;
    }

    private function checkedRecently(): bool
    {
        $state = json_decode((string) @file_get_contents($this->path()), true);
        $checkedAt = is_array($state) ? (int) ($state['checked_at'] ?? 0) : 0;

        return now()->getTimestamp() - $checkedAt < self::ONCE_EVERY;
    }

    private function remember(?string $latest): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return;
        }

        @file_put_contents($this->path(), json_encode(['checked_at' => now()->getTimestamp(), 'latest' => $latest])."\n");
    }

    private function path(): string
    {
        return dirname($this->credentials->path()).'/updates.json';
    }
}
