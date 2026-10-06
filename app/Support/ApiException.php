<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * An answer from Brewless that is not a success, in words for the person at
 * the terminal.
 */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly ?string $error = null)
    {
        parent::__construct($message);
    }
}
