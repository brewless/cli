<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class LogsCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'logs {environment : The environment whose logs to show}
        {--tail : Keep following new lines until you stop it}
        {--range=hour : How far back to start: hour, day or week}
        {--search= : Only lines that contain this text}
        {--source= : Which container or database to read: web (the default), release, worker or a database name}';

    protected $description = 'Show the logs of an environment, read live from your provider';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $path = '/api/environments/'.$environment['id'].'/logs';
            $query = array_filter([
                'range' => $this->option('range'),
                'search' => $this->option('search'),
                'source' => $this->source($api, $path, (string) $this->option('source')),
            ]);
            $after = null;

            do {
                $logs = $api->get($path, $query + array_filter(['after' => $after]))['data'] ?? [];

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
     * The id of the resource whose logs to read, by its name. Without a name
     * the server reads the web container. The server names the resources that
     * write logs with every answer, so one read finds the id.
     */
    private function source(Api $api, string $path, string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $sources = $api->get($path)['data']['sources'] ?? [];

        foreach ($sources as $source) {
            if ($source['name'] === $name) {
                return (string) $source['id'];
            }
        }

        $known = implode(', ', array_map(static fn (array $source): string => $source['name'], $sources));

        throw new RuntimeException('The environment has no logs named "'.$name.'".'.($known === '' ? '' : ' It has: '.$known.'.'));
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
