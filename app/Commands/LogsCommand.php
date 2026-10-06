<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;

final class LogsCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'logs {environment : The environment whose logs to show}
        {--tail : Keep following new lines until you stop it}
        {--range=hour : How far back to start: hour, day or week}
        {--search= : Only lines that contain this text}';

    protected $description = 'Show the logs of an environment, read live from your provider';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $query = array_filter(['range' => $this->option('range'), 'search' => $this->option('search')]);
            $after = null;

            do {
                $logs = $api->get('/api/environments/'.$environment['id'].'/logs', $query + array_filter(['after' => $after]))['data'] ?? [];

                if (is_string($logs['unavailable'] ?? null) && $after === null) {
                    $this->components->warn('The logs cannot be read right now ('.$logs['unavailable'].').');

                    return self::FAILURE;
                }

                foreach ($logs['lines'] ?? [] as $line) {
                    $this->line('<fg=gray>'.$line['at'].'</> '.$line['message']);
                    $after = (string) $line['id'];
                }

                if ($this->option('tail')) {
                    Sleep::for(3)->seconds();
                }
            } while ($this->option('tail') && ! $this->stopTailing());

            return self::SUCCESS;
        });
    }

    /**
     * Following ends when the person stops it (Ctrl+C). `brewless.tail_rounds`
     * ends it after that many reads, for a script or a test.
     */
    private function stopTailing(): bool
    {
        $rounds = config('brewless.tail_rounds');

        return is_int($rounds) && ++$this->rounds >= $rounds;
    }

    private int $rounds = 0;
}
