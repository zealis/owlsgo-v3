<?php
// ============================================================
// 领域层：版块/用户查询、帖子与评论写入、点赞收藏、通知公告、
// 附件、回收站（软删 JSON 快照）、计数重算、操作日志
// 计数口径（与写入路径对称）：
//   threads.replies = 该帖未删且已通过评论数（不含首帖）
//   forums.threads / posts = 未删帖数 / 未删帖 + 未删已通过评论
// ============================================================

// ------------------------------------------------------------
// 版块与用户
// ------------------------------------------------------------
function forums_all(bool $refresh = false): array
{
    static $forums = null;
    if ($forums === null || $refresh) {
        $forums = all('SELECT * FROM ow_forums ORDER BY sort ASC, id ASC');
    }
    return $forums;
}

function forum_by_id(int $id): ?array
{
    foreach (forums_all() as $f) {
        if ((int) $f['id'] === $id) {
            return $f;
        }
    }
    return null;
}

function forum_children(int $parentId): array
{
    return array_values(array_filter(forums_all(), fn($f) => (int) $f['parent_id'] === $parentId));
}

function forum_options(int $excludeId = 0): array
{
    // 父版块 → 子版块 的有序选项
    $out = [];
    foreach (forum_children(0) as $parent) {
        $out[] = $parent;
        foreach (forum_children((int) $parent['id']) as $child) {
            if ((int) $child['id'] !== $excludeId) {
                $child['name'] = '　├ ' . $child['name'];
                $out[] = $child;
            }
        }
    }
    return $out;
}

function groups_all(bool $refresh = false): array
{
    static $groups = null;
    if ($groups === null || $refresh) {
        $groups = all('SELECT * FROM ow_groups ORDER BY id ASC');
    }
    return $groups;
}

function group_by_id(int $id): ?array
{
    foreach (groups_all() as $g) {
        if ((int) $g['id'] === $id) {
            return $g;
        }
    }
    return null;
}

function user_by_id(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM ow_users WHERE id=?', [$id]) : null;
}

function user_by_name(string $name): ?array
{
    return one('SELECT * FROM ow_users WHERE name=?', [$name]);
}

// 批量装配作者信息，杜绝 N+1
function attach_users(array $rows, string $key = 'user_id'): array
{
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r[$key] ?? 0), $rows))));
    if (!$ids) {
        return $rows;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $users = [];
    foreach (all("SELECT id, name, avatar, group_id, banned FROM ow_users WHERE id IN ($marks)", $ids) as $u) {
        $users[(int) $u['id']] = $u;
    }
    foreach ($rows as &$row) {
        $row['_user'] = $users[(int) ($row[$key] ?? 0)] ?? null;
    }
    unset($row);
    return $rows;
}

function user_link(int $id, string $name): string
{
    return '<a class="user-link" href="' . h(route_url('user', ['id' => $id])) . '">' . h($name) . '</a>';
}

function user_state_tags(array $user): string
{
    $tags = '';
    $group = group_by_id((int) $user['group_id']);
    if ($group) {
        $cls = (int) $user['group_id'] === 1 ? 'tag-admin' : ((int) $user['group_id'] === 2 ? 'tag-mod' : '');
        $tags .= '<span class="tag ' . $cls . '">' . h($group['name']) . '</span>';
    }
    if ((int) $user['banned'] === 1) {
        $tags .= '<span class="tag tag-danger">已禁访</span>';
    } elseif ((int) $user['muted_until'] > now()) {
        $tags .= '<span class="tag tag-warn">禁言中</span>';
    }
    return $tags;
}

// ------------------------------------------------------------
// 头像与封面
// ------------------------------------------------------------
function avatar_url(int $userId): string
{
    $u = user_by_id($userId);
    if ($u && $u['avatar'] !== '') {
        return app_url('index.php?a=avatar&id=' . $userId);
    }
    return app_url('index.php?a=avatar&id=' . $userId . '&ident=1');
}

