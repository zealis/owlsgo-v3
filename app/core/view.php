<?php
// ============================================================
// 视图层：页面骨架（page）、分页、帖子/评论构件、侧栏、编辑器、
// 内联 SVG 图标。CSP script-src 'self'，页面零内联脚本。
// ============================================================

function icons(string $name): string
{
    static $set = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19c1.2-3 3.6-4.5 6.5-4.5s5.3 1.5 6.5 4.5"/><path d="M16 5.5a3.5 3.5 0 0 1 0 6.8"/><path d="M17.5 14.8c2 .6 3.5 1.9 4.2 4.2"/>',
        'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'pin' => '<path d="M9 4h6l-1 7 3 3v2H7v-2l3-3z"/><path d="M12 16v5"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.8-4.5 4.3 1.1 6-5.4-2.9-5.4 2.9 1.1-6L3.2 9.4l6.1-.8z"/>',
        'edit' => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m13.5 6.5 3 3"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6.5 7 8 20h8l1.5-13"/>',
        'reply' => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h10a6 6 0 0 1 6 6v1"/>',
        'like' => '<path d="M7 11v9H4v-9z"/><path d="M7 12l4.5-8c1.6 0 2.5 1 2.3 2.6L13 10h6a2 2 0 0 1 2 2.4l-1.3 6A2 2 0 0 1 17.7 20H7"/>',
        'fav' => '<path d="M6 4h12v17l-6-4-6 4z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'image' => '<rect x="4.5" y="5.5" width="15" height="13" rx="2"/><circle cx="9" cy="9.5" r="1.4"/><path d="m7.5 15.5 3-3 2.5 2.5 1.5-1.5 2.5 2.5"/>',
        'attach' => '<path d="m20 11-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8L12 4a4 4 0 0 1 5.7 5.7L9.5 18"/>',
        'sun' => '<circle cx="12" cy="12" r="4.5"/><path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M4.9 4.9 6.7 6.7M17.3 17.3l1.8 1.8M19.1 4.9l-1.8 1.8M6.7 17.3l-1.8 1.8"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2L10 21h4l.5-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'check' => '<path d="m4.5 12.5 5 5 10-11"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12"/><circle cx="12" cy="12" r="3"/>',
        'message' => '<path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
        'move' => '<path d="M12 2v20M2 12h20"/><path d="m8 6-4 6 4 6M16 6l4 6-4 6"/>',
        'shield' => '<path d="M12 3 5 6v5c0 5 3 8.5 7 10 4-1.5 7-5 7-10V6z"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 3v4h-4"/>',
        'warning' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.5"/>',
        'quote' => '<path d="M5 15c0-4 2-7 6-8l.5 2c-2 .8-3 2.2-3.2 4H10v6H5zM14 15c0-4 2-7 6-8l.5 2c-2 .8-3 2.2-3.2 4H19v6h-5z"/>',
        'link' => '<path d="M10 14a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 10a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7L13 17"/>',
    ];
    $path = $set[$name] ?? $set['file'];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

// ------------------------------------------------------------
// 页面骨架
// ------------------------------------------------------------
function theme_class(): string
{
    $u = me();
    $mode = $u ? (string) ($u['theme'] ?? 'auto') : (string) ($_COOKIE['owlsgo_theme'] ?? 'auto');
    if (!in_array($mode, ['light', 'dark', 'auto'], true)) {
        $mode = 'auto';
    }
    return 'theme-' . $mode;
}

function site_logo_url(): string
{
    $custom = setting('site_logo');
    if ($custom !== '') {
        return app_url('index.php?a=site_logo');
    }
    return app_url('app/assets/index.svg');
}

