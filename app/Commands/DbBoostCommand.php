<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesCapacity;
use App\Commands\Concerns\TalksToBrewless;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function is_array;
use function is_numeric;
use function is_string;

final class DbBoostCommand extends Command
{
    use ManagesCapacity;
    use TalksToBrewless;

    /** Minutes a boost warms up before it starts, when it starts later and --prewarm is not given. */
    private const int PREWARM = 15;

    /** Minutes a boost cools down after it ends, when --cooldown is not given. */
    private const int COOLDOWN = 30;

    protected $signature = 'db:boost {environment : The environment whose database to boost}
        {--min= : The fewest vCPUs during the boost (0 to 15)}
        {--max= : The most vCPUs during the boost (1 to 15)}
        {--for= : How long the boost holds, such as 90m, 2h or 1h30m}
        {--at= : When it starts, such as "2027-05-01 10:00" in this machine\'s time zone; now when left out}
        {--until= : When it ends: a time on the day it starts, such as 12:00, or a date and time}
        {--prewarm= : Minutes to raise the bounds before it starts (default 15 for a later start)}
        {--cooldown= : Minutes to keep them after it ends (default 30)}
        {--name= : A name to know it by (default the key)}
        {--key= : The key of the boost; sending the same key again does nothing twice (default a new one)}
        {--database= : One database of the environment, by name, when it has more than one}
        {--yes : Do not ask for confirmation}
        {--no-wait : Do not wait until the database has the new bounds}';

    protected $description = 'Raise the vCPU bounds of an environment\'s Serverless SQL Database for a while';

    public function handle(): int
    {
        return $this->attemptCapacity(function (): int {
            $slug = (string) $this->argument('environment');
            $min = $this->vcpu('min', $slug);
            $max = $this->vcpu('max', $slug);
            $at = is_string($this->option('at')) ? $this->moment($this->option('at'), '--at') : null;
            $from = $at ?? $this->localNow();

            if (is_string($this->option('for')) === is_string($this->option('until'))) {
                throw new RuntimeException('Say how long the boost holds with --for (such as 2h), or until when with --until (such as 12:00).');
            }

            $until = is_string($this->option('until')) ? $this->until($this->option('until'), $from) : null;

            if ($until !== null && $until->lessThanOrEqualTo($from)) {
                throw new RuntimeException('--until is not later than the start of the boost.');
            }

            $minutes = $until === null ? $this->duration((string) $this->option('for')) : (int) $from->diffInMinutes($until, true);
            $later = $at !== null && $at->isFuture();
            $prewarm = $this->minutes('prewarm') ?? ($later ? self::PREWARM : 0);
            $cooldown = $this->minutes('cooldown') ?? self::COOLDOWN;
            $database = is_string($this->option('database')) ? $this->option('database') : null;

            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $capacity = $this->capacity($api, $environment, $database);

            if (($capacity['allowed'] ?? true) === false) {
                $this->planLacksBoosts($project->organisation);

                return self::FAILURE;
            }

            $estimate = $api->get($this->capacityPath($environment).'/estimate', array_filter([
                'min_vcpu' => $min,
                'max_vcpu' => $max,
                'minutes' => $prewarm + $minutes + $cooldown,
                'database' => $database,
            ], static fn (mixed $value): bool => $value !== null))['data'] ?? [];

            $this->newLine();
            $this->describeDatabase($capacity, $slug);
            $this->line('  Boost to <options=bold>'.$min.'–'.$max.' vCPU</> '.$this->span($at, $until, $minutes).', from '.$this->bounds(is_array($capacity['baseline'] ?? null) ? $capacity['baseline'] : null).' normally.');

            if ($prewarm > 0 || $cooldown > 0) {
                $this->line('  It warms up '.$prewarm.' minutes before and cools down '.$cooldown.' minutes after.');
            }

            if (is_array($estimate) && is_numeric($estimate['floor_cents'] ?? null) && is_numeric($estimate['ceiling_cents'] ?? null)) {
                $this->line('  Estimate: between <options=bold>'.$this->euros((int) $estimate['floor_cents']).'</> and <options=bold>'.$this->euros((int) $estimate['ceiling_cents']).'</>, billed by Scaleway, excl. VAT; the real cost depends on use.');
            }

            $this->newLine();
            $this->line('  A large raise can restart the database: open connections are closed and it can be');
            $this->line('  unreachable for up to about 2 minutes. Small raises take seconds, and going down never restarts it.');
            $this->line($later && $prewarm > 0
                ? '  The boost warms up '.$prewarm.' minutes early, so a restart happens before it starts.'
                : '  To have that happen before a peak, plan the boost ahead with --at: it then warms up first.');

            if (! $this->option('yes') && ! $this->confirm('Boost the database of '.$slug.'?')) {
                return self::SUCCESS;
            }

            $key = is_string($this->option('key')) && $this->option('key') !== '' ? $this->option('key') : 'cli-'.Str::lower(Str::random(24));

            $answer = $api->post($this->capacityPath($environment).'/boosts', array_filter([
                'key' => $key,
                'database' => $database,
                'name' => is_string($this->option('name')) ? $this->option('name') : null,
                'min_vcpu' => $min,
                'max_vcpu' => $max,
                'starts_at' => $at?->toIso8601String(),
                'ends_at' => $until?->toIso8601String(),
                'minutes' => $until === null ? $minutes : null,
                'prewarm_minutes' => $this->minutes('prewarm'),
                'cooldown_minutes' => $this->minutes('cooldown'),
            ], static fn (mixed $value): bool => $value !== null));

            $boost = is_array($answer['data'] ?? null) ? $answer['data'] : [];
            $operation = is_array($answer['operation'] ?? null) ? $answer['operation'] : null;

            $this->newLine();

            if ($operation === null) {
                $this->components->info('Boost '.$key.' is planned. It warms up at '.$this->when($boost['window_starts_at'] ?? null).' and holds from '.$this->when($boost['starts_at'] ?? null).' to '.$this->when($boost['ends_at'] ?? null, $boost['starts_at'] ?? null).'.');
                $this->line('  See it with: brewless db:capacity '.$slug);

                return self::SUCCESS;
            }

            if ($this->option('no-wait')) {
                $this->components->info('Boost '.$key.' is under way at Scaleway. See how far with: brewless db:capacity '.$slug);

                return self::SUCCESS;
            }

            $this->line('  Boost '.$key.': waiting until Scaleway gives at least '.$min.' vCPU. Ctrl+C stops the waiting, not the boost.');

            $operation = $this->followCapacity($api, $operation);

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->newLine();
                $this->components->error(is_string($operation['error_message'] ?? null) ? $operation['error_message'] : 'Scaleway did not take the new bounds.');

                return self::FAILURE;
            }

            $boost = $this->boost($this->capacity($api, $environment, $database), $key) ?? $boost;

            $this->newLine();
            $this->components->info(($boost['ready'] ?? false) === true
                ? 'The database of '.$slug.' is boosted to '.$min.'–'.$max.' vCPU until '.$this->when($boost['ends_at'] ?? null, CarbonImmutable::now()->toIso8601String()).'.'
                : 'Scaleway has the new bounds. Follow the boost with: brewless db:capacity '.$slug);

            return self::SUCCESS;
        });
    }

    private function vcpu(string $option, string $slug): int
    {
        $value = $this->option($option);

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new RuntimeException('Give both bounds as a number of vCPUs: brewless db:boost '.$slug.' --min=4 --max=15 --for=2h');
        }

        return (int) $value;
    }

    private function minutes(string $option): ?int
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            throw new RuntimeException('Give --'.$option.' as a number of minutes, such as 15.');
        }

        return (int) $value;
    }

    /**
     * When the boost holds, in words.
     */
    private function span(?CarbonImmutable $at, ?CarbonImmutable $until, int $minutes): string
    {
        $length = intdiv($minutes, 60) > 0
            ? intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : '')
            : $minutes.' min';

        if ($at === null) {
            return 'from now for '.$length;
        }

        $end = $until ?? $at->addMinutes($minutes);

        return 'from '.$at->format('D j M Y H:i').' to '.($end->isSameDay($at) ? $end->format('H:i') : $end->format('D j M Y H:i')).' '.$at->format('T').' ('.$length.')';
    }
}
