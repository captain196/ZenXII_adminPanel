<?php
namespace Tests\Support;

/** Thrown by the redirect() stub so tests can catch and assert on it. */
class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $uri, int $code = 0)
    {
        parent::__construct("Redirect to: {$uri}", $code);
    }
}

/** Thrown by the show_error() stub. */
class ShowErrorException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 500)
    {
        parent::__construct($message, $statusCode);
    }
}
