/* ============================================================
   AL-FALAAH PORTAL LOGIN
   Wires the role-based login panels to the JSON API, including
   the optional email-code step for staff and parent accounts.
   ============================================================ */
(function () {
  "use strict";

  var API = window.AlFalaahApi;
  if (!API) return;

  var twoFactor = {
    active: false,
    email: "",
    originForm: null,
    originPanel: null,
    resendAt: 0,
    expiresAt: 0,
    timer: null
  };

  function setMessage(form, message, tone) {
    var box = form.querySelector(".form-msg");
    if (!box) return;
    box.textContent = message || "";
    box.classList.remove("form-msg--err", "form-msg--ok");
    if (message) {
      box.classList.add("is-visible");
      if (tone === "ok") box.classList.add("form-msg--ok");
      else box.classList.add("form-msg--err");
    } else {
      box.classList.remove("is-visible");
    }
  }

  function setBusy(form, busy, label) {
    var button = form.querySelector('button[type="submit"]');
    if (!button) return;
    if (!button.getAttribute("data-label")) {
      button.setAttribute("data-label", button.innerHTML);
    }
    button.disabled = busy;
    form.setAttribute("aria-busy", busy ? "true" : "false");
    if (busy) {
      button.innerHTML = '<i data-lucide="loader-circle"></i> ' + (label || "Signing in\u2026");
    } else {
      button.innerHTML = button.getAttribute("data-label");
    }
    if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
  }

  function fieldValue(form, name) {
    var field = form.querySelector('[name="' + name + '"]');
    return field ? String(field.value || "").trim() : "";
  }

  async function submitLogin(form, event) {
    event.preventDefault();
    setMessage(form, "");

    var email = fieldValue(form, "email");
    var password = fieldValue(form, "password");

    if (!email || !password) {
      setMessage(form, "Enter your email address and password to continue.");
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setMessage(form, "Enter a valid email address.");
      return;
    }

    setBusy(form, true);
    try {
      var result = await API.login(email, password);
      if (result && result.two_factor_required) {
        showTwoFactor(result, form);
        return;
      }
      setMessage(form, "Signed in. Opening your dashboard\u2026", "ok");
      window.location.replace("dashboard.html#overview");
    } catch (error) {
      setMessage(form, API.message(error));
      setBusy(form, false);
    }
  }

  async function submitTwoFactor(form, event) {
    event.preventDefault();
    setMessage(form, "");

    var code = fieldValue(form, "code").replace(/\s+/g, "");
    if (!/^\d{6}$/.test(code)) {
      setMessage(form, "Enter the six-digit code from your email.");
      return;
    }

    setBusy(form, true, "Verifying\u2026");
    try {
      await API.verifyTwoFactor(code);
      setMessage(form, "Code accepted. Opening your dashboard\u2026", "ok");
      window.location.replace("dashboard.html#overview");
    } catch (error) {
      setMessage(form, API.message(error));
      setBusy(form, false);
      if (error && (error.code === "two_factor_expired" || error.status === 429)) {
        resetTwoFactor();
      }
    }
  }

  async function resendCode() {
    var form = document.querySelector("[data-two-factor-form]");
    var button = form ? form.querySelector("[data-2fa-resend]") : null;
    if (!form || (button && button.disabled)) return;

    if (button) button.disabled = true;
    try {
      var data = await API.resendTwoFactor();
      setMessage(form, "A new code was sent to " + (data.email || twoFactor.email) + ".", "ok");
      twoFactor.resendAt = Date.now() + Number(data.resend_in || 30) * 1000;
      twoFactor.expiresAt = Date.now() + Number(data.expires_in || 0) * 1000;
      if (data.delivery === "unavailable") {
        setMessage(form, "We could not send the email. Contact the school office for help.", "err");
      }
    } catch (error) {
      setMessage(form, API.message(error));
    }
    updateTwoFactorTimer();
  }

  async function cancelTwoFactor() {
    try { await API.cancelTwoFactor(); } catch (error) { /* clear locally regardless */ }
    resetTwoFactor();
  }

  function showTwoFactor(data, form) {
    var panel = document.getElementById("panelTwoFactor");
    var codeForm = document.querySelector("[data-two-factor-form]");
    if (!panel || !codeForm) {
      setMessage(form, "Verification is unavailable. Please refresh and try again.");
      setBusy(form, false);
      return;
    }

    twoFactor.active = true;
    twoFactor.email = data.email || "";
    twoFactor.originForm = form;
    twoFactor.originPanel = form.closest(".login-panel");
    twoFactor.resendAt = Date.now() + Number(data.resend_in || 30) * 1000;
    twoFactor.expiresAt = Date.now() + Number(data.expires_in || 0) * 1000;

    var emailEl = panel.querySelector("[data-2fa-email]");
    if (emailEl) emailEl.textContent = twoFactor.email || "your address";

    var tabs = document.querySelector(".login-tabs");
    if (tabs) tabs.style.display = "none";
    document.querySelectorAll(".login-panel").forEach(function (item) {
      item.classList.remove("is-active");
    });
    panel.classList.add("is-active");

    codeForm.reset();
    setMessage(codeForm, "");
    setBusy(form, false);
    setBusy(codeForm, false);
    startTwoFactorTimer();

    if (data.delivery === "unavailable") {
      setMessage(codeForm, "We could not send the email. Contact the school office for help.", "err");
    }

    var input = codeForm.querySelector('[name="code"]');
    if (input) input.focus();
    if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
  }

  function resetTwoFactor() {
    clearInterval(twoFactor.timer);
    twoFactor.timer = null;
    twoFactor.active = false;

    var panel = document.getElementById("panelTwoFactor");
    if (panel) panel.classList.remove("is-active");

    var tabs = document.querySelector(".login-tabs");
    if (tabs) tabs.style.display = "";

    var origin = twoFactor.originPanel || document.querySelector(".login-panel");
    if (origin) origin.classList.add("is-active");

    var codeForm = document.querySelector("[data-two-factor-form]");
    if (codeForm) {
      codeForm.reset();
      setMessage(codeForm, "");
      setBusy(codeForm, false);
    }

    twoFactor.originForm = null;
    twoFactor.originPanel = null;
    twoFactor.email = "";
    twoFactor.resendAt = 0;
    twoFactor.expiresAt = 0;
  }

  function startTwoFactorTimer() {
    clearInterval(twoFactor.timer);
    updateTwoFactorTimer();
    twoFactor.timer = setInterval(updateTwoFactorTimer, 1000);
  }

  function updateTwoFactorTimer() {
    if (!twoFactor.active) return;

    var form = document.querySelector("[data-two-factor-form]");
    var timer = form ? form.querySelector("[data-2fa-timer]") : null;
    var resend = form ? form.querySelector("[data-2fa-resend]") : null;
    var now = Date.now();

    var expires = Math.max(0, Math.ceil((twoFactor.expiresAt - now) / 1000));
    var wait = Math.max(0, Math.ceil((twoFactor.resendAt - now) / 1000));

    if (resend) resend.disabled = wait > 0;

    if (timer) {
      if (expires <= 0) {
        timer.textContent = "That code has expired. Request a new code or sign in again.";
      } else {
        var minutes = Math.floor(expires / 60);
        var seconds = String(expires % 60).padStart(2, "0");
        timer.textContent = "Code expires in " + minutes + ":" + seconds +
          (wait > 0 ? " \u00B7 Resend available in " + wait + "s" : " \u00B7 You can request a new code.");
      }
    }
  }

  function showSignedIn(user) {
    var card = document.querySelector(".login-card");
    if (!card) return;
    var role = user.role === "admin" ? "administrator" : user.role;
    card.innerHTML =
      '<div class="login-card__head">' +
        "<h2>You are already signed in</h2>" +
        "<p>" + escapeHtml(user.display_name || user.email) + " \u00B7 " + escapeHtml(role) + "</p>" +
      "</div>" +
      '<div class="login-card__body">' +
        '<div class="demo-notice"><i data-lucide="shield-check"></i>' +
          "<span>Your secure session is active. Continue to your dashboard, or sign out first to use another account.</span>" +
        "</div>" +
        '<a class="btn btn--primary mt-3" href="dashboard.html#overview" style="width:100%;">' +
          '<i data-lucide="layout-dashboard"></i> Continue to dashboard</a>' +
        '<button class="btn btn--ghost mt-2" type="button" data-session-logout style="width:100%;">' +
          '<i data-lucide="log-out"></i> Sign out</button>' +
      "</div>";
    var logout = card.querySelector("[data-session-logout]");
    if (logout) {
      logout.addEventListener("click", async function () {
        logout.disabled = true;
        try { await API.logout(); } catch (e) { /* clear locally regardless */ }
        window.location.reload();
      });
    }
    if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function bindForms() {
    document.querySelectorAll("[data-login-form]").forEach(function (form) {
      if (form.getAttribute("data-bound") === "true") return;
      form.setAttribute("data-bound", "true");
      form.addEventListener("submit", function (event) {
        submitLogin(form, event);
      });
    });

    var codeForm = document.querySelector("[data-two-factor-form]");
    if (codeForm && codeForm.getAttribute("data-bound") !== "true") {
      codeForm.setAttribute("data-bound", "true");
      codeForm.addEventListener("submit", function (event) {
        submitTwoFactor(codeForm, event);
      });

      var input = codeForm.querySelector('[name="code"]');
      if (input) {
        input.addEventListener("input", function () {
          input.value = input.value.replace(/\D+/g, "").slice(0, 6);
        });
      }

      var resend = codeForm.querySelector("[data-2fa-resend]");
      if (resend) resend.addEventListener("click", resendCode);

      var cancel = codeForm.querySelector("[data-2fa-cancel]");
      if (cancel) cancel.addEventListener("click", cancelTwoFactor);
    }
  }

  async function init() {
    bindForms();

    try {
      var user = await API.bootstrap();
      if (user) {
        showSignedIn(user);
        return;
      }
    } catch (error) {
      var form = document.querySelector("[data-login-form]");
      if (form) {
        setMessage(form, API.message(error));
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
