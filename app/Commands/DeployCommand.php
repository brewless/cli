<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Git;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class DeployCommand extends Command
{
    use FollowsReleases;
    use TalksToBrewless;

    protected $signature = 'deploy {environment : The environment to deploy to, such as production}';

    protected $description = 'Deploy the current commit to an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
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

            try {
                $upload = Http::timeout(900)->withBody((string) file_get_contents($archive), 'application/gzip')->put((string) $link['url']);
            } finally {
                @unlink($archive);
            }

            if ($upload->failed()) {
                throw new RuntimeException('Uploading the source to your project failed (status '.$upload->status().'). Nothing was deployed.');
            }

            $release = $api->post('/api/environments/'.$environment['id'].'/releases', [
                'commit' => $commit,
                'commit_time' => $git->commitTime(),
                // One key per run of this command: a request that is sent twice starts one release.
                'key' => 'cli-'.Str::lower(Str::random(24)),
            ])['data'] ?? [];

            $this->line('  Release '.$release['number']);

            return $this->conclude($api, $this->follow($api, $release), $environment) ? self::SUCCESS : self::FAILURE;
        });
    }
}
