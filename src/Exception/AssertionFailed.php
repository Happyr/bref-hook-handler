<?php

declare(strict_types=1);

namespace Happyr\BrefHookHandler\Exception;

class AssertionFailed extends \RuntimeException
{
    public static function create(string $message, ...$args): self
    {
        return new self(($args ? vsprintf($message, $args) : $message));
    }
}
