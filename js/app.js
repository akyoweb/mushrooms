/*!
 * داشبورد مانیتورینگ سالن‌های قارچ
 * jQuery + Chart.js
 */
(function ($) {
  "use strict";

  var API = window.APP.apiUrl;
  var POLL_INTERVAL = 30000; // بازخوانی خودکار هر ۳۰ ثانیه
  var CAPTURE_LABELS = {
    stored: "خوانش بازه ثبت شد",
    already: "بازه جاری ثبت شده",
    "no-sensor": "دماسنج وصل نشده",
    skipped: "بازه رد شد",
  };
  var FIELD_ORDER = ["air", "compost", "RH"];
  var state = {
    data: null,
    chart: null,
    chartField: "all",
    filter: "all",
    search: "",
    polling: null,
  };

  /* ------------------------------------------------------------------ */
  /* ابزارها                                                            */
  /* ------------------------------------------------------------------ */

  function faDigits(value) {
    return String(value).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
  }

  function num(value) {
    if (value === null || value === undefined || value === "") return "—";
    return faDigits(
      Number(value).toLocaleString("en-US", { maximumFractionDigits: 1 }),
    );
  }

  function statusTone(status) {
    return (
      { ok: "success", low: "info", high: "danger", nodata: "secondary" }[
        status
      ] || "secondary"
    );
  }

  function statusIcon(status) {
    return (
      {
        ok: "bi-check-circle-fill",
        low: "bi-arrow-down-circle-fill",
        high: "bi-arrow-up-circle-fill",
        nodata: "bi-question-circle-fill",
      }[status] || "bi-question-circle-fill"
    );
  }

  function toast(message, tone) {
    var id = "toast" + Date.now();
    var html =
      '<div id="' +
      id +
      '" class="toast align-items-center text-bg-' +
      (tone || "success") +
      ' border-0" role="alert">' +
      '<div class="d-flex"><div class="toast-body">' +
      message +
      "</div>" +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
      "</div></div>";
    var $el = $(html).appendTo("#toastArea");
    var t = new bootstrap.Toast($el[0], { delay: 3500 });
    t.show();
    $el.on("hidden.bs.toast", function () {
      $el.remove();
    });
  }

  function errorMessage(xhr) {
    if (xhr.responseJSON && xhr.responseJSON.error) {
      return xhr.responseJSON.error;
    }
    return "ارتباط با سرور برقرار نشد.";
  }

  /* ------------------------------------------------------------------ */
  /* دریافت داده                                                        */
  /* ------------------------------------------------------------------ */

  function loadDashboard(silent) {
    $("#btnRefresh").prop("disabled", true).find("i").addClass("spin");
    $.getJSON(API, { action: "snapshot" })
      .done(function (res) {
        if (!res.ok) {
          toast(res.error || "خطای نامشخص", "danger");
          return;
        }
        state.data = res;
        renderSummary(res);
        renderSaloons(res);
        renderTable(res);
        renderAlerts(res);
        renderChart(res.histories);
        renderSlots(res.slots || [], res);

        // زمان دقیق آخرین خوانش و بازه جاری (زمان سرور، نه زمان مرورگر)
        $("#updatedAt").text(res.updatedAt ? faDigits(res.updatedAt) : "—").attr("class", "fw-semibold slot-time");
        $("#currentPeriod").text(
          faDigits(res.currentPeriod || "—") +
            " → " +
            faDigits(String(res.periodEnd || "").slice(11, 16)),
        );
        $("#readingsCount").text(faDigits(res.readings));
        $("#cronLastRun").text(res.cronLog ? faDigits(res.cronLog) : "—");

        var action = (res.capture && res.capture.action) || "skipped";
        $("#captureState")
          .text(CAPTURE_LABELS[action] || action)
          .attr(
            "class",
            "badge text-bg-" +
              (action === "stored" || action === "already"
                ? "success"
                : action === "no-sensor"
                  ? "secondary"
                  : "warning"),
          )
          .attr("title", "هر " + faDigits(window.APP.interval) + " دقیقه — cron.php");
        $("#layoutBadge")
          .text("جدول‌های متصل (saloons ↔ readings)")
          .attr("class", "badge text-bg-primary");
        if (!silent && res.hasData) {
          // بازخوانی خاموش: پیام نمایش داده نمی‌شود
        }
      })
      .fail(function (xhr) {
        toast(errorMessage(xhr), "danger");
      })
      .always(function () {
        $("#btnRefresh").prop("disabled", false).find("i").removeClass("spin");
      });
  }

  /* ------------------------------------------------------------------ */
  /* رندر                                                               */
  /* ------------------------------------------------------------------ */

  function renderSummary(res) {
    FIELD_ORDER.forEach(function (field) {
      $('[data-avg="' + field + '"]').text(num(res.averages[field]));
      var count = res.alerts.filter(function (a) {
        return a.field === field;
      }).length;
      $('[data-alert-count="' + field + '"]')
        .text(faDigits(count) + " هشدار")
        .attr("class", "badge text-bg-" + (count ? "danger" : "success"));
    });
    $("#alertsTotal").text(faDigits(res.alertsCount));
  }

  function renderSaloons(res) {
    res.saloons.forEach(function (saloon) {
      var $card = $('.saloon-col[data-saloon="' + saloon.saloon + '"]').find(
        ".saloon-card",
      );
      var $state = $card.find(".saloon-state");

      $card.removeClass("skeleton").attr("data-status", saloon.status);

      if (saloon.status === "ok") {
        $state.html(
          '<span class="badge text-bg-success"><i class="bi bi-check2"></i> سالم</span>',
        );
      } else {
        $state.html(
          '<span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle"></i> ' +
            faDigits(saloon.issues.length) +
            " مورد</span>",
        );
      }

      var rows = FIELD_ORDER.map(function (field) {
        var v = saloon.values[field];
        return (
          '<div class="metric metric-' +
          v.status +
          '">' +
          '<span class="metric-label">' +
          v.label +
          "</span>" +
          '<span class="metric-value">' +
          num(v.value) +
          '<small class="unit">' +
          v.unit +
          "</small>" +
          '<i class="bi ' +
          statusIcon(v.status) +
          " metric-icon text-" +
          statusTone(v.status) +
          '"></i></span>' +
          '<span class="metric-range' +
          (v.custom ? " metric-range-custom" : "") +
          '" title="' +
          (v.custom ? "بازه تعیین‌شده توسط ادمین" : "بازه پیش‌فرض") +
          '">' +
          faDigits(v.good[0]) +
          "–" +
          faDigits(v.good[1]) +
          (v.custom ? ' <i class="bi bi-pin-angle-fill"></i>' : "") +
          "</span>" +
          "</div>"
        );
      }).join("");

      $card.find(".saloon-body").html(rows);

      // مرحله فعال سالن (از بخش اختصاصی سالن)
      var $stage = $card.find(".saloon-stage .stage-pill");
      if (saloon.stage) {
        $stage
          .html(
            '<i class="bi ' +
              (saloon.stage.stage_icon || "bi-diagram-2") +
              '"></i> مرحله: ' +
              saloon.stage.stage_name,
          )
          .css("--stage-color", saloon.stage.stage_color || "");
      } else {
        $stage
          .html('<i class="bi bi-pause-circle"></i> مرحله‌ای فعال نیست')
          .css("--stage-color", "");
      }
    });
    applyFilter();
  }

  function renderTable(res) {
    var html = res.saloons
      .map(function (s) {
        var cells = FIELD_ORDER.map(function (field) {
          var v = s.values[field];
          return (
            '<td><span class="table-val text-' +
            statusTone(v.status) +
            '">' +
            num(v.value) +
            '</span> <small class="text-muted">' +
            v.unit +
            "</small></td>"
          );
        }).join("");

        var statusCell = s.issues.length
          ? '<span class="badge text-bg-warning">' +
            s.issues.join("، ") +
            "</span>"
          : '<span class="badge text-bg-success">در محدوده مطلوب</span>';

        var stageCell = s.stage
          ? '<span class="stage-name" style="--stage-color:' +
            (s.stage.stage_color || "") +
            '"><i class="bi ' +
            (s.stage.stage_icon || "bi-diagram-2") +
            '"></i>' +
            s.stage.stage_name +
            "</span>"
          : '<span class="text-muted small">—</span>';

        return (
          '<tr class="' +
          (s.status === "warn" ? "table-warning-soft" : "") +
          '">' +
          '<td class="fw-semibold"><a class="saloon-link" href="saloon.php?id=' +
          s.saloon +
          '">سالن ' +
          faDigits(s.saloon) +
          "</a></td>" +
          cells +
          "<td>" +
          stageCell +
          "</td>" +
          "<td>" +
          statusCell +
          "</td>" +
          "</tr>"
        );
      })
      .join("");

    $("#tableBody").html(
      html ||
        '<tr><td colspan="6" class="text-center text-muted py-4">داده‌ای موجود نیست.</td></tr>',
    );
  }

  function renderAlerts(res) {
    if (!res.alerts.length) {
      $("#alertsList").html(
        '<div class="alert-empty">' +
          '<i class="bi bi-shield-check"></i>' +
          '<p class="mb-0 mt-2 text-muted small">' +
          (res.hasData
            ? "همه سالن‌ها در محدوده مطلوب هستند."
            : "هنوز خوانشی ثبت نشده است.") +
          "</p>" +
          "</div>",
      );
      return;
    }

    var html = res.alerts
      .slice(0, 12)
      .map(function (a) {
        var sign = a.status === "high" ? "بالاتر" : "پایین‌تر";
        return (
          '<a class="alert-item alert-' +
          a.status +
          '" href="#saloon-' +
          a.saloon +
          '">' +
          '<span class="alert-icon"><i class="bi ' +
          statusIcon(a.status) +
          '"></i></span>' +
          '<span class="alert-text">' +
          "<b>سالن " +
          faDigits(a.saloon) +
          "</b>" +
          (a.stage ? " («" + a.stage + "»)" : "") +
          " — " +
          a.label +
          '<small class="d-block text-muted">' +
          sign +
          " از محدوده • مقدار " +
          num(a.value) +
          a.unit +
          " (مطلوب " +
          faDigits(a.good[0]) +
          "–" +
          faDigits(a.good[1]) +
          ")</small>" +
          "</span></a>"
        );
      })
      .join("");

    var more =
      res.alerts.length > 12
        ? '<p class="small text-muted text-center mt-2 mb-0">و ' +
          faDigits(res.alerts.length - 12) +
          " هشدار دیگر...</p>"
        : "";

    $("#alertsList").html(html + more);
  }

  /** جدول بازه‌های ثبت هر نیم ساعت */
  function renderSlots(slots, res) {
    if (!slots.length) {
      $("#slotsBody").html(
        '<tr><td colspan="5" class="text-center text-muted py-3">داده‌ای برای نمایش نیست.</td></tr>',
      );
      return;
    }

    var html = slots
      .map(function (slot, index) {
        var pending = index === 0 && slot.status !== "ok";
        var tone = slot.status === "ok" ? "success" : pending ? "secondary" : "warning";
        var text = slot.status === "ok" ? "ثبت شد" : pending ? "در انتظار" : "بدون خوانش";
        return (
          '<tr class="' +
          (slot.status !== "ok" && !pending ? "table-warning-soft" : "") +
          ">" +
          '<td class="slot-time text-end">' +
          faDigits(slot.period_start.slice(11, 16)) +
          " – " +
          faDigits(slot.period_end.slice(11, 16)) +
          "</td>" +
          '<td class="slot-time text-end">' +
          faDigits(slot.period_start.slice(0, 10)) +
          "</td>" +
          '<td class="slot-time text-end">' +
          faDigits(slot.period_end.slice(11, 16)) +
          "</td>" +
          '<td class="slot-time text-end">' +
          (slot.read_at ? faDigits(slot.read_at) : "—") +
          "</td>" +
          '<td><span class="badge text-bg-' +
          tone +
          '">' +
          text +
          "</span></td>" +
          "</tr>"
        );
      })
      .join("");

    $("#slotsBody").html(html);
  }

  /* ------------------------------------------------------------------ */
  /* نمودار                                                             */
  /* ------------------------------------------------------------------ */

  function buildDatasets(histories, fieldFilter) {
    var airAxis = "y";
    var datasets = [];
    var legend = [];

    FIELD_ORDER.forEach(function (field) {
      if (fieldFilter !== "all" && fieldFilter !== field) return;
      var points = histories[field] || [];
      if (!points.length) return;

      var isHumidity = field === "RH";
      var name =
        histories[field].label ||
        (FIELD_LABELS[field] || field) +
          (isHumidity ? " (%)" : " (°C)");
      datasets.push({
        label: name,
        data: points.map(function (p) {
          return p.avg;
        }),
        borderColor: FIELD_COLORS[field].line,
        backgroundColor: FIELD_COLORS[field].fill,
        borderWidth: 2,
        pointRadius: points.length > 20 ? 0 : 3,
        pointHoverRadius: 5,
        tension: 0.35,
        fill: false,
        borderDash: isHumidity ? [6, 4] : [],
        yAxisID: isHumidity ? "y1" : airAxis,
      });
      legend.push({
        field: field,
        label:
          histories[field].label || FIELD_LABELS[field] || field,
        color: FIELD_COLORS[field].line,
      });
    });

    return { datasets: datasets, legend: legend };
  }

  var FIELD_COLORS = {};

  var FIELD_LABELS = {
    air: "دمای هوا",
    compost: "دمای کمپوست",
    RH: "رطوبت نسبی",
  };

  function initColors(fields) {
    Object.keys(fields).forEach(function (f) {
      if (fields[f].label) FIELD_LABELS[f] = fields[f].label;
      FIELD_COLORS[f] = {
        line: fields[f].color,
        fill: fields[f].color + "22",
      };
    });
  }

  function renderChart(histories) {
    if (!state.chart) return;
    var built = buildDatasets(histories, state.chartField);
    state.chart.data.datasets = built.datasets;

    // برچسب محور افقی: زمان دقیق ثبت هر خوانش (مثل 10:30:07)
    var times = [];
    Object.keys(histories).forEach(function (f) {
      (histories[f] || []).forEach(function (p) {
        if (p.t && times.indexOf(p.t) === -1) times.push(p.t);
      });
    });
    times.sort();
    var maxLen = Math.max.apply(
      null,
      [0].concat(
        Object.keys(histories).map(function (f) {
          return (histories[f] || []).length;
        }),
      ),
    );
    state.chart.data.labels = times.length
      ? times.map(function (t) {
          return faDigits(String(t).slice(11, 16));
        })
      : [];
    if (!times.length && maxLen) {
      for (var i = 1; i <= maxLen; i++) state.chart.data.labels.push("#" + faDigits(i));
    }
    state.chart.update();

    $("#chartLegend").html(
      built.legend
        .map(function (l) {
          return (
            '<span class="legend-item"><i style="background:' +
            l.color +
            '"></i>' +
            l.label +
            "</span>"
          );
        })
        .join(""),
    );

    var empty = maxLen === 0;
    $("#chartEmpty").toggle(empty);
  }

  function createChart(fields) {
    var ctx = document.getElementById("chart");
    if (!ctx) return;
    if (fields) initColors(fields);

    state.chart = new Chart(ctx, {
      type: "line",
      data: { labels: [], datasets: [] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        animation: { duration: 450 },
        plugins: {
          legend: { display: false },
          tooltip: {
            rtl: true,
            textDirection: "rtl",
            callbacks: {
              label: function (item) {
                return (
                  " " +
                  item.dataset.label +
                  ": " +
                  faDigits(item.formattedValue)
                );
              },
            },
          },
        },
        scales: {
          x: {
            grid: { color: "rgba(0,0,0,.04)" },
            title: { display: true, text: "زمان خوانش", color: "#6c757d" },
            ticks: {
              color: "#6c757d",
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 10,
            },
          },
          y: {
            position: "right",
            title: { display: true, text: "دما (°C)", color: "#6c757d" },
            grid: { color: "rgba(0,0,0,.06)" },
            ticks: { color: "#6c757d" },
          },
          y1: {
            position: "left",
            title: { display: true, text: "رطوبت (%)", color: "#2d6cdf" },
            grid: { drawOnChartArea: false },
            min: 0,
            max: 100,
            ticks: { color: "#2d6cdf" },
          },
        },
      },
    });
  }

  /* ------------------------------------------------------------------ */
  /* فیلتر سالن‌ها                                                      */
  /* ------------------------------------------------------------------ */

  function applyFilter() {
    $(".saloon-col").each(function () {
      var $col = $(this);
      var status = $col.find(".saloon-card").attr("data-status");
      var saloon = $col.attr("data-saloon");
      var matchSearch =
        !state.search || String(saloon).indexOf(state.search) !== -1;
      var matchStatus = state.filter === "all" || status === state.filter;
      $col.toggle(matchSearch && matchStatus);
    });
  }

  /* ------------------------------------------------------------------ */
  /* رویدادها                                                           */
  /* ------------------------------------------------------------------ */

  $(function () {
    createChart(window.APP.fields);
    loadDashboard(true);

    state.polling = setInterval(function () {
      loadDashboard(true);
    }, POLL_INTERVAL);

    $("#btnRefresh").on("click", function () {
      loadDashboard(false);
      toast("داده‌ها بازخوانی شد.", "primary");
    });

    $("#btnSimulate").on("click", function () {
      var $btn = $(this).prop("disabled", true);
      $.post(API, { action: "simulate" })
        .done(function (res) {
          if (res.ok) {
            toast(res.message, "success");
            loadDashboard(true);
          } else {
            toast(res.error || "خطا", "danger");
          }
        })
        .fail(function (xhr) {
          toast(errorMessage(xhr), "danger");
        })
        .always(function () {
          $btn.prop("disabled", false);
        });
    });

    $("#chartToggles").on("click", "button", function () {
      $(this).addClass("active").siblings().removeClass("active");
      state.chartField = $(this).data("field");
      if (state.data) {
        if (!Object.keys(FIELD_COLORS).length && state.data.fields)
          initColors(state.data.fields);
        renderChart(state.data.histories);
      }
    });

    $("#saloonFilter").on("change", function () {
      state.filter = $(this).val();
      applyFilter();
    });

    $("#saloonSearch").on("input", function () {
      state.search = $(this).val().trim();
      applyFilter();
    });

    // کلیک روی هشدار: پرش به کارت سالن (کلیک دوم وارد بخش اختصاصی سالن می‌شود)
    $(document).on("click", ".alert-item", function () {
      var id = $(this).attr("href");
      var $target = $(id);
      if ($target.length) {
        $("html, body").animate({ scrollTop: $target.offset().top - 80 }, 300);
        $target.find(".saloon-card").addClass("flash");
        setTimeout(function () {
          $target.find(".saloon-card").removeClass("flash");
        }, 1200);
      }
      return false;
    });

    // توقف درخواست‌ها وقتی تب مخفی است
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) {
        clearInterval(state.polling);
      } else {
        loadDashboard(true);
        state.polling = setInterval(function () {
          loadDashboard(true);
        }, POLL_INTERVAL);
      }
    });
  });
})(jQuery);
