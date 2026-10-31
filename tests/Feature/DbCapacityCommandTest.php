<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A boost as Brewless returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capacityBoost(array $overrides = []): array
{
    return [
        'key' => 'event-123-sale',
        'name' => 'Sale of event 123',
        'database' => 'shop-main',
        'environment' => 'production',
        'source' => 'cli',
        'status' => 'scheduled',
        'ready' => false,
        'ready_at' => null,
        'min_vcpu' => 4,
        'max_vcpu' => 15,
        'starts_at' => '2027-05-01T10:00:00+02:00',
        'ends_at' => '2027-05-01T12:00:00+02:00',
        'prewarm_minutes' => 15,
        'cooldown_minutes' => 30,
        'window_starts_at' => '2027-05-01T09:45:00+02:00',
        'window_ends_at' => '2027-05-01T12:30:00+02:00',
        'cpu_current' => null,
        'failure_reason' => null,
        'cancelled_at' => null,
        'estimate' => ['floor_cents' => 151, 'ceiling_cents' => 567, 'minutes' => 165, 'currency' => 'EUR'],
        'created_at' => '2026-10-31T14:00:00+01:00',
        ...$overrides,
    ];
}

/**
 * The capacity of the database of production as Brewless returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capacity(array $overrides = []): array
{
    return ['data' => [
        'database' => ['id' => 'db1', 'name' => 'shop-main', 'shared' => false, 'environments' => [['id' => 'env1', 'name' => 'production', 'application' => 'shop']]],
        'baseline' => ['min' => 0, 'max' => 4],
        'effective' => ['min' => 0, 'max' => 4],
        'cpu_current' => 0.0,
        'status' => 'ready',
        'allowed' => true,
        'boosts' => [],
        'operation' => null,
        'drift' => null,
        'failure' => null,
        ...$overrides,
    ]];
}

/**
 * The capacity change at Scaleway as an operation.
 *
 * @return array<string, mixed>
 */
function capacityOperation(string $status): array
{
    $steps = array_map(static fn (string $name): array => ['name' => $name, 'status' => $status === 'succeeded' ? 'succeeded' : 'pending'], ['apply', 'confirm', 'settle']);

    return ['id' => 'op7', 'status' => $status, 'steps' => $steps, 'error_message' => $status === 'failed' ? 'Scaleway refused 16 vCPU.' : null];
}

beforeEach(function (): void {
    signedIn();
    project();

    config(['brewless.timezone' => 'Europe/Amsterdam']);
    Carbon::setTestNow('2026-10-31 13:00:00 UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('db:capacity shows the bounds, what Scaleway gives, the boosts and a drift', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/capacity*' => Http::response(capacity([
            'database' => ['id' => 'db1', 'name' => 'shop-main', 'shared' => true, 'environments' => [['id' => 'env1', 'name' => 'production', 'application' => 'shop'], ['id' => 'env2', 'name' => 'staging', 'application' => 'shop']]],
            'effective' => ['min' => 4, 'max' => 15],
            'cpu_current' => 4.0,
            'boosts' => [capacityBoost(['status' => 'active', 'ready' => true, 'cpu_current' => 4.0]), capacityBoost(['key' => 'nightly', 'name' => 'Nightly import', 'source' => 'api'])],
            'drift' => ['since' => '2026-10-31T12:00:00+00:00', 'at_scaleway' => ['min' => 2, 'max' => 8]],
        ])),
    ]);

    $this->artisan('db:capacity', ['environment' => 'production', '--database' => 'shop-main'])
        ->expectsOutputToContain('shop-main')
        ->expectsOutputToContain('hold for every environment linked to it: production (shop), staging (shop)')
        ->expectsOutputToContain('0–4 vCPU')
        ->expectsOutputToContain('4–15 vCPU')
        ->expectsOutputToContain('Since 2026-10-31 13:00 Scaleway has 2–8 vCPU')
        ->expectsOutputToContain('2027-05-01 10:00 → 12:00 (warm 09:45, cool to 12:30)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/environments/env1/database/capacity?database=shop-main'));
});

test('db:capacity on a plan without boosts says where they come from', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/capacity*' => Http::response(capacity(['allowed' => false])),
    ]);

    $this->artisan('db:capacity', ['environment' => 'production'])
        ->expectsOutputToContain('come with the Business plan and up: https://acme.brewless.eu/billing')
        ->assertSuccessful();
});

