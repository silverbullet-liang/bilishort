/* BiliShort — 转换、复制、问答折叠。无框架、无依赖。 */
(function () {
  'use strict';

  var form  = document.getElementById('form');
  var input = document.getElementById('q');
  var btn   = document.getElementById('go');
  var msg   = document.getElementById('msg');
  var out   = document.getElementById('out');
  var seq   = 0;
  var timer = null;

  var fields = {
    code:   document.getElementById('r-code'),
    url:    document.getElementById('r-url'),
    player: document.getElementById('r-player'),
    embed:  document.getElementById('r-embed')
  };

  function say(text, isErr) {
    msg.textContent = text || '';
    msg.className = isErr ? 'msg err' : 'msg';
  }

  function busy(on) {
    btn.disabled = on;
    btn.classList.toggle('is-busy', on);
  }

  function convert(retry) {
    var q = input.value.trim();
    if (!q) {
      out.hidden = true;
      say('');
      return;
    }

    var my = ++seq;
    busy(true);
    say('转换中…');

    /* 必须同源带 Cookie：部分主机的防护层要靠 Cookie 校验，
       用了 credentials:'omit' 会被判定为陌生请求并返回挑战页。 */
    fetch('?bilibili&api=1&q=' + encodeURIComponent(q), {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    }).then(function (r) {
      return r.text().then(function (t) {
        var body = (t || '').replace(/^\uFEFF?\s*/, '');
        if (!/^[{[]/.test(body)) {
          throw new Error('non-json');
        }
        return JSON.parse(body);
      });
    }).then(function (j) {
      if (my !== seq) { return; }
      busy(false);
      if (!j.ok) {
        out.hidden = true;
        say(j.error || '转换失败', true);
        return;
      }
      fields.code.textContent = j.code;
      fields.url.textContent = j.url;
      fields.url.href = j.url;
      fields.player.textContent = j.player;
      fields.player.href = j.player;
      fields.embed.textContent = j.embed;
      out.hidden = false;
      say('已识别 ' + j.bv);
    }).catch(function (err) {
      if (my !== seq) { return; }
      var blocked = err && err.message === 'non-json';
      /* 被防护页拦下时自动再试一次，仍失败才提示 */
      if (blocked && !retry) {
        setTimeout(function () { if (my === seq) { convert(true); } }, 300);
        return;
      }
      busy(false);
      out.hidden = true;
      say(blocked ? '被主机防护页拦下，刷新页面后重试' : '请求失败，检查网络后重试', true);
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(timer);
    convert();
  });

  input.addEventListener('input', function () {
    clearTimeout(timer);
    if (!input.value.trim()) {
      out.hidden = true;
      say('');
      return;
    }
    timer = setTimeout(convert, 420);
  });

  /* 示例：点一下填入并立刻转换 */
  Array.prototype.forEach.call(document.querySelectorAll('.chip'), function (chip) {
    chip.addEventListener('click', function () {
      input.value = chip.getAttribute('data-fill') || '';
      clearTimeout(timer);
      convert();
      input.focus();
    });
  });

  /* 复制：优先 Clipboard API，失败退回 textarea */
  document.addEventListener('click', function (e) {
    var box = e.target.closest ? e.target.closest('.copy') : null;
    if (!box) { return; }
    var el = document.getElementById(box.getAttribute('data-copy'));
    if (!el) { return; }
    var text = el.tagName === 'A' ? el.href : el.textContent;
    if (!text) { return; }

    function done() {
      box.classList.add('done');
      setTimeout(function () { box.classList.remove('done'); }, 1100);
    }

    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* 忽略 */ }
      document.body.removeChild(ta);
    }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  });

  /* 问答折叠：button + aria-expanded + hidden，键盘天然可用 */
  Array.prototype.forEach.call(document.querySelectorAll('.faq-q'), function (q) {
    q.addEventListener('click', function () {
      var open = q.getAttribute('aria-expanded') === 'true';
      q.setAttribute('aria-expanded', open ? 'false' : 'true');
      var panel = document.getElementById(q.getAttribute('aria-controls'));
      if (panel) { panel.hidden = open; }
    });
  });
})();
