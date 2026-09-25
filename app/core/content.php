<?php
// ============================================================
// 正文渲染：白名单 Markdown 子集（标题/加粗/斜体/删除线/链接/图片/
// 代码/引用/列表/分割线），不解析用户 HTML；@提及 与摘要
// ============================================================

function safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?://|/|\./|\.\./|mailto:)#i', $url)) {
        return $url;
    }
    return '';
}

function image_ext_allowed(string $url): bool
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    return (bool) preg_match('/\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i', $path);
}

// 站内图片附件：URL 由 attachment_url() 生成，形如 /index.php?a=attachment&id=N
// 或 /attachment/N —— 没有扩展名，无法靠后缀判断，改查库按真实 mime 认定。
// 外链不享受此豁免，避免借 a=attachment 形态把外部资源渲染成 <img>。
function is_local_image_attachment(string $url): bool
{
    static $cache = [];
    // 比对 host 时剥离端口：parse_url 的 host 不含端口，而 HTTP_HOST 含
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $raw = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $self = strtolower((string) (parse_url($raw === '' ? '' : 'http://' . $raw, PHP_URL_HOST) ?: ''));
    if ($host !== '' && $host !== $self) {
        return false;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    $id = 0;
    if (preg_match('#(?:^|/)index\.php$#i', $path)
        && preg_match('#(?:^|&)a=attachment(?:&|$)#i', $query)
        && preg_match('#(?:^|&)id=(\d+)#i', $query, $m)) {
        $id = (int) $m[1];
    } elseif (preg_match('#/attachment/(\d+)#i', $path, $m)) {
        $id = (int) $m[1];
    }
    if ($id <= 0) {
        return false;
    }
    if (!array_key_exists($id, $cache)) {
        $att = one('SELECT mime FROM ow_attachments WHERE id=?', [$id]);
        $cache[$id] = !empty($att) && str_starts_with((string) ($att['mime'] ?? ''), 'image/');
    }
    return $cache[$id];
}

// 行内语法（输入已是转义后的文本）
function inline_html(string $escaped): string
{
    // 代码行内
    $escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
    // 加粗 / 斜体 / 删除线
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $escaped);
    $escaped = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $escaped);
    // 图片 ![alt](url)
    $escaped = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        $url = safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($url === '' || !(image_ext_allowed($url) || is_local_image_attachment($url))) {
            return $m[0];
        }
        return '<img class="content-image" src="' . h($url) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $escaped);
    // 链接 [text](url)
    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return $m[1];
        }
        return '<a href="' . h($url) . '" rel="nofollow ugc">' . $m[1] . '</a>';
    }, $escaped);
    // 自动链接
    $escaped = preg_replace_callback('#(?<!["\'=])(https?://[^\s<]+)#', function ($m) {
        $url = rtrim($m[1], '.,;:!?)');
        return '<a href="' . h($url) . '" rel="nofollow ugc">' . h($url) . '</a>';
    }, $escaped);
    // @提及
    $escaped = preg_replace_callback('/@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,30})/u', function ($m) {
        $u = one('SELECT id, name FROM ow_users WHERE name=?', [$m[1]]);
        if ($u) {
            return '<a class="mention" href="' . h(route_url('user', ['id' => $u['id']])) . '">@' . $m[1] . '</a>';
        }
        return $m[0];
    }, $escaped);
    return $escaped;
}

function content_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $lines = explode("\n", $text);
    $out = [];
    $para = [];
    $inCode = false;
    $codeBuf = [];
    $listType = '';
    $listBuf = [];

    $flushPara = function () use (&$para, &$out): void {
        if ($para) {
            $out[] = '<p>' . inline_html(h(implode("\n", $para))) . '</p>';
            $para = [];
        }
    };
    $flushList = function () use (&$listType, &$listBuf, &$out): void {
        if ($listBuf) {
            $tag = $listType === 'ol' ? 'ol' : 'ul';
            $out[] = '<' . $tag . '><li>' . implode('</li><li>', array_map(fn($i) => inline_html(h($i)), $listBuf)) . '</li></' . $tag . '>';
            $listBuf = [];
            $listType = '';
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            if ($inCode) {
                $out[] = '<pre><code>' . h(implode("\n", $codeBuf)) . '</code></pre>';
                $codeBuf = [];
                $inCode = false;
            } else {
                $flushPara();
                $flushList();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $codeBuf[] = $line;
            continue;
        }
        $trim = trim($line);
        if ($trim === '') {
            $flushPara();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $flushList();
            $level = strlen($m[1]) + 1; // h2-h5
            $out[] = '<h' . $level . '>' . inline_html(h($m[2])) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
            $flushPara();
            $flushList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^&gt;|^>/', $trim)) {
            $flushPara();
            $flushList();
            $out[] = '<blockquote>' . inline_html(h(ltrim($trim, '> '))) . '</blockquote>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trim, $m)) {
            $flushPara();
            if ($listType !== 'ul') {
                $flushList();
                $listType = 'ul';
            }
            $listBuf[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+[.、]\s*(.+)$/', $trim, $m)) {
            $flushPara();
            if ($listType !== 'ol') {
                $flushList();
                $listType = 'ol';
            }
            $listBuf[] = $m[1];
            continue;
        }
        $para[] = $line;
    }
    if ($inCode) {
        $out[] = '<pre><code>' . h(implode("\n", $codeBuf)) . '</code></pre>';
    }
    $flushPara();
    $flushList();
    $html = implode("\n", $out);
    // 插件钩子：正文渲染后过滤
    if (function_exists('hook_filter')) {
        $html = hook_filter('content.render', $html, []);
    }
    return $html;
}

function content_excerpt(string $text, ?int $max = null): string
{
    $max = $max ?? 120;
    $plain = trim(preg_replace('/[#>*`\-\[\]!()]/', '', str_replace(["\r\n", "\r", "\n"], ' ', $text)));
    return cut($plain, $max);
}

// 提取 @提及 的用户（通知用）
function mention_targets(string $body, int $senderId): array
{
    preg_match_all('/@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,30})/u', $body, $m);
    $names = array_unique($m[1]);
    if (!$names) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($names), '?'));
    $rows = all("SELECT id, name FROM ow_users WHERE name IN ($marks) AND id != ?", [...$names, $senderId]);
    return $rows;
}
