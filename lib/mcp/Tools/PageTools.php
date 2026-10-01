<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * Pages, through ContentOperations / ContentBase exactly like the CMSContentManager editor:
 * CreateNewContent + page defaults, FillParams, ValidateData, Save (hooks, routes, search index).
 */
final class PageTools
{
    /** API argument => admin form parameter consumed by ContentBase::FillParams() */
    const PARAM_MAP = [
        'title' => 'title',
        'menu_text' => 'menutext',
        'alias' => 'alias',
        'template_id' => 'template_id',
        'design_id' => 'design_id',
        'active' => 'active',
        'show_in_menu' => 'showinmenu',
        'cachable' => 'cachable',
        'secure' => 'secure',
        'url' => 'page_url',
        'metadata' => 'metadata',
        'pagedata' => 'pagedata',
        'title_attribute' => 'titleattribute',
        'access_key' => 'accesskey',
        'tab_index' => 'tabindex',
        'target' => 'target',
        'searchable' => 'searchable',
        'disable_wysiwyg' => 'disable_wysiwyg',
        'wants_children' => 'wantschildren',
        'extra1' => 'extra1',
        'extra2' => 'extra2',
        'extra3' => 'extra3',
        'image' => 'image',
        'thumbnail' => 'thumbnail',
    ];
    const BOOL_ARGS = ['active', 'show_in_menu', 'cachable', 'secure', 'searchable', 'disable_wysiwyg', 'wants_children'];

