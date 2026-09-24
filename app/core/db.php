<?php
// ============================================================
// 数据库层：PDO 三驱动抽象 + 声明式结构定义 + 幂等结构同步
// SQLite(默认) / MySQL 8+ / PostgreSQL 13+ 共用 db_schema() 一套声明，
// 不用正则解析 SQL，结构同步逐列比对，三库行为一致。
// ============================================================

define('DB_PREFIX', 'ow_');

function db_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_DIR . '/data/db.php';
        $cfg = is_file($file) ? (require $file) : ['driver' => 'sqlite'];
    }
    return $cfg;
}

function db_driver(): string
{
    return db_config()['driver'] ?? 'sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = db_config();
    $driver = $cfg['driver'] ?? 'sqlite';
    if ($driver === 'sqlite') {
        $path = $cfg['path'] ?? (APP_DIR . '/data/owlsgo.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } elseif ($driver === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 3306, $cfg['name'] ?? 'owlsgo');
        $pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
            $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 5432, $cfg['name'] ?? 'owlsgo');
        $pdo = new PDO($dsn, $cfg['user'] ?? 'postgres', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// 标识符引用（反引号 / 双引号 / 无）
function ident(string $name): string
{
    $driver = db_driver();
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '', $name) . '`';
    }
    if ($driver === 'pgsql') {
        return '"' . str_replace('"', '', $name) . '"';
    }
    return $name;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = $fn();
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function last_id(string $table): int
{
    if (db_driver() === 'pgsql') {
        return (int) val('SELECT MAX(id) FROM ' . ident($table));
    }
    return (int) db()->lastInsertId();
}

function db_table_exists(string $table): bool
{
    $driver = db_driver();
    if ($driver === 'sqlite') {
        return (bool) val('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=?', [$table]);
    }
    if ($driver === 'mysql') {
        return (bool) val('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    }
    return (bool) val('SELECT tablename FROM pg_tables WHERE schemaname=current_schema() AND tablename=?', [$table]);
}

function db_columns(string $table): array
{
    $driver = db_driver();
    if ($driver === 'sqlite') {
        return array_column(all('PRAGMA table_info(' . ident($table) . ')'), 'name');
    }
    if ($driver === 'mysql') {
        return array_column(all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), 'COLUMN_NAME');
    }
    return array_column(all('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]), 'column_name');
}

// ------------------------------------------------------------
// 声明式结构定义：加表/加列只改这里，后台「结构同步」幂等补齐
// 类型：id=自增主键 int=整数 string=变长字符串 text=长文本 ts=时间戳
// 每列：['类型', 默认值]；string 可写作 'string:120' 指定长度
// ------------------------------------------------------------
function db_schema(): array
{
    return [
        'ow_users' => [
            'columns' => [
                'id' => ['id'], 'name' => ['string:60'], 'email' => ['string:190'],
                'pass' => ['string'], 'group_id' => ['int', 3],
                'avatar' => ['string', ''], 'cover' => ['string', ''],
                'bio' => ['string', ''], 'signature' => ['string', ''],
                'theme' => ['string:20', 'auto'], 'privacy' => ['string', ''],
                'threads' => ['int', 0], 'replies' => ['int', 0],
                'muted_until' => ['ts', 0], 'banned' => ['int', 0],
                'reset_token' => ['string', ''], 'reset_expires' => ['ts', 0],
                'created' => ['ts', 0], 'last_active' => ['ts', 0],
            ],
            'indexes' => [['name', 'unique' => true], ['email'], ['group_id']],
        ],
        'ow_groups' => [
            'columns' => [
                'id' => ['id'], 'name' => ['string:60'], 'permissions' => ['text'],
                'attach_quota_mb' => ['int', 20], 'created' => ['ts', 0],
            ],
            'indexes' => [],
        ],
        'ow_forums' => [
            'columns' => [
                'id' => ['id'], 'parent_id' => ['int', 0], 'name' => ['string:120'],
                'description' => ['string', ''], 'sort' => ['int', 0],
                'threads' => ['int', 0], 'posts' => ['int', 0],
                'moderators' => ['string', ''], 'created' => ['ts', 0],
            ],
            'indexes' => [['parent_id'], ['sort']],
        ],
        'ow_threads' => [
            'columns' => [
                'id' => ['id'], 'forum_id' => ['int', 0], 'user_id' => ['int', 0],
                'title' => ['string:190'], 'content' => ['text'],
                'pinned' => ['int', 0], 'locked' => ['int', 0], 'featured' => ['int', 0],
                'highlight' => ['string:20', ''], 'status' => ['string:20', 'ok'],
                'views' => ['int', 0], 'replies' => ['int', 0],
                'last_reply_at' => ['ts', 0], 'last_reply_user' => ['int', 0],
                'created' => ['ts', 0], 'edited_at' => ['ts', 0],
            ],
            'indexes' => [['forum_id'], ['user_id'], ['status'], ['last_reply_at'], ['created']],
        ],
        'ow_replies' => [
            'columns' => [
                'id' => ['id'], 'thread_id' => ['int', 0], 'user_id' => ['int', 0],
                'parent_id' => ['int', 0], 'floor' => ['int', 0], 'content' => ['text'],
                'status' => ['string:20', 'ok'], 'likes' => ['int', 0],
                'created' => ['ts', 0], 'edited_at' => ['ts', 0],
            ],
            'indexes' => [['thread_id'], ['user_id'], ['status'], ['parent_id']],
        ],
        'ow_attachments' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'name' => ['string:190'],
                'path' => ['string', ''], 'size' => ['int', 0], 'mime' => ['string:100', ''],
                'hash' => ['string:64', ''], 'downloads' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['user_id'], ['hash']],
        ],
        'ow_likes' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'target' => ['string:20'],
                'target_id' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['user_id', 'target', 'target_id', 'unique' => true], ['target', 'target_id']],
        ],
        'ow_favorites' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'thread_id' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['user_id', 'thread_id', 'unique' => true]],
        ],
        'ow_notices' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'title' => ['string:190'],
                'content' => ['text'], 'pushed' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['created']],
        ],
        'ow_notifications' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'sender_id' => ['int', 0],
                'kind' => ['string:30', ''], 'content' => ['string', ''],
                'thread_id' => ['int', 0], 'reply_id' => ['int', 0],
                'is_read' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['user_id', 'is_read'], ['created']],
        ],
        'ow_settings' => [
            'columns' => ['name' => ['string:100'], 'value' => ['text']],
            'indexes' => [['name', 'unique' => true]],
        ],
        'ow_logs' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'action' => ['string:60', ''],
                'detail' => ['string', ''], 'ip' => ['string:60', ''], 'created' => ['ts', 0],
            ],
            'indexes' => [['created'], ['user_id']],
        ],
        'ow_plugins' => [
            'columns' => [
                'id' => ['string:60'], 'name' => ['string:120', ''], 'version' => ['string:30', ''],
                'enabled' => ['int', 0], 'settings' => ['text'], 'created' => ['ts', 0],
            ],
            'indexes' => [],
        ],
        'ow_cron_jobs' => [
            'columns' => [
                'id' => ['id'], 'name' => ['string:60', ''], 'callback' => ['string:190', ''],
                'interval' => ['int', 3600], 'enabled' => ['int', 1],
                'last_run' => ['ts', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['enabled', 'last_run']],
        ],
        'ow_rate_limits' => [
            'columns' => [
                'id' => ['id'], 'bucket' => ['string:120', ''], 'created' => ['ts', 0],
            ],
            'indexes' => [['bucket', 'created']],
        ],
        'ow_bans' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'ip' => ['string:60', ''],
                'reason' => ['string', ''], 'until' => ['ts', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['user_id'], ['ip']],
        ],
        'ow_api_tokens' => [
            'columns' => [
                'id' => ['id'], 'user_id' => ['int', 0], 'name' => ['string:60', ''],
                'token' => ['string:80', ''], 'last_used' => ['ts', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['token', 'unique' => true], ['user_id']],
        ],
        'ow_trash' => [
            'columns' => [
                'id' => ['id'], 'type' => ['string:20', ''], 'data' => ['text'],
                'operator_id' => ['int', 0], 'created' => ['ts', 0],
            ],
            'indexes' => [['type'], ['created']],
        ],
    ];
}

