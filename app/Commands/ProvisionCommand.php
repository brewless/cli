<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\SetsEnvironmentsUp;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class ProvisionCommand extends Command
{
    use SetsEnvironmentsUp;
    use TalksToBrewless;

    protected $signature = 'provision {environment : The environment to set up}
        {--database= : new, existing or none; asked when left out}
        {--database-resource= : The name of the existing database to use}
        {--queue= : database, sqs or none; asked when left out}
        {--bucket : Make a private bucket for the files of the application}
        {--no-database : The same as --database=none}';

    protected $description = 'Make what a new environment needs in your own cloud accounts';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            // Asked only of a person who said nothing about it; a script gets what its options say.
            $plan = $this->setupWasGiven() || ! $this->input->isInteractive()
                ? $this->setupFromOptions($api, $environment)
                : $this->askSetup($api, $environment);

            if ($plan === null) {
                return self::FAILURE;
            }

            return $this->setUp($api, $environment, $plan) ? self::SUCCESS : self::FAILURE;
        });
    }
}
