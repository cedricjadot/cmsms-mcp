<?php
if (!defined('CMS_VERSION')) exit;

$dict = NewDataDictionary($db);
$sqlarray = $dict->DropTableSQL(CMS_DB_PREFIX . MCPServer::KEYS_TABLE);
$dict->ExecuteSQLArray($sqlarray);

$this->RemovePermission(MCPServer::MANAGE_PERM);
$this->RemovePreference();
