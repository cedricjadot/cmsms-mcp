<?php

namespace CmsmsMcp;

final class Util
{
    public static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }

    public static function json($value): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        $out = json_encode($value, $flags);
        if ($out === false) {
            $out = json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'JSON encoding failed: ' . json_last_error_msg()]]);
        }
        return $out;
    }

    public static function truncate(string $s, int $max): string
    {
        if ($max <= 0) return $s;
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
        }
        return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }

    /** Read a boolean argument ("true", 1, "yes"... accepted). */
    public static function bool(array $args, string $key, $default = null)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) return $default;
        $v = $args[$key];
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int) $v) !== 0;
        $v = strtolower(trim((string) $v));
        if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'n', 'off', ''], true)) return false;
        throw new ToolError("Argument '$key' must be a boolean");
    }

    public static function int(array $args, string $key, $default = null)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') return $default;
        if (!is_numeric($args[$key])) throw new ToolError("Argument '$key' must be an integer");
        return (int) $args[$key];
    }

    public static function str(array $args, string $key, $default = null)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) return $default;
        if (is_array($args[$key]) || is_object($args[$key])) throw new ToolError("Argument '$key' must be a string");
        return (string) $args[$key];
    }

    public static function requireStr(array $args, string $key): string
    {
        $v = self::str($args, $key);
        if ($v === null || trim($v) === '') throw new ToolError("Missing required argument '$key'");
        return $v;
    }

    public static function arr(array $args, string $key, $default = null)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) return $default;
        if (!is_array($args[$key])) throw new ToolError("Argument '$key' must be an array/object");
        return $args[$key];
    }

    /** @return int[] */
    public static function intList(array $args, string $key, $default = null)
    {
        $v = self::arr($args, $key);
        if ($v === null) return $default;
        $out = [];
        foreach ($v as $item) {
            if (!is_numeric($item)) throw new ToolError("Argument '$key' must be a list of integer ids");
            $out[] = (int) $item;
        }
        return $out;
    }

    /**
     * Parse a date argument: ISO 8601 / "YYYY-MM-DD HH:MM[:SS]" (site timezone) or unix timestamp.
     */
    public static function date(array $args, string $key, $default = null)
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') return $default;
        $v = $args[$key];
        if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int) $v;
        $ts = strtotime((string) $v);
        if ($ts === false) throw new ToolError("Argument '$key' is not a valid date (use ISO 8601, e.g. 2025-01-31T14:00:00)");
        return $ts;
    }

    /** Database datetime string (as stored by CMSMS) to ISO 8601, null-safe. */
    public static function isoDate($dbValue)
    {
        if ($dbValue === null || $dbValue === '' || $dbValue === '0000-00-00 00:00:00') return null;
        $ts = is_numeric($dbValue) ? (int) $dbValue : strtotime((string) $dbValue);
        return $ts ? date('c', $ts) : null;
    }

    /** Limit/offset pagination helper. */
    public static function page(array $args, int $defaultLimit = 50, int $maxLimit = 500): array
    {
        $limit = self::int($args, 'limit', $defaultLimit);
        $offset = self::int($args, 'offset', 0);
        return [max(1, min($maxLimit, $limit)), max(0, $offset)];
    }

    public static function likeEscape(string $s): string
    {
        return addcslashes($s, '\\%_');
    }
}
