<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\AppliesManifest;
use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\ReportsManifestErrors;
use App\Commands\Concerns\SetsEnvironmentsUp;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\ApiException;
use App\Support\Git;
use App\Support\Manifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class DeployCommand extends Command
{
    use AppliesManifest;
    use FollowsReleases;
    use ReportsManifestErrors;
    use SetsEnvironmentsUp;
    use TalksToBrewless;

    protected $signature = 'deploy {environment? : The environment to deploy to, such as production; without it, default-environment in brewless.yml}
        {--domains : Add the domains of brewless.yml the environment does not answer on yet, without asking}';

    protected $description = 'Deploy the current commit to an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $manifest = Manifest::read((string) getcwd());
            $api = $this->api($project->organisation);
            $slug = $this->argument('environment') ?? $manifest->defaultEnvironment()
                ?? throw new RuntimeException('Name the environment to deploy to, such as: brewless deploy production. Or set default-environment in brewless.yml.');
            $slug = (string) $slug;

            // The environment, its resources and domains first, as the file asks; the release goes onto what stands then.
            if (! $this->applyManifest($api, $project, $manifest, $slug)) {
                $this->line('  Nothing was deployed.');

                return self::FAILURE;
            }

            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $git = new Git((string) getcwd());
            $commit = $git->commit();

            $this->newLine();
            $this->line('  Deploying <options=bold>'.$project->application.'</> to <options=bold>'.$slug.'</> at commit '.substr($commit, 0, 12));

            if ($git->isDirty()) {
                $this->components->warn('You have changes that are not committed. They are not part of this release.');
            }

            // The source goes from this machine straight into a private bucket of your own project.
            $link = $api->post('/api/environments/'.$environment['id'].'/releases/source', ['commit' => $commit])['data'] ?? [];
            $archive = $git->archive();

            // Streamed from disk: a repository with media in it packs to hundreds of megabytes.
            $source = fopen($archive, 'r');

            if ($source === false) {
                throw new RuntimeException('Could not read the packed commit. Nothing was deployed.');
            }

            try {
                $upload = Http::timeout(900)->withBody($source, 'application/gzip')->put((string) $link['url']);
            } finally {
                fclose($source);
                @unlink($archive);
            }

            if ($upload->failed()) {
                throw new RuntimeException('Uploading the source to your project failed (status '.$upload->status().'). Nothing was deployed.');
            }

            try {
                $release = $api->post('/api/environments/'.$environment['id'].'/releases', [
                    'commit' => $commit,
                    'commit_time' => $git->commitTime(),
                    // One key per run of this command: a request that is sent twice starts one release.
                    'key' => 'cli-'.Str::lower(Str::random(24)),
                    // What brewless.yml asks for; without it Brewless builds for its default version.
                    ...($project->php === null ? [] : ['php' => $project->php]),
                    // The whole file: Brewless applies what it asks of this environment with the release.
                    'manifest' => $manifest->data,
                ])['data'] ?? [];
            } catch (ApiException $exception) {
                if (! $this->manifestRefused($manifest, $exception)) {
                    throw $exception;
                }

                $this->line('  Nothing was deployed.');

                return self::FAILURE;
            }

            $this->line('  Release '.$release['number'].(is_string($release['php_version'] ?? null) ? ', PHP '.$release['php_version'] : ''));

            return $this->conclude($api, $this->follow($api, $release), $environment) ? self::SUCCESS : self::FAILURE;
        });
    }
}
