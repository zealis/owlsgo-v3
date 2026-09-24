<?php
// ============================================================
// Plugin 插件机制：manifest 发现/启用、钩子、自定义路由与后台页、
// 资源合并（CSS/JS）、ZIP 上传安装
// manifest 示例见 app/plugins/owlsgo-demo/plugin.php
// ============================================================

function plugin_dir(string $id): string
{
    return APP_DIR . '/plugins/' . basename($id);
}

function plugin_manifest(string $id): ?array
{
    $file = plugin_dir($id) . '/plugin.php';
    if (!is_file($file)) {
        return null;
    }
    $m = require $file;
    return is_array($m) ? $m : null;
}

function plugin_all(): array
{
    $out = [];
    $base = APP_DIR . '/plugins';
    if (!is_dir($base)) {
        return $out;
    }
    foreach (scandir($base) as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) {
            continue;
        }
        $m = plugin_manifest($entry);
        if ($m) {
            $row = one('SELECT * FROM ow_plugins WHERE id=?', [$entry]);
            $out[] = [
                'id' => $entry,
                'manifest' => $m,
                'enabled' => $row ? (int) $row['enabled'] === 1 : false,
                'row' => $row,
            ];
        }
    }
    return $out;
}

// 请求早期调用：装载已启用插件的钩子/路由/资源
function plugin_bootstrap(): void
{
    $GLOBALS['__plugin_routes'] = [];
    $GLOBALS['__plugin_pages'] = [];
    try {
        $enabled = all('SELECT id FROM ow_plugins WHERE enabled=1');
    } catch (Throwable $e) {
        return;
    }
    foreach ($enabled as $row) {
        $m = plugin_manifest($row['id']);
        if (!$m) {
            continue;
        }
        foreach ((array) ($m['hooks'] ?? []) as $name => $cb) {
            if (is_callable($cb)) {
                hook_add($name, $cb);
            }
        }
        foreach ((array) ($m['routes'] ?? []) as $action => $cb) {
            if (is_callable($cb)) {
                $GLOBALS['__plugin_routes'][$action] = $cb;
            }
        }
        foreach ((array) ($m['pages'] ?? []) as $slug => $def) {
            $GLOBALS['__plugin_pages'][$slug] = $def;
        }
        foreach ((array) ($m['cron'] ?? []) as $job) {
            if (!empty($job['name']) && !one('SELECT id FROM ow_cron_jobs WHERE callback=?', ['plugin:' . $row['id'] . ':' . $job['name']])) {
                q('INSERT INTO ow_cron_jobs (name, callback, interval, enabled, last_run, created) VALUES (?,?,?,1,0,?)',
                    ['插件：' . ($m['name'] ?? $row['id']), 'plugin:' . $row['id'] . ':' . $job['name'], (int) ($job['interval'] ?? 3600), now()]);
            }
        }
    }
}

function plugin_route_exists(string $action): bool
{
    return isset($GLOBALS['__plugin_routes'][$action]);
}

function plugin_route_run(string $action): never
{
    $cb = $GLOBALS['__plugin_routes'][$action];
    $cb();
    exit;
}

function plugin_handle_page(string $slug): bool
{
    $def = $GLOBALS['__plugin_pages'][$slug] ?? null;
    if (!$def) {
        return false;
    }
    $title = is_array($def) ? ($def['title'] ?? '插件页面') : '插件页面';
    $cb = is_array($def) ? ($def['render'] ?? null) : $def;
    if (!is_callable($cb)) {
        return false;
    }
    page($title, (string) $cb(), sidebar_html());
    return true;
}

