<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * What a service needs from Docker on this machine: build its image for the
 * platform Scaleway runs, tell its identifier, and save it as one file. The
 * image stays on this machine until the client uploads it to your project.
 */
final class Docker
{
    /** The platform of a Scaleway Serverless Container. */
    public const string PLATFORM = 'linux/amd64';

    public function ensureRunning(): void
    {
        if (Process::run('docker version --format "{{.Server.Version}}"')->failed()) {
            throw new RuntimeException('A service is built with Docker on this machine, and Docker does not answer. Start Docker and try again.');
        }
    }

    /**
     * Build the Dockerfile in a directory under a local name. What Docker
     * prints goes to $output as it comes.
     *
     * @param  Closure(string): void  $output
     */
    public function build(string $directory, string $name, Closure $output): void
    {
        $result = Process::forever()->run(
            'docker build --platform '.self::PLATFORM.' --progress=plain -t '.escapeshellarg($name).' '.escapeshellarg($directory),
            static function (string $type, string $line) use ($output): void {
                $output($line);
            },
        );

        if ($result->failed()) {
            throw new RuntimeException('Docker could not build the image. Nothing was uploaded.');
        }
    }

    /**
     * The image's own identifier, without `sha256:`: the same image always
     * has the same one.
     */
    public function identifier(string $name): string
    {
        $result = Process::run('docker image inspect --format "{{.Id}}" '.escapeshellarg($name));
        $identifier = preg_replace('/^sha256:/', '', trim($result->output())) ?? '';

        if ($result->failed() || preg_match('/^[0-9a-f]{64}$/', $identifier) !== 1) {
            throw new RuntimeException('Docker did not give the identifier of the image it built.');
        }

        return $identifier;
    }

    /**
     * Save the image, compressed, and return the path of the file.
     */
    public function save(string $name): string
    {
        $path = sys_get_temp_dir().'/brewless-service-'.bin2hex(random_bytes(8)).'.tar.gz';
        $result = Process::forever()->run('set -o pipefail; docker save '.escapeshellarg($name).' | gzip -1 > '.escapeshellarg($path));

        if ($result->failed() || ! is_file($path)) {
            @unlink($path);

            throw new RuntimeException('Docker could not save the image. Nothing was uploaded.');
        }

        return $path;
    }
}