test('db:boost now shows the estimate, warns about a restart, asks, sends a key and waits until it is ready', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 151, 'ceiling_cents' => 567, 'minutes' => 120, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(['status' => 'active']), 'operation' => capacityOperation('running')], 201),
        '*/cli/api/operations/op7' => Http::response(['data' => capacityOperation('succeeded')]),
        // Exact: every stub whose pattern matches is asked, so a wider one would use up the sequence.
        '*/environments/env1/database/capacity' => Http::sequence()
            ->push(capacity())
            ->push(capacity(['boosts' => [['key' => 'placeholder']]])),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '1h30m'])
        ->expectsOutputToContain('Boost to 4–15 vCPU from now for 1 h 30 min')
        ->expectsOutputToContain('between €1.51 and €5.67, billed by Scaleway, excl. VAT; the real cost depends on use')
        ->expectsOutputToContain('A large raise can restart the database')
        ->expectsOutputToContain('plan the boost ahead with --at')
        ->expectsConfirmation('Boost the database of production?', 'yes')
        ->expectsOutputToContain('Scaleway gives the new minimum')
        ->expectsOutputToContain('Scaleway has the new bounds')
        ->assertSuccessful();

    // Starting now: no warm-up, the default cooldown of 30 minutes.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/estimate?') && $request['min_vcpu'] == 4 && $request['max_vcpu'] == 15 && $request['minutes'] == 120);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env1/database/capacity/boosts')
        && str_starts_with((string) $request['key'], 'cli-') && strlen((string) $request['key']) > 10
        && $request['min_vcpu'] === 4 && $request['max_vcpu'] === 15 && $request['minutes'] === 90
        && ! isset($request['starts_at']) && ! isset($request['ends_at']) && ! isset($request['prewarm_minutes']));
});

test('db:boost says when the database is boosted once Scaleway gives the minimum', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 100, 'ceiling_cents' => 200, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => capacityOperation('succeeded')], 201),
        // Exact: every stub whose pattern matches is asked, so a wider one would use up the sequence.
        '*/environments/env1/database/capacity' => Http::sequence()
            ->push(capacity())
            ->push(capacity(['boosts' => [capacityBoost(['key' => 'peak', 'status' => 'active', 'ready' => true, 'ends_at' => '2026-10-31T16:00:00+01:00'])]])),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--key' => 'peak', '--yes' => true])
        ->expectsOutputToContain('The database of production is boosted to 4–15 vCPU until 16:00.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['key'] === 'peak' && $request['minutes'] === 120);
});

test('a planned boost reads --at in the local zone and --until on the same day, and needs no waiting', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 151, 'ceiling_cents' => 567, 'minutes' => 170, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => null], 201),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', [
        'environment' => 'production', '--min' => '4', '--max' => '15',
        '--at' => '2027-05-01 10:00', '--until' => '12:00', '--prewarm' => '20', '--cooldown' => '30',
        '--name' => 'Sale of event 123', '--key' => 'event-123-sale', '--yes' => true,
    ])
        ->expectsOutputToContain('from Sat 1 May 2027 10:00 to 12:00 CEST (2 h)')
        ->expectsOutputToContain('It warms up 20 minutes before and cools down 30 minutes after.')
        ->expectsOutputToContain('so a restart happens before it starts')
        ->expectsOutputToContain('Boost event-123-sale is planned. It warms up at 2027-05-01 09:45 and holds from 2027-05-01 10:00 to 12:00.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/estimate?') && $request['minutes'] == 170);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['key'] === 'event-123-sale' && $request['name'] === 'Sale of event 123'
        && $request['starts_at'] === '2027-05-01T10:00:00+02:00' && $request['ends_at'] === '2027-05-01T12:00:00+02:00'
        && ! isset($request['minutes']) && $request['prewarm_minutes'] === 20 && $request['cooldown_minutes'] === 30);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/operations/'));
});

test('--at with an offset keeps that moment, and a later start warms up 15 minutes by default', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 135, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => null], 201),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '2', '--max' => '8', '--at' => '2027-01-10T08:00:00Z', '--for' => '90m', '--yes' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/estimate?') && $request['minutes'] == 15 + 90 + 30);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['starts_at'] === '2027-01-10T09:00:00+01:00' && $request['minutes'] === 90);
});

test('db:boost with --no-wait returns once Brewless has it', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => capacityOperation('queued')], 201),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--yes' => true, '--no-wait' => true])
        ->expectsOutputToContain('under way at Scaleway. See how far with: brewless db:capacity production')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/operations/'));
});

test('saying no boosts nothing', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h'])
        ->expectsConfirmation('Boost the database of production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('a boost that fails at Scaleway says why and fails', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => capacityOperation('failed')], 201),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--yes' => true])
        ->expectsOutputToContain('Scaleway refused 16 vCPU.')
        ->assertFailed();
});

test('db:boost reads --for as 90m, 2h, 1h30m or minutes, and refuses what it cannot read before calling Brewless', function (string $for, int $minutes): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => $minutes + 30, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['data' => capacityBoost(), 'operation' => null], 201),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '1', '--max' => '4', '--for' => $for, '--yes' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['minutes'] === $minutes);
})->with([
    ['90m', 90],
    ['2h', 120],
    ['1h30m', 90],
    ['45', 45],
]);

