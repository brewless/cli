<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use RuntimeException;

/**
 * What the dns commands share: finding a zone Brewless manages by its domain,
 * and writing a record the way a zone file does.
 */
trait ManagesDns
{
    /** The types Brewless can add and change; the server keeps the same list. */
    private const array EDITABLE_TYPES = ['a', 'aaaa', 'cname', 'txt', 'mx', 'srv', 'caa', 'ns'];

    /**
     * A zone and its records, found by its domain.
     *
     * @return array{zone: array<string, mixed>, domain: string, records: list<array<string, mixed>>, unavailable: string|null}
     */
    protected function zone(Api $api, string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $zones = $api->get('/api/dns')['data'] ?? [];

        foreach ($zones as $zone) {
            if (strtolower((string) ($zone['name'] ?? '')) === $domain) {
                return $api->get('/api/dns/'.$zone['id'])['data'];
            }
        }

        $known = implode(', ', array_column($zones, 'name'));

        throw new RuntimeException('Brewless manages no DNS zone "'.$domain.'".'.($known === '' ? ' Take a zone at Bunny DNS over on the Resources page of an environment first.' : ' It manages: '.$known.'.'));
    }

    /**
     * One record of a zone by its id.
     *
     * @param  array{records: list<array<string, mixed>>}  $zone
     * @return array<string, mixed>
     */
    protected function record(array $zone, string $id): array
    {
        foreach ($zone['records'] as $record) {
            if ((string) $record['id'] === $id) {
                return $record;
            }
        }

        throw new RuntimeException('The zone has no record with id '.$id.'. Run brewless dns:list to see the ids.');
    }

    /**
     * The full name of a record.
     *
     * @param  array<string, mixed>  $record
     */
    protected function hostname(array $record, string $domain): string
    {
        return ($record['name'] ?? '') === '' ? $domain : $record['name'].'.'.$domain;
    }

    /**
     * What a record says: priority, weight, port or CAA flags and tag, then its value.
     *
     * @param  array<string, mixed>  $record
     */
    protected function describe(array $record): string
    {
        $parts = array_filter([$record['priority'] ?? null, $record['weight'] ?? null, $record['port'] ?? null, $record['flags'] ?? null, $record['tag'] ?? null], static fn (mixed $part): bool => $part !== null);

        return trim(implode(' ', [...$parts, $record['value'] ?? '']));
    }

    /**
     * The fields of a record as the options of this command give them, on top of what it was.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function withOptions(array $record): array
    {
        $type = strtolower((string) ($record['type'] ?? ''));

        if (! in_array($type, self::EDITABLE_TYPES, true)) {
            throw new RuntimeException('Brewless can set '.strtoupper(implode(', ', self::EDITABLE_TYPES)).' records; '.str_replace('_', ' ', strtoupper($type)).' records are changed at bunny.net.');
        }

        $fields = ['type' => $type, 'name' => ($record['name'] ?? '') === '' ? '@' : (string) $record['name'], 'value' => (string) ($record['value'] ?? ''), 'ttl' => (int) ($record['ttl'] ?? 300)];

        foreach (['ttl', 'priority', 'weight', 'port', 'flags'] as $number) {
            $option = $this->option($number);
            $current = $record[$number] ?? null;

            if (is_numeric($option)) {
                $fields[$number] = (int) $option;
            } elseif ($current !== null) {
                $fields[$number] = (int) $current;
            }
        }

        $fields['tag'] = is_string($this->option('tag')) ? $this->option('tag') : ($record['tag'] ?? null);

        // A field the type does not have stays out of the request.
        $wanted = ['mx' => ['priority'], 'srv' => ['priority', 'weight', 'port'], 'caa' => ['flags', 'tag']][$type] ?? [];

        foreach (['priority', 'weight', 'port', 'flags', 'tag'] as $field) {
            if (! in_array($field, $wanted, true)) {
                unset($fields[$field]);
            }
        }

        return $fields;
    }
}
