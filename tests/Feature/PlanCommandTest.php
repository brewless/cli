<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    file_put_contents(getcwd().'/brewless.yml', <<<'YAML'
# Which application this is at Brewless. No secrets belong in this file.
organisation: acme
application: shop
framework: laravel
php: 8.4
default-environment: production

environments:
  production:
    kind: production
    web:
      memory: 1024
      max: 10
  staging:
    kind: staging
YAML);
});

/**
 * The plan answer for the brewless.yml above.
 *
 * @return array<string, mixed>
 */
function planAnswer(): array
{
    return ['data' => [
        'environments' => [
            ['name' => 'production', 'exists' => true, 'lines' => [
                ['key' => 'php', 'current' => '8.4', 'wanted' => '8.4', 'state' => 'same', 'applied_on_deploy' => true],
                ['key' => 'web.memory', 'current' => '512', 'wanted' => '1024', 'state' => 'change', 'applied_on_deploy' => true],
                ['key' => 'database', 'current' => null, 'wanted' => 'shop-main', 'state' => 'add', 'applied_on_deploy' => false],
                ['key' => 'domains', 'current' => null, 'wanted' => 'shop.example.eu', 'state' => 'unknown', 'applied_on_deploy' => false],
                ['key' => 'variables.APP_LOCALE', 'current' => null, 'wanted' => 'nl', 'state' => 'refused', 'reason' => 'The variables of this environment are not kept through Brewless yet.', 'applied_on_deploy' => true],
            ]],
            ['name' => 'staging', 'exists' => false, 'lines' => [
                ['key' => 'environment', 'current' => null, 'wanted' => 'staging', 'state' => 'add', 'applied_on_deploy' => false],
            ]],
        ],
        'unlisted' => ['preview'],
    ]];
}

test('plan sends the whole file, php as text, and shows per environment what would change', function (): void {
    Http::fake([
        '*/cli/api/applications/app1/manifest/plan' => Http::response(planAnswer()),
        '*/cli/api/applications' => Http::response(applications()),
    ]);

    $this->artisan('plan')
        ->expectsOutputToContain('production')
        ->expectsOutputToContain('~ web.memory               512 → 1024')
        ->expectsOutputToContain('+ database                 add shop-main (not applied by a deploy yet)')
        ->expectsOutputToContain('! variables.APP_LOCALE     nl: The variables of this environment are not kept through Brewless yet.')
        ->expectsOutputToContain('= php                      8.4')
        ->expectsOutputToContain('? domains                  shop.example.eu (what stands cannot be read)')
        ->expectsOutputToContain('staging (not at Brewless yet)')
        ->expectsOutputToContain('+ environment              a new staging environment')
        ->expectsOutputToContain('Not in brewless.yml, so left as they are: preview.')
        ->expectsOutputToContain('A deploy applies these lines, except those marked "not applied by a deploy yet".')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/manifest/plan')
        && $request['manifest']['php'] === '8.4'
        && $request['manifest']['environments']['production']['web']['memory'] === 1024
        && $request['manifest']['default-environment'] === 'production');
});

test('plan for one environment shows only that one', function (): void {
    Http::fake([
        '*/cli/api/applications/app1/manifest/plan' => Http::response(planAnswer()),
        '*/cli/api/applications' => Http::response(applications()),
    ]);

    $this->artisan('plan staging')
        ->expectsOutputToContain('a new staging environment')
        ->doesntExpectOutputToContain('web.memory')
        ->assertSuccessful();

    $this->artisan('plan live')->expectsOutputToContain('brewless.yml has no environment "live".')->assertFailed();
});

test('a note brewless gives with a line, such as a cost at bunny.net, is shown under it', function (): void {
    $answer = planAnswer();
    $answer['data']['environments'][0]['lines'][] = ['key' => 'firewall.bot_control', 'current' => null, 'wanted' => 'challenge', 'state' => 'add', 'applied_on_deploy' => true, 'note' => 'Needs Shield Advanced or higher, billed by bunny.net.'];
    $answer['data']['environments'][0]['lines'][] = ['key' => 'firewall.rate_limit', 'current' => '100', 'wanted' => '100', 'state' => 'same', 'applied_on_deploy' => true, 'note' => 'Requests per 10 seconds.'];

    Http::fake([
        '*/cli/api/applications/app1/manifest/plan' => Http::response($answer),
        '*/cli/api/applications' => Http::response(applications()),
    ]);

    $this->artisan('plan')
        ->expectsOutputToContain('+ firewall.bot_control     add challenge')
        ->expectsOutputToContain('      Needs Shield Advanced or higher, billed by bunny.net.')
        // A line that changes nothing does not repeat its note.
        ->doesntExpectOutputToContain('Requests per 10 seconds.')
        ->assertSuccessful();
});

test('what brewless refuses in the file is shown with the line it is on', function (): void {
    Http::fake([
        '*/cli/api/applications/app1/manifest/plan' => Http::response(['message' => 'invalid', 'errors' => [
            'manifest.environments.production.web.memory' => ['Give a whole number, at least 128.'],
            'manifest.environments.staging.kind' => ['Choose a kind: production, staging, development.'],
        ]], 422),
        '*/cli/api/applications' => Http::response(applications()),
    ]);

    $this->artisan('plan')
        ->expectsOutputToContain('brewless.yml cannot be used as it is.')
        ->expectsOutputToContain('line 12: Give a whole number, at least 128.')
        ->expectsOutputToContain('line 15: Choose a kind: production, staging, development.')
        ->assertFailed();
});

test('a file that is not valid yaml is named with its line before anything is sent', function (): void {
    Http::fake();
    file_put_contents(getcwd().'/brewless.yml', "organisation: acme\napplication: shop\nenvironments:\n  production:\n    web: [memory: 1024\n");

    $this->artisan('plan')->expectsOutputToContain('brewless.yml cannot be read on line')->assertFailed();

    Http::assertNothingSent();
});

test('deploy without an environment takes default-environment from brewless.yml', function (): void {
    Http::fake(['*/cli/api/applications' => Http::response(['data' => []])]);

    // The application is unknown here; what matters is that production was asked for, not an error about a missing argument.
    $this->artisan('deploy')->expectsOutputToContain('has no application "shop"')->assertFailed();

    file_put_contents(getcwd().'/brewless.yml', "organisation: acme\napplication: shop\n");

    $this->artisan('deploy')->expectsOutputToContain('Or set default-environment in brewless.yml.')->assertFailed();
});
