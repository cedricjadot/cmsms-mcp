<?php
$lang['friendlyname'] = 'Serveur MCP';
$lang['admindescription'] = 'Permet à des agents IA (Claude, clients MCP) de gérer ce site via le Model Context Protocol, avec une clé API par agent.';
$lang['postinstall'] = 'Serveur MCP installé. Allez dans Extensions > Serveur MCP pour créer une clé API et activer le serveur.';
$lang['really_uninstall'] = 'La désinstallation supprime toutes les clés API MCP : les agents connectés perdront l\'accès. Continuer ?';

$lang['section_status'] = 'État';
$lang['status_on'] = 'Activé — %d clé(s) API active(s)';
$lang['status_no_key'] = 'Activé, mais aucune clé API active : toutes les requêtes sont refusées.';
$lang['status_off'] = 'Désactivé : l\'endpoint répond 503 à toutes les requêtes.';
$lang['endpoint_urls'] = 'Endpoint MCP (Streamable HTTP) :';
$lang['forced_readonly'] = 'La lecture seule est imposée à toutes les clés par $config[\'mcp_readonly\'] dans config.php.';

$lang['section_keys'] = 'Clés API';
$lang['keys_intro'] = 'Donnez à chaque agent sa propre clé. Une clé agit en tant que l\'utilisateur CMSMS choisi et n\'a jamais plus de droits que lui ; elle peut en plus être limitée à la lecture seule. Révoquez une clé pour couper immédiatement l\'accès d\'un agent.';
$lang['key_label'] = 'Agent / libellé';
$lang['key_label_placeholder'] = 'ex. Claude - équipe contenu';
$lang['key_id'] = 'Clé';
$lang['key_user'] = 'Agit en tant que';
$lang['key_user_help'] = 'L\'agent a exactement les permissions de cet utilisateur (de préférence un utilisateur dédié aux permissions limitées).';
$lang['key_rights'] = 'Droits';
$lang['key_created'] = 'Créée le';
$lang['key_last_used'] = 'Dernière utilisation';
$lang['readonly'] = 'lecture seule';
$lang['readwrite'] = 'lecture/écriture';
$lang['readonly_help'] = 'Lecture seule : l\'agent peut consulter le site mais ne peut rien modifier';
$lang['udt_help'] = 'Autoriser l\'écriture des balises utilisateur (UDT, code PHP exécuté par le site) — uniquement pour des agents de confiance';
$lang['superuser'] = 'administrateur';
$lang['user_inactive'] = 'utilisateur inactif : clé inutilisable';
$lang['revoked'] = 'révoquée';
$lang['never'] = 'jamais';
$lang['make_rw'] = 'autoriser l\'écriture';
$lang['make_ro'] = 'passer en lecture seule';
$lang['udt_on'] = 'autoriser les UDT';
$lang['udt_off'] = 'interdire les UDT';
$lang['revoke'] = 'révoquer';
$lang['reactivate'] = 'réactiver';
$lang['delete'] = 'supprimer';
$lang['confirm_delete'] = 'Supprimer cette clé API ? L\'agent qui l\'utilise perdra l\'accès.';
$lang['no_keys'] = 'Aucune clé API : aucun agent ne peut se connecter.';
$lang['create_key'] = 'Créer une clé API';

$lang['new_key_title'] = 'Nouvelle clé API';
$lang['new_key_once'] = 'Copiez cette clé maintenant : elle n\'est affichée qu\'une seule fois et ne pourra pas être récupérée (seule une empreinte est stockée). En cas de perte, révoquez-la et créez-en une nouvelle.';
$lang['new_key_value'] = 'Clé API :';
$lang['use_claude_ai'] = 'URL du connecteur personnalisé Claude.ai (contient la clé) :';
$lang['use_header'] = 'Ou, pour les clients qui envoient des en-têtes :';
$lang['use_claude_code'] = 'Claude Code :';

