<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SECRET = 'sk_live_never-sent-to-brewless';

/**
 * Brewless and Scaleway as the two commands meet them.
 *
 * @param  array{revision: int, content: string}|null  $latest  what is at the provider now
 */
function vault(?array $latest, int $next = 0): void
{
    putenv('SCW_SECRET_KEY=scw-secret-key');

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/secrets' => Http::response(['data' => [
            'secret' => ['name' => 'shop-production-env', 'path' => '/brewless', 'region' => 'fr-par', 'project_id' => 'project-1'],
            'revision' => $latest['revision'] ?? null,
            'keys' => [],
        ]]),
        'https://api.scaleway.com/secret-manager/v1beta1/regions/fr-par/secrets?*' => Http::response(['secrets' => $latest === null ? [] : [['id' => 's1', 'name' => 'shop-production-env']]]),
        'https://api.scaleway.com/secret-manager/v1beta1/regions/fr-par/secrets/s1/versions/latest/access' => $latest === null
            ? Http::response(['message' => 'not found'], 404)
            : Http::response(['revision' => $latest['revision'], 'data' => base64_encode($latest['content'])]),
        'https://api.scaleway.com/secret-manager/v1beta1/regions/fr-par/secrets/s1/versions' => Http::response(['revision' => $next]),
        'https://api.scaleway.com/secret-manager/v1beta1/regions/fr-par/secrets' => Http::response(['id' => 's1', 'name' => 'shop-production-env']),
    ]);
}

function pushedToBrewless(): ?Request
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/environments/env1/secrets'))->first()[0] ?? null;
}

beforeEach(function (): void {
    signedIn();
    project();
});

afterEach(function (): void {
    putenv('SCW_SECRET_KEY');
});

test('pull reads the variables at scaleway with your own key and notes the revision in the file', function (): void {
    vault(['revision' => 7, 'content' => 'API_KEY="'.SECRET."\"\nAPP_NAME=Shop\n"]);

    $this->artisan('env:pull', ['environment' => 'production'])
        ->expectsOutputToContain('Wrote revision 7 (2 variables) to .env.production.')
        ->assertSuccessful();

    $file = $this->project.'/.env.production';

    expect(file_get_contents($file))->toStartWith('# brewless: revision 7 of shop-production-env.')
        ->and(file_get_contents($file))->toContain('API_KEY="'.SECRET.'"')
        ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600');

    // The key went to Scaleway and the token to Brewless, never the other way round.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.scaleway.com') && $request->hasHeader('X-Auth-Token', 'scw-secret-key') && ! $request->hasHeader('Authorization'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'brewless.eu') && $request->hasHeader('X-Auth-Token'));
});

test('pull says so when nothing was pushed yet', function (): void {
    vault(null);

    $this->artisan('env:pull', ['environment' => 'production'])
        ->expectsOutputToContain('No variables have been pushed for production yet.')
        ->assertSuccessful();

    expect(is_file($this->project.'/.env.production'))->toBeFalse();
});

test('push writes a new version at scaleway and tells brewless the names only', function (): void {
    vault(['revision' => 7, 'content' => "API_KEY=old\n"], next: 8);
    file_put_contents($this->project.'/.env.production', "# brewless: revision 7 of shop-production-env. Keep this line; it tells a push what you started from.\nAPI_KEY=\"".SECRET."\"\nAPP_NAME=Shop\n");

    $this->artisan('env:push', ['environment' => 'production'])
        ->expectsOutputToContain('Pushed revision 8 (2 variables) to your Secret Manager.')
        ->expectsOutputToContain('brewless deploy production')
        ->assertSuccessful();

    $written = 'API_KEY="'.SECRET."\"\nAPP_NAME=Shop\n";

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/secrets/s1/versions') && base64_decode($request['data'], true) === $written);

    $report = pushedToBrewless();

    expect($report?->data())->toBe(['revision' => 8, 'keys' => ['API_KEY', 'APP_NAME'], 'checksum' => hash('sha256', $written)])
        ->and($report?->body())->not->toContain(SECRET)
        ->and(file_get_contents($this->project.'/.env.production'))->toStartWith('# brewless: revision 8 of shop-production-env.');

    // Nothing that went to Brewless carried a value.
    foreach (Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'brewless.eu')) as [$request]) {
        expect($request->body().$request->url())->not->toContain(SECRET);
    }
});

