<?php
/**
 * Loads the MCP server classes (no Composer: shipped inside the MCPServer module).
 */

if (!defined('CMS_VERSION')) exit;

foreach ([
    'ToolError', 'Util', 'Http', 'Server', 'Registry', 'Context',
    'Tools/SiteTools', 'Tools/PageTools', 'Tools/DesignTools', 'Tools/NewsTools',
    'Tools/UdtTools', 'Tools/FileTools', 'Tools/UtilityTools', 'Tools/LiseTools', 'Tools/GalleryTools',
] as $__mcp_file) {
    require_once __DIR__ . '/' . $__mcp_file . '.php';
}
unset($__mcp_file);
