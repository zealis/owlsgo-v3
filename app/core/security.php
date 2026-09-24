<?php
// ============================================================
// 安全层：HMAC 签名登录 Cookie、CSRF 双提交、权限键目录与判定、
// 版块级版主、算术验证码、限流与封禁
// ============================================================

// ------------------------------------------------------------
// Cookie 与 CSRF
// ------------------------------------------------------------
function cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function set_cookie_raw(string $name, string $value, int $expire, bool $httpOnly = true): bool
{
    return setcookie($name, $value, [
        'expires' => $expire,
        'path' => '/',
        'secure' => cookie_secure(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
}

function app_secret(): string
{
    $cfg = db_config();
    if (!empty($cfg['secret'])) {
        return $cfg['secret'];
    }
    // 未配置时以数据库路径+站点名派生，安装后仍稳定
    return hash('sha256', ($cfg['driver'] ?? 'sqlite') . '|' . ($cfg['path'] ?? '') . '|' . php_uname());
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    $token = $_SESSION['csrf'];
    set_cookie_raw('owlsgo_csrf', hash_hmac('sha256', $token, app_secret()), 0, false);
    return $token;
}

function form_token(): string
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!is_post()) {
        return;
    }
    $token = (string) ($_POST['_token'] ?? '');
    $expect = (string) ($_SESSION['csrf'] ?? '');
    $cookie = (string) ($_COOKIE['owlsgo_csrf'] ?? '');
    $sign = $expect !== '' ? hash_hmac('sha256', $expect, app_secret()) : '';
    if ($token === '' || $expect === '' || !hash_equals($expect, $token) || !hash_equals($sign, $cookie)) {
        err('表单已过期，请刷新页面重试', 403);
    }
}

// ------------------------------------------------------------
// 登录会话：HMAC 签名 Cookie（uid.expires.sign）
// ------------------------------------------------------------
function auth_cookie_set(int $userId, string $passwordHash, bool $remember = true): void
{
    $expires = now() + ($remember ? 86400 * 30 : 86400);
    $sign = hash_hmac('sha256', $userId . '|' . $expires . '|' . $passwordHash, app_secret());
    set_cookie_raw('owlsgo_auth', $userId . '.' . $expires . '.' . $sign, $expires);
}

function auth_cookie_clear(): void
{
    set_cookie_raw('owlsgo_auth', '', now() - 3600);
}

function me(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $raw = (string) ($_COOKIE['owlsgo_auth'] ?? '');
    if ($raw !== '' && substr_count($raw, '.') === 2) {
        [$uid, $expires, $sign] = explode('.', $raw);
        $uid = (int) $uid;
        $expires = (int) $expires;
        if ($uid > 0 && $expires > now()) {
            $row = one('SELECT * FROM ow_users WHERE id=?', [$uid]);
            if ($row) {
                $expect = hash_hmac('sha256', $uid . '|' . $expires . '|' . $row['pass'], app_secret());
                if (hash_equals($expect, $sign)) {
                    $user = $row;
                }
            }
        }
    }
    if ($user && (int) $user['last_active'] < now() - 300) {
        q('UPDATE ow_users SET last_active=? WHERE id=?', [now(), $user['id']]);
        $user['last_active'] = now();
    }
    return $user;
}

function uid(): int
{
    $u = me();
    return $u ? (int) $u['id'] : 0;
}

// ------------------------------------------------------------
// 权限键目录与判定（与 v1 的 29 键口径一致）
// ------------------------------------------------------------
function perm_keys(): array
{
    return [
        'site.view' => '访问站点',
        'thread.view' => '浏览帖子',
        'thread.create' => '发布帖子',
        'thread.edit_own' => '编辑自己的帖子',
        'thread.delete_own' => '删除自己的帖子',
        'reply.create' => '发表评论',
        'reply.edit_own' => '编辑自己的评论',
        'reply.delete_own' => '删除自己的评论',
        'like.use' => '点赞',
        'favorite.use' => '收藏',
        'attachment.upload' => '上传附件',
        'attachment.download' => '下载附件',
        'user.view' => '查看用户主页',
        'notice.view' => '查看公告',
        'search.use' => '使用搜索',
        'moderate.thread' => '管理帖子（置顶/锁定/精华/移动/审核）',
        'moderate.reply' => '管理评论（审核/删除）',
        'moderate.highlight' => '设置标题高亮',
        'recycle.manage' => '回收站管理',
        'notice.manage' => '公告管理',
        'forum.manage' => '版块管理',
        'group.manage' => '用户组管理',
        'user.manage' => '用户管理',
        'log.view' => '查看日志',
        'cron.manage' => '计划任务管理',
        'plugin.manage' => '插件管理',
        'setting.manage' => '站点设置',
        'upgrade.run' => '在线升级',
        'admin.enter' => '进入后台',
    ];
}

function group_permissions(array $group): array
{
    $perms = json_decode((string) ($group['permissions'] ?? ''), true);
    return is_array($perms) ? $perms : [];
}

function user_group(?int $userId = null): ?array
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        try {
            foreach (all('SELECT * FROM ow_groups') as $g) {
                $groups[(int) $g['id']] = $g;
            }
        } catch (Throwable $e) {
            $groups = [];
        }
    }
    if ($userId === null) {
        $u = me();
        $gid = $u ? (int) $u['group_id'] : 0;
        // 游客组：id=3 之外的兜底——约定 1=管理员 2=版主 3=注册用户 4=游客
        if (!$u) {
            $gid = 4;
        }
        return $groups[$gid] ?? null;
    }
    $u = one('SELECT group_id FROM ow_users WHERE id=?', [$userId]);
    return $u ? ($groups[(int) $u['group_id']] ?? null) : ($groups[4] ?? null);
}

