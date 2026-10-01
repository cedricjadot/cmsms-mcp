<?php
#-------------------------------------------------------------------------
# Module: MCPServer - Model Context Protocol server for CMS Made Simple 2.2.x
# Lets AI agents (Claude, MCP Inspector, ...) manage the site through the
# CMSMS API: pages, templates, stylesheets, designs, News, LISE, UDT, uploads.
# Each agent authenticates with its own API key, bound to a CMSMS user.
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#-------------------------------------------------------------------------

final class MCPServer extends CMSModule
{
    const MANAGE_PERM = 'Manage MCP Server';
    const KEYS_TABLE = 'module_mcpserver_keys';
    const KEY_PREFIX = 'cmsmcp_';

    /** Global settings that can be locked in config.php with $config['mcp_<name>'] */
    const CONFIG_KEYS = ['enabled', 'endpoint', 'readonly', 'render_base_url', 'allowed_origins', 'max_upload_bytes'];

    /** @var bool */
    private static $handling = false;

    public function GetName() { return 'MCPServer'; }
    public function GetFriendlyName() { return $this->Lang('friendlyname'); }
    public function GetVersion() { return '1.2.0'; }
    public function MinimumCMSVersion() { return '2.2.0'; }
    public function GetAuthor() { return 'cmsms-mcp'; }
    public function GetAuthorEmail() { return ''; }
    public function GetHelp() { return $this->Lang('help'); }
    public function GetChangeLog() { return @file_get_contents(__DIR__ . '/changelog.inc'); }
    public function HasAdmin() { return true; }
    public function GetAdminSection() { return 'extensions'; }
    public function GetAdminDescription() { return $this->Lang('admindescription'); }
    public function VisibleToAdminUser() { return $this->CheckPermission(self::MANAGE_PERM); }
    public function IsPluginModule() { return false; }
    public function InstallPostMessage() { return $this->Lang('postinstall'); }
    public function UninstallPreMessage() { return $this->Lang('really_uninstall'); }

    /** Loaded on every frontend request: it has to see MCP requests before page routing. */
    public function LazyLoadFrontend() { return false; }
    public function LazyLoadAdmin() { return true; }

    /**
     * Frontend requests: if the URL is the MCP endpoint, answer it right away and
     * exit, before index.php loads a page or starts a session.
     */
    protected function InitializeFrontend()
    {
        if (self::$handling || cmsms()->is_cli() || !$this->isEndpointRequest()) return;
        self::$handling = true;

        if (!defined('CMSMS_MCP_VERSION')) define('CMSMS_MCP_VERSION', $this->GetVersion());
        require_once __DIR__ . '/lib/mcp/bootstrap.php';

        // We run while CMSMS is still loading frontend modules: finish loading the
        // remaining ones so their hooks and APIs are available to the tools.
        $ops = ModuleOperations::get_instance();
        foreach (array_keys((array) $ops->GetInstalledModules()) as $name) {
            if ($name !== $this->GetName()) $ops->get_module_instance($name);
        }

        $settings = $this->GetSettings();
        $settings['mcp_authenticate'] = function ($key) use ($settings) {
            return $this->Authenticate((string) $key, !empty($settings['mcp_readonly']));
        };
        \CmsmsMcp\Http::handle($settings);
        exit;
    }

    // ------------------------------------------------------------------ settings

    /**
     * Global settings: module preferences, overridden by $config['mcp_*'] in config.php.
     */
    public function GetSettings(): array
    {
        $s = [
            'mcp_enabled' => (bool) $this->GetPreference('enabled', 0),
            'mcp_endpoint' => (string) $this->GetPreference('endpoint', 'mcp'),
            'mcp_readonly' => false, // forced read-only for every key (config.php only)
            'mcp_render_base_url' => (string) $this->GetPreference('render_base_url', ''),
            'mcp_allowed_origins' => (string) $this->GetPreference('allowed_origins', '*'),
            'mcp_max_upload_bytes' => (int) $this->GetPreference('max_upload_mb', 10) * 1048576,
        ];
        foreach ($this->GetConfigOverrides() as $key => $value) {
            $s['mcp_' . $key] = $value;
        }
        return $s;
    }

