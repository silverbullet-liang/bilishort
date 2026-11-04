<?php
/**
 * BiliShort — 站点配置
 *
 * 本文件只能被 index.php / selfcheck.php 引入，禁止直接访问。
 * 修改短码相关常量会让已发出的短码失效，除非你确认要作废全部旧链接。
 */
declare(strict_types=1);

if (!defined('APP_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

/* ---------------- 站点 ---------------- */

const SITE_NAME_ZH = 'B 站视频短链';
const SITE_NAME_EN = 'BiliShort';
const SITE_TAGLINE_ZH = '粘贴 BV 号、av 号或视频链接，立刻拿到一条短链。';
const SITE_TAGLINE_EN = 'Paste a BV id, av id or video link, get a short link instantly.';

/* 站点对外地址，例：'https://bili.example.com'。留空则按当前请求推断。 */
const SITE_ORIGIN = '';

/* 除主域名外还想让搜索引擎收录的镜像域名，可写成 ['https://mirror.example.com'] */
const SITE_MIRRORS = [];

/* ---------------- 播放器 ---------------- */

const PLAYER_HOST = 'player.bilibili.com';
/* 固定追加到播放器地址的参数，例：'&danmaku=0' */
const PLAYER_QUERY = '';

/* 允许从短链原样透传给播放器的参数（其余一律丢弃，防止被当成任意跳转） */
const PLAYER_PASSTHROUGH = ['p', 'page', 't', 'danmaku', 'autoplay', 'high_quality', 'as_wide'];

/* ---------------- 短码（算法冻结） ---------------- */

const CODE_LEN = 9;              /* 61^9 ≈ 1.17e16，覆盖 B 站 avid 上限 2^51 */

/* ID_SRC 同时充当 61 进制的数字表：索引即数值 0..60 */
const ID_SRC   = '123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
const ID_MAP   = '456789123LMNOPQRSTUVWXYZABCDEFGHIJKfghijklmnopqrstuvwxyzabcde';
const ID_UNMAP = '789123456PQRSTUVWXYZABCDEFGHIJKLMNOvwxyzabcdefghijklmnopqrstu';

/* 位置置乱：两表必须互为逆 */
const SHUFFLE     = [4, 0, 7, 2, 8, 5, 1, 6, 3];
const SHUFFLE_INV = [1, 6, 3, 8, 0, 5, 7, 2, 4];

/* ---------------- BV ⇄ AV（B 站现行算法） ---------------- */

const MAX_AID     = 2251799813685248;   /* 2^51 */
const MASK_CODE   = 2251799813685247;   /* 2^51 - 1 */
const XOR_CODE    = 23442827791579;
const BV_ALPHABET = 'FcwAPNKTMug3GV5Lj7EJnHpWsx4tb8haYeviqBz6rkCy12mUSDQX9RdoZf';

/* ---------------- 固定彩蛋 ---------------- */

const EGG_MAP = [
    '1'        => 'BV1xx411c7mD',
    '114514'   => 'BV1yH4y1E7wp',
    '8848'     => 'BV1m2CZBEEa1',
    'xiaomi'   => 'BV1es411D7sW',
    'redmi'    => 'BV1es411D7sW',
    'huawei'   => 'BV1c8NC6gEAt',
    'deepseek' => 'BV1i24P6rEmt',
];

/* ---------------- 动态彩蛋：/google 取该账号最新投稿 ---------------- */

const LIVE_UID  = 64169458;
const CACHE_TTL = 1800;      /* 秒 */

/* ---------------- 自动延伸：短链解析 ---------------- */

/* 允许解析的 B 站官方短链域名，其余域名一律不出站 */
const B23_HOSTS = ['b23.tv', 'bili2233.cn'];
const B23_TTL   = 2592000;   /* 解析结果缓存 30 天：短链与视频的对应关系基本不变 */

/* 每个 IP 每小时最多解析多少次官方短链（0 = 不限制） */
const B23_RATE_MAX    = 60;
const B23_RATE_WINDOW = 3600;

/* ---------------- 缓存 ---------------- */

const CACHE_DIR = __DIR__ . '/cache';
