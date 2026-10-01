<?php

namespace CmsmsMcp\Tools;

use CmsmsMcp\Context;
use CmsmsMcp\Registry;
use CmsmsMcp\ToolError;
use CmsmsMcp\Util;

/**
 * Gallery module (Jos) — photo galleries.
 *
 * Gallery keeps galleries (directories, filename ending with "/") and images in one
 * table, module_gallery; files live in uploads/images/Gallery/<path>/. There is no
 * write API: this reproduces the admin actions (do_editgallery, do-upload,
 * do_editimage, multiaction) with Gallery_utils (AddFileToDB, UpdateGalleryDB,
 * DeleteGalleryDB, CreateThumbnail, CheckEditor) and the module preferences
 * (allowed extensions, max image size, new galleries active, search, permissions).
 */
final class GalleryTools
{
    const ROOT_ID = 1;

    public static function register(Registry $r, Context $ctx)
    {
        if (!\cms_utils::get_module('Gallery')) return;

        $gallery = ['type' => ['integer', 'string'], 'description' => 'Gallery id or path (e.g. "holidays/2025"; "" or 1 = root gallery)'];
        $fields = ['type' => 'object', 'description' => 'Custom field values {"field name or id": value} (see gallery_list_galleries custom_fields)'];

        $r->add([
            'name' => 'gallery_list_galleries',
            'title' => 'List photo galleries',
            'description' => 'All Gallery galleries as a tree (id, path, title, active, image and sub-gallery counts, cover, template), plus the custom field definitions, display templates and upload settings. Display on a page with {Gallery dir="path"}.',
            'handler' => [__CLASS__, 'listGalleries'],
        ]);
        $r->add([
            'name' => 'gallery_get_gallery',
            'title' => 'Get photo gallery',
            'description' => 'A gallery with its images (id, file, title, comment, active, order, URLs, custom fields) and sub-galleries. Files added by FTP are synchronised first, like the admin does.',
            'properties' => ['gallery' => $gallery],
            'required' => ['gallery'],
            'handler' => [__CLASS__, 'getGallery'],
        ]);
        $r->add([
            'name' => 'gallery_create_gallery',
            'title' => 'Create photo gallery',
            'description' => 'Create a gallery (a folder under uploads/images/Gallery) inside a parent gallery. Then add photos with gallery_upload_image.',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Folder name (converted to a URL-safe name), e.g. "summer-2025"'],
                'parent' => $gallery + ['description' => 'Parent gallery (default: root)'],
                'title' => ['type' => 'string'],
                'comment' => ['type' => 'string', 'description' => 'Description (HTML allowed)'],
                'date' => ['type' => 'string', 'description' => 'Gallery date (YYYY-MM-DD, default now)'],
                'template' => ['type' => ['integer', 'string'], 'description' => 'Display template name or id (default: the module default)'],
                'active' => ['type' => 'boolean', 'description' => 'Default: Gallery setting "new galleries active"'],
                'hide_parent_link' => ['type' => 'boolean'],
                'fields' => $fields,
            ],
            'required' => ['name'],
            'write' => true,
            'handler' => [__CLASS__, 'createGallery'],
        ]);
        $r->add([
            'name' => 'gallery_update_gallery',
            'title' => 'Update photo gallery',
            'description' => 'Modify a gallery: title, description, date, template, active, cover image, image order, custom fields. Only given fields change.',
            'properties' => [
                'gallery' => $gallery,
                'title' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
                'date' => ['type' => 'string'],
                'template' => ['type' => ['integer', 'string'], 'description' => 'Template name or id; 0 or "" = module default'],
                'active' => ['type' => 'boolean'],
                'hide_parent_link' => ['type' => 'boolean'],
                'cover_image' => ['type' => 'integer', 'description' => 'Image id used as the gallery thumbnail (0 = automatic)'],
                'image_order' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Image ids in display order (unlisted images go after)'],
                'fields' => $fields,
            ],
            'required' => ['gallery'],
            'write' => true,
            'idempotent' => true,
            'handler' => [__CLASS__, 'updateGallery'],
        ]);
        $r->add([
            'name' => 'gallery_delete_gallery',
            'title' => 'Delete photo gallery',
            'description' => 'Delete a gallery WITH its folder, all its photos and all its sub-galleries. Pass confirm = the gallery path.',
            'properties' => ['gallery' => $gallery, 'confirm' => ['type' => 'string', 'description' => 'Must equal the gallery path, e.g. "holidays/2025"']],
            'required' => ['gallery', 'confirm'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteGallery'],
        ]);

        $r->add([
            'name' => 'gallery_upload_image',
            'title' => 'Upload photo to gallery',
            'description' => 'Add a photo to a gallery: file content in base64, or a file already in uploads/ (source_path, e.g. after write_file). Allowed types come from the Gallery settings; images larger than the Gallery max size are resized; the admin thumbnail is created.',
            'properties' => [
                'gallery' => $gallery,
                'filename' => ['type' => 'string', 'description' => 'File name, e.g. "beach.jpg" (default: name of source_path)'],
                'content_base64' => ['type' => 'string', 'description' => 'Image file content, base64'],
                'source_path' => ['type' => 'string', 'description' => 'Alternative: path of an image inside uploads/ to copy (e.g. "images/photo.jpg")'],
                'title' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
                'active' => ['type' => 'boolean', 'description' => 'Default true'],
                'overwrite' => ['type' => 'boolean', 'description' => 'Replace an existing file with the same name (default false)'],
                'fields' => $fields,
            ],
            'required' => ['gallery'],
            'write' => true,
            'handler' => [__CLASS__, 'uploadImage'],
        ]);
        $r->add([
            'name' => 'gallery_update_image',
            'title' => 'Update gallery photo',
            'description' => 'Modify a photo: title, comment, date, active, custom fields; move it to another gallery; rotate it.',
            'properties' => [
                'image' => ['type' => 'integer', 'description' => 'Image id'],
                'title' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
                'date' => ['type' => 'string'],
                'active' => ['type' => 'boolean'],
                'move_to' => $gallery + ['description' => 'Move the photo to this gallery'],
                'rotate' => ['type' => 'string', 'enum' => ['clockwise', 'anticlockwise']],
                'fields' => $fields,
            ],
            'required' => ['image'],
            'write' => true,
            'handler' => [__CLASS__, 'updateImage'],
        ]);
        $r->add([
            'name' => 'gallery_delete_image',
            'title' => 'Delete gallery photo',
            'description' => 'Delete a photo (file, thumbnails and database entry).',
            'properties' => ['image' => ['type' => 'integer', 'description' => 'Image id']],
            'required' => ['image'],
            'write' => true,
            'destructive' => true,
            'handler' => [__CLASS__, 'deleteImage'],
        ]);
    }

