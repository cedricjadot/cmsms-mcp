<?php

namespace CmsmsMcp;

/**
 * Holds the tool definitions and filters them for the current configuration
 * (read-only mode, UDT write opt-in).
 */
final class Registry
{
    /** @var array<string,array> */
    private $tools = [];
    /** @var Context */
    private $ctx;

    public function __construct(Context $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * @param array $def name, title, description, properties, required, handler,
     *                   write (bool), destructive (bool), idempotent (bool), requires_udt_write (bool)
     */
    public function add(array $def)
    {
        $def += [
            'properties' => [],
            'required' => [],
            'write' => false,
            'destructive' => false,
            'idempotent' => false,
            'requires_udt_write' => false,
        ];
        $this->tools[$def['name']] = $def;
    }

    public function isEnabled(array $def): bool
    {
        if ($def['write'] && $this->ctx->readonly) return false;
        if ($def['requires_udt_write'] && !$this->ctx->allowUdtWrite) return false;
        return true;
    }

    public function get(string $name)
    {
        return $this->tools[$name] ?? null;
    }

    public function describeAll(): array
    {
        $out = [];
        foreach ($this->tools as $def) {
            if (!$this->isEnabled($def)) continue;
            $schema = ['type' => 'object', 'properties' => (object) $def['properties']];
            if ($def['required']) $schema['required'] = $def['required'];
            $schema['additionalProperties'] = false;
            $out[] = [
                'name' => $def['name'],
                'title' => $def['title'],
                'description' => $def['description'],
                'inputSchema' => $schema,
                'annotations' => [
                    'title' => $def['title'],
                    'readOnlyHint' => !$def['write'],
                    'destructiveHint' => $def['write'] && $def['destructive'],
                    'idempotentHint' => !$def['write'] || $def['idempotent'],
                    'openWorldHint' => false,
                ],
            ];
        }
        return $out;
    }
}
