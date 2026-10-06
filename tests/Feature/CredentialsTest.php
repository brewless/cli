<?php

declare(strict_types=1);

use App\Support\Credentials;
use App\Support\Framework;
use App\Support\Project;

test('a sign-in is kept per organisation in a file only its owner can read', function (): void {
    $credentials = resolve(Credentials::class);

    expect($credentials->for('brewless.eu', 'acme'))->toBeNull();

    $credentials->store('brewless.eu', 'acme', 'bl_token', 'ada@example.test', '2027-01-01T00:00:00Z');
    $credentials->store('brewless.eu', 'beans', 'bl_other', 'ada@example.test', null);

    expect($credentials->for('brewless.eu', 'acme'))->toBe(['token' => 'bl_token', 'user' => 'ada@example.test', 'expires_at' => '2027-01-01T00:00:00Z'])
        ->and($credentials->organisations('brewless.eu'))->toBe(['acme', 'beans'])
        ->and($credentials->for('test.brewless.eu', 'acme'))->toBeNull()
        ->and(substr(sprintf('%o', fileperms($credentials->path())), -4))->toBe('0600');

    expect($credentials->forget('brewless.eu', 'acme'))->toBeTrue()
        ->and($credentials->forget('brewless.eu', 'acme'))->toBeFalse()
        ->and($credentials->organisations('brewless.eu'))->toBe(['beans']);
});

test('the framework is read from composer.json', function (string $package, string $framework): void {
    file_put_contents($this->project.'/composer.json', json_encode(['require' => [$package => '^1.0']]));

    expect(Framework::detect($this->project))->toBe($framework);
})->with([
    ['laravel/framework', 'laravel'],
    ['symfony/framework-bundle', 'symfony'],
    ['monolog/monolog', 'generic'],
]);

test('a project without composer.json is plain php', function (): void {
    expect(Framework::detect($this->project))->toBe('generic');
});

test('brewless.yml names the organisation and the application and nothing secret', function (): void {
    (new Project('acme', 'shop', 'laravel'))->write($this->project);

    $project = Project::read($this->project);

    expect($project->organisation)->toBe('acme')
        ->and($project->application)->toBe('shop')
        ->and($project->framework)->toBe('laravel')
        ->and(file_get_contents($this->project.'/brewless.yml'))->toContain('No secrets belong in this file');
});

test('a missing or incomplete brewless.yml says what to do', function (): void {
    expect(fn () => Project::read($this->project))->toThrow(RuntimeException::class, 'brewless init');

    file_put_contents($this->project.'/brewless.yml', "organisation: acme\n");

    expect(fn () => Project::read($this->project))->toThrow(RuntimeException::class, 'has no "application"');
});