function page(string $title, string $body, string $sidebar = ''): never
{
    $u = me();
    $siteName = setting('site_name', 'owlsgo');
    $flash = take_flash();
    $unread = $u ? unread_count((int) $u['id']) : 0;
    $fullTitle = $title === '' ? $siteName : $title . ' - ' . $siteName;
    $layoutCls = $sidebar !== '' ? 'layout with-sidebar' : 'layout';

    $nav = '<a href="' . h(route_url('home')) . '">' . icons('home') . '<span>首页</span></a>';
    if (can('search.use')) {
        $nav .= '<a href="' . h(route_url('search')) . '">' . icons('search') . '<span>搜索</span></a>';
    }
    if ($u && can('notice.view')) {
        $nav .= '<a href="' . h(route_url('notifications')) . '">' . icons('bell') . '<span>通知</span>' . ($unread > 0 ? '<i class="badge">' . $unread . '</i>' : '') . '</a>';
    }

    $userArea = '';
    if ($u) {
        $userArea = '<div class="user-menu" data-dropdown>'
            . '<button type="button" class="avatar-btn" data-dropdown-toggle aria-label="用户菜单">' . avatar_tag((int) $u['id'], $u['name']) . '</button>'
            . '<div class="dropdown">'
            . '<a href="' . h(route_url('user', ['id' => $u['id']])) . '">' . icons('user') . '我的主页</a>'
            . '<a href="' . h(route_url('profile')) . '">' . icons('settings') . '账号设置</a>'
            . (can_admin() ? '<a href="' . h(route_url('admin')) . '">' . icons('shield') . '后台管理</a>' : '')
            . '<a href="' . h(route_url('logout')) . '" data-confirm="确定退出登录？">' . icons('logout') . '退出登录</a>'
            . '</div></div>';
    } else {
        $userArea = '<a class="btn btn-ghost" href="' . h(route_url('login')) . '">登录</a>'
            . (setting('register_open', '1') === '1' ? '<a class="btn btn-primary" href="' . h(route_url('register')) . '">注册</a>' : '');
    }

    $flashHtml = $flash !== '' ? '<div class="flash" role="status">' . h($flash) . '</div>' : '';

    echo '<!DOCTYPE html><html lang="zh-CN" class="' . theme_class() . '"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($fullTitle) . '</title>'
        . '<meta name="description" content="' . h(setting('site_desc')) . '">'
        . '<link rel="icon" href="' . h(site_logo_url()) . '" type="image/svg+xml">'
        . '<link rel="stylesheet" href="' . h(asset_url('index.css')) . '">'
        . '<script src="' . h(asset_url('index.js')) . '" defer></script>'
        . '</head><body>'
        . '<header class="site-header"><div class="wrap header-in">'
        . '<button type="button" class="icon-btn drawer-toggle" data-drawer-toggle aria-label="菜单">' . icons('menu') . '</button>'
        . '<a class="brand" href="' . h(route_url('home')) . '"><img src="' . h(site_logo_url()) . '" alt="" class="brand-logo"><span class="brand-name">' . h($siteName) . '</span></a>'
        . '<nav class="main-nav" data-drawer>' . $nav . '</nav>'
        . '<div class="header-actions">' . $userArea . '</div>'
        . '</div></header>'
        . '<div class="wrap ' . $layoutCls . '"><main class="content">' . $flashHtml . $body . '</main>'
        . ($sidebar !== '' ? '<aside class="sidebar">' . $sidebar . '</aside>' : '')
        . '</div>'
        . '<footer class="site-footer"><div class="wrap">'
        . '<p>' . h($siteName) . ' · ' . h(setting('site_desc')) . ' · Powered by owlsgo v' . APP_VERSION . '</p>'
        . (setting('icp') !== '' ? '<p>' . h(setting('icp')) . '</p>' : '')
        . (setting('footer_html') !== '' ? '<div>' . setting('footer_html') . '</div>' : '')
        . '</div></footer>'
        . '</body></html>';
    exit;
}

function avatar_tag(int $userId, string $name, string $class = ''): string
{
    return '<img class="avatar ' . h($class) . '" src="' . h(avatar_url($userId)) . '" alt="' . h($name) . '" loading="lazy">';
}

