<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * Files under the uploads directory only ($config['uploads_path']).
 * Executable/server-config file types are refused.
 */
final class FileTools
{
    const BLOCKED_EXT = '/\.(php\d*|phtml|phar|pht|phps|inc|cgi|pl|py|rb|sh|bash|asp|aspx|jsp|exe|dll|so|htaccess|htpasswd|user\.ini|shtml?)$/i';
    const TEXT_EXT = ['txt', 'css', 'js', 'json', 'html', 'htm', 'xml', 'svg', 'csv', 'md', 'tpl', 'ini', 'yml', 'yaml', 'map', 'vtt', 'srt'];
    const DEFAULT_MAX_BYTES = 10485760; // 10 MiB

    public static function register(Registry $r, Context $ctx)
    {
        $path = ['type' => 'string', 'description' => 'Path relative to the uploads directory, e.g. "images/logo.png" ("" = uploads root)'];
        $r->add([
            'name' => 'list_files',
            'title' => 'List upload files',
            'description' => 'List files and folders in the uploads directory (name, size, type, modified, public URL).',
            'properties' => ['path' => $path, 'recursive' => ['type' => 'boolean', 'description' => 'Walk sub-folders (max 2000 entries)']],
            'handler' => [__CLASS__, 'listFiles'],
        ]);
        $r->add([
            'name' => 'read_file',
            'title' => 'Read upload file',
            'description' => 'Read a file from uploads: text files as UTF-8 text, other files as base64 (max 5 MiB).',
            'properties' => ['path' => $path],
            'required' => ['path'],
            'handler' => [__CLASS__, 'readFile'],
        ]);
        $r->add([
            'name' => 'write_file',
            'title' => 'Write upload file',
            'description' => 'Create or replace a file in uploads (e.g. an image to use in a page). Content as text or base64. Folders are created as needed. PHP and other executable types are refused.',
            'properties' => [
                'path' => $path,
                'content' => ['type' => 'string', 'description' => 'File content (text, or base64 when encoding=base64)'],
                'encoding' => ['type' => 'string', 'enum' => ['text', 'base64'], 'description' => 'Default text'],
                'overwrite' => ['type' => 'boolean', 'description' => 'Replace an existing file (default false)'],
            ],
            'required' => ['path', 'content'],
            'write' => true,
            'handler' => [__CLASS__, 'writeFile'],
        ]);
        $r->add([
            'name' => 'create_folder',
            'title' => 'Create upload folder',
            'description' => 'Create a folder (and parents) in uploads.',
            'properties' => ['path' => $path],
            'required' => ['path'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'createFolder'],
        ]);
        $r->add([
            'name' => 'move_file',
            'title' => 'Move/rename upload file',
            'description' => 'Move or rename a file or folder inside uploads.',
            'properties' => ['from' => $path, 'to' => $path, 'overwrite' => ['type' => 'boolean']],
            'required' => ['from', 'to'],
            'write' => true,
            'handler' => [__CLASS__, 'moveFile'],
        ]);
        $r->add([
            'name' => 'delete_file',
            'title' => 'Delete upload file',
            'description' => 'Delete a file, or an empty folder, in uploads.',
            'properties' => ['path' => $path],
            'required' => ['path'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteFile'],
        ]);
    }

    public static function listFiles(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$abs, $rel] = self::resolve($ctx, Util::str($args, 'path', ''), true);
        if (!is_dir($abs)) throw new ToolError("'$rel' is not a folder");
        $recursive = (bool) Util::bool($args, 'recursive', false);
        $out = [];
        $truncated = false;
        $walk = function ($dir, $relDir) use (&$walk, &$out, &$truncated, $recursive, $ctx) {
            $items = @scandir($dir);
            if ($items === false) return;
            foreach ($items as $name) {
                if ($name === '.' || $name === '..' || $name[0] === '.') continue;
                if (count($out) >= 2000) {
                    $truncated = true;
                    return;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                $r = ltrim($relDir . '/' . $name, '/');
                $isDir = is_dir($full);
                $out[] = [
                    'path' => $r,
                    'type' => $isDir ? 'folder' : 'file',
                    'size' => $isDir ? null : @filesize($full),
                    'modified' => date('c', (int) @filemtime($full)),
                    'url' => $isDir ? null : self::publicUrl($ctx, $r),
                ];
                if ($isDir && $recursive) $walk($full, $r);
            }
        };
        $walk($abs, $rel);
        return ['path' => $rel, 'count' => count($out), 'truncated' => $truncated, 'entries' => $out];
    }

    public static function readFile(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$abs, $rel] = self::resolve($ctx, Util::requireStr($args, 'path'), true);
        if (!is_file($abs)) throw new ToolError("'$rel' is not a file");
        $size = filesize($abs);
        if ($size > 5242880) throw new ToolError("File too large to read through MCP ($size bytes, max 5 MiB)");
        $data = file_get_contents($abs);
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $isText = in_array($ext, self::TEXT_EXT, true) && (!function_exists('mb_check_encoding') || mb_check_encoding($data, 'UTF-8'));
        return [
            'path' => $rel,
            'size' => $size,
            'mime_type' => self::mime($abs),
            'url' => self::publicUrl($ctx, $rel),
            'encoding' => $isText ? 'text' : 'base64',
            'content' => $isText ? $data : base64_encode($data),
        ];
    }