// 声明类型 → 驱动 DDL 片段
function db_column_ddl(string $name, array $def): string
{
    $driver = db_driver();
    $type = $def[0];
    $default = $def[1] ?? null;
    $col = ident($name);
    if ($type === 'id') {
        if ($driver === 'mysql') {
            return "$col INT AUTO_INCREMENT PRIMARY KEY";
        }
        if ($driver === 'pgsql') {
            return "$col SERIAL PRIMARY KEY";
        }
        return "$col INTEGER PRIMARY KEY AUTOINCREMENT";
    }
    $len = 255;
    if (str_starts_with($type, 'string:')) {
        [$type, $len] = explode(':', $type);
        $len = (int) $len;
    }
    $sqlType = match ($type) {
        'int' => 'INT', 'ts' => 'INT',
        'text' => 'TEXT',
        default => $driver === 'pgsql' ? "VARCHAR($len)" : "VARCHAR($len)",
    };
    $ddl = "$col $sqlType NOT NULL";
    if ($default !== null && $default !== '') {
        $ddl .= is_int($default) ? " DEFAULT $default" : " DEFAULT '" . str_replace("'", "''", (string) $default) . "'";
    } elseif ($default === '') {
        $ddl .= " DEFAULT ''";
    }
    return $ddl;
}

function db_create_table(string $table, array $def): void
{
    $cols = [];
    foreach ($def['columns'] as $name => $colDef) {
        $cols[] = db_column_ddl($name, $colDef);
    }
    // ow_plugins / ow_settings 以字符串列为主键
    if ($table === 'ow_plugins') {
        $cols[] = 'PRIMARY KEY (' . ident('id') . ')';
    }
    q('CREATE TABLE ' . ident($table) . ' (' . implode(', ', $cols) . ')');
    foreach ($def['indexes'] as $idx) {
        db_create_index($table, $idx);
    }
}

function db_create_index(string $table, array $idx): void
{
    $unique = in_array('unique', $idx, true) || (($idx['unique'] ?? false) === true);
    $cols = array_values(array_filter($idx, 'is_string'));
    if (!$cols) {
        return;
    }
    $name = 'idx_' . $table . '_' . implode('_', $cols);
    $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . ident($name)
        . ' ON ' . ident($table) . ' (' . implode(', ', array_map('ident', $cols)) . ')';
    try {
        q($sql);
    } catch (Throwable $e) {
        // 索引已存在等：幂等忽略
    }
}

// 结构同步：缺表建表、缺列补列、缺索引补索引，可反复执行
function db_sync(): array
{
    $done = [];
    foreach (db_schema() as $table => $def) {
        if (!db_table_exists($table)) {
            db_create_table($table, $def);
            $done[] = "建表 $table";
            continue;
        }
        $existing = db_columns($table);
        foreach ($def['columns'] as $name => $colDef) {
            if (!in_array($name, $existing, true)) {
                q('ALTER TABLE ' . ident($table) . ' ADD COLUMN ' . db_column_ddl($name, $colDef));
                $done[] = "补列 $table.$name";
            }
        }
        foreach ($def['indexes'] as $idx) {
            db_create_index($table, $idx);
        }
    }
    return $done;
}