$lang['section_settings'] = 'Réglages du serveur';
$lang['enabled'] = 'Activé';
$lang['enabled_help'] = 'Accepter les requêtes MCP (avec une clé API valide)';
$lang['locked_in_config'] = 'Défini dans config.php ($config[\'mcp_enabled\']).';
$lang['endpoint'] = 'Chemin de l\'endpoint';
$lang['endpoint_help'] = 'Chemin d\'URL de l\'endpoint (par défaut « mcp »). Ne doit pas être l\'alias d\'une page.';
$lang['render_base_url'] = 'URL de base pour le rendu';
$lang['render_base_url_help'] = 'Seulement si l\'URL publique du site n\'est pas joignable depuis le serveur lui-même (Docker, proxy) : utilisée par render_page.';
$lang['allowed_origins'] = 'Origines navigateur autorisées (CORS)';
$lang['allowed_origins_help'] = '* ou une liste d\'origines séparées par des virgules. Inutile pour les clients côté serveur comme Claude.ai.';
$lang['max_upload_mb'] = 'Taille max. des fichiers (write_file)';
$lang['save'] = 'Enregistrer';

$lang['msg_saved'] = 'Réglages enregistrés';
$lang['msg_key_created'] = 'Clé API créée';
$lang['msg_key_revoked'] = 'Clé API révoquée';
$lang['msg_key_deleted'] = 'Clé API supprimée';
$lang['err_endpoint'] = 'Chemin d\'endpoint invalide (lettres, chiffres, « - », « _ » et « / »)';
$lang['err_endpoint_page'] = 'Une page utilise déjà l\'alias « %s » : choisissez un autre chemin';
$lang['err_render_url'] = 'L\'URL de rendu doit commencer par http:// ou https://';
$lang['err_key_label'] = 'Donnez un libellé à la clé (quel agent l\'utilise)';
$lang['err_user'] = 'Choisissez un utilisateur CMSMS actif';

$lang['help'] = <<<'EOT'
<h3>À quoi sert ce module ?</h3>
<p>Il transforme le site en serveur <a href="https://modelcontextprotocol.io">Model Context Protocol</a> (transport Streamable HTTP, réponses JSON). Des agents IA comme Claude peuvent alors consulter et gérer les pages, blocs de contenu, templates, feuilles de style, designs, News, instances LISE, balises utilisateur (UDT) et fichiers de <code>uploads/</code>, faire des recherches, vider le cache et afficher le rendu des pages.</p>
<p>Chaque modification passe par l'API CMSMS (validation, événements, index de recherche, routes, journal d'administration) avec les permissions de l'utilisateur CMSMS associé à la clé API.</p>
<h3>Mise en place</h3>
<ol>
<li>Créez un utilisateur/groupe CMSMS dédié avec uniquement les permissions nécessaires à l'agent (recommandé).</li>
<li>Dans Extensions &gt; Serveur MCP, créez une clé API pour l'agent, liée à cet utilisateur, en lecture seule ou en lecture/écriture. Copiez la clé : elle n'est affichée qu'une fois.</li>
<li>Activez le serveur.</li>
<li>Connectez l'agent : URL de connecteur Claude.ai <code>https://votre-site/mcp?key=...</code>, ou en-tête <code>Authorization: Bearer ...</code>.</li>
</ol>
<h3>Sécurité</h3>
<ul>
<li>Les requêtes sans clé valide et active sont refusées (HTTP 401). Les clés sont stockées sous forme d'empreinte SHA-256.</li>
<li>Utilisez HTTPS. Avec <code>?key=</code>, la clé peut apparaître dans les journaux d'accès du serveur web : préférez l'en-tête Authorization quand le client le permet.</li>
<li>Apache + PHP-FPM/CGI peut supprimer l'en-tête Authorization : ajoutez <code>CGIPassAuth On</code> au .htaccess, ou utilisez <code>?key=</code>.</li>
<li>config.php peut verrouiller des réglages : <code>$config['mcp_enabled']</code>, <code>mcp_endpoint</code>, <code>mcp_readonly</code> (impose la lecture seule à toutes les clés), <code>mcp_render_base_url</code>, <code>mcp_allowed_origins</code>, <code>mcp_max_upload_bytes</code>.</li>
</ul>
EOT;
