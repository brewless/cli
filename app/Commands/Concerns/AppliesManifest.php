<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use App\Support\ApiException;
use App\Support\Manifest;
use App\Support\Project;

use function Laravel\Prompts\confirm;

/**
 * What brewless.yml asks of an environment's resources, carried out before a
 * deploy: the environment itself, its database, queue and bucket, and its
 * domains. Brewless makes only what is missing and refuses to switch what
 * stands; the refusals are printed with their line.
 *
 * Needs SetsEnvironmentsUp and ReportsManifestErrors next to it, and a
 * --domains option on the command.
 */
trait AppliesManifest
{
    /**
     * Whether the deploy can go on: false when the file was refused or
     * setting the environment up failed, which is said here.
     */
    protected function applyManifest(Api $api, Project $project, Manifest $manifest, string $slug): bool
    {
        $settings = $manifest->data['environments'][$slug] ?? null;

        // An environment the file does not name is deployed as it stands.
        if (! is_array($settings)) {
            return true;
        }

        $application = $this->application($api, $project);
        $applied = $this->sendManifest($api, $application, $manifest, $slug);

        if ($applied === false) {
            return false;
        }

        if ($applied === null) {
            return true;
        }

        if (($applied['created'] ?? false) === true) {
            $this->line('  Made the environment <options=bold>'.$slug.'</> ('.($applied['environment']['kind'] ?? '').', '.($applied['environment']['region'] ?? '').')');
        }

        if (is_array($applied['operation'] ?? null)) {
            $running = ($applied['running'] ?? false) === true;
            $this->line('  '.($running ? 'Adding what '.$slug.' is missing' : 'Setting '.$slug.' up').' from '.Project::FILE);

            // What the file does not ask for is not made; adding to an environment that runs leaves what makes it run alone.
            $silent = array_keys(array_filter([
                'registry' => $running,
                'namespace' => $running,
                'containers' => $running,
                'edge' => $running,
                'database' => self::databaseName($settings) === null,
                'queue' => ($settings['queue'] ?? null) !== 'sqs',
                'bucket' => ! is_string($settings['storage'] ?? null),
                'backup_bucket' => ! is_array($settings['backup'] ?? null),
                'runtime_databases' => ! is_array($settings['databases'] ?? null),
                // A cache is reached over the network only, so it brings the network along.
                'network' => ($settings['network'] ?? null) !== 'private' && ! isset($settings['cache']),
                'cache' => ! isset($settings['cache']) || $settings['cache'] === false,
            ]));
            $operation = $this->followSetup($api, $applied['operation'], $silent);

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->components->error($operation['error_message'] ?? $slug.' was not set up.');

                return false;
            }

            // It runs now: what needs an environment that runs, a linked database and domains, comes with a second call.
            if (! $running) {
                $applied = $this->sendManifest($api, $application, $manifest, $slug) ?: $applied;
            }
        }

        if (is_array($applied['database_link'] ?? null)) {
            $this->line('  Linking the database '.(self::databaseName($settings) ?? '').' to '.$slug.', with a login of its own');
            $link = $this->followSetup($api, $applied['database_link']);

            if (($link['status'] ?? '') !== 'succeeded') {
                $this->components->error($link['error_message'] ?? 'The database was not linked.');

                return false;
            }
        }

        // Let go by the first call; a second one finds nothing left to let go.
        $released = (array) ($applied['released'] ?? []);
        $missing = array_values(array_filter((array) ($applied['missing_domains'] ?? []), is_string(...)));

        if ($missing !== [] && $this->addDomainsNow($slug, $missing)) {
            $applied = $this->sendManifest($api, $application, $manifest, $slug, domains: true) ?: $applied;
        } elseif ($missing !== []) {
            $this->line('  Not added: '.implode(', ', $missing).'. Deploy with --domains to add them, or run: brewless domain:add '.$slug.' '.$missing[0]);
        }

        $applied = $this->mailRecords($api, $application, $manifest, $slug, $applied);

        foreach ($applied['domains'] ?? [] as $domain) {
            $this->line('  Adding the domain <options=bold>'.($domain['hostname'] ?? 'named in '.Project::FILE).'</>. It carries on after the deploy; its certificate comes once the DNS points the right way. See it under Domains in the console.');
        }

