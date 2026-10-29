<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A dump `pg_dump` wrote, lying on this machine: what `db:import --file`
 * copies from. It is read here, in Docker, and never travels to Brewless;
 * of the file only its name reaches the audit trail, never where it sat.
 */
final readonly class SourceDump
{
    public function __construct(public string $path, public int $bytes) {}

    /** The dump at the path that was given, with `~` for the home a shell would fill in. */
    public static function at(string $given): self
    {
        $home = (string) getenv('HOME');
        $path = $home === '' ? trim($given) : (string) preg_replace('#^~(?=/|$)#', $home, trim($given));
        $found = $path === '' ? false : realpath($path);

        if ($found === false || ! is_file($found) || ! is_readable($found)) {
            throw new RuntimeException('There is no file to copy from at '.($path === '' ? '--file' : $path).'. Give the path of a dump that pg_dump wrote.');
        }

        // Docker tells a mount's parts apart by the colons in it, so a path with one cannot be given to it.
        if (str_contains($found, ':')) {
            throw new RuntimeException('Docker cannot be given a file whose path holds a colon ('.$found.'). Copy the dump to a path without one.');
        }

        $dump = new self($found, (int) filesize($found));

        if ($dump->bytes === 0) {
            throw new RuntimeException($found.' is empty, so there is nothing to copy.');
        }

        return $dump;
    }

    public function name(): string
    {
        return basename($this->path);
    }

    /** Where the data comes from, as it is shown and written to the audit trail. */
    public function location(): string
    {
        return 'file://'.$this->name();
    }

    /**
     * What may be written to the audit trail: the name of the dump, not its path.
     *
     * @return array{engine: string, file: string}
     */
    public function described(): array
    {
        return ['engine' => SourceDatabase::POSTGRES, 'file' => $this->name()];
    }
}
