<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();

    $this->folder = sys_get_temp_dir().'/brewless-export-test-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

function exported(): array
{
    return [
        'files' => [
            'README.md' => '# Shop, Production',
            'main.tf' => 'terraform {}',
            'resources.json' => '[]',
            // A name that tries to leave the folder is not written.
            '../outside.txt' => 'no',
            '.hidden' => 'no',
        ],
        'summary' => ['application' => 'shop', 'environment' => 'production', 'resources' => 5, 'releases' => 3, 'domains' => 1, 'variables' => 12],
    ];
}

test('export writes the files into a folder and nothing outside it', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/export' => Http::response(['data' => exported()]),
    ]);

    $this->artisan('export', ['environment' => 'production', '--output' => $this->folder])
        ->expectsOutputToContain('5 resources, 3 releases, 12 variable names. No secret values.')
        ->assertSuccessful();

    expect(File::get($this->folder.'/main.tf'))->toBe('terraform {}')
        ->and(File::get($this->folder.'/README.md'))->toBe('# Shop, Production')
        ->and(collect(File::files($this->folder, true))->map->getFilename()->sort()->values()->all())->toBe(['README.md', 'main.tf', 'resources.json'])
        ->and(File::exists(dirname($this->folder).'/outside.txt'))->toBeFalse();
});

test('detach says what stops and what stays, asks, and writes the last export', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/detach' => Http::response(['data' => exported() + ['released' => 5, 'resources_left' => 0, 'providers_connected' => ['Scaleway', 'bunny.net']]]),
    ]);

    $this->artisan('detach', ['environment' => 'production', '--output' => $this->folder])
        ->expectsOutputToContain('Nothing at your providers is touched.')
        ->expectsConfirmation('Detach production?', 'yes')
        ->expectsOutputToContain('production is detached: 5 resources are yours alone again.')
        ->expectsOutputToContain('still holds a key of Scaleway, bunny.net')
        ->assertSuccessful();

    expect(File::exists($this->folder.'/main.tf'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/detach') && $request['confirm'] === 'production');
});

test('saying no detaches nothing, and a refusal of the server is passed on', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/detach' => Http::response(['errors' => ['environment' => ['Something is still being carried out for this environment. Wait until it is done, then detach.']]], 422),
    ]);

    $this->artisan('detach', ['environment' => 'production', '--output' => $this->folder])
        ->expectsConfirmation('Detach production?', 'no')
        ->expectsOutputToContain('Nothing was detached.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/detach'));

    $this->artisan('detach', ['environment' => 'production', '--force' => true, '--output' => $this->folder])
        ->expectsOutputToContain('Wait until it is done, then detach.')
        ->assertFailed();

    expect(File::exists($this->folder))->toBeFalse();
});
