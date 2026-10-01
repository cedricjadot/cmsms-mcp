<style>
.mcp-box { border:1px solid #ccc; border-radius:4px; padding:12px 16px; margin:0 0 20px; background:#fafafa; }
.mcp-new-key { border-color:#2e7d32; background:#f1f8e9; }
.mcp-code { font-family:monospace; background:#fff; border:1px solid #ddd; padding:6px 8px; display:block; word-break:break-all; user-select:all; margin:4px 0 10px; }
.mcp-warn { color:#b71c1c; font-weight:bold; }
.mcp-muted { color:#777; }
table.mcp-keys td, table.mcp-keys th { padding:6px 8px; vertical-align:top; }
.mcp-off { opacity:.55; }
.mcp-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:90%; }
.mcp-rw { background:#ffe0b2; } .mcp-ro { background:#e3f2fd; } .mcp-udt { background:#ffcdd2; }
</style>

{if $new_key}
<div class="mcp-box mcp-new-key">
  <h3>{$mod->Lang('new_key_title')} : {$new_key_row.label|escape}</h3>
  <p class="mcp-warn">{$mod->Lang('new_key_once')}</p>
  <p>{$mod->Lang('new_key_value')}</p>
  <code class="mcp-code">{$new_key}</code>
  <p>{$mod->Lang('use_claude_ai')}</p>
  <code class="mcp-code">{$claude_url|escape}</code>
  <p>{$mod->Lang('use_header')}</p>
  <code class="mcp-code">Authorization: Bearer {$new_key}</code>
  <p>{$mod->Lang('use_claude_code')}</p>
  <code class="mcp-code">claude mcp add --transport http cmsms {$endpoint_urls[0]|escape} --header "Authorization: Bearer {$new_key}"</code>
</div>
{/if}

<h3>{$mod->Lang('section_status')}</h3>
<div class="mcp-box">
  <p>
    {if $s.mcp_enabled && $active_keys > 0}
      <strong style="color:#2e7d32">{$mod->Lang('status_on', $active_keys)}</strong>
    {elseif $s.mcp_enabled}
      <strong class="mcp-warn">{$mod->Lang('status_no_key')}</strong>
    {else}
      <strong class="mcp-muted">{$mod->Lang('status_off')}</strong>
    {/if}
  </p>
  <p>{$mod->Lang('endpoint_urls')}</p>
  {foreach $endpoint_urls as $u}<code class="mcp-code">{$u|escape}</code>{/foreach}
  {if $s.mcp_readonly}<p class="mcp-warn">{$mod->Lang('forced_readonly')}</p>{/if}
</div>

<h3>{$mod->Lang('section_keys')}</h3>
<p class="mcp-muted">{$mod->Lang('keys_intro')}</p>
{if $keys}
<table class="pagetable mcp-keys" style="width:100%">
  <thead><tr>
    <th>{$mod->Lang('key_label')}</th><th>{$mod->Lang('key_id')}</th><th>{$mod->Lang('key_user')}</th>
    <th>{$mod->Lang('key_rights')}</th><th>{$mod->Lang('key_created')}</th><th>{$mod->Lang('key_last_used')}</th><th></th>
  </tr></thead>
  <tbody>
  {foreach $keys as $k}
    <tr class="{cycle values='row1,row2'}{if !$k.active} mcp-off{/if}">
      <td><strong>{$k.label|escape}</strong>{if !$k.active}<br><span class="mcp-warn">{$mod->Lang('revoked')}</span>{/if}</td>
      <td><code>{$k.prefix}</code></td>
      <td>{$k.user|escape}{if $k.superuser} <span class="mcp-badge mcp-rw">{$mod->Lang('superuser')}</span>{/if}{if !$k.user_ok}<br><span class="mcp-warn">{$mod->Lang('user_inactive')}</span>{/if}</td>
      <td>
        {if $k.readonly}<span class="mcp-badge mcp-ro">{$mod->Lang('readonly')}</span>{else}<span class="mcp-badge mcp-rw">{$mod->Lang('readwrite')}</span>{/if}
        {if $k.udt && !$k.readonly} <span class="mcp-badge mcp-udt">UDT</span>{/if}
      </td>
      <td>{$k.created}</td>
      <td>{$k.last_used}</td>
      <td style="white-space:nowrap">
        <a href="{$k.url_toggle_readonly}">{if $k.readonly}{$mod->Lang('make_rw')}{else}{$mod->Lang('make_ro')}{/if}</a> |
        <a href="{$k.url_toggle_udt}">{if $k.udt}{$mod->Lang('udt_off')}{else}{$mod->Lang('udt_on')}{/if}</a> |
        <a href="{$k.url_toggle_active}">{if $k.active}{$mod->Lang('revoke')}{else}{$mod->Lang('reactivate')}{/if}</a> |
        <a href="{$k.url_delete}" onclick="return confirm('{$mod->Lang('confirm_delete')|escape:'javascript'}');">{$mod->Lang('delete')}</a>
      </td>
    </tr>
  {/foreach}
  </tbody>
</table>
{else}
<p><em>{$mod->Lang('no_keys')}</em></p>
{/if}

<h4 style="margin-top:16px">{$mod->Lang('create_key')}</h4>
{$key_form_start}
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_key_label">{$mod->Lang('key_label')} :</label></p>
  <p class="pageinput"><input type="text" id="mcp_key_label" name="{$actionid}key_label" size="40" maxlength="100" placeholder="{$mod->Lang('key_label_placeholder')}" required></p>
</div>
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_key_user">{$mod->Lang('key_user')} :</label></p>
  <p class="pageinput">
    <select id="mcp_key_user" name="{$actionid}key_user_id">
      {foreach $users as $uid => $uname}<option value="{$uid}"{if $uid == $current_uid} selected{/if}>{$uname|escape}</option>{/foreach}
    </select>
    <br><span class="mcp-muted">{$mod->Lang('key_user_help')}</span>
  </p>
</div>
<div class="pageoverflow">
  <p class="pagetext">{$mod->Lang('key_rights')} :</p>
  <p class="pageinput">
    <label><input type="checkbox" name="{$actionid}key_readonly" value="1" checked> {$mod->Lang('readonly_help')}</label><br>
    <label><input type="checkbox" name="{$actionid}key_udt" value="1"> {$mod->Lang('udt_help')}</label>
  </p>
</div>
<div class="pageoverflow">
  <p class="pageinput"><input type="submit" name="{$actionid}create_key" value="{$mod->Lang('create_key')}"></p>
</div>
{$form_end}

<h3>{$mod->Lang('section_settings')}</h3>
{$settings_form_start}
<div class="pageoverflow">
  <p class="pagetext">{$mod->Lang('enabled')} :</p>
  <p class="pageinput">
    <input type="hidden" name="{$actionid}enabled" value="0">
    <label><input type="checkbox" name="{$actionid}enabled" value="1"{if $s.mcp_enabled} checked{/if}{if isset($locked.enabled)} disabled{/if}> {$mod->Lang('enabled_help')}</label>
    {if isset($locked.enabled)}<br><span class="mcp-muted">{$mod->Lang('locked_in_config')}</span>{/if}
  </p>
</div>
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_endpoint">{$mod->Lang('endpoint')} :</label></p>
  <p class="pageinput">
    <input type="text" id="mcp_endpoint" name="{$actionid}endpoint" value="{$s.mcp_endpoint|escape}" size="20"{if isset($locked.endpoint)} disabled{/if}>
    <br><span class="mcp-muted">{$mod->Lang('endpoint_help')}</span>
  </p>
</div>
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_render">{$mod->Lang('render_base_url')} :</label></p>
  <p class="pageinput">
    <input type="text" id="mcp_render" name="{$actionid}render_base_url" value="{$s.mcp_render_base_url|escape}" size="40" placeholder="http://127.0.0.1"{if isset($locked.render_base_url)} disabled{/if}>
    <br><span class="mcp-muted">{$mod->Lang('render_base_url_help')}</span>
  </p>
</div>
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_origins">{$mod->Lang('allowed_origins')} :</label></p>
  <p class="pageinput">
    <input type="text" id="mcp_origins" name="{$actionid}allowed_origins" value="{$s.mcp_allowed_origins|escape}" size="40"{if isset($locked.allowed_origins)} disabled{/if}>
    <br><span class="mcp-muted">{$mod->Lang('allowed_origins_help')}</span>
  </p>
</div>
<div class="pageoverflow">
  <p class="pagetext"><label for="mcp_upload">{$mod->Lang('max_upload_mb')} :</label></p>
  <p class="pageinput"><input type="number" id="mcp_upload" name="{$actionid}max_upload_mb" value="{$max_upload_mb}" min="1" max="64"{if isset($locked.max_upload_bytes)} disabled{/if}> MB</p>
</div>
<div class="pageoverflow">
  <p class="pageinput"><input type="submit" name="{$actionid}save_settings" value="{$mod->Lang('save')}"></p>
</div>
{$form_end}
