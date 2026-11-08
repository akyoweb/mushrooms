/*!
 * فرم ثبت خوانش سالن‌ها
 * jQuery: اعتبارسنجی، پر کردن سریع و ارسال AJAX
 */
(function ($) {
  "use strict";

  var API = window.APP.apiUrl;
  var FIELDS = window.APP.fields || {};

  function faDigits(value) {
    return String(value).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
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
    new bootstrap.Toast($el[0], { delay: 4500 }).show();
    $el.on("hidden.bs.toast", function () {
      $el.remove();
    });
  }

  function randomAround(meta) {
    var center = (meta.good[0] + meta.good[1]) / 2;
    var spread = (meta.good[1] - meta.good[0]) / 2;
    var value = center + (Math.random() * 2 - 1) * spread * 0.8;
    return Math.round(value);
  }

  /** رنگ‌کردن ورودی بر اساس محدوده مطلوب */
  function markInput($input) {
    var value = parseFloat($input.val());
    var min = parseFloat($input.attr("data-good-min"));
    var max = parseFloat($input.attr("data-good-max"));
    var $cell = $input.closest("td");

    $cell.removeClass("cell-ok cell-bad");
    if ($input.val() === "" || isNaN(value)) {
      return;
    }
    if (value < min || value > max) {
      $cell.addClass("cell-bad");
    } else {
      $cell.addClass("cell-ok");
    }
  }

  function validate() {
    var errors = [];
    $("#readingForm .saloon-input").each(function (index) {
      var $input = $(this);
      var field = $input.data("field");
      var meta = FIELDS[field] || {};
      var saloon = Math.floor(index / Object.keys(FIELDS).length) + 1;
      var raw = $input.val();

      $input.removeClass("is-invalid");
      if (raw === "" || isNaN(Number(raw))) {
        $input.addClass("is-invalid");
        errors.push(
          "سالن " +
            faDigits(saloon) +
            " — " +
            (meta.label || field) +
            ": مقدار خالی است",
        );
        return;
      }
      var value = Number(raw);
      if (value < meta.min || value > meta.max) {
        $input.addClass("is-invalid");
        errors.push(
          "سالن " +
            faDigits(saloon) +
            " — " +
            (meta.label || field) +
            ": خارج از بازه " +
            faDigits(meta.min) +
            " تا " +
            faDigits(meta.max),
        );
      }
    });
    return errors;
  }

  function collect() {
    var data = { action: "save" };
    Object.keys(FIELDS).forEach(function (field) {
      data[field + "[]"] = [];
    });
    Object.keys(FIELDS).forEach(function (field) {
      data[field + "[]"] = $(
        '#readingForm .saloon-input[data-field="' + field + '"]',
      )
        .map(function () {
          return $(this).val();
        })
        .get();
    });
    return data;
  }

  $(function () {
    // اعمال مقدار گروهی به همه ۱۲ سالن
    function applyBulk(field) {
      var $bulk = $('.bulk-input[data-field="' + field + '"]');
      var value = $bulk.val();
      if (value === "") {
        toast(
          "ابتدا مقدار " + FIELDS[field].label + " را وارد کنید.",
          "warning",
        );
        return;
      }
      $('#readingForm .saloon-input[data-field="' + field + '"]').each(
        function () {
          $(this).val(value);
          markInput($(this));
        },
      );
    }

    $("[data-apply]").on("click", function () {
      applyBulk($(this).data("apply"));
    });

    $("#btnApplyAll").on("click", function () {
      Object.keys(FIELDS).forEach(function (field) {
        if ($('.bulk-input[data-field="' + field + '"]').val() !== "") {
          applyBulk(field);
        }
      });
      toast("مقادیر گروهی اعمال شد.", "primary");
    });

    $("#btnClearAll").on("click", function () {
      $("#readingForm")[0].reset();
      $("#readingForm .saloon-input").removeClass("is-invalid");
      $("#readingForm td").removeClass("cell-ok cell-bad");
    });

    // پر کردن یک ردیف با مقدار نمونه
    $(document).on("click", ".row-fill", function () {
      var saloon = $(this).data("saloon");
      var $inputs = $("#readingForm .saloon-input").slice(
        (saloon - 1) * Object.keys(FIELDS).length,
        saloon * Object.keys(FIELDS).length,
      );
      $inputs.each(function () {
        var field = $(this).data("field");
        $(this).val(randomAround(FIELDS[field]));
        markInput($(this));
      });
    });

    // کل فرم با داده نمونه
    $("#demoToggle").on("change", function () {
      var on = $(this).prop("checked");
      $("#readingForm .saloon-input").each(function () {
        var field = $(this).data("field");
        if (on) {
          $(this).val(randomAround(FIELDS[field]));
        } else {
          $(this).val("");
        }
        markInput($(this));
      });
    });

    $("#readingForm").on("input change", ".saloon-input", function () {
      $(this).removeClass("is-invalid");
      markInput($(this));
    });

    $("#readingForm").on("reset", function () {
      setTimeout(function () {
        $("#readingForm .saloon-input").removeClass("is-invalid");
        $("#readingForm td").removeClass("cell-ok cell-bad");
        $("#demoToggle").prop("checked", false);
      }, 0);
    });

    $("#readingForm").on("submit", function (e) {
      e.preventDefault();

      var errors = validate();
      if (errors.length) {
        $("#formHint").html(
          '<span class="text-danger">' +
            faDigits(errors.length) +
            " خطا: " +
            errors.slice(0, 3).join(" | ") +
            "</span>",
        );
        toast(errors[0], "danger");
        return;
      }

      var $btn = $(this).find('button[type="submit"]').prop("disabled", true);

      $.post(API, collect())
        .done(function (res) {
          if (res.ok) {
            toast(res.message, "success");
            $("#formHint").html(
              '<span class="text-success"><i class="bi bi-check2"></i> با موفقیت ثبت شد. ' +
                '<a href="dashboard.php">مشاهده داشبورد</a></span>',
            );
            $("#bulkRow .bulk-input").val("");
          } else {
            toast(res.error || "خطا در ثبت", "danger");
          }
        })
        .fail(function (xhr) {
          var message =
            (xhr.responseJSON && xhr.responseJSON.error) ||
            "ثبت انجام نشد؛ ارتباط با سرور را بررسی کنید.";
          toast(message, "danger");
        })
        .always(function () {
          $btn.prop("disabled", false);
        });
    });
  });
})(jQuery);