function plugin_handle_asset(string $id, string $file): bool
{
    $file = basename($file);
    $path = plugin_dir($id) . '/' . $file;
    if (!is_file($path)) {
        return false;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
    if (!isset($types[$ext])) {
        return false;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=604800');
    readfile($path);
    return true;
}

// ------------------------------------------------------------
// 后台插件页
// ------------------------------------------------------------
function plugin_admin_html(): string
{
    $html = '<div class="panel"><div class="panel-head"><strong>安装插件（ZIP）</strong></div><div class="panel-body">'
        . '<form method="post" enctype="multipart/form-data" data-confirm-submit="确认安装该插件包？只会解压到 app/plugins/ 并登记。">' . form_token()
        . '<input type="hidden" name="do" value="plugin_upload">'
        . '<input type="file" name="zip" accept=".zip" required> <button type="submit" class="btn btn-primary">上传安装</button></form>'
        . '<p class="muted">ZIP 内应包含一个插件目录，目录中有 plugin.php 返回 manifest。</p></div></div>';
    $html .= '<div class="panel"><div class="panel-head"><strong>插件列表</strong></div><ul class="simple-list">';
    foreach (plugin_all() as $p) {
        $m = $p['manifest'];
        $html .= '<li><span class="user-cell"><strong>' . h($m['name'] ?? $p['id']) . '</strong> <span class="tag">' . h($m['version'] ?? '?') . '</span>'
            . '<span class="muted">' . h($m['description'] ?? '') . '（' . h($p['id']) . '）</span></span>'
            . '<span class="row-actions">'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="plugin_toggle"><input type="hidden" name="id" value="' . h($p['id']) . '">'
            . '<button type="submit" class="op-btn' . ($p['enabled'] ? ' active' : '') . '" title="' . ($p['enabled'] ? '停用' : '启用') . '">' . icons('check') . '</button></form>'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="plugin_delete"><input type="hidden" name="id" value="' . h($p['id']) . '">'
            . '<button type="submit" class="op-btn danger" title="卸载" data-confirm="卸载并删除插件文件？">' . icons('trash') . '</button></form>'
            . '</span></li>';
    }
    return $html . '<li class="empty">把插件目录放进 app/plugins/ 即可被发现</li></ul></div>';
}

function plugin_admin_post(): void
{
    $do = post('do');
    if ($do === 'plugin_toggle') {
        $id = post('id');
        $m = plugin_manifest($id);
        if (!$m) {
            err('插件不存在');
        }
        $row = one('SELECT * FROM ow_plugins WHERE id=?', [$id]);
        if ($row) {
            q('UPDATE ow_plugins SET enabled=1-enabled WHERE id=?', [$id]);
        } else {
            q('INSERT INTO ow_plugins (id, name, version, enabled, settings, created) VALUES (?,?,?,1,\'\',?)',
                [$id, (string) ($m['name'] ?? $id), (string) ($m['version'] ?? ''), now()]);
        }
        log_action('plugin.toggle', '启停插件：' . $id);
        go(route_url('admin', ['tab' => 'plugins']));
    }
    if ($do === 'plugin_delete') {
        $id = post('id');
        q('DELETE FROM ow_plugins WHERE id=?', [$id]);
        plugin_remove_dir(plugin_dir($id));
        log_action('plugin.delete', '卸载插件：' . $id);
        set_flash('插件已卸载');
        go(route_url('admin', ['tab' => 'plugins']));
    }
    if ($do === 'plugin_upload') {
        if (empty($_FILES['zip']['tmp_name']) || !class_exists('ZipArchive')) {
            err('上传失败或服务器缺少 ZipArchive 扩展');
        }
        $zip = new ZipArchive();
        if ($zip->open($_FILES['zip']['tmp_name']) !== true) {
            err('ZIP 无法打开');
        }
        // 找到包含 plugin.php 的顶层目录名
        $slug = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/plugin\.php$#', $name, $m)) {
                $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $m[1]);
                break;
            }
        }
        if ($slug === '') {
            $zip->close();
            err('ZIP 中未找到 插件目录/plugin.php');
        }
        $dest = plugin_dir($slug);
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, $slug . '/') || str_contains($name, '..')) {
                continue;
            }
            $rel = substr($name, strlen($slug) + 1);
            if ($rel === '' || str_ends_with($name, '/')) {
                continue;
            }
            $target = $dest . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $zip->getFromIndex($i));
        }
        $zip->close();
        log_action('plugin.upload', '上传安装插件：' . $slug);
        set_flash('插件「' . $slug . '」已安装，请启用');
        go(route_url('admin', ['tab' => 'plugins']));
    }
}

function plugin_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
