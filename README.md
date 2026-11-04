# BiliShort · Short links for bilibili videos

Turn a bilibili **BV id, av id, video URL or official short link** into one stable short link. Pure algorithm — no records, no database, no cookies.

The UI lives only at `/bilibili`; the site root returns 404 on purpose, so a random visitor sees nothing.

> 简体中文：[README.zh-CN.md](README.zh-CN.md)

## Features

- **Four input shapes**: BV id, av id (with or without the `av` prefix), any bilibili video URL, and official `b23.tv` / `bili2233.cn` short links — pasting a whole share message works too
- **Short code redirect**: `/<code>` → 302 to the bilibili player, embeddable in an iframe
- **Direct paths**: `/BV1es411D7sW` and `/av2271112` work as well
- **Parameter passthrough**: `p`, `t`, `danmaku`, `autoplay`, `high_quality`, `as_wide` are forwarded; everything else is dropped
- **Easter eggs**: `/1`, `/114514`, `/8848`, `/xiaomi`, `/redmi`, `/huawei`, `/deepseek`; `/google` jumps to an account's newest upload
- **Stateless**: no accounts, no database, no analytics, no cookies

## Deploy

Requires PHP 7.4+ (7.1 works) and a writable `cache/`; Apache with `mod_rewrite` and `mod_headers`.

1. Upload everything to the web root (subdirectories work — uncomment `RewriteBase` in `.htaccess` and set the real path)
2. `chmod 755 cache`, owned by the PHP process user
3. Open `/bilibili`; run `php selfcheck.php` to verify the install

Equivalent nginx config:

```nginx
location ~ ^/(config|lib|selfcheck)\.php$|^/(views|tools|cache)/ { deny all; }
location / { try_files $uri /index.php?$query_string; }
```

## Layout

```
.
├── .htaccess          routing, access control, security headers
├── index.php          single entry (routing, page render, API)
├── config.php         configuration and alphabet tables   ← not directly accessible
├── lib.php            algorithms and helpers              ← not directly accessible
├── views/tool.html    page template                       ← not directly accessible
├── assets/            app.css / app.js / favicon.svg / og.png
├── cache/             runtime cache                       ← whole directory denied
├── selfcheck.php      self-check (CLI only)               ← not directly accessible
└── tools/shortcode.py Python reference implementation and cross-check
```

## Routes

| Path | Behaviour |
| --- | --- |
| `/` | 404 |
| `/bilibili` | converter page |
| `?bilibili&api=1&q=…` | converter API (JSON) |
| `/<code>` | 302 to the player |
| `/BVxxxxxxxxxx`, `/av<number>` | 302 to the player |
| `/robots.txt`, `/sitemap.xml` | generated dynamically |
| anything else | 404 |

API response:

```json
{"ok":true,"bv":"BV1es411D7sW","av":2271112,"code":"44X4XM444",
 "url":"https://example.com/44X4XM444",
 "player":"https://player.bilibili.com/player.html?bvid=BV1es411D7sW",
 "embed":"<iframe …></iframe>"}
```

## Algorithm (frozen)

```
av   → fixed 9 digits in base 61 → position shuffle → table substitution → code
code → table lookup → inverse shuffle → base 61 decode → av → BV
```

`61^9 ≈ 1.17e16` covers bilibili's `2^51` avid ceiling. The same video always yields the same code, on any device, at any time.

Do **not** edit `ID_MAP`, `SHUFFLE`, `BV_ALPHABET` or related constants — every code already handed out would break. Before touching the algorithm:

```bash
php selfcheck.php          # 73 checks
python3 tools/shortcode.py selftest
php tools/crosscheck.php   # PHP vs Python, record by record
```

The Python reference matches PHP on 10,020 samples (boundaries, full-range randoms, reverse decoding), with zero differences.

Known limit: codes are case-sensitive. If a messenger lowercases the link, it will not resolve — inherent to a substitution-based scheme, independent of code length.

## Security

- `.htaccess` denial plus in-file `APP_ENTRY` / `PHP_SAPI` guards
- CSP without `unsafe-inline`; all CSS/JS external; `frame-ancestors 'none'`
- Redirect target can only be `player.bilibili.com`; parameters are whitelisted, so the site cannot be used as an open redirector
- Outbound requests limited to `b23.tv` / `bili2233.cn`, max 3 hops, cached 30 days, rate-limited per IP (60/hour)
- Trusts `REMOTE_ADDR` only; visitor IPs are never logged

## SEO and sharing

- `/bilibili` is indexable: canonical, description, `WebApplication` + `FAQPage` structured data, `robots.txt` allowing only that path, `sitemap.xml` listing only that path
- Short codes and redirect paths always send `X-Robots-Tag: noindex` (a 302 would not be indexed anyway)
- Share card: 1200×630 `assets/og.png` with `og:*` and `twitter:summary_large_image`
- Light/dark aware, no emoji, all icons inline SVG

## License

MIT, see [LICENSE](LICENSE).