function avatar_link(int $userId, string $name, string $class = ''): string
{
    return '<a href="' . h(route_url('user', ['id' => $userId])) . '" class="avatar-link ' . h($class) . '">' . avatar_tag($userId, $name) . '</a>';
}

// ------------------------------------------------------------
// 分页（统一 .pager，放在 panel 外）
// ------------------------------------------------------------
function paginate(string $url, int $total, int $size, int $page): string
{
    $pages = max(1, (int) ceil($total / max(1, $size)));
    if ($pages <= 1) {
        return '';
    }
    $page = min($page, $pages);
    $html = '<div class="pager">';
    $mk = fn(int $p, string $label, string $cls = '') => '<a class="pager-item ' . $cls . '" href="' . h(append_query($url, ['p' => $p])) . '">' . $label . '</a>';
    $html .= $page > 1 ? $mk($page - 1, '上一页') : '<span class="pager-item disabled">上一页</span>';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $html .= $mk(1, '1') . ($start > 2 ? '<span class="pager-item dots">…</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page ? '<span class="pager-item current">' . $i . '</span>' : $mk($i, (string) $i);
    }
    if ($end < $pages) {
        $html .= ($end < $pages - 1 ? '<span class="pager-item dots">…</span>' : '') . $mk($pages, (string) $pages);
    }
    $html .= $page < $pages ? $mk($page + 1, '下一页') : '<span class="pager-item disabled">下一页</span>';
    return $html . '</div>';
}

// ------------------------------------------------------------
// 帖子列表项（状态标签跟在标题之后）
// ------------------------------------------------------------
function thread_tags_html(array $thread): string
{
    $tags = '';
    if ((int) $thread['pinned'] === 1) {
        $tags .= '<span class="tag tag-pin">置顶</span>';
    }
    if ((int) $thread['featured'] === 1) {
        $tags .= '<span class="tag tag-featured">精华</span>';
    }
    if ((int) $thread['locked'] === 1) {
        $tags .= '<span class="tag">锁定</span>';
    }
    if ($thread['status'] === 'pending') {
        $tags .= '<span class="tag tag-warn">待审核</span>';
    }
    return $tags;
}

function thread_item_html(array $thread, ?array $author, string $sort = 'reply', bool $showForum = false): string
{
    $forum = forum_by_id((int) $thread['forum_id']);
    $hl = $thread['highlight'] !== '' && setting('title_highlight', '1') === '1' ? ' style="color:' . h($thread['highlight']) . '"' : '';
    // 摘要：首帖正文去 markdown 轻符号 → 纯文本单行（参考 v1 列表版式）
    $excerpt = str_replace(["\r", "\n", "\t"], ' ', (string) ($thread['content'] ?? ''));
    $excerpt = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $excerpt);
    $excerpt = (string) preg_replace('/`{1,3}([^`]*)`{1,3}/', '$1', $excerpt);
    $excerpt = (string) preg_replace('/^#+\s*|[*_~>]+/m', '', $excerpt);
    $excerpt = cut(trim((string) preg_replace('/\s{2,}/', ' ', $excerpt)), 80);
    // 最后评论者（有评论且记录了最后回复人时展示）
    $lastUid = (int) ($thread['last_reply_user'] ?? 0);
    $lastAt = (int) ($thread['last_reply_at'] ?? 0);
    $lastHtml = '';
    if ((int) $thread['replies'] > 0 && $lastUid > 0 && $lastAt > 0) {
        $lastUser = one('SELECT id, name FROM ow_users WHERE id=?', [$lastUid]);
        if ($lastUser) {
            $lastHtml = '<span class="thread-item-last">' . user_link((int) $lastUser['id'], $lastUser['name'])
                . '<span>' . human_time($lastAt) . '</span></span>';
        }
    }
    $html = '<div class="thread-item">'
        . ($author ? avatar_link((int) $author['id'], $author['name']) : '<span class="avatar"></span>')
        . '<div class="thread-item-main">'
        . '<div class="thread-item-title"><a href="' . h(route_url('thread', ['id' => $thread['id']])) . '"' . $hl . '>' . h($thread['title']) . '</a>' . thread_tags_html($thread) . '</div>'
        . ($excerpt !== '' ? '<div class="thread-item-excerpt">' . h($excerpt) . '</div>' : '')
        . '<div class="thread-item-meta">'
        . ($author ? user_link((int) $author['id'], $author['name']) : '<span>已注销</span>')
        . '<span>' . human_time((int) $thread['created']) . '</span>'
        . '<span class="thread-item-comments"><strong>' . (int) $thread['replies'] . '</strong> 评论</span>'
        . $lastHtml
        . '</div></div>'
        . '<div class="thread-item-stats">'
        . ($showForum && $forum ? '<a class="thread-item-forum" href="' . h(route_url('forum', ['id' => $forum['id']])) . '" title="' . h($forum['name']) . '">' . h($forum['name']) . '</a>' : '')
        . '<span class="thread-item-views"><strong>' . (int) $thread['views'] . '</strong> 浏览</span>'
        . '</div></div>';
    return $html;
}

