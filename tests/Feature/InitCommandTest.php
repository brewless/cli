<?php

declare(strict_types=1);

use App\Support\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('init connects the project to an application that exists', function (): void {
    signedIn('acme');
    file_put_contents($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0']]));
    Http::fake(['https://acme.brewless.eu/cli/api/applications' => Http::response(applications())]);

    $this->artisan('init', ['--application' => 'shop'])
        ->expectsOutputToContain('Framework: laravel')
        ->expectsOutputToContain('Wrote brewless.yml')
        ->assertSuccessful();

    $project = Project::read($this->project);

    expect([$project->organisation, $project->application, $project->framework])->toBe(['acme', 'shop', 'laravel']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bl_test-token'));
});

test('init refuses an application the organisation does not have and a framework it does not know', function (): void {
    signedIn('acme');
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $this->artisan('init', ['--application' => 'blog'])->expectsOutputToContain('has no application "blog"')->assertFailed();
    $this->artisan('init', ['--application' => 'shop', '--framework' => 'rails'])->expectsOutputToContain('Unknown framework "rails"')->assertFailed();

    expect(Project::exists($this->project))->toBeFalse();
});

test('init needs a sign-in first', function (): void {
    $this->artisan('init', ['--application' => 'shop'])
        ->expectsOutputToContain('not signed in to any organisation')
        ->assertFailed();
});
