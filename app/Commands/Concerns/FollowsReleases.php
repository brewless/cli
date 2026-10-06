<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use Illuminate\Support\Sleep;

/**
 * Watches a release until it is over and prints each step as it changes, the
 * way the terminal on the homepage shows a deploy.
 */
trait FollowsReleases
{
    private const array STEP_LABELS = [
        'source' => 'Source in your project',
        'build' => 'Build started in your project',
        'image' => 'Image built and pushed',
        'migrate' => 'Release commands',
        'activate' => 'Web container live and healthy',
        'workers' => 'Workers and schedule',
        'attach_assets' => 'Built files served from your storage zone',
    ];

    private const array OVER = ['active', 'superseded', 'failed', 'cancelled'];

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed> the release as it ended
     */
    protected function follow(Api $api, array $release): array
    {
        $printed = [];
        $started = microtime(true);

        while (true) {
            foreach ($release['operation']['steps'] ?? [] as $step) {
                $name = (string) ($step['name'] ?? '');
                $status = (string) ($step['status'] ?? '');

                if (($printed[$name] ?? null) === $status || ! in_array($status, ['succeeded', 'failed'], true)) {
                    continue;
                }

                // Housekeeping around the switch that is only worth a line when it fails.
                if ($name === 'detach_assets' && $status === 'succeeded') {
                    $printed[$name] = $status;

                    continue;
                }

                $printed[$name] = $status;
                // A rollback builds nothing: its image step only checks the registry.
                $label = $name === 'image' && ($release['source'] ?? '') === 'rollback'
                    ? 'Image still in your registry'
                    : (self::STEP_LABELS[$name] ?? $name);
                $elapsed = sprintf('%3ds', (int) (microtime(true) - $started));

                $status === 'succeeded'
                    ? $this->line("  <fg=green>✓</> {$label} <fg=gray>{$elapsed}</>")
                    : $this->line("  <fg=red>✗</> {$label} <fg=gray>{$elapsed}</>");
            }

            if (in_array($release['status'] ?? '', self::OVER, true)) {
                return $release;
            }

            Sleep::for(3)->seconds();

            $release = $api->get('/api/releases/'.$release['id'])['data'] ?? $release;
        }
    }

    /**
     * Say how a release ended. Returns whether it is live.
     *
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $environment
     */
    protected function conclude(Api $api, array $release, array $environment): bool
    {
        if (($release['status'] ?? '') !== 'active') {
            $this->newLine();
            $this->components->error('Release '.$release['number'].' did not go live: '.($release['operation']['error_message'] ?? 'it was '.$release['status'].'.'));

            if (is_string($release['migration_output'] ?? null) && $release['migration_output'] !== '') {
                $this->line($release['migration_output']);
            }

            return false;
        }

        $this->newLine();
        $this->components->info('Release '.$release['number'].' is live.');

        // The address visitors use: the own domain when there is one, else what the provider gave.
        $addresses = $api->get('/api/environments/'.$environment['id'].'/addresses')['data'] ?? [];
        $address = $addresses[0]['url'] ?? null;

        if (is_string($address)) {
            $this->line('  '.$address);
        }

        return true;
    }
}
