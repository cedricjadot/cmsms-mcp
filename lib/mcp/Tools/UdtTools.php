<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * User Defined Tags (UserTagOperations). A UDT body is PHP executed by the site:
 * writing is only exposed when allowed in the MCP Server settings.
 */
final class UdtTools
{
    public static function register(Registry $r, Context $ctx)
    {
        $r->add([
            'name' => 'list_udts',
            'title' => 'List user defined tags',
            'description' => 'User Defined Tags (UDT): Smarty tags implemented in PHP, used in templates as {tag_name param=value}.',
            'handler' => [__CLASS__, 'listUdts'],
        ]);
        $r->add([
            'name' => 'get_udt',
            'title' => 'Get user defined tag',
            'description' => 'A UDT with its PHP code (body of function($params, $smarty)).',
            'properties' => ['udt' => ['type' => ['integer', 'string'], 'description' => 'UDT id or name']],
            'required' => ['udt'],
            'handler' => [__CLASS__, 'getUdt'],
        ]);
        $r->add([
            'name' => 'save_udt',
            'title' => 'Create or update user defined tag',
            'description' => 'Create a UDT, or update it when "udt" (id or name) is given. The code is the PHP body of function($params, $smarty) (no <?php tag); it is syntax-checked but not executed. WARNING: this is PHP run by the site.',
            'properties' => [
                'udt' => ['type' => ['integer', 'string'], 'description' => 'Existing UDT id or name to update (omit to create)'],
                'name' => ['type' => 'string', 'description' => 'Tag name (letters, digits, underscore). Required when creating.'],
                'code' => ['type' => 'string', 'description' => 'PHP code'],
                'description' => ['type' => 'string'],
            ],
            'write' => true,
            'requires_udt_write' => true,
            'handler' => [__CLASS__, 'saveUdt'],
        ]);
        $r->add([
            'name' => 'delete_udt',
            'title' => 'Delete user defined tag',
            'description' => 'Delete a UDT. Templates or pages still calling it will break.',
            'properties' => ['udt' => ['type' => ['integer', 'string'], 'description' => 'UDT id or name']],
            'required' => ['udt'],
            'write' => true,
            'destructive' => true,
            'requires_udt_write' => true,
            'handler' => [__CLASS__, 'deleteUdt'],
        ]);
    }

    public static function listUdts(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify User-defined Tags');
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT userplugin_id, userplugin_name, description, modified_date FROM ' . CMS_DB_PREFIX . 'userplugins ORDER BY userplugin_name') as $r) {
            $out[] = [
                'id' => (int) $r['userplugin_id'],
                'name' => $r['userplugin_name'],
                'description' => $r['description'],
                'modified' => Util::isoDate($r['modified_date']),
            ];
        }
        return ['count' => count($out), 'udts' => $out, 'write_allowed' => $ctx->allowUdtWrite && !$ctx->readonly];
    }

    public static function getUdt(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify User-defined Tags');
        $row = self::load($ctx, $args['udt']);
        return [
            'id' => (int) $row['userplugin_id'],
            'name' => $row['userplugin_name'],
            'description' => $row['description'],
            'code' => $row['code'],
            'created' => Util::isoDate($row['create_date']),
            'modified' => Util::isoDate($row['modified_date']),
        ];
    }

    public static function saveUdt(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify User-defined Tags');
        $ops = \UserTagOperations::get_instance();
        $existing = isset($args['udt']) && $args['udt'] !== '' ? self::load($ctx, $args['udt']) : null;

        $name = trim((string) Util::str($args, 'name', $existing ? $existing['userplugin_name'] : ''));
        $code = Util::str($args, 'code', $existing ? $existing['code'] : null);
        $description = Util::str($args, 'description', $existing ? $existing['description'] : '');
        if ($name === '') throw new ToolError('name is required to create a UDT');
        if ($code === null || trim($code) === '') throw new ToolError('code is required');

        // Same checks as admin/editusertag.php
        if (!preg_match('<^[ a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$>', $name)) {
            throw new ToolError('Invalid UDT name: use letters, digits and underscores, not starting with a digit');
        }
        $dupe = $ctx->db->GetOne('SELECT userplugin_id FROM ' . CMS_DB_PREFIX . 'userplugins WHERE userplugin_name = ?', [$name]);
        if ($dupe && (!$existing || (int) $dupe !== (int) $existing['userplugin_id'])) {
            throw new ToolError("A UDT named '$name' already exists");
        }
        if (!$existing && $ops->SmartyTagExists($name, false)) {
            throw new ToolError("'$name' is already the name of a Smarty plugin or module tag");
        }
        $code = trim($code);
        if (Util::startsWith($code, '<?php')) $code = substr($code, 5);
        if (substr($code, -2) === '?>') $code = substr($code, 0, -2);
        $code = trim($code);
        self::syntaxCheck($code);

        $id = $existing ? (int) $existing['userplugin_id'] : null;
        if (!$ops->SetUserTag($name, $code, $description, $id)) {
            throw new ToolError('Could not save the UDT: ' . $ctx->db->ErrorMsg());
        }
        $row = self::load($ctx, $name);
        audit((int) $row['userplugin_id'], 'User Defined Tag: ' . $name, ($existing ? 'Edited' : 'Added') . ' (MCP)');

        return [
            ($existing ? 'updated' : 'created') => true,
            'udt' => ['id' => (int) $row['userplugin_id'], 'name' => $row['userplugin_name'], 'description' => $row['description']],
            'usage' => '{' . $name . '}',
        ];
    }

    public static function deleteUdt(array $args, Context $ctx): array
    {
        $ctx->requirePerm('Modify User-defined Tags');
        $row = self::load($ctx, $args['udt']);
        $id = (int) $row['userplugin_id'];
        $name = $row['userplugin_name'];
        \CMSMS\HookManager::do_hook('Core::DeleteUserDefinedTagPre', ['id' => $id, 'name' => &$name]);
        if (!\UserTagOperations::get_instance()->RemoveUserTag($name)) {
            throw new ToolError('Could not delete the UDT');
        }
        \CMSMS\HookManager::do_hook('Core::DeleteUserDefinedTagPost', ['id' => $id, 'name' => &$name]);
        audit($id, 'User Defined Tag: ' . $name, 'Deleted (MCP)');
        return ['deleted' => true, 'udt' => ['id' => $id, 'name' => $name]];
    }

    /**
     * Compile without executing: the code becomes the body of a closure that is
     * created but never called (so nothing inside is declared or run).
     */
    private static function syntaxCheck(string $code)
    {
        if (strrpos($code, '{') > strrpos($code, '}')) {
            throw new ToolError('Invalid code: a closing brace is missing');
        }
        try {
            eval('return static function ($params, $smarty) {' . $code . "\n};");
        } catch (\ParseError $e) {
            throw new ToolError('PHP syntax error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        }
    }

    private static function load(Context $ctx, $ref): array
    {
        $col = is_numeric($ref) ? 'userplugin_id' : 'userplugin_name';
        $row = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . "userplugins WHERE $col = ?", [is_numeric($ref) ? (int) $ref : (string) $ref]);
        if (!$row) throw new ToolError("UDT '$ref' not found");
        return $row;
    }
}
