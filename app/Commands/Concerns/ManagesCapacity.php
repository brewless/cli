<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeZone;
use Exception;
use Illuminate\Support\Sleep;
use RuntimeException;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * What the capacity commands share: the capacity of an environment's
 * database, times read in the zone of this machine, the boost as a line, and
 * following the change at Scaleway until it is done.
 *
 * Needs TalksToBrewless next to it.
 */
trait ManagesCapacity
{
    /** What each step of a capacity change says once it is done. */
    private const array CAPACITY_STEPS = [
        'apply' => 'New bounds sent to Scaleway',
        'confirm' => 'Scaleway gives the new minimum',
        'settle' => 'Database settled at its new bounds',
    ];

    /** The option of the command for each field the server can refuse. */
    private const array CAPACITY_FIELDS = [
        'key' => '--key',
        'database' => '--database',
        'name' => '--name',
        'min_vcpu' => '--min',
        'max_vcpu' => '--max',
        'starts_at' => '--at',
        'ends_at' => '--until',
        'minutes' => '--for',
        'prewarm_minutes' => '--prewarm',
        'cooldown_minutes' => '--cooldown',
    ];

    /**
     * Run a capacity command's work, and say in its own words what a refusal
     * of Brewless means here: a plan without boosts, or what is wrong per option.
     *
     * @param  callable(): int  $work
     */
    protected function attemptCapacity(callable $work): int
    {
        return $this->attempt(function () use ($work): int {
            try {
                return $work();
            } catch (ApiException $exception) {
                if ($exception->status === 403) {
                    $this->planLacksBoosts($this->project()->organisation);

                    return self::FAILURE;
                }

                if ($exception->status !== 422 || $exception->errors === []) {
                    throw $exception;
                }

                $this->components->error('Brewless refused this:');

                foreach ($exception->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $this->line('  <fg=red>'.(self::CAPACITY_FIELDS[$field] ?? $field).'</>  '.$message);
                    }
                }

                return self::FAILURE;
            }
        });
    }

    protected function planLacksBoosts(string $organisation): void
    {
        $this->components->error('Capacity boosts come with the Business plan and up. Change the plan on the Billing page: '.$this->billing($organisation));
        $this->line('  On Business already? Then your role may not change them; ask an owner or admin of '.$organisation.'.');
    }

    protected function billing(string $organisation): string
    {
        return config('brewless.scheme').'://'.$organisation.'.'.config('brewless.host').'/billing';
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    protected function capacityPath(array $environment): string
    {
        return '/api/environments/'.$environment['id'].'/database/capacity';
    }

    /**
     * The capacity of the environment's database: bounds, boosts and notices.
     *
     * @param  array<string, mixed>  $environment
     * @return array<string, mixed>
     */
    protected function capacity(Api $api, array $environment, ?string $database = null): array
    {
        $data = $api->get($this->capacityPath($environment), $database === null ? [] : ['database' => $database])['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * One boost of the capacity, by its key.
     *
     * @param  array<string, mixed>  $capacity
     * @return array<string, mixed>|null
     */
    protected function boost(array $capacity, string $key): ?array
    {
        foreach (is_array($capacity['boosts'] ?? null) ? $capacity['boosts'] : [] as $boost) {
            if (is_array($boost) && ($boost['key'] ?? null) === $key) {
                return $boost;
            }
        }

        return null;
    }

    /**
     * The database the capacity is of, and for a shared one every environment that uses it.
     *
     * @param  array<string, mixed>  $capacity
     */
    protected function describeDatabase(array $capacity, string $slug): void
    {
        $database = is_array($capacity['database'] ?? null) ? $capacity['database'] : [];

        $this->line('  Serverless SQL Database <options=bold>'.($database['name'] ?? '?').'</> of <options=bold>'.$slug.'</>');

        if (($database['shared'] ?? false) !== true) {
            return;
        }

        $environments = array_map(
            static fn (array $environment): string => ($environment['name'] ?? '?').(is_string($environment['application'] ?? null) ? ' ('.$environment['application'].')' : ''),
            array_filter(is_array($database['environments'] ?? null) ? $database['environments'] : [], is_array(...)),
        );

        $this->line('  It is shared, so its bounds and boosts hold for every environment linked to it: '.implode(', ', $environments).'.');
    }

    /**
     * @param  array<string, mixed>|null  $bounds
     */
    protected function bounds(?array $bounds): string
    {
        return $bounds === null ? '?' : ($bounds['min'] ?? '?').'–'.($bounds['max'] ?? '?').' vCPU';
    }

    protected function euros(int $cents): string
    {
        return '€'.number_format($cents / 100, 2);
    }

    /**
     * The zone the person at this machine reads the clock in: TZ when it is
     * set, else the zone of the system, else PHP's own.
     */
    protected function localZone(): DateTimeZone
    {
        $candidates = [config('brewless.timezone')];
        $link = @readlink('/etc/localtime');

        if (is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $match) === 1) {
            $candidates[] = $match[1];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array(ltrim($candidate, ':'), DateTimeZone::listIdentifiers(), true)) {
                return new DateTimeZone(ltrim($candidate, ':'));
            }
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    protected function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->localZone());
    }

    /**
     * A moment as given on the command line: in this machine's zone unless it names an offset.
     */
    protected function moment(string $value, string $option): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $this->localZone())->setTimezone($this->localZone());
        } catch (InvalidFormatException|Exception) {
            throw new RuntimeException('Give '.$option.' as a date and time, such as "2027-05-01 10:00", or with an offset: 2027-05-01T10:00:00+02:00.');
        }
    }

    /**
     * The end as given with --until: a time is on the day of $from (the next
     * day when it is not later than $from), anything else a date and time.
     */
    protected function until(string $value, CarbonImmutable $from): CarbonImmutable
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match) === 1) {
            $until = $from->setTime((int) $match[1], (int) $match[2]);

            return $until->lessThanOrEqualTo($from) ? $until->addDay() : $until;
        }

        return $this->moment($value, '--until');
    }

    /**
     * Minutes from "90m", "2h", "1h30m" or a plain number of minutes.
     */
    protected function duration(string $value): int
    {
        $value = strtolower(str_replace(' ', '', $value));

        if (is_numeric($value) && (int) $value == $value && (int) $value > 0) {
            return (int) $value;
        }

        if ($value === '' || preg_match('/^(?:(\d+)h)?(?:(\d+)m(?:in)?)?$/', $value, $match) !== 1) {
            throw new RuntimeException('Give --for as a duration, such as 90m, 2h or 1h30m.');
        }

        $minutes = (int) ($match[1] ?? 0) * 60 + (int) ($match[2] ?? 0);

        if ($minutes < 1) {
            throw new RuntimeException('Give --for as a duration, such as 90m, 2h or 1h30m.');
        }

        return $minutes;
    }

    /**
     * A moment from Brewless, in this machine's zone: the time alone when it
     * is on the day of $day.
     */
    protected function when(mixed $value, mixed $day = null): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $moment = CarbonImmutable::parse($value)->setTimezone($this->localZone());

        if (is_string($day) && $day !== '' && CarbonImmutable::parse($day)->setTimezone($this->localZone())->isSameDay($moment)) {
            return $moment->format('H:i');
        }

        return $moment->format('Y-m-d H:i');
    }

    /**
     * Follow a capacity change at Scaleway until it is over, a line per step done.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed> the operation as it ended
     */
    protected function followCapacity(Api $api, array $operation): array
    {
        $printed = [];

        while (true) {
            foreach (is_array($operation['steps'] ?? null) ? $operation['steps'] : [] as $step) {
                $name = (string) ($step['name'] ?? '');

                if (($step['status'] ?? '') === 'succeeded' && ! isset($printed[$name])) {
                    $printed[$name] = true;
                    $this->line('  <fg=green>✓</> '.(self::CAPACITY_STEPS[$name] ?? $name));
                }
            }

            if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                return $operation;
            }

            Sleep::for(5)->seconds();

            $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
        }
    }
}
