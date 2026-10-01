<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * News articles and categories. The News module has no service API for writes:
 * this reproduces action.addarticle.php / action.editarticle.php (module_news*
 * tables, static routes via news_admin_ops, Search index, News::* hooks) and uses
 * news_admin_ops::delete_article() for deletions.
 */
final class NewsTools
{
    public static function register(Registry $r, Context $ctx)
    {
        if (!\cms_utils::get_module('News')) return;

        $r->add([
            'name' => 'list_news',
            'title' => 'List news articles',
            'description' => 'News articles, newest first (without the full text). Filter by category, status or text.',
            'properties' => [
                'category' => ['type' => ['integer', 'string'], 'description' => 'Category id or name'],
                'status' => ['type' => 'string', 'enum' => ['published', 'draft']],
                'search' => ['type' => 'string', 'description' => 'Substring of title, summary or content'],
                'limit' => ['type' => 'integer', 'description' => 'Default 20, max 200'],
                'offset' => ['type' => 'integer'],
            ],
            'handler' => [__CLASS__, 'listNews'],
        ]);
        $r->add([
            'name' => 'get_news',
            'title' => 'Get news article',
            'description' => 'A news article with its full content, summary, dates, category and custom field values.',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'handler' => [__CLASS__, 'getNews'],
        ]);

        $fields = [
            'content' => ['type' => 'string', 'description' => 'Article body (HTML)'],
            'summary' => ['type' => 'string', 'description' => 'Summary (HTML)'],
            'category' => ['type' => ['integer', 'string'], 'description' => 'Category id or name (default: module default category)'],
            'status' => ['type' => 'string', 'enum' => ['published', 'draft'], 'description' => 'Publishing requires the "Approve News" permission'],
            'post_date' => ['type' => 'string', 'description' => 'Article date, ISO 8601 (default now)'],
            'start_date' => ['type' => 'string', 'description' => 'Display from (ISO 8601). Setting start/end enables expiry.'],
            'end_date' => ['type' => 'string', 'description' => 'Display until (ISO 8601)'],
            'use_expiry' => ['type' => 'boolean', 'description' => 'Use start/end dates (default: true if a start or end date is given). false clears them.'],
            'extra' => ['type' => 'string'],
            'news_url' => ['type' => 'string', 'description' => 'Pretty URL (e.g. "news/my-article"), no leading/trailing slash'],
            'searchable' => ['type' => 'boolean'],
            'custom_fields' => ['type' => 'object', 'description' => 'Custom field values {"field name or id": "value"} (see list_news_categories)'],
        ];
        $r->add([
            'name' => 'create_news',
            'title' => 'Create news article',
            'description' => 'Create a news article (default status: published if you have "Approve News", else draft).',
            'properties' => ['title' => ['type' => 'string']] + $fields,
            'required' => ['title', 'content'],
            'write' => true,
            'handler' => [__CLASS__, 'createNews'],
        ]);
        $r->add([
            'name' => 'update_news',
            'title' => 'Update news article',
            'description' => 'Modify a news article (only given fields change).',
            'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']] + $fields,
            'required' => ['id'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateNews'],
        ]);
        $r->add([
            'name' => 'delete_news',
            'title' => 'Delete news article',
            'description' => 'Permanently delete a news article (with its custom field values, files, route and search index entries).',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteNews'],
        ]);