test('db:boost refuses a duration, a moment or bounds it cannot read', function (array $options, string $message): void {
    Http::fake();

    $this->artisan('db:boost', ['environment' => 'production', ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    [['--min' => '4', '--max' => '15', '--for' => 'a while'], 'Give --for as a duration'],
    [['--min' => '4', '--max' => '15', '--at' => 'next blue moon', '--for' => '2h'], 'Give --at as a date and time'],
    [['--min' => '4', '--max' => '15'], 'Say how long the boost holds'],
    [['--min' => '4', '--max' => '15', '--for' => '2h', '--until' => '12:00'], 'Say how long the boost holds'],
    [['--max' => '15', '--for' => '2h'], 'Give both bounds'],
    [['--min' => '4', '--max' => '15', '--at' => '2027-05-01 12:00', '--until' => '2027-05-01 11:00'], 'is not later than the start'],
]);

test('a plan without capacity boosts is told where they come from', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/capacity*' => Http::response(capacity(['allowed' => false])),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--yes' => true])
        ->expectsOutputToContain('Capacity boosts come with the Business plan and up. Change the plan on the Billing page: https://acme.brewless.eu/billing')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('a 403 from Brewless names the plan and the role', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/capacity*' => Http::response(['message' => 'Capacity boosts come with Business.'], 403),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--yes' => true])
        ->expectsOutputToContain('Capacity boosts come with the Business plan and up')
        ->expectsOutputToContain('ask an owner or admin of acme')
        ->assertFailed();
});

test('what Brewless refuses is shown per option', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['message' => 'Invalid.', 'errors' => [
            'max_vcpu' => ['The key allows at most 8 vCPU.'],
            'minutes' => ['This month 2 boost hours are left.'],
        ]], 422),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--yes' => true])
        ->expectsOutputToContain('--max  The key allows at most 8 vCPU.')
        ->expectsOutputToContain('--for  This month 2 boost hours are left.')
        ->assertFailed();
});

test('a key used before with other values is refused with what Brewless says', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/database/capacity/estimate*' => Http::response(['data' => ['floor_cents' => 1, 'ceiling_cents' => 2, 'minutes' => 150, 'currency' => 'EUR']]),
        '*/database/capacity/boosts' => Http::response(['message' => 'The key peak was used before for another boost.'], 409),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost', ['environment' => 'production', '--min' => '4', '--max' => '15', '--for' => '2h', '--key' => 'peak', '--yes' => true])
        ->expectsOutputToContain('The key peak was used before for another boost.')
        ->assertFailed();
});

test('db:boost:extend reads a time on the day the boost ends and sends the new end', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/boosts/event-123-sale/extend' => Http::response(['data' => capacityBoost(['ends_at' => '2027-05-01T14:00:00+02:00', 'window_ends_at' => '2027-05-01T14:30:00+02:00']), 'operation' => null]),
        '*/environments/env1/database/capacity*' => Http::response(capacity(['boosts' => [capacityBoost()]])),
    ]);

    $this->artisan('db:boost:extend', ['environment' => 'production', 'key' => 'event-123-sale', '--until' => '14:00'])
        ->expectsOutputToContain('Boost event-123-sale now ends at 2027-05-01 14:00 and cools down until 14:30.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['ends_at'] === '2027-05-01T14:00:00+02:00');
});

test('db:boost:extend wants --until and passes on what Brewless refuses', function (): void {
    $this->artisan('db:boost:extend', ['environment' => 'production', 'key' => 'event-123-sale'])
        ->expectsOutputToContain('Say until when: brewless db:boost:extend production event-123-sale --until=14:00')
        ->assertFailed();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/boosts/gone/extend' => Http::response(['message' => 'Not found.'], 404),
        '*/environments/env1/database/capacity*' => Http::response(capacity()),
    ]);

    $this->artisan('db:boost:extend', ['environment' => 'production', 'key' => 'gone', '--until' => '2027-05-01 14:00'])
        ->expectsOutputToContain('Brewless does not know that here')
        ->assertFailed();
});

test('db:boost:cancel explains that a running boost ends now and cools down, asks, and cancels', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/boosts/event-123-sale' => Http::response(['data' => capacityBoost(['status' => 'cooling_down']), 'operation' => null]),
        '*/environments/env1/database/capacity*' => Http::response(capacity(['boosts' => [capacityBoost(['status' => 'active'])]])),
    ]);

    $this->artisan('db:boost:cancel', ['environment' => 'production', 'key' => 'event-123-sale'])
        ->expectsOutputToContain('Cancelling ends it now: the database keeps the raised bounds for its cooldown of 30 minutes')
        ->expectsConfirmation('Cancel boost event-123-sale of production?', 'yes')
        ->expectsOutputToContain('Boost event-123-sale ended now. It cools down until 2027-05-01 12:30')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/environments/env1/database/capacity/boosts/event-123-sale'));
});

test('db:boost:cancel with --yes cancels a planned boost without asking, and no cancels nothing', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/boosts/event-123-sale' => Http::response(['data' => capacityBoost(['status' => 'cancelled']), 'operation' => null]),
        '*/environments/env1/database/capacity*' => Http::response(capacity(['boosts' => [capacityBoost()]])),
    ]);

    $this->artisan('db:boost:cancel', ['environment' => 'production', 'key' => 'event-123-sale'])
        ->expectsConfirmation('Cancel boost event-123-sale of production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $this->artisan('db:boost:cancel', ['environment' => 'production', 'key' => 'event-123-sale', '--yes' => true])
        ->expectsOutputToContain('has not begun. Cancelling it changes nothing at Scaleway.')
        ->expectsOutputToContain('Boost event-123-sale is cancelled.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});
