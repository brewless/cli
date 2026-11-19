<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The records of tenheuveltours.nl at Lettermint, as Brewless keeps them.
 */
function mailSender(array $overrides = []): array
{
    return [
        'environment_id' => 'env1',
        'provider' => 'lettermint',
        'domain' => 'tenheuveltours.nl',
        'zone' => null,
        'kept_elsewhere' => true,
        'status' => 'awaiting_dns',
        'records' => [
            ['kind' => 'return_path', 'type' => 'CNAME', 'name' => 'lm-bounces.tenheuveltours.nl', 'value' => 'bounces.lmta.net', 'status' => 'valid'],
            ['kind' => 'dkim_primary', 'type' => 'CNAME', 'name' => 'lm1._domainkey.tenheuveltours.nl', 'value' => 'lm1.734fwtogzugidbgrmgilx5elge.dkim.lmta.net', 'status' => 'pending'],
        ],
        'checked_at' => '2026-11-19T09:00:00.000000Z',
        'manual_until' => null,
        'gives_up_at' => '2026-11-20T21:00:00.000000Z',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    signedIn();
    project();
    config(['brewless.timezone' => 'Europe/Amsterdam']);
});

test('mail:records shows every record with its type, its name and its whole value, and what the mailer found', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/mail-records' => Http::response(['data' => mailSender()]),
    ]);

    $this->artisan('mail:records', ['environment' => 'production'])
        ->expectsOutputToContain('tenheuveltours.nl sends mail through Lettermint. Its DNS is kept elsewhere')
        ->expectsOutputToContain('lm1._domainkey.tenheuveltours.nl')
        ->expectsOutputToContain('lm1.734fwtogzugidbgrmgilx5elge.dkim.lmta.net')
        ->expectsOutputToContain('Lettermint found 1 of 2 records, when it last looked at 2026-11-19 10:00:00.')
        ->expectsOutputToContain('Brewless asks Lettermint again by itself until 2026-11-20 22:00:00')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('mail:records --check has the mailer look now and says until when Brewless asks every quarter of an hour', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/mail-records/check' => Http::response(['data' => mailSender(['manual_until' => '2026-11-19T11:00:00.000000Z'])]),
    ]);

    $this->artisan('mail:records', ['environment' => 'production', '--check' => true])
        ->expectsOutputToContain('Brewless asks Lettermint again every 15 minutes until 12:00 (Europe/Amsterdam)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/environments/env1/mail-records/check'));
});

test('mail:records says so when every record is found, and when the environment sends from no domain', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/mail-records' => Http::sequence()
            ->push(['data' => mailSender(['status' => 'verified'])])
            ->push(['data' => null]),
    ]);

    $this->artisan('mail:records', ['environment' => 'production'])->expectsOutputToContain('Lettermint found every record.')->assertSuccessful();
    $this->artisan('mail:records', ['environment' => 'production'])->expectsOutputToContain('production sends mail from no domain of its own.')->assertSuccessful();
});

test('domain:list names the mail records still to make when the zone is kept elsewhere', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::response(['data' => ['route' => 'bunny', 'domains' => [], 'unavailable' => null, 'mail' => mailSender()]]),
    ]);

    $this->artisan('domain:list', ['environment' => 'production'])
        ->expectsOutputToContain('Mail from tenheuveltours.nl (Lettermint): 1 of 2 records not found yet')
        ->expectsOutputToContain('CNAME  lm1._domainkey.tenheuveltours.nl  ->  lm1.734fwtogzugidbgrmgilx5elge.dkim.lmta.net')
        ->doesntExpectOutputToContain('lm-bounces.tenheuveltours.nl')
        ->assertSuccessful();
});
