<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Credentials;
use LaravelZero\Framework\Commands\Command;

final class LogoutCommand extends Command
{
    protected $signature = 'logout {organisation : The organisation to sign this machine out of}';

    protected $description = 'Forget this machine\'s sign-in to an organisation';

    public function handle(Credentials $credentials): int
    {
        $organisation = strtolower(trim((string) $this->argument('organisation')));

        if (! $credentials->forget((string) config('brewless.host'), $organisation)) {
            $this->components->warn('This machine was not signed in to '.$organisation.'.');

            return self::SUCCESS;
        }

        $this->components->info('Signed out of '.$organisation.' on this machine.');
        $this->line('  The sign-in itself still exists until it expires. End it in the console, on the Account page.');

        return self::SUCCESS;
    }
}
