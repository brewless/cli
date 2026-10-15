<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesDns;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DnsUpdateCommand extends Command
{
    use ManagesDns;
    use TalksToBrewless;

    protected $signature = 'dns:update {zone : The domain of the zone, such as example.eu}
        {id : The id of the record, from brewless dns:list}
        {--type= : A, AAAA, CNAME, TXT, MX, SRV, CAA or NS}
        {--name= : The name in the zone: www, or @ for the domain itself}
        {--value= : What the record says}
        {--ttl= : How many seconds resolvers may keep the answer}
        {--priority= : For MX and SRV records}
        {--weight= : For SRV records}
        {--port= : For SRV records}
        {--flags= : For CAA records}
        {--tag= : For CAA records: issue, issuewild or iodef}';

    protected $description = 'Change a record of a DNS zone Brewless manages; what you do not give stays as it is';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $zone = $this->zone($api, (string) $this->argument('zone'));
            $current = $this->record($zone, (string) $this->argument('id'));

            $fields = $this->withOptions([
                ...$current,
                'type' => is_string($this->option('type')) ? strtolower($this->option('type')) : $current['type'],
                'name' => is_string($this->option('name')) ? $this->option('name') : $current['name'],
                'value' => is_string($this->option('value')) ? $this->option('value') : $current['value'],
            ]);

            $record = $api->put('/api/dns/'.$zone['zone']['id'].'/records/'.$current['id'], $fields)['data'] ?? [];

            $this->components->info(strtoupper((string) $record['type']).' '.$this->hostname($record, $zone['domain']).' → '.$this->describe($record));

            return self::SUCCESS;
        });
    }
}
