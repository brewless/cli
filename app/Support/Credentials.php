<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The sign-ins of this machine, one per organisation, in a file only its
 * owner can read. A token is all that is kept: it stands for a person, can be
 * ended from the console and stops working by itself.
 */
final class Credentials
{
    public function path(): string
    {
        $home = config('brewless.home');

        if (! is_string($home) || $home === '') {
            $base = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') ?: sys_get_temp_dir()).'/.config';
            $home = $base.'/brewless';
        }

        return rtrim($home, '/').'/credentials.json';
    }

    /**
     * @return array{token: string, user: string, expires_at: string|null}|null
     */
    public function for(string $host, string $organisation): ?array
    {
        $entry = $this->all()[$host][$organisation] ?? null;

        return is_array($entry) && is_string($entry['token'] ?? null)
            ? ['token' => $entry['token'], 'user' => (string) ($entry['user'] ?? ''), 'expires_at' => $entry['expires_at'] ?? null]
            : null;
    }

    public function store(string $host, string $organisation, string $token, string $user, ?string $expiresAt): void
    {
        $all = $this->all();
        $all[$host][$organisation] = ['token' => $token, 'user' => $user, 'expires_at' => $expiresAt];

        $this->write($all);
    }

    public function forget(string $host, string $organisation): bool
    {
        $all = $this->all();

        if (! isset($all[$host][$organisation])) {
            return false;
        }

        unset($all[$host][$organisation]);
        $this->write($all);

        return true;
    }

    /**
     * The organisations this machine is signed in to on a host.
     *
     * @return list<string>
     */
    public function organisations(string $host): array
    {
        return array_map(strval(...), array_keys($this->all()[$host] ?? []));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function all(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $all
     */
    private function write(array $all): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        // Created empty with the right mode first, so the token is never in a file others can read.
        if (! is_file($path)) {
            touch($path);
        }

        chmod($path, 0600);
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