    public static function register(Registry $r, Context $ctx)
    {
        $pageRef = ['type' => ['integer', 'string'], 'description' => 'Page id or page alias'];

        $r->add([
            'name' => 'list_pages',
            'title' => 'List pages',
            'description' => 'List site pages in hierarchy order (id, title, menu text, alias, type, parent, position like "2.1", active, in menu, default, template, url). Optionally restrict to the children/descendants of a page.',
            'properties' => [
                'parent' => ['type' => ['integer', 'string'], 'description' => 'Only pages under this page (id or alias). -1 = top level.'],
                'recursive' => ['type' => 'boolean', 'description' => 'With parent: include all descendants (default true) or direct children only'],
                'include_inactive' => ['type' => 'boolean', 'description' => 'Include inactive pages (default true)'],
                'content_type' => ['type' => 'string', 'description' => 'Filter by content type (content, link, pagelink, sectionheader, separator, errorpage...)'],
                'search' => ['type' => 'string', 'description' => 'Filter on title, menu text or alias (case-insensitive substring)'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 200, max 1000)'],
                'offset' => ['type' => 'integer'],
            ],
            'handler' => [__CLASS__, 'listPages'],
        ]);

        $r->add([
            'name' => 'get_page',
            'title' => 'Get page',
            'description' => 'Full details of a page: all settings, every content property (content blocks such as content_en, extra1...), public URL, lock status and the content blocks declared by its template.',
            'properties' => [
                'page' => $pageRef,
                'include_content' => ['type' => 'boolean', 'description' => 'Include property values (default true). false = settings only.'],
            ],
            'required' => ['page'],
            'handler' => [__CLASS__, 'getPage'],
        ]);

        $r->add([
            'name' => 'get_template_blocks',
            'title' => 'Get template content blocks',
            'description' => 'Content blocks declared in a page template ({content}, {content_image}, {content_module}): the block ids to use in create_page/update_page "blocks". Give template_id, or page to use that page\'s template.',
            'properties' => [
                'template_id' => ['type' => 'integer'],
                'page' => $pageRef,
            ],
            'handler' => [__CLASS__, 'templateBlocks'],
        ]);

        $fields = self::fieldSchema();

        $r->add([
            'name' => 'create_page',
            'title' => 'Create page',
            'description' => 'Create a page with the site page defaults (template, design, flags) unless overridden. Alias and URL are generated if omitted. Content is HTML (Smarty tags allowed); "content" is the main block (content_en), other template blocks go in "blocks".',
            'properties' => ['title' => ['type' => 'string', 'description' => 'Page title (required)'],
                    'content_type' => ['type' => 'string', 'description' => 'Content type (default: site default, usually "content")'],
                    'parent' => ['type' => ['integer', 'string'], 'description' => 'Parent page id or alias; -1 or omitted = top level'],
                ] + $fields,
            'required' => ['title'],
            'write' => true,
            'handler' => [__CLASS__, 'createPage'],
        ]);

        $r->add([
            'name' => 'update_page',
            'title' => 'Update page',
            'description' => 'Modify a page. Only the given fields change. Moving: set parent. Changing template: blocks of the new template apply. Refused if the page is locked in the admin console.',
            'properties' => ['page' => $pageRef,
                    'title' => ['type' => 'string'],
                    'parent' => ['type' => ['integer', 'string'], 'description' => 'New parent page id or alias; -1 = top level'],
                ] + $fields,
            'required' => ['page'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updatePage'],
        ]);

        $r->add([
            'name' => 'delete_page',
            'title' => 'Delete page',
            'description' => 'Permanently delete a page. Refused for the default page, pages with children, and locked pages.',
            'properties' => ['page' => $pageRef],
            'required' => ['page'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deletePage'],
        ]);
    }

    private static function fieldSchema(): array
    {
        return [
            'content' => ['type' => 'string', 'description' => 'Main content block (content_en), HTML'],
            'blocks' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Other content blocks: {"block id or name": "value"} (see get_template_blocks)'],
            'menu_text' => ['type' => 'string', 'description' => 'Menu text (defaults to title)'],
            'alias' => ['type' => 'string', 'description' => 'Page alias (empty string on update = regenerate)'],
            'template_id' => ['type' => 'integer'],
            'design_id' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
            'show_in_menu' => ['type' => 'boolean'],
            'cachable' => ['type' => 'boolean'],
            'secure' => ['type' => 'boolean'],
            'searchable' => ['type' => 'boolean'],
            'disable_wysiwyg' => ['type' => 'boolean'],
            'wants_children' => ['type' => 'boolean'],
            'url' => ['type' => 'string', 'description' => 'Page URL (pretty URL path, e.g. "about/team"); empty = auto (if enabled)'],
            'metadata' => ['type' => 'string', 'description' => 'Page specific metadata (HTML placed in <head>)'],
            'pagedata' => ['type' => 'string', 'description' => 'Smarty data/logic processed before the template'],
            'title_attribute' => ['type' => 'string', 'description' => 'Description / title attribute'],
            'access_key' => ['type' => 'string'],
            'tab_index' => ['type' => 'integer'],
            'target' => ['type' => 'string', 'description' => 'Link target (_blank, ...)'],
            'extra1' => ['type' => 'string'],
            'extra2' => ['type' => 'string'],
            'extra3' => ['type' => 'string'],
            'image' => ['type' => 'string', 'description' => 'Image (path relative to uploads/images)'],
            'thumbnail' => ['type' => 'string'],
            'owner_id' => ['type' => 'integer', 'description' => 'Owner user id (requires Manage All Content)'],
            'additional_editors' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User ids (or negative group ids) allowed to edit (requires Manage All Content)'],
            'properties' => ['type' => 'object', 'description' => 'Advanced: raw content-type parameters passed to FillParams (e.g. {"url": "https://..."} for a "link" page, {"page": 12} for a "pagelink")'],
        ];
    }

    // ---------------------------------------------------------------- read

    public static function listPages(array $args, Context $ctx): array
    {
        [$limit, $offset] = Util::page($args, 200, 1000);
        $ops = \ContentOperations::get_instance();
        $rows = (array) $ctx->db->GetArray(
            'SELECT content_id, content_name, menu_text, content_alias, type, parent_id, hierarchy, id_hierarchy, active, show_in_menu, default_content, template_id, page_url, modified_date
             FROM ' . CMS_DB_PREFIX . 'content ORDER BY hierarchy'
        );

        $parentId = null;
        if (isset($args['parent']) && $args['parent'] !== '') {
            $parentId = (int) $args['parent'] === -1 && is_numeric($args['parent']) ? -1 : self::resolvePageId($args['parent']);
        }
        $recursive = Util::bool($args, 'recursive', true);
        $inactive = Util::bool($args, 'include_inactive', true);
        $type = Util::str($args, 'content_type');
        $search = Util::str($args, 'search');
        $parentIdHier = null;
        if ($parentId !== null && $parentId > 0) {
            foreach ($rows as $row) {
                if ((int) $row['content_id'] === $parentId) $parentIdHier = $row['id_hierarchy'];
            }
        }

        $tplNames = self::templateNames($ctx);
        $out = [];
        $total = 0;
        foreach ($rows as $row) {
            if (!$inactive && !$row['active']) continue;
            if ($type !== null && strcasecmp($row['type'], $type) !== 0) continue;
            if ($parentId !== null) {
                if ($parentId === -1 && !$recursive && (int) $row['parent_id'] !== -1) continue;
                if ($parentId > 0) {
                    if ($recursive) {
                        if (!Util::startsWith((string) $row['id_hierarchy'], $parentIdHier . '.')) continue;
                    } elseif ((int) $row['parent_id'] !== $parentId) {
                        continue;
                    }
                }
            }
            if ($search !== null && $search !== '') {
                $hay = $row['content_name'] . ' ' . $row['menu_text'] . ' ' . $row['content_alias'];
                if (stripos($hay, $search) === false) continue;
            }
            $total++;
            if ($total <= $offset || count($out) >= $limit) continue;
            $out[] = [
                'id' => (int) $row['content_id'],
                'title' => $row['content_name'],
                'menu_text' => $row['menu_text'],
                'alias' => $row['content_alias'],
                'type' => $row['type'],
                'parent_id' => (int) $row['parent_id'],
                'position' => $ops->CreateFriendlyHierarchyPosition($row['hierarchy']),
                'depth' => substr_count((string) $row['hierarchy'], '.') + 1,
                'active' => (bool) $row['active'],
                'show_in_menu' => (bool) $row['show_in_menu'],
                'default' => (bool) $row['default_content'],
                'template_id' => (int) $row['template_id'],
                'template' => $tplNames[(int) $row['template_id']] ?? null,
                'url' => $row['page_url'],
                'modified' => Util::isoDate($row['modified_date']),
            ];
        }
        return ['total' => $total, 'offset' => $offset, 'count' => count($out), 'pages' => $out];
    }

    public static function getPage(array $args, Context $ctx): array
    {
        $id = self::resolvePageId($args['page']);
        $obj = self::load($id);
        $withContent = Util::bool($args, 'include_content', true);
        $out = self::describe($obj, $ctx);
        $props = (array) $ctx->db->GetArray('SELECT prop_name, content FROM ' . CMS_DB_PREFIX . 'content_props WHERE content_id = ? ORDER BY prop_name', [$id]);
        $out['properties'] = new \stdClass();
        foreach ($props as $p) {
            $out['properties']->{$p['prop_name']} = $withContent ? $p['content'] : (strlen((string) $p['content']) . ' bytes');
        }
        if ($obj->HasTemplate() && $obj->TemplateId() > 0) {
            try {
                $out['template_blocks'] = self::formatBlocks($ctx->templateBlocks((int) $obj->TemplateId()));
            } catch (\Throwable $e) {
                $out['template_blocks_error'] = $e->getMessage();
            }
        }
        return $out;
    }

    public static function templateBlocks(array $args, Context $ctx): array
    {
        $tplId = Util::int($args, 'template_id');
        if ($tplId === null) {
            if (!isset($args['page'])) throw new ToolError('Give template_id or page');
            $obj = self::load(self::resolvePageId($args['page']));
            $tplId = (int) $obj->TemplateId();
        }
        try {
            $tpl = \CmsLayoutTemplate::load($tplId);
        } catch (\Throwable $e) {
            throw new ToolError("Template $tplId not found");
        }
        $blocks = self::formatBlocks($ctx->templateBlocks($tplId));
        return [
            'template_id' => $tplId,
            'template' => $tpl->get_name(),
            'blocks' => $blocks,
            'note' => 'Use the block "id" as key in create_page/update_page "blocks" (content_en = the "content" argument).',
        ];
    }

    // ---------------------------------------------------------------- write

    public static function createPage(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Add Pages');
        $ops = \ContentOperations::get_instance();
        $d = $ctx->pageDefaults();

        $type = Util::str($args, 'content_type', $d['contenttype'] ?? 'content');
        $allowed = (array) $ops->ListContentTypes(false, true);
        if (!array_key_exists($type, $allowed)) {
            throw new ToolError("Unknown or disallowed content type '$type'", ['allowed' => array_keys($allowed)]);
        }
        $obj = $ops->CreateNewContent($type);
        if (!$obj) throw new ToolError("Could not create a content object of type '$type'");

        // Same initialisation as CMSContentManager action.admin_editcontent.php
        $obj->SetOwner($ctx->uid);
        $obj->SetLastModifiedBy($ctx->uid);
        $obj->SetActive($d['active']);
        $obj->SetSecure($d['secure']);
        $obj->SetCachable($d['cachable']);
        $obj->SetShowInMenu($d['showinmenu']);
        $obj->SetPropertyValue('design_id', $d['design_id']);
        $obj->SetTemplateId($d['template_id']);
        $obj->SetPropertyValue('searchable', $d['searchable']);
        $obj->SetPropertyValue('content_en', $d['content']);
        $obj->SetMetaData($d['metadata']);
        $obj->SetPropertyValue('extra1', $d['extra1']);
        $obj->SetPropertyValue('extra2', $d['extra2']);
        $obj->SetPropertyValue('extra3', $d['extra3']);
        $obj->SetAdditionalEditors($d['addteditors']);
        $obj->SetParentId(-1);

        $params = self::buildParams($args, $ctx, $obj, false);
        self::fillValidateSave($obj, $params, false);
        audit($obj->Id(), 'Content Item: ' . $obj->Name(), ' Added (MCP)');

        return ['created' => true, 'page' => self::describe(self::reload($obj->Id()), $ctx)];
    }

    public static function updatePage(array $args, Context $ctx): array
    {
        $id = self::resolvePageId($args['page']);
        self::assertCanEdit($ctx, $id);
        $ctx->assertNotLocked('content', $id);
        $obj = self::load($id);

        $params = self::buildParams($args, $ctx, $obj, true);
        if (!$params) throw new ToolError('Nothing to update: give at least one field');
        $obj->SetLastModifiedBy($ctx->uid);
        self::fillValidateSave($obj, $params, true);
        audit($obj->Id(), 'Content Item: ' . $obj->Name(), ' Edited (MCP)');

        return ['updated' => true, 'page' => self::describe(self::reload($id), $ctx)];
    }

    public static function deletePage(array $args, Context $ctx): array
    {
        $id = self::resolvePageId($args['page']);
        if (!$ctx->can('Manage All Content') && !($ctx->can('Remove Pages') && check_authorship($ctx->uid, $id))) {
            throw new ToolError("Permission denied: deleting this page needs 'Manage All Content', or 'Remove Pages' and being its owner/editor");
        }
        $ops = \ContentOperations::get_instance();
        $node = $ops->quickfind_node_by_id($id);
        if (!$node) throw new ToolError("Page $id not found");
        if ($node->has_children()) throw new ToolError('This page has children: move or delete them first');
        $content = $node->GetContent(false, false, false);
        if (!$content) throw new ToolError("Page $id could not be loaded");
        if ($content->DefaultContent()) throw new ToolError('The default (home) page cannot be deleted');
        $ctx->assertNotLocked('content', $id);

        $info = ['id' => $id, 'title' => $content->Name(), 'alias' => $content->Alias()];
        $content->Delete();
        audit($id, 'Core', 'Deleted content page (MCP)');
        $ops->SetAllHierarchyPositions();
        return ['deleted' => true, 'page' => $info];
    }

    // ---------------------------------------------------------------- internals

    /** Translate API arguments into the admin form parameters FillParams() expects. */
    private static function buildParams(array $args, Context $ctx, $obj, bool $editing): array
    {
        $params = [];
        foreach (self::PARAM_MAP as $arg => $param) {
            if (!array_key_exists($arg, $args) || $args[$arg] === null) continue;
            if (in_array($arg, self::BOOL_ARGS, true)) {
                $params[$param] = Util::bool($args, $arg) ? '1' : '0';
            } elseif (in_array($arg, ['template_id', 'design_id', 'tab_index'], true)) {
                $params[$param] = (string) Util::int($args, $arg);
            } else {
                $params[$param] = Util::str($args, $arg);
            }
        }

        if (isset($params['template_id'])) {
            try {
                $tpl = \CmsLayoutTemplate::load((int) $params['template_id']);
            } catch (\Throwable $e) {
                throw new ToolError("Template {$params['template_id']} not found (see list_templates type Core::page)");
            }
        }
        if (isset($params['design_id'])) {
            try {
                \CmsLayoutCollection::load((int) $params['design_id']);
            } catch (\Throwable $e) {
                throw new ToolError("Design {$params['design_id']} not found (see list_designs)");
            }
        }

        if (array_key_exists('parent', $args) && $args['parent'] !== null && $args['parent'] !== '') {
            $parent = (is_numeric($args['parent']) && (int) $args['parent'] === -1) ? -1 : self::resolvePageId($args['parent']);
            if ($parent > 0) {
                // CheckParentage(a, b): is a an ancestor of (or equal to) b
                if ($editing && \ContentOperations::get_instance()->CheckParentage((int) $obj->Id(), $parent)) {
                    throw new ToolError('Invalid parent: a page cannot be moved under itself or one of its descendants');
                }
                $pobj = self::load($parent);
                if (!$pobj->WantsChildren()) throw new ToolError("Page $parent does not accept children");
            }
            if (!$ctx->can('Manage All Content') && !$ctx->can('Modify Any Page')) {
                $allowed = (array) author_pages($ctx->uid);
                if ($parent > 0 && !in_array($parent, $allowed)) {
                    throw new ToolError('Permission denied: you can only create/move pages under pages you can edit');
                }
            }
            $params['parent_id'] = (string) $parent;
        }

        if (isset($args['owner_id']) || isset($args['additional_editors'])) {
            $ctx->requirePerm('Manage All Content');
            if (isset($args['owner_id'])) $params['ownerid'] = (string) Util::int($args, 'owner_id');
            if (isset($args['additional_editors'])) $params['additional_editors'] = Util::intList($args, 'additional_editors');
        }

        // Content blocks: validated against the template the page will use.
        $blocks = Util::arr($args, 'blocks', []);
        if (array_key_exists('content', $args) && $args['content'] !== null) {
            $blocks = ['content_en' => Util::str($args, 'content')] + $blocks;
        }
        if ($blocks) {
            $tplId = isset($params['template_id']) ? (int) $params['template_id'] : (int) $obj->TemplateId();
            $known = $obj->HasTemplate() && $tplId > 0 ? $ctx->templateBlocks($tplId) : [];
            $byId = [];
            foreach ($known as $name => $info) {
                $byId[$info['id']] = $info['id'];
                $byId[$name] = $info['id'];
            }
            foreach ($blocks as $key => $value) {
                if (is_array($value) || is_object($value)) throw new ToolError("Block '$key' value must be a string");
                $key = (string) $key;
                if (!isset($byId[$key])) {
                    throw new ToolError("Unknown content block '$key' for template $tplId", ['available_blocks' => array_values(array_unique(array_values($byId)))]);
                }
                $params[$byId[$key]] = (string) $value;
            }
        }

        foreach (Util::arr($args, 'properties', []) as $k => $v) {
            $params[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : $v;
        }
        return $params;
    }

    private static function fillValidateSave($obj, array $params, bool $editing)
    {
        try {
            $obj->FillParams($params, $editing);
            $errors = $obj->ValidateData();
        } catch (\CmsException $e) {
            throw new ToolError('Invalid page data: ' . $e->getMessage());
        }
        if ($errors) {
            throw new ToolError('Page validation failed', array_values(array_map('strip_tags', (array) $errors)));
        }
        $obj->Save();
    }

    public static function resolvePageId($ref): int
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
        } else {
            $id = (int) \ContentOperations::get_instance()->GetPageIDFromAlias((string) $ref);
        }
        if ($id < 1) throw new ToolError("Page '$ref' not found (give a page id or alias)");
        return $id;
    }

    private static function load(int $id)
    {
        $obj = \ContentOperations::get_instance()->LoadContentFromId($id);
        if (!$obj || (int) $obj->Id() !== $id) throw new ToolError("Page $id not found");
        return $obj;
    }

    /** Fresh copy from the database (the content cache still holds the pre-save object). */
    private static function reload(int $id)
    {
        $row = cmsms()->GetDb()->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'content WHERE content_id = ?', [$id]);
        if (!$row) throw new ToolError("Page $id not found after save");
        $obj = \ContentOperations::get_instance()->CreateNewContent(strtolower($row['type']));
        $obj->LoadFromData($row, false);
        return $obj;
    }

