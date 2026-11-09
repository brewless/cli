<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class WakesCommand extends Command
{
    use TalksToBrewless;

    /** What Brewless says when it could not read the log, by its reason. */
    private const array UNAVAILABLE = [
        'no_pull_zone' => 'This environment is not behind bunny.net, so there is no request log to read.',
        'not_connected' => 'bunny.net is not connected to this organisation.',
        'logging_off' => 'Logging is off for this pull zone at bunny.net, so there is no request log to read.',
        'key_rejected' => 'bunny.net refused the API key. Check the connection in the console under Providers.',
    ];

    protected $signature = 'wakes {environment : The environment whose web container to look at}';

    protected $description = 'List what reached the web container over the last day, from the edge\'s request log';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $wakers = $api->get('/api/environments/'.$environment['id'].'/wakers')['data'] ?? [];
            $unavailable = $wakers['unavailable'] ?? null;

            if (is_string($unavailable) && $unavailable !== '') {
                $this->components->warn(self::UNAVAILABLE[$unavailable] ?? 'bunny.net could not be reached. Try again in a moment.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  '.number_format((int) ($wakers['origin_requests'] ?? 0)).' requests reached the web container of <options=bold>'.$slug.'</> in the last '.(int) ($wakers['hours'] ?? 24).' hours.');

            $paths = is_array($wakers['paths'] ?? null) ? $wakers['paths'] : [];

            if ($paths !== []) {
                $this->table(['Address', 'Requests', 'Cache status'], array_map(static fn (array $path): array => [
                    OutputFormatter::escape((string) ($path['path'] ?? '')),
                    number_format((int) ($path['requests'] ?? 0)),
                    implode(', ', array_map(
                        static fn (string $status, int $count): string => ($status === '-' ? 'no cache' : $status).' '.number_format($count),
                        array_keys((array) ($path['statuses'] ?? [])),
                        array_values((array) ($path['statuses'] ?? [])),
                    )),
                ], $paths));
            }

            if (($wakers['complete'] ?? true) === false) {
                $this->line('  <fg=gray>The zone had more requests than were read: these are counted from the newest.</>');
            }

            if ((int) ($wakers['storage_requests'] ?? 0) > 0) {
                $this->line('  <fg=gray>'.number_format((int) $wakers['storage_requests']).' requests for built and versioned files went to your storage zone instead; they do not wake the container.</>');
            }

            if ((int) ($wakers['page_requests'] ?? 0) > 0) {
                $this->line('  <fg=gray>'.number_format((int) $wakers['page_requests']).' requests were pages the edge took from your storage zone (edge.keep_pages), so they never reached the container.</>');
            }

            if (($wakers['versioned_from_storage'] ?? true) === false) {
                $this->line('  <fg=gray>Your pull zone is not taking the files your pages ask for with a version (?v=) from your storage zone, so your container serves every one of them and they are counted above.</>');
            }

            if (($wakers['pages_known'] ?? true) === false) {
                $this->line('  <fg=gray>Your storage zone holds more folders of pages than are read at once, so some pages may still be counted above as if the container answered them.</>');
            }

            $this->line('  <fg=gray>Of a query string only the names are shown. Read from bunny.net\'s log, which keeps three days; nothing is asked of the container.</>');

            return self::SUCCESS;
        });
    }
}
