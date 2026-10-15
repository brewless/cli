<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesDns;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DnsRemoveCommand extends Command
{
    use ManagesDns;
    use TalksToBrewless;

    protected $signature = 'dns:remove {zone : The domain of the zone, such as example.eu}
        {id : The id of the record, from brewless dns:list}
        {--force : Remove without asking first}';

    protected $description = 'Remove a record from a DNS zone Brewless manages';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $zone = $this->zone($api, (string) $this->argument('zone'));
            $record = $this->record($zone, (string) $this->argument('id'));
            $line = strtoupper((string) $record['type']).' '.$this->hostname($record, $zone['domain']).' → '.$this->describe($record);

            // Brewless keeps no copy of the zone: once removed, the record is gone.
            if (! $this->option('force') && ! $this->confirm('Remove '.$line.'?')) {
                $this->components->info('Nothing was removed.');

                return self::SUCCESS;
            }

            $api->delete('/api/dns/'.$zone['zone']['id'].'/records/'.$record['id']);

            $this->components->info('Removed '.$line.'.');

            return self::SUCCESS;
        });
    }
}