        $r->add([
            'name' => 'list_news_categories',
            'title' => 'List news categories',
            'description' => 'News categories (hierarchical, with article counts) and the custom field definitions.',
            'handler' => [__CLASS__, 'listCategories'],
        ]);
        $r->add([
            'name' => 'create_news_category',
            'title' => 'Create news category',
            'description' => 'Create a news category (requires "Modify Site Preferences", like the admin).',
            'properties' => ['name' => ['type' => 'string'], 'parent' => ['type' => ['integer', 'string'], 'description' => 'Parent category id or name (default: none)']],
            'required' => ['name'],
            'write' => true,
            'handler' => [__CLASS__, 'createCategory'],
        ]);
        $r->add([
            'name' => 'update_news_category',
            'title' => 'Update news category',
            'description' => 'Rename or move a news category.',
            'properties' => [
                'category' => ['type' => ['integer', 'string'], 'description' => 'Category id or name'],
                'name' => ['type' => 'string'],
                'parent' => ['type' => ['integer', 'string'], 'description' => 'New parent id or name; -1 = top level'],
            ],
            'required' => ['category'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateCategory'],
        ]);
        $r->add([
            'name' => 'delete_news_category',
            'title' => 'Delete news category',
            'description' => 'Delete a news category. Its articles and sub-categories are kept (moved to no category / top level).',
            'properties' => ['category' => ['type' => ['integer', 'string'], 'description' => 'Category id or name']],
            'required' => ['category'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteCategory'],
        ]);
    }

    // ================================================================ articles

    public static function listNews(array $args, Context $ctx): array
    {
        [$limit, $offset] = Util::page($args, 20, 200);
        $p = CMS_DB_PREFIX;
        $where = ' WHERE 1=1';
        $params = [];
        if (isset($args['category']) && $args['category'] !== '') {
            $where .= ' AND n.news_category_id = ?';
            $params[] = self::resolveCategory($ctx, $args['category']);
        }
        if (($s = Util::str($args, 'status')) !== null) {
            $where .= ' AND n.status = ?';
            $params[] = $s;
        }
        if (($q = Util::str($args, 'search')) !== null && $q !== '') {
            $like = '%' . Util::likeEscape($q) . '%';
            $where .= ' AND (n.news_title LIKE ? OR n.summary LIKE ? OR n.news_data LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $total = (int) $ctx->db->GetOne("SELECT COUNT(*) FROM {$p}module_news n" . $where, $params);
        $rows = $ctx->db->SelectLimit(
            "SELECT n.news_id, n.news_title, n.summary, n.status, n.news_date, n.start_time, n.end_time, n.news_url, n.author_id, n.news_category_id, c.long_name
             FROM {$p}module_news n LEFT JOIN {$p}module_news_categories c ON c.news_category_id = n.news_category_id" . $where . ' ORDER BY n.news_date DESC, n.news_id DESC',
            $limit, $offset, $params
        );
        $out = [];
        while ($rows && !$rows->EOF()) {
            $r = $rows->fields;
            $out[] = [
                'id' => (int) $r['news_id'],
                'title' => $r['news_title'],
                'status' => $r['status'],
                'category' => $r['long_name'],
                'category_id' => (int) $r['news_category_id'],
                'post_date' => Util::isoDate($r['news_date']),
                'start_date' => Util::isoDate($r['start_time']),
                'end_date' => Util::isoDate($r['end_time']),
                'news_url' => $r['news_url'] ?: null,
                'author' => $ctx->userName((int) $r['author_id']),
                'summary' => Util::truncate(trim(strip_tags((string) $r['summary'])), 200),
            ];
            $rows->MoveNext();
        }
        return ['total' => $total, 'offset' => $offset, 'count' => count($out), 'articles' => $out];
    }

    public static function getNews(array $args, Context $ctx): array
    {
        return self::describe($ctx, self::loadRow($ctx, (int) $args['id']));
    }

    public static function createNews(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify News');
        $mod = $ctx->module('News');
        $ctx->moduleClass('News', 'news_admin_ops');
        $db = $ctx->db;

        $ndays = (int) $mod->GetPreference('expiry_interval', 180) ?: 180;
        $a = [
            'title' => trim(strip_tags(Util::requireStr($args, 'title'))),
            'content' => Util::str($args, 'content', ''),
            'summary' => Util::str($args, 'summary', ''),
            'status' => Util::str($args, 'status', $ctx->can('Approve News') ? 'published' : 'draft'),
            'category' => isset($args['category']) ? self::resolveCategory($ctx, $args['category']) : (int) $mod->GetPreference('default_category', ''),
            'postdate' => Util::date($args, 'post_date', time()),
            'startdate' => Util::date($args, 'start_date'),
            'enddate' => Util::date($args, 'end_date', strtotime(sprintf('+%d days', $ndays))),
            'useexp' => Util::bool($args, 'use_expiry', isset($args['start_date']) || isset($args['end_date'])),
            'extra' => trim(strip_tags(Util::str($args, 'extra', ''))),
            'news_url' => trim(Util::str($args, 'news_url', '')),
            'searchable' => Util::bool($args, 'searchable', true),
        ];
        // No explicit start: visible from the article date (not "now": News compares with SQL NOW()).
        if ($a['startdate'] === null) $a['startdate'] = $a['postdate'];
        $custom = self::resolveCustomFields($ctx, Util::arr($args, 'custom_fields', []));
        self::validate($ctx, $a, null);

        $id = (int) $db->GenID(CMS_DB_PREFIX . 'module_news_seq');
        $now = $ctx->dbTime(time());
        $db->Execute(
            'INSERT INTO ' . CMS_DB_PREFIX . 'module_news (news_id, news_category_id, news_title, news_data, summary, status, news_date, start_time, end_time, create_date, modified_date, author_id, news_extra, news_url, searchable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $id, $a['category'], $a['title'], $a['content'], $a['summary'], $a['status'],
                $ctx->dbTime($a['postdate']),
                $a['useexp'] ? $ctx->dbTime($a['startdate']) : null,
                $a['useexp'] ? $ctx->dbTime($a['enddate']) : null,
                $now, $now, $ctx->uid, $a['extra'], $a['news_url'], $a['searchable'] ? 1 : 0,
            ]
        ) or self::sqlError($ctx);

        foreach ($custom as $fid => $value) {
            if ($value === '') continue;
            $db->Execute(
                'INSERT INTO ' . CMS_DB_PREFIX . 'module_news_fieldvals (news_id, fielddef_id, value, create_date, modified_date) VALUES (?,?,?,?,?)',
                [$id, $fid, $value, $now, $now]
            ) or self::sqlError($ctx);
        }

        self::syncRouteAndIndex($ctx, $mod, $id, $a, $custom);
        \CMSMS\HookManager::do_hook('News::NewsArticleAdded', [
            'news_id' => $id, 'category_id' => $a['category'], 'title' => $a['title'], 'content' => $a['content'],
            'summary' => $a['summary'], 'status' => $a['status'], 'start_time' => $a['startdate'], 'end_time' => $a['enddate'],
            'postdate' => $a['postdate'], 'useexp' => $a['useexp'] ? 1 : 0, 'extra' => $a['extra'],
        ]);
        audit($id, 'News: ' . $a['title'], 'Article added (MCP)');

        return ['created' => true, 'article' => self::describe($ctx, self::loadRow($ctx, $id))];
    }

    public static function updateNews(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify News');
        $mod = $ctx->module('News');
        $ctx->moduleClass('News', 'news_admin_ops');
        $db = $ctx->db;
        $id = (int) $args['id'];
        $row = self::loadRow($ctx, $id);

        $hadExpiry = !empty($row['start_time']) || !empty($row['end_time']);
        $a = [
            'title' => isset($args['title']) ? trim(strip_tags(Util::str($args, 'title'))) : $row['news_title'],
            'content' => Util::str($args, 'content', $row['news_data']),
            'summary' => Util::str($args, 'summary', $row['summary']),
            'status' => Util::str($args, 'status', $row['status']),
            'category' => isset($args['category']) ? self::resolveCategory($ctx, $args['category']) : (int) $row['news_category_id'],
            'postdate' => Util::date($args, 'post_date', strtotime($row['news_date']) ?: time()),
            'startdate' => Util::date($args, 'start_date', $row['start_time'] ? strtotime($row['start_time']) : null),
            'enddate' => Util::date($args, 'end_date', $row['end_time'] ? strtotime($row['end_time']) : strtotime('+180 days')),
            'useexp' => Util::bool($args, 'use_expiry', $hadExpiry || isset($args['start_date']) || isset($args['end_date'])),
            'extra' => isset($args['extra']) ? trim(strip_tags(Util::str($args, 'extra'))) : $row['news_extra'],
            'news_url' => isset($args['news_url']) ? trim(Util::str($args, 'news_url')) : (string) $row['news_url'],
            'searchable' => Util::bool($args, 'searchable', (bool) $row['searchable']),
        ];
        if ($a['status'] === 'published' && $row['status'] !== 'published' && !$ctx->can('Approve News')) {
            throw new ToolError("Permission denied: publishing a news article requires 'Approve News'");
        }
        // No explicit start: visible from the article date (not "now": News compares with SQL NOW()).
        if ($a['startdate'] === null) $a['startdate'] = $a['postdate'];
        $custom = self::resolveCustomFields($ctx, Util::arr($args, 'custom_fields', []));
        self::validate($ctx, $a, $id);

        $db->Execute(
            'UPDATE ' . CMS_DB_PREFIX . 'module_news SET news_title=?, news_data=?, summary=?, status=?, news_date=?, news_category_id=?, start_time=?, end_time=?, modified_date=?, news_extra=?, news_url=?, searchable=? WHERE news_id=?',
            [
                $a['title'], $a['content'], $a['summary'], $a['status'], $ctx->dbTime($a['postdate']), $a['category'],
                $a['useexp'] ? $ctx->dbTime($a['startdate']) : null,
                $a['useexp'] ? $ctx->dbTime($a['enddate']) : null,
                $ctx->dbTime(time()), $a['extra'], $a['news_url'], $a['searchable'] ? 1 : 0, $id,
            ]
        ) or self::sqlError($ctx);

        $now = $ctx->dbTime(time());
        foreach ($custom as $fid => $value) {
            $exists = $db->GetOne('SELECT 1 FROM ' . CMS_DB_PREFIX . 'module_news_fieldvals WHERE news_id = ? AND fielddef_id = ?', [$id, $fid]);
            if ($value === '') {
                if ($exists) $db->Execute('DELETE FROM ' . CMS_DB_PREFIX . 'module_news_fieldvals WHERE news_id = ? AND fielddef_id = ?', [$id, $fid]);
            } elseif ($exists) {
                $db->Execute('UPDATE ' . CMS_DB_PREFIX . 'module_news_fieldvals SET value = ?, modified_date = ? WHERE news_id = ? AND fielddef_id = ?', [$value, $now, $id, $fid]);
            } else {
                $db->Execute('INSERT INTO ' . CMS_DB_PREFIX . 'module_news_fieldvals (news_id, fielddef_id, value, create_date, modified_date) VALUES (?,?,?,?,?)', [$id, $fid, $value, $now, $now]);
            }
        }
        // Index all current custom values, not only the ones changed now.
        $allCustom = [];
        foreach ((array) $db->GetArray('SELECT fielddef_id, value FROM ' . CMS_DB_PREFIX . 'module_news_fieldvals WHERE news_id = ?', [$id]) as $fv) {
            $allCustom[(int) $fv['fielddef_id']] = (string) $fv['value'];
        }

        self::syncRouteAndIndex($ctx, $mod, $id, $a, $allCustom);
        \CMSMS\HookManager::do_hook('News::NewsArticleEdited', [
            'news_id' => $id, 'category_id' => $a['category'], 'title' => $a['title'], 'content' => $a['content'],
            'summary' => $a['summary'], 'status' => $a['status'], 'start_time' => $a['startdate'], 'end_time' => $a['enddate'],
            'post_time' => $a['postdate'], 'extra' => $a['extra'], 'useexp' => $a['useexp'] ? 1 : 0, 'news_url' => $a['news_url'],
        ]);
        audit($id, 'News: ' . $a['title'], 'Article edited (MCP)');

        return ['updated' => true, 'article' => self::describe($ctx, self::loadRow($ctx, $id))];
    }

    public static function deleteNews(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Delete News');
        $ctx->moduleClass('News', 'news_admin_ops');
        $id = (int) $args['id'];
        $row = self::loadRow($ctx, $id);
        \news_admin_ops::delete_article($id);
        return ['deleted' => true, 'article' => ['id' => $id, 'title' => $row['news_title']]];
    }

    /** Same checks as the admin actions. */
    private static function validate(Context $ctx, array $a, $articleId)
    {
        if ($a['title'] === '') throw new ToolError('A title is required');
        if (trim((string) $a['content']) === '') throw new ToolError('Content is required');
        if (!in_array($a['status'], ['published', 'draft'], true)) throw new ToolError("status must be 'published' or 'draft'");
        if ($a['status'] === 'published' && $articleId === null && !$ctx->can('Approve News')) {
            throw new ToolError("Permission denied: publishing requires 'Approve News' (use status 'draft')");
        }
        if ($a['useexp'] && $a['startdate'] >= $a['enddate']) throw new ToolError('start_date must be before end_date');
        if ($a['category'] > 0 && !$ctx->db->GetOne('SELECT 1 FROM ' . CMS_DB_PREFIX . 'module_news_categories WHERE news_category_id = ?', [$a['category']])) {
            throw new ToolError("News category {$a['category']} not found");
        }

        $url = $a['news_url'];
        if ($url === '') return;
        if (Util::startsWith($url, '/') || substr($url, -1) === '/') throw new ToolError('news_url must not start or end with a slash');
        if (strtolower(munge_string_to_url($url, false, true)) !== strtolower($url)) {
            throw new ToolError('news_url contains invalid characters (use letters, digits, "-", "_", "." and "/")');
        }
        \cms_route_manager::load_routes();
        $route = \cms_route_manager::find_match($url, true);
        if ($route) {
            $dflts = $route->get_defaults();
            $own = $articleId !== null && $route['key1'] === 'News' && isset($dflts['articleid']) && (int) $dflts['articleid'] === (int) $articleId;
            if (!$own) throw new ToolError("news_url '$url' is already used by another route (page or article)");
        }
    }

    private static function syncRouteAndIndex(Context $ctx, $mod, int $id, array $a, array $custom)
    {
        // Static route only for published articles with a URL.
        \news_admin_ops::delete_static_route($id);
        if ($a['status'] === 'published' && $a['news_url'] !== '') {
            \news_admin_ops::register_static_route($a['news_url'], $id);
        }

        $search = \cms_utils::get_search_module();
        if (!is_object($search)) return;
        if ($a['status'] !== 'published' || !$a['searchable']) {
            $search->DeleteWords($mod->GetName(), $id, 'article');
            return;
        }
        $text = '';
        foreach ($custom as $value) {
            if (strlen((string) $value) > 1) $text .= $value . ' ';
        }
        $text .= $a['content'] . ' ' . $a['summary'] . ' ' . $a['title'] . ' ' . $a['title'];
        $expires = ($a['useexp'] && $mod->GetPreference('expired_searchable', 0) == 0) ? $a['enddate'] : null;
        $search->AddWords($mod->GetName(), $id, 'article', $text, $expires);
    }

    private static function resolveCustomFields(Context $ctx, array $values): array
    {
        if (!$values) return [];
        $defs = [];
        foreach ((array) $ctx->db->GetArray('SELECT id, name, type FROM ' . CMS_DB_PREFIX . 'module_news_fielddefs') as $d) {
            $defs[(int) $d['id']] = $d;
        }
        $out = [];
        foreach ($values as $key => $value) {
            $fid = null;
            foreach ($defs as $d) {
                if ((is_numeric($key) && (int) $key === (int) $d['id']) || strcasecmp((string) $key, $d['name']) === 0) $fid = (int) $d['id'];
            }
            if ($fid === null) {
                throw new ToolError("Unknown news custom field '$key'", ['available' => array_values(array_map(function ($d) {
                    return $d['name'];
                }, $defs))]);
            }
            if (is_bool($value)) $value = $value ? '1' : '';
            if (is_array($value) || is_object($value)) throw new ToolError("Custom field '$key' value must be a string");
            $out[$fid] = (string) $value;
        }
        return $out;
    }

    private static function loadRow(Context $ctx, int $id): array
    {
        $row = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_news WHERE news_id = ?', [$id]);
        if (!$row) throw new ToolError("News article $id not found");
        return $row;
    }

    private static function describe(Context $ctx, array $row): array
    {
        $p = CMS_DB_PREFIX;
        $id = (int) $row['news_id'];
        $cat = $ctx->db->GetRow("SELECT news_category_name, long_name FROM {$p}module_news_categories WHERE news_category_id = ?", [$row['news_category_id']]);
        $custom = [];
        foreach ((array) $ctx->db->GetArray("SELECT d.id, d.name, d.type, v.value FROM {$p}module_news_fieldvals v JOIN {$p}module_news_fielddefs d ON d.id = v.fielddef_id WHERE v.news_id = ? ORDER BY d.item_order", [$id]) as $f) {
            $custom[] = ['id' => (int) $f['id'], 'name' => $f['name'], 'type' => $f['type'], 'value' => $f['value']];
        }
        $url = null;
        if ($row['status'] === 'published' && $row['news_url'] !== '' && $row['news_url'] !== null && $ctx->config['url_rewriting'] !== 'none') {
            $url = $ctx->rootUrl() . ($ctx->config['url_rewriting'] === 'internal' ? '/index.php/' : '/') . $row['news_url'] . $ctx->config['page_extension'];
        }
        return [
            'id' => $id,
            'title' => $row['news_title'],
            'status' => $row['status'],
            'category_id' => (int) $row['news_category_id'],
            'category' => $cat ? $cat['long_name'] : null,
            'post_date' => Util::isoDate($row['news_date']),
            'start_date' => Util::isoDate($row['start_time']),
            'end_date' => Util::isoDate($row['end_time']),
            'summary' => $row['summary'],
            'content' => $row['news_data'],
            'extra' => $row['news_extra'],
            'news_url' => $row['news_url'] ?: null,
            'public_url' => $url,
            'searchable' => (bool) $row['searchable'],
            'author' => $ctx->userName((int) $row['author_id']),
            'custom_fields' => $custom,
            'created' => Util::isoDate($row['create_date']),
            'modified' => Util::isoDate($row['modified_date']),
        ];
    }

    private static function sqlError(Context $ctx)
    {
        throw new ToolError('Database error: ' . $ctx->db->ErrorMsg());
    }

    // ================================================================ categories

    public static function listCategories(array $args, Context $ctx): array
    {
        $p = CMS_DB_PREFIX;
        $counts = [];
        foreach ((array) $ctx->db->GetArray("SELECT news_category_id, COUNT(*) AS n FROM {$p}module_news GROUP BY news_category_id") as $r) {
            $counts[(int) $r['news_category_id']] = (int) $r['n'];
        }
        $cats = [];
        foreach ((array) $ctx->db->GetArray("SELECT * FROM {$p}module_news_categories ORDER BY hierarchy") as $r) {
            $cats[] = [
                'id' => (int) $r['news_category_id'],
                'name' => $r['news_category_name'],
                'full_name' => $r['long_name'],
                'parent_id' => (int) $r['parent_id'],
                'articles' => $counts[(int) $r['news_category_id']] ?? 0,
            ];
        }
        $fields = [];
        foreach ((array) $ctx->db->GetArray("SELECT id, name, type, max_length, public, extra FROM {$p}module_news_fielddefs ORDER BY item_order") as $f) {
            $extra = $f['extra'] ? @unserialize($f['extra']) : null;
            $fields[] = [
                'id' => (int) $f['id'],
                'name' => $f['name'],
                'type' => $f['type'],
                'max_length' => (int) $f['max_length'],
                'public' => (bool) $f['public'],
                'options' => is_array($extra) && isset($extra['options']) ? $extra['options'] : null,
            ];
        }
        $mod = $ctx->module('News');
        return [
            'default_category_id' => (int) $mod->GetPreference('default_category', 0) ?: null,
            'categories' => $cats,
            'custom_fields' => $fields,
            'uncategorized_articles' => ($counts[-1] ?? 0) + ($counts[0] ?? 0),
        ];
    }

    public static function createCategory(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Site Preferences');
        $ctx->moduleClass('News', 'news_admin_ops');
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;
        $name = trim(Util::requireStr($args, 'name'));
        $parent = isset($args['parent']) && $args['parent'] !== '' && (string) $args['parent'] !== '-1' ? self::resolveCategory($ctx, $args['parent']) : -1;
        if ($db->GetOne("SELECT news_category_id FROM {$p}module_news_categories WHERE parent_id = ? AND news_category_name = ?", [$parent, $name])) {
            throw new ToolError("A category named '$name' already exists at this level");
        }
        $order = (int) $db->GetOne("SELECT MAX(item_order) FROM {$p}module_news_categories WHERE parent_id = ?", [$parent]) + 1;
        $id = (int) $db->GenID($p . 'module_news_categories_seq');
        $db->Execute(
            "INSERT INTO {$p}module_news_categories (news_category_id, news_category_name, parent_id, item_order, create_date, modified_date) VALUES (?,?,?,?,NOW(),NOW())",
            [$id, $name, $parent, $order]
        ) or self::sqlError($ctx);
        \news_admin_ops::UpdateHierarchyPositions();
        \CMSMS\HookManager::do_hook('News::NewsCategoryAdded', ['category_id' => $id, 'name' => $name]);
        audit($id, 'News category: ' . $name, ' Category added (MCP)');
        return ['created' => true, 'category' => self::categoryRow($ctx, $id)];
    }

    public static function updateCategory(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Site Preferences');
        $ctx->moduleClass('News', 'news_admin_ops');
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;
        $id = self::resolveCategory($ctx, $args['category']);
        $row = $db->GetRow("SELECT * FROM {$p}module_news_categories WHERE news_category_id = ?", [$id]);
        $name = isset($args['name']) ? trim(Util::str($args, 'name')) : $row['news_category_name'];
        if ($name === '') throw new ToolError('The name cannot be empty');
        $parent = (int) $row['parent_id'];
        if (isset($args['parent']) && $args['parent'] !== '') {
            $parent = (string) $args['parent'] === '-1' ? -1 : self::resolveCategory($ctx, $args['parent']);
        }
        if ($parent === $id) throw new ToolError('A category cannot be its own parent');
        if ($parent > 0 && self::isDescendant($ctx, $parent, $id)) throw new ToolError('A category cannot be moved under one of its sub-categories');
        if ($db->GetOne("SELECT news_category_id FROM {$p}module_news_categories WHERE parent_id = ? AND news_category_name = ? AND news_category_id != ?", [$parent, $name, $id])) {
            throw new ToolError("A category named '$name' already exists at this level");
        }
        $order = (int) $row['item_order'];
        if ($parent !== (int) $row['parent_id']) {
            $order = (int) $db->GetOne("SELECT MAX(item_order) FROM {$p}module_news_categories WHERE parent_id = ?", [$parent]) + 1;
            $db->Execute("UPDATE {$p}module_news_categories SET item_order = item_order - 1 WHERE parent_id = ? AND item_order > ?", [$row['parent_id'], $row['item_order']]);
        }
        $db->Execute(
            "UPDATE {$p}module_news_categories SET news_category_name = ?, item_order = ?, parent_id = ?, modified_date = NOW() WHERE news_category_id = ?",
            [$name, $order, $parent, $id]
        ) or self::sqlError($ctx);
        \news_admin_ops::UpdateHierarchyPositions();
        \CMSMS\HookManager::do_hook('News::NewsCategoryEdited', ['category_id' => $id, 'name' => $name, 'origname' => $row['news_category_name']]);
        audit($id, 'News category: ' . $name, ' Category edited (MCP)');
        return ['updated' => true, 'category' => self::categoryRow($ctx, $id)];
    }

    public static function deleteCategory(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Site Preferences');
        $ctx->moduleClass('News', 'news_admin_ops');
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;
        $id = self::resolveCategory($ctx, $args['category']);
        $row = $db->GetRow("SELECT * FROM {$p}module_news_categories WHERE news_category_id = ?", [$id]);
        // Same as action.deletecategory.php
        $db->Execute("UPDATE {$p}module_news_categories SET parent_id = ?, modified_date = NOW() WHERE parent_id = ?", [-1, $id]);
        $db->Execute("DELETE FROM {$p}module_news_categories WHERE news_category_id = ?", [$id]);
        $moved = (int) $db->GetOne("SELECT COUNT(*) FROM {$p}module_news WHERE news_category_id = ?", [$id]);
        $db->Execute("UPDATE {$p}module_news SET news_category_id = -1 WHERE news_category_id = ?", [$id]);
        \CMSMS\HookManager::do_hook('News::NewsCategoryDeleted', ['category_id' => $id, 'name' => $row['news_category_name']]);
        audit($id, 'News category: ' . $id, ' Category deleted (MCP)');
        \news_admin_ops::UpdateHierarchyPositions();
        return ['deleted' => true, 'category' => ['id' => $id, 'name' => $row['news_category_name']], 'articles_uncategorized' => $moved];
    }

    private static function resolveCategory(Context $ctx, $ref): int
    {
        $p = CMS_DB_PREFIX;
        if (is_numeric($ref)) {
            $id = (int) $ctx->db->GetOne("SELECT news_category_id FROM {$p}module_news_categories WHERE news_category_id = ?", [(int) $ref]);
        } else {
            $id = (int) $ctx->db->GetOne("SELECT news_category_id FROM {$p}module_news_categories WHERE news_category_name = ? OR long_name = ? ORDER BY hierarchy", [(string) $ref, (string) $ref]);
        }
        if ($id < 1) throw new ToolError("News category '$ref' not found (see list_news_categories)");
        return $id;
    }

    private static function isDescendant(Context $ctx, int $candidate, int $ancestor): bool
    {
        $seen = [];
        while ($candidate > 0 && !isset($seen[$candidate])) {
            if ($candidate === $ancestor) return true;
            $seen[$candidate] = true;
            $candidate = (int) $ctx->db->GetOne('SELECT parent_id FROM ' . CMS_DB_PREFIX . 'module_news_categories WHERE news_category_id = ?', [$candidate]);
        }
        return false;
    }

    private static function categoryRow(Context $ctx, int $id): array
    {
        $r = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_news_categories WHERE news_category_id = ?', [$id]);
        return ['id' => $id, 'name' => $r['news_category_name'], 'full_name' => $r['long_name'], 'parent_id' => (int) $r['parent_id']];
    }
}