test('push refuses to overwrite a revision the file was not pulled at', function (string $header, string $expected): void {
    vault(['revision' => 9, 'content' => "API_KEY=theirs\n"], next: 10);
    file_put_contents($this->project.'/.env.production', $header."API_KEY=mine\n");

    $this->artisan('env:push', ['environment' => 'production'])
        ->expectsOutputToContain($expected)
        ->assertFailed();

    expect(pushedToBrewless())->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/secrets/s1/versions'));
})->with([
    'pulled earlier' => ["# brewless: revision 7 of shop-production-env.\n", 'at revision 9 and this file was pulled at revision 7'],
    'never pulled' => ['', 'at revision 9 and this file was not pulled'],
]);

test('push names the variables it would remove and asks before it does', function (): void {
    vault(['revision' => 7, 'content' => "API_KEY=x\nDB_PASSWORD=y\nMAIL_KEY=z\n"], next: 8);
    file_put_contents($this->project.'/.env.production', "# brewless: revision 7 of shop-production-env.\nAPI_KEY=x\n");

    $this->artisan('env:push', ['environment' => 'production'])
        ->expectsOutputToContain('This push removes 2 variables: DB_PASSWORD, MAIL_KEY')
        ->expectsConfirmation('Remove them from production?', 'no')
        ->assertSuccessful();

    expect(pushedToBrewless())->toBeNull();

    $this->artisan('env:push', ['environment' => 'production'])
        ->expectsConfirmation('Remove them from production?', 'yes')
        ->expectsOutputToContain('Pushed revision 8 (1 variables)')
        ->assertSuccessful();
});

test('the first push creates the secret', function (): void {
    vault(null, next: 1);
    file_put_contents($this->project.'/.env.production', "API_KEY=x\n");

    $this->artisan('env:push', ['environment' => 'production'])->expectsOutputToContain('Pushed revision 1')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/regions/fr-par/secrets') && $request['name'] === 'shop-production-env' && $request['path'] === '/brewless');

});

test('an unchanged file pushes nothing', function (): void {
    vault(['revision' => 1, 'content' => "API_KEY=x\n"], next: 2);
    file_put_contents($this->project.'/.env.production', "# brewless: revision 1 of shop-production-env.\nAPI_KEY=x\n");

    $this->artisan('env:push', ['environment' => 'production'])->expectsOutputToContain('Nothing changed: production is at revision 1 already.')->assertSuccessful();

    expect(pushedToBrewless())->toBeNull();
});

test('an empty file removes every variable and is stored as a comment, because a version cannot be empty', function (): void {
    vault(['revision' => 7, 'content' => "API_KEY=x\n"], next: 8);
    file_put_contents($this->project.'/.env.production', "# brewless: revision 7 of shop-production-env.\n");

    $this->artisan('env:push', ['environment' => 'production', '--force' => true])
        ->expectsOutputToContain('Pushed revision 8 (0 variables)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/secrets/s1/versions') && base64_decode($request['data'], true) === "# no variables\n");

    expect(pushedToBrewless()?->data()['keys'])->toBe([]);
});

test('a file that is no dotenv file, a missing file and a missing scaleway key are each said plainly', function (): void {
    vault(null);

    $this->artisan('env:push', ['environment' => 'production'])->expectsOutputToContain('Run: brewless env:pull production')->assertFailed();

    file_put_contents($this->project.'/.env.production', "this is = not valid \"\n");
    $this->artisan('env:push', ['environment' => 'production'])->expectsOutputToContain('not a valid dotenv file')->assertFailed();

    file_put_contents($this->project.'/.env.production', "API_KEY=x\n");
    putenv('SCW_SECRET_KEY');
    putenv('SCW_CONFIG_PATH='.$this->home.'/none.yaml');

    $this->artisan('env:push', ['environment' => 'production'])->expectsOutputToContain('No Scaleway key on this machine.')->assertFailed();

    putenv('SCW_CONFIG_PATH');
});
