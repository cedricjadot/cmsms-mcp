<?php

namespace CmsmsMcp;

/**
 * Streamable HTTP transport (JSON responses only, no SSE, stateless).
 */
final class Http
{
    const MAX_BODY_BYTES = 33554432; // 32 MiB (file uploads are base64 in JSON)

    /** @var bool */
    private static $sent = false;
    /** @var mixed JSON-RPC id of the request being processed (for fatal error reports) */
    private static $currentId = null;
    /** @var int */
    private static $obLevel = 0;
    /** @var array */
    private static $siteConfig = [];

    /**
     * Handles one MCP HTTP request and exits. Called by the MCPServer module while
     * CMSMS loads its frontend modules, before any page, session or output.
     *
     * @param array $settings mcp_* settings: mcp_enabled, mcp_allowed_origins, ... and
     *                        mcp_authenticate: callable(string $key): ?array returning the
     *                        key's own settings (mcp_user, mcp_readonly, mcp_allow_udt_write, mcp_key)
     */
    public static function handle(array $settings)
    {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');
        self::$obLevel = ob_get_level();
        register_shutdown_function([__CLASS__, 'onShutdown']);
        ob_start();
        $payload = self::preflight($settings);
        ob_start();
        self::dispatch($payload);
    }

    /**
     * CORS, method, auth and body checks. Returns the decoded JSON-RPC payload;
     * exits on any failure.
     */
    private static function preflight(array $settings)
    {
        self::$siteConfig = $settings;
        self::discardOutput();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            self::send(204, null);
        }

        if (empty($settings['mcp_enabled'])) {
            self::sendError(503, null, -32001, 'MCP server disabled: enable it in the CMSMS admin (Extensions > MCP Server)');
        }
        // API key -> per-key settings (acting user, read-only, UDT write), or null.
        $key = null;
        $authenticate = $settings['mcp_authenticate'] ?? null;
        foreach (self::givenKeys() as $given) {
            $key = is_callable($authenticate) ? $authenticate($given) : null;
            if ($key) break;
        }
        if (!$key) {
            header('WWW-Authenticate: Bearer realm="cmsms-mcp"');
            self::sendError(401, null, -32001, 'Unauthorized: send a valid MCP API key as "Authorization: Bearer <key>" or ?key=<key> (keys are created in Extensions > MCP Server)');
        }
        unset($settings['mcp_authenticate']);
        self::$siteConfig = array_merge($settings, $key);
        self::scrubKeyFromRequest();

        if ($method !== 'POST') {
            // No server-initiated SSE stream and no sessions to terminate.
            header('Allow: POST, OPTIONS');
            self::sendError(405, null, -32000, 'Method not allowed: this MCP server only accepts POST (JSON responses, no SSE stream)');
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            self::sendError(413, null, -32600, 'Request body too large');
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            self::sendError(400, null, -32700, 'Parse error: empty body');
        }
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            self::sendError(400, null, -32700, 'Parse error: ' . json_last_error_msg());
        }
        if (!Util::isList($payload) && array_key_exists('id', $payload)) {
            self::$currentId = $payload['id'];
        }
        return $payload;
    }

    public static function siteConfig(): array
    {
        return self::$siteConfig;
    }

    /**
     * CMSMS may echo, redirect() or die() while tools run: output is buffered and
     * discarded, and a fatal/exit is turned into a JSON-RPC error by onShutdown().
     */
    private static function dispatch($payload)
    {
        try {
            $context = new Context(self::$siteConfig);
            $server = new Server($context);
        } catch (\Throwable $e) {
            self::discardOutput();
            self::sendError(500, self::$currentId, -32603, 'Server configuration error: ' . $e->getMessage());
        }
        $response = $server->handle($payload);
        self::discardOutput();
        if ($response === null) {
            self::send(202, null);
        }
        self::send(200, $response);
    }

    public static function setCurrentId($id)
    {
        self::$currentId = $id;
    }

    /**
     * Turns fatal errors, die() and exit (e.g. CMSMS redirect()) into a JSON-RPC error.
     */
    public static function onShutdown()
    {
        if (self::$sent) return;
        $output = '';
        while (ob_get_level() > self::$obLevel) {
            $output .= (string) ob_get_clean();
        }
        if (function_exists('header_remove') && !headers_sent()) {
            header_remove();
        }
        $err = error_get_last();
        $message = 'The CMS stopped the request unexpectedly';
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $message = 'PHP fatal error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'];
        } else {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($output)));
            if ($text !== '') $message .= ': ' . Util::truncate($text, 500);
        }
        self::sendCorsHeaders();
        self::sendError(500, self::$currentId, -32603, $message, false);
    }

    public static function sendError(int $status, $id, int $code, string $message, bool $exit = true)
    {
        self::send($status, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $exit);
    }

    public static function send(int $status, $body, bool $exit = true)
    {
        self::$sent = true;
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            if ($body !== null) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }
        if ($body !== null) {
            echo Util::json($body);
        }
        if ($exit) exit;
    }

    private static function discardOutput()
    {
        while (ob_get_level() > self::$obLevel) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            // CMSMS may have set headers (Content-Type, cookies, Location...).
            header_remove();
            self::sendCorsHeaders();
        }
    }

    private static function sendCorsHeaders()
    {
        if (headers_sent()) return;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = self::$siteConfig['mcp_allowed_origins'] ?? '*';
        if (is_string($allowed)) {
            $allowed = array_filter(array_map('trim', explode(',', $allowed)));
        }
        if (in_array('*', (array) $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, (array) $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
        header('Access-Control-Expose-Headers: Mcp-Session-Id, Mcp-Protocol-Version, WWW-Authenticate');
        header('Access-Control-Max-Age: 86400');
    }

    /** Keys sent by the client: Authorization: Bearer header, then ?key=. */
    private static function givenKeys(): array
    {
        $candidates = [];
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) $header = (string) $v;
            }
        }
        if ($header !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            $candidates[] = $m[1];
        }
        if (isset($_GET['key']) && is_string($_GET['key'])) {
            $candidates[] = $_GET['key'];
        }
        return array_values(array_filter(array_map('strval', $candidates), function ($k) {
            return $k !== '' && strlen($k) <= 256;
        }));
    }

    /** Keeps the token out of anything CMSMS might log or echo. */
    private static function scrubKeyFromRequest()
    {
        unset($_GET['key'], $_REQUEST['key'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        foreach (['QUERY_STRING', 'REQUEST_URI'] as $k) {
            if (isset($_SERVER[$k])) {
                $_SERVER[$k] = preg_replace('/([?&])key=[^&]*(&|$)/', '$1', $_SERVER[$k]);
                $_SERVER[$k] = rtrim($_SERVER[$k], '?&');
            }
        }
    }

}
