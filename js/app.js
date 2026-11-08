/*!
 * داشبورد مانیتورینگ سالن‌های قارچ
 * jQuery + Chart.js
 */
(function ($) {
  "use strict";

  var API = window.APP.apiUrl;
  var POLL_INTERVAL = 30000; // بازخوانی خودکار هر ۳۰ ثانیه
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
        $("#updatedAt").text(faDigits(res.updatedAt));
        $("#readingsCount").text(faDigits(res.readings));
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

        return (
          '<tr class="' +
          (s.status === "warn" ? "table-warning-soft" : "") +
          '">' +
          '<td class="fw-semibold">سالن ' +
          faDigits(s.saloon) +
          "</td>" +
          cells +
          "<td>" +
          statusCell +
          "</td>" +
          "</tr>"
        );
      })
      .join("");

    $("#tableBody").html(
      html ||
        '<tr><td colspan="5" class="text-center text-muted py-4">داده‌ای موجود نیست.</td></tr>',
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
          "</b> — " +
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
      datasets.push({
        label: histories[field].label + (isHumidity ? " (%)" : " (°C)"),
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
        label: isHumidity ? "رطوبت نسبی" : histories[field].label,
        color: FIELD_COLORS[field].line,
      });
    });

    return { datasets: datasets, legend: legend };
  }

  var FIELD_COLORS = {};

  function initColors(fields) {
    Object.keys(fields).forEach(function (f) {
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

    var maxLen = 0;
    Object.keys(histories).forEach(function (f) {
      maxLen = Math.max(maxLen, (histories[f] || []).length);
    });
    state.chart.data.labels = [];
    for (var i = 1; i <= maxLen; i++) {
      state.chart.data.labels.push("#" + faDigits(i));
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
