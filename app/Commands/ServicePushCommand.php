<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ReportsManifestErrors;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\ApiException;
use App\Support\Docker;
use App\Support\Manifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class ServicePushCommand extends Command
{
    use ReportsManifestErrors;
    use TalksToBrewless;

    /** What each step of putting a service live is called while it runs. */
    private const array STEPS = [
        'source' => 'Image found in your project',
        'push' => 'Pushing it into your registry',
        'image' => 'Image in your registry',
        'container' => 'Service container live',
        'record' => 'Recorded as a resource of the environment',
    ];

    protected $signature = 'service:push {environment : The environment to put the service live in, such as production}
        {service : The service, as brewless.yml names it under services}';

    protected $description = 'Build a service of brewless.yml with Docker and put it live in an environment';

    public function handle(Docker $docker): int
    {
        return $this->attempt(function () use ($docker): int {
            $project = $this->project();
            $manifest = Manifest::read((string) getcwd());
            $slug = (string) $this->argument('environment');
            $service = (string) $this->argument('service');
            $directory = $this->directory($manifest, $slug, $service);

            $docker->ensureRunning();

            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $this->newLine();
            $this->line('  Building <options=bold>'.$service.'</> from '.$directory.' for '.Docker::PLATFORM);

            $name = 'brewless-'.$project->application.'-'.$service.':local';
            $docker->build((string) getcwd().'/'.$directory, $name, function (string $output): void {
                $this->output->write('<fg=gray>'.$output.'</>');
            });

            $tag = substr($docker->identifier($name), 0, 16);
            $this->line('  Image '.$tag.' built');

            // The image goes from this machine straight into a private bucket of your own project.
            $link = $api->post('/api/environments/'.$environment['id'].'/services/'.$service.'/upload', ['tag' => $tag])['data'] ?? [];
            $this->upload($docker->save($name), (string) ($link['url'] ?? ''));

            try {
                $operation = $api->post('/api/environments/'.$environment['id'].'/services/'.$service, [
                    'tag' => $tag,
                    // One key per run of this command: a request that is sent twice starts one push.
                    'key' => 'cli-'.Str::lower(Str::random(24)),
                    'manifest' => $manifest->data,
                ])['data'] ?? [];
            } catch (ApiException $exception) {
                if (! $this->manifestRefused($manifest, $exception)) {
                    throw $exception;
                }

                $this->line('  Nothing was changed.');

                return self::FAILURE;
            }

            $operation = $this->follow($api, $operation);

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->components->error((string) ($operation['error_message'] ?? 'The service was not put live.'));

                return self::FAILURE;
            }

            $this->components->info($service.' runs in '.$slug.'. The next deploy gives the application '.strtoupper($service).'_URL and '.strtoupper($service).'_TOKEN.');

            return self::SUCCESS;
        });
    }

    /**
     * The directory with the service's Dockerfile, as brewless.yml names it.
     */
    private function directory(Manifest $manifest, string $environment, string $service): string
    {
        $settings = $manifest->data['environments'][$environment]['services'][$service] ?? null;
        $build = is_array($settings) ? ($settings['build'] ?? null) : null;

        if (! is_string($build) || $build === '') {
            throw new RuntimeException('brewless.yml names no '.$service.' service with a build directory under environments.'.$environment.'.services.');
        }

        $build = trim($build, '/');

        if (! is_file((string) getcwd().'/'.$build.'/Dockerfile')) {
            throw new RuntimeException('There is no Dockerfile in '.$build.', the build directory of the '.$service.' service.');
        }

        return $build;
    }

    /**
     * Streamed from disk: an image with a model in it is gigabytes.
     */
    private function upload(string $archive, string $url): void
    {
        $size = (int) filesize($archive);
        $this->line('  Uploading '.number_format($size / 1048576, 0).' MB to your project');
        $body = fopen($archive, 'r');

        if ($body === false) {
            @unlink($archive);

            throw new RuntimeException('Could not read the saved image. Nothing was uploaded.');
        }

        try {
            $upload = Http::timeout(3600)->withBody($body, 'application/gzip')->put($url);
        } finally {
            fclose($body);
            @unlink($archive);
        }

        if ($upload->failed()) {
            throw new RuntimeException('Uploading the image to your project failed (status '.$upload->status().'). Nothing was changed.');
        }
    }

    /**
     * Print each step as it finishes, until the operation is over.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function follow(Api $api, array $operation): array
    {
        $shown = [];

        while (true) {
            foreach ($operation['steps'] ?? [] as $step) {
                $name = (string) ($step['name'] ?? '');

                if (($step['status'] ?? '') === 'succeeded' && ! in_array($name, $shown, true)) {
                    $shown[] = $name;
                    $this->line('  '.(self::STEPS[$name] ?? $name));
                }
            }

            if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                return $operation;
            }

            Sleep::for(3)->seconds();

            $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
        }
    }
}