    public static function writeFile(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$abs, $rel] = self::resolve($ctx, Util::requireStr($args, 'path'), false);
        self::assertAllowedName($rel);
        $content = Util::str($args, 'content', '');
        if (Util::str($args, 'encoding', 'text') === 'base64') {
            $content = base64_decode(preg_replace('/\s+/', '', $content), true);
            if ($content === false) throw new ToolError('content is not valid base64');
        }
        $max = (int) ($ctx->siteConfig['mcp_max_upload_bytes'] ?? self::DEFAULT_MAX_BYTES);
        if (strlen($content) > $max) throw new ToolError('File too large (' . strlen($content) . " bytes, max $max; see Extensions > MCP Server settings)");
        $exists = file_exists($abs);
        if ($exists && is_dir($abs)) throw new ToolError("'$rel' is a folder");
        if ($exists && !Util::bool($args, 'overwrite', false)) throw new ToolError("'$rel' already exists: pass overwrite=true to replace it");

        self::mkdirs($ctx, dirname($abs));
        if (file_put_contents($abs, $content, LOCK_EX) === false) throw new ToolError("Could not write '$rel' (permissions?)");
        $mode = octdec((string) $ctx->config['default_upload_permission']);
        if ($mode) @chmod($abs, $mode);
        audit('', 'File: ' . $rel, ($exists ? 'Replaced' : 'Uploaded') . ' (MCP)');
        return [($exists ? 'replaced' : 'created') => true, 'path' => $rel, 'size' => strlen($content), 'url' => self::publicUrl($ctx, $rel)];
    }

    public static function createFolder(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$abs, $rel] = self::resolve($ctx, Util::requireStr($args, 'path'), false);
        if (is_file($abs)) throw new ToolError("'$rel' is a file");
        $existed = is_dir($abs);
        self::mkdirs($ctx, $abs);
        return ['path' => $rel, 'created' => !$existed];
    }

    public static function moveFile(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$from, $relFrom] = self::resolve($ctx, Util::requireStr($args, 'from'), true);
        [$to, $relTo] = self::resolve($ctx, Util::requireStr($args, 'to'), false);
        if ($relFrom === '') throw new ToolError('Cannot move the uploads root');
        if (is_file($from)) self::assertAllowedName($relTo);
        if (file_exists($to)) {
            if (!Util::bool($args, 'overwrite', false) || is_dir($to)) throw new ToolError("'$relTo' already exists");
        }
        if (is_dir($from) && Util::startsWith($relTo . '/', $relFrom . '/')) throw new ToolError('Cannot move a folder into itself');
        self::mkdirs($ctx, dirname($to));
        if (!@rename($from, $to)) throw new ToolError("Could not move '$relFrom' to '$relTo'");
        audit('', 'File: ' . $relFrom, 'Moved to ' . $relTo . ' (MCP)');
        return ['moved' => true, 'from' => $relFrom, 'to' => $relTo, 'url' => is_file($to) ? self::publicUrl($ctx, $relTo) : null];
    }

    public static function deleteFile(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Files');
        [$abs, $rel] = self::resolve($ctx, Util::requireStr($args, 'path'), true);
        if ($rel === '') throw new ToolError('Cannot delete the uploads root');
        if (is_dir($abs)) {
            if (count(array_diff((array) scandir($abs), ['.', '..'])) > 0) throw new ToolError("Folder '$rel' is not empty");
            if (!@rmdir($abs)) throw new ToolError("Could not delete folder '$rel'");
        } elseif (!@unlink($abs)) {
            throw new ToolError("Could not delete '$rel'");
        }
        audit('', 'File: ' . $rel, 'Deleted (MCP)');
        return ['deleted' => true, 'path' => $rel];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array [absolute path, normalised relative path]
     */
    private static function resolve(Context $ctx, string $path, bool $mustExist): array
    {
        $base = realpath((string) $ctx->config['uploads_path']);
        if (!$base || !is_dir($base)) throw new ToolError('The uploads directory is not available');
        if (strpos($path, "\0") !== false) throw new ToolError('Invalid path');

        $parts = [];
        foreach (preg_split('#[\\\\/]+#', trim($path)) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') throw new ToolError('".." is not allowed in paths');
            if ($seg[0] === '.') throw new ToolError('Hidden files and folders (starting with ".") are not allowed');
            $parts[] = $seg;
        }
        $rel = implode('/', $parts);
        $abs = $base . ($rel === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));

        if ($mustExist) {
            $real = realpath($abs);
            if ($real === false) throw new ToolError("'$rel' does not exist in uploads");
            if ($real !== $base && !Util::startsWith($real, $base . DIRECTORY_SEPARATOR)) throw new ToolError('Path escapes the uploads directory');
        } else {
            // Nearest existing ancestor must be inside uploads (symlink safety).
            $probe = $abs;
            while (!file_exists($probe) && $probe !== dirname($probe)) $probe = dirname($probe);
            $real = realpath($probe);
            if ($real === false || ($real !== $base && !Util::startsWith($real, $base . DIRECTORY_SEPARATOR))) {
                throw new ToolError('Path escapes the uploads directory');
            }
        }
        return [$abs, $rel];
    }

    private static function assertAllowedName(string $rel)
    {
        if ($rel === '') throw new ToolError('A file name is required');
        if (preg_match(self::BLOCKED_EXT, $rel) || preg_match('/\.(php\d*|phtml|phar)\./i', $rel)) {
            throw new ToolError('This file type is not allowed (executable or server configuration)');
        }
    }

    private static function mkdirs(Context $ctx, string $dir)
    {
        if (is_dir($dir)) return;
        if (!@mkdir($dir, 0777 & ~umask(), true) && !is_dir($dir)) {
            throw new ToolError('Could not create folder (permissions?)');
        }
    }

    private static function publicUrl(Context $ctx, string $rel): string
    {
        return rtrim((string) $ctx->config['uploads_url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    private static function mime(string $abs): string
    {
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($abs);
            if ($m) return $m;
        }
        return 'application/octet-stream';
    }
}
