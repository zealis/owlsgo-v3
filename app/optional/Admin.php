<?php
// ============================================================
// Admin 后台（a=admin&tab=…）：仪表盘/版块/用户组/用户/内容/评论/
// 附件/公告/回收站/日志/计划任务/设置/插件/在线升级
// ============================================================

function admin_tabs_def(): array
{
    return [
        'dashboard' => ['仪表盘', 'log.view'],
        'forums' => ['版块', 'forum.manage'],
        'groups' => ['用户组', 'group.manage'],
        'users' => ['用户', 'user.manage'],
        'content' => ['内容', 'moderate.thread'],
        'replies' => ['评论', 'moderate.reply'],
        'attachments' => ['附件', 'user.manage'],
        'notices' => ['公告', 'notice.manage'],
        'recycle' => ['回收站', 'recycle.manage'],
        'logs' => ['日志', 'log.view'],
        'cron' => ['计划任务', 'cron.manage'],
        'settings' => ['设置', 'setting.manage'],
        'plugins' => ['插件', 'plugin.manage'],
        'upgrade' => ['在线升级', 'upgrade.run'],
    ];
}

function admin_route(): never
{
    need_admin();
    $tab = get('tab') ?: 'dashboard';
    $tabs = admin_tabs_def();
    if (!isset($tabs[$tab])) {
        $tab = 'dashboard';
    }
    [$title, $perm] = $tabs[$tab];
    if (!can($perm) && !is_super()) {
        err('没有该页面的管理权限', 403);
    }
    if (is_post()) {
        check_csrf();
        admin_handle_post($tab);
    }
    // 组装后台骨架：左导航 + 右内容
    $nav = '';
    foreach ($tabs as $key => [$label, $p]) {
        if (can($p) || is_super()) {
            $nav .= '<a class="' . ($key === $tab ? 'active' : '') . '" href="' . h(route_url('admin', ['tab' => $key])) . '">' . $label . '</a>';
        }
    }
    $body = '<div class="admin"><nav class="admin-nav">' . $nav . '</nav><div class="admin-main">'
        . admin_render($tab)
        . '</div></div>';
    page('后台 - ' . $title, $body);
}

function admin_render(string $tab): string
{
    return match ($tab) {
        'dashboard' => admin_dashboard(),
        'forums' => admin_forums(),
        'groups' => admin_groups(),
        'users' => admin_users(),
        'content' => admin_content(),
        'replies' => admin_replies(),
        'attachments' => admin_attachments(),
        'notices' => admin_notices(),
        'recycle' => admin_recycle(),
        'logs' => admin_logs(),
        'cron' => admin_cron(),
        'settings' => admin_settings(),
        'plugins' => admin_plugins(),
        'upgrade' => admin_upgrade(),
        default => '<div class="panel"><div class="panel-body">未知页面</div></div>',
    };
}

// ------------------------------------------------------------
// 仪表盘
// ------------------------------------------------------------
function admin_dashboard(): string
{
    $stats = [
        '用户' => (int) val('SELECT COUNT(*) FROM ow_users'),
        '帖子' => (int) val('SELECT COUNT(*) FROM ow_threads'),
        '评论' => (int) val('SELECT COUNT(*) FROM ow_replies'),
        '附件' => (int) val('SELECT COUNT(*) FROM ow_attachments'),
        '待审帖' => (int) val('SELECT COUNT(*) FROM ow_threads WHERE status=\'pending\''),
        '待审评论' => (int) val('SELECT COUNT(*) FROM ow_replies WHERE status=\'pending\''),
        '回收站' => (int) val('SELECT COUNT(*) FROM ow_trash'),
    ];
    $html = '<div class="panel"><div class="panel-head"><strong>概览</strong><span class="muted">v' . APP_VERSION . ' · ' . db_driver() . ' · PHP ' . PHP_VERSION . '</span></div>'
        . '<div class="stat-grid wide">';
    foreach ($stats as $label => $num) {
        $html .= '<div class="stat"><strong>' . $num . '</strong><span>' . $label . '</span></div>';
    }
    $html .= '</div></div>';
    // 维护操作
    $syncLog = take_flash();
    $html .= '<div class="panel"><div class="panel-head"><strong>维护</strong></div><div class="panel-body admin-actions">'
        . '<form method="post">' . form_token() . '<input type="hidden" name="do" value="sync_schema"><button type="submit" class="btn btn-ghost">' . icons('refresh') . '结构同步</button></form>'
        . '<form method="post">' . form_token() . '<input type="hidden" name="do" value="clear_opcache"><button type="submit" class="btn btn-ghost">' . icons('refresh') . '清理 OPcache</button></form>'
        . '</div>' . ($syncLog ? '<div class="panel-body"><pre class="log-pre">' . h($syncLog) . '</pre></div>' : '') . '</div>';
    // 最近动态
    $recent = attach_users(all('SELECT * FROM ow_threads ORDER BY created DESC LIMIT 8'));
    $html .= '<div class="panel thread-list"><div class="panel-head"><strong>最新帖子</strong></div>';
    foreach ($recent as $t) {
        $html .= thread_item_html($t, $t['_user'], 'reply', true);
    }
    $html .= '</div>';
    return $html;
}