    /** @return array setting name => value set in config.php */
    public function GetConfigOverrides(): array
    {
        $config = cms_config::get_instance();
        $out = [];
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($config['mcp_' . $key])) $out[$key] = $config['mcp_' . $key];
        }
        return $out;
    }

    // ------------------------------------------------------------------ API keys

    /**
     * Create an API key for one agent. Returns the key in clear: it is shown once,
     * only its sha256 is stored.
     */
    public function CreateKey(string $label, int $user_id, bool $readonly, bool $allow_udt_write): string
    {
        $key = self::KEY_PREFIX . bin2hex(random_bytes(20));
        $db = $this->GetDb();
        $db->Execute(
            'INSERT INTO ' . CMS_DB_PREFIX . self::KEYS_TABLE . ' (label, key_hash, key_prefix, user_id, readonly, allow_udt_write, active, created, created_by) VALUES (?,?,?,?,?,?,1,?,?)',
            [$label, hash('sha256', $key), substr($key, 0, 12), $user_id, $readonly ? 1 : 0, $allow_udt_write ? 1 : 0, time(), (int) get_userid(false)]
        );
        audit((int) $db->Insert_ID(), $this->GetName(), 'API key created: ' . $label);
        return $key;
    }

    public function ListKeys(): array
    {
        $p = CMS_DB_PREFIX;
        return (array) $this->GetDb()->GetArray(
            "SELECT k.*, u.username, u.active AS user_active FROM {$p}" . self::KEYS_TABLE . " k LEFT JOIN {$p}users u ON u.user_id = k.user_id ORDER BY k.active DESC, k.label"
        );
    }

    public function GetKey(int $id)
    {
        return $this->GetDb()->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . self::KEYS_TABLE . ' WHERE id = ?', [$id]) ?: null;
    }

    /** @param string $field active | readonly | allow_udt_write */
    public function SetKeyFlag(int $id, string $field, bool $value)
    {
        if (!in_array($field, ['active', 'readonly', 'allow_udt_write'], true)) return;
        $this->GetDb()->Execute('UPDATE ' . CMS_DB_PREFIX . self::KEYS_TABLE . " SET $field = ? WHERE id = ?", [$value ? 1 : 0, $id]);
        $row = $this->GetKey($id);
        if ($row) audit($id, $this->GetName(), "API key {$row['label']}: $field = " . ($value ? 'yes' : 'no'));
    }

    public function DeleteKey(int $id)
    {
        $row = $this->GetKey($id);
        $this->GetDb()->Execute('DELETE FROM ' . CMS_DB_PREFIX . self::KEYS_TABLE . ' WHERE id = ?', [$id]);
        if ($row) audit($id, $this->GetName(), 'API key deleted: ' . $row['label']);
    }

    /**
     * Validate an API key; returns the per-key server settings or null.
     */
    public function Authenticate(string $key, bool $forceReadonly = false)
    {
        if (strpos($key, self::KEY_PREFIX) !== 0 || strlen($key) > 100) return null;
        $db = $this->GetDb();
        $row = $db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . self::KEYS_TABLE . ' WHERE key_hash = ? AND active = 1', [hash('sha256', $key)]);
        if (!$row) return null;
        $db->Execute('UPDATE ' . CMS_DB_PREFIX . self::KEYS_TABLE . ' SET last_used = ?, last_ip = ? WHERE id = ?', [time(), substr((string) cms_utils::get_real_ip(), 0, 45), $row['id']]);
        return [
            'mcp_user' => (int) $row['user_id'],
            'mcp_readonly' => $forceReadonly || (bool) $row['readonly'],
            'mcp_allow_udt_write' => (bool) $row['allow_udt_write'],
            'mcp_key' => ['id' => (int) $row['id'], 'label' => $row['label'], 'prefix' => $row['key_prefix']],
        ];
    }

    // ------------------------------------------------------------------ endpoint

    /**
     * The endpoint answers on <root>/<endpoint> (pretty URLs), <root>/index.php/<endpoint>
     * and <root>/index.php?page=<endpoint>.
     */
    private function isEndpointRequest(): bool
    {
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') return false;
        $config = cms_config::get_instance();
        $slug = trim((string) (isset($config['mcp_endpoint']) ? $config['mcp_endpoint'] : $this->GetPreference('endpoint', 'mcp')), '/');
        if ($slug === '') return false;

        if (isset($_GET['page']) && trim((string) $_GET['page'], '/') === $slug) return true;

        $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $base = rtrim((string) parse_url((string) $config['root_url'], PHP_URL_PATH), '/');
        foreach ([$base . '/' . $slug, $base . '/index.php/' . $slug] as $candidate) {
            if (rtrim($path, '/') === $candidate) return true;
        }
        return false;
    }

    /** Public URLs of the endpoint, best first. */
    public function GetEndpointUrls(): array
    {
        $config = cms_config::get_instance();
        $root = rtrim((string) $config['root_url'], '/');
        $slug = trim((string) $this->GetSettings()['mcp_endpoint'], '/');
        $urls = [];
        if ($config['url_rewriting'] === 'mod_rewrite') $urls[] = $root . '/' . $slug;
        $urls[] = $root . '/index.php/' . $slug;
        $urls[] = $root . '/index.php?page=' . rawurlencode($slug);
        return $urls;
    }
}