function avatar_identicon_svg(string $name): string
{
    // 由用户名生成稳定的几何图案头像（DiceBear 风格简化版）
    $hash = md5($name);
    $hue = hexdec(substr($hash, 0, 2)) * 360 / 255;
    $cells = '';
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < 3; $x++) {
            $on = hexdec(substr($hash, 4 + $y * 3 + $x, 1)) % 2 === 0;
            if ($on) {
                foreach ([$x, 4 - $x] as $cx) {
                    $cells .= '<rect x="' . ($cx * 20) . '" y="' . ($y * 20) . '" width="20" height="20"/>';
                }
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        . '<rect width="100" height="100" fill="hsl(' . (int) $hue . ',60%,92%)"/>'
        . '<g fill="hsl(' . (int) $hue . ',55%,55%)">' . $cells . '</g></svg>';
}

// ------------------------------------------------------------
// 通知
// ------------------------------------------------------------
function notify_create(int $recipientId, int $senderId, string $kind, string $content, int $threadId = 0, int $replyId = 0): void
{
    if ($recipientId <= 0 || $recipientId === $senderId) {
        return;
    }
    q('INSERT INTO ow_notifications (user_id, sender_id, kind, content, thread_id, reply_id, is_read, created) VALUES (?,?,?,?,?,?,0,?)',
        [$recipientId, $senderId, $kind, cut($content, 200), $threadId, $replyId, now()]);
}

function unread_count(int $userId): int
{
    return (int) val('SELECT COUNT(*) FROM ow_notifications WHERE user_id=? AND is_read=0', [$userId]);
}

function notices_pinned(): array
{
    return all('SELECT * FROM ow_notices WHERE pushed=1 ORDER BY created DESC LIMIT 3');
}

// ------------------------------------------------------------
// 点赞与收藏
// ------------------------------------------------------------
function like_toggle(string $type, int $targetId): array
{
    need_login();
    if (!can('like.use')) {
        err('没有点赞权限', 403);
    }
    if (!in_array($type, ['thread', 'reply'], true)) {
        err('参数错误');
    }
    $uid = uid();
    $row = one('SELECT id FROM ow_likes WHERE user_id=? AND target=? AND target_id=?', [$uid, $type, $targetId]);
    if ($row) {
        q('DELETE FROM ow_likes WHERE id=?', [$row['id']]);
        $active = false;
    } else {
        q('INSERT INTO ow_likes (user_id, target, target_id, created) VALUES (?,?,?,?)', [$uid, $type, $targetId, now()]);
        $active = true;
        // 通知作者
        $table = $type === 'thread' ? 'ow_threads' : 'ow_replies';
        $owner = one("SELECT user_id, " . ($type === 'thread' ? 'title' : 'content') . " AS body FROM $table WHERE id=?", [$targetId]);
        if ($owner) {
            $threadId = $type === 'thread' ? $targetId : (int) (one('SELECT thread_id FROM ow_replies WHERE id=?', [$targetId])['thread_id'] ?? 0);
            notify_create((int) $owner['user_id'], $uid, 'like', '赞了你的' . ($type === 'thread' ? '帖子' : '评论') . '：' . content_excerpt((string) $owner['body'], 50), $threadId, $type === 'reply' ? $targetId : 0);
        }
    }
    $count = like_count($type, $targetId);
    if ($type === 'reply') {
        q('UPDATE ow_replies SET likes=? WHERE id=?', [$count, $targetId]);
    }
    return ['active' => $active, 'count' => $count];
}

function like_count(string $type, int $targetId): int
{
    return (int) val('SELECT COUNT(*) FROM ow_likes WHERE target=? AND target_id=?', [$type, $targetId]);
}

function like_mine(string $type, int $targetId): bool
{
    $uid = uid();
    return $uid > 0 && (bool) val('SELECT id FROM ow_likes WHERE user_id=? AND target=? AND target_id=?', [$uid, $type, $targetId]);
}

function favorite_toggle(int $threadId): array
{
    need_login();
    if (!can('favorite.use')) {
        err('没有收藏权限', 403);
    }
    $uid = uid();
    $row = one('SELECT id FROM ow_favorites WHERE user_id=? AND thread_id=?', [$uid, $threadId]);
    if ($row) {
        q('DELETE FROM ow_favorites WHERE id=?', [$row['id']]);
        $active = false;
    } else {
        q('INSERT INTO ow_favorites (user_id, thread_id, created) VALUES (?,?,?)', [$uid, $threadId, now()]);
        $active = true;
    }
    return ['active' => $active, 'count' => (int) val('SELECT COUNT(*) FROM ow_favorites WHERE thread_id=?', [$threadId])];
}

// ------------------------------------------------------------
// 计数重算：任何删除/恢复/审核状态变化后调用
// ------------------------------------------------------------
function recount_thread(int $threadId): void
{
    $count = (int) val('SELECT COUNT(*) FROM ow_replies WHERE thread_id=? AND status=\'ok\'', [$threadId]);
    $last = one('SELECT user_id, created FROM ow_replies WHERE thread_id=? AND status=\'ok\' ORDER BY created DESC LIMIT 1', [$threadId]);
    q('UPDATE ow_threads SET replies=?, last_reply_at=?, last_reply_user=? WHERE id=?',
        [$count, $last ? $last['created'] : 0, $last ? $last['user_id'] : 0, $threadId]);
}

function recount_forum(int $forumId): void
{
    $threads = (int) val('SELECT COUNT(*) FROM ow_threads WHERE forum_id=?', [$forumId]);
    $posts = $threads + (int) val('SELECT COUNT(*) FROM ow_replies r JOIN ow_threads t ON r.thread_id=t.id WHERE t.forum_id=? AND r.status=\'ok\'', [$forumId]);
    q('UPDATE ow_forums SET threads=?, posts=? WHERE id=?', [$threads, $posts, $forumId]);
}

function recount_user(int $userId): void
{
    $threads = (int) val('SELECT COUNT(*) FROM ow_threads WHERE user_id=?', [$userId]);
    $replies = (int) val('SELECT COUNT(*) FROM ow_replies WHERE user_id=? AND status=\'ok\'', [$userId]);
    q('UPDATE ow_users SET threads=?, replies=? WHERE id=?', [$threads, $replies, $userId]);
}

// ------------------------------------------------------------
// 回收站：删除=快照进 ow_trash + 删原行；恢复=严格逆操作 + recount
// ------------------------------------------------------------
function trash_put(string $type, array $row, int $operatorId): int
{
    q('INSERT INTO ow_trash (type, data, operator_id, created) VALUES (?,?,?,?)',
        [$type, json_encode($row, JSON_UNESCAPED_UNICODE), $operatorId, now()]);
    return last_id('ow_trash');
}

function delete_thread(int $threadId, int $operatorId): void
{
    $thread = thread_by_id($threadId);
    if (!$thread) {
        return;
    }
    tx(function () use ($thread, $threadId, $operatorId): void {
        // 连带评论一起快照
        foreach (all('SELECT * FROM ow_replies WHERE thread_id=?', [$threadId]) as $reply) {
            trash_put('reply', $reply + ['_thread_deleted' => $threadId], $operatorId);
            q('DELETE FROM ow_replies WHERE id=?', [$reply['id']]);
        }
        trash_put('thread', $thread, $operatorId);
        q('DELETE FROM ow_threads WHERE id=?', [$threadId]);
        q('DELETE FROM ow_likes WHERE target=\'thread\' AND target_id=?', [$threadId]);
        q('DELETE FROM ow_favorites WHERE thread_id=?', [$threadId]);
    });
    recount_forum((int) $thread['forum_id']);
    recount_user((int) $thread['user_id']);
    log_action('thread.delete', '删除帖子 #' . $threadId . '「' . cut($thread['title'], 40) . '」', $operatorId);
}

function delete_reply(int $replyId, int $operatorId): void
{
    $reply = one('SELECT * FROM ow_replies WHERE id=?', [$replyId]);
    if (!$reply) {
        return;
    }
    tx(function () use ($reply, $replyId, $operatorId): void {
        trash_put('reply', $reply, $operatorId);
        q('DELETE FROM ow_replies WHERE id=?', [$replyId]);
        q('DELETE FROM ow_likes WHERE target=\'reply\' AND target_id=?', [$replyId]);
    });
    recount_thread((int) $reply['thread_id']);
    $thread = thread_by_id((int) $reply['thread_id']);
    if ($thread) {
        recount_forum((int) $thread['forum_id']);
    }
    recount_user((int) $reply['user_id']);
    log_action('reply.delete', '删除评论 #' . $replyId, $operatorId);
}

// ------------------------------------------------------------
// 操作日志
// ------------------------------------------------------------
function log_action(string $action, string $detail = '', ?int $userId = null): void
{
    try {
        q('INSERT INTO ow_logs (user_id, action, detail, ip, created) VALUES (?,?,?,?,?)',
            [$userId ?? uid(), $action, cut($detail, 250), client_ip(), now()]);
    } catch (Throwable $e) {
        // 日志失败不阻断主流程
    }
}

// ------------------------------------------------------------
// 附件
// ------------------------------------------------------------
function upload_ext_map(): array
{
    return [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain', 'md' => 'text/plain', 'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];
}

function attachment_url(int $id): string
{
    return app_url('index.php?a=attachment&id=' . $id);
}

function user_quota_bytes(int $userId): int
{
    $u = user_by_id($userId);
    $group = $u ? group_by_id((int) $u['group_id']) : null;
    $mb = $group ? (int) $group['attach_quota_mb'] : 0;
    return max(0, $mb) * 1024 * 1024;
}

function user_quota_used(int $userId): int
{
    return (int) val('SELECT COALESCE(SUM(size),0) FROM ow_attachments WHERE user_id=?', [$userId]);
}

// 保存上传：扩展名 + MIME 双重白名单 + 内容嗅探 + 按 hash 去重
function save_upload(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        err('上传失败（错误码 ' . (int) ($file['error'] ?? -1) . '）');
    }
    $maxBytes = (int) setting('upload_max_mb', '10') * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        err('文件超过大小限制（' . setting('upload_max_mb', '10') . 'MB）');
    }
    $name = (string) $file['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = upload_ext_map();
    if (!isset($map[$ext])) {
        err('不允许的文件类型');
    }
    $tmp = (string) $file['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    $isImage = str_starts_with($mime, 'image/');
    if ($isImage && !in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
        err('文件内容与类型不符');
    }
    $hash = hash_file('sha256', $tmp);
    // 去重：同用户同内容直接复用
    $dup = one('SELECT * FROM ow_attachments WHERE user_id=? AND hash=?', [$userId, $hash]);
    if ($dup) {
        return $dup;
    }
    $quota = user_quota_bytes($userId);
    if ($quota > 0 && user_quota_used($userId) + $file['size'] > $quota) {
        err('附件配额不足');
    }
    $dir = APP_DIR . '/upload/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = substr($hash, 0, 16) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        err('保存文件失败');
    }
    q('INSERT INTO ow_attachments (user_id, name, path, size, mime, hash, downloads, created) VALUES (?,?,?,?,?,?,0,?)',
        [$userId, cut($name, 180), date('Y/m') . '/' . $filename, (int) $file['size'], $mime, $hash, now()]);
    return one('SELECT * FROM ow_attachments WHERE id=?', [last_id('ow_attachments')]);
}

// ------------------------------------------------------------
// 钩子（插件机制的最小实现，Plugin 模块加载后注册回调）
// ------------------------------------------------------------
function hook_filter(string $name, $value, array $context = [])
{
    foreach ($GLOBALS['__hooks'][$name] ?? [] as $cb) {
        $value = $cb($value, $context);
    }
    return $value;
}

function hook_run(string $name, array $context = []): void
{
    foreach ($GLOBALS['__hooks'][$name] ?? [] as $cb) {
        $cb(null, $context);
    }
}

function hook_add(string $name, callable $cb): void
{
    $GLOBALS['__hooks'][$name][] = $cb;
}
