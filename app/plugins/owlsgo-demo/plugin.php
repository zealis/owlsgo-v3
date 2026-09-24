<?php
// owlsgo 示例插件：演示 manifest 的全部能力
// 复制本目录改名即可开发自己的插件；slug = 目录名
return [
    'name' => 'owlsgo 示例插件',
    'version' => '1.0.0',
    'description' => '演示钩子、自定义路由、后台页与计划任务的写法',

    // 钩子：content.render / thread.after_create / reply.after_create …
    'hooks' => [
        // 正文渲染后过滤：给外部链接加提示图标（演示，默认不改动）
        'content.render' => function ($html, $ctx) {
            return $html;
        },
    ],

    // 自定义路由：a=demo_hello 时接管
    'routes' => [
        'demo_hello' => function () {
            page('示例插件', '<div class="panel"><div class="panel-body"><h2>示例插件已启用</h2><p>这是插件接管的路由页面。</p></div></div>', sidebar_html());
        },
    ],

    // 计划任务：注册后可在后台「计划任务」看到
    'cron' => [
        ['name' => 'demo_tick', 'interval' => 3600, 'callback' => function () {
            return 'demo tick';
        }],
    ],

    // 插件级默认设置（启用时写入 ow_settings，前缀插件 id）
    'settings' => [
        'demo_greeting' => '你好，世界',
    ],
];
