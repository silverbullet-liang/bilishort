# BiliShort · B 站视频短链

把 B 站视频的 **BV 号 / av 号 / 视频链接 / 官方短链** 换成一条固定短链，纯算法生成，不存记录、不建数据库、不放 Cookie。

入口刻意只开在 `/bilibili`，根路径直接 404，别人打域名看不到东西。

> English: [README.md](README.md)

## 功能

- **四种输入**：BV 号、av 号（含纯数字）、B 站视频链接、`b23.tv` / `bili2233.cn` 官方短链，整段分享文案粘进来也能识别
- **短码跳转**：`/<短码>` 直接 302 到 B 站播放器，可嵌入 iframe
- **路径直跳**：`/BV1es411D7sW`、`/av2271112` 同样有效
- **参数透传**：`p` / `t` / `danmaku` / `autoplay` / `high_quality` / `as_wide` 会带到播放器，其余参数一律丢弃
- **固定彩蛋**：`/1`、`/114514`、`/8848`、`/xiaomi`、`/redmi`、`/huawei`、`/deepseek`；`/google` 跳某账号最新投稿
- **零状态**：无账号、无数据库、无统计、无 Cookie

## 部署

需要 PHP 7.4+（7.1 亦可）与可写的 `cache/` 目录；Apache 开 `mod_rewrite`、`mod_headers`。

1. 上传全部文件到站点根目录（子目录也行，`.htaccess` 里 `RewriteBase` 放开并改成实际路径）
2. `chmod 755 cache`，属主与 PHP 进程一致
3. 访问 `/bilibili` 使用；`php selfcheck.php` 跑一遍自检

nginx 需要等价配置：

```nginx
location ~ ^/(config|lib|selfcheck)\.php$|^/(views|tools|cache)/ { deny all; }
location / { try_files $uri /index.php?$query_string; }
```

## 目录

```
.
├── .htaccess          路由 + 访问控制 + 安全头
├── index.php          唯一入口（路由、页面渲染、接口）
├── config.php         配置与字符表          ← 禁止直接访问
├── lib.php            算法与工具函数        ← 禁止直接访问
├── views/tool.html    页面模板              ← 禁止直接访问
├── assets/            app.css / app.js / favicon.svg / og.png
├── cache/             运行时缓存            ← 整目录拒绝
├── selfcheck.php      自检（仅命令行）      ← 禁止直接访问
└── tools/shortcode.py 算法 Python 参考实现与交叉校验
```

## 路由

| 路径 | 行为 |
| --- | --- |
| `/` | 404 |
| `/bilibili` | 转换页 |
| `?bilibili&api=1&q=…` | 转换接口（JSON） |
| `/<短码>` | 302 到播放器 |
| `/BVxxxxxxxxxx`、`/av<数字>` | 302 到播放器 |
| `/robots.txt`、`/sitemap.xml` | 动态生成 |
| 其余 | 404 |

接口返回：

```json
{"ok":true,"bv":"BV1es411D7sW","av":2271112,"code":"44X4XM444",
 "url":"https://example.com/44X4XM444",
 "player":"https://player.bilibili.com/player.html?bvid=BV1es411D7sW",
 "embed":"<iframe …></iframe>"}
```

## 短码算法（已冻结）

```
av   → 61 进制定长 9 位 → 位置置乱 → 字符表替换 → 短码
短码 → 查表还原数值 → 逆置乱 → 61 进制还原 → av → BV
```

`61^9 ≈ 1.17e16`，覆盖 B 站 avid 上限 `2^51`。同一条视频在任何设备、任何时候算出的短码都一样。

**不要改** `ID_MAP`、`SHUFFLE`、`BV_ALPHABET` 等常量，否则已发出的短码全部失效。改动前先跑：

```bash
php selfcheck.php          # 73 项
python3 tools/shortcode.py selftest
php tools/crosscheck.php   # PHP 与 Python 逐条比对
```

Python 参考实现与 PHP 在 10020 组样本（含边界、全量程随机、反向解码）上逐条一致。

已知限制：短码区分大小写，转发时被自动转成小写会解不回原视频——这是分类映射方案的固有限制，与长短无关。

## 安全

- `.htaccess` 拒绝 + 文件内 `APP_ENTRY` / `PHP_SAPI` 双重拦截
- CSP 无 `unsafe-inline`，样式脚本全部外链；`frame-ancestors 'none'`
- 跳转目标只可能是 `player.bilibili.com`，参数走白名单，不会被当成任意跳转跳板
- 出站请求只允许 `b23.tv` / `bili2233.cn`，最多 3 跳，结果缓存 30 天，按 IP 限速（每小时 60 次）
- 只信 `REMOTE_ADDR`，不读转发头；日志不记录访问者 IP

## SEO 与分享

- `/bilibili` 可被收录：canonical、description、`WebApplication` + `FAQPage` 结构化数据、`robots.txt` 只放行该路径、`sitemap.xml` 只列该路径
- 短码与跳转路径一律 `X-Robots-Tag: noindex`（302 本身也不会被收录）
- 分享卡片：1200×630 `assets/og.png`，含 `og:*` 与 `twitter:summary_large_image`
- 浅色/深色自适应，无 Emoji，图标全内联 SVG

## 许可

MIT，见 [LICENSE](LICENSE)。