    // ================================================================ read

    public static function listGalleries(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        \Gallery_utils::UpdateGalleryDB('', self::ROOT_ID); // like the admin galleries tab
        $p = CMS_DB_PREFIX;
        $counts = [];
        foreach ((array) $ctx->db->GetArray("SELECT galleryid, SUM(CASE WHEN filename LIKE '%/' THEN 0 ELSE 1 END) AS images, SUM(CASE WHEN filename LIKE '%/' THEN 1 ELSE 0 END) AS subs FROM {$p}module_gallery WHERE fileid <> 1 GROUP BY galleryid") as $r) {
            $counts[(int) $r['galleryid']] = ['images' => (int) $r['images'], 'subs' => (int) $r['subs']];
        }
        $templates = self::templates($ctx);
        $out = [];
        foreach (\Gallery_utils::GetGalleries() as $row) {
            $g = self::describeGallery($ctx, $row, $templates);
            $g['images'] = $counts[(int) $row['fileid']]['images'] ?? 0;
            $g['sub_galleries'] = $counts[(int) $row['fileid']]['subs'] ?? 0;
            $out[] = $g;
        }
        return [
            'galleries' => $out,
            'custom_fields' => self::fieldDefs($ctx),
            'templates' => array_values($templates),
            'default_template' => $mod->GetPreference('current_template', ''),
            'upload' => [
                'allowed_extensions' => self::allowedExtensions($mod),
                'max_image_size' => ['width' => (int) $mod->GetPreference('maximagewidth', 0), 'height' => (int) $mod->GetPreference('maximageheight', 0)],
                'max_bytes' => (int) ($ctx->siteConfig['mcp_max_upload_bytes'] ?? 10485760),
            ],
            'usage' => '{Gallery} shows the root gallery; {Gallery dir="path"} a gallery; add template="Name" to choose the display template.',
        ];
    }

    public static function getGallery(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $row = self::galleryRow($ctx, $args['gallery']);
        $path = self::galleryPath($row);
        \Gallery_utils::UpdateGalleryDB($path, (int) $row['fileid']);
        $row = self::galleryRow($ctx, (int) $row['fileid']);

        $out = self::describeGallery($ctx, $row, self::templates($ctx));
        $out['fields'] = (object) self::fieldValues($ctx, (int) $row['fileid'], true);
        $out['images'] = [];
        $out['sub_galleries'] = [];
        $rows = (array) $ctx->db->GetArray(
            'SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE galleryid = ? AND fileid <> 1 ORDER BY fileorder, filename',
            [(int) $row['fileid']]
        );
        foreach ($rows as $item) {
            if (substr($item['filename'], -1) === '/') {
                $out['sub_galleries'][] = ['id' => (int) $item['fileid'], 'path' => rtrim($item['filepath'] . $item['filename'], '/'), 'title' => $item['title'], 'active' => (bool) $item['active']];
            } else {
                $out['images'][] = self::describeImage($ctx, $item);
            }
        }
        return $out;
    }

    // ================================================================ galleries