function can(string $key): bool
{
    $u = me();
    if ($u && (int) $u['group_id'] === 1) {
        return true; // 管理员组全量
    }
    if ($u && (int) $u['banned'] === 1) {
        return in_array($key, ['site.view'], true);
    }
    $group = user_group();
    if (!$group) {
        // 无用户组数据时的游客兜底：只允许浏览
        return in_array($key, ['site.view', 'thread.view', 'user.view', 'notice.view', 'search.use'], true);
    }
    $perms = group_permissions($group);
    return in_array($key, $perms, true);
}

// 版块级版主管辖（forums.moderators 存用户 id 逗号串）
function moderated_forum_ids(?int $userId = null): array
{
    $userId = $userId ?? uid();
    if ($userId <= 0) {
        return [];
    }
    $ids = [];
    foreach (all('SELECT id, moderators FROM ow_forums WHERE moderators != \'\'') as $f) {
        $list = array_filter(array_map('intval', explode(',', (string) $f['moderators'])));
        if (in_array($userId, $list, true)) {
            $ids[] = (int) $f['id'];
        }
    }
    return $ids;
}

function moderates_forum(int $forumId): bool
{
    $u = me();
    if (!$u) {
        return false;
    }
    if ((int) $u['group_id'] === 1) {
        return true;
    }
    if ((int) $u['group_id'] === 2 && can('moderate.thread')) {
        // 版主组：管辖列表为空表示全站，否则按列表
        $ids = moderated_forum_ids((int) $u['id']);
        return !$ids || in_array($forumId, $ids, true);
    }
    return false;
}

function thread_by_id(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM ow_threads WHERE id=?', [$id]) : null;
}

function can_manage_thread(array $thread): bool
{
    $u = me();
    if (!$u) {
        return false;
    }
    if ((int) $u['group_id'] === 1) {
        return true;
    }
    if (can('moderate.thread') && moderates_forum((int) $thread['forum_id'])) {
        return true;
    }
    return (int) $thread['user_id'] === (int) $u['id'] && can('thread.edit_own');
}

function can_delete_thread(array $thread): bool
{
    $u = me();
    if (!$u) {
        return false;
    }
    if ((int) $u['group_id'] === 1 || (can('moderate.thread') && moderates_forum((int) $thread['forum_id']))) {
        return true;
    }
    return (int) $thread['user_id'] === (int) $u['id'] && can('thread.delete_own');
}

