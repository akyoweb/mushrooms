/*!
 * پنل ادمین: تعیین دماهای مطلوب هر سالن
 * jQuery
 */
(function ($) {
  "use strict";

  var FIELDS = window.ADMIN_PAGE ? window.ADMIN_PAGE.fields : {};
  var currentField = Object.keys(FIELDS)[0];

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
    new bootstrap.Toast($el[0], { delay: 3200 }).show();
    $el.on("hidden.bs.toast", function () {
      $el.remove();
    });
  }

  function $panel(field) {
    return $('.range-panel[data-panel="' + (field || currentField) + '"]');
  }

  function $rows(field) {
    return $panel(field).find("tbody tr");
  }

  /** وضعیت هر ردیف را بررسی و نمایش می‌دهد */
  function refreshRow($row) {
    var $inputs = $row.find(".range-input");
    var low = parseFloat($inputs.eq(0).val());
    var high = parseFloat($inputs.eq(1).val());
    var $status = $row.find(".text-status");

    $inputs.removeClass("is-invalid");
    $row.removeClass("row-custom");

    if (isNaN(low) || isNaN(high)) {
      $inputs.addClass("is-invalid");
      $status.html('<span class="badge text-bg-secondary">ناقص</span>');
      return false;
    }
    if (low >= high) {
      $inputs.addClass("is-invalid");
      $status.html('<span class="badge text-bg-danger">حداقل ≥ حداکثر</span>');
      return false;
    }
    $status.html(
      '<span class="badge text-bg-success">' +
        faDigits(low) +
        " – " +
        faDigits(high) +
        "</span>",
    );
    return true;
  }

  function refreshAll(field) {
    var valid = true;
    $rows(field).each(function () {
      if (!refreshRow($(this))) valid = false;
    });
    var $status = $("#formStatus");
    if (!valid) {
      $status.html(
        '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> چند ردیف نامعتبر است</span>',
      );
    } else {
      $status.html(
        '<span class="text-muted">آماده ذخیره • ' +
          faDigits($rows(field).length) +
          " سالن</span>",
      );
    }
    return valid;
  }

  $(function () {
    if (!Object.keys(FIELDS).length) {
      return;
    }

    // ---------- تب فیلدها ----------
    $("#fieldTabs").on("click", "button", function () {
      $(this).addClass("active").siblings().removeClass("active");
      currentField = $(this).data("field");
      $(".range-panel").attr("hidden", true);
      $panel(currentField).removeAttr("hidden");
      $("#bulkMin, #bulkMax").val("");
      refreshAll(currentField);
    });

    // ---------- تغییر ورودی‌ها ----------
    $(document).on("input change", ".range-input", function () {
      refreshRow($(this).closest("tr"));
      refreshAll(currentField);
    });

    // ---------- اعمال یکسان روی کل فیلد ----------
    $("#btnBulkApply").on("click", function () {
      var low = $("#bulkMin").val();
      var high = $("#bulkMax").val();

      if (low === "" || high === "") {
        toast("حداقل و حداکثر را وارد کنید.", "warning");
        return;
      }
      if (Number(low) >= Number(high)) {
        toast("حداقل باید کمتر از حداکثر باشد.", "danger");
        return;
      }

      $rows(currentField).each(function () {
        $(this).find(".range-input").eq(0).val(low);
        $(this).find(".range-input").eq(1).val(high);
      });
      refreshAll(currentField);
      toast(
        "روی همه " +
          faDigits($rows(currentField).length) +
          " سالن اعمال شد؛ برای ثبت «ذخیره تنظیمات» را بزنید.",
        "primary",
      );
    });

    // ---------- کپی بازه یک ردیف روی همه سالن‌ها ----------
    $(document).on("click", ".copy-row", function () {
      var $row = $(this).closest("tr");
      var low = $row.find(".range-input").eq(0).val();
      var high = $row.find(".range-input").eq(1).val();
      var saloon = $row.data("saloon");

      if (low === "" || high === "" || Number(low) >= Number(high)) {
        toast("ابتدا بازه معتبر برای این سالن وارد کنید.", "warning");
        return;
      }

      $rows(currentField).each(function () {
        $(this).find(".range-input").eq(0).val(low);
        $(this).find(".range-input").eq(1).val(high);
      });
      refreshAll(currentField);
      toast(
        "بازه سالن " + faDigits(saloon) + " روی همه سالن‌ها کپی شد.",
        "primary",
      );
    });

    // ---------- بازگشت یک ردیف به پیش‌فرض ----------
    $(document).on("click", ".reset-row", function () {
      var $row = $(this).closest("tr");
      var defaults = FIELDS[currentField].good;
      $row.find(".range-input").eq(0).val(defaults[0]);
      $row.find(".range-input").eq(1).val(defaults[1]);
      refreshRow($row);
      refreshAll(currentField);
    });

    // ---------- پرکردن همه با پیش‌فرض‌ها (داخل فرم) ----------
    $("#btnFillDefaults").on("click", function () {
      Object.keys(FIELDS).forEach(function (field) {
        var defaults = FIELDS[field].good;
        $rows(field).each(function () {
          $(this).find(".range-input").eq(0).val(defaults[0]);
          $(this).find(".range-input").eq(1).val(defaults[1]);
          if (field === currentField) refreshRow($(this));
        });
      });
      refreshAll(currentField);
      toast(
        "فرم با مقادیر پیش‌فرض پر شد؛ برای ذخیره «ذخیره تنظیمات» را بزنید.",
        "primary",
      );
    });

    // ---------- اعتبارسنجی قبل از ارسال ----------
    $("#rangesForm").on("submit", function (e) {
      var invalid = [];
      Object.keys(FIELDS).forEach(function (field) {
        $rows(field).each(function () {
          if (!refreshRow($(this))) {
            invalid.push($(this).data("saloon"));
          }
        });
      });

      if (invalid.length) {
        e.preventDefault();
        toast(
          "ردیف‌های نامعتبر سالن " +
            faDigits(invalid.slice(0, 5).join("، ")) +
            " را اصلاح کنید.",
          "danger",
        );
        return;
      }

      $(this)
        .find('button[type="submit"]')
        .prop("disabled", true)
        .html(
          '<span class="spinner-border spinner-border-sm"></span> در حال ذخیره...',
        );
    });

    refreshAll(currentField);
  });
})(jQuery);
