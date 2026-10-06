<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * What a deploy needs from the repository: which commit, when it was made,
 * and its files. Only what is committed is deployed.
 */
final class Git
{
    public function __construct(private readonly string $directory) {}

    public function commit(): string
    {
        return $this->run('git rev-parse HEAD', 'This is not a git repository, or it has no commit yet.');
    }

    public function commitTime(): int
    {
        return (int) $this->run('git log -1 --format=%ct HEAD', 'Could not read the time of the commit.');
    }

    /**
     * Files that are changed but not committed: they will not be in the release.
     */
    public function isDirty(): bool
    {
        return $this->run('git status --porcelain --untracked-files=no', 'Could not read the state of the repository.') !== '';
    }

    /**
     * Pack the commit and return the path of the archive.
     */
    public function archive(): string
    {
        $path = sys_get_temp_dir().'/brewless-source-'.bin2hex(random_bytes(8)).'.tar.gz';

        $this->run('git archive --format=tar.gz -o '.escapeshellarg($path).' HEAD', 'Could not pack the commit.');

        return $path;
    }

    private function run(string $command, string $failure): string
    {
        $result = Process::path($this->directory)->run($command);

        if ($result->failed()) {
            throw new RuntimeException($failure);
        }

        return trim($result->output());
    }
}