    private static function assertCanEdit(Context $ctx, int $id)
    {
        if ($ctx->can('Manage All Content', 'Modify Any Page')) return;
        if (in_array($id, (array) author_pages($ctx->uid))) return;
        throw new ToolError("Permission denied: user '{$ctx->username}' cannot edit page $id");
    }

    private static function describe($obj, Context $ctx): array
    {
        $ops = \ContentOperations::get_instance();
        $tplName = null;
        if ($obj->TemplateId() > 0) {
            $names = self::templateNames($ctx);
            $tplName = $names[(int) $obj->TemplateId()] ?? null;
        }
        $parentAlias = $obj->ParentId() > 0 ? $ops->GetPageAliasFromID($obj->ParentId()) : null;
        $publicUrl = null;
        try {
            if ($obj->HasUsableLink()) $publicUrl = $obj->GetURL();
        } catch (\Throwable $e) {
        }
        return [
            'id' => (int) $obj->Id(),
            'title' => $obj->Name(),
            'menu_text' => $obj->MenuText(),
            'alias' => $obj->Alias(),
            'type' => $obj->Type(),
            'parent_id' => (int) $obj->ParentId(),
            'parent_alias' => $parentAlias ?: null,
            'position' => $obj->Hierarchy() ? $ops->CreateFriendlyHierarchyPosition($obj->Hierarchy()) : null,
            'template_id' => (int) $obj->TemplateId(),
            'template' => $tplName,
            'design_id' => (int) $obj->GetPropertyValue('design_id'),
            'active' => (bool) $obj->Active(),
            'show_in_menu' => (bool) $obj->ShowInMenu(),
            'default' => (bool) $obj->DefaultContent(),
            'cachable' => (bool) $obj->Cachable(),
            'secure' => (bool) $obj->Secure(),
            'url' => $obj->URL(),
            'public_url' => $publicUrl,
            'metadata' => $obj->Metadata(),
            'title_attribute' => $obj->TitleAttribute(),
            'access_key' => $obj->AccessKey(),
            'tab_index' => $obj->TabIndex(),
            'owner' => ['id' => (int) $obj->Owner(), 'username' => $ctx->userName((int) $obj->Owner())],
            'last_modified_by' => ['id' => (int) $obj->LastModifiedBy(), 'username' => $ctx->userName((int) $obj->LastModifiedBy())],
            'created' => Util::isoDate($obj->GetCreationDate()),
            'modified' => Util::isoDate($obj->GetModifiedDate()),
            'lock' => $ctx->lockInfo('content', (int) $obj->Id()),
        ];
    }

