// Подсветка правок по отделам для совместной правки договора закупки.
//
// Каждый участник красит фоном цвета своего отдела только СВОИ вставки
// (отслеживаемые правки «добавлено» с его userId). Поэтому отделы не спорят за
// один фрагмент, а текст, набранный внутри чужой правки, перекрашивается в свой
// цвет. Удаления не красим: редактор и так рисует их зачёркиванием в цвете
// автора, а заливка на удалённом тексте осталась бы на исходном тексте договора,
// если удаление отклонят.
//
// Заливка — обычное изменение документа: соавторы видят её сразу, и она
// сохраняется в docx как w:shd. На время покраски рецензирование выключается
// локально, иначе сама заливка стала бы правкой «Форматирование».
//
// Отдел приходит из editorConfig.plugins.options (PurchaseFileCoEditing).
// Цвета — те же, что в легенде редактора на фронте (PurchaseFileEditor.tsx).
//
// Утверждающему (clearAccepted) плагин наоборот снимает эту заливку с текста,
// который уже не вставка: «Принять» убирает правку, а цвет отдела оставался.
(function (window) {
  var COLORS = {
    PURCHASE_DEPARTMENT: [201, 231, 202],
    ACCOUNTING: [184, 234, 230],
    LEGAL: [224, 210, 242],
    PROFILE_DEPUTY: [255, 241, 166],
    DIRECTOR: [249, 198, 198],
  };
  var MODIFIER_KEYS = [16, 17, 18, 91, 92, 93, 224];
  var RESUME_AFTER_UNDO_MS = 4000;
  // Красим после паузы в своём наборе: покраска берёт абзац под блокировку,
  // и посреди набора она спорила бы и с автором, и с соавтором в том же абзаце.
  var IDLE_BEFORE_PAINT_MS = 2000;
  // Покраску может откатить сервер (абзац занят соавтором) — проверяем ещё раз.
  var RECHECK_AFTER_MS = 2500;

  var rgb = null;
  var me = null;
  var clearMode = false;
  var DEPT_COLORS = [
    [201, 231, 202],
    [184, 234, 230],
    [224, 210, 242],
    [255, 241, 166],
    [249, 198, 198],
  ];
  var pending = {};
  var deferred = {};
  var busy = false;
  var lastKeyAt = 0;
  var idleTimer = null;
  // Покраска — отдельный шаг отмены. После Ctrl+Z / Ctrl+Y не красим, пока
  // человек снова не начнёт печатать или не пройдёт пауза: иначе отмена
  // чередовалась бы с перекраской.
  var suppressed = false;
  var resumeTimer = null;

  function resume() {
    suppressed = false;
    clearTimeout(resumeTimer);
    resumeTimer = null;
    Object.keys(deferred).forEach(function (pid) { pending[pid] = true; });
    deferred = {};
    pump();
  }

  function enqueue(pid) {
    (suppressed ? deferred : pending)[pid] = true;
    pump();
  }

  window.Asc.plugin.init = function () {
    var info = this.info || {};
    var options = info.options || {};
    rgb = COLORS[options.department] || null;
    me = info.userId;
    clearMode = !!options.clearAccepted;
    if (info.isViewMode || (!clearMode && !rgb)) {
      return;
    }

    this.attachEditorEvent('onKeyDown', function (e) {
      var code = e && (e.keyCode || e.which);
      if (MODIFIER_KEYS.indexOf(code) !== -1) {
        return;
      }
      lastKeyAt = Date.now();
      if ((e.ctrlKey || e.metaKey) && (code === 90 || code === 89)) {
        suppressed = true;
        clearTimeout(resumeTimer);
        resumeTimer = setTimeout(resume, RESUME_AFTER_UNDO_MS);
        return;
      }
      if (suppressed) {
        resume();
      }
    });

    // Приходит через ~1 с после паузы в наборе в абзаце — и после своих, и после чужих правок.
    this.attachEditorEvent('onParagraphText', function (ev) {
      enqueue(ev.paragraphId);
    });
  };

  // У callCommand один слот на ответ, поэтому абзацы идут по одному.
  function pump() {
    if (busy || suppressed) {
      return;
    }
    var pid = Object.keys(pending)[0];
    if (!pid) {
      return;
    }
    var wait = lastKeyAt + IDLE_BEFORE_PAINT_MS - Date.now();
    if (wait > 0) {
      clearTimeout(idleTimer);
      idleTimer = setTimeout(pump, wait);
      return;
    }
    delete pending[pid];
    busy = true;
    Asc.scope.pid = pid;
    Asc.scope.me = me;
    Asc.scope.rgb = rgb;
    Asc.scope.clearAccepted = clearMode;
    Asc.scope.colors = DEPT_COLORS;

    window.Asc.plugin.callCommand(function () {
      var para = Api.GetByInternalId(Asc.scope.pid);
      if (!para || !para.GetElementsCount) {
        return 0;
      }
      var clear = !!Asc.scope.clearAccepted;
      var want = Asc.scope.rgb;
      var colors = Asc.scope.colors || [];
      var todo = [];

      function fillRgb(json) {
        var shd = json.rPr && json.rPr.shd;
        var fill = shd && (shd.fill || shd.color);
        return fill && (fill.rgb || fill);
      }

      function same(c, rgb) {
        return !!c && !!rgb && c.r === rgb[0] && c.g === rgb[1] && c.b === rgb[2];
      }

      function isDeptFill(json) {
        var c = fillRgb(json);
        for (var i = 0; i < colors.length; i++) {
          if (same(c, colors[i])) {
            return true;
          }
        }
        return false;
      }

      function visit(el) {
        var type = el && el.GetClassType ? el.GetClassType() : '';
        if (type === 'run') {
          var json;
          try {
            json = JSON.parse(el.ToJSON(false, false));
          } catch (e) {
            return;
          }
          if (clear) {
            // Принятая вставка уже не reviewType add, заливка на ней лишняя.
            if (json.reviewType !== 'add' && isDeptFill(json)) {
              todo.push(el);
            }
            return;
          }
          var review = json.reviewInfo || {};
          if (json.reviewType === 'add'
            && (review.userId || review.UserId) === Asc.scope.me
            && !same(fillRgb(json), want)) {
            todo.push(el);
          }
          return;
        }
        // Гиперссылки и поля внутри абзаца: их run-ы лежат уровнем ниже.
        if (el && el.GetElementsCount) {
          for (var i = 0; i < el.GetElementsCount(); i++) {
            visit(el.GetElement(i));
          }
        }
      }

      for (var i = 0; i < para.GetElementsCount(); i++) {
        visit(para.GetElement(i));
      }
      if (todo.length) {
        var track = Api.asc_GetLocalTrackRevisions();
        Api.asc_SetLocalTrackRevisions(false);
        todo.forEach(function (run) {
          if (clear) {
            run.SetShd('nil');
          } else {
            run.SetShd('clear', want[0], want[1], want[2]);
          }
        });
        Api.asc_SetLocalTrackRevisions(track);
      }
      return todo.length;
    }, false, false, function (painted) {
      busy = false;
      if (painted === undefined) {
        // Редактор был занят (сохранение, блокировка) — вернёмся к абзацу позже.
        deferred[pid] = true;
        if (!suppressed) {
          setTimeout(resume, 1500);
        }
      } else if (painted > 0) {
        setTimeout(function () { enqueue(pid); }, RECHECK_AFTER_MS);
      }
      pump();
    });
  }
})(window);
