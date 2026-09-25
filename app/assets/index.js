/* owlsgo v3 交互脚本（ES5，CSP script-src 'self'，全部行为外置） */
(function () {
  'use strict';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) {
    var list = (ctx || document).querySelectorAll(sel);
    var out = [];
    for (var i = 0; i < list.length; i++) { out.push(list[i]); }
    return out;
  }
  function on(el, ev, fn) { if (el) { el.addEventListener(ev, fn, false); } }

  /* ---------- 气泡提示 ---------- */
  var toastTimer = null;
  function toast(msg) {
    var old = $('.toast');
    if (old) { old.parentNode.removeChild(old); }
    var el = document.createElement('div');
    el.className = 'toast';
    el.textContent = msg;
    document.body.appendChild(el);
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () {
      if (el.parentNode) { el.parentNode.removeChild(el); }
    }, 2600);
  }

  /* ---------- 移动抽屉菜单 ---------- */
  on($('[data-drawer-toggle]'), 'click', function () {
    var nav = $('[data-drawer]');
    if (nav) { nav.className = nav.className.indexOf('open') >= 0 ? nav.className.replace(/\s*open/g, '') : nav.className + ' open'; }
  });

  /* ---------- 用户菜单下拉 ---------- */
  on($('[data-dropdown-toggle]'), 'click', function (e) {
    e.stopPropagation();
    var dd = $('.dropdown', this.parentNode);
    if (dd) { dd.className = dd.className.indexOf('open') >= 0 ? dd.className.replace(/\s*open/g, '') : dd.className + ' open'; }
  });
  on(document, 'click', function () {
    var dd = $('.dropdown.open');
    if (dd) { dd.className = dd.className.replace(/\s*open/g, ''); }
  });

  /* ---------- 确认对话框（链接/按钮/表单） ---------- */
  on(document, 'click', function (e) {
    var el = e.target;
    while (el && el !== document) {
      if (el.getAttribute && el.getAttribute('data-confirm')) {
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          e.preventDefault();
          e.stopPropagation();
        }
        return;
      }
      el = el.parentNode;
    }
  });
  on(document, 'submit', function (e) {
    var f = e.target;
    if (f.getAttribute && f.getAttribute('data-confirm-submit')) {
      if (!window.confirm(f.getAttribute('data-confirm-submit'))) {
        e.preventDefault();
      }
    }
  });

  /* ---------- AJAX 表单 ---------- */
  function ajaxSubmit(form) {
    var data = new FormData(form);
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.action || window.location.href, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      if (btn) { btn.disabled = false; }
      var res = null;
      try { res = JSON.parse(xhr.responseText); } catch (err) {}
      if (!res) {
        toast('网络异常，请重试');
        return;
      }
      if (!res.ok) {
        toast(res.error || '操作失败');
        if (res.login) { window.location.href = res.login; }
        return;
      }
      var field = form.getAttribute('data-state-field');
      if (field === 'like' || field === 'favorite') {
        var button = form.querySelector('.op-btn');
        if (button) {
          if (res.active) {
            if (button.className.indexOf('active') < 0) { button.className += ' active'; }
          } else {
            button.className = button.className.replace(/\s*active/g, '');
          }
          var count = button.querySelector('[data-like-count]');
          if (count && typeof res.count !== 'undefined') { count.textContent = res.count; }
        }
        toast(res.active ? '已加入' : '已取消');
      } else if (typeof res.state !== 'undefined') {
        var b = form.querySelector('.op-btn');
        if (b) {
          if (res.state) {
            if (b.className.indexOf('active') < 0) { b.className += ' active'; }
          } else {
            b.className = b.className.replace(/\s*active/g, '');
          }
        }
        toast('操作成功');
      }
      if (form.getAttribute('data-reset')) {
        form.reset();
        clearDraft(form);
      }
      if (res.redirect) {
        window.location.href = res.redirect;
      }
    };
    xhr.send(data);
  }
  on(document, 'submit', function (e) {
    var form = e.target;
    if (form.getAttribute && form.getAttribute('data-ajax')) {
      e.preventDefault();
      ajaxSubmit(form);
    }
  });

  /* ---------- 编辑器：草稿 / 工具栏 / 预览 / 上传 ---------- */
  function draftKey(editor) { return 'owlsgo-draft-' + (editor.getAttribute('data-draft-key') || 'default'); }
  function clearDraft(form) {
    var editor = form.querySelector('[data-editor]');
    if (!editor) { return; }
    try {
      window.localStorage.removeItem(draftKey(editor));
      window.sessionStorage.setItem(draftKey(editor) + '-done', '1');
    } catch (e) {}
  }
  function initEditor(editor) {
    var area = editor.querySelector('[data-editor-area]');
    var key = draftKey(editor);
    var i;
    // 提交成功跳转回来：清掉本地草稿与本框内容（服务端一次性标记）
    if (editor.getAttribute('data-draft-clear')) {
      try {
        window.localStorage.removeItem(key);
        window.sessionStorage.setItem(key + '-done', '1');
      } catch (e) {}
      area.value = '';
    }
    // 草稿恢复（已提交标记优先）
    try {
      if (!area.value && !window.sessionStorage.getItem(key + '-done')) {
        var draft = window.localStorage.getItem(key);
        if (draft) { area.value = draft; }
      }
    } catch (e) {}
    var timer = null;
    on(area, 'input', function () {
      if (timer) { clearTimeout(timer); }
      timer = setTimeout(function () {
        try {
          window.localStorage.setItem(key, area.value);
          window.sessionStorage.removeItem(key + '-done');
        } catch (e) {}
      }, 400);
    });
    // 工具栏
    var mdBtns = $$('[data-md]', editor);
    for (i = 0; i < mdBtns.length; i++) {
      (function (btn) {
        on(btn, 'click', function () {
          insertAtCursor(area, btn.getAttribute('data-md'));
        });
      })(mdBtns[i]);
    }
    var blockBtns = $$('[data-md-block]', editor);
    for (i = 0; i < blockBtns.length; i++) {
      (function (btn) {
        on(btn, 'click', function () {
          insertAtCursor(area, '\n' + btn.getAttribute('data-md-block') + '\n');
        });
      })(blockBtns[i]);
    }
    // 预览
    var previewBtn = editor.querySelector('[data-preview-toggle]');
    var preview = editor.querySelector('[data-editor-preview]');
    on(previewBtn, 'click', function () {
      if (!preview) { return; }
      if (preview.hidden) {
        var form = editor.closest('form');
        var token = form ? form.querySelector('input[name="_token"]') : null;
        var data = new FormData();
        data.append('content', area.value);
        if (token) { data.append('_token', token.value); }
        var xhr = new XMLHttpRequest();
        var base = window.location.pathname.replace(/\/[^/]*$/, '/');
        xhr.open('POST', base + 'index.php?a=preview', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) { return; }
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.ok) {
              preview.innerHTML = res.html;
              preview.hidden = false;
              area.hidden = true;
            }
          } catch (err) { toast('预览失败'); }
        };
        xhr.send(data);
      } else {
        preview.hidden = true;
        preview.innerHTML = '';
        area.hidden = false;
      }
    });
    // 上传
    var fileInput = editor.querySelector('[data-upload]');
    on(fileInput, 'change', function () {
      if (!fileInput.files || !fileInput.files[0]) { return; }
      var form = editor.closest('form');
      var token = form ? form.querySelector('input[name="_token"]') : null;
      var data = new FormData();
      data.append('file', fileInput.files[0]);
      if (token) { data.append('_token', token.value); }
      var xhr = new XMLHttpRequest();
      var base = window.location.pathname.replace(/\/[^/]*$/, '/');
      xhr.open('POST', base + 'index.php?a=upload', true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) { return; }
        fileInput.value = '';
        try {
          var res = JSON.parse(xhr.responseText);
          if (!res.ok) { toast(res.error || '上传失败'); return; }
          var md = res.is_image ? ('![' + res.name + '](' + res.url + ')') : ('[' + res.name + '](' + res.url + ')');
          insertAtCursor(area, '\n' + md + '\n');
          toast('已插入：' + res.name);
        } catch (err) { toast('上传失败'); }
      };
      xhr.send(data);
    });
  }
  function insertAtCursor(area, text) {
    var start = area.selectionStart || 0;
    var end = area.selectionEnd || 0;
    var before = area.value.substring(0, start);
    var after = area.value.substring(end);
    area.value = before + text + after;
    area.focus();
    area.selectionStart = area.selectionEnd = start + text.length;
  }
  var editors = $$('[data-editor]');
  for (var ei = 0; ei < editors.length; ei++) { initEditor(editors[ei]); }

  /* ---------- 评论指定楼层 ---------- */
  on(document, 'click', function (e) {
    var el = e.target;
    while (el && el !== document) {
      if (el.getAttribute && el.getAttribute('data-reply-to')) {
        e.preventDefault();
        var box = $('#reply-box');
        if (!box) { return; }
        var input = box.querySelector('[data-parent-id]');
        var tip = box.querySelector('[data-reply-tip]');
        if (input) { input.value = el.getAttribute('data-reply-to'); }
        if (tip) {
          tip.hidden = false;
          tip.innerHTML = '';
          tip.appendChild(document.createTextNode('评论 ' + el.getAttribute('data-floor') + ' 楼'));
          var cancel = document.createElement('span');
          cancel.className = 'cancel';
          cancel.textContent = '取消';
          on(cancel, 'click', function () {
            input.value = '0';
            tip.hidden = true;
          });
          tip.appendChild(cancel);
        }
        var area = box.querySelector('textarea');
        if (area) { area.focus(); }
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      el = el.parentNode;
    }
  });

  /* ---------- 图片灯箱 ---------- */
  on(document, 'click', function (e) {
    var el = e.target;
    if (el.tagName === 'IMG' && el.className.indexOf('content-image') >= 0) {
      var box = document.createElement('div');
      box.className = 'lightbox';
      var img = document.createElement('img');
      img.src = el.src;
      box.appendChild(img);
      on(box, 'click', function () { box.parentNode.removeChild(box); });
      document.body.appendChild(box);
    }
  });
  on(document, 'keydown', function (e) {
    if (e.keyCode === 27) {
      var box = $('.lightbox');
      if (box) { box.parentNode.removeChild(box); }
    }
  });

  /* ---------- 长内容折叠 ---------- */
  var contents = $$('.reply-list .post-content');
  for (var ci = 0; ci < contents.length; ci++) {
    (function (el) {
      if (el.scrollHeight > 420) {
        el.className += ' folded';
        var toggle = document.createElement('span');
        toggle.className = 'fold-toggle';
        toggle.textContent = '展开全部';
        on(toggle, 'click', function () {
          el.className = el.className.replace(/\s*folded/g, '');
          toggle.parentNode.removeChild(toggle);
        });
        el.parentNode.insertBefore(toggle, el.nextSibling);
      }
    })(contents[ci]);
  }

  /* ---------- 批量全选 ---------- */
  var checkAll = $('[data-check-all]');
  on(checkAll, 'change', function () {
    var form = checkAll.closest('form');
    var boxes = $$('input[type="checkbox"][name="ids[]"]', form);
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = checkAll.checked; }
  });

  /* ---------- 安装页数据库切换 ---------- */
  var driverSelect = $('[data-driver-select]');
  on(driverSelect, 'change', function () {
    var extra = $('[data-db-extra]');
    if (extra) { extra.hidden = driverSelect.value === 'sqlite'; }
    var port = document.querySelector('input[name="port"]');
    if (port && driverSelect.value === 'pgsql') { port.value = '5432'; }
    if (port && driverSelect.value === 'mysql') { port.value = '3306'; }
  });
  if (driverSelect) {
    var evt = document.createEvent('HTMLEvents');
    evt.initEvent('change', true, false);
    driverSelect.dispatchEvent(evt);
  }

  /* ---------- 外链新窗口 ---------- */
  var links = $$('.post-content a');
  for (var li = 0; li < links.length; li++) {
    if (/^https?:\/\//.test(links[li].href) && links[li].host !== window.location.host) {
      links[li].target = '_blank';
      links[li].rel = 'noopener nofollow ugc';
    }
  }
})();
