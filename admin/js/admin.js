/**
 * SFAF Chat admin screens: the live appearance preview and the question set editor.
 * Nothing here saves anything. Both screens are ordinary forms, and PHP has the last
 * word on every value.
 */
(function () {
  'use strict';

  var DATA = window.sfafChatAdmin || {};

  /* ============================== APPEARANCE ============================== */

  function rgb(hex) {
    return [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
  }

  function luminance(hex) {
    var c = rgb(hex).map(function (v) {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }

  function contrast(a, b) {
    var x = luminance(a);
    var y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }

  // KEEP IN STEP WITH SFAF_Chat_Appearance::on_accent().
  function onAccent(values) {
    var list = [values.dark, '#373433', '#000000', '#ffffff'];
    for (var i = 0; i < list.length; i++) {
      if (contrast(list[i], values.accent) >= 4.5) return list[i];
    }
    return '#000000';
  }

  function validHex(v) {
    return /^#[0-9a-f]{6}$/i.test(v);
  }

  function initAppearance() {
    var form = document.getElementById('sfaf-chat-appearance');
    var preview = document.getElementById('sfaf-chat-preview');
    var report = document.getElementById('sfaf-chat-contrast');
    if (!form || !preview || !report) return;

    var fields = form.querySelectorAll('[data-color]');

    function values() {
      var out = {};
      Array.prototype.forEach.call(fields, function (f) { out[f.getAttribute('data-color')] = f.value.trim(); });
      return out;
    }

    function setVar(name, value) {
      preview.style.setProperty('--p-' + name, value);
    }

    function showReport(problems) {
      report.textContent = '';
      report.classList.toggle('is-ok', !problems.length);
      var p = document.createElement('p');
      if (!problems.length) {
        p.textContent = 'All text meets the readable contrast standard (WCAG AA).';
        report.appendChild(p);
        return;
      }
      var strong = document.createElement('strong');
      strong.textContent = 'Some text is hard to read:';
      p.appendChild(strong);
      report.appendChild(p);
      var ul = document.createElement('ul');
      problems.forEach(function (text) {
        var li = document.createElement('li');
        li.textContent = text;
        ul.appendChild(li);
      });
      report.appendChild(ul);
    }

    function refresh() {
      var v = values();
      var problems = [];

      Object.keys(v).forEach(function (key) {
        if (validHex(v[key])) setVar(key.replace(/_/g, '-'), v[key]);
      });

      var allValid = Object.keys(v).every(function (key) { return validHex(v[key]); });
      if (allValid) {
        setVar('on-accent', onAccent(v));
        (DATA.pairs || []).forEach(function (pair) {
          var ratio = contrast(v[pair[0]], v[pair[1]]);
          if (ratio < 4.5) {
            problems.push(pair[2] + ' is ' + (Math.floor(ratio * 100) / 100).toFixed(2) + ' to 1. Readable text needs at least 4.5 to 1.');
          }
        });
        showReport(problems);
      }

      var radius = parseInt(form.elements.radius.value, 10);
      setVar('radius', (isNaN(radius) ? 0 : Math.max(0, radius)) + 'px');

      var site = form.querySelector('[name="font_mode"][value="site"]').checked;
      setVar('font', site ? 'inherit' : (form.elements.font.value.trim() || 'inherit'));
      form.elements.font.readOnly = site;
    }

    // The swatch and the hex box stay the same color, whichever is used.
    Array.prototype.forEach.call(form.querySelectorAll('[data-swatch-for]'), function (swatch) {
      var text = document.getElementById(swatch.getAttribute('data-swatch-for'));
      swatch.addEventListener('input', function () {
        text.value = swatch.value;
        refresh();
      });
      text.addEventListener('input', function () {
        if (validHex(text.value.trim())) swatch.value = text.value.trim().toLowerCase();
      });
    });

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    refresh();
  }

  /* ============================ QUESTION SETS ============================= */

  function initQuestions() {
    var form = document.getElementById('sfaf-chat-starters');
    var list = document.getElementById('sfaf-chat-sets');
    var template = document.getElementById('sfaf-chat-set-template');
    var addBtn = document.getElementById('sfaf-chat-add-set');
    var status = document.getElementById('sfaf-chat-moved');
    if (!form || !list || !template || !addBtn) return;

    // Form indexes only have to be unique: PHP keeps the order they arrive in.
    var next = list.querySelectorAll('[data-set]').length;

    function say(text) {
      if (status) status.textContent = text;
    }

    // A control at the end of a list that can't go further is switched off.
    function refreshButtons() {
      Array.prototype.forEach.call(form.querySelectorAll('[data-questions]'), function (ol) {
        var rows = ol.children;
        Array.prototype.forEach.call(rows, function (li, i) {
          li.querySelector('[data-move="up"]').disabled = i === 0;
          li.querySelector('[data-move="down"]').disabled = i === rows.length - 1;
        });
      });
      var sets = list.querySelectorAll('[data-set]');
      Array.prototype.forEach.call(sets, function (set, i) {
        set.querySelector('[data-set-move="up"]').disabled = i === 0;
        set.querySelector('[data-set-move="down"]').disabled = i === sets.length - 1;
      });
    }

    // Moving a disabled button would lose focus, so land on the other arrow instead.
    function keepFocus(button, other) {
      refreshButtons();
      (button.disabled ? other : button).focus();
    }

    form.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest) return;

      var move = t.closest('[data-move]');
      if (move) {
        var li = move.closest('li');
        var up = move.getAttribute('data-move') === 'up';
        var sibling = up ? li.previousElementSibling : li.nextElementSibling;
        if (sibling) {
          li.parentNode.insertBefore(li, up ? sibling : sibling.nextElementSibling);
          keepFocus(move, li.querySelector('[data-move="' + (up ? 'down' : 'up') + '"]'));
          say(up ? 'Question moved up.' : 'Question moved down.');
        }
        return;
      }

      var setMove = t.closest('[data-set-move]');
      if (setMove) {
        var set = setMove.closest('[data-set]');
        var goUp = setMove.getAttribute('data-set-move') === 'up';
        var near = goUp ? set.previousElementSibling : set.nextElementSibling;
        if (near) {
          set.parentNode.insertBefore(set, goUp ? near : near.nextElementSibling);
          keepFocus(setMove, set.querySelector('[data-set-move="' + (goUp ? 'down' : 'up') + '"]'));
          say(goUp ? 'Set moved up.' : 'Set moved down.');
        }
        return;
      }

      var remove = t.closest('[data-set-remove]');
      if (remove) {
        if (window.confirm('Remove this set? It is gone once you save.')) {
          remove.closest('[data-set]').remove();
          refreshButtons();
          addBtn.focus();
          say('Set removed.');
        }
      }
    });

    addBtn.addEventListener('click', function () {
      var holder = document.createElement('div');
      holder.innerHTML = template.innerHTML.replace(/__i__/g, String(next++));
      var set = holder.firstElementChild;
      list.appendChild(set);
      refreshButtons();
      set.querySelector('input').focus();
    });

    refreshButtons();
  }

  initAppearance();
  initQuestions();
})();
