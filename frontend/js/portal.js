/* ============================================================
   AL-FALAAH PORTAL — LOGIN
   Wires the role-based login panels to the JSON API.
   ============================================================ */
(function () {
  "use strict";

  var API = window.AlFalaahApi;
  if (!API) return;

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

  function setBusy(form, busy) {
    var button = form.querySelector('button[type="submit"]');
    if (!button) return;
    if (!button.getAttribute("data-label")) {
      button.setAttribute("data-label", button.innerHTML);
    }
    button.disabled = busy;
    form.setAttribute("aria-busy", busy ? "true" : "false");
    if (busy) {
      button.innerHTML = '<i data-lucide="loader-circle"></i> Signing in\u2026';
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
      await API.login(email, password);
      setMessage(form, "Signed in. Opening your dashboard\u2026", "ok");
      window.location.replace("dashboard.html#overview");
    } catch (error) {
      setMessage(form, API.message(error));
      setBusy(form, false);
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
