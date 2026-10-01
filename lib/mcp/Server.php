<?php

namespace CmsmsMcp;

/**
 * JSON-RPC 2.0 / MCP message handling.
 */
final class Server
{
    const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var Context */
    private $ctx;
    /** @var Registry */
    private $registry;

    public function __construct(Context $ctx)
    {
        $this->ctx = $ctx;
        $this->registry = new Registry($ctx);
        Tools\SiteTools::register($this->registry, $ctx);
        Tools\PageTools::register($this->registry, $ctx);
        Tools\DesignTools::register($this->registry, $ctx);
        Tools\NewsTools::register($this->registry, $ctx);
        Tools\UdtTools::register($this->registry, $ctx);
        Tools\FileTools::register($this->registry, $ctx);
        Tools\UtilityTools::register($this->registry, $ctx);
        Tools\LiseTools::register($this->registry, $ctx);
        Tools\GalleryTools::register($this->registry, $ctx);
    }

    /**
     * @param array $payload A single message or a batch.
     * @return array|null Response(s), or null when nothing needs answering (HTTP 202).
     */
    public function handle(array $payload)
    {
        if (Util::isList($payload)) {
            if (!$payload) return $this->error(null, -32600, 'Invalid Request: empty batch');
            $out = [];
            foreach ($payload as $message) {
                $r = is_array($message) ? $this->handleOne($message) : $this->error(null, -32600, 'Invalid Request');
                if ($r !== null) $out[] = $r;
            }
            return $out ?: null;
        }
        return $this->handleOne($payload);
    }

    private function handleOne(array $msg)
    {
        $hasId = array_key_exists('id', $msg);
        $id = $hasId ? $msg['id'] : null;
        Http::setCurrentId($id);

        if (($msg['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
        }
        if (!isset($msg['method'])) {
            // A response from the client (we never send requests): nothing to do.
            return null;
        }
        $method = (string) $msg['method'];
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];

        if (!$hasId) {
            // Notification (notifications/initialized, notifications/cancelled...): no response.
            return null;
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->result($id, $this->initialize($params));
                case 'ping':
                    return $this->result($id, new \stdClass());
                case 'tools/list':
                    return $this->result($id, ['tools' => $this->registry->describeAll()]);
                case 'tools/call':
                    return $this->result($id, $this->callTool($params));
                case 'resources/list':
                    return $this->result($id, ['resources' => []]);
                case 'resources/templates/list':
                    return $this->result($id, ['resourceTemplates' => []]);
                case 'prompts/list':
                    return $this->result($id, ['prompts' => []]);
                case 'logging/setLevel':
                    return $this->result($id, new \stdClass());
                default:
                    return $this->error($id, -32601, "Method not found: $method");
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

        $mode = $this->ctx->readonly ? 'READ-ONLY mode: write tools are disabled.' : 'Read/write mode.';
        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'cmsms-mcp',
                'title' => 'CMS Made Simple MCP server',
                'version' => CMSMS_MCP_VERSION,
            ],
            'instructions' => implode("\n", [
                'Remote control of the CMS Made Simple site "' . $this->ctx->siteName() . '" (' . $this->ctx->rootUrl() . '), acting as CMSMS user "' . $this->ctx->username . '". ' . $mode,
                'Start with site_info. Pages: list_pages / get_page; before writing page content call get_template_blocks for the page template to learn the block ids (content_en is the main block).',
                'Content is HTML and may contain Smarty tags (e.g. {news}, {global_content name="footer"}). Pages are saved through the CMSMS API (validation, hooks, search index, routes).',
                'Items locked by someone editing in the admin console cannot be modified. Use render_page to check the public result; use clear_cache if a change is not visible.',
            ]),
        ];
    }

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Missing tool name');
        }
        $def = $this->registry->get($name);
        if (!$def) {
            throw new \InvalidArgumentException("Unknown tool: $name");
        }
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            throw new \InvalidArgumentException('Tool arguments must be an object');
        }

        try {
            if (!$this->registry->isEnabled($def)) {
                if ($def['write'] && $this->ctx->readonly) {
                    throw new ToolError("Tool '$name' is disabled: this API key is read-only (Extensions > MCP Server)");
                }
                throw new ToolError("Tool '$name' is disabled: writing UDT code must be allowed in Extensions > MCP Server settings");
            }
            foreach ($def['required'] as $req) {
                if (!array_key_exists($req, $args) || $args[$req] === null || $args[$req] === '') {
                    throw new ToolError("Missing required argument '$req'");
                }
            }
            foreach (array_keys($args) as $k) {
                if (!array_key_exists($k, $def['properties'])) {
                    throw new ToolError("Unknown argument '$k' for tool '$name'. Allowed: " . implode(', ', array_keys($def['properties'])));
                }
            }
            $result = call_user_func($def['handler'], $args, $this->ctx);
            return [
                'content' => [['type' => 'text', 'text' => Util::json($result)]],
                'isError' => false,
            ];
        } catch (ToolError $e) {
            $err = ['error' => $e->getMessage()];
            if ($e->getDetails()) $err['details'] = $e->getDetails();
            return ['content' => [['type' => 'text', 'text' => Util::json($err)]], 'isError' => true];
        } catch (\Throwable $e) {
            $err = ['error' => $e->getMessage() ?: get_class($e), 'exception' => get_class($e)];
            return ['content' => [['type' => 'text', 'text' => Util::json($err)]], 'isError' => true];
        }
    }

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
