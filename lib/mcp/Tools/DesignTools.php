<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * Templates, stylesheets and designs (CmsLayoutTemplate, CmsLayoutStylesheet,
 * CmsLayoutCollection, CmsLayoutTemplateType).
 */
final class DesignTools
{
    public static function register(Registry $r, Context $ctx)
    {
        $idOrName = ['type' => ['integer', 'string'], 'description' => 'Id or name'];
        $designIds = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Design ids this item belongs to (replaces the current list)'];

        // ------------------------------------------------------------ templates
        $r->add([
            'name' => 'list_template_types',
            'title' => 'List template types',
            'description' => 'Template types (Core::page for page templates, News::summary, News::detail, Navigator::navigation...) with their default template.',
            'handler' => [__CLASS__, 'listTypes'],
        ]);
        $r->add([
            'name' => 'list_templates',
            'title' => 'List templates',
            'description' => 'List templates (without content). Filter by type ("Core::page" = page templates), design or name.',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Template type, e.g. "Core::page", "News::summary"'],
                'design_id' => ['type' => 'integer'],
                'search' => ['type' => 'string', 'description' => 'Substring of the name'],
            ],
            'handler' => [__CLASS__, 'listTemplates'],
        ]);
        $r->add([
            'name' => 'get_template',
            'title' => 'Get template',
            'description' => 'A template with its Smarty source. For page templates, also the content blocks it declares.',
            'properties' => ['template' => $idOrName],
            'required' => ['template'],
            'handler' => [__CLASS__, 'getTemplate'],
        ]);
        $tplFields = [
            'content' => ['type' => 'string', 'description' => 'Smarty template source'],
            'description' => ['type' => 'string'],
            'designs' => $designIds,
            'default_for_type' => ['type' => 'boolean', 'description' => 'Make it the default template of its type'],
            'listable' => ['type' => 'boolean'],
        ];
        $r->add([
            'name' => 'create_template',
            'title' => 'Create template',
            'description' => 'Create a template of a given type. Without content the type\'s default content is used. A page template must contain {content} (the content_en block).',
            'properties' => [
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string', 'description' => 'Template type, e.g. "Core::page" (see list_template_types)'],
            ] + $tplFields,
            'required' => ['name', 'type'],
            'write' => true,
            'handler' => [__CLASS__, 'createTemplate'],
        ]);
        $r->add([
            'name' => 'update_template',
            'title' => 'Update template',
            'description' => 'Modify a template (only given fields). Refused if locked in the admin console.',
            'properties' => ['template' => $idOrName, 'name' => ['type' => 'string', 'description' => 'New name']] + $tplFields,
            'required' => ['template'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateTemplate'],
        ]);
        $r->add([
            'name' => 'delete_template',
            'title' => 'Delete template',
            'description' => 'Delete a template. Refused if it is the default of its type or used by pages.',
            'properties' => ['template' => $idOrName],
            'required' => ['template'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteTemplate'],
        ]);

        // ------------------------------------------------------------ stylesheets
        $r->add([
            'name' => 'list_stylesheets',
            'title' => 'List stylesheets',
            'description' => 'List stylesheets (without content), optionally for one design.',
            'properties' => ['design_id' => ['type' => 'integer'], 'search' => ['type' => 'string']],
            'handler' => [__CLASS__, 'listStylesheets'],
        ]);
        $r->add([
            'name' => 'get_stylesheet',
            'title' => 'Get stylesheet',
            'description' => 'A stylesheet with its CSS.',
            'properties' => ['stylesheet' => $idOrName],
            'required' => ['stylesheet'],
            'handler' => [__CLASS__, 'getStylesheet'],
        ]);
        $cssFields = [
            'content' => ['type' => 'string', 'description' => 'CSS (Smarty with [[ ]] delimiters allowed)'],
            'description' => ['type' => 'string'],
            'media_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["screen","print"]'],
            'media_query' => ['type' => 'string', 'description' => 'e.g. "screen and (max-width: 600px)"'],
            'designs' => $designIds,
        ];
        $r->add([
            'name' => 'create_stylesheet',
            'title' => 'Create stylesheet',
            'description' => 'Create a stylesheet, optionally attached to designs (appended at the end of their stylesheet list).',
            'properties' => ['name' => ['type' => 'string']] + $cssFields,
            'required' => ['name', 'content'],
            'write' => true,
            'handler' => [__CLASS__, 'createStylesheet'],
        ]);
        $r->add([
            'name' => 'update_stylesheet',
            'title' => 'Update stylesheet',
            'description' => 'Modify a stylesheet (only given fields). Refused if locked in the admin console.',
            'properties' => ['stylesheet' => $idOrName, 'name' => ['type' => 'string', 'description' => 'New name']] + $cssFields,
            'required' => ['stylesheet'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateStylesheet'],
        ]);
        $r->add([
            'name' => 'delete_stylesheet',
            'title' => 'Delete stylesheet',
            'description' => 'Delete a stylesheet (it is detached from its designs).',
            'properties' => ['stylesheet' => $idOrName],
            'required' => ['stylesheet'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteStylesheet'],
        ]);

        // ------------------------------------------------------------ designs
        $r->add([
            'name' => 'list_designs',
            'title' => 'List designs',
            'description' => 'Designs (themes) with their templates and ordered stylesheets, and which one is the default.',
            'handler' => [__CLASS__, 'listDesigns'],
        ]);
        $r->add([
            'name' => 'get_design',
            'title' => 'Get design',
            'description' => 'One design with its templates, ordered stylesheets and the number of pages using it.',
            'properties' => ['design' => $idOrName],
            'required' => ['design'],
            'handler' => [__CLASS__, 'getDesign'],
        ]);
        $designFields = [
            'description' => ['type' => 'string'],
            'templates' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Template ids (replaces the list)'],
            'stylesheets' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Stylesheet ids in load order (replaces the list)'],
            'default' => ['type' => 'boolean', 'description' => 'Make it the default design'],
        ];
        $r->add([
            'name' => 'create_design',
            'title' => 'Create design',
            'description' => 'Create a design and attach templates/stylesheets.',
            'properties' => ['name' => ['type' => 'string']] + $designFields,
            'required' => ['name'],
            'write' => true,
            'handler' => [__CLASS__, 'createDesign'],
        ]);
        $r->add([
            'name' => 'update_design',
            'title' => 'Update design',
            'description' => 'Modify a design: name, description, templates, stylesheet order, default flag.',
            'properties' => ['design' => $idOrName, 'name' => ['type' => 'string', 'description' => 'New name']] + $designFields,
            'required' => ['design'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateDesign'],
        ]);
        $r->add([
            'name' => 'delete_design',
            'title' => 'Delete design',
            'description' => 'Delete a design (templates and stylesheets are kept). Refused for the default design, a design used by pages, or one with templates unless force=true.',
            'properties' => ['design' => $idOrName, 'force' => ['type' => 'boolean', 'description' => 'Delete even if templates are attached']],
            'required' => ['design'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteDesign'],
        ]);
    }

    // ================================================================ templates

    public static function listTypes(array $args, Context $ctx): array
    {
        $dflt = [];
        foreach ((array) $ctx->db->GetArray('SELECT id, name, type_id FROM ' . CMS_DB_PREFIX . 'layout_templates WHERE type_dflt = 1') as $r) {
            $dflt[(int) $r['type_id']] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $counts = [];
        foreach ((array) $ctx->db->GetArray('SELECT type_id, COUNT(*) AS n FROM ' . CMS_DB_PREFIX . 'layout_templates GROUP BY type_id') as $r) {
            $counts[(int) $r['type_id']] = (int) $r['n'];
        }
        $out = [];
        foreach ((array) \CmsLayoutTemplateType::get_all() as $t) {
            $out[] = [
                'id' => (int) $t->get_id(),
                'type' => self::typeName($t->get_originator(), $t->get_name()),
                'description' => $t->get_description(),
                'has_content_blocks' => (bool) $t->get_content_block_flag(),
                'one_only' => (bool) $t->get_oneonly_flag(),
                'templates' => $counts[(int) $t->get_id()] ?? 0,
                'default_template' => $dflt[(int) $t->get_id()] ?? null,
            ];
        }
        return ['types' => $out];
    }

    public static function listTemplates(array $args, Context $ctx): array
    {
        $p = CMS_DB_PREFIX;
        $sql = "SELECT t.id, t.name, t.description, t.type_id, t.type_dflt, t.owner_id, t.listable, t.modified, ty.originator, ty.name AS type_name
                FROM {$p}layout_templates t LEFT JOIN {$p}layout_tpl_type ty ON ty.id = t.type_id WHERE 1=1";
        $params = [];
        if (($type = Util::str($args, 'type')) !== null) {
            $typeObj = self::loadType($type);
            $sql .= ' AND t.type_id = ?';
            $params[] = (int) $typeObj->get_id();
        }
        if (($d = Util::int($args, 'design_id')) !== null) {
            $sql .= " AND t.id IN (SELECT tpl_id FROM {$p}layout_design_tplassoc WHERE design_id = ?)";
            $params[] = $d;
        }
        if (($s = Util::str($args, 'search')) !== null && $s !== '') {
            $sql .= ' AND t.name LIKE ?';
            $params[] = '%' . Util::likeEscape($s) . '%';
        }
        $rows = (array) $ctx->db->GetArray($sql . ' ORDER BY ty.originator, ty.name, t.name', $params);
        $designs = self::assoc($ctx, 'layout_design_tplassoc', 'tpl_id');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'type' => self::typeName($r['originator'], $r['type_name']),
                'default_for_type' => (bool) $r['type_dflt'],
                'description' => $r['description'],
                'designs' => $designs[(int) $r['id']] ?? [],
                'owner' => $ctx->userName((int) $r['owner_id']),
                'modified' => Util::isoDate($r['modified']),
            ];
        }
        return ['count' => count($out), 'templates' => $out];
    }

    public static function getTemplate(array $args, Context $ctx): array
    {
        $tpl = self::loadTemplate($args['template']);
        $out = self::describeTemplate($tpl, $ctx);
        $out['content'] = $tpl->get_content();
        $type = $tpl->get_type();
        if ($type && $type->get_content_block_flag()) {
            $out['content_blocks'] = self::blockIds($ctx, (int) $tpl->get_id());
        }
        $out['used_by_pages'] = (int) $ctx->db->GetOne('SELECT COUNT(*) FROM ' . CMS_DB_PREFIX . 'content WHERE template_id = ?', [$tpl->get_id()]);
        return $out;
    }

    public static function createTemplate(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Templates', 'Add Templates');
        $type = self::loadType(Util::requireStr($args, 'type'));
        $name = trim(Util::requireStr($args, 'name'));
        try {
            // No name argument: CmsLayoutTemplateType::create_new_template() passes
            // the template object instead of the name to set_name() (CMSMS bug).
            $tpl = $type->create_new_template();
            $tpl->set_name($name);
            $tpl->set_owner($ctx->uid);
            self::applyTemplateFields($tpl, $args);
            $tpl->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not create the template: ' . $e->getMessage());
        }
        return ['created' => true, 'template' => self::describeAfterSave($tpl, $ctx)];
    }

    public static function updateTemplate(array $args, Context $ctx): array
    {
        $tpl = self::loadTemplate($args['template']);
        if (!$ctx->can('Modify Templates') && !\CmsLayoutTemplate::user_can_edit($tpl->get_id(), $ctx->uid)) {
            throw new ToolError("Permission denied: needs 'Modify Templates' or being the template owner/additional editor");
        }
        $ctx->assertNotLocked('template', (int) $tpl->get_id());
        try {
            if (($name = Util::str($args, 'name')) !== null) $tpl->set_name(trim($name));
            self::applyTemplateFields($tpl, $args);
            $tpl->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not save the template: ' . $e->getMessage());
        }
        return ['updated' => true, 'template' => self::describeAfterSave($tpl, $ctx)];
    }

    public static function deleteTemplate(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Templates');
        $tpl = self::loadTemplate($args['template']);
        $id = (int) $tpl->get_id();
        if ($tpl->get_type_dflt()) {
            throw new ToolError('This template is the default for its type: make another template the default first');
        }
        $pages = (int) $ctx->db->GetOne('SELECT COUNT(*) FROM ' . CMS_DB_PREFIX . 'content WHERE template_id = ?', [$id]);
        if ($pages > 0) {
            throw new ToolError("This template is used by $pages page(s): assign another template to them first");
        }
        $ctx->assertNotLocked('template', $id);
        $name = $tpl->get_name();
        $tpl->delete();
        return ['deleted' => true, 'template' => ['id' => $id, 'name' => $name]];
    }

    private static function applyTemplateFields(\CmsLayoutTemplate $tpl, array $args)
    {
        if (($c = Util::str($args, 'content')) !== null) $tpl->set_content($c);
        if (($d = Util::str($args, 'description')) !== null) $tpl->set_description($d);
        if (($designs = Util::intList($args, 'designs')) !== null) {
            foreach ($designs as $did) self::loadDesign($did);
            $tpl->set_designs($designs);
        }
        if (($flag = Util::bool($args, 'default_for_type')) !== null) $tpl->set_type_dflt($flag);
        if (($flag = Util::bool($args, 'listable')) !== null) $tpl->set_listable($flag);
    }

    private static function describeTemplate(\CmsLayoutTemplate $tpl, Context $ctx): array
    {
        $type = $tpl->get_type();
        return [
            'id' => (int) $tpl->get_id(),
            'name' => $tpl->get_name(),
            'type' => $type ? self::typeName($type->get_originator(), $type->get_name()) : null,
            'default_for_type' => (bool) $tpl->get_type_dflt(),
            'description' => $tpl->get_description(),
            'designs' => array_map('intval', (array) $tpl->get_designs()),
            'owner' => $ctx->userName((int) $tpl->get_owner_id()),
            'listable' => (bool) $tpl->get_listable(),
            'created' => Util::isoDate($tpl->get_created()),
            'modified' => Util::isoDate($tpl->get_modified()),
            'lock' => $ctx->lockInfo('template', (int) $tpl->get_id()),
        ];
    }

    private static function describeAfterSave(\CmsLayoutTemplate $tpl, Context $ctx): array
    {
        $out = self::describeTemplate($tpl, $ctx);
        $type = $tpl->get_type();
        if ($type && $type->get_content_block_flag()) {
            $out['content_blocks'] = self::blockIds($ctx, (int) $tpl->get_id());
            if (!in_array('content_en', $out['content_blocks'], true)) {
                $out['warning'] = 'No {content} (content_en) block found: pages using this template cannot be saved.';
            }
        }
        return $out;
    }

    private static function blockIds(Context $ctx, int $tplId): array
    {
        try {
            return array_values(array_map(function ($b) {
                return $b['id'];
            }, $ctx->templateBlocks($tplId)));
        } catch (\Throwable $e) {
            return ['error: ' . $e->getMessage()];
        }
    }

    /** @return \CmsLayoutTemplate */
    private static function loadTemplate($ref)
    {
        try {
            return \CmsLayoutTemplate::load(is_numeric($ref) ? (int) $ref : (string) $ref);
        } catch (\Throwable $e) {
            throw new ToolError("Template '$ref' not found");
        }
    }

    /** @return \CmsLayoutTemplateType */
    private static function loadType(string $ref)
    {
        try {
            return \CmsLayoutTemplateType::load(is_numeric($ref) ? (int) $ref : $ref);
        } catch (\Throwable $e) {
            throw new ToolError("Template type '$ref' not found (format Originator::name, e.g. Core::page; see list_template_types)");
        }
    }

    private static function typeName($originator, $name): string
    {
        return ($originator === \CmsLayoutTemplateType::CORE ? 'Core' : $originator) . '::' . $name;
    }

    // ================================================================ stylesheets

    public static function listStylesheets(array $args, Context $ctx): array
    {
        $p = CMS_DB_PREFIX;
        $sql = "SELECT id, name, description, media_type, media_query, modified FROM {$p}layout_stylesheets WHERE 1=1";
        $params = [];
        if (($d = Util::int($args, 'design_id')) !== null) {
            $sql .= " AND id IN (SELECT css_id FROM {$p}layout_design_cssassoc WHERE design_id = ?)";
            $params[] = $d;
        }
        if (($s = Util::str($args, 'search')) !== null && $s !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . Util::likeEscape($s) . '%';
        }
        $designs = self::assoc($ctx, 'layout_design_cssassoc', 'css_id');
        $out = [];
        foreach ((array) $ctx->db->GetArray($sql . ' ORDER BY name', $params) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'description' => $r['description'],
                'media_types' => array_values(array_filter(array_map('trim', explode(',', (string) $r['media_type'])))),
                'media_query' => $r['media_query'],
                'designs' => $designs[(int) $r['id']] ?? [],
                'modified' => Util::isoDate($r['modified']),
            ];
        }
        return ['count' => count($out), 'stylesheets' => $out];
    }

    public static function getStylesheet(array $args, Context $ctx): array
    {
        $css = self::loadStylesheet($args['stylesheet']);
        $out = self::describeStylesheet($css, $ctx);
        $out['content'] = $css->get_content();
        return $out;
    }

    public static function createStylesheet(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Stylesheets');
        $css = new \CmsLayoutStylesheet();
        try {
            $css->set_name(trim(Util::requireStr($args, 'name')));
            self::applyStylesheetFields($css, $args, $ctx);
            $css->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not create the stylesheet: ' . $e->getMessage());
        }
        return ['created' => true, 'stylesheet' => self::describeStylesheet($css, $ctx)];
    }

    public static function updateStylesheet(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Stylesheets');
        $css = self::loadStylesheet($args['stylesheet']);
        $ctx->assertNotLocked('stylesheet', (int) $css->get_id());
        try {
            if (($name = Util::str($args, 'name')) !== null) $css->set_name(trim($name));
            self::applyStylesheetFields($css, $args, $ctx);
            $css->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not save the stylesheet: ' . $e->getMessage());
        }
        return ['updated' => true, 'stylesheet' => self::describeStylesheet($css, $ctx)];
    }

    public static function deleteStylesheet(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Stylesheets');
        $css = self::loadStylesheet($args['stylesheet']);
        $id = (int) $css->get_id();
        $ctx->assertNotLocked('stylesheet', $id);
        $name = $css->get_name();
        $css->delete();
        return ['deleted' => true, 'stylesheet' => ['id' => $id, 'name' => $name]];
    }

    private static function applyStylesheetFields(\CmsLayoutStylesheet $css, array $args, Context $ctx)
    {
        if (($c = Util::str($args, 'content')) !== null) $css->set_content($c);
        if (($d = Util::str($args, 'description')) !== null) $css->set_description($d);
        if (($m = Util::arr($args, 'media_types')) !== null) $css->set_media_types(array_values(array_map('strval', $m)));
        if (($q = Util::str($args, 'media_query')) !== null) $css->set_media_query($q);
        if (($designs = Util::intList($args, 'designs')) !== null) {
            foreach ($designs as $did) self::loadDesign($did);
            $css->set_designs($designs);
        }
    }

    private static function describeStylesheet(\CmsLayoutStylesheet $css, Context $ctx): array
    {
        return [
            'id' => (int) $css->get_id(),
            'name' => $css->get_name(),
            'description' => $css->get_description(),
            'media_types' => array_values((array) $css->get_media_types()),
            'media_query' => $css->get_media_query(),
            'designs' => array_map('intval', (array) $css->get_designs()),
            'created' => Util::isoDate($css->get_created()),
            'modified' => Util::isoDate($css->get_modified()),
            'lock' => $css->get_id() ? $ctx->lockInfo('stylesheet', (int) $css->get_id()) : null,
        ];
    }

    /** @return \CmsLayoutStylesheet */
    private static function loadStylesheet($ref)
    {
        try {
            return \CmsLayoutStylesheet::load(is_numeric($ref) ? (int) $ref : (string) $ref);
        } catch (\Throwable $e) {
            throw new ToolError("Stylesheet '$ref' not found");
        }
    }

    // ================================================================ designs

    public static function listDesigns(array $args, Context $ctx): array
    {
        $out = [];
        foreach ((array) \CmsLayoutCollection::get_all() as $d) {
            $out[] = self::describeDesign($d, $ctx, false);
        }
        return ['count' => count($out), 'designs' => $out];
    }

    public static function getDesign(array $args, Context $ctx): array
    {
        return self::describeDesign(self::loadDesign($args['design']), $ctx, true);
    }

    public static function createDesign(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Designs');
        $d = new \CmsLayoutCollection();
        try {
            $d->set_name(trim(Util::requireStr($args, 'name')));
            self::applyDesignFields($d, $args);
            $d->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not create the design: ' . $e->getMessage());
        }
        return ['created' => true, 'design' => self::describeDesign(self::loadDesign((int) $d->get_id()), $ctx, true)];
    }

    public static function updateDesign(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Designs');
        $d = self::loadDesign($args['design']);
        try {
            if (($name = Util::str($args, 'name')) !== null) $d->set_name(trim($name));
            self::applyDesignFields($d, $args);
            $d->save();
        } catch (\CmsException $e) {
            throw new ToolError('Could not save the design: ' . $e->getMessage());
        }
        return ['updated' => true, 'design' => self::describeDesign(self::loadDesign((int) $d->get_id()), $ctx, true)];
    }

    public static function deleteDesign(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Manage Designs');
        $d = self::loadDesign($args['design']);
        if ($d->get_default()) throw new ToolError('The default design cannot be deleted: make another design the default first');
        $pages = self::pagesUsingDesign($ctx, (int) $d->get_id());
        if ($pages > 0) throw new ToolError("This design is used by $pages page(s): assign another design to them first");
        $force = (bool) Util::bool($args, 'force', false);
        if ($d->has_templates() && !$force) {
            throw new ToolError('This design has templates attached (they would be kept, only detached). Pass force=true to delete anyway.');
        }
        $info = ['id' => (int) $d->get_id(), 'name' => $d->get_name()];
        try {
            $d->delete($force);
        } catch (\CmsException $e) {
            throw new ToolError('Could not delete the design: ' . $e->getMessage());
        }
        return ['deleted' => true, 'design' => $info];
    }

    private static function applyDesignFields(\CmsLayoutCollection $d, array $args)
    {
        if (($desc = Util::str($args, 'description')) !== null) $d->set_description($desc);
        if (($tpls = Util::intList($args, 'templates')) !== null) {
            foreach ($tpls as $id) self::loadTemplate($id);
            $d->set_templates($tpls);
        }
        if (($css = Util::intList($args, 'stylesheets')) !== null) {
            foreach ($css as $id) self::loadStylesheet($id);
            $d->set_stylesheets($css);
        }
        if (($flag = Util::bool($args, 'default')) !== null) {
            if (!$flag && $d->get_default()) throw new ToolError('To change the default design, set default=true on the new default design');
            $d->set_default($flag);
        }
    }

    private static function describeDesign(\CmsLayoutCollection $d, Context $ctx, bool $full): array
    {
        $p = CMS_DB_PREFIX;
        $id = (int) $d->get_id();
        $tpls = [];
        foreach ((array) $ctx->db->GetArray("SELECT t.id, t.name FROM {$p}layout_design_tplassoc a JOIN {$p}layout_templates t ON t.id = a.tpl_id WHERE a.design_id = ? ORDER BY t.name", [$id]) as $r) {
            $tpls[] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $css = [];
        foreach ((array) $ctx->db->GetArray("SELECT s.id, s.name FROM {$p}layout_design_cssassoc a JOIN {$p}layout_stylesheets s ON s.id = a.css_id WHERE a.design_id = ? ORDER BY a.item_order", [$id]) as $r) {
            $css[] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $out = [
            'id' => $id,
            'name' => $d->get_name(),
            'description' => $d->get_description(),
            'default' => (bool) $d->get_default(),
            'templates' => $tpls,
            'stylesheets' => $css,
            'modified' => Util::isoDate($d->get_modified()),
        ];
        if ($full) $out['used_by_pages'] = self::pagesUsingDesign($ctx, $id);
        return $out;
    }

    private static function pagesUsingDesign(Context $ctx, int $id): int
    {
        return (int) $ctx->db->GetOne('SELECT COUNT(*) FROM ' . CMS_DB_PREFIX . "content_props WHERE prop_name = 'design_id' AND content = ?", [(string) $id]);
    }

    /** @return \CmsLayoutCollection */
    private static function loadDesign($ref)
    {
        try {
            return \CmsLayoutCollection::load(is_numeric($ref) ? (int) $ref : (string) $ref);
        } catch (\Throwable $e) {
            throw new ToolError("Design '$ref' not found");
        }
    }

    /** item id => [design ids] */
    private static function assoc(Context $ctx, string $table, string $col): array
    {
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT design_id, ' . $col . ' AS item FROM ' . CMS_DB_PREFIX . $table) as $r) {
            $out[(int) $r['item']][] = (int) $r['design_id'];
        }
        return $out;
    }
}
