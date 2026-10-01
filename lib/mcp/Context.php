<?php

namespace CmsmsMcp;

/**
 * Request context once CMSMS is loaded: acting user, permissions, helpers.
 */
final class Context
{
    /** @var bool */
    public $readonly;
    /** @var bool */
    public $allowUdtWrite;
    /** @var int */
    public $uid;
    /** @var string */
    public $username;
    /** @var \User */
    public $user;
    /** @var \CmsApp */
    public $app;
    /** @var \CMSMS\Database\Connection */
    public $db;
    /** @var \cms_config */
    public $config;
    /** @var array mcp_* settings (module preferences, overridable in config.php) */
    public $siteConfig;

    public function __construct(array $siteConfig)
    {
        $this->siteConfig = $siteConfig;
        $this->readonly = self::truthy($siteConfig['mcp_readonly'] ?? false);
        $this->allowUdtWrite = self::truthy($siteConfig['mcp_allow_udt_write'] ?? false);
        $this->app = \CmsApp::get_instance();
        $this->db = $this->app->GetDb();
        $this->config = \cms_config::get_instance();

        $this->resolveUser($siteConfig['mcp_user'] ?? null);
        $this->injectLogin();
        $this->prepareSmartyForBlockParsing();
    }

    private static function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveUser($ref)
    {
        if ($ref === null || $ref === '' || (string) $ref === '0') {
            throw new \RuntimeException('No acting user selected: choose the CMSMS account the MCP server acts as in Extensions > MCP Server');
        }
        $userops = \UserOperations::get_instance();
        $uid = is_numeric($ref)
            ? (int) $ref
            : (int) $this->db->GetOne('SELECT user_id FROM ' . CMS_DB_PREFIX . 'users WHERE username = ?', [(string) $ref]);
        $user = $uid > 0 ? $userops->LoadUserByID($uid) : null;
        if (!$user) {
            throw new \RuntimeException("The acting user ($ref) does not match any CMSMS user: check Extensions > MCP Server");
        }
        if (!$user->active) {
            throw new \RuntimeException("The acting CMSMS user '{$user->username}' is inactive");
        }
        $this->user = $user;
        $this->uid = (int) $user->id;
        $this->username = (string) $user->username;
    }

    /**
     * get_userid()/get_username() read LoginOperations::_data (normally built from
     * the admin session cookie) and redirect to login.php when it is empty. Fill it
     * in memory for this request only: no session, no cookie.
     */
    private function injectLogin()
    {
        $data = [
            'uid' => $this->uid,
            'username' => $this->username,
            'eff_uid' => null,
            'eff_username' => null,
            'hash' => 'cmsms-mcp',
        ];
        $ops = \CMSMS\LoginOperations::get_instance();
        $setter = function (array $d) {
            $this->_data = $d;
        };
        $setter->call($ops, $data);
        if ((int) get_userid(false) !== $this->uid) {
            throw new \RuntimeException('Could not initialise the CMSMS user context');
        }
    }

    /**
     * In a frontend request Smarty_CMS registers the *rendering* versions of
     * {content}, {content_image} and {content_module}. page_template_parser then
     * silently fails to register its block-collecting compilers (Smarty refuses
     * duplicate plugins), so no block is detected and Content::ValidateData()
     * fails. This server never renders pages in-process, so drop them.
     */
    private function prepareSmartyForBlockParsing()
    {
        $smarty = $this->app->GetSmarty();
        foreach (['compiler', 'function', 'block'] as $type) {
            foreach (['content', 'content_image', 'content_module'] as $tag) {
                $smarty->unregisterPlugin($type, $tag);
            }
        }
    }

    // ---------------------------------------------------------------- permissions

    public function can(string ...$perms): bool
    {
        foreach ($perms as $p) {
            if (check_permission($this->uid, $p)) return true;
        }
        return false;
    }

    public function requirePerm(string ...$perms)
    {
        if (!$this->can(...$perms)) {
            throw new ToolError("Permission denied: user '{$this->username}' needs the CMSMS permission " . implode(' or ', array_map(function ($p) {
                return "'$p'";
            }, $perms)));
        }
    }

