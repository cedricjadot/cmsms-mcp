<?php
if (!defined('CMS_VERSION')) exit;
if (!$this->CheckPermission(MCPServer::MANAGE_PERM)) exit;

$locked = $this->GetConfigOverrides();
$userops = UserOperations::get_instance();
$new_key = null;
$new_key_row = null;
$errors = [];
$messages = [];

// ---------------------------------------------------------------- server settings
if (isset($params['save_settings'])) {
    $endpoint = trim((string) ($params['endpoint'] ?? 'mcp'), '/ ');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-\/]*$/', $endpoint)) {
        $errors[] = $this->Lang('err_endpoint');
    } elseif (ContentOperations::get_instance()->GetPageIDFromAlias($endpoint)) {
        $errors[] = $this->Lang('err_endpoint_page', $endpoint);
    }
    $render = trim((string) ($params['render_base_url'] ?? ''));
    if ($render !== '' && !preg_match('#^https?://[^/\s]+#i', $render)) $errors[] = $this->Lang('err_render_url');

    if (!$errors) {
        $this->SetPreference('enabled', empty($params['enabled']) ? 0 : 1);
        $this->SetPreference('endpoint', $endpoint);
        $this->SetPreference('render_base_url', $render);
        $this->SetPreference('allowed_origins', trim((string) ($params['allowed_origins'] ?? '*')) ?: '*');
        $this->SetPreference('max_upload_mb', max(1, min(64, (int) ($params['max_upload_mb'] ?? 10))));
        audit('', $this->GetName(), 'Settings saved');
        $messages[] = $this->Lang('msg_saved');
    }
}

// ---------------------------------------------------------------- new API key
if (isset($params['create_key'])) {
    $label = trim(strip_tags((string) ($params['key_label'] ?? '')));
    $user_id = (int) ($params['key_user_id'] ?? 0);
    $user = $user_id > 0 ? $userops->LoadUserByID($user_id) : null;
    if ($label === '') $errors[] = $this->Lang('err_key_label');
    if (!$user || !$user->active) $errors[] = $this->Lang('err_user');
    if (!$errors) {
        $new_key = $this->CreateKey($label, $user_id, !empty($params['key_readonly']), !empty($params['key_udt']));
        $new_key_row = ['label' => $label, 'username' => $user->username, 'readonly' => !empty($params['key_readonly'])];
        $messages[] = $this->Lang('msg_key_created');
    }
}

// ---------------------------------------------------------------- key actions
if (isset($params['key_op'], $params['key_id'])) {
    $kid = (int) $params['key_id'];
    $row = $this->GetKey($kid);
    if ($row) {
        switch ($params['key_op']) {
            case 'revoke':   $this->SetKeyFlag($kid, 'active', false); $messages[] = $this->Lang('msg_key_revoked'); break;
            case 'activate': $this->SetKeyFlag($kid, 'active', true); $messages[] = $this->Lang('msg_saved'); break;
            case 'readonly': $this->SetKeyFlag($kid, 'readonly', !$row['readonly']); $messages[] = $this->Lang('msg_saved'); break;
            case 'udt':      $this->SetKeyFlag($kid, 'allow_udt_write', !$row['allow_udt_write']); $messages[] = $this->Lang('msg_saved'); break;
            case 'delete':   $this->DeleteKey($kid); $messages[] = $this->Lang('msg_key_deleted'); break;
        }
    }
}

if ($errors) echo $this->ShowErrors($errors);
foreach ($messages as $m) echo $this->ShowMessage($m);

// ---------------------------------------------------------------- display
$settings = $this->GetSettings();
$users = [];
foreach ((array) $userops->LoadUsers() as $u) {
    if (!$u->active) continue;
    $users[$u->id] = $u->username . ($userops->IsSuperuser($u->id) ? ' (' . $this->Lang('superuser') . ')' : '');
}

$keys = [];
foreach ($this->ListKeys() as $k) {
    $link = function ($op) use ($id, $returnid, $k) {
        return $this->CreateLink($id, 'defaultadmin', $returnid, '', ['key_op' => $op, 'key_id' => $k['id']], '', true);
    };
    $keys[] = [
        'label' => $k['label'],
        'prefix' => $k['key_prefix'] . '…',
        'user' => $k['username'] ?: ('#' . $k['user_id']),
        'user_ok' => (bool) $k['user_active'],
        'superuser' => $userops->IsSuperuser((int) $k['user_id']),
        'readonly' => (bool) $k['readonly'],
        'udt' => (bool) $k['allow_udt_write'],
        'active' => (bool) $k['active'],
        'created' => $k['created'] ? date('Y-m-d H:i', (int) $k['created']) : '',
        'last_used' => $k['last_used'] ? date('Y-m-d H:i', (int) $k['last_used']) . ($k['last_ip'] ? ' (' . $k['last_ip'] . ')' : '') : $this->Lang('never'),
        'url_toggle_active' => $link($k['active'] ? 'revoke' : 'activate'),
        'url_toggle_readonly' => $link('readonly'),
        'url_toggle_udt' => $link('udt'),
        'url_delete' => $link('delete'),
    ];
}

$urls = $this->GetEndpointUrls();
$tpl = $smarty->CreateTemplate($this->GetTemplateResource('defaultadmin.tpl'), null, null, $smarty);
$tpl->assign('mod', $this);
$tpl->assign('actionid', $id);
$tpl->assign('settings_form_start', $this->CreateFormStart($id, 'defaultadmin', $returnid));
$tpl->assign('key_form_start', $this->CreateFormStart($id, 'defaultadmin', $returnid));
$tpl->assign('form_end', $this->CreateFormEnd());
$tpl->assign('s', $settings);
$tpl->assign('locked', $locked);
$tpl->assign('users', $users);
$tpl->assign('current_uid', (int) get_userid(false));
$tpl->assign('keys', $keys);
$tpl->assign('active_keys', count(array_filter($keys, function ($k) { return $k['active']; })));
$tpl->assign('endpoint_urls', $urls);
$tpl->assign('max_upload_mb', (int) ($settings['mcp_max_upload_bytes'] / 1048576));
$tpl->assign('new_key', $new_key);
$tpl->assign('new_key_row', $new_key_row);
$tpl->assign('claude_url', $new_key ? $urls[0] . (strpos($urls[0], '?') === false ? '?' : '&') . 'key=' . $new_key : null);
$tpl->display();
