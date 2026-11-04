<?php
/**
 * BiliShort — 唯一入口
 *
 * 路由（按优先级）：
 *   /bilibili                         转换页（唯一入口，根路径不暴露）
 *   ?bilibili[&api=1&q=…]             转换页 / 转换接口（兼容旧地址）
 *   /robots.txt  /sitemap.xml         动态生成，避免写死域名
 *   /google                           彩蛋：某账号最新投稿
 *   /<彩蛋词>                          固定彩蛋
 *   /BVxxxxxxxxxx  /av<数字>           直接跳播放器
 *   /<短码>                            短码跳播放器
 *   其余（含根路径）                    404
 */
declare(strict_types=1);

define('APP_ENTRY', true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

ob_start();
register_shutdown_function(static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' && ob_get_level() > 0) {
        ob_end_clean();
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    not_found();
}

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path    = is_string($rawPath) ? trim(rawurldecode($rawPath), '/') : '';

/* ---------- 1) robots.txt / sitemap.xml ---------- */

if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    $base = base_path();
    echo "User-agent: *\nDisallow: /\n";
    echo 'Allow: ' . $base . '/bilibili' . "\n";
    echo 'Sitemap: ' . site_url('/sitemap.xml') . "\n";
    exit;
}

if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    $urls = array_merge([site_url('/bilibili')], array_map(static function (string $m): string {
        return rtrim($m, '/') . '/bilibili';
    }, SITE_MIRRORS));
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1, 'UTF-8') . '</loc>'
            . '<changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
    }
    echo '</urlset>' . "\n";
    exit;
}

/* ---------- 2) 转换页 / 转换接口 ---------- */

if ($path === 'bilibili' || array_key_exists('bilibili', $_GET)) {
    if (isset($_GET['api'])) {
        convert_api();
    }
    render_tool_page();
    exit;
}

/* ---------- 3) 彩蛋（优先于短码） ---------- */

$lower  = strtolower($path);
$params = player_params($_GET);

if ($lower === 'google') {
    $bv = live_latest_bv();
    if ($bv === null) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
        exit('暂时取不到，稍后再试');
    }
    redirect_to_player($bv, $params);
}

if (isset(EGG_MAP[$lower])) {
    redirect_to_player(EGG_MAP[$lower], $params);
}

/* ---------- 4) 路径上直接写 BV 号 / av 号 ---------- */

if (is_valid_bv($path)) {
    redirect_to_player($path, $params);
}

if (preg_match('/^(?:av)?(\d{1,16})$/i', $path, $m)) {
    $bv = av_to_bv((int)$m[1]);
    if ($bv !== null) {
        redirect_to_player($bv, $params);
    }
}

/* ---------- 5) 短码 ---------- */

$bv = shortcode_to_bv($path);
if ($bv !== null) {
    redirect_to_player($bv, $params);
}

/* ---------- 6) 其余 404 ---------- */

not_found();

/* ==================== 页面与接口 ==================== */

function convert_api(): void
{
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if ($q === '' || strlen($q) > 300) {
        json_out(['ok' => false, 'error' => '请输入 BV 号、av 号、视频链接或短码']);
    }

    $bv = normalize_to_bv($q);
    if ($bv === null) {
        json_out(['ok' => false, 'error' => '没认出这是哪个视频，检查一下输入']);
    }

    $code = bv_to_shortcode($bv);
    if ($code === null) {
        json_out(['ok' => false, 'error' => '这个视频号暂时无法生成短码']);
    }

    $params = player_params($_GET);
    json_out([
        'ok'     => true,
        'bv'     => $bv,
        'av'     => bv_to_av($bv),
        'code'   => $code,
        'url'    => site_url('/' . $code),
        'player' => player_url($bv, $params),
        'embed'  => player_embed($bv, $params),
    ]);
}

