<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\ApiException;
use App\Support\Credentials;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

final class LoginCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'login {organisation? : The organisation to sign in to, as in <organisation>.brewless.eu}
        {--no-browser : Do not try to open the browser}';

    protected $description = 'Sign this machine in to an organisation';

    public function handle(Credentials $credentials): int
    {
        return $this->attempt(function () use ($credentials): int {
            $organisation = $this->argument('organisation')
                ?? text(label: 'Which organisation?', placeholder: 'acme', required: true, hint: 'As in acme.'.config('brewless.host'));
            $organisation = strtolower(trim((string) $organisation));

            $api = new Api($organisation);
            $login = $api->post('/auth/device', ['machine' => 'CLI on '.(gethostname() ?: 'this machine')]);

            $this->newLine();
            $this->line('  Open this page and approve the sign-in:');
            $this->line('  <options=bold>'.$login['verification_url'].'</>');
            $this->line('  Code: <options=bold>'.$login['user_code'].'</>');
            $this->newLine();

            if (! $this->option('no-browser')) {
                $this->open((string) $login['verification_url']);
            }

            $deadline = time() + (int) $login['expires_in'];
            $answer = null;

            $this->output->write('  Waiting for approval');

            while ($answer === null && time() < $deadline) {
                Sleep::for((int) ($login['interval'] ?? 3))->seconds();
                $this->output->write('.');

                try {
                    $answer = $api->post('/auth/token', ['device_code' => $login['device_code']]);
                } catch (ApiException $exception) {
                    if ($exception->error !== 'authorization_pending') {
                        $this->newLine();

                        throw $exception;
                    }
                }
            }

            $this->newLine();

            if ($answer === null) {
                $this->components->error('Nobody approved the sign-in in time. Run brewless login again.');

                return self::FAILURE;
            }

            $credentials->store((string) config('brewless.host'), $organisation, (string) $answer['token'], (string) ($answer['user']['email'] ?? ''), $answer['expires_at'] ?? null);

            $this->components->info('Signed in to '.($answer['organisation']['name'] ?? $organisation).' as '.($answer['user']['name'] ?? 'you').'.');

            return self::SUCCESS;
        });
    }

    /**
     * Open the page in the browser when the machine has one. Never fatal: the link is printed too.
     */
    private function open(string $url): void
    {
        $opener = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            default => null,
        };

        if ($opener !== null && getenv('CI') === false) {
            Process::quietly()->run($opener.' '.escapeshellarg($url));
        }
    }
}
