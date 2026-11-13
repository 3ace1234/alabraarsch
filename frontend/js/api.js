/* ============================================================
   AL-FALAAH PORTAL API CLIENT
   Small promise-based wrapper around the JSON API.
   The base URL defaults to ../backend/public/api relative to the
   current page. Override window.ALFALAAH_API_BASE before loading
   this file when the API is hosted elsewhere.
   ============================================================ */
(function (global) {
  "use strict";

  var fallbackBase = "";
  try {
    fallbackBase = new URL("../backend/public/api/", global.location.href).href;
  } catch (e) {
    fallbackBase = "/backend/public/api/";
  }

  var base = String(global.ALFALAAH_API_BASE || fallbackBase).replace(/\/+$/, "");

  function ApiError(message, status, code, fields) {
    this.name = "ApiError";
    this.message = message || "Something went wrong. Please try again.";
    this.status = status || 0;
    this.code = code || "request_failed";
    this.fields = fields || null;
    if (Error.captureStackTrace) Error.captureStackTrace(this, ApiError);
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  var API = {
    base: base,
    csrf: null,
    user: null,
    bootstrapped: false,
    bootstrapping: null
  };

  function capture(payload) {
    var data = payload && payload.data;
    if (!data || typeof data !== "object" || Array.isArray(data)) return;
    if (typeof data.csrf_token === "string") API.csrf = data.csrf_token;
    if (Object.prototype.hasOwnProperty.call(data, "user")) API.user = data.user;
  }

  function errorFromResponse(status, payload) {
    var err = payload && payload.error ? payload.error : {};
    return new ApiError(
      err.message || "The request could not be completed.",
      status,
      err.code || "request_failed",
      err.fields || null
    );
  }

  async function request(path, options) {
    options = options || {};
    var method = String(options.method || "GET").toUpperCase();
    var headers = { Accept: "application/json" };
    var body;

    if (Object.prototype.hasOwnProperty.call(options, "body")) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(options.body);
    }
    if (method !== "GET" && method !== "HEAD" && API.csrf) {
      headers["X-CSRF-Token"] = API.csrf;
    }
    if (options.headers) {
      Object.keys(options.headers).forEach(function (key) {
        headers[key] = options.headers[key];
      });
    }

    var response;
    try {
      response = await global.fetch(API.base + path, {
        method: method,
        headers: headers,
        credentials: "include",
        body: body,
        cache: "no-store"
      });
    } catch (e) {
      throw new ApiError(
        "Unable to reach the school portal. Check your connection and try again.",
        0,
        "network_error"
      );
    }

    var raw = await response.text();
    var payload = null;
    if (raw) {
      try {
        payload = JSON.parse(raw);
      } catch (e) {
        throw new ApiError("The portal returned an unreadable response.", response.status, "invalid_response");
      }
    }

    if (!response.ok) {
      var error = errorFromResponse(response.status, payload);

      if (
        response.status === 403 &&
        error.code === "csrf_failed" &&
        !options._csrfRetry
      ) {
        API.bootstrapped = false;
        await bootstrap(true);
        return request(path, Object.assign({}, options, { _csrfRetry: true }));
      }

      throw error;
    }

    capture(payload);
    return payload;
  }

  async function bootstrap(force) {
    if (API.bootstrapped && !force) return API.user;
    if (API.bootstrapping && !force) return API.bootstrapping;

    API.bootstrapping = request("/session", { method: "GET" })
      .then(function (payload) {
        API.bootstrapped = true;
        API.bootstrapping = null;
        return payload && payload.data ? payload.data.user || null : null;
      })
      .catch(function (error) {
        API.bootstrapping = null;
        throw error;
      });

    return API.bootstrapping;
  }

  API.bootstrap = bootstrap;

  API.get = function (path) {
    return request(path, { method: "GET" });
  };

  API.post = function (path, body) {
    return request(path, { method: "POST", body: body === undefined ? {} : body });
  };

  API.patch = function (path, body) {
    return request(path, { method: "PATCH", body: body === undefined ? {} : body });
  };

  API.del = function (path) {
    return request(path, { method: "DELETE" });
  };

  API.login = async function (email, password) {
    if (!API.bootstrapped) await bootstrap();
    var payload = await request("/auth/login", {
      method: "POST",
      body: { email: email, password: password }
    });
    API.bootstrapped = true;

    var data = payload && payload.data ? payload.data : {};
    if (data.two_factor_required) {
      // The password was accepted but the session is not authenticated yet.
      API.user = null;
    } else {
      API.user = data.user || null;
    }

    return data;
  };

  API.verifyTwoFactor = async function (code) {
    var payload = await request("/auth/2fa/verify", {
      method: "POST",
      body: { code: String(code || "") }
    });
    var data = payload && payload.data ? payload.data : {};
    API.user = data.user || null;
    API.bootstrapped = true;
    return data;
  };

  API.resendTwoFactor = async function () {
    var payload = await request("/auth/2fa/resend", { method: "POST", body: {} });
    return payload && payload.data ? payload.data : {};
  };

  API.cancelTwoFactor = async function () {
    await request("/auth/2fa/cancel", { method: "POST", body: {} });
  };

  API.forgotPassword = async function (email) {
    if (!API.bootstrapped) await bootstrap();
    var payload = await request("/auth/forgot-password", {
      method: "POST",
      body: { email: email }
    });
    return payload && payload.data ? payload.data : {};
  };

  API.resetPassword = async function (token, password, confirmation) {
    if (!API.bootstrapped) await bootstrap();
    var payload = await request("/auth/reset-password", {
      method: "POST",
      body: {
        token: token,
        password: password,
        password_confirmation: confirmation
      }
    });
    return payload && payload.data ? payload.data : {};
  };

  API.twoFactorSettings = async function (enabled, currentPassword) {
    if (!API.bootstrapped) await bootstrap();
    var body = { enabled: !!enabled };
    if (currentPassword) body.current_password = currentPassword;

    var payload = await request("/auth/two-factor", { method: "POST", body: body });
    var data = payload && payload.data ? payload.data : {};
    if (data.user) API.user = data.user;
    return data;
  };

  API.logout = async function () {
    try {
      await request("/auth/logout", { method: "POST", body: {} });
    } finally {
      API.csrf = null;
      API.user = null;
      API.bootstrapped = false;
      API.bootstrapping = null;
    }
  };

  API.message = function (error) {
    if (!error) return "Something went wrong. Please try again.";
    if (error.fields && typeof error.fields === "object") {
      var keys = Object.keys(error.fields);
      if (keys.length) {
        var first = error.fields[keys[0]];
        if (typeof first === "string") return first;
      }
    }
    return error.message || "Something went wrong. Please try again.";
  };

  API.isAuthError = function (error) {
    return !!error && (error.status === 401 || error.code === "unauthenticated");
  };

  global.AlFalaahApi = API;
  global.AlFalaahApiError = ApiError;
})(window);