    public function isSuperuser(): bool
    {
        return \UserOperations::get_instance()->IsSuperuser($this->uid);
    }

    // ---------------------------------------------------------------- locks

    /**
     * Refuse to modify an object someone is editing in the admin console.
     * (Direct query: CmsLockOperations::is_locked() sleeps one second.)
     */
    public function assertNotLocked(string $type, int $oid)
    {
        $row = $this->db->GetRow(
            'SELECT l.uid, l.expires, u.username FROM ' . CMS_DB_PREFIX . 'locks l LEFT JOIN ' . CMS_DB_PREFIX . 'users u ON u.user_id = l.uid WHERE l.type = ? AND l.oid = ? AND l.expires >= ?',
            [$type, $oid, time()]
        );
        if ($row) {
            throw new ToolError(sprintf(
                'This %s (id %d) is locked: user "%s" is editing it in the admin console (lock expires %s). Retry later or ask them to close the editor.',
                $type, $oid, $row['username'] ?: ('#' . $row['uid']), date('c', (int) $row['expires'])
            ));
        }
    }

    public function lockInfo(string $type, int $oid)
    {
        $row = $this->db->GetRow(
            'SELECT l.uid, l.expires, u.username FROM ' . CMS_DB_PREFIX . 'locks l LEFT JOIN ' . CMS_DB_PREFIX . 'users u ON u.user_id = l.uid WHERE l.type = ? AND l.oid = ? AND l.expires >= ?',
            [$type, $oid, time()]
        );
        return $row ? ['user' => $row['username'], 'expires' => date('c', (int) $row['expires'])] : null;
    }

    // ---------------------------------------------------------------- helpers

    public function dbTime(int $ts): string
    {
        return trim($this->db->DBTimeStamp($ts), "'");
    }

    public function siteName(): string
    {
        return (string) \cms_siteprefs::get('sitename', 'CMSMS Site');
    }

    public function rootUrl(): string
    {
        return (string) $this->config['root_url'];
    }

    /** @return \CMSModule */
    public function module(string $name)
    {
        $mod = \cms_utils::get_module($name);
        if (!is_object($mod)) {
            throw new ToolError("The CMSMS module '$name' is not installed or not active");
        }
        return $mod;
    }

    /** Make sure a class from modules/<Module>/lib is loaded. */
    public function moduleClass(string $module, string $class)
    {
        $this->module($module);
        if (!class_exists($class, true)) {
            $fn = CMS_ROOT_PATH . "/modules/$module/lib/class.$class.php";
            if (is_file($fn)) require_once $fn;
        }
        if (!class_exists($class, false)) {
            throw new \RuntimeException("Class $class (module $module) not found");
        }
    }

    public function pageDefaults(): array
    {
        $this->moduleClass('CMSContentManager', 'CmsContentManagerUtils');
        return \CmsContentManagerUtils::get_pagedefaults();
    }

    /**
     * Content blocks declared by a page template ({content}, {content_image}, {content_module}).
     * @return array block name => info (id = content property name)
     */
    public function templateBlocks(int $templateId): array
    {
        \CMS_Content_Block::reset();
        try {
            $parser = new \CMSMS\internal\page_template_parser('cms_template:' . $templateId, $this->app->GetSmarty());
            $parser->compileTemplateSource();
        } catch (\SmartyException $e) {
            throw new ToolError('Could not parse template ' . $templateId . ': ' . $e->getMessage());
        }
        $blocks = \CMS_Content_Block::get_content_blocks();
        return is_array($blocks) ? $blocks : [];
    }

    public function userName(int $uid)
    {
        static $cache = [];
        if ($uid <= 0) return null;
        if (!array_key_exists($uid, $cache)) {
            $cache[$uid] = $this->db->GetOne('SELECT username FROM ' . CMS_DB_PREFIX . 'users WHERE user_id = ?', [$uid]) ?: null;
        }
        return $cache[$uid];
    }
}
