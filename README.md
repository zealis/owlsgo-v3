# owlsgo v3

一个极简原生 PHP 社区论坛。**纯原生、无框架、无 Composer 依赖**，入口 `index.php` 在网站根目录（无需指定目录），支持 SQLite、MySQL 和 PostgreSQL。适合社区站点、低成本部署和 AI 二次开发。

## 特点

- 纯原生 PHP（8.1+），单入口 + 分层核心库（`app/core/` 六个文件）+ 可选模块（`app/optional/`）
- 三数据库一套声明式结构定义（`db_schema()`），后台「结构同步」幂等补表/补列/补索引，不用正则解析 SQL
- 完整论坛功能：版块（子版块/版主管辖）、帖子（置顶/锁定/精华/标题高亮/移动/先审后显）、评论（楼层/引用/@提及/编辑标记/审核）、点赞、收藏、通知中心、公告、搜索、找回密码、只读 API（token）
- 用户中心八分组：资料/账号/头像（上传+图案候选）/封面/隐私/外观（浅色/深色/跟随系统，服务端输出无闪烁）/密码/API 令牌
- 后台 14 区：仪表盘（结构同步/OPcache 清理）、版块、用户组（29 权限键勾选）、用户（批量禁访禁言改组删号、重置密码）、内容（待审+批量移动删除）、评论、附件、公告、回收站（恢复=删除的严格逆操作并 recount）、日志（CSV 导出）、计划任务（令牌/启停/立即运行）、设置（五分组 + Logo 上传恢复）、插件（ZIP 上传/启停/卸载）、在线升级（manifest + sha256 校验）
- 插件机制：manifest（hooks/routes/cron/settings）、钩子过滤、路由接管、自定义页面与资源路由（示例 `app/plugins/owlsgo-demo/`）
- 前端：自研设计系统（CSS 变量 + 深浅色 + 响应式双栏/移动抽屉）、AJAX 交互（点赞/收藏/管理/评论无刷新）、图片灯箱、长内容折叠、编辑器（草稿 localStorage/预览/附件上传插入），**无 JS 时全部表单可正常提交**
- 旧浏览器兼容：CSS 不用 `@layer`/`:has()`/`color-mix()`/`clamp()`/`dvh`/嵌套；JS 为 ES5
- 安全基线：CSP `script-src 'self'`（零内联脚本）、CSRF 双提交、HMAC 签名登录 Cookie、PDO 预处理、上传扩展名+MIME 双白名单 + 内容嗅探、输出统一转义、算术验证码、限流与封禁

## 目录结构

```
index.php            单入口：引导 + 全部前台路由
router.php           PHP 内置服务器开发路由
app/
  version.php        版本号（每次改代码递增）
  core/              核心库：db(三库抽象+声明式结构) base(工具/设置/URL)
                     security(认证/CSRF/权限/验证码/限流) content(正文白名单渲染)
                     forum(领域逻辑/计数/回收站/附件) view(页面骨架与构件)
  optional/          可选模块：Setup 安装器 / Admin 后台 / Plugin 插件 / Cron 计划任务
  assets/            index.css 设计系统 / index.js 交互 / index.svg 站点 Logo
  plugins/           插件目录（slug=目录名）
  data/              运行时数据：db.php、install.lock、SQLite 库（禁止 Web 直访）
  upload/            附件（按 年/月 存储）
  avatars/           头像与封面
.htaccess            Apache 伪静态 + 目录保护
nginx.htaccess       Nginx 配置参考
```

## 部署

1. 上传全部文件到网站目录，确保 `index.php` 位于网站根。
2. **必须禁止 Web 直接访问 `app/data/`、`app/core/`、`app/optional/`、`app/plugins/`**（`.htaccess`/`nginx.htaccess` 已内置，访问 `https://域名/app/data/` 应返回 403）。
3. 伪静态（可选，后台可关）：Nginx `try_files $uri $uri/ /index.php?$query_string;`；Apache 启用 mod_rewrite。
4. 浏览器访问站点，按安装页选择数据库、设置站点名与管理员。
5. 计划任务（可选）：外部定时每分钟 GET `https://域名/index.php?a=cron&token=…`。

本地冒烟：

```bash
php -d opcache.enable=0 -S 127.0.0.1:8099 router.php
```

## 数据口径（与写入路径对称）

- `threads.replies` = 该帖未删且已通过评论数（不含首帖）
- `forums.threads` / `posts` = 未删帖数 / 未删帖 + 未删已通过评论
- 删除一律进回收站（`ow_trash` 保存原行 JSON），恢复是删除的严格逆操作并自动 recount
- 后台「结构同步」幂等补齐新表/新列/新索引（升级后执行一次）

## 二次开发

- 加字段：只改 `app/core/db.php` 的 `db_schema()` 声明，后台执行一次结构同步（三库通吃）
- 加权限键：`security.php` 的 `perm_keys()` + 用户组勾选
- 加设置项：`base.php` 的 `default_settings()` + 后台设置分组
- 写插件：复制 `app/plugins/owlsgo-demo/` 改名，manifest 返回 hooks/routes/cron/settings
- 术语：帖子 = thread、评论 = reply；界面文案写「评论」不写「回帖」
