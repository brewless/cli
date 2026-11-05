<?php

declare(strict_types=1);

use App\Commands\DeployCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Release 2 waits for its release container; release 1 is live.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function underWay(array $overrides = []): array
{
    return ['data' => [
        release([
            'id' => 'r2',
            'number' => 2,
            'status' => 'migrating',
            'created_at' => '2026-11-05T10:12:15Z',
            'operation' => ['status' => 'queued', 'steps' => [
                ['name' => 'image', 'status' => 'succeeded', 'attempts' => 1],
                ['name' => 'migrate', 'status' => 'pending', 'attempts' => 1, 'error_message' => 'The release container is still rolling out.'],
                ['name' => 'activate', 'status' => 'pending', 'attempts' => 0],
            ]],
            ...$overrides,
        ]),
        release(['id' => 'r1', 'number' => 1, 'status' => 'active', 'created_at' => '2026-11-04T09:00:00Z']),
    ]];
}

test('cancel stops the release under way after asking, and says what it waited for', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(underWay()),
        '*/releases/r2/cancel' => Http::response(['data' => release(['id' => 'r2', 'number' => 2, 'status' => 'cancelled'])]),
    ]);

    $this->artisan('cancel', ['environment' => 'production'])
        ->expectsOutputToContain('Release 2 (commit aaaaaaaaaaaa) is migrating, at: Release commands · The release container is still rolling out.')
        ->expectsOutputToContain('The release that is live now stays live.')
        ->expectsConfirmation('Cancel release 2 of production?', 'yes')
        ->expectsOutputToContain('Release 2 is cancelled.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/cli/api/releases/r2/cancel'));
});

test('a release at work on a step stops after it, and saying no cancels nothing', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(underWay()),
        '*/releases/r2/cancel' => Http::response(underWay(['operation' => ['status' => 'running', 'cancel_requested' => true, 'steps' => [['name' => 'migrate', 'status' => 'running']]]])['data'][0]),
    ]);

    $this->artisan('cancel', ['environment' => 'production'])
        ->expectsConfirmation('Cancel release 2 of production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel'));

    $this->artisan('cancel', ['environment' => 'production', '--yes' => true])
        ->expectsOutputToContain('Release 2 stops after the step it is on: Release commands. See it end with: brewless releases production')
        ->assertSuccessful();
});

test('with no release under way nothing is sent', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(['data' => [release(['status' => 'active'])]]),
    ]);

    $this->artisan('cancel', ['environment' => 'production', '--yes' => true])
        ->expectsOutputToContain('No release of production is under way. Nothing was cancelled.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel'));
});

test('releases shows times in this machine\'s zone, and what the release under way waits for', function (): void {
    signedIn();
    project();
    config(['brewless.timezone' => 'Europe/Amsterdam']);

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(underWay()),
    ]);

    $this->artisan('releases', ['environment' => 'production'])
        ->expectsOutputToContain('Started (Europe/Amsterdam)')
        ->expectsOutputToContain('2026-11-05 11:12:15')
        ->expectsOutputToContain('Release 2 is at: Release commands · The release container is still rolling out.')
        ->expectsOutputToContain('Stop it with: brewless cancel production')
        ->assertSuccessful();
});

test('a stopped pipeline run cancels the release it started; ctrl-c only stops following', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/releases/r2/cancel' => Http::response(['data' => release(['id' => 'r2', 'number' => 2, 'status' => 'cancelled'])]),
    ]);

    $command = resolve(DeployCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    // As handle() leaves the command once its release started.
    (function () use ($output): void {
        $this->output = new OutputStyle(new ArrayInput([]), $output);
        $this->components = new Factory($this->output);
        $this->following = ['api' => $this->api('acme'), 'release' => release(['id' => 'r2', 'number' => 2]), 'slug' => 'production'];
    })->call($command);

    expect($command->getSubscribedSignals())->toBe([SIGTERM, SIGINT])
        ->and($command->handleSignal(SIGINT))->toBe(130);

    Http::assertNothingSent();

    expect($command->handleSignal(SIGTERM))->toBe(1)
        ->and($output->fetch())->toContain('Stopped following. Release 2 goes on at Brewless; stop it with: brewless cancel production', 'The run was stopped. Cancelling release 2.', 'Release 2 is cancelled.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cli/api/releases/r2/cancel'));
});
