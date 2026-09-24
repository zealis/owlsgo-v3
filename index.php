<?php
// ============================================================
// owlsgo v3 —— 极简原生 PHP 论坛 · 单入口
// 纯原生、无框架、无依赖；SQLite(默认)/MySQL/PostgreSQL
// ============================================================

declare(strict_types=1);

define('APP_DIR', __DIR__ . '/app');

require APP_DIR . '/version.php';
require APP_DIR . '/core/db.php';
require APP_DIR . '/core/base.php';
require APP_DIR . '/core/security.php';
require APP_DIR . '/core/content.php';
require APP_DIR . '/core/forum.php';
require APP_DIR . '/core/view.php';

session_name('owlsgo_sid');
session_start();

// 安全响应头（CSP 禁内联脚本；HTML 不缓存，静态资源 7 天）
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// 伪静态解析：/thread/123 → a=thread&id=123
function parse_path_route(): void
{
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '' && isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $path = '/' . ltrim(substr($uri, strlen($base)), '/');
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }
    }
    if ($path === '' || $path === '/') {
        return;
    }
    $seg = explode('/', trim($path, '/'));
    $map = ['forum', 'thread', 'user', 'search', 'login', 'register'];
    if (in_array($seg[0], $map, true)) {
        $_GET['a'] = $seg[0];
        if (isset($seg[1]) && ctype_digit($seg[1])) {
            $_GET['id'] = $seg[1];
        }
    }
}
parse_path_route();

$action = get('a') ?: 'home';

// 安装守卫：未安装一律进安装页
$installed = is_file(APP_DIR . '/data/install.lock');
if (!$installed && $action !== 'install') {
    go(app_url('index.php?a=install'));
}
if ($installed && !in_array($action, ['install', 'cron'], true)) {
    // 加载已启用插件
    if (is_file(APP_DIR . '/optional/Plugin.php')) {
        require APP_DIR . '/optional/Plugin.php';
        plugin_bootstrap();
    }
    need_site_access();
}