        foreach ($released as $name) {
            $this->line('  '.$name.' is no longer part of '.$slug.'. It keeps running at Scaleway, and your variables still point at it until you change them.');
        }

        return true;
    }

    /**
     * The DNS records the domain the environment sends mail from still
     * needs. Brewless writes those of a zone it manages after a yes, or with
     * --mail-records; the SPF record that stands gets the include, MX is
     * never touched. Those of a zone kept elsewhere are named, to add there.
     *
     * @param  array<string, mixed>  $application
     * @param  array<string, mixed>  $applied
     * @return array<string, mixed>
     */
    private function mailRecords(Api $api, array $application, Manifest $manifest, string $slug, array $applied): array
    {
        $missing = array_values(array_filter((array) ($applied['missing_mail_records'] ?? []), is_array(...)));
        $inZone = array_values(array_filter($missing, static fn (array $record): bool => ($record['in_zone'] ?? false) === true));
        $elsewhere = array_values(array_filter($missing, static fn (array $record): bool => ($record['in_zone'] ?? false) !== true));

        foreach ($elsewhere as $record) {
            $this->line('  Mail needs a '.($record['type'] ?? 'TXT').' record at <options=bold>'.($record['name'] ?? '').'</>: '.($record['value'] ?? '').'. Add it where the DNS of the domain is kept; mail sends once Scaleway has checked it.');
        }

        if ($inZone === []) {
            return $applied;
        }

        $write = $this->option('mail-records') === true || ($this->input->isInteractive() && confirm(
            label: 'Write '.count($inZone).' DNS record'.(count($inZone) === 1 ? '' : 's').' so '.$slug.' may send mail ('.implode(', ', array_map(static fn (array $record): string => ($record['action'] ?? 'add').' '.($record['name'] ?? ''), $inZone)).')?',
            default: false,
            hint: 'An SPF record that stands keeps what it says and gets the include of Scaleway; MX records stay as they are.',
        ));

        if (! $write) {
            $this->line('  Not written: the mail records of '.$slug.'. Deploy with --mail-records to write them, or add them yourself.');

            return $applied;
        }

        $applied = $this->sendManifest($api, $application, $manifest, $slug, mailRecords: true) ?: $applied;
        $this->line('  Wrote '.count((array) ($applied['mail_records'] ?? [])).' DNS records for mail. Scaleway checks them again; that takes a few minutes.');

        return $applied;
    }

    /**
     * Adding a domain can move its visitors: when its DNS zone is in the
     * customer's accounts, the record is pointed at Brewless. So it is asked
     * first, or given with --domains; without a terminal it is not done.
     *
     * @param  list<string>  $missing
     */
    private function addDomainsNow(string $slug, array $missing): bool
    {
        if ($this->option('domains') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->line('  '.Project::FILE.' names '.implode(', ', $missing).', which '.$slug.' does not answer on yet. Adding a domain points its DNS at Brewless when the zone is in your bunny.net or Scaleway account, so visitors may arrive at this environment.');

        return confirm('Add '.(count($missing) === 1 ? 'it' : 'them').' now?', default: false);
    }

    /**
     * Send the file. Null when this Brewless does not apply resources yet;
     * false when it refused the file.
     *
     * @param  array<string, mixed>  $application
     * @return array<string, mixed>|false|null
     */
    private function sendManifest(Api $api, array $application, Manifest $manifest, string $slug, bool $domains = false, bool $mailRecords = false): array|false|null
    {
        try {
            return $api->post('/api/applications/'.$application['id'].'/manifest/apply', ['environment' => $slug, 'manifest' => $manifest->data, ...($domains ? ['domains' => true] : []), ...($mailRecords ? ['mail_records' => true] : [])])['data'] ?? [];
        } catch (ApiException $exception) {
            if ($exception->status === 404) {
                $this->components->warn('This Brewless does not set environments up from '.Project::FILE.' yet. The release is deployed to the environment as it stands.');

                return null;
            }

            if ($this->manifestRefused($manifest, $exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * The name of the database the file names: `database: shop-main`, or the
     * map form `database: {name: shop-main, standby: true}`.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private static function databaseName(array $settings): ?string
    {
        $database = $settings['database'] ?? null;
        $name = is_array($database) ? ($database['name'] ?? null) : $database;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
