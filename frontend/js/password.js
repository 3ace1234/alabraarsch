/* ============================================================
   AL-FALAAH PORTAL — PASSWORD RECOVERY
   Handles the forgot-password request and the reset form.
   ============================================================ */
(function () {
  "use strict";

  var API = window.AlFalaahApi;
  if (!API) return;

  function setMessage(form, message, tone) {
    var box = form ? form.querySelector(".form-msg") : null;
    if (!box) return;
    box.textContent = message || "";
    box.classList.remove("form-msg--err", "form-msg--ok");
    if (message) {
      box.classList.add("is-visible");
      box.classList.add(tone === "ok" ? "form-msg--ok" : "form-msg--err");
    } else {
      box.classList.remove("is-visible");
    }
  }

  function setBusy(form, busy, label) {
    var button = form ? form.querySelector('button[type="submit"]') : null;
    if (!button) return;
    if (!button.getAttribute("data-label")) {
      button.setAttribute("data-label", button.innerHTML);
    }
    button.disabled = busy;
    form.setAttribute("aria-busy", busy ? "true" : "false");
    if (busy) {
      button.innerHTML = '<i data-lucide="loader-circle"></i> ' + (label || "Working\u2026");
    } else {
      button.innerHTML = button.getAttribute("data-label");
    }
    if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
  }

  function fieldValue(form, name) {
    var field = form.querySelector('[name="' + name + '"]');
    return field ? String(field.value || "").trim() : "";
  }

  function bindForgot() {
    var form = document.querySelector("[data-forgot-form]");
    if (!form || form.getAttribute("data-bound") === "true") return;
    form.setAttribute("data-bound", "true");

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      setMessage(form, "");

      var email = fieldValue(form, "email");
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        setMessage(form, "Enter the email address used for your portal account.");
        return;
      }

      setBusy(form, true, "Sending\u2026");
      try {
        var data = await API.forgotPassword(email);
        form.reset();
        setMessage(
          form,
          data.message || "If that email belongs to an active account, a reset link is on its way.",
          "ok"
        );
      } catch (error) {
        setMessage(form, API.message(error));
      }
      setBusy(form, false);
    });
  }

  function bindReset() {
    var form = document.querySelector("[data-reset-form]");
    if (!form || form.getAttribute("data-bound") === "true") return;
    form.setAttribute("data-bound", "true");

    var status = document.querySelector("[data-reset-status]");
    var done = document.querySelector("[data-reset-done]");
    var params = new URLSearchParams(window.location.search);
    var token = params.get("token") || "";

    // Keep the one-time token out of the address bar and the Referer header.
    if (token && window.history && window.history.replaceState) {
      window.history.replaceState(null, "", window.location.pathname);
    }

    if (!token) {
      if (status) {
        status.hidden = false;
        status.innerHTML = '<i data-lucide="alert-triangle"></i><span>This reset link is incomplete. Request a new link from the sign-in page.</span>';
      }
      form.hidden = true;
      if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
      return;
    }

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      setMessage(form, "");

      var password = fieldValue(form, "password");
      var confirmation = fieldValue(form, "password_confirmation");

      if (password !== confirmation) {
        setMessage(form, "The passwords do not match.");
        return;
      }
      if (password.length < 10) {
        setMessage(form, "Use at least 10 characters with an uppercase letter, lowercase letter, number and symbol.");
        return;
      }

      setBusy(form, true, "Saving\u2026");
      try {
        await API.resetPassword(token, password, confirmation);
        form.hidden = true;
        if (status) status.hidden = true;
        if (done) {
          done.hidden = false;
          if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        }
      } catch (error) {
        setMessage(form, API.message(error));
        setBusy(form, false);
      }
    });
  }

  function init() {
    bindForgot();
    bindReset();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
