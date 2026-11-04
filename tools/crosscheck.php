<?php
/**
 * PHP 与 Python 逐条交叉校验。
 *
 *   php tools/crosscheck.php
 *
 * 需要 php-cli 与本机 python3；不联网。任一条不一致即非 0 退出。
 */
declare(strict_types=1);

define('APP_ENTRY', true);
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib.php';

$py = dirname(__DIR__) . '/tools/shortcode.py';
if (!is_file($py)) {
    fwrite(STDERR, "缺少 tools/shortcode.py\n");
    exit(2);
}

$avs = [0, 1, 2, 60, 61, 62, 2271112, 111298867365120, MAX_AID - 2, MAX_AID - 1];
mt_srand(20261104);
for ($i = 0; $i < 2000; $i++) {
    $avs[] = ($i % 5 === 0) ? mt_rand(0, 1000000) : mt_rand(0, MAX_AID - 1);
}

$rows = [];
foreach ($avs as $av) {
    $rows[] = [$av, av_to_bv((int)$av), av_to_shortcode((int)$av)];
}

$input = json_encode($rows);
$cmd = 'python3 -c ' . escapeshellarg(
    'import json,sys;sys.path.insert(0,sys.argv[1]);import shortcode as s;'
    . 'rows=json.loads(sys.stdin.read());'
    . 'print(json.dumps([[r[0],s.av_to_bv(r[0]),s.av_to_shortcode(r[0])] for r in rows]))'
) . ' ' . escapeshellarg(dirname($py));

$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "无法启动 python3\n");
    exit(2);
}
fwrite($pipes[0], $input);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
if ($code !== 0) {
    fwrite(STDERR, "python 失败：{$err}\n");
    exit(2);
}

$their = json_decode((string)$out, true);
$fail = 0;
foreach ($rows as $i => $row) {
    if ($their[$i][1] !== $row[1] || $their[$i][2] !== $row[2]) {
        $fail++;
        echo "不一致 av={$row[0]} php={$row[1]}/{$row[2]} py={$their[$i][1]}/{$their[$i][2]}\n";
        if ($fail > 5) {
            break;
        }
    }
    /* 反向：短码必须解回同一个 av */
    if ($row[2] !== null && shortcode_to_av($row[2]) !== (int)$row[0]) {
        $fail++;
        echo "解码不一致 av={$row[0]} code={$row[2]}\n";
    }
}

echo $fail === 0
    ? "交叉校验通过：" . count($rows) . " 组样本，PHP 与 Python 完全一致\n"
    : "交叉校验失败：{$fail} 处不一致\n";
exit($fail === 0 ? 0 : 1);