    public static function createGallery(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $parent = isset($args['parent']) && $args['parent'] !== '' ? self::galleryRow($ctx, $args['parent']) : self::galleryRow($ctx, self::ROOT_ID);
        self::assertCanEdit($ctx, $mod, $parent);
        if ($mod->GetPreference('use_permissions') && !$ctx->can('Gallery - Add subgalleries')) {
            throw new ToolError("Permission denied: creating galleries needs 'Gallery - Add subgalleries'");
        }
        $dir = munge_string_to_url(trim(Util::requireStr($args, 'name')));
        if ($dir === '' || $dir === '.' || $dir === '..') throw new ToolError('Invalid gallery name');
        $parentPath = self::galleryPath($parent);
        $abs = \Gallery_utils::DefaultGalleryPath() . $parentPath . $dir;
        if (file_exists($abs)) throw new ToolError("A gallery or file named '$dir' already exists in this gallery");
        if (!@mkdir($abs)) throw new ToolError("Could not create the folder for gallery '$parentPath$dir' (permissions?)");

        $templateId = self::resolveTemplate($ctx, $args['template'] ?? null);
        $date = self::date($args, 'date') ?? date('Y-m-d H:i:s');
        $id = \Gallery_utils::AddFileToDB(
            $dir . '/', $parentPath, $date, (int) $parent['fileid'],
            (string) Util::str($args, 'title', ''), (string) Util::str($args, 'comment', ''),
            $templateId, (bool) Util::bool($args, 'hide_parent_link', false), (string) $ctx->uid
        );
        if (!$id) throw new ToolError('Could not register the gallery: ' . $ctx->db->ErrorMsg());
        if (($active = Util::bool($args, 'active')) !== null) {
            $ctx->db->Execute('UPDATE ' . CMS_DB_PREFIX . 'module_gallery SET active = ? WHERE fileid = ?', [$active ? 1 : 0, $id]);
        }
        self::saveFields($ctx, (int) $id, true, Util::arr($args, 'fields', []), true);
        self::indexGallery($ctx, $mod, (int) $id);
        audit($id, 'Gallery: ' . $parentPath . $dir, 'Gallery added (MCP)');
        return ['created' => true, 'gallery' => self::describeGallery($ctx, self::galleryRow($ctx, (int) $id), self::templates($ctx)), 'usage' => '{Gallery dir="' . $parentPath . $dir . '"}'];
    }

    public static function updateGallery(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $row = self::galleryRow($ctx, $args['gallery']);
        $gid = (int) $row['fileid'];
        self::assertCanEdit($ctx, $mod, $row);
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;

        $set = [];
        $params = [];
        foreach (['title' => 'title', 'comment' => 'comment'] as $arg => $col) {
            if (array_key_exists($arg, $args)) {
                $set[] = "$col = ?";
                $params[] = (string) Util::str($args, $arg, '');
            }
        }
        if (($d = self::date($args, 'date')) !== null) {
            $set[] = 'filedate = ?';
            $params[] = $d;
        }
        if (($active = Util::bool($args, 'active')) !== null) {
            if ($gid === self::ROOT_ID && !$active) throw new ToolError('The root gallery cannot be deactivated');
            $set[] = 'active = ?';
            $params[] = $active ? 1 : 0;
        }
        if (array_key_exists('cover_image', $args)) {
            $cover = (int) Util::int($args, 'cover_image', 0);
            if ($cover > 0 && !$db->GetOne("SELECT 1 FROM {$p}module_gallery WHERE fileid = ? AND galleryid = ? AND filename NOT LIKE '%/'", [$cover, $gid])) {
                throw new ToolError("Image $cover is not a photo of this gallery");
            }
            $set[] = 'defaultfile = ?';
            $params[] = $cover;
        }
        if ($set) {
            $params[] = $gid;
            $db->Execute("UPDATE {$p}module_gallery SET " . implode(', ', $set) . ' WHERE fileid = ?', $params);
        }

        if (array_key_exists('template', $args) || array_key_exists('hide_parent_link', $args)) {
            if (!$db->GetOne("SELECT 1 FROM {$p}module_gallery_props WHERE fileid = ?", [$gid])) {
                $db->Execute("INSERT INTO {$p}module_gallery_props (fileid, templateid, hideparentlink, editors) VALUES (?,0,?,?)", [$gid, $gid === self::ROOT_ID ? 1 : 0, (string) $ctx->uid]);
            }
            if (array_key_exists('template', $args)) {
                $db->Execute("UPDATE {$p}module_gallery_props SET templateid = ? WHERE fileid = ?", [self::resolveTemplate($ctx, $args['template']), $gid]);
            }
            if (array_key_exists('hide_parent_link', $args)) {
                $db->Execute("UPDATE {$p}module_gallery_props SET hideparentlink = ? WHERE fileid = ?", [$gid === self::ROOT_ID || Util::bool($args, 'hide_parent_link') ? 1 : 0, $gid]);
            }
        }

        if (($order = Util::intList($args, 'image_order')) !== null) {
            $ids = array_map('intval', (array) $db->GetCol("SELECT fileid FROM {$p}module_gallery WHERE galleryid = ? AND fileid <> 1", [$gid]));
            $unknown = array_diff($order, $ids);
            if ($unknown) throw new ToolError('Not in this gallery: ' . implode(', ', $unknown));
            $pos = 1;
            foreach ($order as $fid) $db->Execute("UPDATE {$p}module_gallery SET fileorder = ? WHERE fileid = ?", [$pos++, $fid]);
            foreach (array_diff($ids, $order) as $fid) $db->Execute("UPDATE {$p}module_gallery SET fileorder = ? WHERE fileid = ?", [$pos++, $fid]);
        }

        if (array_key_exists('fields', $args)) self::saveFields($ctx, $gid, true, Util::arr($args, 'fields', []), false);
        self::indexGallery($ctx, $mod, $gid);
        audit($gid, 'Gallery: ' . self::galleryPath($row), 'Gallery edited (MCP)');
        return ['updated' => true, 'gallery' => self::describeGallery($ctx, self::galleryRow($ctx, $gid), self::templates($ctx))];
    }

