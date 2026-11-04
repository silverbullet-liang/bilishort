#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BiliShort 短码算法参考实现（Python）。

与站点 PHP 实现（lib.php）逐位等价，用于交叉校验与离线转换：
    av  -> 61 进制定长 9 位 -> 位置置乱 -> 字符表替换 -> 短码
    短码 -> 查表还原数值 -> 逆置乱 -> 61 进制还原 -> av -> BV

算法自 2025 年首发后冻结：任何改动都会让已发出的短码失效。

CLI:
    python3 shortcode.py bv BV1es411D7sW      # BV -> av / 短码 / 播放器地址
    python3 shortcode.py av 2271112           # av -> BV / 短码
    python3 shortcode.py code aB3dE5gH7       # 短码 -> BV / av
    python3 shortcode.py selftest             # 内置向量与往返测试
"""

import random
import sys

# ---------- 与 config.php 完全一致的常量 ----------

CODE_LEN = 9
ID_MAP = "456789123LMNOPQRSTUVWXYZABCDEFGHIJKfghijklmnopqrstuvwxyzabcde"
ID_SRC = "123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"

SHUFFLE = (4, 0, 7, 2, 8, 5, 1, 6, 3)
SHUFFLE_INV = (1, 6, 3, 8, 0, 5, 7, 2, 4)

MAX_AID = 2251799813685248      # 2^51
MASK_CODE = 2251799813685247    # 2^51 - 1
XOR_CODE = 23442827791579
BV_ALPHABET = "FcwAPNKTMug3GV5Lj7EJnHpWsx4tb8haYeviqBz6rkCy12mUSDQX9RdoZf"

PLAYER_HOST = "player.bilibili.com"


# ---------- BV <-> AV（B 站现行算法） ----------

def bv_to_av(bv):
    if not isinstance(bv, str) or len(bv) != 12 or bv[0] != "B" or bv[1] != "V":
        return None
    b = list(bv)
    b[3], b[9] = b[9], b[3]
    b[4], b[7] = b[7], b[4]
    n = 0
    for i in range(3, 12):
        d = BV_ALPHABET.find(b[i])
        if d < 0:
            return None
        n = n * 58 + d
    return (n ^ XOR_CODE) & MASK_CODE


def av_to_bv(av):
    if av is None or av < 0 or av >= MAX_AID:
        return None
    n = (av | MAX_AID) ^ XOR_CODE
    b = ["B", "V", "1", "", "", "", "", "", "", "", "", ""]
    for i in range(11, 2, -1):
        b[i] = BV_ALPHABET[n % 58]
        n //= 58
    if n != 0:
        return None
    b[3], b[9] = b[9], b[3]
    b[4], b[7] = b[7], b[4]
    return "".join(b)


# ---------- AV <-> 短码 ----------

def _decode_table():
    """ID_MAP 的逆表：密文字符 -> 数值 0..60"""
    return {ID_MAP[v]: v for v in range(61)}


_TABLE = _decode_table()


def av_to_shortcode(av):
    if av is None or av < 0 or av >= MAX_AID:
        return None
    d = [0] * CODE_LEN
    n = av
    for i in range(CODE_LEN - 1, -1, -1):
        d[i] = n % 61
        n //= 61
    if n != 0:
        return None
    return "".join(ID_MAP[d[SHUFFLE[i]]] for i in range(CODE_LEN))


def shortcode_to_av(code):
    if not isinstance(code, str) or len(code) != CODE_LEN:
        return None
    p = []
    for c in code:
        v = _TABLE.get(c)
        if v is None:
            return None
        p.append(v)
    d = [p[SHUFFLE_INV[j]] for j in range(CODE_LEN)]
    av = 0
    for v in d:
        av = av * 61 + v
    return av


# ---------- 组合接口 ----------

def is_valid_bv(s):
    return bool(isinstance(s, str) and len(s) == 12 and s[:2] == "BV"
                and all(c.isascii() and c.isalnum() for c in s[2:]))


def is_valid_code(s):
    return bool(isinstance(s, str) and len(s) == CODE_LEN
                and all(c in _TABLE for c in s))


def bv_to_shortcode(bv):
    av = bv_to_av(bv)
    return None if av is None else av_to_shortcode(av)


def shortcode_to_bv(code):
    av = shortcode_to_av(code)
    if av is None:
        return None
    bv = av_to_bv(av)
    return bv if bv is not None and is_valid_bv(bv) else None


def player_url(bv):
    return "https://%s/player.html?bvid=%s" % (PLAYER_HOST, bv)


# ---------- 自检 ----------

def selftest():
    fails = []
    passed = [0]

    def ok(name, cond, detail=""):
        if cond:
            passed[0] += 1
        else:
            fails.append(name if not detail else "%s (%s)" % (name, detail))

    # 常量表
    ok("ID_MAP 长度 61", len(ID_MAP) == 61)
    ok("ID_SRC 长度 61", len(ID_SRC) == 61)
    ok("ID_MAP 无重复", len(set(ID_MAP)) == 61)
    ok("ID_SRC 无重复", len(set(ID_SRC)) == 61)
    ok("置乱是合法排列", sorted(SHUFFLE) == list(range(CODE_LEN)))
    ok("置乱表互逆", all(SHUFFLE_INV[SHUFFLE[i]] == i for i in range(CODE_LEN)))
    ok("容量大于 avid 上限", 61 ** CODE_LEN > MAX_AID, "61^9=%d" % (61 ** CODE_LEN))

    # 已知向量
    ok("BV1es411D7sW -> 2271112", bv_to_av("BV1es411D7sW") == 2271112)
    ok("2271112 -> BV1es411D7sW", av_to_bv(2271112) == "BV1es411D7sW")

    # 往返：样例 + 边界 + 随机
    samples = ["BV1es411D7sW", "BV1xx411c7mD", "BV1yH4y1E7wp", "BV1m2CZBEEa1",
               "BV1c8NC6gEAt", "BV1i24P6rEmt"]
    for bv in samples:
        av = bv_to_av(bv)
        ok("BV 往返 " + bv, av is not None and av_to_bv(av) == bv)
        code = av_to_shortcode(av) if av is not None else None
        ok("短码往返 " + bv, code is not None and shortcode_to_bv(code) == bv, str(code))

    for av in (0, 1, 60, 61, 62, 2271112, MAX_AID - 1, 111298867365120):
        bv = av_to_bv(av)
        code = av_to_shortcode(av)
        ok("边界往返 av=%d" % av,
           bv is not None and bv_to_av(bv) == av
           and code is not None and shortcode_to_av(code) == av)

    ok("超出上限拒绝", av_to_shortcode(MAX_AID) is None and av_to_bv(MAX_AID) is None)
    ok("含非法字符拒绝", shortcode_to_av("!!!!!!!!!") is None)
    ok("长度不符拒绝", shortcode_to_av("ABCD") is None)

    rnd = random.Random(20261104)
    for _ in range(20000):
        av = rnd.randrange(0, MAX_AID)
        if shortcode_to_av(av_to_shortcode(av)) != av:
            ok("随机往返 av=%d" % av, False)
            break
    else:
        ok("随机 20000 次往返", True)

    print("BiliShort Python 参考实现自检")
    print("  通过 %d 项，失败 %d 项" % (passed[0], len(fails)))
    for f in fails:
        print("  FAIL " + f)
    return 0 if not fails else 1


def main(argv):
    if len(argv) < 2 or argv[1] in ("-h", "--help"):
        print(__doc__)
        return 0
    cmd = argv[1]
    if cmd == "selftest":
        return selftest()
    if len(argv) < 3:
        print("缺少参数，见 --help")
        return 2
    arg = argv[2].strip()

    if cmd == "bv":
        av = bv_to_av(arg)
        if av is None:
            print("不是合法 BV 号")
            return 1
        print("av      %d" % av)
        print("短码    %s" % av_to_shortcode(av))
        print("播放器  %s" % player_url(arg))
    elif cmd == "av":
        s = arg[2:] if arg.lower().startswith("av") else arg
        if not s.isdigit():
            print("不是合法 avid")
            return 1
        av = int(s)
        bv = av_to_bv(av)
        if bv is None:
            print("avid 超出范围")
            return 1
        print("BV      %s" % bv)
        print("短码    %s" % av_to_shortcode(av))
    elif cmd == "code":
        bv = shortcode_to_bv(arg)
        if bv is None:
            print("不是合法短码")
            return 1
        print("BV      %s" % bv)
        print("av      %d" % bv_to_av(bv))
    else:
        print("未知命令，见 --help")
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
