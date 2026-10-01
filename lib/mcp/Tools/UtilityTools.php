<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * search, clear_cache, render_page.
 */
final class UtilityTools
{
    const SCOPES = ['pages', 'news', 'templates', 'stylesheets', 'udts'];

    public static function register(Registry $r, Context $ctx)
    {
        $r->add([
            'name' => 'search',
            'title' => 'Search the site',
            'description' => 'Full-text substring search across page titles/aliases/content blocks, news, templates, stylesheets and UDTs (sources, not the rendered site). Returns matches with a snippet.',
            'properties' => [
                'query' => ['type' => 'string'],
                'scopes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::SCOPES], 'description' => 'Default: all'],
                'limit' => ['type' => 'integer', 'description' => 'Max results per scope (default 20, max 100)'],
            ],
            'required' => ['query'],
            'handler' => [__CLASS__, 'search'],
        ]);
        $r->add([
            'name' => 'clear_cache',
            'title' => 'Clear CMS cache',
            'description' => 'Clear CMSMS caches (compiled templates, cached pages/stylesheets, internal caches), like Site Admin > System Maintenance.',
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'clearCache'],
        ]);
        $r->add([
            'name' => 'render_page',
            'title' => 'Render page',
            'description' => 'Fetch the public, rendered HTML of a page (over HTTP, as a visitor sees it) to check the result of changes. Give page (id/alias) or path (URL path relative to the site root, e.g. "news/my-article").',
            'properties' => [
                'page' => ['type' => ['integer', 'string'], 'description' => 'Page id or alias'],
                'path' => ['type' => 'string', 'description' => 'Path relative to the site root URL'],
                'format' => ['type' => 'string', 'enum' => ['html', 'text'], 'description' => 'html (default) or visible text only'],
                'max_chars' => ['type' => 'integer', 'description' => 'Truncate output (default 50000)'],
            ],
            'handler' => [__CLASS__, 'renderPage'],
        ]);
    }

    // ---------------------------------------------------------------- search

    public static function search(array $args, Context $ctx): array
    {
        $q = trim(Util::requireStr($args, 'query'));
        if (strlen($q) < 2) throw new ToolError('query must have at least 2 characters');
        $scopes = Util::arr($args, 'scopes', self::SCOPES);
        $limit = max(1, min(100, (int) Util::int($args, 'limit', 20)));
        $like = '%' . Util::likeEscape($q) . '%';
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;
        $out = [];

        if (in_array('pages', $scopes, true)) {
            $hits = [];
            foreach ((array) $db->GetArray("SELECT content_id, content_name, content_alias, menu_text FROM {$p}content WHERE content_name LIKE ? OR content_alias LIKE ? OR menu_text LIKE ? OR page_url LIKE ? ORDER BY hierarchy", [$like, $like, $like, $like]) as $r) {
                $hits[(int) $r['content_id']] = ['id' => (int) $r['content_id'], 'title' => $r['content_name'], 'alias' => $r['content_alias'], 'matches' => [['field' => 'title/alias/menu_text']]];
            }
            foreach ((array) $db->GetArray("SELECT p.content_id, p.prop_name, p.content, c.content_name, c.content_alias FROM {$p}content_props p JOIN {$p}content c ON c.content_id = p.content_id WHERE p.content LIKE ? ORDER BY c.hierarchy", [$like]) as $r) {
                $id = (int) $r['content_id'];
                if (!isset($hits[$id])) $hits[$id] = ['id' => $id, 'title' => $r['content_name'], 'alias' => $r['content_alias'], 'matches' => []];
                $hits[$id]['matches'][] = ['field' => $r['prop_name'], 'snippet' => self::snippet((string) $r['content'], $q)];
            }
            $out['pages'] = array_slice(array_values($hits), 0, $limit);
        }

        if (in_array('news', $scopes, true) && \cms_utils::get_module('News')) {
            $out['news'] = [];
            $rs = $db->SelectLimit("SELECT news_id, news_title, status, summary, news_data FROM {$p}module_news WHERE news_title LIKE ? OR summary LIKE ? OR news_data LIKE ? OR news_extra LIKE ? ORDER BY news_date DESC", $limit, -1, [$like, $like, $like, $like]);
            while ($rs && !$rs->EOF()) {
                $r = $rs->fields;
                $text = $r['news_title'] . ' ' . $r['summary'] . ' ' . $r['news_data'];
                $out['news'][] = ['id' => (int) $r['news_id'], 'title' => $r['news_title'], 'status' => $r['status'], 'snippet' => self::snippet($text, $q)];
                $rs->MoveNext();
            }
        }

        if (in_array('templates', $scopes, true)) {
            $out['templates'] = [];
            $rs = $db->SelectLimit("SELECT id, name, content FROM {$p}layout_templates WHERE name LIKE ? OR content LIKE ? OR description LIKE ? ORDER BY name", $limit, -1, [$like, $like, $like]);
            while ($rs && !$rs->EOF()) {
                $r = $rs->fields;
                $out['templates'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'snippet' => self::snippet((string) $r['content'], $q, false)];
                $rs->MoveNext();
            }
        }

        if (in_array('stylesheets', $scopes, true)) {
            $out['stylesheets'] = [];
            $rs = $db->SelectLimit("SELECT id, name, content FROM {$p}layout_stylesheets WHERE name LIKE ? OR content LIKE ? ORDER BY name", $limit, -1, [$like, $like]);
            while ($rs && !$rs->EOF()) {
                $r = $rs->fields;
                $out['stylesheets'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'snippet' => self::snippet((string) $r['content'], $q, false)];
                $rs->MoveNext();
            }
        }

        if (in_array('udts', $scopes, true) && $ctx->can('Modify User-defined Tags')) {
            $out['udts'] = [];
            $rs = $db->SelectLimit("SELECT userplugin_id, userplugin_name, code FROM {$p}userplugins WHERE userplugin_name LIKE ? OR code LIKE ? OR description LIKE ? ORDER BY userplugin_name", $limit, -1, [$like, $like, $like]);
            while ($rs && !$rs->EOF()) {
                $r = $rs->fields;
                $out['udts'][] = ['id' => (int) $r['userplugin_id'], 'name' => $r['userplugin_name'], 'snippet' => self::snippet((string) $r['code'], $q, false)];
                $rs->MoveNext();
            }
        }

        return ['query' => $q, 'results' => $out];
    }

    private static function snippet(string $text, string $q, bool $stripTags = true): string
    {
        if ($stripTags) $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $pos = function_exists('mb_stripos') ? mb_stripos($text, $q, 0, 'UTF-8') : stripos($text, $q);
        if ($pos === false) return Util::truncate($text, 160);
        $start = max(0, $pos - 70);
        $piece = function_exists('mb_substr') ? mb_substr($text, $start, 160, 'UTF-8') : substr($text, $start, 160);
        return ($start > 0 ? '…' : '') . $piece . '…';
    }

    // ---------------------------------------------------------------- cache

    public static function clearCache(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Site Preferences', 'Manage All Content', 'Modify Templates', 'Manage Stylesheets', 'Manage Designs');
        $ctx->app->clear_cached_files();
        if (class_exists('CmsTemplateCache')) \CmsTemplateCache::clear_cache();
        \CMSMS\internal\global_cache::clear_all();
        audit('', 'Core', 'Cleared cache (MCP)');
        return ['cleared' => true];
    }

    // ---------------------------------------------------------------- render

    public static function renderPage(array $args, Context $ctx): array
    {
        $root = rtrim($ctx->rootUrl(), '/');
        if (isset($args['page']) && $args['page'] !== '') {
            $id = PageTools::resolvePageId($args['page']);
            $obj = \ContentOperations::get_instance()->LoadContentFromId($id);
            if (!$obj) throw new ToolError("Page $id not found");
            if (!$obj->Active()) throw new ToolError("Page $id is inactive: visitors cannot see it (activate it with update_page)");
            if (!$obj->HasUsableLink()) throw new ToolError("Page $id ({$obj->Type()}) has no viewable URL");
            $url = $obj->GetURL();
        } elseif (($path = Util::str($args, 'path')) !== null) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $path)) throw new ToolError('path must be relative to the site root (no scheme or host)');
            $url = $root . '/' . ltrim($path, '/');
        } else {
            throw new ToolError('Give page or path');
        }

        // Inside a container/proxy the public root URL may not be reachable from the server itself.
        $base = rtrim((string) ($ctx->siteConfig['mcp_render_base_url'] ?? ''), '/');
        $fetchUrl = ($base !== '' && Util::startsWith($url, $root)) ? $base . substr($url, strlen($root)) : $url;
        if (!preg_match('#^https?://#i', $fetchUrl)) throw new ToolError("Cannot fetch '$fetchUrl'");

        [$status, $type, $body, $finalUrl] = self::httpGet($fetchUrl);
        $max = max(1000, (int) Util::int($args, 'max_chars', 50000));
        $format = Util::str($args, 'format', 'html');
        if ($format === 'text') {
            $body = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $body);
            $body = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $body);
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
            $body = trim(preg_replace(["/[ \t]+/", "/\n\s*\n+/"], [' ', "\n\n"], $body));
        }
        $length = strlen($body);
        return [
            'url' => $url,
            'fetched_url' => $finalUrl,
            'status' => $status,
            'content_type' => $type,
            'length' => $length,
            'truncated' => $length > $max,
            'content' => Util::truncate($body, $max),
        ];
    }

    private static function httpGet(string $url): array
    {
        $timeout = 20;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'cmsms-mcp/' . CMSMS_MCP_VERSION . ' (render_page)',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new ToolError("Could not fetch $url: $err (set the render base URL in Extensions > MCP Server settings if the public URL is not reachable from the server)");
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            return [$status, $type, (string) $body, $final];
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => 'User-Agent: cmsms-mcp/' . CMSMS_MCP_VERSION . " (render_page)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ToolError("Could not fetch $url (set the render base URL in Extensions > MCP Server settings if the public URL is not reachable from the server)");
        }
        $status = 0;
        $type = '';
        foreach ((array) ($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
            if (stripos($h, 'Content-Type:') === 0) $type = trim(substr($h, 13));
        }
        return [$status, $type, $body, $url];
    }
}