    public static function deleteGallery(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $row = self::galleryRow($ctx, $args['gallery']);
        $gid = (int) $row['fileid'];
        if ($gid === self::ROOT_ID) throw new ToolError('The root gallery cannot be deleted');
        self::assertCanEdit($ctx, $mod, $row);
        if ($mod->GetPreference('use_permissions') && !($ctx->can('Gallery - Edit all galleries') && $ctx->can('Gallery - Delete subgalleries'))) {
            throw new ToolError("Permission denied: deleting galleries needs 'Gallery - Edit all galleries' and 'Gallery - Delete subgalleries'");
        }
        $path = rtrim(self::galleryPath($row), '/');
        if (trim((string) Util::str($args, 'confirm'), '/') !== $path) {
            throw new ToolError("Confirmation mismatch: pass confirm=\"$path\" to delete this gallery, its sub-galleries and all their photos");
        }
        $p = CMS_DB_PREFIX;
        $images = (int) $ctx->db->GetOne("SELECT COUNT(*) FROM {$p}module_gallery WHERE fileid <> 1 AND filename NOT LIKE '%/' AND (filepath = ? OR filepath LIKE ?)", [$path . '/', $path . '/%']);
        $ids = array_map('intval', (array) $ctx->db->GetCol("SELECT fileid FROM {$p}module_gallery WHERE fileid <> 1 AND (fileid = ? OR filepath = ? OR filepath LIKE ?)", [$gid, $path . '/', $path . '/%']));
        \Gallery_utils::DeleteGalleryDB($path, $gid);
        if ($ids) {
            $in = implode(',', $ids);
            $ctx->db->Execute("DELETE FROM {$p}module_gallery_fieldvals WHERE fileid IN ($in)");
            $ctx->db->Execute("DELETE FROM {$p}module_gallery_props WHERE fileid IN ($in)");
        }
        $abs = \Gallery_utils::DefaultGalleryPath() . $path;
        if (is_dir($abs)) \Gallery_utils::DeleteFiles($abs . DIRECTORY_SEPARATOR);
        audit($gid, 'Gallery: ' . $path, 'Gallery deleted (MCP)');
        return ['deleted' => true, 'gallery' => ['id' => $gid, 'path' => $path], 'photos_deleted' => $images, 'entries_deleted' => count($ids)];
    }

    // ================================================================ images

