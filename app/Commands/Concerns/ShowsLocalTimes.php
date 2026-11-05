<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Brewless gives its times in UTC. The person at this machine reads them in
 * their own zone: a release that started at 10:12 UTC started at 11:12 in
 * Amsterdam, and reading the one next to the other is how a 28-minute wait
 * once looked like 85 (BL-257).
 */
trait ShowsLocalTimes
{
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

    /**
     * A moment from Brewless in this machine's zone, to the second: empty
     * when there is none.
     */
    protected function localMoment(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return CarbonImmutable::parse($value)->setTimezone($this->localZone())->format('Y-m-d H:i:s');
    }
}
