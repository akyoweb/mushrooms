/*!
 * بخش اختصاصی سالن
 * - جابه‌جایی بین سالن‌ها
 * - اعتبارسنجی بازه‌های دمای مطلوب و مراحل
 * jQuery
 */
(function ($) {
  "use strict";

  var PAGE = window.SALOON_PAGE || { saloon: 1, baseUrl: "" };

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

  /** بررسی یک جفت حداقل/حداکثر */
  function checkPair($min, $max) {
    var low = $min.val();
    var high = $max.val();

    $min.removeClass("is-invalid");
    $max.removeClass("is-invalid");

    // هر دو خالی: استفاده از پیش‌فرض مرحله
    if (low === "" && high === "") return true;
    if (low === "" || high === "") {
      $min.addClass("is-invalid");
      $max.addClass("is-invalid");
      return false;
    }
    if (Number(low) >= Number(high)) {
      $min.addClass("is-invalid");
      $max.addClass("is-invalid");
      return false;
    }
    return true;
  }

  $(function () {
    // ---------- تغییر سالن ----------
    $("#saloonSwitch").on("change", function () {
      window.location.href = PAGE.baseUrl + "saloon.php?id=" + $(this).val();
    });

    // ---------- اعتبارسنجی دمای مطلوب سالن ----------
    $("#rangesForm").on("submit", function (e) {
      var invalid = 0;
      $(this)
        .find("tbody tr")
        .each(function () {
          var $inputs = $(this).find(".range-input");
          if (!checkPair($inputs.eq(0), $inputs.eq(1))) invalid++;
        });
      if (invalid) {
        e.preventDefault();
        toast("بازه‌های نامعتبر را اصلاح کنید (حداقل < حداکثر).", "danger");
      }
    });

    // ---------- اعتبارسنجی مراحل ----------
    $("#stagesForm").on("submit", function (e) {
      var invalid = 0;
      $(this)
        .find("tbody tr")
        .each(function () {
          var $inputs = $(this).find(".range-pair");
          $inputs.each(function () {
            var $pair = $(this).find("input");
            if (!checkPair($pair.eq(0), $pair.eq(1))) invalid++;
          });
        });
      if (invalid) {
        e.preventDefault();
        toast(
          faDigits(invalid) +
            " بازه نامعتبر است؛ حداقل باید کمتر از حداکثر باشد.",
          "danger",
        );
      }
    });

    // ---------- پاک‌کردن خطا هنگام تایپ ----------
    $(document).on("input", ".range-pair input, .range-input", function () {
      $(this).removeClass("is-invalid");
    });
  });
})(jQuery);