    public static function uploadImage(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $row = self::galleryRow($ctx, $args['gallery']);
        self::assertCanEdit($ctx, $mod, $row);
        $gid = (int) $row['fileid'];
        $path = self::galleryPath($row);

        // Content: base64 or a file already in uploads/.
        $source = Util::str($args, 'source_path');
        $b64 = Util::str($args, 'content_base64');
        if (($source === null) === ($b64 === null)) throw new ToolError('Give either content_base64 or source_path');
        if ($b64 !== null) {
            $data = base64_decode(preg_replace('/\s+/', '', $b64), true);
            if ($data === false || $data === '') throw new ToolError('content_base64 is not valid base64');
        } else {
            $srcAbs = self::uploadsFile($ctx, $source);
            $data = (string) file_get_contents($srcAbs);
        }
        $max = (int) ($ctx->siteConfig['mcp_max_upload_bytes'] ?? 10485760);
        if (strlen($data) > $max) throw new ToolError('File too large (' . strlen($data) . " bytes, max $max)");

        $name = Util::str($args, 'filename', $source !== null ? basename($source) : null);
        if ($name === null || trim($name) === '') throw new ToolError('filename is required');
        $name = self::cleanFileName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions($mod);
        if (!in_array($ext, $allowed, true)) throw new ToolError("File type .$ext not allowed by the Gallery settings", ['allowed' => $allowed]);

        $dir = rtrim(\Gallery_utils::DefaultGalleryPath() . $path, '/\\');
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        $existing = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE filename = ? AND filepath = ?', [$name, $path]);
        if (file_exists($dest) && !Util::bool($args, 'overwrite', false)) {
            throw new ToolError("'$name' already exists in this gallery: pass overwrite=true to replace it, or choose another filename");
        }

        // Write, validate as an image, shrink to the Gallery max size, create the admin thumbnail (as action.do-upload).
        $part = $dest . '.part';
        if (file_put_contents($part, $data) === false) throw new ToolError('Could not write the file in the gallery folder (permissions?)');
        $info = @getimagesize($part);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            @unlink($part);
            throw new ToolError('The file is not a valid JPEG/PNG/GIF/WebP image');
        }
        $maxW = (int) $mod->GetPreference('maximagewidth', 0);
        $maxH = (int) $mod->GetPreference('maximageheight', 0);
        $resized = false;
        if ($maxW > 0 && $maxH > 0 && ($info[0] > $maxW || $info[1] > $maxH)) {
            @unlink($dest);
            if (!\Gallery_utils::CreateThumbnail($dest, $part, $maxW, $maxH, 'sc')) {
                @unlink($part);
                throw new ToolError('Could not resize the image');
            }
            @unlink($part);
            $resized = true;
        } elseif (!@rename($part, $dest)) {
            @unlink($part);
            throw new ToolError('Could not move the file into the gallery folder');
        }
        $thumb = $dir . DIRECTORY_SEPARATOR . IM_PREFIX . $name;
        @unlink($thumb);
        \Gallery_utils::CreateThumbnail($thumb, $dest, \cms_siteprefs::get('thumbnail_width', 96), \cms_siteprefs::get('thumbnail_height', 96), 'sc');
        if ($existing) {
            // Replaced file: drop the frontend thumbnails generated from the old one.
            \Gallery_utils::DeleteFiles(\Gallery_utils::DefaultGalleryThumbsPath(), $existing['fileid'] . '-*', false);
        }

        // Register in the database (the admin does it by synchronising the folder).
        \Gallery_utils::UpdateGalleryDB($path, $gid);
        $img = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE filename = ? AND filepath = ?', [$name, $path]);
        if (!$img) throw new ToolError('The file was written but Gallery did not register it');
        $fid = (int) $img['fileid'];
        $ctx->db->Execute(
            'UPDATE ' . CMS_DB_PREFIX . 'module_gallery SET title = ?, comment = ?, active = ? WHERE fileid = ?',
            [
                (string) Util::str($args, 'title', $existing['title'] ?? ''),
                (string) Util::str($args, 'comment', $existing['comment'] ?? ''),
                Util::bool($args, 'active', true) ? 1 : 0,
                $fid,
            ]
        );
        if (isset($args['fields'])) self::saveFields($ctx, $fid, false, Util::arr($args, 'fields', []), false);
        self::indexImage($ctx, $mod, $fid);
        audit($fid, 'Gallery: ' . $path . $name, 'Image uploaded (MCP)');

        $size = @getimagesize($dest);
        return [
            ($existing ? 'replaced' : 'created') => true,
            'image' => self::describeImage($ctx, $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE fileid = ?', [$fid])),
            'width' => $size ? $size[0] : null,
            'height' => $size ? $size[1] : null,
            'resized' => $resized,
        ];
    }

    public static function updateImage(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $img = self::imageRow($ctx, (int) $args['image']);
        $fid = (int) $img['fileid'];
        $gallery = self::galleryRow($ctx, (int) $img['galleryid']);
        self::assertCanEdit($ctx, $mod, $gallery);
        $db = $ctx->db;
        $p = CMS_DB_PREFIX;

        $set = [];
        $params = [];
        foreach (['title' => 'title', 'comment' => 'comment'] as $arg => $col) {
            if (array_key_exists($arg, $args)) {
                $set[] = "$col = ?";
                $params[] = (string) Util::str($args, $arg, '');
            }
        }
        if (($d = self::date($args, 'date')) !== null) {
            $set[] = 'filedate = ?';
            $params[] = $d;
        }
        if (($active = Util::bool($args, 'active')) !== null) {
            $set[] = 'active = ?';
            $params[] = $active ? 1 : 0;
        }
        if ($set) {
            $params[] = $fid;
            $db->Execute("UPDATE {$p}module_gallery SET " . implode(', ', $set) . ' WHERE fileid = ?', $params);
        }
        if (array_key_exists('fields', $args)) self::saveFields($ctx, $fid, false, Util::arr($args, 'fields', []), false);

        $file = \Gallery_utils::DefaultGalleryPath() . $img['filepath'] . $img['filename'];
        if (($rotate = Util::str($args, 'rotate')) !== null) {
            if (!in_array($rotate, ['clockwise', 'anticlockwise'], true)) throw new ToolError('rotate must be clockwise or anticlockwise');
            \Gallery_utils::RotateImage($file, $rotate === 'clockwise' ? 270 : 90);
            \Gallery_utils::DeleteFiles(\Gallery_utils::DefaultGalleryThumbsPath(), $fid . '-*', false);
        }

        if (isset($args['move_to']) && $args['move_to'] !== '') {
            $target = self::galleryRow($ctx, $args['move_to']);
            self::assertCanEdit($ctx, $mod, $target);
            $newPath = self::galleryPath($target);
            if ($newPath !== $img['filepath']) {
                $newFile = \Gallery_utils::DefaultGalleryPath() . $newPath . $img['filename'];
                if (file_exists($newFile)) throw new ToolError("A file named '{$img['filename']}' already exists in the target gallery");
                if (!@rename($file, $newFile)) throw new ToolError('Could not move the file');
                $oldThumb = \Gallery_utils::DefaultGalleryPath() . $img['filepath'] . IM_PREFIX . $img['filename'];
                if (is_file($oldThumb)) @rename($oldThumb, \Gallery_utils::DefaultGalleryPath() . $newPath . IM_PREFIX . $img['filename']);
                $db->Execute("UPDATE {$p}module_gallery SET filepath = ?, galleryid = ?, fileorder = 0 WHERE fileid = ?", [$newPath, (int) $target['fileid'], $fid]);
                $db->Execute("UPDATE {$p}module_gallery SET defaultfile = 0 WHERE defaultfile = ? AND fileid = ?", [$fid, (int) $gallery['fileid']]);
            }
        }
        self::indexImage($ctx, $mod, $fid);
        audit($fid, 'Gallery: ' . $img['filename'], 'Image edited (MCP)');
        return ['updated' => true, 'image' => self::describeImage($ctx, self::imageRow($ctx, $fid))];
    }

