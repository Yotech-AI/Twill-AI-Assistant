<?php

namespace TwillAi\Exceptions;

use RuntimeException;

/**
 * Recoverable Twill AI error. Tool handlers catch this and return the message
 * to the model so it can correct itself; it is never a server fault.
 */
class TwillAiException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self("The request was rejected:\n- ".implode("\n- ", $errors));
    }
}
