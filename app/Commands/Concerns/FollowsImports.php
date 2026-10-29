<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use Illuminate\Support\Sleep;

/**
 * Following a copy that runs in the customer's project (the operations
 * `storage.import` and `database.import`): how far it got while it runs,
 * what it came to when it ends. Stopping to follow it stops nothing.
 */
trait FollowsImports
{
    /**
     * What is said about a copy of this kind. A copy of files by default;
     * a command for another kind of copy gives its own words.
     *
     * @return array{status: string, deleted: string, cancelled: string}
     */
    protected function importWords(): array
    {
        return [
            'status' => 'storage:status',
            'deleted' => 'The keys of the copy were deleted from your Secret Manager.',
            'cancelled' => 'The copy was cancelled. What was copied stays in the bucket.',
        ];
    }

    /**
     * Follow until the copy ends. True when it succeeded.
     *
     * @param  array<string, mixed>  $operation
     */
    protected function followImport(Api $api, array $operation, string $slug): bool
    {
        $this->trapInterrupt($slug);
        $last = null;

        while (! in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
            $line = $this->importLine($operation);

            if ($line !== $last) {
                $this->live() ? $this->output->write("\r\033[2K  ".$line) : $this->line('  '.$line);
                $last = $line;
            }

            Sleep::for(5)->seconds();

            $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
        }

        if ($this->live() && $last !== null) {
            $this->output->write("\r\033[2K");
        }

        return $this->importEnded($operation);
    }

    /**
     * Say how a copy ended. True when it succeeded.
     *
     * @param  array<string, mixed>  $operation
     */
    protected function importEnded(array $operation): bool
    {
        $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];

        return match ($operation['status'] ?? '') {
            'succeeded' => (function () use ($result): bool {
                $this->components->info(is_string($result['summary'] ?? null) ? $result['summary'] : 'The copy is done.');
                $this->line('  '.$this->importWords()['deleted']);

                return true;
            })(),
            'cancelled' => (function (): bool {
                $this->components->warn($this->importWords()['cancelled']);

                return false;
            })(),
            default => (function () use ($operation): bool {
                $this->components->error(is_string($operation['error_message'] ?? null) ? $operation['error_message'] : 'The copy stopped before it was done.');

                return false;
            })(),
        };
    }

    /**
     * One line of where a running copy is.
     *
     * @param  array<string, mixed>  $operation
     */
    protected function importLine(array $operation): string
    {
        foreach (is_array($operation['steps'] ?? null) ? $operation['steps'] : [] as $step) {
            if (($step['name'] ?? '') !== 'copy') {
                continue;
            }

            $progress = is_array($step['progress'] ?? null) ? $step['progress'] : null;

            if ($progress !== null && isset($progress['copied'], $progress['to_copy'])) {
                $eta = is_int($progress['eta_seconds'] ?? null) ? ', about '.$this->duration($progress['eta_seconds']).' to go' : '';

                return number_format((int) $progress['copied']).' of '.number_format((int) $progress['to_copy']).' files, '
                    .$this->size((int) ($progress['bytes'] ?? 0)).' of '.$this->size((int) ($progress['total_bytes'] ?? 0)).$eta;
            }

            if (is_string($step['error_message'] ?? null) && $step['error_message'] !== '') {
                return $step['error_message'];
            }
        }

        return 'Starting the copy in your project…';
    }

    protected function size(int $bytes): string
    {
        foreach ([[1e12, 'TB'], [1e9, 'GB'], [1e6, 'MB'], [1e3, 'kB']] as [$size, $unit]) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    protected function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
            $seconds >= 60 => intdiv($seconds, 60).' min',
            default => $seconds.' s',
        };
    }

    /** Whether a line can be written over in place: a terminal, or a test. */
    protected function live(): bool
    {
        return $this->output->isDecorated() && (stream_isatty(STDOUT) || $this->laravel->runningUnitTests());
    }

    /**
     * Ctrl+C stops following, not the copy.
     */
    private function trapInterrupt(string $slug): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals') || ! defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($slug): void {
            $this->newLine(2);
            $this->line('  The copy goes on in your Scaleway project. Look again with: brewless '.$this->importWords()['status'].' '.$slug);

            exit(self::SUCCESS);
        });
    }
}