// ------------------------------------------------------------
// 版块管理
// ------------------------------------------------------------
function admin_forums(): string
{
    $edit = gid('edit') ? forum_by_id(gid('edit')) : null;
    $html = '<div class="panel"><div class="panel-head"><strong>' . ($edit ? '编辑版块' : '新建版块') . '</strong></div><div class="panel-body">'
        . '<form method="post" class="form-grid">' . form_token()
        . '<input type="hidden" name="do" value="save_forum"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="field"><label>名称</label><input type="text" name="name" required maxlength="60" value="' . h($edit['name'] ?? '') . '"></div>'
        . '<div class="field"><label>父版块</label><select name="parent_id"><option value="0">（顶级）</option>';
    foreach (forum_children(0) as $f) {
        if ($edit && (int) $edit['id'] === (int) $f['id']) {
            continue;
        }
        $html .= '<option value="' . (int) $f['id'] . '"' . ($edit && (int) $edit['parent_id'] === (int) $f['id'] ? ' selected' : '') . '>' . h($f['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="field"><label>描述</label><input type="text" name="description" maxlength="200" value="' . h($edit['description'] ?? '') . '"></div>'
        . '<div class="field"><label>排序</label><input type="number" name="sort" value="' . (int) ($edit['sort'] ?? 0) . '"></div>'
        . '<div class="field"><label>版主（用户 ID，逗号分隔）</label><input type="text" name="moderators" value="' . h($edit['moderators'] ?? '') . '" placeholder="如 2,5,9"></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form></div></div>';
    // 列表
    $html .= '<div class="panel"><div class="panel-head"><strong>版块列表</strong></div><ul class="simple-list">';
    foreach (forums_all(true) as $f) {
        $parent = $f['parent_id'] ? forum_by_id((int) $f['parent_id']) : null;
        $html .= '<li><span>' . ($parent ? '　├ ' : '') . '<strong>' . h($f['name']) . '</strong> <span class="muted">' . (int) $f['threads'] . ' 帖 / 排序 ' . (int) $f['sort'] . '</span></span>'
            . '<span class="row-actions"><a class="op-btn" href="' . h(route_url('admin', ['tab' => 'forums', 'edit' => $f['id']])) . '">' . icons('edit') . '</a>'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="delete_forum"><input type="hidden" name="id" value="' . (int) $f['id'] . '"><button type="submit" class="op-btn danger" data-confirm="删除版块？其下帖子不会被删除但会无处归属，建议先移动。">' . icons('trash') . '</button></form></span></li>';
    }
    return $html . '</ul></div>';
}

// ------------------------------------------------------------
// 用户组管理
// ------------------------------------------------------------
function admin_groups(): string
{
    $edit = gid('edit') ? group_by_id(gid('edit')) : null;
    $html = '<div class="panel"><div class="panel-head"><strong>' . ($edit ? '编辑用户组' : '新建用户组') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="do" value="save_group"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="form-grid"><div class="field"><label>名称</label><input type="text" name="name" required maxlength="60" value="' . h($edit['name'] ?? '') . '"></div>'
        . '<div class="field"><label>附件配额 (MB，0=禁止)</label><input type="number" name="quota" value="' . (int) ($edit['attach_quota_mb'] ?? 20) . '"></div></div>'
        . '<div class="field"><label>权限</label><div class="perm-grid">';
    $current = $edit ? group_permissions($edit) : [];
    foreach (perm_keys() as $key => $label) {
        $html .= '<label class="perm-item"><input type="checkbox" name="perms[]" value="' . h($key) . '"' . (in_array($key, $current, true) ? ' checked' : '') . '> ' . h($label) . '<code>' . h($key) . '</code></label>';
    }
    $html .= '</div></div><div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form></div></div>';
    $html .= '<div class="panel"><div class="panel-head"><strong>用户组列表</strong></div><ul class="simple-list">';
    foreach (groups_all(true) as $g) {
        $count = (int) val('SELECT COUNT(*) FROM ow_users WHERE group_id=?', [$g['id']]);
        $html .= '<li><span><strong>' . h($g['name']) . '</strong> <span class="muted">' . $count . ' 人 · 配额 ' . (int) $g['attach_quota_mb'] . 'MB</span></span>'
            . '<span class="row-actions"><a class="op-btn" href="' . h(route_url('admin', ['tab' => 'groups', 'edit' => $g['id']])) . '">' . icons('edit') . '</a>'
            . (!in_array((int) $g['id'], [1, 2, 3, 4], true) ? '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="delete_group"><input type="hidden" name="id" value="' . (int) $g['id'] . '"><button type="submit" class="op-btn danger" data-confirm="删除该用户组？组内用户将移到注册用户组。">' . icons('trash') . '</button></form>' : '')
            . '</span></li>';
    }
    return $html . '</ul></div>';
}

// ------------------------------------------------------------
// 用户管理
// ------------------------------------------------------------
function admin_users(): string
{
    $kw = get('q');
    $pageNum = page_now();
    $perPage = 20;
    $where = '1=1';
    $params = [];
    if ($kw !== '') {
        $where = '(name LIKE ? OR email LIKE ?)';
        $params = ['%' . $kw . '%', '%' . $kw . '%'];
    }
    $total = (int) val("SELECT COUNT(*) FROM ow_users WHERE $where", $params);
    $rows = all("SELECT * FROM ow_users WHERE $where ORDER BY created DESC LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage), $params);

    $html = '<div class="panel"><div class="panel-body"><form method="get" class="search-form">'
        . '<input type="hidden" name="a" value="admin"><input type="hidden" name="tab" value="users">'
        . '<input type="search" name="q" value="' . h($kw) . '" placeholder="搜索用户名/邮箱"><button type="submit" class="btn btn-primary">搜索</button></form></div></div>'
        . '<div class="panel"><form method="post" data-batch>' . form_token() . '<input type="hidden" name="do" value="batch_user">'
        . '<div class="panel-head bulk-bar"><label><input type="checkbox" data-check-all> 全选</label>'
        . '<select name="op"><option value="">批量操作…</option><option value="ban">禁访</option><option value="unban">解除禁访</option><option value="mute7">禁言 7 天</option><option value="unmute">解除禁言</option><option value="delete">删除账号</option></select>'
        . '<select name="group_id"><option value="">改组…</option>';
    foreach (groups_all() as $g) {
        $html .= '<option value="' . (int) $g['id'] . '">→ ' . h($g['name']) . '</option>';
    }
    $html .= '</select><button type="submit" class="btn btn-ghost">执行</button></div><ul class="simple-list user-list">';
    foreach ($rows as $u) {
        $html .= '<li><label class="check-cell"><input type="checkbox" name="ids[]" value="' . (int) $u['id'] . '"></label>'
            . avatar_tag((int) $u['id'], $u['name']) . '<span class="user-cell">' . user_link((int) $u['id'], $u['name']) . ' ' . user_state_tags($u)
            . '<span class="muted">' . h($u['email']) . ' · 注册 ' . date('Y-m-d', (int) $u['created']) . '</span></span>'
            . '<span class="row-actions">'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="reset_pass"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><button type="submit" class="op-btn" title="重置密码" data-confirm="为该用户生成新密码？">' . icons('refresh') . '</button></form>'
            . '</span></li>';
    }
    $html .= '</ul></form></div>' . paginate(route_url('admin', ['tab' => 'users', 'q' => $kw]), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 内容管理（待审帖 + 全部帖子批量）
// ------------------------------------------------------------
function admin_content(): string
{
    $sub = get('sub') ?: 'pending';
    $html = tabs_html([
        'pending' => ['待审核', route_url('admin', ['tab' => 'content'])],
        'all' => ['全部帖子', route_url('admin', ['tab' => 'content', 'sub' => 'all'])],
    ], $sub);
    $pageNum = page_now();
    $perPage = 20;
    $where = $sub === 'pending' ? 'status=\'pending\'' : '1=1';
    $total = (int) val("SELECT COUNT(*) FROM ow_threads WHERE $where");
    $rows = attach_users(all("SELECT * FROM ow_threads WHERE $where ORDER BY created DESC LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage)));
    $html .= '<div class="panel"><form method="post" data-batch>' . form_token() . '<input type="hidden" name="do" value="batch_thread">'
        . '<div class="panel-head bulk-bar"><label><input type="checkbox" data-check-all> 全选</label>'
        . '<select name="op"><option value="">批量操作…</option><option value="approve">通过审核</option><option value="delete">删除（入回收站）</option></select>'
        . '<select name="forum_id"><option value="">移动到…</option>';
    foreach (forum_options() as $f) {
        $html .= '<option value="' . (int) $f['id'] . '">' . h($f['name']) . '</option>';
    }
    $html .= '</select><button type="submit" class="btn btn-ghost">执行</button></div><ul class="simple-list">';
    foreach ($rows as $t) {
        $html .= '<li><label class="check-cell"><input type="checkbox" name="ids[]" value="' . (int) $t['id'] . '"></label>'
            . '<span class="user-cell"><a href="' . h(route_url('thread', ['id' => $t['id']])) . '">' . h($t['title']) . '</a> ' . thread_tags_html($t)
            . '<span class="muted">' . h($t['_user']['name'] ?? '已注销') . ' · ' . human_time((int) $t['created']) . '</span></span></li>';
    }
    $html .= ($rows ? '' : '<li class="empty">暂无内容</li>') . '</ul></form></div>'
        . paginate(route_url('admin', ['tab' => 'content', 'sub' => $sub]), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 评论管理（待审 + 全部）
// ------------------------------------------------------------
function admin_replies(): string
{
    $sub = get('sub') ?: 'pending';
    $html = tabs_html([
        'pending' => ['待审核', route_url('admin', ['tab' => 'replies'])],
        'all' => ['全部评论', route_url('admin', ['tab' => 'replies', 'sub' => 'all'])],
    ], $sub);
    $pageNum = page_now();
    $perPage = 20;
    $where = $sub === 'pending' ? 'r.status=\'pending\'' : '1=1';
    $total = (int) val("SELECT COUNT(*) FROM ow_replies r WHERE $where");
    $rows = all("SELECT r.*, t.title AS thread_title, u.name AS author_name FROM ow_replies r JOIN ow_threads t ON t.id=r.thread_id LEFT JOIN ow_users u ON u.id=r.user_id WHERE $where ORDER BY r.created DESC LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage));
    $html .= '<div class="panel"><form method="post" data-batch>' . form_token() . '<input type="hidden" name="do" value="batch_reply">'
        . '<div class="panel-head bulk-bar"><label><input type="checkbox" data-check-all> 全选</label>'
        . '<select name="op"><option value="">批量操作…</option><option value="approve">通过审核</option><option value="delete">删除（入回收站）</option></select>'
        . '<button type="submit" class="btn btn-ghost">执行</button></div><ul class="simple-list">';
    foreach ($rows as $r) {
        $html .= '<li><label class="check-cell"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '"></label>'
            . '<span class="user-cell"><a href="' . h(route_url('thread', ['id' => $r['thread_id']]) . '#p' . $r['id']) . '">' . h(content_excerpt($r['content'], 60)) . '</a> ' . ($r['status'] === 'pending' ? '<span class="tag tag-warn">待审核</span>' : '')
            . '<span class="muted">' . h($r['author_name'] ?? '已注销') . ' 评论于「' . h(cut($r['thread_title'], 24)) . '」 · ' . human_time((int) $r['created']) . '</span></span></li>';
    }
    $html .= ($rows ? '' : '<li class="empty">暂无评论</li>') . '</ul></form></div>'
        . paginate(route_url('admin', ['tab' => 'replies', 'sub' => $sub]), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 附件管理
// ------------------------------------------------------------
function admin_attachments(): string
{
    $pageNum = page_now();
    $perPage = 20;
    $total = (int) val('SELECT COUNT(*) FROM ow_attachments');
    $rows = all('SELECT a.*, u.name AS author_name FROM ow_attachments a LEFT JOIN ow_users u ON u.id=a.user_id ORDER BY a.created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage));
    $html = '<div class="panel"><form method="post" data-batch>' . form_token() . '<input type="hidden" name="do" value="batch_attach">'
        . '<div class="panel-head bulk-bar"><label><input type="checkbox" data-check-all> 全选</label>'
        . '<span class="muted">共 ' . $total . ' 个附件，合计 ' . round(((int) val('SELECT COALESCE(SUM(size),0) FROM ow_attachments')) / 1048576, 1) . ' MB</span>'
        . '<button type="submit" class="btn btn-ghost">批量删除</button></div><ul class="simple-list">';
    foreach ($rows as $a) {
        $html .= '<li><label class="check-cell"><input type="checkbox" name="ids[]" value="' . (int) $a['id'] . '"></label>'
            . '<span class="user-cell"><a href="' . h(attachment_url((int) $a['id'])) . '">' . h($a['name']) . '</a>'
            . '<span class="muted">' . h($a['author_name'] ?? '?') . ' · ' . round($a['size'] / 1024, 1) . ' KB · 下载 ' . (int) $a['downloads'] . ' 次 · ' . human_time((int) $a['created']) . '</span></span></li>';
    }
    $html .= ($rows ? '' : '<li class="empty">暂无附件</li>') . '</ul></form></div>'
        . paginate(route_url('admin', ['tab' => 'attachments']), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 公告管理
// ------------------------------------------------------------
function admin_notices(): string
{
    $edit = gid('edit') ? one('SELECT * FROM ow_notices WHERE id=?', [gid('edit')]) : null;
    $html = '<div class="panel"><div class="panel-head"><strong>' . ($edit ? '编辑公告' : '发布公告') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token() . '<input type="hidden" name="do" value="save_notice"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="field"><label>标题</label><input type="text" name="title" required maxlength="120" value="' . h($edit['title'] ?? '') . '"></div>'
        . '<div class="field"><label>内容</label>' . editor_widget('content', $edit['content'] ?? '') . '</div>'
        . '<div class="field check"><label><input type="checkbox" name="pushed" value="1"' . ($edit && (int) $edit['pushed'] === 1 ? ' checked' : '') . '> 推送到侧栏公告位</label></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form></div></div>';
    $rows = all('SELECT n.*, u.name AS author_name FROM ow_notices n LEFT JOIN ow_users u ON u.id=n.user_id ORDER BY n.created DESC LIMIT 50');
    $html .= '<div class="panel"><div class="panel-head"><strong>公告列表</strong></div><ul class="simple-list">';
    foreach ($rows as $n) {
        $html .= '<li><span><a href="' . h(route_url('notice', ['id' => $n['id']])) . '">' . h($n['title']) . '</a>' . ((int) $n['pushed'] === 1 ? ' <span class="tag tag-pin">推送中</span>' : '')
            . '<span class="muted">' . h($n['author_name'] ?? '') . ' · ' . human_time((int) $n['created']) . '</span></span>'
            . '<span class="row-actions"><a class="op-btn" href="' . h(route_url('admin', ['tab' => 'notices', 'edit' => $n['id']])) . '">' . icons('edit') . '</a>'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="delete_notice"><input type="hidden" name="id" value="' . (int) $n['id'] . '"><button type="submit" class="op-btn danger" data-confirm="删除该公告？">' . icons('trash') . '</button></form></span></li>';
    }
    return $html . ($rows ? '' : '<li class="empty">暂无公告</li>') . '</ul></div>';
}

// ------------------------------------------------------------
// 回收站
// ------------------------------------------------------------
function admin_recycle(): string
{
    $pageNum = page_now();
    $perPage = 20;
    $total = (int) val('SELECT COUNT(*) FROM ow_trash');
    $rows = all('SELECT * FROM ow_trash ORDER BY created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage));
    $html = '<div class="panel"><div class="panel-head"><strong>回收站</strong><span class="muted">删除的内容在此保留，可恢复；超过 30 天由计划任务清理</span></div>'
        . '<form method="post" data-batch>' . form_token() . '<input type="hidden" name="do" value="batch_trash">'
        . '<div class="panel-head bulk-bar"><label><input type="checkbox" data-check-all> 全选</label>'
        . '<select name="op"><option value="">批量操作…</option><option value="restore">恢复</option><option value="purge">彻底删除</option></select>'
        . '<button type="submit" class="btn btn-ghost">执行</button></div><ul class="simple-list">';
    foreach ($rows as $r) {
        $data = json_decode((string) $r['data'], true) ?: [];
        $label = $r['type'] === 'thread' ? '帖子：' . cut((string) ($data['title'] ?? ''), 40) : '评论：' . content_excerpt((string) ($data['content'] ?? ''), 50);
        $html .= '<li><label class="check-cell"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '"></label>'
            . '<span class="user-cell"><span class="tag">' . ($r['type'] === 'thread' ? '帖子' : '评论') . '</span> ' . h($label)
            . '<span class="muted">删除于 ' . full_time((int) $r['created']) . '</span></span></li>';
    }
    $html .= ($rows ? '' : '<li class="empty">回收站是空的</li>') . '</ul></form></div>'
        . paginate(route_url('admin', ['tab' => 'recycle']), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 日志
// ------------------------------------------------------------
function admin_logs(): string
{
    $pageNum = page_now();
    $perPage = 30;
    $total = (int) val('SELECT COUNT(*) FROM ow_logs');
    $rows = all('SELECT l.*, u.name AS user_name FROM ow_logs l LEFT JOIN ow_users u ON u.id=l.user_id ORDER BY l.created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage));
    $html = '<div class="panel"><div class="panel-head"><strong>操作日志</strong><div class="spacer"></div>'
        . '<a class="btn btn-ghost" href="' . h(route_url('admin', ['tab' => 'logs', 'export' => '1'])) . '">' . icons('download') . '导出 CSV</a>'
        . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="clear_logs"><button type="submit" class="btn btn-ghost danger" data-confirm="清空全部日志？">清空</button></form></div>'
        . '<ul class="simple-list">';
    foreach ($rows as $l) {
        $html .= '<li><span class="user-cell"><code>' . h($l['action']) . '</code> ' . h($l['detail'])
            . '<span class="muted">' . h($l['user_name'] ?? '游客') . ' · ' . h($l['ip']) . ' · ' . full_time((int) $l['created']) . '</span></span></li>';
    }
    $html .= ($rows ? '' : '<li class="empty">暂无日志</li>') . '</ul></div>'
        . paginate(route_url('admin', ['tab' => 'logs']), $total, $perPage, $pageNum);
    return $html;
}

// ------------------------------------------------------------
// 计划任务
// ------------------------------------------------------------
function admin_cron(): string
{
    $rows = all('SELECT * FROM ow_cron_jobs ORDER BY id ASC');
    $token = setting('cron_token');
    $cronUrl = app_url('index.php?a=cron') . ($token !== '' ? '&token=' . $token : '');
    $html = '<div class="panel"><div class="panel-head"><strong>计划任务</strong></div><div class="panel-body">'
        . '<p class="muted">外部定时每分钟访问：<code>' . h($cronUrl) . '</code></p>'
        . '<form method="post" class="form-grid">' . form_token() . '<input type="hidden" name="do" value="save_token">'
        . '<div class="field"><label>触发令牌（留空则任何人可触发）</label><input type="text" name="token" value="' . h($token) . '" maxlength="60"></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存令牌</button></div></form></div>'
        . '<ul class="simple-list">';
    foreach ($rows as $j) {
        $html .= '<li><span class="user-cell"><strong>' . h($j['name']) . '</strong> <code>' . h($j['callback']) . '</code>'
            . '<span class="muted">每 ' . (int) $j['interval'] . ' 秒 · 上次运行 ' . ((int) $j['last_run'] > 0 ? full_time((int) $j['last_run']) : '从未') . '</span></span>'
            . '<span class="row-actions">'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="run_cron"><input type="hidden" name="id" value="' . (int) $j['id'] . '"><button type="submit" class="op-btn" title="立即运行">' . icons('refresh') . '</button></form>'
            . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="do" value="toggle_cron"><input type="hidden" name="id" value="' . (int) $j['id'] . '"><button type="submit" class="op-btn' . ((int) $j['enabled'] === 1 ? ' active' : '') . '" title="启停">' . icons('check') . '</button></form>'
            . '</span></li>';
    }
    return $html . ($rows ? '' : '<li class="empty">暂无任务</li>') . '</ul></div>';
}

// ------------------------------------------------------------
// 站点设置（五分组 + Logo + 结构同步入口在仪表盘）
// ------------------------------------------------------------
function admin_settings(): string
{
    $groups = [
        '基本' => ['site_name' => '站点名称', 'site_desc' => '站点描述', 'site_keywords' => '关键词', 'icp' => '备案号', 'footer_html' => '页脚 HTML', 'notice_guide' => '侧栏引导语', 'pretty_url' => '伪静态地址(1/0)'],
        '注册与登录' => ['register_open' => '开放注册(1/0)', 'register_captcha' => '注册验证码(1/0)', 'login_captcha' => '登录验证码(1/0)'],
        '发帖与审核' => ['thread_review' => '帖子先审后显(1/0)', 'reply_review' => '评论先审后显(1/0)', 'thread_interval' => '发帖间隔(秒)', 'reply_interval' => '评论间隔(秒)'],
        '界面' => ['title_highlight' => '标题高亮色(1/0)', 'hot_threshold' => '热门阈值', 'fold_threshold' => '长内容折叠阈值(字)', 'per_page' => '每页帖子数', 'floor_per_page' => '每页楼层数', 'home_tabs' => '首页页签(逗号)'],
        '上传' => ['upload_max_mb' => '单文件上限(MB)'],
    ];
    $html = '';
    // Logo 管理
    $html .= '<div class="panel"><div class="panel-head"><strong>站点 Logo</strong></div><div class="panel-body logo-row">'
        . '<img class="logo-preview" src="' . h(site_logo_url()) . '" alt="">'
        . '<form method="post" enctype="multipart/form-data">' . form_token() . '<input type="hidden" name="do" value="upload_logo">'
        . '<input type="file" name="logo" accept=".svg" required> <button type="submit" class="btn btn-primary">上传 SVG</button></form>'
        . (setting('site_logo') !== '' ? '<form method="post">' . form_token() . '<input type="hidden" name="do" value="reset_logo"><button type="submit" class="btn btn-ghost">恢复默认</button></form>' : '')
        . '</div></div>';
    foreach ($groups as $groupName => $fields) {
        $html .= '<div class="panel"><div class="panel-head"><strong>' . $groupName . '</strong></div><div class="panel-body">'
            . '<form method="post">' . form_token() . '<input type="hidden" name="do" value="save_settings"><div class="form-grid">';
        foreach ($fields as $key => $label) {
            $html .= '<div class="field"><label>' . h($label) . '</label><input type="text" name="s_' . h($key) . '" value="' . h(setting($key)) . '"></div>';
        }
        $html .= '</div><div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form></div></div>';
    }
    return $html;
}

// ------------------------------------------------------------
// 插件（委托 Plugin 模块的后台页）
// ------------------------------------------------------------
function admin_plugins(): string
{
    require_once APP_DIR . '/optional/Plugin.php';
    return plugin_admin_html();
}

// ------------------------------------------------------------
// 在线升级：manifest URL → 下载 → sha256 校验 → 写入
// ------------------------------------------------------------
function admin_upgrade(): string
{
    $html = '<div class="panel"><div class="panel-head"><strong>在线升级</strong><span class="muted">当前 v' . APP_VERSION . '</span></div><div class="panel-body">'
        . '<form method="post">' . form_token() . '<input type="hidden" name="do" value="upgrade_check">'
        . '<div class="field"><label>升级清单 URL（manifest.json）</label><input type="text" name="manifest" value="' . h(setting('upgrade_manifest', 'https://raw.githubusercontent.com/zealis/owlsgo-v3/main/manifest.json')) . '" required></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">检查更新</button></div></form></div></div>';
    return $html;
}

function admin_fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'owlsgo-upgrader/' . APP_VERSION]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $code >= 400) {
            err('下载失败（HTTP ' . $code . '）');
        }
        return (string) $data;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'owlsgo-upgrader/' . APP_VERSION]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        err('下载失败，请检查网络');
    }
    return $data;
}

// ------------------------------------------------------------
// POST 动作分发
// ------------------------------------------------------------
function admin_handle_post(string $tab): void
{
    $do = post('do');
    // 仪表盘维护
    if ($do === 'sync_schema') {
        $done = db_sync();
        set_flash($done ? '结构同步完成：' . implode('、', $done) : '结构已是最新');
        go(route_url('admin'));
    }
    if ($do === 'clear_opcache') {
        $ok = function_exists('opcache_reset') ? opcache_reset() : false;
        set_flash($ok ? 'OPcache 已清理' : 'OPcache 未启用');
        go(route_url('admin'));
    }
    // 版块
    if ($do === 'save_forum') {
        $id = (int) post('id');
        $mods = implode(',', array_filter(array_map('intval', explode(',', post('moderators')))));
        if ($id > 0) {
            q('UPDATE ow_forums SET name=?, parent_id=?, description=?, sort=?, moderators=? WHERE id=?',
                [post('name', 60), (int) post('parent_id'), post('description', 200), (int) post('sort'), $mods, $id]);
            log_action('forum.edit', '编辑版块 #' . $id);
        } else {
            q('INSERT INTO ow_forums (parent_id, name, description, sort, moderators, created) VALUES (?,?,?,?,?,?)',
                [(int) post('parent_id'), post('name', 60), post('description', 200), (int) post('sort'), $mods, now()]);
            log_action('forum.create', '新建版块「' . post('name', 60) . '」');
        }
        forums_all(true);
        go(route_url('admin', ['tab' => 'forums']));
    }
    if ($do === 'delete_forum') {
        $id = (int) post('id');
        q('DELETE FROM ow_forums WHERE id=?', [$id]);
        q('UPDATE ow_forums SET parent_id=0 WHERE parent_id=?', [$id]);
        forums_all(true);
        log_action('forum.delete', '删除版块 #' . $id);
        go(route_url('admin', ['tab' => 'forums']));
    }
    // 用户组
    if ($do === 'save_group') {
        $id = (int) post('id');
        $perms = array_values(array_filter((array) ($_POST['perms'] ?? []), fn($k) => isset(perm_keys()[$k])));
        if ($id > 0) {
            q('UPDATE ow_groups SET name=?, permissions=?, attach_quota_mb=? WHERE id=?',
                [post('name', 60), json_encode($perms, JSON_UNESCAPED_UNICODE), (int) post('quota'), $id]);
            log_action('group.edit', '编辑用户组 #' . $id);
        } else {
            q('INSERT INTO ow_groups (name, permissions, attach_quota_mb, created) VALUES (?,?,?,?)',
                [post('name', 60), json_encode($perms, JSON_UNESCAPED_UNICODE), (int) post('quota'), now()]);
            log_action('group.create', '新建用户组「' . post('name', 60) . '」');
        }
        go(route_url('admin', ['tab' => 'groups']));
    }
    if ($do === 'delete_group') {
        $id = (int) post('id');
        if (in_array($id, [1, 2, 3, 4], true)) {
            err('内置用户组不能删除');
        }
        q('UPDATE ow_users SET group_id=3 WHERE group_id=?', [$id]);
        q('DELETE FROM ow_groups WHERE id=?', [$id]);
        log_action('group.delete', '删除用户组 #' . $id);
        go(route_url('admin', ['tab' => 'groups']));
    }
    // 用户批量
    if ($do === 'batch_user') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = post('op');
        $groupId = (int) post('group_id');
        foreach ($ids as $id) {
            if ($id === 1 || $id === uid()) {
                continue; // 保护首个管理员与自己
            }
            if ($op === 'ban') {
                q('UPDATE ow_users SET banned=1 WHERE id=?', [$id]);
            } elseif ($op === 'unban') {
                q('UPDATE ow_users SET banned=0 WHERE id=?', [$id]);
            } elseif ($op === 'mute7') {
                q('UPDATE ow_users SET muted_until=? WHERE id=?', [now() + 7 * 86400, $id]);
            } elseif ($op === 'unmute') {
                q('UPDATE ow_users SET muted_until=0 WHERE id=?', [$id]);
            } elseif ($op === 'delete') {
                admin_delete_user($id);
            } elseif ($groupId > 0 && group_by_id($groupId)) {
                q('UPDATE ow_users SET group_id=? WHERE id=?', [$groupId, $id]);
            }
        }
        log_action('user.batch', "批量 $op/组$groupId：" . implode(',', $ids));
        set_flash('批量操作完成');
        go(route_url('admin', ['tab' => 'users']));
    }
    if ($do === 'reset_pass') {
        $id = (int) post('id');
        $u = user_by_id($id);
        if ($u) {
            $new = substr(bin2hex(random_bytes(8)), 0, 10);
            q('UPDATE ow_users SET pass=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $id]);
            log_action('user.reset_pass', '重置密码：' . $u['name']);
            set_flash('「' . $u['name'] . '」的新密码：' . $new . '（只显示这一次）');
        }
        go(route_url('admin', ['tab' => 'users']));
    }
    // 内容批量
    if ($do === 'batch_thread') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = post('op');
        $toForum = (int) post('forum_id');
        foreach ($ids as $id) {
            $t = thread_by_id($id);
            if (!$t) {
                continue;
            }
            if ($op === 'approve') {
                q('UPDATE ow_threads SET status=\'ok\' WHERE id=?', [$id]);
                recount_forum((int) $t['forum_id']);
            } elseif ($op === 'delete') {
                delete_thread($id, uid());
            } elseif ($toForum > 0 && forum_by_id($toForum)) {
                q('UPDATE ow_threads SET forum_id=? WHERE id=?', [$toForum, $id]);
                recount_forum((int) $t['forum_id']);
                recount_forum($toForum);
            }
        }
        log_action('thread.batch', "批量 $op/移$toForum：" . implode(',', $ids));
        set_flash('批量操作完成');
        go(route_url('admin', ['tab' => 'content', 'sub' => get('sub')]));
    }
    if ($do === 'batch_reply') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = post('op');
        foreach ($ids as $id) {
            $r = one('SELECT * FROM ow_replies WHERE id=?', [$id]);
            if (!$r) {
                continue;
            }
            if ($op === 'approve') {
                q('UPDATE ow_replies SET status=\'ok\' WHERE id=?', [$id]);
                recount_thread((int) $r['thread_id']);
            } elseif ($op === 'delete') {
                delete_reply($id, uid());
            }
        }
        log_action('reply.batch', "批量 $op：" . implode(',', $ids));
        set_flash('批量操作完成');
        go(route_url('admin', ['tab' => 'replies', 'sub' => get('sub')]));
    }
    // 附件批量
    if ($do === 'batch_attach') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        foreach ($ids as $id) {
            $att = one('SELECT * FROM ow_attachments WHERE id=?', [$id]);
            if ($att) {
                @unlink(APP_DIR . '/upload/' . $att['path']);
                q('DELETE FROM ow_attachments WHERE id=?', [$id]);
            }
        }
        log_action('attachment.batch_delete', '批量删除附件：' . implode(',', $ids));
        set_flash('已删除选中附件');
        go(route_url('admin', ['tab' => 'attachments']));
    }
    // 公告
    if ($do === 'save_notice') {
        $id = (int) post('id');
        $pushed = post('pushed') === '1' ? 1 : 0;
        if ($id > 0) {
            q('UPDATE ow_notices SET title=?, content=?, pushed=? WHERE id=?', [post('title', 120), post('content'), $pushed, $id]);
        } else {
            q('INSERT INTO ow_notices (user_id, title, content, pushed, created) VALUES (?,?,?,?,?)',
                [uid(), post('title', 120), post('content'), $pushed, now()]);
        }
        log_action('notice.save', '保存公告「' . post('title', 40) . '」');
        go(route_url('admin', ['tab' => 'notices']));
    }
    if ($do === 'delete_notice') {
        q('DELETE FROM ow_notices WHERE id=?', [(int) post('id')]);
        log_action('notice.delete', '删除公告 #' . (int) post('id'));
        go(route_url('admin', ['tab' => 'notices']));
    }
    // 回收站
    if ($do === 'batch_trash') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = post('op');
        $restored = 0;
        $purged = 0;
        foreach ($ids as $id) {
            $row = one('SELECT * FROM ow_trash WHERE id=?', [$id]);
            if (!$row) {
                continue;
            }
            if ($op === 'restore' && trash_restore($row)) {
                $restored++;
            } elseif ($op === 'purge') {
                q('DELETE FROM ow_trash WHERE id=?', [$id]);
                $purged++;
            }
        }
        log_action('trash.batch', "恢复 $restored / 彻底删除 $purged");
        set_flash("已恢复 $restored 条，彻底删除 $purged 条");
        go(route_url('admin', ['tab' => 'recycle']));
    }
    // 日志
    if ($do === 'clear_logs') {
        q('DELETE FROM ow_logs');
        log_action('logs.clear', '清空日志');
        go(route_url('admin', ['tab' => 'logs']));
    }
    // 计划任务
    if ($do === 'save_token') {
        settings_save(['cron_token' => post('token', 60)]);
        go(route_url('admin', ['tab' => 'cron']));
    }
    if ($do === 'toggle_cron') {
        q('UPDATE ow_cron_jobs SET enabled=1-enabled WHERE id=?', [(int) post('id')]);
        go(route_url('admin', ['tab' => 'cron']));
    }
    if ($do === 'run_cron') {
        require_once APP_DIR . '/optional/Cron.php';
        $job = one('SELECT * FROM ow_cron_jobs WHERE id=?', [(int) post('id')]);
        $result = $job ? cron_run_job($job) : '任务不存在';
        set_flash('运行结果：' . $result);
        go(route_url('admin', ['tab' => 'cron']));
    }
    // 设置
    if ($do === 'save_settings') {
        $values = [];
        foreach ($_POST as $k => $v) {
            if (str_starts_with($k, 's_')) {
                $values[substr($k, 2)] = trim((string) $v);
            }
        }
        $defaults = default_settings();
        $values = array_intersect_key($values, $defaults + ['upgrade_manifest' => 1]);
        settings_save($values);
        log_action('settings.save', '保存站点设置');
        set_flash('设置已保存');
        go(route_url('admin', ['tab' => 'settings']));
    }
    if ($do === 'upload_logo') {
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'svg') {
                err('仅支持 SVG 格式 Logo');
            }
            $content = file_get_contents($_FILES['logo']['tmp_name']);
            if (!str_contains($content, '<svg')) {
                err('文件不是有效的 SVG');
            }
            file_put_contents(APP_DIR . '/data/site_logo.svg', $content);
            settings_save(['site_logo' => 'site_logo.svg']);
            log_action('settings.logo', '上传站点 Logo');
            set_flash('Logo 已更新');
        }
        go(route_url('admin', ['tab' => 'settings']));
    }
    if ($do === 'reset_logo') {
        @unlink(APP_DIR . '/data/site_logo.svg');
        settings_save(['site_logo' => '']);
        set_flash('已恢复默认 Logo');
        go(route_url('admin', ['tab' => 'settings']));
    }
    // 插件 POST 委托
    if ($tab === 'plugins') {
        require_once APP_DIR . '/optional/Plugin.php';
        plugin_admin_post();
    }
    // 在线升级
    if ($do === 'upgrade_check') {
        admin_upgrade_check(post('manifest'));
    }
    if ($do === 'upgrade_apply') {
        admin_upgrade_apply();
    }
}

// 删除账号：内容入回收站，账号与令牌/通知清除
function admin_delete_user(int $id): void
{
    $u = user_by_id($id);
    if (!$u) {
        return;
    }
    foreach (all('SELECT id FROM ow_threads WHERE user_id=?', [$id]) as $t) {
        delete_thread((int) $t['id'], uid());
    }
    foreach (all('SELECT id FROM ow_replies WHERE user_id=?', [$id]) as $r) {
        delete_reply((int) $r['id'], uid());
    }
    q('DELETE FROM ow_api_tokens WHERE user_id=?', [$id]);
    q('DELETE FROM ow_notifications WHERE user_id=? OR sender_id=?', [$id, $id]);
    q('DELETE FROM ow_likes WHERE user_id=?', [$id]);
    q('DELETE FROM ow_favorites WHERE user_id=?', [$id]);
    q('DELETE FROM ow_users WHERE id=?', [$id]);
    log_action('user.delete', '删除账号：' . $u['name']);
}

// 回收站恢复：thread 连同其级联评论一起恢复
function trash_restore(array $trashRow): bool
{
    $data = json_decode((string) $trashRow['data'], true);
    if (!is_array($data)) {
        return false;
    }
    if ($trashRow['type'] === 'thread') {
        $threadId = (int) ($data['id'] ?? 0);
        if ($threadId <= 0 || one('SELECT id FROM ow_threads WHERE id=?', [$threadId])) {
            return false;
        }
        unset($data['_thread_deleted']);
        tx(function () use ($data, $threadId, $trashRow): void {
            db_insert_row('ow_threads', $data);
            // 级联评论
            foreach (all('SELECT * FROM ow_trash WHERE type=\'reply\'') as $r) {
                $rd = json_decode((string) $r['data'], true);
                if (is_array($rd) && (int) ($rd['_thread_deleted'] ?? 0) === $threadId) {
                    unset($rd['_thread_deleted']);
                    if (!one('SELECT id FROM ow_replies WHERE id=?', [(int) $rd['id']])) {
                        db_insert_row('ow_replies', $rd);
                    }
                    q('DELETE FROM ow_trash WHERE id=?', [$r['id']]);
                }
            }
            q('DELETE FROM ow_trash WHERE id=?', [$trashRow['id']]);
        });
        recount_thread($threadId);
        recount_forum((int) $data['forum_id']);
        recount_user((int) $data['user_id']);
        log_action('trash.restore', '恢复帖子 #' . $threadId);
        return true;
    }
    if ($trashRow['type'] === 'reply') {
        $replyId = (int) ($data['id'] ?? 0);
        $threadId = (int) ($data['thread_id'] ?? 0);
        if ($replyId <= 0 || one('SELECT id FROM ow_replies WHERE id=?', [$replyId])) {
            return false;
        }
        if (!thread_by_id($threadId)) {
            return false; // 帖子已不在（随帖子删除的由帖子恢复负责）
        }
        unset($data['_thread_deleted']);
        db_insert_row('ow_replies', $data);
        q('DELETE FROM ow_trash WHERE id=?', [$trashRow['id']]);
        recount_thread($threadId);
        $t = thread_by_id($threadId);
        if ($t) {
            recount_forum((int) $t['forum_id']);
        }
        recount_user((int) $data['user_id']);
        log_action('trash.restore', '恢复评论 #' . $replyId);
        return true;
    }
    return false;
}

// 按 db_schema 的列定义回插一行（只写已知列，三库通用）
function db_insert_row(string $table, array $row): void
{
    $schema = db_schema()[$table]['columns'] ?? [];
    $row = array_intersect_key($row, $schema);
    $cols = array_keys($row);
    $marks = implode(',', array_fill(0, count($cols), '?'));
    q('INSERT INTO ' . ident($table) . ' (' . implode(',', array_map('ident', $cols)) . ') VALUES (' . $marks . ')', array_values($row));
}

// ------------------------------------------------------------
// 在线升级实现
// ------------------------------------------------------------
function admin_upgrade_check(string $manifestUrl): never
{
    $raw = admin_fetch($manifestUrl);
    $manifest = json_decode($raw, true);
    if (!is_array($manifest) || empty($manifest['version']) || empty($manifest['files']) || !is_array($manifest['files'])) {
        err('升级清单格式不正确');
    }
    settings_save(['upgrade_manifest' => $manifestUrl]);
    $_SESSION['upgrade_manifest'] = $manifest;
    $html = '<div class="panel"><div class="panel-head"><strong>发现新版本 v' . h($manifest['version']) . '</strong><span class="muted">当前 v' . APP_VERSION . '</span></div>'
        . '<div class="panel-body"><p>将更新 ' . count($manifest['files']) . ' 个文件（下载后逐个 sha256 校验）：</p><ul class="simple-list">';
    foreach ($manifest['files'] as $f) {
        $html .= '<li><code>' . h($f['path'] ?? '?') . '</code></li>';
    }
    $html .= '</ul><form method="post">' . form_token() . '<input type="hidden" name="do" value="upgrade_apply">'
        . '<button type="submit" class="btn btn-primary" data-confirm="确认升级？建议先备份。">开始升级</button> '
        . '<a class="btn btn-ghost" href="' . h(route_url('admin', ['tab' => 'upgrade'])) . '">取消</a></form></div></div>';
    page('检查更新', '<div class="admin"><nav class="admin-nav"></nav><div class="admin-main">' . $html . '</div></div>');
}

function admin_upgrade_apply(): never
{
    $manifest = $_SESSION['upgrade_manifest'] ?? null;
    unset($_SESSION['upgrade_manifest']);
    if (!is_array($manifest)) {
        err('升级会话已过期，请重新检查更新');
    }
    $root = dirname(APP_DIR);
    $done = [];
    $failed = [];
    foreach ($manifest['files'] as $f) {
        $path = (string) ($f['path'] ?? '');
        $url = (string) ($f['url'] ?? '');
        $sha = (string) ($f['sha256'] ?? '');
        if ($path === '' || $url === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            $failed[] = $path . '（路径非法）';
            continue;
        }
        try {
            $content = admin_fetch($url);
        } catch (Throwable $e) {
            $failed[] = $path . '（下载失败）';
            continue;
        }
        if ($sha !== '' && hash('sha256', $content) !== strtolower($sha)) {
            $failed[] = $path . '（校验失败）';
            continue;
        }
        $dest = $root . '/' . $path;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }
        file_put_contents($dest, $content);
        $done[] = $path;
    }
    log_action('upgrade.apply', '升级到 v' . $manifest['version'] . '：成功 ' . count($done) . ' 失败 ' . count($failed));
    $html = '<div class="panel"><div class="panel-head"><strong>升级完成</strong></div><div class="panel-body">'
        . '<p>成功 ' . count($done) . ' 个文件' . ($failed ? '，失败 ' . count($failed) . ' 个：<br>' . h(implode('<br>', $failed)) : '') . '</p>'
        . '<p class="muted">如升级包含结构变更，请到仪表盘执行一次「结构同步」。页面没变化请先清理 OPcache。</p>'
        . '<a class="btn btn-primary" href="' . h(route_url('admin')) . '">返回后台</a></div></div>';
    page('升级完成', $html);
}
