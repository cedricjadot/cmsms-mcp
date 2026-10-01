<?php

namespace CmsmsMcp;

/**
 * An error the model should see and can act on (bad arguments, missing
 * permission, validation failure...). Reported as a tool result with
 * isError=true, not as a JSON-RPC protocol error.
 */
class ToolError extends \RuntimeException
{
    /** @var array */
    private $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
