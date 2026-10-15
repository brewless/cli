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
                'database' => ! is_string($settings['database'] ?? null),
                'queue' => ($settings['queue'] ?? null) !== 'sqs',
                'bucket' => ! is_string($settings['storage'] ?? null),
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
            $this->line('  Linking the database '.$settings['database'].' to '.$slug.', with a login of its own');
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

        foreach ($applied['domains'] ?? [] as $domain) {
            $this->line('  Adding the domain <options=bold>'.($domain['hostname'] ?? 'named in '.Project::FILE).'</>. It carries on after the deploy; its certificate comes once the DNS points the right way. See it under Domains in the console.');
        }

        foreach ($released as $name) {
            $this->line('  '.$name.' is no longer part of '.$slug.'. It keeps running at Scaleway, and your variables still point at it until you change them.');
        }

        return true;
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
    private function sendManifest(Api $api, array $application, Manifest $manifest, string $slug, bool $domains = false): array|false|null
    {
        try {
            return $api->post('/api/applications/'.$application['id'].'/manifest/apply', ['environment' => $slug, 'manifest' => $manifest->data, ...($domains ? ['domains' => true] : [])])['data'] ?? [];
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
}