// ------------------------------------------------------------
// 帖子/评论操作按钮（带内容保护线）
// ------------------------------------------------------------
function post_ops_html(string $type, array $row, array $content): string
{
    $ops = '';
    $id = (int) $row['id'];
    $likeCount = $type === 'reply' ? (int) $row['likes'] : like_count('thread', $id);
    $likeActive = like_mine($type, $id);
    if (can('like.use')) {
        $ops .= '<form method="post" action="' . h(route_url('like')) . '" data-ajax data-state-field="like" class="inline-form">'
            . form_token() . '<input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="op-btn' . ($likeActive ? ' active' : '') . '" title="点赞">' . icons('like') . '<span data-like-count>' . $likeCount . '</span></button></form>';
    } else {
        $ops .= '<span class="op-btn disabled">' . icons('like') . $likeCount . '</span>';
    }
    if ($type === 'thread') {
        $favActive = (bool) ($content['favorited'] ?? false);
        if (can('favorite.use')) {
            $ops .= '<form method="post" action="' . h(route_url('favorite')) . '" data-ajax data-state-field="favorite" class="inline-form">'
                . form_token() . '<input type="hidden" name="id" value="' . $id . '">'
                . '<button type="submit" class="op-btn' . ($favActive ? ' active' : '') . '" title="收藏">' . icons('fav') . '</button></form>';
        }
        $canManage = can_manage_thread($row);
        if ($canManage) {
            $ops .= '<a class="op-btn" href="' . h(route_url('thread_edit', ['id' => $id])) . '" title="编辑">' . icons('edit') . '</a>';
        }
        if (can('moderate.thread') && moderates_forum((int) $row['forum_id'])) {
            foreach ([['pinned', 'pin', '置顶'], ['featured', 'star', '精华'], ['locked', 'lock', '锁定']] as [$field, $icon, $label]) {
                $on = (int) $row[$field] === 1;
                $ops .= '<form method="post" action="' . h(route_url('moderate')) . '" data-ajax class="inline-form">'
                    . form_token() . '<input type="hidden" name="type" value="thread"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="' . $field . '">'
                    . '<button type="submit" class="op-btn' . ($on ? ' active' : '') . '" title="' . $label . '">' . icons($icon) . '</button></form>';
            }
        }
        if (can_delete_thread($row)) {
            $ops .= '<form method="post" action="' . h(route_url('delete')) . '" class="inline-form">'
                . form_token() . '<input type="hidden" name="type" value="thread"><input type="hidden" name="id" value="' . $id . '">'
                . '<button type="submit" class="op-btn danger" title="删除" data-confirm="确定删除该帖子？将进入回收站。">' . icons('trash') . '</button></form>';
        }
    } else {
        $ops .= '<button type="button" class="op-btn" title="评论" data-reply-to="' . $id . '" data-floor="' . (int) $row['floor'] . '">' . icons('reply') . '</button>';
        if (can_manage_reply($row)) {
            $ops .= '<a class="op-btn" href="' . h(route_url('reply_edit', ['id' => $id])) . '" title="编辑">' . icons('edit') . '</a>';
        }
        if (can_delete_reply($row)) {
            $ops .= '<form method="post" action="' . h(route_url('delete')) . '" class="inline-form">'
                . form_token() . '<input type="hidden" name="type" value="reply"><input type="hidden" name="id" value="' . $id . '">'
                . '<button type="submit" class="op-btn danger" title="删除" data-confirm="确定删除该评论？将进入回收站。">' . icons('trash') . '</button></form>';
        }
    }
    return '<div class="post-ops">' . $ops . '</div>';
}

