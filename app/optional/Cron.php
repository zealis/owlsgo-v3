<?php
// ============================================================
// Cron 计划任务（a=cron）：令牌保护、按间隔运行到期任务
// 外部定时：每分钟 GET /index.php?a=cron&token=…
// ============================================================

function cron_route(): never
{
    header('Content-Type: text/plain; charset=utf-8');
    $token = setting('cron_token');
    if ($token !== '' && !hash_equals($token, get('token'))) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
    $jobs = all('SELECT * FROM ow_cron_jobs WHERE enabled=1 AND last_run + interval <= ?', [now()]);
    $ran = [];
    foreach ($jobs as $job) {
        $ran[] = $job['name'] . ': ' . cron_run_job($job);
    }
    echo 'ok ' . count($ran) . "\n" . implode("\n", $ran) . "\n";
    exit;
}

function cron_run_job(array $job): string
{
    $callback = (string) $job['callback'];
    $result = 'noop';
    try {
        if ($callback === 'cron_purge_trash') {
            $result = cron_purge_trash();
        } elseif (str_starts_with($callback, 'plugin:')) {
            $result = cron_run_plugin_job($callback);
        } elseif (function_exists($callback)) {
            $result = (string) $callback();
        }
    } catch (Throwable $e) {
        $result = 'error: ' . $e->getMessage();
    }
    q('UPDATE ow_cron_jobs SET last_run=? WHERE id=?', [now(), $job['id']]);
    return $result;
}

// 内置任务：回收站过期清理（30 天）
function cron_purge_trash(): string
{
    $before = (int) val('SELECT COUNT(*) FROM ow_trash');
    q('DELETE FROM ow_trash WHERE created<?', [now() - 30 * 86400]);
    $after = (int) val('SELECT COUNT(*) FROM ow_trash');
    return 'purged ' . ($before - $after);
}

function cron_run_plugin_job(string $callback): string
{
    // plugin:<id>:<jobname>
    [, $id, $name] = explode(':', $callback, 3) + ['', '', ''];
    $m = function_exists('plugin_manifest') ? plugin_manifest($id) : null;
    if (!$m) {
        require_once APP_DIR . '/optional/Plugin.php';
        $m = plugin_manifest($id);
    }
    foreach ((array) ($m['cron'] ?? []) as $job) {
        if (($job['name'] ?? '') === $name && is_callable($job['callback'] ?? null)) {
            return (string) $job['callback']();
        }
    }
    return 'missing';
}
