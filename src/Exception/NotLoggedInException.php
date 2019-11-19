<?php
namespace App\Exception;

use Azura\Exception;
use Psr\Log\LogLevel;
use Throwable;

class NotLoggedInException extends Exception
{
    public function __construct(
        string $message = 'Not logged in.',
        int $code = 0,
        Throwable $previous = null,
        string $loggerLevel = LogLevel::DEBUG
    ) {
        parent::__construct($message, $code, $previous, $loggerLevel);
    }
}
