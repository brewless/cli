<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\AppliesManifest;
use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\ReportsManifestErrors;
use App\Commands\Concerns\SetsEnvironmentsUp;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\ApiException;
use App\Support\Git;
use App\Support\Manifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;

final class DeployCommand extends Command implements SignalableCommandInterface
{
    use AppliesManifest;
    use FollowsReleases;
    use ReportsManifestErrors;
    use SetsEnvironmentsUp;
    use TalksToBrewless;

    protected $signature = 'deploy {environment? : The environment to deploy to, such as production; without it, default-environment in brewless.yml}
        {--domains : Add the domains of brewless.yml the environment does not answer on yet, without asking}
        {--mail-records : Write the DNS records the mail domain needs in a zone Brewless manages, without asking}';

    protected $description = 'Deploy the current commit to an environment';

    /** @var array{api: Api, release: array<string, mixed>, slug: string}|null the release this run started, once it did */
    private ?array $following = null;

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
                $upload = Http::timeout(900)->withBody($source, 'application/x-tar')->put((string) $link['url']);
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
            $this->following = ['api' => $api, 'release' => $release, 'slug' => $slug];

            return $this->conclude($api, $this->follow($api, $release), $environment) ? self::SUCCESS : self::FAILURE;
        });
    }

    /**
     * A pipeline whose run is stopped sends SIGTERM: the release this run
     * started is cancelled with it, or it would go on at Brewless while the
     * pipeline says it stopped (BL-257). Ctrl-C only stops following.
     *
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') && defined('SIGINT') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->following === null) {
            // Before a release was started there is nothing to stop but this command.
            return defined('SIGINT') && $signal === \SIGINT ? 130 : self::FAILURE;
        }

        ['api' => $api, 'release' => $release, 'slug' => $slug] = $this->following;
        $this->newLine();

        if (defined('SIGINT') && $signal === \SIGINT) {
            $this->line('  Stopped following. Release '.$release['number'].' goes on at Brewless; stop it with: brewless cancel '.$slug);

            return 130;
        }

        $this->line('  The run was stopped. Cancelling release '.$release['number'].'.');

        try {
            $this->reportCancel($api->post('/api/releases/'.$release['id'].'/cancel')['data'] ?? $release, $slug);
        } catch (ApiException $exception) {
            $this->components->error('Release '.$release['number'].' could not be cancelled: '.$exception->getMessage().' Cancel it with: brewless cancel '.$slug);
        }

        return self::FAILURE;
    }
}
