<?php
// ============================================================
// Setup 安装器（a=install）：选库 → 写配置 → 建表 → 初始数据 → 安装锁
// ============================================================

function setup_installed(): bool
{
    return is_file(APP_DIR . '/data/install.lock');
}

function setup_route(): never
{
    if (setup_installed()) {
        page('已安装', '<div class="panel narrow"><div class="panel-body"><p>站点已安装。如需重新安装，请删除 <code>app/data/install.lock</code> 与 <code>app/data/db.php</code>。</p><p><a class="btn btn-primary" href="' . h(route_url('home')) . '">进入首页</a></p></div></div>');
    }
    if (is_post()) {
        setup_install();
    }
    $drivers = [];
    if (extension_loaded('pdo_sqlite')) {
        $drivers['sqlite'] = 'SQLite（推荐，零配置）';
    }
    if (extension_loaded('pdo_mysql')) {
        $drivers['mysql'] = 'MySQL 8.0+';
    }
    if (extension_loaded('pdo_pgsql')) {
        $drivers['pgsql'] = 'PostgreSQL 13+';
    }
    $body = '<div class="panel narrow"><div class="panel-head"><strong>安装 owlsgo</strong></div><div class="panel-body">';
    if (!$drivers) {
        $body .= '<p class="error-text">未检测到任何 PDO 数据库驱动（pdo_sqlite / pdo_mysql / pdo_pgsql），请先在 PHP 中启用。</p>';
    } else {
        $body .= '<form method="post" data-setup>'
            . '<div class="field"><label>数据库</label><select name="driver" data-driver-select>';
        foreach ($drivers as $key => $label) {
            $body .= '<option value="' . $key . '">' . $label . '</option>';
        }
        $body .= '</select></div>'
            . '<div data-db-extra hidden>'
            . '<div class="field"><label>主机</label><input type="text" name="host" value="127.0.0.1"></div>'
            . '<div class="field"><label>端口</label><input type="text" name="port" value="3306"></div>'
            . '<div class="field"><label>数据库名</label><input type="text" name="dbname" value="owlsgo"></div>'
            . '<div class="field"><label>用户名</label><input type="text" name="dbuser" value="root"></div>'
            . '<div class="field"><label>密码</label><input type="password" name="dbpass"></div>'
            . '</div>'
            . '<div class="field"><label>站点名称</label><input type="text" name="site_name" value="owlsgo" required maxlength="60"></div>'
            . '<div class="field"><label>管理员用户名</label><input type="text" name="admin_name" required maxlength="30"></div>'
            . '<div class="field"><label>管理员邮箱</label><input type="email" name="admin_email" required></div>'
            . '<div class="field"><label>管理员密码</label><input type="password" name="admin_pass" required minlength="6"></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary btn-block">开始安装</button></div></form>'
            . '<p class="muted">安装后请确保 app/data、app/upload、app/avatars 可写，且 Web 无法直接访问 app/data。</p>';
    }
    $body .= '</div></div>';
    page('安装', $body);
}

function setup_install(): never
{
    $driver = post('driver');
    if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
        err('请选择数据库');
    }
    $siteName = post('site_name', 60) ?: 'owlsgo';
    $adminName = post('admin_name', 30);
    $adminEmail = post('admin_email', 190);
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,30}$/u', $adminName)) {
        err('管理员用户名需为 2-30 位字母、数字、下划线或中文');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        err('管理员邮箱格式不正确');
    }
    if (strlen($adminPass) < 6) {
        err('管理员密码至少 6 位');
    }

    // 组织配置并先测试连接
    $cfg = ['driver' => $driver, 'secret' => bin2hex(random_bytes(32))];
    if ($driver === 'sqlite') {
        $cfg['path'] = APP_DIR . '/data/owlsgo.sqlite';
        if (!is_dir(APP_DIR . '/data')) {
            mkdir(APP_DIR . '/data', 0755, true);
        }
        $test = new PDO('sqlite:' . $cfg['path']);
    } else {
        $cfg['host'] = post('host', 100) ?: '127.0.0.1';
        $cfg['port'] = (int) post('port') ?: ($driver === 'mysql' ? 3306 : 5432);
        $cfg['name'] = post('dbname', 60) ?: 'owlsgo';
        $cfg['user'] = post('dbuser', 60);
        $cfg['pass'] = (string) ($_POST['dbpass'] ?? '');
        try {
            $dsn = $driver === 'mysql'
                ? "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4"
                : "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}";
            $test = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            err('数据库连接失败：' . $e->getMessage());
        }
    }

    // 写配置（install.lock 之前，db.php 不含注释外的敏感信息回显）
    $export = var_export($cfg, true);
    file_put_contents(APP_DIR . '/data/db.php', "<?php\nreturn " . $export . ";\n");

    // 建表 + 初始数据
    $steps = db_sync();

    // 默认用户组：1 管理员 / 2 版主 / 3 注册用户 / 4 游客
    $allKeys = array_keys(perm_keys());
    $userKeys = ['site.view', 'thread.view', 'thread.create', 'thread.edit_own', 'thread.delete_own',
        'reply.create', 'reply.edit_own', 'reply.delete_own', 'like.use', 'favorite.use',
        'attachment.upload', 'attachment.download', 'user.view', 'notice.view', 'search.use'];
    $modKeys = array_merge($userKeys, ['moderate.thread', 'moderate.reply', 'moderate.highlight', 'admin.enter']);
    $guestKeys = ['site.view', 'thread.view', 'user.view', 'notice.view', 'search.use'];
    $groups = [
        [1, '管理员', $allKeys, 100],
        [2, '版主', $modKeys, 50],
        [3, '注册用户', $userKeys, 20],
        [4, '游客', $guestKeys, 0],
    ];
    foreach ($groups as [$id, $name, $perms, $quota]) {
        q('INSERT INTO ow_groups (id, name, permissions, attach_quota_mb, created) VALUES (?,?,?,?,?)',
            [$id, $name, json_encode($perms, JSON_UNESCAPED_UNICODE), $quota, now()]);
    }

    // 默认版块
    q('INSERT INTO ow_forums (parent_id, name, description, sort, created) VALUES (0,?, ?, 1, ?)',
        ['综合讨论', '什么都可以聊', now()]);
    q('INSERT INTO ow_forums (parent_id, name, description, sort, created) VALUES (0,?, ?, 2, ?)',
        ['站务公告', '站点公告与规则', now()]);

    // 管理员
    q('INSERT INTO ow_users (name, email, pass, group_id, created, last_active) VALUES (?,?,?,1,?,?)',
        [$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), now(), now()]);

    // 站点名
    settings_save(['site_name' => $siteName]);

    // 计划任务：回收站过期清理
    q('INSERT INTO ow_cron_jobs (name, callback, interval, enabled, last_run, created) VALUES (?,?,?,1,0,?)',
        ['回收站过期清理', 'cron_purge_trash', 86400, now()]);

    file_put_contents(APP_DIR . '/data/install.lock', date('c') . ' v' . APP_VERSION . ' steps: ' . count($steps));

    $body = '<div class="panel narrow"><div class="panel-head"><strong>安装完成</strong></div><div class="panel-body">'
        . '<p>owlsgo v' . APP_VERSION . ' 安装成功（' . h($driver) . '，建表 ' . count($steps) . ' 步）。</p>'
        . '<p class="muted">安全确认：访问 <code>/app/data/</code> 应返回 403 或 404。</p>'
        . '<div class="form-actions"><a class="btn btn-primary btn-block" href="' . h(app_url('index.php')) . '">进入站点</a></div>'
        . '</div></div>';
    page('安装完成', $body);
}
