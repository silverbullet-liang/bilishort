<?php
/**
 * BiliShort — 自检（仅命令行）
 *
 *   php selfcheck.php
 *
 * 校验三件事：短码算法常量自洽、算法往返正确、输入归一化与参数白名单符合预期。
 * 任何一项不一致都会打印 FAIL 并以非 0 退出，方便放进 CI 或部署脚本。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ENTRY', true);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

$fail   = 0;
$passed = 0;
$group  = '';

function section(string $name): void
{
    global $group;
    $group = $name;
    echo "\n" . $name . "\n";
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $fail, $passed;
    if ($cond) {
        $passed++;
        echo "  ok    {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}" . ($detail !== '' ? "   ({$detail})" : '') . "\n";
    }
}

function same_class(string $a, string $b): bool
{
    $f = static function (string $c): int {
        if ($c >= '0' && $c <= '9') return 0;
        if ($c >= 'A' && $c <= 'Z') return 1;
        return 2;
    };
    return $f($a) === $f($b);
}

echo "BiliShort 自检\n";

/* ---------- 字符表 ---------- */
section('字符表');
ok('ID_SRC 长度 61',   strlen(ID_SRC) === 61,   (string)strlen(ID_SRC));
ok('ID_MAP 长度 61',   strlen(ID_MAP) === 61,   (string)strlen(ID_MAP));
ok('ID_UNMAP 长度 61', strlen(ID_UNMAP) === 61, (string)strlen(ID_UNMAP));
ok('ID_SRC 无重复字符',   count(count_chars(ID_SRC, 1)) === 61);
ok('ID_MAP 无重复字符',   count(count_chars(ID_MAP, 1)) === 61);
ok('ID_UNMAP 无重复字符', count(count_chars(ID_UNMAP, 1)) === 61);

$fixed = [];
$classBad = [];
for ($i = 0; $i < 61; $i++) {
    $s = ID_SRC[$i];
    $m = ID_MAP[$i];
    if ($s === $m) {
        $fixed[] = $s;
    }
    if (!same_class($s, $m)) {
        $classBad[] = $s . '->' . $m;
    }
}
ok('ID_MAP 无不动点',   $fixed === [],    implode('', $fixed));
ok('ID_MAP 同类对同类', $classBad === [], implode(',', $classBad));

$bad = [];
for ($i = 0; $i < 61; $i++) {
    $j = strpos(ID_SRC, ID_MAP[$i]);
    if ($j === false || ID_UNMAP[$j] !== ID_SRC[$i]) {
        $bad[] = ID_SRC[$i];
    }
}
ok('ID_UNMAP 与 ID_MAP 一致', $bad === [], implode(',', $bad));

/* ---------- 置乱与容量 ---------- */
section('置乱与容量');
$sorted = SHUFFLE;
sort($sorted);
ok('SHUFFLE 是合法排列', $sorted === range(0, CODE_LEN - 1));

$invBad = [];
for ($i = 0; $i < CODE_LEN; $i++) {
    if (SHUFFLE_INV[SHUFFLE[$i]] !== $i) {
        $invBad[] = (string)$i;
    }
}
ok('SHUFFLE_INV 与 SHUFFLE 互为逆', $invBad === [], implode(',', $invBad));

$cap = 1;
for ($i = 0; $i < CODE_LEN; $i++) {
    $cap *= 61;
}
ok('短码容量大于 avid 上限', $cap > MAX_AID, '61^' . CODE_LEN . ' = ' . $cap);

/* ---------- BV ⇄ AV ---------- */
section('BV ⇄ AV');
ok('BV1es411D7sW → av 2271112', bv_to_av('BV1es411D7sW') === 2271112, (string)bv_to_av('BV1es411D7sW'));
ok('av 2271112 → BV1es411D7sW', av_to_bv(2271112) === 'BV1es411D7sW', (string)av_to_bv(2271112));
ok('拒绝超上限 avid', av_to_bv(MAX_AID) === null);
ok('拒绝负数 avid', av_to_bv(-1) === null);
ok('拒绝非法 BV', bv_to_av('BV1es411D7s') === null && bv_to_av('XX1es411D7sW') === null);

/* ---------- 往返 ---------- */
section('往返');
$samples = ['BV1es411D7sW', 'BV1xx411c7mD', 'BV1yH4y1E7wp', 'BV1m2CZBEEa1', 'BV1c8NC6gEAt', 'BV1i24P6rEmt'];
foreach ($samples as $bv) {
    $av = bv_to_av($bv);
    ok("BV 往返 {$bv}", $av !== null && av_to_bv($av) === $bv);

    $code = bv_to_shortcode($bv);
    ok("短码往返 {$bv}", $code !== null && strlen($code) === CODE_LEN && shortcode_to_bv($code) === $bv,
        (string)$code);
}

