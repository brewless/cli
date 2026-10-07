<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Updates;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Phar;
use Symfony\Component\Console\Style\SymfonyStyle;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, $this->offerUpgrade(...));
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Before a command, say once a day that a newer client exists and offer to
     * install it first. Only to a person at a terminal: a pipeline, a pipe and
     * a checkout of the source are left alone.
     */
    private function offerUpgrade(CommandStarting $event): void
    {
        $path = Phar::running(false);

        if ($path === '' || in_array($event->command, [null, 'list', 'help', 'completion', '_complete'], true)) {
            return;
        }

        if (getenv('BREWLESS_NO_UPDATE_CHECK') || getenv('CI') || ! $event->input->isInteractive() || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            return;
        }

        $updates = resolve(Updates::class);
        $current = ltrim((string) config('app.version'), 'v');
        $latest = $updates->newerThan($current);

        if ($latest === null) {
            return;
        }

        $plan = $updates->plan($path, $latest);
        $style = new SymfonyStyle($event->input, $event->output);

        $style->newLine();
        $style->writeln('  <fg=yellow>A newer Brewless client is available:</> '.$current.' → <options=bold>'.$latest.'</>');

        if (! $style->confirm('Upgrade first?', true)) {
            $style->writeln('  Later: '.$updates->byHand($plan, $path, $latest));
            $style->newLine();

            return;
        }

        if (! $updates->upgrade($plan, $path, $latest, fn (string $output) => $style->write($output))) {
            $style->writeln('  <fg=red>That did not work.</> Run it yourself: '.$updates->byHand($plan, $path, $latest));
            $style->newLine();

            return;
        }

        $style->writeln('  <fg=green>Brewless is now '.$latest.'.</>');
        $style->newLine();

        // The file this process runs from was just replaced: what was asked for is run by the new one.
        $arguments = array_map(escapeshellarg(...), array_slice((array) ($_SERVER['argv'] ?? []), 1));
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' '.implode(' ', $arguments), $exitCode);

        exit($exitCode);
    }
}
