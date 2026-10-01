<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * LISE (List It Special Edition) and its generated instances (LISE<Name> modules).
 *
 * Uses the LISE API like the LISE admin actions: LISEDuplicator + InstallModule for
 * instances, LISEFielddefOperations for field definitions, InitiateItem /
 * LoadItemByIdentifier / SaveItem / DeleteItemById for items (field event handlers,
 * search index, PreItemSave/PostItemSave events), SaveCategory / DeleteCategoryById.
 * Permissions are the per-instance ones: <alias>_modify_item, _remove_item,
 * _modify_category, _modify_option.
 */
final class LiseTools
{
    const MODES = ['list' => 0, 'local' => 1, 'global' => 2];
    const MULTI_TYPES = ['CheckboxGroup', 'MultiSelect', 'JQueryMultiSelect', 'Tags'];

    public static function register(Registry $r, Context $ctx)
    {
        if (!\cms_utils::get_module('LISE')) return;

        $instance = ['type' => 'string', 'description' => 'Instance module name, e.g. "LISEProducts" (or "Products")'];
        $itemRef = ['type' => ['integer', 'string'], 'description' => 'Item id or alias'];
        $catRef = ['type' => ['integer', 'string'], 'description' => 'Category id, alias or name'];
        $fieldRef = ['type' => ['integer', 'string'], 'description' => 'Field definition id or alias'];

        // ------------------------------------------------------------ instances
        $r->add([
            'name' => 'lise_list_instances',
            'title' => 'List LISE instances',
            'description' => 'LISE instances (each one is a module "LISE<Name>" with its own items, categories and field definitions): name, friendly name, mode, counts, Smarty tag to display it.',
            'handler' => [__CLASS__, 'listInstances'],
        ]);
        $r->add([
            'name' => 'lise_create_instance',
            'title' => 'Create LISE instance',
            'description' => 'Create and install a new LISE instance (module "LISE<name>"), like LISE admin > Instances > Create. Then define its fields with lise_save_field. Requires "Modify Modules".',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Letters and digits only, e.g. "Products" -> module LISEProducts'],
                'friendly_name' => ['type' => 'string', 'description' => 'Name shown in the admin menu'],
                'description' => ['type' => 'string'],
                'admin_section' => ['type' => 'string', 'enum' => ['main', 'content', 'layout', 'files', 'usersgroups', 'extensions', 'siteadmin', 'myprefs', 'ecommerce'], 'description' => 'Default content'],
                'item_singular' => ['type' => 'string', 'description' => 'Label of one item, e.g. "Product"'],
                'item_plural' => ['type' => 'string', 'description' => 'Label of several items, e.g. "Products"'],
                'mode' => ['type' => 'string', 'enum' => array_keys(self::MODES), 'description' => 'LISE instance mode (default list)'],
            ],
            'required' => ['name'],
            'write' => true,
            'handler' => [__CLASS__, 'createInstance'],
        ]);
        $r->add([
            'name' => 'lise_delete_instance',
            'title' => 'Delete LISE instance',
            'description' => 'Uninstall a LISE instance and delete its module files: ALL its items, categories, fields and templates are lost. Pass confirm = the full module name.',
            'properties' => ['instance' => $instance, 'confirm' => ['type' => 'string', 'description' => 'Must equal the full module name, e.g. "LISEProducts"']],
            'required' => ['instance', 'confirm'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteInstance'],
        ]);

        // ------------------------------------------------------------ fields
        $r->add([
            'name' => 'lise_list_field_types',
            'title' => 'List LISE field types',
            'description' => 'Field definition types available for an instance (TextInput, TextArea, Dropdown, Checkbox, Categories, SelectDateTime, CoreFilePicker...) with their option keys.',
            'properties' => ['instance' => $instance],
            'required' => ['instance'],
            'handler' => [__CLASS__, 'listFieldTypes'],
        ]);
        $r->add([
            'name' => 'lise_list_fields',
            'title' => 'List LISE fields',
            'description' => 'Field definitions (custom structure) of an instance: id, name, alias (used in templates and in item "fields"), type, required, help, options.',
            'properties' => ['instance' => $instance],
            'required' => ['instance'],
            'handler' => [__CLASS__, 'listFields'],
        ]);
        $r->add([
            'name' => 'lise_save_field',
            'title' => 'Create or update LISE field',
            'description' => 'Create a field definition (give type), or update one (give field). Options depend on the type (see lise_list_field_types), e.g. Dropdown {"options": "Red\nGreen\nBlue"}, TextInput {"max_length": "80"}, Categories {"subtype": "MultiSelect"}.',
            'properties' => [
                'instance' => $instance,
                'field' => $fieldRef + ['description' => 'Existing field id or alias (omit to create)'],
                'type' => ['type' => 'string', 'description' => 'Field type when creating, e.g. "TextInput", "TextArea", "Dropdown", "Categories"'],
                'name' => ['type' => 'string'],
                'alias' => ['type' => 'string', 'description' => 'Template alias (generated from the name if omitted)'],
                'help' => ['type' => 'string'],
                'required' => ['type' => 'boolean'],
                'options' => ['type' => 'object', 'description' => 'Type specific options {key: value}'],
            ],
            'required' => ['instance'],
            'write' => true,
            'handler' => [__CLASS__, 'saveField'],
        ]);
        $r->add([
            'name' => 'lise_delete_field',
            'title' => 'Delete LISE field',
            'description' => 'Delete a field definition and all its values in every item.',
            'properties' => ['instance' => $instance, 'field' => $fieldRef],
            'required' => ['instance', 'field'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteField'],
        ]);

        // ------------------------------------------------------------ items
        $r->add([
            'name' => 'lise_list_items',
            'title' => 'List LISE items',
            'description' => 'Items of an instance in admin order. Filter by category, active state or text (title/alias/field values). include_fields=true adds the field values.',
            'properties' => [
                'instance' => $instance,
                'category' => $catRef,
                'active' => ['type' => 'boolean'],
                'search' => ['type' => 'string'],
                'include_fields' => ['type' => 'boolean', 'description' => 'Default false'],
                'limit' => ['type' => 'integer', 'description' => 'Default 50, max 500'],
                'offset' => ['type' => 'integer'],
            ],
            'required' => ['instance'],
            'handler' => [__CLASS__, 'listItems'],
        ]);
        $r->add([
            'name' => 'lise_get_item',
            'title' => 'Get LISE item',
            'description' => 'One item with all its field values and categories.',
            'properties' => ['instance' => $instance, 'item' => $itemRef],
            'required' => ['instance', 'item'],
            'handler' => [__CLASS__, 'getItem'],
        ]);
        $itemFields = [
            'title' => ['type' => 'string'],
            'alias' => ['type' => 'string'],
            'active' => ['type' => 'boolean'],
            'url' => ['type' => 'string', 'description' => 'Pretty URL of the item, e.g. "products/blue-chair" (no leading/trailing slash)'],
            'start_time' => ['type' => 'string', 'description' => 'Display from (date). Empty string clears.'],
            'end_time' => ['type' => 'string', 'description' => 'Display until (date). Empty string clears.'],
            'key1' => ['type' => 'string'],
            'key2' => ['type' => 'string'],
            'key3' => ['type' => 'string'],
            'fields' => ['type' => 'object', 'description' => 'Field values {"field alias, id or name": value}. Multi-value fields take an array. Categories fields take category ids, aliases or names. Unlisted fields keep their value.'],
        ];
        $r->add([
            'name' => 'lise_create_item',
            'title' => 'Create LISE item',
            'description' => 'Create an item in an instance (field values validated by their field types, search index and events handled by LISE).',
            'properties' => ['instance' => $instance] + $itemFields,
            'required' => ['instance', 'title'],
            'write' => true,
            'handler' => [__CLASS__, 'createItem'],
        ]);
        $r->add([
            'name' => 'lise_update_item',
            'title' => 'Update LISE item',
            'description' => 'Modify an item: only the given properties and fields change.',
            'properties' => ['instance' => $instance, 'item' => $itemRef] + $itemFields,
            'required' => ['instance', 'item'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateItem'],
        ]);
        $r->add([
            'name' => 'lise_delete_item',
            'title' => 'Delete LISE item',
            'description' => 'Permanently delete an item (and its field values, category links, uploaded files).',
            'properties' => ['instance' => $instance, 'item' => $itemRef],
            'required' => ['instance', 'item'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteItem'],
        ]);

        // ------------------------------------------------------------ categories
        $r->add([
            'name' => 'lise_list_categories',
            'title' => 'List LISE categories',
            'description' => 'Categories of an instance (hierarchical) with item counts.',
            'properties' => ['instance' => $instance],
            'required' => ['instance'],
            'handler' => [__CLASS__, 'listCategories'],
        ]);
        $r->add([
            'name' => 'lise_save_category',
            'title' => 'Create or update LISE category',
            'description' => 'Create a category, or update it when "category" is given. Items are put in categories through a field of type Categories.',
            'properties' => [
                'instance' => $instance,
                'category' => $catRef + ['description' => 'Existing category (omit to create)'],
                'name' => ['type' => 'string'],
                'alias' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'parent' => $catRef + ['description' => 'Parent category; -1 = top level'],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['instance'],
            'write' => true,
            'handler' => [__CLASS__, 'saveCategory'],
        ]);
        $r->add([
            'name' => 'lise_delete_category',
            'title' => 'Delete LISE category',
            'description' => 'Delete a category (items are kept, they just leave the category).',
            'properties' => ['instance' => $instance, 'category' => $catRef],
            'required' => ['instance', 'category'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteCategory'],
        ]);
    }

    // ================================================================ instances

    public static function listInstances(array $args, Context $ctx): array
    {
        $lise = $ctx->module('LISE');
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT module_id, module_name, module_mode FROM ' . CMS_DB_PREFIX . 'module_lise_instances ORDER BY module_name') as $row) {
            $mod = \cms_utils::get_module($row['module_name']);
            $info = [
                'module' => $row['module_name'],
                'mode' => array_search((int) $row['module_mode'], self::MODES, true) ?: (string) $row['module_mode'],
                'loaded' => is_object($mod),
            ];
            if (is_object($mod)) {
                $alias = $mod->_GetModuleAlias();
                $p = CMS_DB_PREFIX . 'module_' . $alias;
                $info += [
                    'friendly_name' => $mod->GetFriendlyName(),
                    'description' => $mod->GetAdminDescription(),
                    'version' => $mod->GetVersion(),
                    'item_singular' => $mod->GetPreference('item_singular', ''),
                    'item_plural' => $mod->GetPreference('item_plural', ''),
                    'items' => (int) $ctx->db->GetOne("SELECT COUNT(*) FROM {$p}_item"),
                    'categories' => (int) $ctx->db->GetOne("SELECT COUNT(*) FROM {$p}_category"),
                    'fields' => (int) $ctx->db->GetOne("SELECT COUNT(*) FROM {$p}_fielddef"),
                    'smarty_tag' => '{' . $row['module_name'] . '}',
                    'template_types' => $row['module_name'] . '::summary / ::detail / ::category / ::archive / ::search',
                ];
            }
            $out[] = $info;
        }
        return ['lise_version' => $lise->GetVersion(), 'count' => count($out), 'instances' => $out];
    }

    public static function createInstance(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Modules');
        $ctx->module('LISE');
        $name = trim(Util::requireStr($args, 'name'));
        if (stripos($name, 'LISE') === 0 && strlen($name) > 4) $name = substr($name, 4);
        if (!preg_match('/^[0-9a-zA-Z]+$/', $name)) throw new ToolError('Invalid name: letters and digits only (e.g. "Products")');
        if (in_array(strtolower($name), ['lise', 'original', 'xdefs', 'loader'], true)) throw new ToolError("'$name' is a reserved name");
        $full = 'LISE' . $name;
        foreach ((array) $ctx->db->GetCol('SELECT module_name FROM ' . CMS_DB_PREFIX . 'module_lise_instances') as $existing) {
            if (strcasecmp($existing, $full) === 0) throw new ToolError("The instance $existing already exists");
        }
        if (file_exists(CMS_ROOT_PATH . '/modules/' . $full)) throw new ToolError("modules/$full already exists on disk");
        if (!is_writable(CMS_ROOT_PATH . '/modules')) throw new ToolError('The modules/ directory is not writable by the web server');

        $mode = self::MODES[Util::str($args, 'mode', 'list')] ?? null;
        if ($mode === null) throw new ToolError('mode must be list, local or global');

        $ctx->moduleClass('LISE', 'LISEDuplicator');
        try {
            (new \LISEDuplicator($name))->Run();
        } catch (\Throwable $e) {
            throw new ToolError('Could not create the module files: ' . $e->getMessage());
        }
        $res = \ModuleOperations::get_instance()->InstallModule($full);
        if (!is_array($res) || empty($res[0])) {
            throw new ToolError("Module $full created but its installation failed: " . (is_array($res) ? strip_tags((string) ($res[1] ?? '')) : 'unknown error'));
        }
        $mod = \cms_utils::get_module($full);
        if (!is_object($mod)) throw new ToolError("Module $full installed but could not be loaded");
        $mod->SetPreference('friendlyname', Util::str($args, 'friendly_name', $name));
        $mod->SetPreference('adminsection', Util::str($args, 'admin_section', 'content'));
        if (($d = Util::str($args, 'description')) !== null) $mod->SetPreference('moddescription', $d);
        if (($s = Util::str($args, 'item_singular')) !== null) $mod->SetPreference('item_singular', $s);
        if (($s = Util::str($args, 'item_plural')) !== null) $mod->SetPreference('item_plural', $s);
        $mod->SetMode($mode);
        audit('', 'LISE', "Instance $full created (MCP)");

        return [
            'created' => true,
            'instance' => $full,
            'smarty_tag' => '{' . $full . '}',
            'next' => 'Define the item structure with lise_save_field, then add items with lise_create_item.',
        ];
    }

    public static function deleteInstance(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify Modules');
        $mod = self::instance($ctx, $args['instance']);
        $full = $mod->GetName();
        if (Util::str($args, 'confirm') !== $full) throw new ToolError("Confirmation mismatch: pass confirm=\"$full\" to delete this instance and all its data");
        $items = (int) $ctx->db->GetOne('SELECT COUNT(*) FROM ' . self::table($mod, 'item'));
        $res = \ModuleOperations::get_instance()->UninstallModule($full);
        if (is_array($res) && empty($res[0])) throw new ToolError('Uninstall failed: ' . strip_tags((string) ($res[1] ?? '')));
        $dir = CMS_ROOT_PATH . '/modules/' . $full;
        $filesRemoved = false;
        if (is_dir($dir) && realpath(dirname($dir)) === realpath(CMS_ROOT_PATH . '/modules')) {
            $filesRemoved = (bool) recursive_delete($dir);
        }
        audit('', 'LISE', "Instance $full deleted (MCP)");
        return ['deleted' => true, 'instance' => $full, 'items_deleted' => $items, 'files_removed' => $filesRemoved];
    }

    // ================================================================ fields

    public static function listFieldTypes(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $out = [];
        foreach ((array) \LISEFielddefOperations::GetFielddefTypes($mod->GetMode(), $mod) as $label => $type) {
            $out[] = ['type' => $type, 'label' => $label, 'options' => self::optionKeys($type)];
        }
        return ['instance' => $mod->GetName(), 'types' => $out];
    }

    public static function listFields(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $out = [];
        foreach ($mod->GetFieldDefs() as $f) $out[] = self::describeField($f);
        usort($out, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });
        return ['instance' => $mod->GetName(), 'fields' => $out];
    }

    public static function saveField(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_option');
        $db = $ctx->db;
        $t = self::table($mod, 'fielddef');

        $existing = isset($args['field']) && $args['field'] !== '';
        if ($existing) {
            $obj = self::loadField($mod, $args['field']);
            if (isset($args['type']) && strcasecmp($args['type'], $obj->GetType()) !== 0) {
                throw new ToolError('The type of an existing field cannot be changed: delete and recreate it');
            }
        } else {
            $type = self::resolveFieldType($mod, Util::requireStr($args, 'type'));
            $obj = \LISEFielddefOperations::LoadFielddefByType($type, $mod);
            if (!is_object($obj)) throw new ToolError("Could not load field type '$type'");
            if (Util::str($args, 'name') === null || trim($args['name']) === '') throw new ToolError('name is required when creating a field');
        }

        $name = trim((string) Util::str($args, 'name', $obj->GetName()));
        $alias = trim((string) Util::str($args, 'alias', $existing ? $obj->GetAlias() : ''));
        if ($name === '') throw new ToolError('The field name cannot be empty');
        if ($alias !== '' && !\lise_utils::is_valid_alias($alias)) throw new ToolError("Invalid alias '$alias' (letters, digits, underscores; not starting with a digit)");
        if ($alias !== '') {
            $dupe = $db->GetOne("SELECT fielddef_id FROM $t WHERE alias = ? AND fielddef_id != ?", [$alias, $existing ? $obj->GetId() : -1]);
            if ($dupe) throw new ToolError("Another field already uses the alias '$alias'");
        }
        if (!$existing && $obj->IsUnique() && \LISEFielddefOperations::TestExistenceByType($mod, $obj->GetType())) {
            throw new ToolError("Only one field of type {$obj->GetType()} is allowed per instance");
        }

        $obj->SetName($name);
        $obj->SetAlias($alias);
        if (($h = Util::str($args, 'help')) !== null) $obj->SetDesc($h);
        if (($req = Util::bool($args, 'required')) !== null) $obj->SetRequired($req ? 1 : 0);
        foreach (Util::arr($args, 'options', []) as $key => $value) {
            if (is_array($value)) $value = implode($key === 'options' ? "\n" : ',', array_map('strval', $value));
            if (is_bool($value)) $value = $value ? '1' : '0';
            $obj->SetOptionValue((string) $key, (string) $value);
        }
        try {
            \LISEFielddefOperations::Save($mod, $obj);
        } catch (\Throwable $e) {
            throw new ToolError('Could not save the field: ' . $e->getMessage());
        }
        return [($existing ? 'updated' : 'created') => true, 'field' => self::describeField(self::loadField($mod, $obj->GetId()))];
    }

    public static function deleteField(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_option');
        $obj = self::loadField($mod, $args['field']);
        $values = (int) $ctx->db->GetOne('SELECT COUNT(DISTINCT item_id) FROM ' . self::table($mod, 'fieldval') . ' WHERE fielddef_id = ?', [$obj->GetId()]);
        \LISEFielddefOperations::Delete($mod, $obj->GetId());
        return ['deleted' => true, 'field' => ['id' => $obj->GetId(), 'alias' => $obj->GetAlias()], 'items_with_values' => $values];
    }

    // ================================================================ items

    public static function listItems(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        [$limit, $offset] = Util::page($args, 50, 500);
        $ti = self::table($mod, 'item');
        $where = ' WHERE 1=1';
        $params = [];
        if (isset($args['category']) && $args['category'] !== '') {
            $where .= ' AND i.item_id IN (SELECT item_id FROM ' . self::table($mod, 'item_categories') . ' WHERE category_id = ?)';
            $params[] = self::resolveCategory($ctx, $mod, $args['category']);
        }
        if (($active = Util::bool($args, 'active')) !== null) {
            $where .= ' AND i.active = ?';
            $params[] = $active ? 1 : 0;
        }
        if (($q = Util::str($args, 'search')) !== null && $q !== '') {
            $like = '%' . Util::likeEscape($q) . '%';
            $where .= ' AND (i.title LIKE ? OR i.alias LIKE ? OR i.item_id IN (SELECT item_id FROM ' . self::table($mod, 'fieldval') . ' WHERE value LIKE ?))';
            array_push($params, $like, $like, $like);
        }
        $order = strtoupper((string) $mod->GetPreference('sortorder', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $total = (int) $ctx->db->GetOne("SELECT COUNT(*) FROM $ti i" . $where, $params);
        $rs = $ctx->db->SelectLimit("SELECT i.* FROM $ti i" . $where . " ORDER BY i.position $order, i.item_id", $limit, $offset, $params);
        $withFields = (bool) Util::bool($args, 'include_fields', false);
        $out = [];
        while ($rs && !$rs->EOF()) {
            $row = $rs->fields;
            $out[] = $withFields ? self::describeItem($ctx, $mod, self::loadItem($ctx, $mod, (int) $row['item_id'])) : self::describeRow($row);
            $rs->MoveNext();
        }
        return ['instance' => $mod->GetName(), 'total' => $total, 'offset' => $offset, 'count' => count($out), 'items' => $out];
    }

    public static function getItem(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        return self::describeItem($ctx, $mod, self::loadItem($ctx, $mod, self::resolveItemId($ctx, $mod, $args['item'])));
    }

    public static function createItem(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_item');
        $obj = $mod->InitiateItem();
        $obj->active = 1;
        self::applyItem($ctx, $mod, $obj, $args, false);
        return ['created' => true, 'item' => self::describeItem($ctx, $mod, self::loadItem($ctx, $mod, (int) $obj->item_id, true))];
    }

    public static function updateItem(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_item');
        $obj = self::loadItem($ctx, $mod, self::resolveItemId($ctx, $mod, $args['item']));
        self::applyItem($ctx, $mod, $obj, $args, true);
        return ['updated' => true, 'item' => self::describeItem($ctx, $mod, self::loadItem($ctx, $mod, (int) $obj->item_id, true))];
    }

    public static function deleteItem(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_remove_item');
        $id = self::resolveItemId($ctx, $mod, $args['item']);
        $title = $ctx->db->GetOne('SELECT title FROM ' . self::table($mod, 'item') . ' WHERE item_id = ?', [$id]);
        $mod->DeleteItemById($id);
        audit($id, $mod->GetName() . ': ' . $title, 'Item deleted (MCP)');
        return ['deleted' => true, 'item' => ['id' => $id, 'title' => $title]];
    }

    /** Same checks and order of operations as framework/action.admin_edititem.php */
    private static function applyItem(Context $ctx, $mod, $obj, array $args, bool $editing)
    {
        $errors = [];
        $ti = self::table($mod, 'item');
        if (isset($args['title'])) $obj->title = trim(Util::str($args, 'title'));
        if (trim((string) $obj->title) === '') $errors[] = 'A title is required';

        if (isset($args['alias'])) {
            $alias = trim(Util::str($args, 'alias'));
            if ($alias !== '' && !\lise_utils::is_valid_alias($alias)) $errors[] = "Invalid alias '$alias'";
            if ($alias !== '' && $ctx->db->GetOne("SELECT item_id FROM $ti WHERE alias = ? AND item_id != ?", [$alias, $editing ? (int) $obj->item_id : -1])) {
                $errors[] = "Another item already uses the alias '$alias'";
            }
            $obj->alias = $alias;
        } elseif (!$editing) {
            $obj->alias = '';
        }
        if (($active = Util::bool($args, 'active')) !== null) $obj->active = $active ? 1 : 0;
        foreach (['key1', 'key2', 'key3'] as $k) {
            if (array_key_exists($k, $args)) $obj->$k = Util::str($args, $k);
        }
        foreach (['start_time', 'end_time'] as $k) {
            if (!array_key_exists($k, $args)) continue;
            $v = trim((string) Util::str($args, $k, ''));
            if ($v !== '' && strtotime($v) === false) $errors[] = "$k is not a valid date";
            $obj->$k = $v;
        }
        if ($obj->start_time && $obj->end_time && strtotime($obj->start_time) > strtotime($obj->end_time)) $errors[] = 'start_time must be before end_time';

        if (array_key_exists('url', $args)) {
            $url = trim((string) Util::str($args, 'url', ''), " /\t\r\n\0\x08");
            if ($url !== '') {
                if (strtolower(munge_string_to_url($url, false, true)) !== strtolower($url)) {
                    $errors[] = 'url contains invalid characters';
                } elseif (!$editing || $url !== (string) $obj->url) {
                    \cms_route_manager::load_routes();
                    if (\cms_route_manager::find_match($url)) $errors[] = "url '$url' is already used by another route";
                }
            }
            $obj->url = $url;
        }

        $fields = Util::arr($args, 'fields', []);
        if ($fields) self::applyFieldValues($ctx, $mod, $obj, $fields);

        $params = [];
        foreach ($obj->fielddefs as $field) {
            $field->EventHandler()->ItemSavePreProcess($errors, $params);
        }
        if ($errors) throw new ToolError('Item validation failed', array_values(array_map('strip_tags', $errors)));

        try {
            $mod->SaveItem($obj);
        } catch (\Throwable $e) {
            throw new ToolError('Could not save the item: ' . $e->getMessage());
        }
        foreach ($obj->fielddefs as $field) {
            $field->EventHandler()->ItemSavePostProcess($errors, $params);
        }
        if ((int) $obj->item_id <= 0) throw new ToolError('The item was not saved');
        audit((int) $obj->item_id, $mod->GetName() . ': ' . $obj->title, ($editing ? 'Item edited' : 'Item added') . ' (MCP)');
    }

    private static function applyFieldValues(Context $ctx, $mod, $obj, array $values)
    {
        $byKey = [];
        foreach ($obj->fielddefs as $fid => $f) {
            $byKey[(string) $f->GetId()] = $fid;
            $byKey[strtolower($f->GetAlias())] = $fid;
            $byKey[strtolower($f->GetName())] = $fid;
        }
        foreach ($values as $key => $value) {
            $k = strtolower((string) $key);
            if (!isset($byKey[$k])) {
                $known = [];
                foreach ($obj->fielddefs as $f) $known[] = $f->GetAlias();
                throw new ToolError("Unknown field '$key' for {$mod->GetName()}", ['available_fields' => $known]);
            }
            $field = $obj->fielddefs[$byKey[$k]];
            $type = $field->GetType();
            if (is_bool($value)) $value = $value ? '1' : '';
            if (is_object($value)) throw new ToolError("Field '$key': value must be a string or a list");
            if ($type === 'Categories') {
                $value = array_map(function ($c) use ($ctx, $mod) {
                    return (string) self::resolveCategory($ctx, $mod, $c);
                }, array_values(array_filter((array) $value, function ($c) {
                    return $c !== '' && $c !== null;
                })));
            } elseif (is_array($value)) {
                $value = array_values(array_map('strval', $value));
            } else {
                $value = (string) $value;
                if (in_array($type, self::MULTI_TYPES, true) && $value !== '' && strpos($value, ',') !== false) {
                    $value = array_map('trim', explode(',', $value));
                }
            }
            $field->SetValue($value);
        }
    }

    // ================================================================ categories

    public static function listCategories(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $counts = [];
        foreach ((array) $ctx->db->GetArray('SELECT category_id, COUNT(*) AS n FROM ' . self::table($mod, 'item_categories') . ' GROUP BY category_id') as $r) {
            $counts[(int) $r['category_id']] = (int) $r['n'];
        }
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT * FROM ' . self::table($mod, 'category') . ' ORDER BY hierarchy') as $r) {
            $out[] = self::describeCategoryRow($r) + ['items' => $counts[(int) $r['category_id']] ?? 0];
        }
        return ['instance' => $mod->GetName(), 'categories' => $out];
    }

    public static function saveCategory(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_category');
        $tc = self::table($mod, 'category');
        $existing = isset($args['category']) && $args['category'] !== '';
        $id = $existing ? self::resolveCategory($ctx, $mod, $args['category']) : -1;
        $obj = $mod->LoadCategoryByIdentifier('category_id', $id);

        if (isset($args['name'])) $obj->name = trim(Util::str($args, 'name'));
        if (trim((string) $obj->name) === '') throw new ToolError('A category name is required');
        if (isset($args['alias'])) {
            $alias = trim(Util::str($args, 'alias'));
            if ($alias !== '' && $ctx->db->GetOne("SELECT category_id FROM $tc WHERE category_alias = ? AND category_id != ?", [$alias, $id])) {
                throw new ToolError("Another category already uses the alias '$alias'");
            }
            $obj->alias = $alias;
        } elseif (!$existing) {
            $obj->alias = '';
        }
        if (isset($args['description'])) $obj->description = Util::str($args, 'description');
        if (isset($args['parent']) && $args['parent'] !== '') {
            $parent = (string) $args['parent'] === '-1' ? -1 : self::resolveCategory($ctx, $mod, $args['parent']);
            if ($existing && $parent > 0) {
                if ($parent === $id) throw new ToolError('A category cannot be its own parent');
                $pHier = (string) $ctx->db->GetOne("SELECT id_hierarchy FROM $tc WHERE category_id = ?", [$parent]);
                if (in_array((string) $id, explode('.', $pHier), true)) throw new ToolError('A category cannot be moved under one of its sub-categories');
            }
            $obj->parent_id = $parent;
        }
        if (($active = Util::bool($args, 'active')) !== null) $obj->active = $active ? 1 : 0;
        if (!$existing && !isset($args['active'])) $obj->active = 1;

        try {
            $mod->SaveCategory($obj);
        } catch (\Throwable $e) {
            throw new ToolError('Could not save the category: ' . $e->getMessage());
        }
        $row = $ctx->db->GetRow("SELECT * FROM $tc WHERE category_id = ?", [(int) $obj->category_id]);
        if (!$row) throw new ToolError('The category was not saved');
        return [($existing ? 'updated' : 'created') => true, 'category' => self::describeCategoryRow($row)];
    }

    public static function deleteCategory(array $args, Context $ctx): array
    {
        $mod = self::instance($ctx, $args['instance']);
        $ctx->requirePerm($mod->_GetModuleAlias() . '_modify_category');
        $id = self::resolveCategory($ctx, $mod, $args['category']);
        $row = $ctx->db->GetRow('SELECT * FROM ' . self::table($mod, 'category') . ' WHERE category_id = ?', [$id]);
        $mod->DeleteCategoryById($id);
        return ['deleted' => true, 'category' => ['id' => $id, 'name' => $row['category_name']]];
    }

    // ================================================================ helpers

    /** @return \LISEInstance */
    private static function instance(Context $ctx, $ref)
    {
        $ctx->module('LISE');
        $ref = trim((string) $ref);
        $names = (array) $ctx->db->GetCol('SELECT module_name FROM ' . CMS_DB_PREFIX . 'module_lise_instances');
        $found = null;
        foreach ($names as $n) {
            if (strcasecmp($n, $ref) === 0 || strcasecmp($n, 'LISE' . $ref) === 0) $found = $n;
        }
        if ($found === null) throw new ToolError("LISE instance '$ref' not found", ['instances' => $names]);
        $mod = \cms_utils::get_module($found);
        if (!is_object($mod) || !($mod instanceof \LISEInstance)) throw new ToolError("LISE instance $found is not installed/active");
        // Loads the instance classes (LISEItem, operations...) from the LISE lib directory.
        foreach (['LISEItemOperations', 'LISEFielddefOperations', 'LISECategoryOperations', 'lise_utils'] as $class) {
            if (!class_exists($class)) $ctx->moduleClass('LISE', $class);
        }
        return $mod;
    }

    private static function table($mod, string $suffix): string
    {
        return CMS_DB_PREFIX . 'module_' . $mod->_GetModuleAlias() . '_' . $suffix;
    }

    private static function resolveItemId(Context $ctx, $mod, $ref): int
    {
        $t = self::table($mod, 'item');
        $id = is_numeric($ref)
            ? (int) $ctx->db->GetOne("SELECT item_id FROM $t WHERE item_id = ?", [(int) $ref])
            : (int) $ctx->db->GetOne("SELECT item_id FROM $t WHERE alias = ?", [(string) $ref]);
        if ($id < 1) throw new ToolError("Item '$ref' not found in {$mod->GetName()}");
        return $id;
    }

    private static function loadItem(Context $ctx, $mod, int $id, bool $fresh = false)
    {
        if ($fresh) {
            // LISE keeps loaded items in memory: bypass it to read what was saved.
            $obj = $mod->InitiateItem();
            $obj->item_id = $id;
            \LISEItemOperations::Load($mod, $obj);
        } else {
            $obj = $mod->LoadItemByIdentifier('item_id', $id);
        }
        if (!$obj || (int) $obj->item_id !== $id || $obj->title === null) throw new ToolError("Item $id not found");
        return $obj;
    }

    private static function resolveCategory(Context $ctx, $mod, $ref): int
    {
        $t = self::table($mod, 'category');
        if (is_numeric($ref)) {
            $id = (int) $ctx->db->GetOne("SELECT category_id FROM $t WHERE category_id = ?", [(int) $ref]);
        } else {
            $id = (int) $ctx->db->GetOne("SELECT category_id FROM $t WHERE category_alias = ? OR category_name = ? ORDER BY hierarchy", [(string) $ref, (string) $ref]);
        }
        if ($id < 1) throw new ToolError("Category '$ref' not found in {$mod->GetName()} (see lise_list_categories)");
        return $id;
    }

    private static function resolveFieldType($mod, string $type): string
    {
        foreach ((array) \LISEFielddefOperations::GetFielddefTypes($mod->GetMode(), $mod) as $label => $t) {
            if (strcasecmp($t, $type) === 0 || strcasecmp($label, $type) === 0 || strcasecmp(str_replace(' ', '', $label), $type) === 0) return $t;
        }
        throw new ToolError("Unknown field type '$type' (see lise_list_field_types)");
    }

    private static function loadField($mod, $ref)
    {
        $obj = is_numeric($ref)
            ? \LISEFielddefOperations::Load($mod, 'fielddef_id', (int) $ref)
            : \LISEFielddefOperations::Load($mod, 'alias', (string) $ref);
        if (!is_object($obj)) throw new ToolError("Field '$ref' not found in {$mod->GetName()} (see lise_list_fields)");
        return $obj;
    }

    /** Option keys a field type reads, taken from its admin template (custom_input[...]). */
    private static function optionKeys(string $type): array
    {
        $tpl = CMS_ROOT_PATH . "/modules/LISE/lib/fielddefs/$type/admin.$type.tpl";
        if (!is_file($tpl)) return [];
        preg_match_all('/custom_input\[([a-zA-Z0-9_]+)\]/', (string) file_get_contents($tpl), $m);
        return array_values(array_unique($m[1]));
    }

    private static function describeField($f): array
    {
        return [
            'id' => (int) $f->GetId(),
            'name' => $f->GetName(),
            'alias' => $f->GetAlias(),
            'type' => $f->GetType(),
            'required' => $f->IsRequired(),
            'help' => $f->GetDesc(),
            'position' => (int) $f->GetPosition(),
            'multi_value' => in_array($f->GetType(), self::MULTI_TYPES, true) || ($f->GetType() === 'Categories' && in_array($f->GetOptionValue('subtype', 'Dropdown'), self::MULTI_TYPES, true)),
            'options' => (object) $f->GetOptionValues(),
            'smarty' => '{$item->' . $f->GetAlias() . '}',
        ];
    }

    private static function describeRow(array $row): array
    {
        return [
            'id' => (int) $row['item_id'],
            'title' => $row['title'],
            'alias' => $row['alias'],
            'active' => (bool) $row['active'],
            'position' => (int) $row['position'],
            'url' => $row['url'] ?: null,
            'start_time' => $row['start_time'] ?: null,
            'end_time' => $row['end_time'] ?: null,
            'created' => Util::isoDate($row['create_time']),
            'modified' => Util::isoDate($row['modified_time']),
        ];
    }

    private static function describeItem(Context $ctx, $mod, $obj): array
    {
        $out = [
            'id' => (int) $obj->item_id,
            'title' => $obj->title,
            'alias' => $obj->alias,
            'active' => (bool) $obj->active,
            'position' => (int) $obj->position,
            'url' => $obj->url ?: null,
            'start_time' => $obj->start_time ?: null,
            'end_time' => $obj->end_time ?: null,
            'owner' => $ctx->userName((int) $obj->owner),
            'key1' => $obj->key1,
            'key2' => $obj->key2,
            'key3' => $obj->key3,
            'created' => Util::isoDate($obj->create_time),
            'modified' => Util::isoDate($obj->modified_time),
            'fields' => new \stdClass(),
        ];
        foreach ($obj->fielddefs as $f) {
            $vals = array_values((array) $f->GetValue('array'));
            $multi = in_array($f->GetType(), self::MULTI_TYPES, true) || $f->GetType() === 'Categories';
            $out['fields']->{$f->GetAlias()} = $multi ? $vals : (count($vals) > 1 ? $vals : (string) ($vals[0] ?? ''));
        }
        $cats = [];
        foreach ((array) $ctx->db->GetArray('SELECT c.category_id, c.category_name, c.category_alias FROM ' . self::table($mod, 'item_categories') . ' ic JOIN ' . self::table($mod, 'category') . ' c ON c.category_id = ic.category_id WHERE ic.item_id = ?', [(int) $obj->item_id]) as $c) {
            $cats[] = ['id' => (int) $c['category_id'], 'name' => $c['category_name'], 'alias' => $c['category_alias']];
        }
        $out['categories'] = $cats;
        return $out;
    }

    private static function describeCategoryRow(array $r): array
    {
        return [
            'id' => (int) $r['category_id'],
            'name' => $r['category_name'],
            'alias' => $r['category_alias'],
            'description' => $r['category_description'],
            'parent_id' => (int) $r['parent_id'],
            'path' => $r['hierarchy_path'],
            'active' => (bool) $r['active'],
        ];
    }
}
