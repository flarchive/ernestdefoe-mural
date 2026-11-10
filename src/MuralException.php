<?php

namespace ErnestDefoe\Mural;

/** A refusal the browser turns into a sentence: a code, a status and anything it needs to say (when, until). */
class MuralException extends \RuntimeException
{
    /** @param array<string, mixed> $meta */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $meta = [])
    {
        parent::__construct($errorCode);
    }
}