    public static function deleteImage(array $args, Context $ctx): array
    {
        $mod = self::init($ctx);
        $img = self::imageRow($ctx, (int) $args['image']);
        $fid = (int) $img['fileid'];
        self::assertCanEdit($ctx, $mod, self::galleryRow($ctx, (int) $img['galleryid']));
        // Same as multiaction "delete" for an image: removes file, IM thumb, thumbnails, row.
        \Gallery_utils::DeleteGalleryDB('do_not_delete_directory', $fid);
        $p = CMS_DB_PREFIX;
        $ctx->db->Execute("DELETE FROM {$p}module_gallery_fieldvals WHERE fileid = ?", [$fid]);
        $ctx->db->Execute("UPDATE {$p}module_gallery SET defaultfile = 0 WHERE defaultfile = ?", [$fid]);
        $search = \cms_utils::get_search_module();
        if (is_object($search)) $search->DeleteWords($mod->GetName(), $fid, 'gallery_image');
        audit($fid, 'Gallery: ' . $img['filepath'] . $img['filename'], 'Image deleted (MCP)');
        return ['deleted' => true, 'image' => ['id' => $fid, 'file' => $img['filepath'] . $img['filename']]];
    }

    // ================================================================ helpers

    private static function init(Context $ctx)
    {
        $mod = $ctx->module('Gallery');
        $ctx->moduleClass('Gallery', 'Gallery_utils');
        $ctx->requirePerm('Use Gallery');
        return $mod;
    }

    private static function assertCanEdit(Context $ctx, $mod, array $gallery)
    {
        $editors = array_filter(explode(';', (string) ($gallery['editors'] ?? '')), 'strlen');
        if (!\Gallery_utils::CheckEditor($ctx->uid, (int) $gallery['fileid'], $editors)) {
            throw new ToolError("Permission denied: user '{$ctx->username}' is not an editor of gallery '" . rtrim(self::galleryPath($gallery), '/') . "'");
        }
    }

