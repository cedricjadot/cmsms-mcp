<?php
$lang['friendlyname'] = 'MCP Server';
$lang['admindescription'] = 'Let AI agents (Claude, MCP clients) manage this site through the Model Context Protocol, with one API key per agent.';
$lang['postinstall'] = 'MCP Server installed. Go to Extensions > MCP Server to create an API key and enable the server.';
$lang['really_uninstall'] = 'Uninstalling deletes every MCP API key: connected agents will lose access. Continue?';

$lang['section_status'] = 'Status';
$lang['status_on'] = 'Enabled — %d active API key(s)';
$lang['status_no_key'] = 'Enabled, but no active API key: every request is refused.';
$lang['status_off'] = 'Disabled: the endpoint answers 503 to every request.';
$lang['endpoint_urls'] = 'MCP endpoint (Streamable HTTP):';
$lang['forced_readonly'] = 'Read-only is forced for every key by $config[\'mcp_readonly\'] in config.php.';

$lang['section_keys'] = 'API keys';
$lang['keys_intro'] = 'Give each agent its own key. A key acts as the chosen CMSMS user and never has more rights than that user; it can also be limited to read-only. Revoke a key to cut an agent off immediately.';
$lang['key_label'] = 'Agent / label';
$lang['key_label_placeholder'] = 'e.g. Claude - content team';
$lang['key_id'] = 'Key';
$lang['key_user'] = 'Acts as CMSMS user';
$lang['key_user_help'] = 'The agent gets exactly the permissions of this user (prefer a dedicated user with limited permissions).';
$lang['key_rights'] = 'Rights';
$lang['key_created'] = 'Created';
$lang['key_last_used'] = 'Last used';
$lang['readonly'] = 'read-only';
$lang['readwrite'] = 'read/write';
$lang['readonly_help'] = 'Read-only: the agent can read the site but cannot modify anything';
$lang['udt_help'] = 'Allow writing User Defined Tags (PHP code executed by the site) — only for trusted agents';
$lang['superuser'] = 'superuser';
$lang['user_inactive'] = 'user inactive: key unusable';
$lang['revoked'] = 'revoked';
$lang['never'] = 'never';
$lang['make_rw'] = 'allow writing';
$lang['make_ro'] = 'make read-only';
$lang['udt_on'] = 'allow UDT';
$lang['udt_off'] = 'forbid UDT';
$lang['revoke'] = 'revoke';
$lang['reactivate'] = 'reactivate';
$lang['delete'] = 'delete';
$lang['confirm_delete'] = 'Delete this API key? The agent using it will lose access.';
$lang['no_keys'] = 'No API key yet: no agent can connect.';
$lang['create_key'] = 'Create an API key';

$lang['new_key_title'] = 'New API key';
$lang['new_key_once'] = 'Copy this key now: it is shown only once and cannot be retrieved later (only a hash is stored). If it is lost, revoke it and create a new one.';
$lang['new_key_value'] = 'API key:';
$lang['use_claude_ai'] = 'Claude.ai custom connector URL (contains the key):';
$lang['use_header'] = 'Or, for clients that send headers:';
$lang['use_claude_code'] = 'Claude Code:';

$lang['section_settings'] = 'Server settings';
$lang['enabled'] = 'Enabled';
$lang['enabled_help'] = 'Accept MCP requests (with a valid API key)';
$lang['locked_in_config'] = 'Set in config.php ($config[\'mcp_enabled\']).';
$lang['endpoint'] = 'Endpoint path';
$lang['endpoint_help'] = 'URL path of the endpoint (default "mcp"). It must not be the alias of a page.';
$lang['render_base_url'] = 'Render base URL';
$lang['render_base_url_help'] = 'Only if the public site URL is not reachable from the server itself (Docker, reverse proxy): used by render_page.';
$lang['allowed_origins'] = 'Allowed browser origins (CORS)';
$lang['allowed_origins_help'] = '* or a comma separated list of origins. Not needed for server-side clients like Claude.ai.';
$lang['max_upload_mb'] = 'Max file size for write_file';
$lang['save'] = 'Save';

$lang['msg_saved'] = 'Settings saved';
$lang['msg_key_created'] = 'API key created';
$lang['msg_key_revoked'] = 'API key revoked';
$lang['msg_key_deleted'] = 'API key deleted';
$lang['err_endpoint'] = 'Invalid endpoint path (letters, digits, "-", "_" and "/")';
$lang['err_endpoint_page'] = 'A page already uses the alias "%s": choose another endpoint path';
$lang['err_render_url'] = 'The render base URL must start with http:// or https://';
$lang['err_key_label'] = 'Give the key a label (which agent uses it)';
$lang['err_user'] = 'Choose an active CMSMS user';

$lang['help'] = <<<'EOT'
<h3>What does this module do?</h3>
<p>It turns the site into a <a href="https://modelcontextprotocol.io">Model Context Protocol</a> server (Streamable HTTP transport, JSON responses). AI agents such as Claude can then read and manage pages, content blocks, templates, stylesheets, designs, News, LISE instances, User Defined Tags and files in <code>uploads/</code>, search the site, clear the cache and render pages.</p>
<p>Every change goes through the CMSMS API (validation, events/hooks, search index, routes, admin log) with the permissions of the CMSMS user bound to the API key.</p>
<h3>Setup</h3>
<ol>
<li>Create a dedicated CMSMS user/group with only the permissions the agent needs (recommended).</li>
<li>In Extensions &gt; MCP Server, create an API key for the agent, bound to that user, read-only or read/write. Copy the key: it is displayed only once.</li>
<li>Enable the server.</li>
<li>Connect the agent: Claude.ai custom connector URL <code>https://your-site/mcp?key=...</code>, or header <code>Authorization: Bearer ...</code>.</li>
</ol>
<h3>Security</h3>
<ul>
<li>Requests without a valid, active key are refused (HTTP 401). Keys are stored as SHA-256 hashes.</li>
<li>Use HTTPS. With <code>?key=</code> the key may appear in web server access logs: prefer the Authorization header when the client supports it.</li>
<li>Apache + PHP-FPM/CGI may drop the Authorization header: add <code>CGIPassAuth On</code> to .htaccess, or use <code>?key=</code>.</li>
<li>config.php can lock settings: <code>$config['mcp_enabled']</code>, <code>mcp_endpoint</code>, <code>mcp_readonly</code> (forces read-only for every key), <code>mcp_render_base_url</code>, <code>mcp_allowed_origins</code>, <code>mcp_max_upload_bytes</code>.</li>
</ul>
EOT;
