<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The steps of a rollback, with the given ones done and the others as asked.
 *
 * @param  array<string, array<string, mixed>>  $others
 * @return list<array<string, mixed>>
 */
function rolloutSteps(array $done, array $others = []): array
{
    return array_map(
        static fn (string $name): array => in_array($name, $done, true)
            ? ['name' => $name, 'status' => 'succeeded', 'attempts' => 1, 'error_message' => null]
            : ['name' => $name, 'status' => 'pending', 'attempts' => 0, 'error_message' => null, ...($others[$name] ?? [])],
        ['image', 'migrate', 'activate', 'workers'],
    );
}

/**
 * Roll back with the release answering as given, and return all it printed,
 * colours left out but every redraw of the line kept.
 *
 * @param  list<array<string, mixed>>  $answers  what the release looks like after starting, then at each look
 * @param  array<string, mixed>  $options
 */
function rollbackPrinting(array $answers, array $options = ['--ansi' => true]): string
{
    signedIn();
    project();

    $sequence = Http::sequence();

    foreach (array_slice($answers, 1) as $answer) {
        $sequence->push(['data' => $answer]);
    }

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(['data' => [
            release(['id' => 'r3', 'number' => 3, 'status' => 'active']),
            release(['id' => 'r2', 'number' => 2, 'status' => 'superseded', 'can_roll_back' => true]),
        ]]),
        '*/releases/r2/rollback' => Http::response(['data' => $answers[0]], 201),
        '*/releases/r4' => $sequence,
        '*/addresses' => Http::response(['data' => [['url' => 'https://shop.example']]]),
    ]);

    $output = new BufferedOutput;
    Artisan::call('rollback', ['environment' => 'production', '--force' => true, ...$options], $output);

    return (string) preg_replace('/\e\[[\d;]*m/', '', $output->fetch());
}

/**
 * What stays on the screen once every redraw has wiped the line it was on.
 */
function screen(string $printed): string
{
    $screen = '';

    foreach (explode("\r\e[2K", $printed) as $index => $part) {
        $screen = $index === 0 ? $part : substr($screen, 0, (int) strrpos("\n".$screen, "\n")).$part;
    }

    return $screen;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rollbackRelease(array $overrides = []): array
{
    return release(['id' => 'r4', 'number' => 4, 'source' => 'rollback', 'status' => 'deploying', ...$overrides]);
}

beforeEach(function (): void {
    // Sleeping moves the clock, so the seconds on the live line count up.
    Carbon::setTestNow('2026-10-15 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the running step gets a live line with its seconds, replaced by the finished line when it is done', function (): void {
    $printed = rollbackPrinting([
        rollbackRelease(['operation' => ['status' => 'running', 'steps' => rolloutSteps(['image'])]]),
        rollbackRelease(['operation' => ['status' => 'running', 'steps' => rolloutSteps(['image'])]]),
        rollbackRelease(['status' => 'active', 'operation' => ['status' => 'succeeded', 'steps' => rolloutSteps(['image', 'migrate', 'activate', 'workers'])]]),
    ]);

    // Drawn several times while the release commands run, the seconds counting up.
    expect($printed)
        ->toContain("\r\e[2K  ⠋ Release commands 0s")
        ->toContain("\r\e[2K  ⠙ Release commands 0s")
        ->toContain('Release commands 3s')
        ->toContain('Release commands 5s');

    // Once over, only the finished lines are left.
    $screen = screen($printed);

    expect($screen)
        ->toContain('  ✓ Image still in your registry')
        ->toContain('  ✓ Release commands')
        ->toContain('  ✓ Web container live and healthy')
        ->toContain("  ✓ Workers and schedule   6s\n\n   INFO  Release 4 is live.")
        ->not->toMatch('/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]/u');
});

test('the live line shows what the step waits for, and when it is tried again', function (): void {
    putenv('COLUMNS=120');

    $printed = rollbackPrinting([
        rollbackRelease(['operation' => [
            'status' => 'queued',
            'attempts' => 3,
            'retry_at' => '2026-10-15T12:00:08Z',
            'steps' => rolloutSteps(['image', 'migrate'], ['activate' => ['error_message' => 'The web container is still rolling out.']]),
        ]]),
        rollbackRelease(['operation' => [
            'status' => 'queued',
            'attempts' => 4,
            'retry_at' => '2026-10-15T12:00:23Z',
            'steps' => rolloutSteps(['image', 'migrate'], ['activate' => ['attempts' => 1, 'error_message' => 'Scaleway did not answer.']]),
        ]]),
        rollbackRelease(['status' => 'active', 'operation' => ['status' => 'succeeded', 'steps' => rolloutSteps(['image', 'migrate', 'activate', 'workers'])]]),
    ]);

    expect($printed)
        ->toContain('Web container live and healthy 0s · looking again in 8s · The web container is still rolling out.')
        ->toContain('Web container live and healthy 3s · try 2 in 20s · Scaleway did not answer.')
        ->toContain('Web container live and healthy 5s · try 2 in 18s');

    putenv('COLUMNS');

    expect(screen($printed))->not->toContain('rolling out');
});

test('a hint wider than the terminal is cut, so the line can be redrawn in place', function (): void {
    putenv('COLUMNS=60');

    try {
        $printed = rollbackPrinting([
            rollbackRelease(['operation' => ['status' => 'running', 'steps' => rolloutSteps(['image'], ['migrate' => ['error_message' => str_repeat('very long hint ', 10)]])]]),
            rollbackRelease(['status' => 'active', 'operation' => ['status' => 'succeeded', 'steps' => rolloutSteps(['image', 'migrate', 'activate', 'workers'])]]),
        ]);
    } finally {
        putenv('COLUMNS');
    }

    foreach (array_slice(explode("\r\e[2K", $printed), 1) as $drawn) {
        expect(mb_strwidth(strtok($drawn, "\n") ?: ''))->toBeLessThan(60);
    }

    expect($printed)->toContain('Release commands 0s · very long hint')->toContain('…');
});

test('without a terminal to redraw, or with --no-interaction, only finished steps are printed', function (array $options): void {
    $printed = rollbackPrinting([
        rollbackRelease(['operation' => ['status' => 'running', 'steps' => rolloutSteps(['image'], ['migrate' => ['error_message' => 'Still running.']])]]),
        rollbackRelease(['status' => 'active', 'operation' => ['status' => 'succeeded', 'steps' => rolloutSteps(['image', 'migrate', 'activate', 'workers'])]]),
    ], $options);

    expect($printed)
        ->not->toContain("\e[2K")
        ->not->toContain('Still running.')
        ->toContain("  ✓ Image still in your registry   0s\n  ✓ Release commands   3s\n  ✓ Web container live and healthy   3s\n  ✓ Workers and schedule   3s\n");

    // One look every three seconds, as before.
    Sleep::assertSequence([Sleep::for(3)->seconds()]);
})->with([
    'no colours' => [[]],
    'no interaction' => [['--ansi' => true, '--no-interaction' => true]],
]);