// ------------------------------------------------------------
// 楼层构件（首楼与评论共用 .post-content 类名）
// ------------------------------------------------------------
function post_html(array $row, array $author, string $bodyHtml, array $extra = []): string
{
    $type = $extra['type'] ?? 'reply';
    $floor = (int) ($row['floor'] ?? 0);
    $group = group_by_id((int) $author['group_id']);
    $edited = (int) ($row['edited_at'] ?? 0) > 0;
    $html = '<article class="post" id="p' . (int) $row['id'] . '">'
        . '<header class="post-head">'
        . avatar_link((int) $author['id'], $author['name'])
        . '<div class="post-head-info">'
        . '<div class="post-head-name">' . user_link((int) $author['id'], $author['name']) . user_state_tags($author) . '</div>'
        . '<div class="post-head-meta"><span title="' . full_time((int) $row['created']) . '">' . human_time((int) $row['created']) . '</span>'
        . ($edited ? '<span class="edited" title="最后编辑于 ' . full_time((int) $row['edited_at']) . '">已编辑</span>' : '')
        . '</div></div>'
        . '<div class="spacer"></div>'
        . ($floor > 0 ? '<a class="floor-no" href="#p' . (int) $row['id'] . '">' . $floor . ' 楼</a>' : '')
        . post_ops_html($type, $row, $extra)
        . '</header>';
    if (!empty($extra['quote'])) {
        $html .= '<div class="quote-ref">' . icons('quote') . '<div><strong>' . h($extra['quote']['name']) . '</strong> ' . ($extra['quote']['floor'] ?? '') . ' 楼：' . h(content_excerpt($extra['quote']['content'], 80)) . '</div></div>';
    }
    $html .= '<div class="post-content">' . $bodyHtml . '</div>';
    if (!empty($extra['attachments'])) {
        $html .= '<div class="attach-list">';
        foreach ($extra['attachments'] as $att) {
            $html .= '<a class="attach-item" href="' . h(attachment_url((int) $att['id'])) . '">' . icons('attach') . h($att['name']) . '<span>' . round($att['size'] / 1024, 1) . ' KB</span></a>';
        }
        $html .= '</div>';
    }
    return $html . '</article>';
}