    private static function galleryRow(Context $ctx, $ref): array
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $row = \Gallery_utils::Getgalleryinfobyid((int) $ref);
        } else {
            $path = trim((string) $ref, '/');
            $row = $path === '' ? \Gallery_utils::Getgalleryinfobyid(self::ROOT_ID) : \Gallery_utils::Getgalleryinfo($path);
        }
        if (!$row || ((int) $row['fileid'] !== self::ROOT_ID && substr((string) $row['filename'], -1) !== '/')) {
            throw new ToolError("Gallery '$ref' not found (see gallery_list_galleries)");
        }
        return $row;
    }

    private static function imageRow(Context $ctx, int $fid): array
    {
        $row = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE fileid = ?', [$fid]);
        if (!$row || $fid === self::ROOT_ID || substr((string) $row['filename'], -1) === '/') throw new ToolError("Image $fid not found");
        return $row;
    }

    /** "" for the root gallery, else "path/to/gallery/" (as stored in filepath of its images). */
    private static function galleryPath(array $row): string
    {
        return (int) $row['fileid'] === self::ROOT_ID ? '' : $row['filepath'] . $row['filename'];
    }

    private static function describeGallery(Context $ctx, array $row, array $templates): array
    {
        $gid = (int) $row['fileid'];
        $path = rtrim(self::galleryPath($row), '/');
        $cover = null;
        if (!empty($row['defaultfile'])) {
            $c = $ctx->db->GetRow('SELECT fileid, filepath, filename FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE fileid = ?', [(int) $row['defaultfile']]);
            if ($c) $cover = ['id' => (int) $c['fileid'], 'url' => self::fileUrl($c['filepath'] . $c['filename'])];
        }
        $tplId = (int) ($row['templateid'] ?? 0);
        return [
            'id' => $gid,
            'path' => $path,
            'parent_id' => $gid === self::ROOT_ID ? null : (int) $row['galleryid'],
            'title' => $row['title'],
            'comment' => $row['comment'],
            'date' => Util::isoDate($row['filedate']),
            'active' => (bool) $row['active'],
            'template' => $tplId > 0 ? ($templates[$tplId]['name'] ?? $tplId) : null,
            'hide_parent_link' => (bool) ($row['hideparentlink'] ?? false),
            'cover' => $cover,
            'smarty' => $path === '' ? '{Gallery}' : '{Gallery dir="' . $path . '"}',
        ];
    }

    private static function describeImage(Context $ctx, array $row): array
    {
        $rel = $row['filepath'] . $row['filename'];
        $thumb = \Gallery_utils::DefaultGalleryPath() . $row['filepath'] . IM_PREFIX . $row['filename'];
        return [
            'id' => (int) $row['fileid'],
            'file' => $row['filename'],
            'gallery_id' => (int) $row['galleryid'],
            'title' => $row['title'],
            'comment' => $row['comment'],
            'date' => Util::isoDate($row['filedate']),
            'active' => (bool) $row['active'],
            'order' => (int) $row['fileorder'],
            'url' => self::fileUrl($rel),
            'admin_thumbnail_url' => is_file($thumb) ? self::fileUrl($row['filepath'] . IM_PREFIX . $row['filename']) : null,
            'fields' => (object) self::fieldValues($ctx, (int) $row['fileid'], false),
        ];
    }

    private static function fileUrl(string $rel): string
    {
        return \Gallery_utils::DefaultGalleryUrl() . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    private static function allowedExtensions($mod): array
    {
        $ext = array_filter(array_map('trim', explode(',', strtolower((string) $mod->GetPreference('allowed_extensions', 'jpg,jpeg,gif,png')))));
        $gd = ['jpg', 'jpeg', 'gif', 'png'];
        if (function_exists('gd_info') && !empty(gd_info()['WebP Support'])) $gd[] = 'webp';
        return array_values(array_intersect($ext, $gd));
    }

    private static function cleanFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = munge_string_to_url(pathinfo($name, PATHINFO_FILENAME), true);
        if ($base === '' || $ext === '') throw new ToolError('Invalid filename (expected e.g. "photo.jpg")');
        if (strpos($base, IM_PREFIX) === 0) $base = 'img-' . $base; // "thumb_" files are Gallery's own thumbnails
        return $base . '.' . $ext;
    }

    /** Absolute path of an existing file inside uploads/ (no traversal). */
    private static function uploadsFile(Context $ctx, string $rel): string
    {
        $base = realpath((string) $ctx->config['uploads_path']);
        if (strpos($rel, "\0") !== false || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $rel)) throw new ToolError('Invalid source_path');
        $abs = realpath($base . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $rel), '/'));
        if (!$base || $abs === false || !is_file($abs) || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
            throw new ToolError("source_path '$rel' is not a file inside uploads/");
        }
        return $abs;
    }

    private static function date(array $args, string $key)
    {
        if (!isset($args[$key]) || $args[$key] === '') return null;
        $ts = Util::date($args, $key);
        return date('Y-m-d H:i:s', $ts);
    }

    private static function templates(Context $ctx): array
    {
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT templateid, template, thumbwidth, thumbheight, resizemethod, visible FROM ' . CMS_DB_PREFIX . 'module_gallery_templateprops ORDER BY template') as $t) {
            $out[(int) $t['templateid']] = [
                'id' => (int) $t['templateid'],
                'name' => $t['template'],
                'thumbnail' => $t['thumbwidth'] ? ['width' => (int) $t['thumbwidth'], 'height' => (int) $t['thumbheight'], 'method' => $t['resizemethod']] : null,
                'visible' => (bool) $t['visible'],
            ];
        }
        return $out;
    }

    private static function resolveTemplate(Context $ctx, $ref): int
    {
        if ($ref === null || $ref === '' || (string) $ref === '0') return 0;
        foreach (self::templates($ctx) as $t) {
            if ((is_numeric($ref) && (int) $ref === $t['id']) || strcasecmp((string) $ref, $t['name']) === 0) return $t['id'];
        }
        throw new ToolError("Gallery template '$ref' not found (see gallery_list_galleries templates)");
    }

    private static function fieldDefs(Context $ctx): array
    {
        $out = [];
        foreach ((array) $ctx->db->GetArray('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery_fielddefs ORDER BY dirfield DESC, sortorder') as $f) {
            $out[] = [
                'id' => (int) $f['fieldid'],
                'name' => $f['name'],
                'alias' => strtolower(str_replace(' ', '_', $f['name'])),
                'type' => $f['type'],
                'for' => $f['dirfield'] ? 'gallery' : 'image',
                'options' => in_array($f['type'], ['dropdown', 'radiobuttons'], true) ? explode(',', (string) $f['properties']) : null,
                'public' => (bool) $f['public'],
            ];
        }
        return $out;
    }

    private static function fieldValues(Context $ctx, int $fileid, bool $dirfield): array
    {
        $out = [];
        foreach ((array) $ctx->db->GetArray(
            'SELECT fd.name, fv.value FROM ' . CMS_DB_PREFIX . 'module_gallery_fielddefs fd JOIN ' . CMS_DB_PREFIX . 'module_gallery_fieldvals fv ON fv.fieldid = fd.fieldid AND fv.fileid = ? WHERE fd.dirfield = ? ORDER BY fd.sortorder',
            [$fileid, $dirfield ? 1 : 0]
        ) as $r) {
            $out[strtolower(str_replace(' ', '_', $r['name']))] = $r['value'];
        }
        return $out;
    }

    /**
     * @param bool $replaceAll true: like the admin form, values not given are cleared
     */
    private static function saveFields(Context $ctx, int $fileid, bool $dirfield, array $values, bool $replaceAll)
    {
        if (!$values && !$replaceAll) return;
        $defs = [];
        foreach (self::fieldDefs($ctx) as $d) {
            if (($d['for'] === 'gallery') !== $dirfield) continue;
            $defs[] = $d;
        }
        $p = CMS_DB_PREFIX;
        foreach ($values as $key => $value) {
            $def = null;
            foreach ($defs as $d) {
                if ((is_numeric($key) && (int) $key === $d['id']) || strcasecmp((string) $key, $d['name']) === 0 || strcasecmp((string) $key, $d['alias']) === 0) $def = $d;
            }
            if (!$def) {
                throw new ToolError("Unknown custom field '$key' for " . ($dirfield ? 'galleries' : 'images'), ['available' => array_column($defs, 'alias')]);
            }
            if (is_bool($value)) $value = $value ? '1' : '';
            if (is_array($value) || is_object($value)) throw new ToolError("Custom field '$key': value must be a string");
            $value = (string) $value;
            if ($def['options'] && $value !== '' && !in_array($value, $def['options'], true)) {
                throw new ToolError("Custom field '$key': value must be one of " . implode(', ', $def['options']));
            }
            $ctx->db->Execute("DELETE FROM {$p}module_gallery_fieldvals WHERE fileid = ? AND fieldid = ?", [$fileid, $def['id']]);
            if ($value !== '') {
                $ctx->db->Execute("INSERT INTO {$p}module_gallery_fieldvals (fieldid, fileid, value) VALUES (?,?,?)", [$def['id'], $fileid, $value]);
            }
        }
    }

    /** Search index, as do_editgallery: galleries are indexed with their title, comment and public fields. */
    private static function indexGallery(Context $ctx, $mod, int $gid)
    {
        $search = \cms_utils::get_search_module();
        if (!is_object($search) || $mod->GetPreference('searchimages', false)) return;
        $row = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE fileid = ?', [$gid]);
        if (!$row || !$row['active']) {
            $search->DeleteWords($mod->GetName(), $gid, 'gallery');
            return;
        }
        $words = $row['title'] . ' ' . $row['comment'] . ' ' . self::publicFieldWords($ctx, [$gid]);
        foreach ((array) $ctx->db->GetArray("SELECT fileid, title, comment FROM " . CMS_DB_PREFIX . "module_gallery WHERE galleryid = ? AND active = 1 AND filename NOT LIKE '%/'", [$gid]) as $img) {
            $words .= ' ' . $img['title'] . ' ' . $img['comment'];
        }
        $search->AddWords($mod->GetName(), $gid, 'gallery', $words);
    }

    private static function indexImage(Context $ctx, $mod, int $fid)
    {
        $search = \cms_utils::get_search_module();
        if (!is_object($search)) return;
        $row = $ctx->db->GetRow('SELECT * FROM ' . CMS_DB_PREFIX . 'module_gallery WHERE fileid = ?', [$fid]);
        if (!$mod->GetPreference('searchimages', false)) {
            if ($row) self::indexGallery($ctx, $mod, (int) $row['galleryid']);
            return;
        }
        if (!$row || !$row['active']) {
            $search->DeleteWords($mod->GetName(), $fid, 'gallery_image');
            return;
        }
        $search->AddWords($mod->GetName(), $fid, 'gallery_image', $row['filename'] . ' ' . $row['title'] . ' ' . $row['comment'] . ' ' . self::publicFieldWords($ctx, [$fid]));
    }

    private static function publicFieldWords(Context $ctx, array $ids): string
    {
        $in = implode(',', array_map('intval', $ids));
        return implode(' ', (array) $ctx->db->GetCol('SELECT fv.value FROM ' . CMS_DB_PREFIX . 'module_gallery_fieldvals fv JOIN ' . CMS_DB_PREFIX . "module_gallery_fielddefs fd ON fd.fieldid = fv.fieldid WHERE fd.public = 1 AND fv.fileid IN ($in)"));
    }
}