function can_manage_reply(array $reply): bool
{
    $u = me();
    if (!$u) {
        return false;
    }
    if ((int) $u['group_id'] === 1) {
        return true;
    }
    $thread = thread_by_id((int) $reply['thread_id']);
    if ($thread && can('moderate.reply') && moderates_forum((int) $thread['forum_id'])) {
        return true;
    }
    return (int) $reply['user_id'] === (int) $u['id'] && can('reply.edit_own');
}

function can_delete_reply(array $reply): bool
{
    $u = me();
    if (!$u) {
        return false;
    }
    $thread = thread_by_id((int) $reply['thread_id']);
    if ($thread && ((int) $u['group_id'] === 1 || (can('moderate.reply') && moderates_forum((int) $thread['forum_id'])))) {
        return true;
    }
    return (int) $reply['user_id'] === (int) $u['id'] && can('reply.delete_own');
}

function is_super(): bool
{
    $u = me();
    return $u && (int) $u['group_id'] === 1;
}

function can_admin(): bool
{
    return can('admin.enter');
}

function is_muted(): bool
{
    $u = me();
    return $u && (int) $u['muted_until'] > now();
}

function can_speak(): bool
{
    return !is_muted() && !(me() && (int) me()['banned'] === 1);
}

function need_site_access(): void
{
    if (!can('site.view')) {
        err('你没有访问本站的权限', 403);
    }
    // IP 封禁
    $ban = one('SELECT * FROM ow_bans WHERE ip=? AND (until=0 OR until>?)', [client_ip(), now()]);
    if ($ban) {
        err('你的 IP 已被限制访问' . ($ban['reason'] ? '：' . $ban['reason'] : ''), 403);
    }
    $u = me();
    if ($u && (int) $u['banned'] === 1) {
        err('账号已被限制访问', 403);
    }
}

function need_login(): void
{
    if (!me()) {
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => '请先登录', 'login' => route_url('login')]);
        }
        go(route_url('login', ['back' => $_SERVER['REQUEST_URI'] ?? '']));
    }
}

function need_speak(): void
{
    need_login();
    if (!can_speak()) {
        err(is_muted() ? '你已被禁言，暂时不能发言' : '账号状态异常，无法发言', 403);
    }
}

function need_admin(): void
{
    need_login();
    if (!can_admin()) {
        err('没有后台访问权限', 403);
    }
}

// ------------------------------------------------------------
// 算术验证码（答案存 session）
// ------------------------------------------------------------
function captcha_field(): string
{
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $_SESSION['captcha_answer'] = $a + $b;
    return '<div class="field"><label>验证码</label><div class="captcha-row"><span class="captcha-q">' . $a . ' + ' . $b . ' = ?</span>'
        . '<input type="text" name="_captcha" required autocomplete="off" inputmode="numeric"></div></div>';
}

function captcha_check(): void
{
    $answer = (string) ($_SESSION['captcha_answer'] ?? '');
    $given = trim((string) ($_POST['_captcha'] ?? ''));
    unset($_SESSION['captcha_answer']);
    if ($answer === '' || $given === '' || (int) $given !== (int) $answer) {
        err('验证码错误，请重试');
    }
}

// ------------------------------------------------------------
// 限流：rate_limits 按 桶(动作+用户/IP) 计数
// ------------------------------------------------------------
function rate_limited(string $action, int $seconds): bool
{
    if ($seconds <= 0) {
        return false;
    }
    $u = me();
    $bucket = $action . ':' . ($u ? 'u' . $u['id'] : 'ip' . client_ip());
    $last = val('SELECT MAX(created) FROM ow_rate_limits WHERE bucket=?', [$bucket]);
    return $last && (now() - (int) $last) < $seconds;
}

function rate_hit(string $action): void
{
    $u = me();
    $bucket = $action . ':' . ($u ? 'u' . $u['id'] : 'ip' . client_ip());
    q('INSERT INTO ow_rate_limits (bucket, created) VALUES (?, ?)', [$bucket, now()]);
    // 顺便清理 1 天前的记录
    q('DELETE FROM ow_rate_limits WHERE created<?', [now() - 86400]);
}

function check_post_interval(string $action, string $settingKey): void
{
    $seconds = (int) setting($settingKey, '10');
    if ($seconds > 0 && rate_limited($action, $seconds)) {
        err('操作太频繁，请 ' . $seconds . ' 秒后再试');
    }
}
