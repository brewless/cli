<?php

declare(strict_types=1);

use App\Support\Updates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function packagist(string ...$versions): void
{
    Http::fake(['repo.packagist.org/*' => Http::response(['packages' => ['brewless/cli' => array_map(fn (string $version): array => ['version' => $version], $versions)]])]);
}

test('a newer release is named, an equal or older one is not', function (string $current, ?string $expected): void {
    packagist('v0.2.0', 'v0.10.1', 'v0.3.0', 'dev-main', 'v1.0.0-beta1');

    expect(resolve(Updates::class)->newerThan($current))->toBe($expected);
})->with([
    ['0.1.1', '0.10.1'],
    ['v0.9.0', '0.10.1'],
    ['0.10.1', null],
    ['0.11.0', null],
]);

test('it is looked up once a day', function (): void {
    packagist('v0.2.0');
    $updates = resolve(Updates::class);

    expect($updates->newerThan('0.1.1'))->toBe('0.2.0')
        ->and($updates->newerThan('0.1.1'))->toBeNull();

    Http::assertSentCount(1);

    $this->travel(25)->hours();

    expect($updates->newerThan('0.1.1'))->toBe('0.2.0');
});

test('a build from a checkout is never compared', function (): void {
    Http::fake();

    expect(resolve(Updates::class)->newerThan('v0.1.1-3-gabc1234'))->toBeNull();

    Http::assertNothingSent();
});

test('an unreachable Packagist is silence, and is not tried again the same day', function (): void {
    Http::fake(['repo.packagist.org/*' => Http::failedConnection()]);
    $updates = resolve(Updates::class);

    expect($updates->newerThan('0.1.1'))->toBeNull()
        ->and($updates->newerThan('0.1.1'))->toBeNull();

    Http::assertSentCount(1);
});

test('an installation with Composer is upgraded with Composer, where it was installed', function (): void {
    Process::fake();
    $updates = resolve(Updates::class);

    $global = $this->home.'/composer';
    mkdir($global);
    file_put_contents($global.'/composer.json', json_encode(['require' => ['brewless/cli' => '^0.1.1']]));
    file_put_contents($this->project.'/composer.json', json_encode(['require-dev' => ['brewless/cli' => '^0.1.1']]));

    $plan = $updates->plan($global.'/vendor/brewless/cli/builds/brewless', '0.2.0');

    expect($plan)->toBe(['kind' => 'composer', 'directory' => $global, 'command' => ['composer', 'require', 'brewless/cli:^0.2.0']])
        ->and($updates->plan($this->project.'/vendor/brewless/cli/builds/brewless', '0.2.0')['command'])->toBe(['composer', 'require', '--dev', 'brewless/cli:^0.2.0'])
        ->and($updates->upgrade($plan, $global.'/vendor/brewless/cli/builds/brewless', '0.2.0', fn () => null))->toBeTrue();

    Process::assertRan(fn ($process): bool => $process->command === ['composer', 'require', 'brewless/cli:^0.2.0'] && $process->path === $global);
});

test('a downloaded file replaces itself only when the checksum of the release matches', function (): void {
    $updates = resolve(Updates::class);
    $path = $this->home.'/brewless';
    file_put_contents($path, 'old');

    $plan = $updates->plan($path, '0.2.0');

    Http::fake([
        '*/v0.2.0/brewless.sha256' => Http::response(str_repeat('0', 64).'  brewless'),
        '*/v0.2.0/brewless' => Http::response('new'),
    ]);

    expect($plan['kind'])->toBe('file')
        ->and($updates->upgrade($plan, $path, '0.2.0', fn () => null))->toBeFalse()
        ->and(file_get_contents($path))->toBe('old');

    Http::fake([
        '*/v0.3.0/brewless.sha256' => Http::response(hash('sha256', 'new').'  brewless'),
        '*/v0.3.0/brewless' => Http::response('new'),
    ]);

    expect($updates->upgrade($plan, $path, '0.3.0', fn () => null))->toBeTrue()
        ->and(file_get_contents($path))->toBe('new')
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0755');
});
