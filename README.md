# MCPServer — module serveur MCP pour CMS Made Simple 2.2.x

Module CMSMS qui transforme le site en serveur MCP : un agent IA (Claude.ai, Claude Desktop/Code, MCP Inspector…) peut piloter le site à distance, comme avec les serveurs MCP WordPress. Il couvre les pages, blocs de contenu, templates, feuilles de style, designs, News, instances LISE, galeries photos (Gallery), UDT, fichiers `uploads/`, ainsi que la recherche, le cache et le rendu des pages.

- Transport **MCP Streamable HTTP**, réponses JSON (pas de SSE), sans état. Versions de protocole 2024-11-05 → 2025-11-25.
- **PHP 7.4 à 8.3**, testé sur CMSMS 2.2.22 (API vérifiée dans le code 2.2.24). Aucune dépendance.
- **Une clé API par agent**, créée dans l'admin, liée à un utilisateur CMSMS, en lecture seule ou en lecture/écriture, révocable à tout moment.
- Toutes les écritures passent par l'API CMSMS (validation, hooks, routes, index de recherche, journal d'admin), avec les **permissions** de l'utilisateur lié à la clé.

## Installation

- **Paquet** : *Extensions > Gestionnaire de modules > Importer un module* avec [dist/MCPServer-1.2.0.xml](dist/MCPServer-1.2.0.xml), puis *Installer*.
- **Ou à la main** : copier [module/MCPServer](module/MCPServer) dans `modules/` du site, puis l'installer depuis le Gestionnaire de modules.

À l'installation, le serveur est **désactivé** et il n'existe **aucune clé**. Ensuite, dans *Extensions > Serveur MCP* :

1. **Créer une clé API** par agent :
   - un libellé (ex. « Claude - équipe contenu ») ;
   - l'utilisateur CMSMS au nom duquel l'agent agit : de préférence un utilisateur dédié, dont le groupe n'a que les permissions nécessaires ;
   - le mode lecture seule (coché par défaut) ;
   - l'autorisation d'écrire des UDT (code PHP), réservée aux agents de confiance.
2. **Copier la clé** : elle n'est affichée qu'**une seule fois**, avec l'URL du connecteur Claude.ai et la commande Claude Code prêtes à copier. Seule son empreinte SHA-256 est stockée : une clé perdue se révoque et se remplace.
3. **Activer le serveur** dans les réglages.

Dans la liste des clés, l'admin voit, pour chaque agent, l'utilisateur, les droits, la date et l'IP de dernière utilisation. Il peut aussi le passer en lecture seule ou en écriture, autoriser ou interdire les UDT, **révoquer** la clé (401 immédiat) ou la supprimer. La page est réservée à la permission « Manage MCP Server ».

### Endpoint

Le module intercepte la requête MCP pendant le chargement des modules frontend, avant tout chargement de page ou de session. Il n'y a donc **aucun fichier PHP à ouvrir** dans `modules/`, ce qui reste compatible avec le `.htaccess` recommandé, qui bloque `/modules/*.php`. L'endpoint répond sur :

- `https://votre-site/mcp` (URL propres `mod_rewrite`) ;
- `https://votre-site/index.php/mcp` ;
- `https://votre-site/index.php?page=mcp` (fonctionne partout).

Le chemin (`mcp`) se change dans les réglages et ne doit pas être l'alias d'une page.

### Réglages

Dans l'admin : activation, chemin de l'endpoint, URL de base du rendu (si l'URL publique n'est pas joignable depuis le serveur lui-même, par exemple sous Docker ou derrière un proxy), origines CORS, taille max des fichiers.

`config.php` peut **verrouiller** ces réglages, et l'admin les affiche alors comme verrouillés :

```php
$config['mcp_enabled'] = false;      // coupe le serveur, quoi que dise l'admin
$config['mcp_readonly'] = true;      // impose la lecture seule à toutes les clés
$config['mcp_endpoint'] = 'mcp';
$config['mcp_render_base_url'] = 'http://127.0.0.1';
$config['mcp_allowed_origins'] = 'https://exemple.com';
$config['mcp_max_upload_bytes'] = 10485760;
```

### Serveur web