// ------------------------------------------------------------
// 首页：四个页签 + 版块分组
// ------------------------------------------------------------
function home_page(): void
{
    $tab = get('tab') ?: 'latest';
    $tabs = array_filter(explode(',', setting('home_tabs', 'latest,newest,hot,featured')));
    if (!in_array($tab, $tabs, true)) {
        $tab = $tabs[0] ?? 'latest';
    }
    $perPage = (int) setting('per_page', '20');
    $pageNum = page_now();
    $where = visible_thread_where('t');
    $order = match ($tab) {
        'newest' => 't.created DESC',
        'hot' => '(t.replies*3 + t.views) DESC',
        'featured' => 't.featured DESC, t.last_reply_at DESC',
        default => 't.last_reply_at DESC',
    };
    if ($tab === 'featured') {
        $where .= ' AND t.featured=1';
    }
    $total = (int) val("SELECT COUNT(*) FROM ow_threads t WHERE $where" . ($tab === 'featured' ? '' : ''));
    $rows = all("SELECT t.* FROM ow_threads t WHERE $where ORDER BY t.pinned DESC, $order LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage));
    $rows = attach_users($rows);

    $tabLinks = [];
    foreach ($tabs as $key) {
        $labels = ['latest' => '最新评论', 'newest' => '最新发布', 'hot' => '热门', 'featured' => '精华'];
        $tabLinks[$key] = [$labels[$key] ?? $key, route_url('home', ['tab' => $key])];
    }

    $body = tabs_html($tabLinks, $tab) . '<div class="panel thread-list">';
    $body .= $rows ? '' : '<div class="empty">暂无帖子</div>';
    foreach ($rows as $thread) {
        $body .= thread_item_html($thread, $thread['_user'], $tab);
    }
    $body .= '</div>' . paginate(route_url('home', ['tab' => $tab]), $total, $perPage, $pageNum);

    page(setting('site_name'), $body, sidebar_html());
}

function visible_thread_where(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    // 待审帖子仅作者与有审核权者可见
    if (can('moderate.thread')) {
        return '1=1';
    }
    $uid = uid();
    return "({$p}status='ok'" . ($uid > 0 ? " OR {$p}user_id=$uid" : '') . ')';
}

// ------------------------------------------------------------
// 版块页
// ------------------------------------------------------------
function forum_page(): void
{
    $id = gid();
    $forum = forum_by_id($id);
    if (!$forum) {
        err('版块不存在', 404);
    }
    $perPage = (int) setting('per_page', '20');
    $pageNum = page_now();
    $sort = get('sort') ?: 'reply';
    $order = $sort === 'new' ? 't.created DESC' : 't.last_reply_at DESC';
    $where = 't.forum_id=' . $id . ' AND ' . visible_thread_where('t');
    $total = (int) val("SELECT COUNT(*) FROM ow_threads t WHERE $where");
    $rows = all("SELECT t.* FROM ow_threads t WHERE $where ORDER BY t.pinned DESC, $order LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage));
    $rows = attach_users($rows);

    $body = '<div class="panel"><div class="panel-head forum-head"><div><strong>' . h($forum['name']) . '</strong>'
        . ($forum['description'] !== '' ? '<p class="muted">' . h($forum['description']) . '</p>' : '')
        . '</div><div class="spacer"></div>'
        . (can('thread.create') ? '<a class="btn btn-primary" href="' . h(route_url('thread_new', ['forum' => $id])) . '">' . icons('plus') . '发帖</a>' : '')
        . '</div>';
    $children = forum_children($id);
    if ($children) {
        $body .= '<div class="sub-forums">子版块：';
        foreach ($children as $c) {
            $body .= '<a class="forum-tag" href="' . h(route_url('forum', ['id' => $c['id']])) . '">' . h($c['name']) . '</a>';
        }
        $body .= '</div>';
    }
    $body .= '</div>';
    $body .= tabs_html(['reply' => ['最新评论', route_url('forum', ['id' => $id])], 'new' => ['最新发布', route_url('forum', ['id' => $id, 'sort' => 'new'])]], $sort);
    $body .= '<div class="panel thread-list">';
    $body .= $rows ? '' : '<div class="empty">暂无帖子</div>';
    foreach ($rows as $thread) {
        $body .= thread_item_html($thread, $thread['_user']);
    }
    $body .= '</div>' . paginate(route_url('forum', ['id' => $id, 'sort' => $sort]), $total, $perPage, $pageNum);
    page($forum['name'], $body, sidebar_html());
}

// ------------------------------------------------------------
// 帖子详情
// ------------------------------------------------------------
function thread_page(): void
{
    $id = gid();
    $thread = thread_by_id($id);
    if (!$thread || ($thread['status'] === 'pending' && (int) $thread['user_id'] !== uid() && !can('moderate.thread'))) {
        err('帖子不存在或待审核', 404);
    }
    // 浏览量：同一会话只记一次
    if (empty($_SESSION['viewed'][$id])) {
        q('UPDATE ow_threads SET views=views+1 WHERE id=?', [$id]);
        $_SESSION['viewed'][$id] = 1;
        $thread['views']++;
    }
    $author = user_by_id((int) $thread['user_id']);
    $forum = forum_by_id((int) $thread['forum_id']);

    $hl = $thread['highlight'] !== '' && setting('title_highlight', '1') === '1' ? ' style="color:' . h($thread['highlight']) . '"' : '';
    $body = '<div class="panel thread-panel">'
        . '<div class="thread-title-row"><h1 class="thread-title"' . $hl . '>' . h($thread['title']) . '</h1>' . thread_tags_html($thread) . '</div>'
        . '<div class="thread-sub">' . ($forum ? '<a class="forum-tag" href="' . h(route_url('forum', ['id' => $forum['id']])) . '">' . h($forum['name']) . '</a>' : '')
        . '<span class="muted">' . icons('eye') . (int) $thread['views'] . ' · ' . icons('message') . (int) $thread['replies'] . '</span></div>'
        . post_html($thread + ['floor' => 0], $author ?: ['id' => 0, 'name' => '已注销', 'group_id' => 0, 'banned' => 0, 'muted_until' => 0], content_html($thread['content']), ['type' => 'thread', 'favorited' => uid() ? (bool) val('SELECT id FROM ow_favorites WHERE user_id=? AND thread_id=?', [uid(), $id]) : false])
        . '</div>';

    // 评论楼层
    $perPage = (int) setting('floor_per_page', '15');
    $pageNum = page_now();
    $where = "thread_id=$id AND " . (can('moderate.reply') ? '1=1' : "(status='ok'" . (uid() ? ' OR user_id=' . uid() : '') . ')');
    $total = (int) val("SELECT COUNT(*) FROM ow_replies WHERE $where");
    $rows = all("SELECT * FROM ow_replies WHERE $where ORDER BY floor ASC LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage));
    $rows = attach_users($rows);
    // 被评论楼层（引用）
    $parentIds = array_values(array_unique(array_filter(array_map(fn($r) => (int) $r['parent_id'], $rows))));
    $parents = [];
    if ($parentIds) {
        $marks = implode(',', array_fill(0, count($parentIds), '?'));
        foreach (all("SELECT r.*, u.name AS author_name FROM ow_replies r LEFT JOIN ow_users u ON u.id=r.user_id WHERE r.id IN ($marks)", $parentIds) as $p) {
            $parents[(int) $p['id']] = $p;
        }
    }
    if ($rows) {
        $body .= '<div class="panel reply-list"><div class="panel-head"><strong>全部评论</strong><span class="muted">共 ' . $total . ' 条</span></div>';
        foreach ($rows as $reply) {
            $quote = null;
            if ((int) $reply['parent_id'] > 0 && isset($parents[(int) $reply['parent_id']])) {
                $p = $parents[(int) $reply['parent_id']];
                $quote = ['name' => $p['author_name'] ?? '已注销', 'floor' => $p['floor'], 'content' => $p['content']];
            }
            $body .= post_html($reply, $reply['_user'] ?: ['id' => 0, 'name' => '已注销', 'group_id' => 0, 'banned' => 0, 'muted_until' => 0], content_html($reply['content']), ['type' => 'reply', 'quote' => $quote]);
        }
        $body .= '</div>' . paginate(route_url('thread', ['id' => $id]), $total, $perPage, $pageNum);
    }

    // 评论框
    if ((int) $thread['locked'] === 1) {
        $body .= '<div class="panel"><div class="panel-body muted">' . icons('lock') . '帖子已锁定，无法评论</div></div>';
    } elseif (can('reply.create')) {
        $body .= '<div class="panel" id="reply-box"><div class="panel-head"><strong>发表评论</strong></div><div class="panel-body">'
            . '<form method="post" action="' . h(route_url('reply')) . '" data-ajax data-reset>'
            . form_token() . '<input type="hidden" name="thread_id" value="' . $id . '"><input type="hidden" name="parent_id" value="0" data-parent-id>'
            . '<div class="reply-tip" data-reply-tip hidden></div>'
            . editor_widget('content', '', 'reply-' . $id)
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">发表</button></div></form></div></div>';
    } elseif (!me()) {
        $body .= '<div class="panel"><div class="panel-body muted"><a href="' . h(route_url('login')) . '">登录</a> 后参与评论</div></div>';
    }
    page($thread['title'], $body, sidebar_html());
}

// ------------------------------------------------------------
// 发帖 / 编辑
// ------------------------------------------------------------
function thread_new_page(): void
{
    need_speak();
    if (!can('thread.create')) {
        err('没有发帖权限', 403);
    }
    $preset = gid('forum');
    $options = '';
    foreach (forum_options() as $f) {
        $options .= '<option value="' . (int) $f['id'] . '"' . ((int) $f['id'] === $preset ? ' selected' : '') . '>' . h($f['name']) . '</option>';
    }
    $body = '<div class="panel"><div class="panel-head"><strong>发布帖子</strong></div><div class="panel-body">'
        . '<form method="post" action="' . h(route_url('thread_new')) . '">' . form_token()
        . '<div class="field"><label>版块</label><select name="forum_id" required>' . $options . '</select></div>'
        . '<div class="field"><label>标题</label><input type="text" name="title" required maxlength="120" placeholder="一句话说清楚"></div>'
        . '<div class="field"><label>正文</label>' . editor_widget('content', '', 'new-thread') . '</div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">发布</button></div></form></div></div>';
    page('发布帖子', $body, sidebar_html());
}

function thread_submit(): void
{
    need_speak();
    check_csrf();
    if (!can('thread.create')) {
        err('没有发帖权限', 403);
    }
    check_post_interval('thread', 'thread_interval');
    $forumId = (int) post('forum_id');
    $forum = forum_by_id($forumId);
    $title = post('title', 120);
    $content = post('content');
    if (!$forum) {
        err('版块不存在');
    }
    if (char_len($title) < 2) {
        err('标题至少 2 个字');
    }
    if (char_len($content) < 5) {
        err('正文至少 5 个字');
    }
    $needReview = setting('thread_review', '0') === '1' && !can('moderate.thread');
    $id = 0;
    tx(function () use ($forumId, $title, $content, $needReview, &$id): void {
        q('INSERT INTO ow_threads (forum_id, user_id, title, content, status, views, replies, last_reply_at, last_reply_user, created, edited_at) VALUES (?,?,?,?,?,0,0,0,0,?,0)',
            [$forumId, uid(), $title, $content, $needReview ? 'pending' : 'ok', now()]);
        $id = last_id('ow_threads');
        q('UPDATE ow_users SET threads=threads+1 WHERE id=?', [uid()]);
    });
    recount_forum($forumId);
    rate_hit('thread');
    // @提及 通知
    foreach (mention_targets($content, uid()) as $target) {
        notify_create((int) $target['id'], uid(), 'mention', '在帖子「' . cut($title, 40) . '」中提到了你', $id);
    }
    hook_run('thread.after_create', ['id' => $id]);
    log_action('thread.create', '发布帖子 #' . $id . '「' . cut($title, 40) . '」');
    if ($needReview) {
        set_flash('帖子已提交，待审核后公开显示');
    }
    go(route_url('thread', ['id' => $id]));
}

function topic_edit_page(): void
{
    need_login();
    $thread = thread_by_id(gid());
    if (!$thread || !can_manage_thread($thread)) {
        err('没有编辑权限', 403);
    }
    if (is_post()) {
        check_csrf();
        $title = post('title', 120);
        $content = post('content');
        if (char_len($title) < 2 || char_len($content) < 5) {
            err('标题或正文太短');
        }
        q('UPDATE ow_threads SET title=?, content=?, edited_at=? WHERE id=?', [$title, $content, now(), $thread['id']]);
        log_action('thread.edit', '编辑帖子 #' . $thread['id']);
        go(route_url('thread', ['id' => $thread['id']]));
    }
    $options = '';
    foreach (forum_options() as $f) {
        $options .= '<option value="' . (int) $f['id'] . '"' . ((int) $f['id'] === (int) $thread['forum_id'] ? ' selected' : '') . '>' . h($f['name']) . '</option>';
    }
    $body = '<div class="panel"><div class="panel-head"><strong>编辑帖子</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<div class="field"><label>版块</label><select disabled><option>' . h(forum_by_id((int) $thread['forum_id'])['name'] ?? '') . '</option></select><p class="muted">移动版块请使用帖子的管理操作</p></div>'
        . '<div class="field"><label>标题</label><input type="text" name="title" required maxlength="120" value="' . h($thread['title']) . '"></div>'
        . '<div class="field"><label>正文</label>' . editor_widget('content', $thread['content']) . '</div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button> <a class="btn btn-ghost" href="' . h(route_url('thread', ['id' => $thread['id']])) . '">取消</a></div></form></div></div>';
    page('编辑帖子', $body, sidebar_html());
}

function reply_submit(): void
{
    need_speak();
    check_csrf();
    if (!can('reply.create')) {
        err('没有评论权限', 403);
    }
    check_post_interval('reply', 'reply_interval');
    $thread = thread_by_id((int) post('thread_id'));
    if (!$thread) {
        err('帖子不存在');
    }
    if ((int) $thread['locked'] === 1 && !can_manage_thread($thread)) {
        err('帖子已锁定', 403);
    }
    $content = post('content');
    if (char_len($content) < 2) {
        err('评论至少 2 个字');
    }
    $parentId = (int) post('parent_id');
    $parent = $parentId > 0 ? one('SELECT * FROM ow_replies WHERE id=? AND thread_id=?', [$parentId, $thread['id']]) : null;
    if ($parentId > 0 && !$parent) {
        err('被评论的楼层不存在');
    }
    $needReview = setting('reply_review', '0') === '1' && !can('moderate.reply');
    $id = 0;
    tx(function () use ($thread, $content, $parent, $needReview, &$id): void {
        $floor = (int) val('SELECT COALESCE(MAX(floor),0)+1 FROM ow_replies WHERE thread_id=?', [$thread['id']]);
        q('INSERT INTO ow_replies (thread_id, user_id, parent_id, floor, content, status, likes, created, edited_at) VALUES (?,?,?,?,?,?,0,?,0)',
            [$thread['id'], uid(), $parent ? (int) $parent['id'] : 0, $floor, $content, $needReview ? 'pending' : 'ok', now()]);
        $id = last_id('ow_replies');
        q('UPDATE ow_users SET replies=replies+1 WHERE id=?', [uid()]);
    });
    recount_thread((int) $thread['id']);
    recount_forum((int) $thread['forum_id']);
    rate_hit('reply');
    // 通知：帖子作者 + 被评论者 + @提及
    notify_create((int) $thread['user_id'], uid(), 'reply', '评论了你的帖子「' . cut($thread['title'], 40) . '」', (int) $thread['id'], $id);
    if ($parent) {
        notify_create((int) $parent['user_id'], uid(), 'reply', '评论了你在「' . cut($thread['title'], 40) . '」的楼层', (int) $thread['id'], $id);
    }
    foreach (mention_targets($content, uid()) as $target) {
        notify_create((int) $target['id'], uid(), 'mention', '在「' . cut($thread['title'], 40) . '」的评论中提到了你', (int) $thread['id'], $id);
    }
    hook_run('reply.after_create', ['id' => $id, 'thread_id' => $thread['id']]);
    log_action('reply.create', '评论 #' . $id . ' @帖子 #' . $thread['id']);
    if ($needReview) {
        set_flash('评论已提交，待审核后公开显示');
    }
    if (is_ajax()) {
        json_out(['ok' => true, 'redirect' => route_url('thread', ['id' => $thread['id']]) . '#p' . $id]);
    }
    go(route_url('thread', ['id' => $thread['id']]) . '#p' . $id);
}

function reply_edit_page(): void
{
    need_login();
    $reply = one('SELECT * FROM ow_replies WHERE id=?', [gid()]);
    if (!$reply || !can_manage_reply($reply)) {
        err('没有编辑权限', 403);
    }
    if (is_post()) {
        check_csrf();
        $content = post('content');
        if (char_len($content) < 2) {
            err('评论至少 2 个字');
        }
        q('UPDATE ow_replies SET content=?, edited_at=? WHERE id=?', [$content, now(), $reply['id']]);
        log_action('reply.edit', '编辑评论 #' . $reply['id']);
        go(route_url('thread', ['id' => $reply['thread_id']]) . '#p' . $reply['id']);
    }
    $body = '<div class="panel"><div class="panel-head"><strong>编辑评论</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . editor_widget('content', $reply['content'])
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button> <a class="btn btn-ghost" href="' . h(route_url('thread', ['id' => $reply['thread_id']])) . '">取消</a></div></form></div></div>';
    page('编辑评论', $body, sidebar_html());
}

// ------------------------------------------------------------
// 删除 / 管理操作 / 点赞 / 收藏
// ------------------------------------------------------------
function delete_route(): void
{
    need_login();
    check_csrf();
    $type = post('type');
    $id = (int) post('id');
    if ($type === 'thread') {
        $thread = thread_by_id($id);
        if (!$thread || !can_delete_thread($thread)) {
            err('没有删除权限', 403);
        }
        delete_thread($id, uid());
        set_flash('帖子已移入回收站');
        go(route_url('forum', ['id' => $thread['forum_id']]));
    }
    if ($type === 'reply') {
        $reply = one('SELECT * FROM ow_replies WHERE id=?', [$id]);
        if (!$reply || !can_delete_reply($reply)) {
            err('没有删除权限', 403);
        }
        delete_reply($id, uid());
        set_flash('评论已移入回收站');
        go(route_url('thread', ['id' => $reply['thread_id']]));
    }
    err('参数错误');
}

function moderate_route(): void
{
    need_login();
    check_csrf();
    $type = post('type');
    $id = (int) post('id');
    $do = post('do');
    if ($type === 'thread') {
        $thread = thread_by_id($id);
        if (!$thread) {
            err('帖子不存在');
        }
        if (in_array($do, ['pinned', 'featured', 'locked'], true)) {
            if (!can('moderate.thread') || !moderates_forum((int) $thread['forum_id'])) {
                err('没有管理权限', 403);
            }
            q('UPDATE ow_threads SET ' . ident($do) . '=1-' . ident($do) . ' WHERE id=?', [$id]);
            $row = thread_by_id($id);
            log_action('thread.moderate', $do . ' 帖子 #' . $id);
            if (is_ajax()) {
                json_out(['ok' => true, 'state' => (int) $row[$do]]);
            }
            go(route_url('thread', ['id' => $id]));
        }
        if ($do === 'highlight' && can('moderate.highlight')) {
            $color = post('color', 20);
            q('UPDATE ow_threads SET highlight=? WHERE id=?', [$color, $id]);
            log_action('thread.highlight', '高亮帖子 #' . $id . ' ' . $color);
            go(route_url('thread', ['id' => $id]));
        }
        if ($do === 'move') {
            if (!can('moderate.thread') || !moderates_forum((int) $thread['forum_id'])) {
                err('没有管理权限', 403);
            }
            $to = (int) post('forum_id');
            if (!forum_by_id($to)) {
                err('目标版块不存在');
            }
            q('UPDATE ow_threads SET forum_id=? WHERE id=?', [$to, $id]);
            recount_forum((int) $thread['forum_id']);
            recount_forum($to);
            log_action('thread.move', '移动帖子 #' . $id . ' 到版块 #' . $to);
            go(route_url('thread', ['id' => $id]));
        }
        if ($do === 'approve' && can('moderate.thread')) {
            q('UPDATE ow_threads SET status=\'ok\' WHERE id=?', [$id]);
            recount_forum((int) $thread['forum_id']);
            log_action('thread.approve', '通过帖子 #' . $id);
            go(route_url('thread', ['id' => $id]));
        }
    }
    if ($type === 'reply' && $do === 'approve') {
        $reply = one('SELECT * FROM ow_replies WHERE id=?', [$id]);
        if ($reply && can('moderate.reply')) {
            q('UPDATE ow_replies SET status=\'ok\' WHERE id=?', [$id]);
            recount_thread((int) $reply['thread_id']);
            log_action('reply.approve', '通过评论 #' . $id);
            go(route_url('thread', ['id' => $reply['thread_id']]) . '#p' . $id);
        }
    }
    err('参数错误');
}

function like_route(): void
{
    check_csrf();
    $result = like_toggle(post('type'), (int) post('id'));
    if (is_ajax()) {
        json_out(['ok' => true] + $result);
    }
    go(route_url('thread', ['id' => post('type') === 'thread' ? (int) post('id') : (int) (one('SELECT thread_id FROM ow_replies WHERE id=?', [(int) post('id')])['thread_id'] ?? 0)]));
}

function favorite_route(): void
{
    check_csrf();
    $result = favorite_toggle((int) post('id'));
    if (is_ajax()) {
        json_out(['ok' => true] + $result);
    }
    go(route_url('thread', ['id' => (int) post('id')]));
}

// ------------------------------------------------------------
// 登录 / 注册 / 找回密码
// ------------------------------------------------------------
function login_page(): void
{
    if (me()) {
        go(route_url('home'));
    }
    if (is_post()) {
        check_csrf();
        if (setting('login_captcha', '1') === '1') {
            captcha_check();
        }
        $login = post('login', 60);
        $pass = (string) ($_POST['pass'] ?? '');
        $user = user_by_name($login) ?: one('SELECT * FROM ow_users WHERE email=?', [$login]);
        if (!$user || !password_verify($pass, $user['pass'])) {
            log_action('login.fail', '登录失败：' . $login);
            err('用户名或密码错误');
        }
        if ((int) $user['banned'] === 1) {
            err('账号已被限制访问', 403);
        }
        auth_cookie_set((int) $user['id'], $user['pass'], post('remember') === '1');
        log_action('login.ok', '登录成功', (int) $user['id']);
        $back = post('back') ?: route_url('home');
        go(str_starts_with($back, '/') || str_starts_with($back, app_url('')) ? $back : route_url('home'));
    }
    $body = '<div class="panel narrow"><div class="panel-head"><strong>登录</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="back" value="' . h(get('back')) . '">'
        . '<div class="field"><label>用户名或邮箱</label><input type="text" name="login" required autocomplete="username"></div>'
        . '<div class="field"><label>密码</label><input type="password" name="pass" required autocomplete="current-password"></div>'
        . (setting('login_captcha', '1') === '1' ? captcha_field() : '')
        . '<div class="field check"><label><input type="checkbox" name="remember" value="1" checked> 保持登录 30 天</label></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary btn-block">登录</button></div>'
        . '<p class="muted"><a href="' . h(route_url('forgot')) . '">忘记密码？</a>'
        . (setting('register_open', '1') === '1' ? ' · <a href="' . h(route_url('register')) . '">注册新账号</a>' : '') . '</p>'
        . '</form></div></div>';
    page('登录', $body);
}

function register_page(): void
{
    if (setting('register_open', '1') !== '1') {
        err('本站已关闭注册', 403);
    }
    if (me()) {
        go(route_url('home'));
    }
    if (is_post()) {
        check_csrf();
        if (setting('register_captcha', '1') === '1') {
            captcha_check();
        }
        $name = post('name', 30);
        $email = post('email', 190);
        $pass = (string) ($_POST['pass'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,30}$/u', $name)) {
            err('用户名需为 2-30 位字母、数字、下划线或中文');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            err('邮箱格式不正确');
        }
        if (strlen($pass) < 6) {
            err('密码至少 6 位');
        }
        if (user_by_name($name)) {
            err('用户名已被使用');
        }
        if (one('SELECT id FROM ow_users WHERE email=?', [$email])) {
            err('邮箱已被注册');
        }
        q('INSERT INTO ow_users (name, email, pass, group_id, created, last_active) VALUES (?,?,?,3,?,?)',
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT), now(), now()]);
        $id = last_id('ow_users');
        log_action('user.register', '注册：' . $name, $id);
        $user = user_by_id($id);
        auth_cookie_set($id, $user['pass'], true);
        go(route_url('home'));
    }
    $body = '<div class="panel narrow"><div class="panel-head"><strong>注册</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<div class="field"><label>用户名</label><input type="text" name="name" required maxlength="30" autocomplete="username"></div>'
        . '<div class="field"><label>邮箱</label><input type="email" name="email" required autocomplete="email"></div>'
        . '<div class="field"><label>密码</label><input type="password" name="pass" required minlength="6" autocomplete="new-password"></div>'
        . (setting('register_captcha', '1') === '1' ? captcha_field() : '')
        . '<div class="form-actions"><button type="submit" class="btn btn-primary btn-block">注册</button></div>'
        . '<p class="muted">已有账号？<a href="' . h(route_url('login')) . '">直接登录</a></p>'
        . '</form></div></div>';
    page('注册', $body);
}

function forgot_page(): void
{
    if (is_post()) {
        check_csrf();
        $login = post('login', 60);
        $user = user_by_name($login) ?: one('SELECT * FROM ow_users WHERE email=?', [$login]);
        if ($user) {
            $token = bin2hex(random_bytes(16));
            q('UPDATE ow_users SET reset_token=?, reset_expires=? WHERE id=?', [$token, now() + 3600, $user['id']]);
            log_action('user.reset_request', '申请重置密码：' . $user['name']);
            // 低成本部署无邮件服务：重置链接直接展示（生产环境建议改接邮件）
            $link = app_url('index.php?a=reset&token=' . $token);
            $body = '<div class="panel narrow"><div class="panel-head"><strong>重置密码</strong></div><div class="panel-body">'
                . '<p>重置链接已生成（1 小时内有效）：</p><p><a class="reset-link" href="' . h($link) . '">' . h($link) . '</a></p>'
                . '<p class="muted">站点未配置邮件服务，请直接点击上方链接。若不是你本人操作，请忽略。</p></div></div>';
            page('重置密码', $body);
        }
        err('找不到该用户');
    }
    $body = '<div class="panel narrow"><div class="panel-head"><strong>找回密码</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<div class="field"><label>用户名或邮箱</label><input type="text" name="login" required></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary btn-block">生成重置链接</button></div>'
        . '</form></div></div>';
    page('找回密码', $body);
}

function reset_page(): void
{
    $token = get('token');
    $user = $token !== '' ? one('SELECT * FROM ow_users WHERE reset_token=? AND reset_expires>?', [$token, now()]) : null;
    if (!$user) {
        err('重置链接无效或已过期', 403);
    }
    if (is_post()) {
        check_csrf();
        $pass = (string) ($_POST['pass'] ?? '');
        if (strlen($pass) < 6) {
            err('密码至少 6 位');
        }
        q('UPDATE ow_users SET pass=?, reset_token=\'\', reset_expires=0 WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        auth_cookie_clear();
        log_action('user.reset_done', '重置密码成功', (int) $user['id']);
        set_flash('密码已重置，请用新密码登录');
        go(route_url('login'));
    }
    $body = '<div class="panel narrow"><div class="panel-head"><strong>设置新密码</strong></div><div class="panel-body">'
        . '<form method="post">' . form_token()
        . '<div class="field"><label>新密码</label><input type="password" name="pass" required minlength="6" autocomplete="new-password"></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary btn-block">保存</button></div>'
        . '</form></div></div>';
    page('设置新密码', $body);
}

function logout_route(): void
{
    auth_cookie_clear();
    session_destroy();
    go(route_url('home'));
}

// ------------------------------------------------------------
// 用户主页（主页/帖子/评论/收藏 四个页签）
// ------------------------------------------------------------
function user_page(): void
{
    if (!can('user.view')) {
        err('没有查看权限', 403);
    }
    $user = user_by_id(gid());
    if (!$user) {
        err('用户不存在', 404);
    }
    $tab = get('tab') ?: 'threads';
    $me = me();
    $isSelf = $me && (int) $me['id'] === (int) $user['id'];
    $privacy = json_decode((string) ($user['privacy'] ?? ''), true) ?: [];
    $perPage = (int) setting('per_page', '20');
    $pageNum = page_now();

    $cover = $user['cover'] !== '' ? ' style="background-image:url(\'' . h(app_url('index.php?a=cover&id=' . $user['id'])) . '\')"' : '';
    $body = '<div class="panel profile-head"><div class="profile-cover"' . $cover . '></div>'
        . '<div class="profile-main">' . avatar_tag((int) $user['id'], $user['name'], 'xl')
        . '<div class="profile-info"><h2>' . h($user['name']) . ' ' . user_state_tags($user) . '</h2>'
        . ($user['bio'] !== '' ? '<p>' . h($user['bio']) . '</p>' : '')
        . '<p class="muted">加入于 ' . date('Y-m-d', (int) $user['created']) . ' · 帖子 ' . (int) $user['threads'] . ' · 评论 ' . (int) $user['replies'] . '</p></div>'
        . '<div class="spacer"></div>' . ($isSelf ? '<a class="btn btn-ghost" href="' . h(route_url('profile')) . '">' . icons('settings') . '编辑资料</a>' : '')
        . '</div></div>';

    $tabs = ['threads' => ['帖子', route_url('user', ['id' => $user['id']])], 'replies' => ['评论', route_url('user', ['id' => $user['id'], 'tab' => 'replies'])]];
    if ($isSelf || ($privacy['favorites'] ?? '1') === '1') {
        $tabs['favorites'] = ['收藏', route_url('user', ['id' => $user['id'], 'tab' => 'favorites'])];
    }
    $body .= tabs_html($tabs, $tab);

    if ($tab === 'threads') {
        $total = (int) val('SELECT COUNT(*) FROM ow_threads WHERE user_id=?', [$user['id']]);
        $rows = attach_users(all('SELECT * FROM ow_threads WHERE user_id=? ORDER BY created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage), [$user['id']]));
        $body .= '<div class="panel thread-list">';
        foreach ($rows as $t) {
            $body .= thread_item_html($t, $t['_user']);
        }
        $body .= ($rows ? '' : '<div class="empty">暂无帖子</div>') . '</div>' . paginate(route_url('user', ['id' => $user['id']]), $total, $perPage, $pageNum);
    } elseif ($tab === 'replies') {
        $total = (int) val('SELECT COUNT(*) FROM ow_replies WHERE user_id=?', [$user['id']]);
        $rows = all('SELECT r.*, t.title AS thread_title FROM ow_replies r JOIN ow_threads t ON t.id=r.thread_id WHERE r.user_id=? ORDER BY r.created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage), [$user['id']]);
        $body .= '<div class="panel"><ul class="simple-list">';
        foreach ($rows as $r) {
            $body .= '<li><a href="' . h(route_url('thread', ['id' => $r['thread_id']]) . '#p' . $r['id']) . '">' . h(content_excerpt($r['content'], 80)) . '</a><span class="muted">' . h(cut($r['thread_title'], 30)) . ' · ' . human_time((int) $r['created']) . '</span></li>';
        }
        $body .= ($rows ? '' : '<li class="empty">暂无评论</li>') . '</ul></div>' . paginate(route_url('user', ['id' => $user['id'], 'tab' => 'replies']), $total, $perPage, $pageNum);
    } else {
        $total = (int) val('SELECT COUNT(*) FROM ow_favorites WHERE user_id=?', [$user['id']]);
        $rows = all('SELECT t.*, u.name AS author_name, u.id AS author_id FROM ow_favorites f JOIN ow_threads t ON t.id=f.thread_id LEFT JOIN ow_users u ON u.id=t.user_id WHERE f.user_id=? ORDER BY f.created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage), [$user['id']]);
        $body .= '<div class="panel thread-list">';
        foreach ($rows as $t) {
            $body .= thread_item_html($t, $t['author_id'] ? ['id' => $t['author_id'], 'name' => $t['author_name']] : null);
        }
        $body .= ($rows ? '' : '<div class="empty">暂无收藏</div>') . '</div>' . paginate(route_url('user', ['id' => $user['id'], 'tab' => 'favorites']), $total, $perPage, $pageNum);
    }
    page($user['name'], $body, sidebar_html());
}

// ------------------------------------------------------------
// 账号设置：资料/账号/头像/封面/隐私/外观/密码/令牌
// ------------------------------------------------------------
function profile_page(): void
{
    need_login();
    $u = me();
    $tab = get('tab') ?: 'profile';
    $tabs = [
        'profile' => ['资料', route_url('profile')],
        'account' => ['账号', route_url('profile', ['tab' => 'account'])],
        'avatar' => ['头像', route_url('profile', ['tab' => 'avatar'])],
        'cover' => ['封面', route_url('profile', ['tab' => 'cover'])],
        'privacy' => ['隐私', route_url('profile', ['tab' => 'privacy'])],
        'theme' => ['外观', route_url('profile', ['tab' => 'theme'])],
        'password' => ['密码', route_url('profile', ['tab' => 'password'])],
        'token' => ['API 令牌', route_url('profile', ['tab' => 'token'])],
    ];
    $body = tabs_html($tabs, $tab) . '<div class="panel"><div class="panel-body">';

    if (is_post()) {
        check_csrf();
        if ($tab === 'profile') {
            q('UPDATE ow_users SET bio=?, signature=? WHERE id=?', [post('bio', 200), post('signature', 200), uid()]);
            set_flash('资料已保存');
        } elseif ($tab === 'account') {
            $name = post('name', 30);
            $email = post('email', 190);
            if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,30}$/u', $name)) {
                err('用户名格式不正确');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                err('邮箱格式不正确');
            }
            $dup = user_by_name($name);
            if ($dup && (int) $dup['id'] !== uid()) {
                err('用户名已被使用');
            }
            $dupE = one('SELECT id FROM ow_users WHERE email=?', [$email]);
            if ($dupE && (int) $dupE['id'] !== uid()) {
                err('邮箱已被使用');
            }
            q('UPDATE ow_users SET name=?, email=? WHERE id=?', [$name, $email, uid()]);
            log_action('user.account', '修改账号信息');
            set_flash('账号信息已保存');
        } elseif ($tab === 'avatar') {
            if (!empty($_FILES['avatar']['tmp_name'])) {
                $f = $_FILES['avatar'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    err('头像仅支持 png/jpg/gif/webp');
                }
                if ($f['size'] > 2 * 1024 * 1024) {
                    err('头像不能超过 2MB');
                }
                $mime = mime_content_type($f['tmp_name']) ?: '';
                if (!str_starts_with($mime, 'image/')) {
                    err('文件不是图片');
                }
                $dir = APP_DIR . '/avatars';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $file = uid() . '_' . substr(md5((string) now()), 0, 8) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $dir . '/' . $file);
                q('UPDATE ow_users SET avatar=? WHERE id=?', [$file, uid()]);
                set_flash('头像已更新');
            } elseif (post('seed') !== '') {
                q('UPDATE ow_users SET avatar=? WHERE id=?', ['seed:' . post('seed', 40), uid()]);
                set_flash('头像已更新');
            }
        } elseif ($tab === 'cover') {
            if (!empty($_FILES['cover']['tmp_name'])) {
                $f = $_FILES['cover'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                    err('封面仅支持 png/jpg/webp');
                }
                if ($f['size'] > 5 * 1024 * 1024) {
                    err('封面不能超过 5MB');
                }
                $dir = APP_DIR . '/avatars';
                $file = 'cover_' . uid() . '_' . substr(md5((string) now()), 0, 8) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $dir . '/' . $file);
                q('UPDATE ow_users SET cover=? WHERE id=?', [$file, uid()]);
                set_flash('封面已更新');
            } elseif (post('remove') === '1') {
                q('UPDATE ow_users SET cover=\'\' WHERE id=?', [uid()]);
                set_flash('封面已移除');
            }
        } elseif ($tab === 'privacy') {
            $privacy = ['favorites' => post('favorites') === '1' ? '1' : '0'];
            q('UPDATE ow_users SET privacy=? WHERE id=?', [json_encode($privacy), uid()]);
            set_flash('隐私设置已保存');
        } elseif ($tab === 'theme') {
            $mode = post('theme');
            if (in_array($mode, ['light', 'dark', 'auto'], true)) {
                q('UPDATE ow_users SET theme=? WHERE id=?', [$mode, uid()]);
                set_cookie_raw('owlsgo_theme', $mode, now() + 86400 * 365, false);
                set_flash('外观已保存');
            }
        } elseif ($tab === 'password') {
            $old = (string) ($_POST['old'] ?? '');
            $new = (string) ($_POST['new'] ?? '');
            if (!password_verify($old, $u['pass'])) {
                err('当前密码不正确');
            }
            if (strlen($new) < 6) {
                err('新密码至少 6 位');
            }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            q('UPDATE ow_users SET pass=? WHERE id=?', [$hash, uid()]);
            auth_cookie_set(uid(), $hash, true);
            log_action('user.password', '修改密码');
            set_flash('密码已修改');
        } elseif ($tab === 'token') {
            if (post('create') !== '') {
                $token = 'owt_' . bin2hex(random_bytes(20));
                q('INSERT INTO ow_api_tokens (user_id, name, token, created) VALUES (?,?,?,?)', [uid(), post('create', 40) ?: '默认', $token, now()]);
                set_flash('令牌已创建：' . $token . '（只显示这一次）');
            } elseif (($del = (int) post('delete')) > 0) {
                q('DELETE FROM ow_api_tokens WHERE id=? AND user_id=?', [$del, uid()]);
                set_flash('令牌已删除');
            }
        }
        go(route_url('profile', ['tab' => $tab]));
    }

    $u = me(); // 重新读取
    if ($tab === 'profile') {
        $body .= '<form method="post">' . form_token()
            . '<div class="field"><label>个人简介</label><textarea name="bio" rows="3" maxlength="200">' . h($u['bio']) . '</textarea></div>'
            . '<div class="field"><label>签名</label><textarea name="signature" rows="2" maxlength="200">' . h($u['signature']) . '</textarea></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form>';
    } elseif ($tab === 'account') {
        $body .= '<form method="post">' . form_token()
            . '<div class="field"><label>用户名</label><input type="text" name="name" value="' . h($u['name']) . '" required maxlength="30"></div>'
            . '<div class="field"><label>邮箱</label><input type="email" name="email" value="' . h($u['email']) . '" required></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form>';
    } elseif ($tab === 'avatar') {
        $body .= '<div class="avatar-edit"><div class="avatar-current">' . avatar_tag(uid(), $u['name'], 'xl') . '<p class="muted">当前头像</p></div>'
            . '<form method="post" enctype="multipart/form-data">' . form_token()
            . '<div class="field"><label>上传新头像（≤2MB）</label><input type="file" name="avatar" accept="image/*" required></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">上传</button></div></form>'
            . '<div class="field"><label>或选择图案头像</label><div class="avatar-seeds">';
        foreach (['owl', 'night', 'amber', 'blue', 'forest', 'river', uid() . 'a', uid() . 'b'] as $seed) {
            $body .= '<form method="post" class="seed-form">' . form_token() . '<input type="hidden" name="seed" value="' . h($seed) . '">'
                . '<button type="submit" class="seed-btn" title="' . h($seed) . '"><img src="data:image/svg+xml;base64,' . base64_encode(avatar_identicon_svg($seed)) . '" alt=""></button></form>';
        }
        $body .= '</div></div></div>';
    } elseif ($tab === 'cover') {
        $body .= '<form method="post" enctype="multipart/form-data">' . form_token()
            . '<div class="field"><label>上传封面横幅（≤5MB，建议 1200×300）</label><input type="file" name="cover" accept="image/*" required></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">上传</button></div></form>'
            . ($u['cover'] !== '' ? '<form method="post">' . form_token() . '<input type="hidden" name="remove" value="1"><button type="submit" class="btn btn-ghost">移除封面</button></form>' : '');
    } elseif ($tab === 'privacy') {
        $privacy = json_decode((string) $u['privacy'], true) ?: [];
        $body .= '<form method="post">' . form_token()
            . '<div class="field check"><label><input type="checkbox" name="favorites" value="1"' . (($privacy['favorites'] ?? '1') === '1' ? ' checked' : '') . '> 公开我的收藏页签</label></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form>';
    } elseif ($tab === 'theme') {
        $body .= '<form method="post">' . form_token() . '<div class="theme-options">';
        foreach (['light' => '浅色', 'dark' => '深色', 'auto' => '跟随系统'] as $mode => $label) {
            $body .= '<label class="theme-option"><input type="radio" name="theme" value="' . $mode . '"' . ($u['theme'] === $mode ? ' checked' : '') . '><span>' . icons($mode === 'dark' ? 'moon' : 'sun') . $label . '</span></label>';
        }
        $body .= '</div><div class="form-actions"><button type="submit" class="btn btn-primary">保存</button></div></form>';
    } elseif ($tab === 'password') {
        $body .= '<form method="post">' . form_token()
            . '<div class="field"><label>当前密码</label><input type="password" name="old" required autocomplete="current-password"></div>'
            . '<div class="field"><label>新密码</label><input type="password" name="new" required minlength="6" autocomplete="new-password"></div>'
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">修改密码</button></div></form>';
    } else {
        $tokens = all('SELECT * FROM ow_api_tokens WHERE user_id=? ORDER BY created DESC', [uid()]);
        $body .= '<form method="post" class="inline-create">' . form_token()
            . '<input type="text" name="create" placeholder="令牌备注" maxlength="40"> <button type="submit" class="btn btn-primary">创建令牌</button></form>'
            . '<p class="muted">API 调用：GET ' . h(app_url('index.php?a=api&do=latest&token=你的令牌')) . '</p><ul class="simple-list">';
        foreach ($tokens as $t) {
            $body .= '<li><span>' . h($t['name']) . ' <code>' . h(substr($t['token'], 0, 10)) . '…</code></span>'
                . '<form method="post" class="inline-form">' . form_token() . '<input type="hidden" name="delete" value="' . (int) $t['id'] . '"><button type="submit" class="op-btn danger" data-confirm="删除该令牌？">' . icons('trash') . '</button></form></li>';
        }
        $body .= ($tokens ? '' : '<li class="empty">暂无令牌</li>') . '</ul>';
    }
    $body .= '</div></div>';
    page('账号设置', $body, sidebar_html());
}

// ------------------------------------------------------------
// 通知中心与公告
// ------------------------------------------------------------
function notifications_page(): void
{
    need_login();
    $perPage = (int) setting('per_page', '20');
    $pageNum = page_now();
    $total = (int) val('SELECT COUNT(*) FROM ow_notifications WHERE user_id=?', [uid()]);
    $rows = all('SELECT n.*, u.name AS sender_name FROM ow_notifications n LEFT JOIN ow_users u ON u.id=n.sender_id WHERE n.user_id=? ORDER BY n.created DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNum - 1) * $perPage), [uid()]);
    $body = '<div class="panel"><div class="panel-head"><strong>通知</strong>'
        . '<div class="spacer"></div>'
        . '<form method="post" action="' . h(route_url('notify_read')) . '" data-ajax>' . form_token() . '<button type="submit" class="btn btn-ghost">全部已读</button></form></div>'
        . '<ul class="simple-list notice-list">';
    $kindLabel = ['reply' => '评论', 'like' => '点赞', 'mention' => '提及', 'system' => '系统'];
    foreach ($rows as $n) {
        $body .= '<li class="' . ((int) $n['is_read'] === 0 ? 'unread' : '') . '">'
            . '<span class="tag">' . ($kindLabel[$n['kind']] ?? $n['kind']) . '</span> '
            . '<a href="' . h(route_url('thread', ['id' => $n['thread_id']]) . ($n['reply_id'] ? '#p' . $n['reply_id'] : '')) . '">' . h($n['content']) . '</a>'
            . '<span class="muted">' . ($n['sender_name'] ? h($n['sender_name']) . ' · ' : '') . human_time((int) $n['created']) . '</span></li>';
    }
    $body .= ($rows ? '' : '<li class="empty">暂无通知</li>') . '</ul></div>'
        . paginate(route_url('notifications'), $total, $perPage, $pageNum);
    page('通知', $body, sidebar_html());
}

function notify_read_route(): void
{
    need_login();
    check_csrf();
    q('UPDATE ow_notifications SET is_read=1 WHERE user_id=?', [uid()]);
    if (is_ajax()) {
        json_out(['ok' => true]);
    }
    go(route_url('notifications'));
}

function notice_page(): void
{
    if (!can('notice.view')) {
        err('没有查看权限', 403);
    }
    $notice = one('SELECT n.*, u.name AS author_name FROM ow_notices n LEFT JOIN ow_users u ON u.id=n.user_id WHERE n.id=?', [gid()]);
    if (!$notice) {
        err('公告不存在', 404);
    }
    $body = '<div class="panel"><div class="panel-body">'
        . '<h1 class="thread-title">' . h($notice['title']) . '</h1>'
        . '<p class="muted">' . h($notice['author_name'] ?? '系统') . ' · ' . full_time((int) $notice['created']) . '</p>'
        . '<div class="post-content">' . content_html($notice['content']) . '</div></div></div>';
    page($notice['title'], $body, sidebar_html());
}

// ------------------------------------------------------------
// 搜索
// ------------------------------------------------------------
function search_page(): void
{
    if (!can('search.use')) {
        err('没有搜索权限', 403);
    }
    $kw = get('q');
    $body = '<div class="panel"><div class="panel-body"><form method="get" action="' . h(app_url('search')) . '" class="search-form">'
        . '<input type="search" name="q" value="' . h($kw) . '" placeholder="搜索帖子标题与正文" required>'
        . '<button type="submit" class="btn btn-primary">' . icons('search') . '搜索</button></form></div></div>';
    if ($kw !== '') {
        $perPage = (int) setting('per_page', '20');
        $pageNum = page_now();
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $kw) . '%';
        // LIKE 转义符各库写法不同：SQLite 字符串不转义用 '\ '，MySQL/PG 用 '\\'
        $esc = db_driver() === 'sqlite' ? " ESCAPE '\\'" : " ESCAPE '\\\\'";
        $where = visible_thread_where('t') . " AND (t.title LIKE ?$esc OR t.content LIKE ?$esc)";
        $total = (int) val("SELECT COUNT(*) FROM ow_threads t WHERE $where", [$like, $like]);
        $rows = attach_users(all("SELECT t.* FROM ow_threads t WHERE $where ORDER BY t.last_reply_at DESC LIMIT $perPage OFFSET " . (($pageNum - 1) * $perPage), [$like, $like]));
        $body .= '<div class="panel thread-list"><div class="panel-head"><strong>搜索「' . h($kw) . '」</strong><span class="muted">' . $total . ' 条结果</span></div>';
        foreach ($rows as $t) {
            $body .= thread_item_html($t, $t['_user']);
        }
        $body .= ($rows ? '' : '<div class="empty">没有找到相关帖子</div>') . '</div>'
            . paginate(app_url('search?q=' . urlencode($kw)), $total, $perPage, $pageNum);
    }
    page('搜索', $body, sidebar_html());
}

// ------------------------------------------------------------
// 附件 / 头像 / 封面 / Logo / 上传 / 预览
// ------------------------------------------------------------
function attachment_route(): void
{
    $att = one('SELECT * FROM ow_attachments WHERE id=?', [gid()]);
    if (!$att) {
        err('附件不存在', 404);
    }
    if (!can('attachment.download')) {
        err('没有下载附件的权限', 403);
    }
    $path = APP_DIR . '/upload/' . $att['path'];
    if (!is_file($path)) {
        err('文件已丢失', 404);
    }
    q('UPDATE ow_attachments SET downloads=downloads+1 WHERE id=?', [$att['id']]);
    header('Content-Type: ' . ($att['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    $inline = str_starts_with((string) $att['mime'], 'image/') || $att['mime'] === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.一-龥-]/u', '_', $att['name']) . '"');
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

function avatar_route(): void
{
    $u = user_by_id(gid());
    if (!$u) {
        http_response_code(404);
        exit;
    }
    $avatar = (string) $u['avatar'];
    if ($avatar !== '' && !str_starts_with($avatar, 'seed:')) {
        $path = APP_DIR . '/avatars/' . basename($avatar);
        if (is_file($path)) {
            header('Content-Type: ' . (mime_content_type($path) ?: 'image/png'));
            header('Cache-Control: public, max-age=604800');
            readfile($path);
            exit;
        }
    }
    $seed = str_starts_with($avatar, 'seed:') ? substr($avatar, 5) : $u['name'];
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=604800');
    echo avatar_identicon_svg($seed);
    exit;
}

function cover_route(): void
{
    $u = user_by_id(gid());
    $path = $u && $u['cover'] !== '' ? APP_DIR . '/avatars/' . basename($u['cover']) : '';
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (mime_content_type($path) ?: 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    readfile($path);
    exit;
}

function site_logo_route(): void
{
    $file = setting('site_logo');
    $path = $file !== '' ? APP_DIR . '/data/' . basename($file) : '';
    if (!$path || !is_file($path)) {
        go(app_url('app/assets/index.svg'));
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

function upload_route(): void
{
    need_login();
    check_csrf();
    if (!can('attachment.upload')) {
        err('没有上传权限', 403);
    }
    if (empty($_FILES['file'])) {
        err('没有文件');
    }
    $att = save_upload($_FILES['file'], uid());
    log_action('attachment.upload', '上传附件：' . $att['name']);
    $isImage = str_starts_with((string) $att['mime'], 'image/');
    json_out([
        'ok' => true, 'id' => (int) $att['id'], 'name' => $att['name'],
        'url' => attachment_url((int) $att['id']), 'is_image' => $isImage,
    ]);
}

function editor_preview_route(): void
{
    need_login();
    check_csrf();
    json_out(['ok' => true, 'html' => content_html(post('content'))]);
}

// ------------------------------------------------------------
// 只读 API（token 鉴权）
// ------------------------------------------------------------
function api_route(): void
{
    $token = get('token');
    $row = $token !== '' ? one('SELECT * FROM ow_api_tokens WHERE token=?', [$token]) : null;
    if (!$row) {
        json_out(['ok' => false, 'error' => 'invalid token']);
    }
    q('UPDATE ow_api_tokens SET last_used=? WHERE id=?', [now(), $row['id']]);
    $do = get('do') ?: 'latest';
    if ($do === 'latest') {
        $rows = all('SELECT id, forum_id, user_id, title, replies, views, created FROM ow_threads WHERE status=\'ok\' ORDER BY created DESC LIMIT 20');
        json_out(['ok' => true, 'data' => $rows]);
    }
    if ($do === 'thread') {
        $t = thread_by_id(gid());
        if (!$t || $t['status'] !== 'ok') {
            json_out(['ok' => false, 'error' => 'not found']);
        }
        json_out(['ok' => true, 'data' => ['id' => $t['id'], 'title' => $t['title'], 'content' => $t['content'], 'replies' => $t['replies'], 'views' => $t['views'], 'created' => $t['created']]]);
    }
    json_out(['ok' => false, 'error' => 'unknown action']);
}

function robots_route(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /app/\n";
    exit;
}

function favicon_route(): void
{
    go(site_logo_url());
}

// ------------------------------------------------------------
// 路由表
// ------------------------------------------------------------
function core_routes(): array
{
    return [
        'home' => 'home_page',
        'forum' => 'forum_page',
        'thread' => 'thread_page',
        'thread_new' => fn() => is_post() ? thread_submit() : thread_new_page(),
        'reply' => 'reply_submit',
        'thread_edit' => 'topic_edit_page',
        'reply_edit' => 'reply_edit_page',
        'delete' => 'delete_route',
        'moderate' => 'moderate_route',
        'like' => 'like_route',
        'favorite' => 'favorite_route',
        'login' => 'login_page',
        'register' => 'register_page',
        'forgot' => 'forgot_page',
        'reset' => 'reset_page',
        'logout' => 'logout_route',
        'user' => 'user_page',
        'profile' => 'profile_page',
        'notifications' => 'notifications_page',
        'notify_read' => 'notify_read_route',
        'notice' => 'notice_page',
        'search' => 'search_page',
        'attachment' => 'attachment_route',
        'avatar' => 'avatar_route',
        'cover' => 'cover_route',
        'site_logo' => 'site_logo_route',
        'upload' => 'upload_route',
        'preview' => 'editor_preview_route',
        'api' => 'api_route',
        'robots' => 'robots_route',
        'favicon' => 'favicon_route',
    ];
}

// optional 模块：插件自定义页面
function plugin_page_route(): never
{
    if (!function_exists('plugin_handle_page') || !plugin_handle_page(get('p'))) {
        err('页面不存在', 404);
    }
    exit;
}

function plugin_asset_route(): never
{
    if (!function_exists('plugin_handle_asset') || !plugin_handle_asset(get('p'), get('f'))) {
        err('资源不存在', 404);
    }
    exit;
}

$routes = core_routes();
if ($action === 'install') {
    require APP_DIR . '/optional/Setup.php';
    setup_route();
}
if ($action === 'admin') {
    require APP_DIR . '/optional/Admin.php';
    admin_route();
}
if ($action === 'cron') {
    require APP_DIR . '/optional/Cron.php';
    cron_route();
}
if ($action === 'plugin_page') {
    plugin_page_route();
}
if ($action === 'plugin_asset') {
    plugin_asset_route();
}

// 插件接管的路由
if (function_exists('plugin_route_exists') && plugin_route_exists($action)) {
    plugin_route_run($action);
}

if (isset($routes[$action])) {
    $handler = $routes[$action];
    $handler();
}

err('页面不存在', 404);
