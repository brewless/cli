<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesDns;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DnsAddCommand extends Command
{
    use ManagesDns;
    use TalksToBrewless;

    protected $signature = 'dns:add {zone : The domain of the zone, such as example.eu}
        {type : A, AAAA, CNAME, TXT, MX, SRV, CAA or NS}
        {name : The name in the zone: www, or @ for the domain itself}
        {value : What the record says, such as 203.0.113.7}
        {--ttl=300 : How many seconds resolvers may keep the answer}
        {--priority= : For MX and SRV records}
        {--weight= : For SRV records}
        {--port= : For SRV records}
        {--flags= : For CAA records}
        {--tag= : For CAA records: issue, issuewild or iodef}';

    protected $description = 'Add a record to a DNS zone Brewless manages';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $zone = $this->zone($api, (string) $this->argument('zone'));

            $fields = $this->withOptions([
                'type' => strtolower((string) $this->argument('type')),
                'name' => (string) $this->argument('name'),
                'value' => (string) $this->argument('value'),
                'ttl' => 300,
                'priority' => strtolower((string) $this->argument('type')) === 'mx' ? 10 : null,
                'flags' => strtolower((string) $this->argument('type')) === 'caa' ? 0 : null,
                'tag' => strtolower((string) $this->argument('type')) === 'caa' ? 'issue' : null,
            ]);
            $fields['name'] = (string) $this->argument('name');

            $record = $api->post('/api/dns/'.$zone['zone']['id'].'/records', $fields)['data'] ?? [];

            $this->components->info(strtoupper((string) $record['type']).' '.$this->hostname($record, $zone['domain']).' → '.$this->describe($record).' (id '.$record['id'].')');

            return self::SUCCESS;
        });
    }
}
