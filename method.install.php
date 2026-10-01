<?php
if (!defined('CMS_VERSION')) exit;

$this->CreatePermission(MCPServer::MANAGE_PERM, MCPServer::MANAGE_PERM);

// One row per agent: only the sha256 of the key is stored.
$dict = NewDataDictionary($db);
$flds = "
    id I KEY AUTO,
    label C(100) NOTNULL,
    key_hash C(64) NOTNULL,
    key_prefix C(16),
    user_id I NOTNULL,
    readonly I1 DEFAULT 0,
    allow_udt_write I1 DEFAULT 0,
    active I1 DEFAULT 1,
    created I,
    created_by I,
    last_used I,
    last_ip C(45)
";
$sqlarray = $dict->CreateTableSQL(CMS_DB_PREFIX . MCPServer::KEYS_TABLE, $flds, ['mysql' => 'ENGINE=InnoDB']);
$dict->ExecuteSQLArray($sqlarray);
$sqlarray = $dict->CreateIndexSQL('idx_mcpserver_key_hash', CMS_DB_PREFIX . MCPServer::KEYS_TABLE, 'key_hash', ['UNIQUE']);
$dict->ExecuteSQLArray($sqlarray);

// Disabled until an administrator creates a key and enables the server.
$this->SetPreference('enabled', 0);
$this->SetPreference('endpoint', 'mcp');
$this->SetPreference('render_base_url', '');
$this->SetPreference('allowed_origins', '*');
$this->SetPreference('max_upload_mb', 10);