/** 页面上的问答，同时用于 FAQ 结构化数据（两处必须一致） */
function faq_items(): array
{
    return [
        ['短码会过期吗？', '不会。短码是拿视频号当场算出来的，不存记录、不建数据库，只要视频还在，短码就一直有效。'],
        ['同一个视频的短码会变吗？', '不会。算法是确定的，任何时候、任何设备算出来都一样。'],
        ['短码区分大小写吗？', '区分。复制和转发时别用会自动改大小写的工具，否则可能打不开。'],
        ['能跳到 B 站以外的网址吗？', '不能。这里只认视频号，跳转目标固定是 B 站播放器，不会变成随便指向哪里都能用的跳板。'],
        ['点开是视频页还是播放器？', '直接是播放器，可以嵌在别的网页里。不登录 B 站账号时最高 480p。'],
        ['播放器能带参数吗？', '可以。短链后面可以带 page（第几 P）、t（起播秒数）、danmaku、autoplay、high_quality、as_wide，这些会原样传给播放器。'],
        ['路径上能直接写 BV 号或 av 号吗？', '能。/BV1xx411c7mD 与 /av2271112 都会跳到对应播放器，不用先算短码。'],
        ['支持 b23.tv 那种分享短链吗？', '支持。把整段分享文案粘进来就行，会先解开官方短链再生成短码。'],
        ['有彩蛋吗？', '有。几个固定词会跳到特定视频；/google 会跳到某个账号最新的一条投稿。大小写都不区分。'],
        ['为什么有的短码打不开？', '多半是视频被删或转成私密了，这时短码就没目标了；也可能是复制过程中大小写被改过。'],
    ];
}

function faq_html(): string
{
    $html = '';
    foreach (faq_items() as $i => $item) {
        $id = 'faq-' . $i;
        $html .= '<div class="faq-item">'
            . '<button class="faq-q" type="button" aria-expanded="false" aria-controls="' . $id . '">'
            . '<span>' . htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') . '</span>'
            . '<svg class="chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>'
            . '</button>'
            . '<div class="faq-a" id="' . $id . '" hidden><p>'
            . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8')
            . '</p></div></div>';
    }
    return $html;
}

function faq_jsonld(): string
{
    $items = [];
    foreach (faq_items() as $item) {
        $items[] = [
            '@type'          => 'Question',
            'name'           => $item[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
        ];
    }
    return json_encode([
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function render_tool_page(): void
{
    $tpl = __DIR__ . '/views/tool.html';
    if (!is_readable($tpl)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('页面模板缺失');
    }

    $canonical = site_url('/bilibili');
    $desc = '把 B 站视频的 BV 号、av 号或链接换成一条固定短链，纯算法生成，不存记录、不建数据库；短码可被 /BV 号、/av 号直接替代，也可嵌入播放器。';

    $app = json_encode([
        '@context'            => 'https://schema.org',
        '@type'               => 'WebApplication',
        'name'                => SITE_NAME_ZH,
        'alternateName'       => SITE_NAME_EN,
        'url'                 => $canonical,
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem'     => 'Any',
        'inLanguage'          => ['zh-CN', 'en'],
        'description'         => $desc,
        'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'CNY'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $map = [
        '{{LANG}}'      => 'zh-CN',
        '{{BASE}}'      => base_path(),
        '{{SITE_NAME}}' => SITE_NAME_ZH,
        '{{SITE_EN}}'   => SITE_NAME_EN,
        '{{TITLE}}'     => SITE_NAME_ZH . ' · BV 号转短链 / ' . SITE_NAME_EN,
        '{{DESC}}'      => $desc,
        '{{CANONICAL}}' => $canonical,
        '{{OG_IMAGE}}'  => site_url('/assets/og.png'),
        '{{TAGLINE}}'   => SITE_TAGLINE_ZH,
        '{{FAQ}}'       => faq_html(),
        '{{JSONLD_1}}'  => $app,
        '{{JSONLD_2}}'  => faq_jsonld(),
        '{{YEAR}}'      => date('Y'),
    ];

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    header('X-Robots-Tag: index, follow');
    echo strtr((string)file_get_contents($tpl), $map);
}
