<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ShowsLocalTimes;
use App\Commands\Concerns\TalksToBrewless;
use Carbon\CarbonImmutable;
use LaravelZero\Framework\Commands\Command;

final class MailRecordsCommand extends Command
{
    use ShowsLocalTimes;
    use TalksToBrewless;

    private const array MAILERS = [
        'scaleway' => 'Transactional Email',
        'lettermint' => 'Lettermint',
    ];

    private const array FOUND = [
        'valid' => '<fg=green>found</>',
        'invalid' => '<fg=red>wrong</>',
        'pending' => '<fg=yellow>not yet</>',
    ];

    protected $signature = 'mail:records {environment : The environment whose mail records to show}
        {--check : Have the mailer look the records up now, and every quarter of an hour for two hours after}';

    protected $description = 'List the DNS records the domain an environment sends mail from needs, with their whole value';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));
            $path = '/api/environments/'.$environment['id'].'/mail-records';

            $answer = $this->option('check') === true ? $api->post($path.'/check') : $api->get($path);

            if (is_string($answer['unavailable'] ?? null)) {
                $this->components->error($answer['unavailable']);

                return self::FAILURE;
            }

            $sender = is_array($answer['data'] ?? null) ? $answer['data'] : null;

            if ($sender === null) {
                $this->components->info($this->argument('environment').' sends mail from no domain of its own.');

                return self::SUCCESS;
            }

            $mailer = self::MAILERS[$sender['provider'] ?? ''] ?? (string) ($sender['provider'] ?? '');
            $records = array_values(array_filter((array) ($sender['records'] ?? []), is_array(...)));

            $this->line('  <options=bold>'.($sender['domain'] ?? '').'</> sends mail through '.$mailer.'. '.(($sender['kept_elsewhere'] ?? false) === true
                ? 'Its DNS is kept elsewhere: make these records there.'
                : 'Its zone '.($sender['zone'] ?? '').' is kept through Brewless: a deploy writes what is missing after a yes, or with --mail-records.'));
            $this->newLine();

            foreach ($records as $record) {
                $this->line('  '.str_pad((string) ($record['type'] ?? ''), 6).'<options=bold>'.($record['name'] ?? '').'</>  '.(self::FOUND[$record['status'] ?? 'pending'] ?? (string) ($record['status'] ?? '')));
                // The whole value, on a line of its own, so it can be copied as it stands.
                $this->line('        '.($record['value'] ?? ''));
            }

            $this->newLine();
            $this->status($sender, $mailer, $records);

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $sender
     * @param  list<array<string, mixed>>  $records
     */
    private function status(array $sender, string $mailer, array $records): void
    {
        $found = count(array_filter($records, static fn (array $record): bool => ($record['status'] ?? null) === 'valid'));

        if (($sender['status'] ?? '') === 'verified') {
            $this->components->info($mailer.' found every record.');

            return;
        }

        $this->line('  '.$mailer.' found '.$found.' of '.count($records).' records'.(is_string($sender['checked_at'] ?? null) ? ', when it last looked at '.$this->localMoment($sender['checked_at']) : '').'.');

        if (($sender['status'] ?? '') === 'failed') {
            $this->line('  Brewless stopped asking. Make the records, then run: brewless mail:records '.$this->argument('environment').' --check');

            return;
        }

        if (is_string($sender['manual_until'] ?? null) && $sender['manual_until'] !== '') {
            $until = CarbonImmutable::parse($sender['manual_until'])->setTimezone($this->localZone());
            $this->line('  Brewless asks '.$mailer.' again every 15 minutes until '.$until->format('H:i').' ('.$until->getTimezone()->getName().'), and stops as soon as it found them all.');

            return;
        }

        if (is_string($sender['gives_up_at'] ?? null) && $sender['gives_up_at'] !== '') {
            $this->line('  Brewless asks '.$mailer.' again by itself until '.$this->localMoment($sender['gives_up_at']).'. Made them just now? Run: brewless mail:records '.$this->argument('environment').' --check');
        }
    }
}