    private static function formatBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $name => $b) {
            $item = [
                'id' => $b['id'] ?? $name,
                'name' => $name,
                'type' => $b['type'] ?? 'text',
                'label' => ($b['label'] ?? '') ?: null,
                'required' => !empty($b['required']),
            ];
            if (($b['type'] ?? 'text') === 'text') {
                $item['wysiwyg'] = cms_to_bool($b['usewysiwyg'] ?? 'true');
                $item['oneline'] = cms_to_bool($b['oneline'] ?? 'false');
                if (($b['default'] ?? '') !== '') $item['default'] = $b['default'];
                if (!empty($b['adminonly'])) $item['admin_only'] = true;
            } elseif ($b['type'] === 'image') {
                $item['dir'] = $b['dir'] ?? '';
            } elseif ($b['type'] === 'module') {
                $item['module'] = $b['module'] ?? '';
            }
            if (!empty($b['tab'])) $item['tab'] = $b['tab'];
            $out[] = $item;
        }
        return $out;
    }

    private static function templateNames(Context $ctx): array
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach ((array) $ctx->db->GetArray('SELECT id, name FROM ' . CMS_DB_PREFIX . 'layout_templates') as $r) {
                $names[(int) $r['id']] = $r['name'];
            }
        }
        return $names;
    }
}
