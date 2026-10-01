<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;

final class SiteTools
{
    public static function register(Registry $r, Context $ctx)
    {
        $r->add([
            'name' => 'site_info',
            'title' => 'Site information',
            'description' => 'CMSMS version, site name and URLs, acting user and its permissions, server mode (read-only, UDT write), installed modules, content types, defaults and item counts. Call this first.',
            'handler' => [__CLASS__, 'siteInfo'],
        ]);
    }

    public static function siteInfo(array $args, Context $ctx): array
    {
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;

        $perms = [];
        foreach ((array) $db->GetCol("SELECT permission_name FROM {$p}permissions ORDER BY permission_name") as $perm) {
            if (check_permission($ctx->uid, $perm)) $perms[] = $perm;
        }
        $groups = [];
        foreach (\UserOperations::get_instance()->GetMemberGroups($ctx->uid) as $gid) {
            $groups[] = $db->GetOne("SELECT group_name FROM {$p}groups WHERE group_id = ?", [$gid]);
        }

        $modules = [];
        foreach ((array) $db->GetArray("SELECT module_name, version, status, active FROM {$p}modules ORDER BY module_name") as $row) {
            $modules[] = [
                'name' => $row['module_name'],
                'version' => $row['version'],
                'active' => (bool) $row['active'],
            ];
        }

        $types = [];
        foreach ((array) \ContentOperations::get_instance()->ListContentTypes(false, false) as $key => $label) {
            $types[] = ['type' => $key, 'label' => $label ?: $key];
        }

        $defaults = [];
        try {
            $d = $ctx->pageDefaults();
            $defaults = [
                'content_type' => $d['contenttype'] ?? 'content',
                'template_id' => (int) ($d['template_id'] ?? 0),
                'design_id' => (int) ($d['design_id'] ?? 0),
                'active' => (bool) ($d['active'] ?? true),
                'show_in_menu' => (bool) ($d['showinmenu'] ?? true),
                'cachable' => (bool) ($d['cachable'] ?? true),
                'searchable' => (bool) ($d['searchable'] ?? true),
            ];
        } catch (\Throwable $e) {
            $defaults = ['error' => $e->getMessage()];
        }

        $count = function ($table, $where = '') use ($db, $p) {
            try {
                return (int) $db->GetOne("SELECT COUNT(*) FROM {$p}{$table} $where");
            } catch (\Throwable $e) {
                return null;
            }
        };
        $newsInstalled = (bool) \cms_utils::get_module('News');
        $defaultPage = \ContentOperations::get_instance()->GetDefaultContent();

        return [
            'cmsms' => ['version' => CMS_VERSION, 'version_name' => CMS_VERSION_NAME, 'schema' => CMS_SCHEMA_VERSION],
            'site' => [
                'name' => $ctx->siteName(),
                'root_url' => $ctx->rootUrl(),
                'uploads_url' => (string) $ctx->config['uploads_url'],
                'url_rewriting' => (string) $ctx->config['url_rewriting'],
                'page_extension' => (string) $ctx->config['page_extension'],
                'default_page_id' => (int) $defaultPage,
                'timezone' => date_default_timezone_get(),
                'frontend_languages' => (string) \cms_siteprefs::get('frontendlang', ''),
            ],
            'server' => [
                'mcp_version' => CMSMS_MCP_VERSION,
                'php_version' => PHP_VERSION,
                'readonly' => $ctx->readonly,
                'udt_write_allowed' => $ctx->allowUdtWrite && !$ctx->readonly,
                'api_key' => $ctx->siteConfig['mcp_key'] ?? null,
            ],
            'user' => [
                'id' => $ctx->uid,
                'username' => $ctx->username,
                'email' => (string) $ctx->user->email,
                'groups' => array_values(array_filter($groups)),
                'superuser' => $ctx->isSuperuser(),
                'permissions' => $perms,
            ],
            'content_types' => $types,
            'page_defaults' => $defaults,
            'counts' => [
                'pages' => $count('content'),
                'templates' => $count('layout_templates'),
                'stylesheets' => $count('layout_stylesheets'),
                'designs' => $count('layout_designs'),
                'news_articles' => $newsInstalled ? $count('module_news') : null,
                'news_categories' => $newsInstalled ? $count('module_news_categories') : null,
                'udts' => $count('userplugins'),
            ],
            'modules' => $modules,
        ];
    }
}