- **Apache + PHP-FPM/CGI** supprime souvent l'en-tête `Authorization`. Ajouter au `.htaccess` :
  ```apache
  CGIPassAuth On
  # ou : RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```
  (sinon utiliser `?key=`).
- HTTPS obligatoire en production (la clé circule dans chaque requête).

## Connexion d'un agent

| Client | Configuration |
|---|---|
| En-tête | `Authorization: Bearer cmsmcp_…` |
| Claude.ai (connecteur personnalisé) | `https://votre-site/mcp?key=cmsmcp_…` |
| Claude Code | `claude mcp add --transport http cmsms https://votre-site/mcp --header "Authorization: Bearer cmsmcp_…"` |
| MCP Inspector | `npx @modelcontextprotocol/inspector` → Transport « Streamable HTTP », URL, en-tête Authorization |

Sans clé valide et active, la réponse est **401**. Avec `?key=`, la clé peut finir dans les journaux d'accès du serveur : préférez l'en-tête quand le client le permet. `site_info` indique à l'agent quelle clé il utilise et s'il est en lecture seule.

## Outils

| Domaine | Outils |
|---|---|
| Site | `site_info` |
| Pages | `list_pages`, `get_page`, `get_template_blocks`, `create_page`, `update_page`, `delete_page` |
| Templates | `list_template_types`, `list_templates`, `get_template`, `create_template`, `update_template`, `delete_template` |
| Feuilles de style | `list_stylesheets`, `get_stylesheet`, `create_stylesheet`, `update_stylesheet`, `delete_stylesheet` |
| Designs | `list_designs`, `get_design`, `create_design`, `update_design`, `delete_design` |
| News | `list_news`, `get_news`, `create_news`, `update_news`, `delete_news`, `list_news_categories`, `create_news_category`, `update_news_category`, `delete_news_category` |
| UDT | `list_udts`, `get_udt`, `save_udt`*, `delete_udt`* |
| Fichiers (`uploads/`) | `list_files`, `read_file`, `write_file`, `create_folder`, `move_file`, `delete_file` |
| Divers | `search`, `clear_cache`, `render_page` |
| Gallery** | `gallery_list_galleries`, `gallery_get_gallery`, `gallery_create_gallery`, `gallery_update_gallery`, `gallery_delete_gallery`, `gallery_upload_image`, `gallery_update_image`, `gallery_delete_image` |
| LISE** | `lise_list_instances`, `lise_create_instance`, `lise_delete_instance`, `lise_list_field_types`, `lise_list_fields`, `lise_save_field`, `lise_delete_field`, `lise_list_items`, `lise_get_item`, `lise_create_item`, `lise_update_item`, `lise_delete_item`, `lise_list_categories`, `lise_save_category`, `lise_delete_category` |

\* seulement pour une clé qui autorise les UDT. \*\* seulement si le module (Gallery, LISE) est installé. Une clé en lecture seule voit 20 outils (28 avec Gallery et LISE).

### LISE

