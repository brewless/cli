<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesDns;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DnsListCommand extends Command
{
    use ManagesDns;
    use TalksToBrewless;

    protected $signature = 'dns:list {zone? : The domain of the zone, such as example.eu; without it the zones are listed}';

    protected $description = 'List the DNS zones Brewless manages, or the records of one zone';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);

            if (! is_string($this->argument('zone'))) {
                return $this->zones($api->get('/api/dns'));
            }

            $zone = $this->zone($api, $this->argument('zone'));

            if (is_string($zone['unavailable'] ?? null)) {
                $this->components->error($zone['unavailable']);

                return self::FAILURE;
            }

            if ($zone['records'] === []) {
                $this->components->info($zone['domain'].' has no records yet. Add one with: brewless dns:add '.$zone['domain'].' a @ 203.0.113.7');

                return self::SUCCESS;
            }

            $this->table(['Id', 'Name', 'Type', 'Value', 'TTL', ''], array_map(fn (array $record): array => [
                $record['id'],
                $this->hostname($record, $zone['domain']),
                str_replace('_', ' ', strtoupper((string) $record['type'])),
                $this->describe($record),
                $record['ttl'],
                // Said once per record that cannot be changed from here, and why.
                $record['editable'] ? '' : (($record['linked_to'] ?? null) !== null ? 'domain of an environment' : 'changed at '.($zone['zone']['provider_label'] ?? 'the DNS provider')),
            ], $zone['records']));

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function zones(array $answer): int
    {
        if (($answer['connected'] ?? true) === false) {
            $this->components->warn('No DNS provider is connected to this organisation, so no zone can be read. Connect bunny.net or Scaleway under Providers in the console.');
        }

        $zones = $answer['data'] ?? [];

        if ($zones === []) {
            $this->components->info('Brewless manages no DNS zone yet. Take a zone at Bunny DNS or Scaleway DNS over on the Resources page of an environment in the console.');

            return self::SUCCESS;
        }

        $this->table(['Zone', 'DNS', 'Used by'], array_map(static fn (array $zone): array => [
            $zone['name'],
            // Where the zone lives: Bunny DNS or Scaleway DNS.
            $zone['provider_label'] ?? 'Bunny DNS',
            implode(', ', array_map(static fn (array $environment): string => $environment['application'].' · '.$environment['name'], $zone['environments'] ?? [])),
        ], $zones));

        return self::SUCCESS;
    }
}