$bigAv = 111298867365120;
$bigBv = av_to_bv($bigAv);
ok('大 avid 往返', $bigBv !== null && bv_to_av($bigBv) === $bigAv, (string)$bigBv);

$rndFail = null;
for ($i = 0; $i < 3000; $i++) {
    $av = random_int(0, MAX_AID - 1);
    $code = av_to_shortcode($av);
    if ($code === null || shortcode_to_av($code) !== $av) {
        $rndFail = $av;
        break;
    }
}
ok('随机 3000 次短码往返', $rndFail === null, (string)$rndFail);

/* ---------- 彩蛋目标 ---------- */
section('彩蛋目标');
foreach (EGG_MAP as $word => $bv) {
    $av = bv_to_av($bv);
    ok("彩蛋 /{$word}", is_valid_bv($bv) && $av !== null && av_to_bv($av) === $bv, $bv);
}

/* ---------- 输入归一化 ---------- */
section('输入归一化');
$expect = [
    'BV1es411D7sW'                                     => 'BV1es411D7sW',
    'bv1es411D7sW'                                     => 'BV1es411D7sW',
    '  BV1es411D7sW  '                                 => 'BV1es411D7sW',
    'av2271112'                                        => 'BV1es411D7sW',
    'AV2271112'                                        => 'BV1es411D7sW',
    '2271112'                                          => 'BV1es411D7sW',
    'https://www.bilibili.com/video/BV1es411D7sW'      => 'BV1es411D7sW',
    'https://www.bilibili.com/video/BV1es411D7sW/?p=2' => 'BV1es411D7sW',
    'https://m.bilibili.com/video/BV1es411D7sW'        => 'BV1es411D7sW',
    'https://www.bilibili.com/video/av2271112'         => 'BV1es411D7sW',
    'https://www.bilibili.com/video/BV1es411D7sW?share_source=copy' => 'BV1es411D7sW',
    '【小米发布会】 https://www.bilibili.com/video/BV1es411D7sW 好看' => 'BV1es411D7sW',
    /* 站外页面里写了 BV 号：只认号，不认域名，仍然跳到 B 站播放器 */
    'https://example.com/post/BV1es411D7sW'             => 'BV1es411D7sW',
    '短码 44X4XM444 试试'                              => null,
    bv_to_shortcode('BV1es411D7sW')                    => 'BV1es411D7sW',
];
foreach ($expect as $input => $want) {
    $got = normalize_to_bv((string)$input);
    ok('归一化 ' . substr((string)$input, 0, 34), $got === $want, '得到 ' . var_export($got, true));
}

$reject = ['', '   ', 'hello', 'BV1es411D7', 'https://example.com/video', 'av', '99999999999999999999'];
foreach ($reject as $input) {
    ok('拒绝 ' . ($input === '' ? '(空)' : $input), normalize_to_bv($input) === null);
}

/* ---------- 播放器参数白名单 ---------- */
section('播放器参数');
$params = player_params(['p' => '2', 't' => '30', 'autoplay' => '1', 'evil' => '1',
                         'danmaku' => '<script>', 'page' => str_repeat('9', 40)]);
ok('保留合法参数', array_keys($params) === ['p', 't', 'autoplay'], implode(',', array_keys($params)));
ok('丢弃越权参数', !isset($params['evil']));
ok('丢弃含标签的值', !isset($params['danmaku']));
ok('丢弃超长值', !isset($params['page']));

$url = player_url('BV1es411D7sW', ['p' => '2', 't' => '30']);
ok('播放器地址拼装', $url === 'https://player.bilibili.com/player.html?bvid=BV1es411D7sW&p=2&t=30', $url);
ok('播放器地址无参数', player_url('BV1es411D7sW') === 'https://player.bilibili.com/player.html?bvid=BV1es411D7sW');
ok('嵌入代码带 referrerpolicy', strpos(player_embed('BV1es411D7sW'), 'referrerpolicy="no-referrer"') !== false);

/* ---------- 短码校验 ---------- */
section('短码校验');
ok('合法短码通过', is_valid_code((string)bv_to_shortcode('BV1es411D7sW')));
ok('长度不符拒绝', !is_valid_code('ABC'));
ok('非法字符拒绝', !is_valid_code('!!!!!!!!!'));
ok('超出上限的短码解出合法 BV 之外的值会被拒绝',
    shortcode_to_bv('zzzzzzzzz') === null || is_valid_bv((string)shortcode_to_bv('zzzzzzzzz')));

/* ---------- 出站白名单 ---------- */
section('出站白名单');
ok('非白名单域名不出站', b23_resolve('https://example.com/x') === null);
ok('b23 域名在白名单', in_array('b23.tv', B23_HOSTS, true) && in_array('bili2233.cn', B23_HOSTS, true));

echo "\n" . ($fail === 0 ? "全部通过（{$passed} 项）\n" : "{$fail} 项未通过，通过 {$passed} 项\n");
exit($fail === 0 ? 0 : 1);