[LISE](https://dev.cmsmadesimple.org/projects/lise) (List It Special Edition) génère des « instances » : chacune est un module `LISE<Nom>` avec ses items, ses catégories et sa structure de champs. Les templates des instances (`LISEProducts::summary`, `::detail`…) se gèrent avec les outils templates.

- **Instances** : `lise_create_instance` reproduit *LISE > Instances > Créer* (`LISEDuplicator` + `InstallModule`, préférences, mode). `lise_delete_instance` désinstalle l'instance et supprime `modules/LISE<Nom>`, avec confirmation du nom exact. Permission : `Modify Modules`.
- **Champs** : `lise_save_field` passe par `LISEFielddefOperations` (type, alias, obligatoire, options propres au type ; `lise_list_field_types` donne les clés d'options de chaque type). Les types uniques (Categories) sont respectés.
- **Items** : `InitiateItem` / `LoadItemByIdentifier` / `SaveItem` / `DeleteItemById`, avec les gestionnaires d'événements des types de champs (validation, obligatoire, longueur max), l'index Search et les événements `PreItemSave`/`PostItemSave`. Les valeurs sont données par alias de champ ; les champs multi-valeurs prennent une liste ; un champ Categories accepte des ids, alias ou noms. Les URL d'items sont vérifiées contre les routes existantes.
- **Permissions** propres à chaque instance : `<alias>_modify_item`, `_remove_item`, `_modify_category`, `_modify_option`.
- Les champs FileUpload ne reçoivent pas de fichier via MCP : déposer le fichier avec `write_file`, puis mettre son nom dans le champ (ou utiliser un champ de type sélection de fichier).

### Gallery

Le module [Gallery](https://dev.cmsmadesimple.org/projects/gallery) range galeries et photos dans une seule table (`module_gallery`) : une galerie est un dossier sous `uploads/images/Gallery/`, la racine porte l'id 1. Comme Gallery n'a pas d'API d'écriture, les outils reproduisent ses actions d'admin avec `Gallery_utils` :

- **Galeries** :
  - `gallery_create_gallery` crée le dossier, puis l'enregistre comme `do_editgallery` (`AddFileToDB`, template, lien parent, éditeurs, champs personnalisés, index Search).
  - `gallery_update_gallery` modifie le titre, le texte, la date, le template, l'état actif, l'image de couverture, l'ordre des photos et les champs.
  - `gallery_delete_gallery` supprime le dossier, les photos et les sous-galeries (`DeleteGalleryDB`) ; il faut confirmer le chemin exact et la racine est protégée.
- **Envoi de photos** (`gallery_upload_image`) :
  - le fichier arrive en base64, ou est copié depuis `uploads/` (`source_path`, par exemple après `write_file`) ;
  - il est vérifié comme une vraie image, avec les seules extensions autorisées dans les réglages Gallery ;
  - une photo plus grande que la taille max de Gallery (800×640 par défaut) est **redimensionnée**, et le nom est nettoyé (minuscules, caractères sûrs) ;
  - ensuite, comme `do-upload`, la miniature d'admin est créée, puis la photo est enregistrée par la synchronisation de Gallery (`UpdateGalleryDB`), avec titre, commentaire et champs ;
  - `overwrite` remplace un fichier existant et purge ses miniatures.
- **Photos** : `gallery_update_image` modifie titre, commentaire, date, état actif et champs ; il peut aussi déplacer la photo (fichier compris) ou la pivoter. `gallery_delete_image` supprime le fichier, les miniatures et l'entrée.
- **Lecture** : `gallery_get_gallery` synchronise d'abord le dossier, comme l'admin, pour voir les fichiers ajoutés par FTP.
- **Affichage** sur une page : `{Gallery dir="chemin"}`, éventuellement avec `template="Lightbox"`.
- **Permissions** : `Use Gallery`. Si l'option « use permissions » de Gallery est active, s'y ajoutent les éditeurs par galerie (`CheckEditor`), `Gallery - Add subgalleries` pour créer, et `Gallery - Edit all galleries` + `Gallery - Delete subgalleries` pour supprimer.

Permissions CMSMS vérifiées : `Add Pages`, `Manage All Content` / `Modify Any Page` / auteur de la page, `Remove Pages`, `Modify Templates` (ou propriétaire du template), `Manage Stylesheets`, `Manage Designs`, `Modify News`, `Approve News` (publication), `Delete News`, `Modify Site Preferences` (catégories News), `Modify User-defined Tags`, `Modify Files`.

## Fonctionnement

- **Interception** : le module se charge sur chaque requête frontend (`LazyLoadFrontend() = false`). Dans `InitializeFrontend()`, si l'URL est celle de l'endpoint, il termine le chargement des autres modules, traite la requête MCP et s'arrête (`exit`) avant le routage des pages et l'ouverture de session. Pour les autres URL, il ne fait rien.
- **Authentification** : la clé (`Bearer` ou `?key=`) est hachée en SHA-256 et cherchée parmi les clés actives (table `module_mcpserver_keys`). Elle fournit l'utilisateur, le mode lecture seule et l'autorisation UDT de la requête ; la date et l'IP de dernière utilisation sont enregistrées.
- **Utilisateur** : `get_userid()` redirige vers `login.php` sans session admin. Le serveur remplit `\CMSMS\LoginOperations::_data` en mémoire (`Closure::call`) pour la durée de la requête, sans cookie ni session.
- **Blocs de contenu** : en mode frontend, Smarty enregistre les versions « rendu » de `{content}`, `{content_image}` et `{content_module}`. `page_template_parser` ne parvient alors pas à enregistrer ses compilateurs de collecte : aucun bloc n'est détecté et `Content::ValidateData()` échoue. Le serveur désenregistre ces plugins (il ne rend jamais de page en interne : `render_page` passe par HTTP).
- **Pages** : même séquence que l'éditeur CMSContentManager : `CreateNewContent` + `CmsContentManagerUtils::get_pagedefaults()`, `FillParams`, `ValidateData`, `Save`. Les blocs inconnus du template sont refusés, avec la liste des blocs disponibles.
- **News** : reproduction de `action.addarticle` / `action.editarticle` (tables `module_news*`, routes statiques via `news_admin_ops`, index Search, hooks `News::*`). Suppression via `news_admin_ops::delete_article`.
- **Verrous** : lecture directe de la table `locks` (sans `CmsLockOperations::is_locked()`, qui fait `sleep(1)`). Un élément ouvert dans l'admin est refusé.
- **Fichiers** : confinés à `uploads_path` (pas de `..`, pas de fichiers cachés, vérification `realpath`, protection contre les liens symboliques). Types exécutables refusés (`.php*`, `.phtml`, `.phar`, `.htaccess`, `.user.ini`…).
- **Erreurs** : les erreurs métier sont renvoyées en résultat d'outil `isError: true`. Les `die()`/`exit`/fatales de CMSMS deviennent une erreur JSON-RPC.
- **Fuseaux horaires** : News enregistre les dates dans le fuseau PHP mais filtre l'affichage avec le `NOW()` SQL. Si MySQL/MariaDB n'est pas dans le même fuseau que PHP, un article reste invisible pendant le décalage. Sans `start_date`, le serveur prend comme début la date de l'article. Aligner le fuseau de la base (ici `TZ` dans le docker-compose).
- Bug CMSMS contourné : `CmsLayoutTemplateType::create_new_template($name)` passe l'objet au lieu du nom à `set_name()`.

## Tests

Environnement local (Docker) :

```bash
docker compose -f docker/docker-compose.yml up -d --build   # PHP_VERSION=7.4|8.1|8.3
```

Copier le contenu de l'installeur officiel (`cmsms-2.2.22-install.expanded.zip`, dossier `installer/`) dans le volume du site, installer via http://localhost:8080/installer/ (DB : hôte `db`, base/utilisateur/mot de passe `cmsms`), supprimer `installer/`, copier `module/MCPServer` dans `modules/` et l'installer. Dans les réglages, mettre l'URL de rendu à `http://localhost` (dans le conteneur, Apache écoute sur le port 80), créer une clé en lecture/écriture et une en lecture seule, puis activer le serveur.

Test de bout en bout (crée puis supprime des éléments `mcp-smoke-*`, à lancer sur un site de test) :

```bash
MCP_KEY=cmsmcp_... MCP_KEY_RO=cmsmcp_... SMOKE_IMAGES=images.json MCP_URL=http://localhost:8080/mcp node tests/smoke.mjs
```

`SMOKE_IMAGES` (optionnel) est un fichier JSON `{"jpg": "<base64>", "png": "<base64>"}`. Le JPEG doit dépasser 800×640 pour tester le redimensionnement.

Résultat : 130/130 sur PHP 7.4.33, 8.1.34 et 8.3.35 avec CMSMS 2.2.22, LISE 1.5.6 et Gallery 2.5.1. Les tests couvrent :
- le transport : 401 (sans clé ou clé invalide), `?key=`, 405, 202, batch, erreurs de parsing ;
- la clé en lecture seule : aucun outil d'écriture listé, écriture refusée ;
- le CRUD et les garde-fous de chaque domaine, le rendu, la recherche et le cache ;
- Gallery : galeries et sous-galerie, envoi d'un JPEG 1200×900 (redimensionné à 800×600) et d'un PNG, envoi depuis `uploads/`, refus des doublons, des faux fichiers et des extensions interdites, couverture, ordre, déplacement, rotation, affichage via `{Gallery}`, puis suppression ;
- LISE : création d'une instance, de champs, de catégories et d'items, affichage sur une page via `{LISE…}`, puis suppression.

Ont été vérifiés à la main : la révocation (401 immédiat, puis 200 à la réactivation), le verrou admin, la page d'administration et MCP Inspector en CLI.
