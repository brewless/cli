<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

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
        'edge' => 'Edge settings checked',
        'attach_assets' => 'Built files served from your storage zone',
        'preview' => 'Test address',
        'standby' => 'Standby copy of the database asked for',
    ];

    /** Steps that only do something when brewless.yml asks: without that they have no line. */
    private const array ONLY_WHEN_ASKED = ['standby'];

    private const array OVER = ['active', 'superseded', 'failed', 'cancelled'];

    /** Housekeeping that has no line of its own unless it fails, named while it runs. */
    private const array RUNNING_LABELS = [
        'detach_assets' => 'Preparing the switch',
    ];

    private const array SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** How often the live line is drawn between two looks at the release. */
    private const int FRAMES_PER_POLL = 12;

    /**
     * How long Brewless may fail to answer before following stops. During a
     * rollout the old version can briefly answer with an error, and the
     * release goes on regardless.
     */
    private const int UNANSWERED_SECONDS = 120;

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed> the release as it ended
     */
    protected function follow(Api $api, array $release): array
    {
        $printed = [];
        $started = Carbon::now();
        // A terminal someone watches gets a live line; a log gets only finished steps.
        $live = $this->input->isInteractive()
            && $this->output->isDecorated()
            && (stream_isatty(STDOUT) || $this->laravel->runningUnitTests());
        $running = null;
        $frame = 0;
        $drawn = false;
        $unansweredSince = null;

        while (true) {
            foreach ($release['operation']['steps'] ?? [] as $step) {
                $name = (string) ($step['name'] ?? '');
                $status = (string) ($step['status'] ?? '');

                if (($printed[$name] ?? null) === $status || ! in_array($status, ['succeeded', 'failed'], true)) {
                    continue;
                }

                // Housekeeping around the switch that is only worth a line when it fails, and a step the file did not ask for.
                if ($status === 'succeeded' && ($name === 'detach_assets' || (in_array($name, self::ONLY_WHEN_ASKED, true) && ($step['skipped'] ?? false) === true))) {
                    $printed[$name] = $status;

                    continue;
                }

                $printed[$name] = $status;
                $label = $this->stepLabel($name, $release);
                $elapsed = sprintf('%3ds', (int) $started->diffInSeconds(Carbon::now(), true));

                if ($drawn) {
                    $this->clearLiveLine();
                    $drawn = false;
                }

                $status === 'succeeded'
                    ? $this->line("  <fg=green>✓</> {$label} <fg=gray>{$elapsed}</>")
                    : $this->line("  <fg=red>✗</> {$label} <fg=gray>{$elapsed}</>");
            }

            if (in_array($release['status'] ?? '', self::OVER, true)) {
                if ($drawn) {
                    $this->clearLiveLine();
                }

                return $release;
            }

            if (! $live) {
                Sleep::for(3)->seconds();
            } else {
                $step = $this->runningStep($release);

                // The clock of a step starts when it is first seen at work.
                if ($step === null) {
                    $running = null;
                } elseif (($running['name'] ?? null) !== $step['name']) {
                    $running = ['name' => (string) $step['name'], 'since' => Carbon::now()];
                }

                // Still three seconds between two looks, with the line drawn in between.
                for ($tick = 0; $tick < self::FRAMES_PER_POLL; $tick++) {
                    if ($step !== null && $running !== null) {
                        $this->drawLiveLine($release, $step, $running['since'], $frame++);
                        $drawn = true;
                    }

                    Sleep::for(intdiv(3000, self::FRAMES_PER_POLL))->milliseconds();
                }
            }

            try {
                $release = $api->get('/api/releases/'.$release['id'])['data'] ?? $release;
                $unansweredSince = null;
            } catch (ApiException $exception) {
                if (! $this->worthAnotherLook($exception)) {
                    throw $exception;
                }

                if ($unansweredSince === null) {
                    $unansweredSince = Carbon::now();

                    if ($drawn) {
                        $this->clearLiveLine();
                        $drawn = false;
                    }

                    $this->line('  <fg=gray>Brewless did not answer'.($exception->status > 0 ? ' ('.$exception->status.')' : '').'. Looking again.</>');
                } elseif ($unansweredSince->diffInSeconds(Carbon::now(), true) >= self::UNANSWERED_SECONDS) {
                    throw new ApiException(
                        'Brewless did not answer for '.intdiv(self::UNANSWERED_SECONDS, 60).' minutes, so following release '.($release['number'] ?? '').' stopped. The release itself goes on at Brewless; see where it is with: brewless releases',
                        $exception->status,
                        $exception->error,
                    );
                }
            }
        }
    }

    /**
     * A lost connection, a busy server or a server error can pass; anything
     * else, such as a revoked token, will not.
     */
    private function worthAnotherLook(ApiException $exception): bool
    {
        return $exception->status === 0 || $exception->status === 429 || $exception->status >= 500;
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function stepLabel(string $name, array $release): string
    {
        // A rollback builds nothing: its image step only checks the registry.
        // A restart (after a database switch) puts the live image back too.
        if ($name === 'image' && in_array($release['source'] ?? '', ['rollback', 'restart'], true)) {
            return 'Image still in your registry';
        }

        return self::STEP_LABELS[$name] ?? self::RUNNING_LABELS[$name] ?? $name;
    }

    /**
     * The step at work, or next in line: the first one that has not ended.
     *
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    private function runningStep(array $release): ?array
    {
        foreach ($release['operation']['steps'] ?? [] as $step) {
            if (in_array($step['status'] ?? '', ['pending', 'running'], true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Redraw the one line that says what is going on right now: the step, how
     * long it has been at work, and what it waits for.
     *
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $step
     */
    private function drawLiveLine(array $release, array $step, Carbon $since, int $frame): void
    {
        $spinner = self::SPINNER[$frame % count(self::SPINNER)];
        $label = $this->stepLabel((string) $step['name'], $release);
        $seconds = (int) $since->diffInSeconds(Carbon::now(), true);
        $head = "  {$spinner} {$label} {$seconds}s";

        $notes = [];
        $retryAt = $release['operation']['retry_at'] ?? null;

        if (is_string($retryAt) && $retryAt !== '' && ($release['operation']['status'] ?? '') === 'queued') {
            $wait = max(0, (int) ceil(Carbon::now()->diffInSeconds(Carbon::parse($retryAt), false)));
            $attempts = (int) ($step['attempts'] ?? 0);

            // A step that failed a try counts it; one that waits for something does not.
            $when = $wait > 0 ? 'in '.$wait.'s' : 'now';
            $notes[] = $attempts > 0 ? 'try '.($attempts + 1).' '.$when : 'looking again '.$when;
        }

        // What the step waits for, as the server put it while it is not done yet.
        $hint = $step['error_message'] ?? null;

        if (is_string($hint) && $hint !== '') {
            $notes[] = $hint;
        }

        // Never wider than the terminal: a wrapped line cannot be redrawn in place.
        $room = (new Terminal)->getWidth() - 1 - mb_strwidth($head);
        $tail = $notes === [] ? '' : mb_strimwidth(' · '.implode(' · ', $notes), 0, max(0, $room), '…');

        $this->output->write(
            "\r\e[2K  <fg=yellow>{$spinner}</> {$label} <fg=gray>{$seconds}s</>"
            .($tail === '' ? '' : '<fg=gray>'.OutputFormatter::escape($tail).'</>'),
        );
    }

    private function clearLiveLine(): void
    {
        $this->output->write("\r\e[2K");
    }

    /**
     * The last lines of a build that failed, as Brewless kept them with the
     * release (without anything secret), so the reason shows right here.
     *
     * @param  array<string, mixed>  $release
     */
    private function buildLog(array $release): void
    {
        $log = $release['build_output'] ?? null;

        if (! is_string($log) || trim($log) === '') {
            return;
        }

        $this->line('  Last lines of the build log:');

        // What a build printed is text, not markup for the terminal.
        foreach (explode("\n", $log) as $line) {
            $this->line('  <fg=gray>'.OutputFormatter::escape($line).'</>');
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

            $this->buildLog($release);

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

        $result = $release['operation']['result'] ?? null;
        $this->notices(is_array($result) ? $result : []);

        return true;
    }
}
