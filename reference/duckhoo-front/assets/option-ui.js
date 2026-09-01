/*!
 * 액상덕후 · 상품 옵션 UI  v2
 * ------------------------------------------------------------------
 * 레이아웃: 왼쪽 큰 이미지 / 오른쪽 짧은 칼럼(접이식 카드 + 칩)
 *
 * 원칙
 *  1. UI 뼈대는 한 번만 만든다. 이후에는 숫자와 클래스만 바꾼다.
 *     -> 수량을 눌러도 높이가 변하지 않으므로 화면이 튀지 않는다.
 *  2. 카드를 접고 펴는 것은 오직 사용자가 눌렀을 때만. 자동으로 열고 닫지 않는다.
 *     (자동 아코디언이 예전 '통통 튀는' 버그의 원인이었다)
 *  3. 장바구니 데이터는 우리가 만들지 않는다. 테마의 select 와
 *     .wd-option-plus / .wd-option-minus / .wd-option-remove 를 대신 눌러줄 뿐이다.
 *  4. 구매 버튼도 테마 것을 그대로 옮겨와 스타일만 입힌다.
 */
(function () {
  'use strict';

  var BOOTED = false;

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function cleanLabel(s) {
    return String(s || '').replace(/\s*\[\s*\+?[\d,]+\s*원?\s*\]\s*$/, '').trim();
  }

  function bottlesIn(s) {
    var m = String(s || '').match(/(\d+)\s*병/);
    return m ? parseInt(m[1], 10) : 0;
  }

  function boot() {
    if (BOOTED) return;

    var form = document.querySelector('form.cart.ppom-flex-controller') ||
               document.querySelector('form.cart');
    if (!form) return;

    var wrappers = [].slice.call(form.querySelectorAll('.ppom-field-wrapper'));
    if (wrappers.length < 2) return;

    var builder = form.querySelector('.wd-option-builder');
    var list    = form.querySelector('.wd-option-builder-list');
    var totalEl = form.querySelector('.wd-option-builder-total');
    var actions = form.querySelector('.vf-drawer-actions');
    if (!builder || !list) return;

    BOOTED = true;
    document.body.classList.add('dhx-on');

    var GROUPS = ['required_main', 'addon_1', 'addon_2', 'addon_3'];

    var fields = wrappers.map(function (w, i) {
      var sel = w.querySelector('select');
      var lab = w.querySelector('label');
      return {
        wrap: w,
        sel: sel,
        group: GROUPS[i] || ('addon_' + i),
        title: lab ? lab.textContent.replace(/\*+/g, '').trim() : ('STEP ' + (i + 1)),
        opts: sel ? [].slice.call(sel.options).slice(1).map(function (o) {
          var raw = o.textContent.trim();
          var pm = raw.match(/\[\s*\+?([\d,]+)\s*원?\s*\]/);
          return { value: o.value, raw: raw, label: cleanLabel(raw), price: pm ? pm[1] : '' };
        }) : []
      };
    }).filter(function (f) { return f.sel; });

    if (!fields.length) return;

    var BUNDLE = fields[0];
    var FLAVOR = fields[1];
    var EXTRAS = fields.slice(2);

    var PER_BUNDLE = 0;
    BUNDLE.opts.forEach(function (o) { PER_BUNDLE = PER_BUNDLE || bottlesIn(o.label); });
    PER_BUNDLE = PER_BUNDLE || bottlesIn(BUNDLE.title) || 10;

    /* 원본 필드와 테마 요약은 화면에서만 감춘다 (폼 값은 그대로) */
    wrappers.forEach(function (w) { w.classList.add('dhx-src'); });
    ['.wd-option-builder-list', '.wd-option-builder-summary',
     '.vf-drawer-summary', '.quantity'].forEach(function (sel) {
      var n = form.querySelector(sel);
      if (n) n.classList.add('dhx-src');
    });

    /* ---------------- 뼈대 ---------------- */
    var root = el('div', 'dhx');

    /* 접이식 카드 한 장 */
    function card(no, title, openByDefault) {
      var c = el('section', 'dhx-card');
      var head = el('button', 'dhx-card__head');
      head.type = 'button';
      head.appendChild(el('span', 'dhx-card__no', String(no)));
      head.appendChild(el('span', 'dhx-card__title', title));
      var st = el('span', 'dhx-card__state', '선택 안 함');
      head.appendChild(st);
      head.appendChild(el('span', 'dhx-card__chev', '⌃'));
      var body = el('div', 'dhx-card__body');
      var inner = el('div', 'dhx-card__inner');
      body.appendChild(inner);
      c.appendChild(head);
      c.appendChild(body);
      if (openByDefault) c.classList.add('is-open');
      head.addEventListener('click', function () {
        if (c.classList.contains('is-locked')) return;
        c.classList.toggle('is-open');
      });
      c._state = st;
      c._body = inner;
      return c;
    }

    /* STEP 1 — 묶음 (선택 / 해지 / 개수) */
    var c1 = card(1, BUNDLE.title, true);
    var s1body = el('div', 'dhx-bundles');
    BUNDLE.opts.forEach(function (o) {
      var row = el('div', 'dhx-bundle');
      var pick = el('button', 'dhx-bundle__pick');
      pick.type = 'button';
      pick.appendChild(el('span', 'dhx-bundle__mark'));
      var nm = el('span', 'dhx-bundle__name', o.label);
      nm.appendChild(el('small', 'dhx-bundle__sub', PER_BUNDLE + '병 묶음 · 다시 누르면 해지'));
      pick.appendChild(nm);
      pick.addEventListener('click', function () { toggleBundle(o); });
      row.appendChild(pick);

      var q = el('div', 'dhx-qty');
      var minus = el('button', '', '−'); minus.type = 'button';
      var num = el('span', 'dhx-qty__n', '0');
      var plus = el('button', '', '+'); plus.type = 'button';
      q.appendChild(minus); q.appendChild(num); q.appendChild(plus);
      minus.addEventListener('click', function () { dec(BUNDLE, o); });
      plus.addEventListener('click', function () { inc(BUNDLE, o); });
      row.appendChild(q);

      row._label = o.label; row._num = num; row._qty = q;
      s1body.appendChild(row);
    });
    c1._body.appendChild(s1body);
    root.appendChild(c1);

    /* STEP 2 — 맛: 칩 그리드 */
    var c2 = card(2, FLAVOR.title, true);
    var lock = el('div', 'dhx-lock', '위 STEP 1 묶음을 먼저 선택해주세요');
    var chips = el('div', 'dhx-chips');
    FLAVOR.opts.forEach(function (o) {
      var chip = el('div', 'dhx-chip');
      var pick = el('button', 'dhx-chip__pick');
      pick.type = 'button';
      pick.textContent = o.label;
      pick.addEventListener('click', function () { inc(FLAVOR, o); });
      chip.appendChild(pick);

      var q = el('div', 'dhx-qty dhx-qty--mini');
      var minus = el('button', '', '−'); minus.type = 'button';
      var num = el('span', 'dhx-qty__n', '0');
      var plus = el('button', '', '+'); plus.type = 'button';
      q.appendChild(minus); q.appendChild(num); q.appendChild(plus);
      minus.addEventListener('click', function () { dec(FLAVOR, o); });
      plus.addEventListener('click', function () { inc(FLAVOR, o); });
      chip.appendChild(q);

      chip._label = o.label; chip._num = num;
      chips.appendChild(chip);
    });
    c2._body.appendChild(lock);
    c2._body.appendChild(chips);
    root.appendChild(c2);

    /* STEP 3.. — 팟/코일, 기기: 기본 접힘 */
    var extraRows = [];
    EXTRAS.forEach(function (f, i) {
      var c = card(3 + i, f.title, false);
      var body = el('div', 'dhx-rows');
      f.opts.forEach(function (o) {
        var row = el('div', 'dhx-row');
        row.appendChild(el('span', 'dhx-row__name', o.label));
        if (o.price) row.appendChild(el('span', 'dhx-row__price', '+' + o.price + '원'));
        var q = el('div', 'dhx-qty');
        var minus = el('button', '', '−'); minus.type = 'button';
        var num = el('span', 'dhx-qty__n', '0');
        var plus = el('button', '', '+'); plus.type = 'button';
        q.appendChild(minus); q.appendChild(num); q.appendChild(plus);
        row.appendChild(q);
        minus.addEventListener('click', function () { dec(f, o); });
        plus.addEventListener('click', function () { inc(f, o); });
        row._num = num; row._label = o.label;
        body.appendChild(row);
        extraRows.push({ field: f, row: row });
      });
      c._body.appendChild(body);
      root.appendChild(c);
      f._card = c;
    });

    /* 요약 */
    var sum = el('section', 'dhx-sum');
    var sr = el('div', 'dhx-sum__row');
    sr.appendChild(el('span', 'dhx-sum__label', '총 상품금액'));
    var totalOut = el('strong', 'dhx-sum__total', '0원');
    sr.appendChild(totalOut);
    sum.appendChild(sr);

    var gauge = el('div', 'dhx-gauge');
    var grow = el('div', 'dhx-gauge__row');
    grow.appendChild(el('span', 'dhx-sum__label', '선택한 액상'));
    var gnum = el('span', 'dhx-gauge__n', '0 / 0');
    grow.appendChild(gnum);
    gauge.appendChild(grow);
    var track = el('div', 'dhx-gauge__track');
    var fill = el('div', 'dhx-gauge__fill');
    fill.style.width = '0%';
    track.appendChild(fill);
    gauge.appendChild(track);
    var picked = el('div', 'dhx-picked');
    gauge.appendChild(picked);
    sum.appendChild(gauge);
    var buybox = el('div', 'dhx-buybox');
    buybox.appendChild(sum);
    if (actions) buybox.appendChild(actions);
    root.appendChild(buybox);

    var host = form.querySelector('.vf-drawer-options') ||
               form.querySelector('.vf-drawer-body') || form;
    host.appendChild(root);

    /* ---------------- 레이아웃 ---------------- */
    var UNDRAWER = [
      ['position', 'static'], ['top', 'auto'], ['right', 'auto'],
      ['bottom', 'auto'], ['left', 'auto'],
      ['width', '100%'], ['height', 'auto'], ['max-height', 'none'],
      ['min-height', '0'], ['transform', 'none'], ['box-shadow', 'none'],
      ['border-radius', '0'], ['background', 'transparent'],
      ['padding', '0'], ['overflow', 'visible'], ['z-index', 'auto'],
      ['display', 'block'], ['filter', 'none'], ['visibility', 'visible'],
      ['opacity', '1'], ['pointer-events', 'auto'],
      /* 테마는 transform 이 아니라 translate 속성으로 드로어를 밀어둔다.
         이게 남아 있으면 form 이 fixed 자식의 기준 박스가 되어
         하단 고정 구매 바가 화면 밖으로 나간다. */
      ['translate', 'none'], ['rotate', 'none'], ['scale', 'none'],
      ['transition', 'none']
    ];
    var drawerBody = form.querySelector('.vf-drawer-body');
    var drawerHead = form.querySelector('.vf-drawer-header');
    var triggerBar = document.getElementById('vf-sticky-trigger-bar');
    var overlay    = document.getElementById('dh-ov');

    /* 화면 크기와 무관하게 드로어를 펼쳐 페이지 안에 그대로 둔다.
       구매 버튼은 CSS 로 화면 하단에 고정하므로 항상 보인다. */
    function applyLayout() {
      [form, drawerBody].forEach(function (t) {
        if (!t) return;
        UNDRAWER.forEach(function (p) {
          t.style.setProperty(p[0], p[1], 'important');
        });
      });
      [drawerHead, triggerBar, overlay].forEach(function (t) {
        if (!t) return;
        t.style.setProperty('display', 'none', 'important');
      });
      document.documentElement.classList.remove('vf-drawer-lock');

      /* 테마가 상품 그리드를 446px 480px 고정으로 잡아 창이 좁으면
         오른쪽 칼럼이 화면 밖으로 밀린다. 비율로 바꾸고 좁으면 1단. */
      var pbox = form.closest('.product');
      var summaryBox = form.closest('.summary');
      if (pbox) {
        if (window.innerWidth >= 981) {
          pbox.style.setProperty('display', 'grid', 'important');
          pbox.style.setProperty('grid-template-columns',
            'minmax(0,1.05fr) minmax(0,1fr)', 'important');
          pbox.style.setProperty('align-items', 'start', 'important');
        } else {
          pbox.style.setProperty('display', 'block', 'important');
          pbox.style.removeProperty('grid-template-columns');
        }

        /* 설명 탭·추천·연관상품을 그리드 '밖'으로 꺼내
           설명 → 추천(어떠세요) → 함께구매 → 연관상품 순으로 쌓는다.
           (grid-column 으로 끼워 넣었더니 테마가 summary 에 걸어둔
            행 배치와 겹쳐서 설명 글자가 이미지·옵션 위로 올라왔고,
            추천 블록이 오른쪽 칼럼만 길게 만들어 왼쪽이 비었다) */
        /* 상품 설명(상세페이지)은 그리드 아래 전체 폭으로, 항상 펼쳐 보이게.
           (접이식 카드에 넣었더니 상세 이미지가 통째로 숨어 "설명이 없어졌다"
            는 피드백 — 긴 상세는 접지 않는다) */
        var tabsEl = pbox.querySelector(':scope > .woocommerce-tabs') ||
                     pbox.querySelector('.woocommerce-tabs') ||
                     document.querySelector('.wd-container > .woocommerce-tabs');

        /* 레퍼런스: 배송·혜택 카드 (오늘출발 + 무료배송 게이지) — 칼럼 맨 아래 */
        if (summaryBox && !document.querySelector('.dhx-ship')) {
          var ship = document.createElement('section');
          ship.className = 'dhx-card dhx-ship is-open';
          var shead = document.createElement('button');
          shead.type = 'button';
          shead.className = 'dhx-card__head';
          shead.innerHTML = '<span class="dhx-card__title" style="font-weight:700">배송 · 혜택</span>' +
                            '<span class="dhx-card__chev">⌃</span>';
          var sbody = document.createElement('div');
          sbody.className = 'dhx-card__body';
          var sinner = document.createElement('div');
          sinner.className = 'dhx-card__inner';
          sbody.appendChild(sinner);
          shead.addEventListener('click', function () { ship.classList.toggle('is-open'); });
          ship.appendChild(shead);
          ship.appendChild(sbody);
          var disp = summaryBox.querySelector(':scope > .wd-today-dispatch');
          var gaug = summaryBox.querySelector(':scope > .wd-free-shipping-gauge');
          if (disp) sinner.appendChild(disp);
          if (gaug) sinner.appendChild(gaug);
          if (disp || gaug) summaryBox.appendChild(ship);
        }

        var cursor = pbox;
        [tabsEl,
         pbox.querySelector('.summary .wc-prl-recommendations'),
         pbox.querySelector(':scope > section.up-sells'),
         pbox.querySelector(':scope > section.related')]
          .forEach(function (n) {
            if (!n) return;
            n.style.removeProperty('grid-column');
            pbox.parentElement.insertBefore(n, cursor.nextSibling);
            cursor = n;
            n.classList.add('dhx-below');
            n.style.setProperty('width', '100%', 'important');
            n.style.setProperty('max-width', '100%', 'important');
            n.style.setProperty('margin-top', '28px', 'important');

            /* 전체 폭에서 상품 카드가 2개씩 거대해지지 않게 열 수 지정
               (테마 CSS 우선순위가 높아 인라인으로 못 박는다) */
            var list = n.querySelector('ul.products, .products');
            if (list) {
              var cols = window.innerWidth >= 1500 ? 5
                       : window.innerWidth >= 1150 ? 4
                       : window.innerWidth >= 860 ? 3 : 2;
              list.style.setProperty('display', 'grid', 'important');
              list.style.setProperty('grid-template-columns',
                'repeat(' + cols + ', minmax(0,1fr))', 'important');
              list.style.setProperty('gap', '18px', 'important');
            }
          });

        /* 이미지 sticky 는 설명과 겹쳐 보여서 제거 — 일반 흐름으로 */
        var gal = pbox.querySelector(':scope > .woocommerce-product-gallery');
        if (gal) {
          ['position', 'top', 'align-self'].forEach(function (k) {
            gal.style.removeProperty(k);
          });
        }
      }
    }
    applyLayout();
    [400, 1200, 2500].forEach(function (t) { setTimeout(applyLayout, t); });
    var rt = null;
    window.addEventListener('resize', function () {
      if (rt) clearTimeout(rt);
      rt = setTimeout(applyLayout, 120);
    });

    /* ---------------- 조작 ---------------- */
    var busy = false;

    function settle() {
      setTimeout(function () { busy = false; sync(); }, 90);
      setTimeout(sync, 320);
      setTimeout(sync, 700);
    }

    function fire(sel) {
      if (window.jQuery) window.jQuery(sel).trigger('change');
      else sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* 테마 라벨엔 "[+6,000원]" 꼬리표가 붙어 있어 정규화해서 비교한다 */
    function normKey(s) {
      return (s || '').replace(/\[[^\]]*\]/g, '').replace(/\s+/g, ' ').trim();
    }

    function itemButton(group, label, cls) {
      var bs = list.querySelectorAll('.' + cls);
      var nl = normKey(label), fb = null;
      for (var i = 0; i < bs.length; i++) {
        if (normKey(bs[i].getAttribute('data-label')) !== nl) continue;
        if (bs[i].getAttribute('data-group') === group) return bs[i];
        fb = fb || bs[i];   /* 그룹 키가 달라도(addon_2 vs addon_pod_2) 라벨로 찾는다 */
      }
      return fb;
    }

    function inc(field, o) {
      busy = true;
      var b = itemButton(field.group, o.label, 'wd-option-plus');
      if (b) b.click();
      else { field.sel.value = o.value; fire(field.sel); }
      settle();
    }

    function dec(field, o) {
      busy = true;
      var b = itemButton(field.group, o.label, 'wd-option-minus');
      if (b) b.click();
      settle();
    }

    function clearGroup(field) {
      var group = field.group;
      var st = groupState(field);
      Object.keys(st).forEach(function (lb) {
        var r = itemButton(group, lb, 'wd-option-remove');
        if (r) r.click();
      });
    }

    function toggleBundle(o) {
      busy = true;
      var cur = groupState(BUNDLE);
      if (cur[o.label]) {
        var rm = itemButton(BUNDLE.group, o.label, 'wd-option-remove');
        if (rm) rm.click();
        clearGroup(FLAVOR);   // 10병 조건이 어긋나지 않게 맛도 비운다
      } else {
        Object.keys(cur).forEach(function (lb) {
          var r = itemButton(BUNDLE.group, lb, 'wd-option-remove');
          if (r) r.click();
        });
        BUNDLE.sel.value = o.value;
        fire(BUNDLE.sel);
      }
      settle();
    }

    /* ---------------- 상태 ---------------- */
    function state() {
      var out = {};
      var items = list.querySelectorAll('.wd-option-item');
      for (var i = 0; i < items.length; i++) {
        var btn = items[i].querySelector('.wd-option-plus, .wd-option-remove');
        if (!btn) continue;
        var g = btn.getAttribute('data-group');
        var lb = normKey(btn.getAttribute('data-label'));
        var qEl = items[i].querySelector('.wd-option-qty span');
        var q = qEl ? parseInt(qEl.textContent, 10) || 0 : 1;
        out[g] = out[g] || {};
        out[g][lb] = (out[g][lb] || 0) + q;
      }
      return out;
    }

    /* 필드의 그룹 키가 테마 데이터와 다를 수 있어(addon_2 vs addon_pod_2)
       키가 안 맞으면 옵션 라벨로 해당 그룹을 찾는다 */
    function groupState(field, st) {
      st = st || state();
      if (st[field.group]) return st[field.group];
      var labels = {};
      field.opts.forEach(function (o) { labels[normKey(o.label)] = 1; });
      var best = null;
      Object.keys(st).forEach(function (g) {
        if (best) return;
        var hit = Object.keys(st[g]).some(function (k) { return labels[k]; });
        if (hit) best = st[g];
      });
      return best || {};
    }

    function setText(node, v) { if (node.textContent !== v) node.textContent = v; }

    function sync() {
      var st = state();

      /* STEP 1 */
      var bundleQty = 0, s1done = false;
      [].forEach.call(s1body.children, function (row) {
        var q = groupState(BUNDLE, st)[normKey(row._label)] || 0;
        row.classList.toggle('is-on', q > 0);
        setText(row._num, String(q));
        row._qty.style.visibility = q > 0 ? 'visible' : 'hidden';
        bundleQty += q;
        if (q > 0) s1done = true;
      });
      c1.classList.toggle('is-done', s1done);
      setText(c1._state, s1done
        ? (bundleQty > 1 ? bundleQty + '묶음' : '선택 완료')
        : '선택 안 함');

      var need = PER_BUNDLE * bundleQty;

      /* STEP 2 */
      c2.classList.toggle('is-locked', !s1done);
      lock.style.display = s1done ? 'none' : '';
      chips.style.display = s1done ? '' : 'none';
      if (!s1done) c2.classList.add('is-open');

      var have = 0, picks = [];
      [].forEach.call(chips.children, function (chip) {
        var q = groupState(FLAVOR, st)[normKey(chip._label)] || 0;
        setText(chip._num, String(q));
        chip.classList.toggle('is-on', q > 0);
        have += q;
        if (q > 0) picks.push({ label: chip._label, qty: q });
      });
      c2.classList.toggle('is-done', need > 0 && have === need);
      setText(c2._state, need ? (have + ' / ' + need + '병') : '선택 안 함');

      /* STEP 3.. */
      extraRows.forEach(function (r) {
        var q = groupState(r.field, st)[normKey(r.row._label)] || 0;
        setText(r.row._num, String(q));
        r.row.classList.toggle('is-on', q > 0);
      });
      EXTRAS.forEach(function (f) {
        var n = 0, m = groupState(f, st);
        Object.keys(m).forEach(function (k) { n += m[k]; });
        f._card.classList.toggle('is-done', n > 0);
        setText(f._card._state, n > 0 ? n + '개 선택' : '선택 안 함');
      });

      /* 요약 */
      if (totalEl) setText(totalOut, totalEl.textContent.trim());
      setText(gnum, have + ' / ' + need);
      gauge.classList.toggle('is-off', need > 0 && have !== need);
      fill.style.width = need ? Math.min(100, (have / need) * 100) + '%' : '0%';

      picked.textContent = '';
      if (!picks.length) {
        picked.appendChild(el('div', 'dhx-picked__empty', '맛을 선택하면 여기에 내역이 쌓입니다.'));
      } else {
        picks.forEach(function (p) {
          var r = el('div', 'dhx-picked__row');
          r.appendChild(el('b', '', p.label));
          r.appendChild(el('span', '', p.qty + '병'));
          picked.appendChild(r);
        });
      }
    }

    var timer = null;
    var mo = new MutationObserver(function () {
      if (busy || timer) return;
      timer = setTimeout(function () { timer = null; sync(); }, 60);
    });
    mo.observe(builder, { childList: true, subtree: true, characterData: true });

    /* 스크롤 등장 — 과하지 않게 카드 단위로만, 사용자 설정 존중 */
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches &&
        'IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          if (e.isIntersecting) {
            e.target.classList.add('dhx-in');
            io.unobserve(e.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px' });
      [].forEach.call(
        document.querySelectorAll('.dhx-card, .dhx-buybox, .dhx-below'),
        function (n) { n.classList.add('dhx-pre'); io.observe(n); }
      );
      /* 안전장치 — 어떤 이유로든 reveal이 안 되면 강제로 표시 (절대 안 보이는 카드 방지) */
      var revealAll = function () {
        [].forEach.call(document.querySelectorAll('.dhx-pre:not(.dhx-in)'),
          function (n) { n.classList.add('dhx-in'); });
      };
      setTimeout(revealAll, 1800);
      window.addEventListener('scroll', function onScr() {
        setTimeout(revealAll, 900);
        window.removeEventListener('scroll', onScr);
      }, { passive: true });
    }

    sync();
  }

  ready(function () {
    boot();                                   /* 즉시 */
    [150, 400, 900, 2000].forEach(function (t) {
      setTimeout(boot, t);                    /* 요소가 늦게 생기는 경우 재시도 */
    });
  });
})();
