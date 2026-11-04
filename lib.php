<?php
/**
 * BiliShort — 全部逻辑
 *
 * 分五段：短码算法（冻结）/ URL 与输出 / 输入归一化 / 出站与缓存 / 彩蛋。
 * 算法段与 Python 参考实现 tools/shortcode.py 逐位等价，改动前请先跑
 * tools/crosscheck 与 selfcheck.php。
 */
declare(strict_types=1);

if (!defined('APP_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

/* ==================== 一、BV ⇄ AV（B 站现行算法） ==================== */

function bv_to_av(string $bv): ?int
{
    if (strlen($bv) !== 12 || $bv[0] !== 'B' || $bv[1] !== 'V') {
        return null;
    }
    $b = str_split($bv);
    [$b[3], $b[9]] = [$b[9], $b[3]];
    [$b[4], $b[7]] = [$b[7], $b[4]];

    $n = 0;
    for ($i = 3; $i <= 11; $i++) {
        $d = strpos(BV_ALPHABET, $b[$i]);
        if ($d === false) {
            return null;
        }
        $n = $n * 58 + $d;
    }
    return ($n ^ XOR_CODE) & MASK_CODE;
}

function av_to_bv(int $av): ?string
{
    if ($av < 0 || $av >= MAX_AID) {
        return null;
    }
    $n = ($av | MAX_AID) ^ XOR_CODE;

    $b = ['B', 'V', '1', '', '', '', '', '', '', '', '', ''];
    for ($i = 11; $i >= 3; $i--) {
        $b[$i] = BV_ALPHABET[$n % 58];
        $n     = intdiv($n, 58);
    }
    if ($n !== 0) {
        return null;
    }
    [$b[3], $b[9]] = [$b[9], $b[3]];
    [$b[4], $b[7]] = [$b[7], $b[4]];
    return implode('', $b);
}

/* ==================== 二、AV ⇄ 短码（冻结） ==================== */

/** 密文字符 → 数值，用 ID_MAP 建一次静态查找表 */
function shortcode_table(): array
{
    static $tab = null;
    if ($tab === null) {
        $tab = [];
        for ($v = 0; $v < 61; $v++) {
            $tab[ID_MAP[$v]] = $v;
        }
    }
    return $tab;
}

function av_to_shortcode(int $av): ?string
{
    if ($av < 0 || $av >= MAX_AID) {
        return null;
    }

    $d = array_fill(0, CODE_LEN, 0);
    $n = $av;
    for ($i = CODE_LEN - 1; $i >= 0; $i--) {
        $d[$i] = $n % 61;
        $n     = intdiv($n, 61);
    }
    if ($n !== 0) {
        return null;   /* 超出容量 */
    }

    $out = '';
    for ($i = 0; $i < CODE_LEN; $i++) {
        $out .= ID_MAP[$d[SHUFFLE[$i]]];
    }
    return $out;
}

function shortcode_to_av(string $code): ?int
{
    if (strlen($code) !== CODE_LEN) {
        return null;
    }
    $tab = shortcode_table();

    $p = [];
    for ($i = 0; $i < CODE_LEN; $i++) {
        $c = $code[$i];
        if (!isset($tab[$c])) {
            return null;
        }
        $p[$i] = $tab[$c];
    }

    $d = [];
    for ($j = 0; $j < CODE_LEN; $j++) {
        $d[$j] = $p[SHUFFLE_INV[$j]];
    }

    $av = 0;
    for ($i = 0; $i < CODE_LEN; $i++) {
        $av = $av * 61 + $d[$i];
    }
    return $av;
}

/* ==================== 三、对外接口 ==================== */

function is_valid_bv(string $s): bool
{
    return (bool)preg_match('/^BV[0-9A-Za-z]{10}$/', $s);
}

function is_valid_code(string $s): bool
{
    return strlen($s) === CODE_LEN && shortcode_to_av($s) !== null;
}

function bv_to_shortcode(string $bv): ?string
{
    $av = bv_to_av($bv);
    return $av === null ? null : av_to_shortcode($av);
}

function shortcode_to_bv(string $code): ?string
{
    $av = shortcode_to_av($code);
    if ($av === null) {
        return null;
    }
    $bv = av_to_bv($av);
    return ($bv !== null && is_valid_bv($bv)) ? $bv : null;
}

/* ==================== 四、URL 与输出 ==================== */

/** 播放器参数：只透传白名单，值统一转义 */
function player_params(array $src): array
{
    $out = [];
    foreach (PLAYER_PASSTHROUGH as $k) {
        if (!isset($src[$k]) || !is_string($src[$k])) {
            continue;
        }
        $v = trim($src[$k]);
        if ($v === '' || strlen($v) > 32 || !preg_match('/^[0-9A-Za-z_.,-]+$/', $v)) {
            continue;
        }
        $out[$k] = $v;
    }
    return $out;
}

function player_url(string $bv, array $params = []): string
{
    $url = 'https://' . PLAYER_HOST . '/player.html?bvid=' . rawurlencode($bv);
    foreach ($params as $k => $v) {
        $url .= '&' . $k . '=' . rawurlencode((string)$v);
    }
    return $url . PLAYER_QUERY;
}

function player_embed(string $bv, array $params = []): string
{
    return '<iframe src="' . htmlspecialchars(player_url($bv, $params), ENT_QUOTES, 'UTF-8')
        . '" width="800" height="450" frameborder="0" allowfullscreen loading="lazy"'
        . ' referrerpolicy="no-referrer" title="bilibili player"></iframe>';
}

/** 站点基路径，子目录部署时自动带上 */
function base_path(): string
{
    $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return ($d === '/' || $d === '.') ? '' : rtrim($d, '/');
}

/** 站点对外源，SITE_ORIGIN 优先；Host 非法则退回默认值，避免把脏 Host 写进 canonical */
function site_origin(): string
{
    if (SITE_ORIGIN !== '') {
        return rtrim(SITE_ORIGIN, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}

function site_url(string $path = ''): string
{
    return site_origin() . base_path() . ($path === '' ? '/' : $path);
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function not_found(bool $asJson = false): void
{
    if ($asJson) {
        json_out(['ok' => false, 'error' => '没找到这个地址'], 404);
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $home = base_path() . '/';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>404 — ' . SITE_NAME_ZH . '</title>'
        . '<link rel="stylesheet" href="' . $home . 'assets/app.css">'
        . '<link rel="icon" href="' . $home . 'assets/favicon.svg" type="image/svg+xml">'
        . '</head><body class="page-narrow"><main>'
        . '<p class="code">404</p><h1>这个地址没有对应的视频</h1>'
        . '<p class="muted">短码可能被改过大小写，也可能视频已经删除或转为私密。</p>'
        . '<p><a class="btn" href="' . $home . '">回到转换页</a></p>'
        . '</main></body></html>';
    exit;
}

function redirect_to_player(string $bv, array $params = []): void
{
    if (!is_valid_bv($bv)) {
        not_found();
    }
    header('Location: ' . player_url($bv, $params), true, 302);
    header('Cache-Control: public, max-age=300');
    header('X-Robots-Tag: noindex');
    exit;
}

/* ==================== 五、输入归一化（自动延伸） ==================== */

/**
 * 从一段纯 URL 里取视频目标，不发请求。
 * 返回 ['kind' => 'bv'|'av'|'short', 'value' => string] 或 null。
 */
function parse_video_url(string $url): ?array
{
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return null;
    }
    $host = strtolower($p['host']);
    $path = $p['path'] ?? '';
    $query = $p['query'] ?? '';

    if (preg_match('#(?:^|\.)bilibili\.com$#', $host)) {
        if (preg_match('#/video/(BV[0-9A-Za-z]{10})#', $path, $m)) {
            return ['kind' => 'bv', 'value' => $m[1]];
        }
        if (preg_match('#/video/av(\d{1,16})#i', $path, $m)) {
            return ['kind' => 'av', 'value' => $m[1]];
        }
        /* 播放页旧式：/s/video/av123、?aid=123 或 ?bvid=BV... */
        parse_str($query, $q);
        if (!empty($q['bvid']) && is_string($q['bvid']) && is_valid_bv($q['bvid'])) {
            return ['kind' => 'bv', 'value' => $q['bvid']];
        }
        if (!empty($q['aid']) && is_string($q['aid']) && ctype_digit($q['aid'])) {
            return ['kind' => 'av', 'value' => $q['aid']];
        }
    }
    return null;
}

/** 文本里出现的第一个 URL */
function first_url(string $text): ?string
{
    if (preg_match('#https?://[^\s<>"\'）)】，。；]+#i', $text, $m)) {
        return $m[0];
    }
    return null;
}

/**
 * 把用户输入统一成 BV 号。
 * 支持：BV 号（前缀大小写容错）、av 号、纯数字 avid、B 站视频链接、
 *      官方短链 b23.tv / bili2233.cn、本站短码，以及夹带链接的整段分享文案。
 */
function normalize_to_bv(string $q): ?string
{
    $q = trim($q);
    if ($q === '' || strlen($q) > 300) {
        return null;
    }

    /* 1) 整段文案里带链接 */
    $url = first_url($q);
    if ($url !== null) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (in_array($host, B23_HOSTS, true)) {
            return b23_resolve($url);
        }
        $hit = parse_video_url($url);
        if ($hit !== null) {
            return $hit['kind'] === 'bv' ? $hit['value'] : av_to_bv((int)$hit['value']);
        }
        /* 链接不认识就继续按裸输入处理，前面可能同时写了 BV 号 */
    }

    /* 2) 裸 BV 号：只修正固定的 BV 前缀 */
    if (preg_match('/^[Bb][Vv][0-9A-Za-z]{10}$/', $q)) {
        $bv = 'BV' . substr($q, 2);
        return is_valid_bv($bv) ? $bv : null;
    }

    /* 3) av 号 / 纯数字 */
    if (preg_match('/^(?:av)?(\d{1,16})$/i', $q, $m)) {
        return av_to_bv((int)$m[1]);
    }

    /* 4) 本站短码 */
    if (strlen($q) === CODE_LEN) {
        $bv = shortcode_to_bv($q);
        if ($bv !== null) {
            return $bv;
        }
    }

    /* 5) 文案里没链接，但写了 BV 号 */
    if (preg_match('/(BV[0-9A-Za-z]{10})/', $q, $m) && is_valid_bv($m[1])) {
        return $m[1];
    }
    return null;
}

/* ==================== 六、出站请求与缓存 ==================== */

/**
 * 出站 GET。返回 ['code'=>int,'body'=>string,'final'=>string,'location'=>string]
 * $follow 为 true 时最多跟随 3 跳，final 是有效地址。
 */
function http_request(string $url, bool $follow = false): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Referer: https://www.bilibili.com/',
            'Accept: application/json, text/html;q=0.9,*/*;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($body === false) {
        return null;
    }
    return ['code' => $code, 'body' => (string)$body, 'final' => $final, 'location' => $loc];
}

function cache_read(string $file, int $ttl): ?array
{
    if (!is_file($file) || time() - (int)filemtime($file) > $ttl) {
        return null;
    }
    $j = json_decode((string)@file_get_contents($file), true);
    return is_array($j) ? $j : null;
}

function cache_write(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function client_ip(): string
{
    /* 本站不经 CDN：只信 REMOTE_ADDR，转发头可被伪造 */
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** 简单的固定窗口限速，落在缓存目录，无需数据库 */
function rate_limited(string $bucket, int $max, int $window): bool
{
    if ($max <= 0) {
        return false;
    }
    $ip = client_ip();
    if ($ip === '') {
        return false;
    }
    $file = CACHE_DIR . '/rl_' . $bucket . '_' . substr(hash('sha256', $ip), 0, 16) . '.json';
    $now  = time();
    $row  = cache_read($file, PHP_INT_MAX) ?: [];
    if (empty($row['start']) || $now - (int)$row['start'] >= $window) {
        $row = ['start' => $now, 'count' => 0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    cache_write($file, $row);
    return $row['count'] > $max;
}

/**
 * 解析 B 站官方短链（b23.tv / bili2233.cn）。
 * 只允许白名单域名，最多跟 3 跳，结果缓存 30 天，并按 IP 限速。
 */
function b23_resolve(string $url): ?string
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (!in_array($host, B23_HOSTS, true)) {
        return null;
    }
    $key  = CACHE_DIR . '/b23_' . hash('sha256', $url) . '.json';
    $c    = cache_read($key, B23_TTL);
    if (is_array($c) && !empty($c['bv']) && is_valid_bv((string)$c['bv'])) {
        return (string)$c['bv'];
    }
    if (rate_limited('b23', B23_RATE_MAX, B23_RATE_WINDOW)) {
        return null;
    }

    $res = http_request($url, true);
    if ($res === null) {
        return null;
    }
    $bv = null;
    foreach ([$res['final'], $res['location']] as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $hit = parse_video_url($candidate);
        if ($hit !== null) {
            $bv = $hit['kind'] === 'bv' ? $hit['value'] : av_to_bv((int)$hit['value']);
            break;
        }
    }
    /* 有的场景返回 200 + 页面内跳转，扫描正文里的视频链接 */
    if ($bv === null && preg_match('#bilibili\.com/video/(BV[0-9A-Za-z]{10})#', $res['body'], $m)) {
        $bv = $m[1];
    }
    if ($bv === null || !is_valid_bv($bv)) {
        return null;
    }
    cache_write($key, ['bv' => $bv, 'ts' => time()]);
    return $bv;
}

/* ==================== 七、动态彩蛋 ==================== */

function wbi_make(string $img, string $sub): string
{
    static $tab = [46, 47, 18, 2, 53, 8, 23, 32, 15, 50, 10, 31, 58, 3, 45, 35, 27, 43, 5, 49,
                   33, 9, 42, 19, 29, 28, 14, 39, 12, 38, 41, 13, 37, 48, 7, 16, 24, 55, 40, 61,
                   26, 17, 0, 1, 60, 51, 30, 4, 22, 25, 54, 21, 56, 59, 6, 63, 57, 62, 11, 36,
                   20, 34, 44, 52];
    $src = $img . $sub;
    $out = '';
    foreach ($tab as $i) {
        if (isset($src[$i])) {
            $out .= $src[$i];
        }
    }
    return substr($out, 0, 32);
}

function wbi_filename(string $url): string
{
    $p = parse_url($url, PHP_URL_PATH);
    return is_string($p) ? (string)pathinfo($p, PATHINFO_FILENAME) : '';
}

function wbi_mixin_key(): ?string
{
    $file = CACHE_DIR . '/wbi.json';
    $c = cache_read($file, 86400);
    if (is_array($c) && !empty($c['img']) && !empty($c['sub'])) {
        return wbi_make((string)$c['img'], (string)$c['sub']);
    }

    $res = http_request('https://api.bilibili.com/x/web-interface/nav');
    if ($res === null) {
        return null;
    }
    $j   = json_decode($res['body'], true);
    $img = wbi_filename((string)($j['data']['wbi_img']['img_url'] ?? ''));
    $sub = wbi_filename((string)($j['data']['wbi_img']['sub_url'] ?? ''));
    if ($img === '' || $sub === '') {
        return null;
    }
    cache_write($file, ['img' => $img, 'sub' => $sub]);
    return wbi_make($img, $sub);
}

function wbi_sign(array $params, string $mixin): string
{
    $params['wts'] = time();
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $v = preg_replace("/[!'()*]/", '', (string)$v);
        $pairs[] = $k . '=' . rawurlencode($v);
    }
    $q = implode('&', $pairs);
    return $q . '&w_rid=' . md5($q . $mixin);
}

function live_latest_bv(): ?string
{
    $file = CACHE_DIR . '/live.json';
    $c = cache_read($file, CACHE_TTL);
    if (is_array($c) && !empty($c['bv']) && is_valid_bv((string)$c['bv'])) {
        return (string)$c['bv'];
    }

    $mixin = wbi_mixin_key();
    if ($mixin === null) {
        return null;
    }
    $query = wbi_sign([
        'mid'      => LIVE_UID,
        'ps'       => 1,
        'pn'       => 1,
        'order'    => 'pubdate',
        'platform' => 'web',
    ], $mixin);

    $res = http_request('https://api.bilibili.com/x/space/wbi/arc/search?' . $query);
    if ($res === null) {
        return null;
    }
    $j  = json_decode($res['body'], true);
    $bv = $j['data']['list']['vlist'][0]['bvid'] ?? null;
    if (!is_string($bv) || !is_valid_bv($bv)) {
        return null;
    }
    cache_write($file, ['bv' => $bv, 'ts' => time()]);
    return $bv;
}