// ------------------------------------------------------------
// 侧栏（唯一实现，全站共用）
// ------------------------------------------------------------
function sidebar_html(): string
{
    $html = '';
    $u = me();
    // 站点公告
    $notices = notices_pinned();
    if ($notices && can('notice.view')) {
        $html .= '<div class="widget"><h3 class="widget-title">' . icons('bell') . '公告</h3><ul class="widget-list">';
        foreach ($notices as $n) {
            $html .= '<li><a href="' . h(route_url('notice', ['id' => $n['id']])) . '">' . h($n['title']) . '</a></li>';
        }
        $html .= '</ul></div>';
    }
    // 用户信息卡
    if ($u) {
        $html .= '<div class="widget user-card">'
            . '<div class="user-card-head">' . avatar_tag((int) $u['id'], $u['name'], 'lg')
            . '<div><strong>' . h($u['name']) . '</strong><div class="muted">帖子 ' . (int) $u['threads'] . ' · 评论 ' . (int) $u['replies'] . '</div></div></div>'
            . (can('thread.create') ? '<a class="btn btn-primary btn-block" href="' . h(route_url('thread_new')) . '">' . icons('plus') . '发布帖子</a>' : '')
            . '</div>';
    } else {
        $html .= '<div class="widget"><p class="muted">' . h(setting('notice_guide')) . '</p>'
            . '<a class="btn btn-primary btn-block" href="' . h(route_url('login')) . '">加入讨论</a></div>';
    }
    // 版块导航
    $forums = forum_children(0);
    if ($forums) {
        $html .= '<div class="widget"><h3 class="widget-title">' . icons('home') . '版块</h3><ul class="widget-list">';
        foreach ($forums as $f) {
            $html .= '<li><a href="' . h(route_url('forum', ['id' => $f['id']])) . '">' . h($f['name']) . '</a><span class="muted">' . (int) $f['threads'] . '</span></li>';
        }
        $html .= '</ul></div>';
    }
    // 站点统计
    $stats = [
        '帖子' => (int) val('SELECT COUNT(*) FROM ow_threads'),
        '评论' => (int) val('SELECT COUNT(*) FROM ow_replies'),
        '用户' => (int) val('SELECT COUNT(*) FROM ow_users'),
    ];
    $html .= '<div class="widget"><h3 class="widget-title">' . icons('file') . '站点统计</h3><div class="stat-grid">';
    foreach ($stats as $label => $num) {
        $html .= '<div class="stat"><strong>' . $num . '</strong><span>' . $label . '</span></div>';
    }
    $html .= '</div></div>';
    return $html;
}

// ------------------------------------------------------------
// 编辑器（草稿 localStorage、已提交标记 sessionStorage、附件上传）
// ------------------------------------------------------------
function editor_widget(string $name, string $value = '', string $draftKey = ''): string
{
    $html = '<div class="editor" data-editor data-draft-key="' . h($draftKey) . '">'
        . '<div class="editor-bar">'
        . '<button type="button" data-md="**粗体**" title="粗体"><strong>B</strong></button>'
        . '<button type="button" data-md="*斜体*" title="斜体"><em>I</em></button>'
        . '<button type="button" data-md="~~删除线~~" title="删除线"><del>S</del></button>'
        . '<button type="button" data-md-block="> 引用" title="引用">' . icons('quote') . '</button>'
        . '<button type="button" data-md-block="```\n代码\n```" title="代码">&lt;/&gt;</button>'
        . '<button type="button" data-md="[链接文字](https://)" title="链接">' . icons('link') . '</button>'
        . (can('attachment.upload') ? '<label class="editor-upload" title="上传附件/图片">' . icons('image') . '<input type="file" data-upload accept="image/*,.txt,.md,.pdf,.zip" hidden></label>' : '')
        . '<div class="spacer"></div>'
        . '<button type="button" data-preview-toggle title="预览">' . icons('eye') . '预览</button>'
        . '</div>'
        . '<textarea name="' . h($name) . '" rows="10" required data-editor-area>' . h($value) . '</textarea>'
        . '<div class="editor-preview" data-editor-preview hidden></div>'
        . '<div class="editor-attach" data-attach-list></div>'
        . '</div>';
    return $html;
}

// 后台/列表页共用的页签条
function tabs_html(array $tabs, string $current): string
{
    $html = '<div class="tabbar">';
    foreach ($tabs as $key => $label) {
        $html .= '<a class="tab' . ($key === $current ? ' active' : '') . '" href="' . h($label[1]) . '">' . h($label[0]) . '</a>';
    }
    return $html . '</div>';
}
