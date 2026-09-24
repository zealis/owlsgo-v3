<?php
// ============================================================
// 基础层：输出转义、请求工具、JSON/重定向/错误响应、闪存消息、
// 站点设置（ow_settings 唯一来源）、URL 生成（a= 与伪静态双模式）
// ============================================================

function h(string|int|float|bool|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function cut(string $v, int $max): string
{
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) . '…' : $v;
}

function char_len(string $v): int
{
    return mb_strlen($v);
}

function now(): int
{
    return time();
}

function human_time(int $ts): string
{
    if ($ts <= 0) {
        return '-';
    }
    $diff = now() - $ts;
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 86400 * 30) {
        return floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

function full_time(int $ts): string
{
    return $ts > 0 ? date('Y-m-d H:i', $ts) : '-';
}

// ------------------------------------------------------------
// 请求工具
// ------------------------------------------------------------
function post(string $key, int $max = 0): string
{
    $v = trim((string) ($_POST[$key] ?? ''));
    return $max > 0 ? mb_substr($v, 0, $max) : $v;
}

function get(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function gid(string $key = 'id'): int
{
    return max(0, (int) ($_GET[$key] ?? $_POST[$key] ?? 0));
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || get('ajax') === '1';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 60);
}

function page_now(): int
{
    return max(1, (int) ($_GET['p'] ?? 1));
}

// ------------------------------------------------------------
// 响应：JSON / 跳转 / 错误 / 闪存
// ------------------------------------------------------------
function json_out(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function go(string $url): never
{
    if (is_ajax()) {
        json_out(['ok' => true, 'redirect' => $url]);
    }
    header('Location: ' . $url);
    exit;
}

function err(string $message, int $status = 200, string $mode = 'auto', string $url = ''): never
{
    if ($mode === 'json' || ($mode === 'auto' && is_ajax())) {
        json_out(['ok' => false, 'error' => $message]);
    }
    http_response_code($status >= 400 ? $status : 200);
    if ($url !== '') {
        set_flash($message);
        header('Location: ' . $url);
        exit;
    }
    page('提示', '<div class="panel"><div class="panel-body"><p class="error-text">' . h($message) . '</p><p><a href="javascript:history.back()">返回上一页</a></p></div></div>');
}

function set_flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): string
{
    $m = (string) ($_SESSION['flash'] ?? '');
    unset($_SESSION['flash']);
    return $m;
}

// ------------------------------------------------------------
// 站点设置：ow_settings 是唯一取值来源，default_settings() 兜底
// ------------------------------------------------------------
function default_settings(): array
{
    return [
        'site_name' => 'owlsgo',
        'site_desc' => '一个极简原生 PHP 社区',
        'site_logo' => '',
        'site_keywords' => '',
        'icp' => '',
        'footer_html' => '',
        'pretty_url' => '1',
        'register_open' => '1',
        'register_captcha' => '1',
        'login_captcha' => '1',
        'thread_review' => '0',
        'reply_review' => '0',
        'thread_interval' => '30',
        'reply_interval' => '10',
        'title_highlight' => '1',
        'hot_threshold' => '20',
        'fold_threshold' => '1200',
        'per_page' => '20',
        'floor_per_page' => '15',
        'upload_max_mb' => '10',
        'notice_guide' => '欢迎来到本站，请友善交流。',
        'cron_token' => '',
        'home_tabs' => 'latest,newest,hot,featured',
    ];
}

function setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];
        try {
            foreach (all('SELECT name, value FROM ow_settings') as $row) {
                $GLOBALS['__settings_cache'][$row['name']] = (string) $row['value'];
            }
        } catch (Throwable $e) {
            $GLOBALS['__settings_cache'] = [];
        }
    }
    if (array_key_exists($key, $GLOBALS['__settings_cache'])) {
        return $GLOBALS['__settings_cache'][$key];
    }
    $defaults = default_settings();
    return $defaults[$key] ?? $default;
}

function settings_save(array $values): void
{
    $driver = db_driver();
    foreach ($values as $k => $v) {
        $v = (string) $v;
        if ($driver === 'mysql') {
            q('INSERT INTO ow_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$k, $v]);
        } elseif ($driver === 'pgsql') {
            q('INSERT INTO ow_settings (name, value) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET value=EXCLUDED.value', [$k, $v]);
        } else {
            q('INSERT INTO ow_settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value=excluded.value', [$k, $v]);
        }
    }
    unset($GLOBALS['__settings_cache']);
}

// ------------------------------------------------------------
// URL 生成：a= 参数模式与伪静态模式
// ------------------------------------------------------------
function app_url(string $path = ''): string
{
    $base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/\\');
    $base = $base === '' || $base === '.' ? '' : $base;
    return $base . '/' . ltrim($path, '/');
}

function append_query(string $url, array $params): string
{
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    if (!$params) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
}

function route_url(string $action = 'home', array $params = []): string
{
    if (setting('pretty_url', '1') === '1') {
        $map = ['home' => '', 'forum' => 'forum', 'thread' => 'thread', 'user' => 'user', 'search' => 'search', 'login' => 'login', 'register' => 'register'];
        if (isset($map[$action])) {
            $path = $map[$action];
            if ($action === 'forum' && isset($params['id'])) {
                $path .= '/' . (int) $params['id'];
                unset($params['id']);
            } elseif (($action === 'thread' || $action === 'user') && isset($params['id'])) {
                $path .= '/' . (int) $params['id'];
                unset($params['id']);
            }
            return append_query(app_url($path), $params);
        }
    }
    return append_query(app_url('index.php'), array_merge(['a' => $action], $params));
}

function asset_url(string $file): string
{
    return app_url('app/assets/' . ltrim($file, '/')) . '?v=' . APP_VERSION;
}
