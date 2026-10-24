/* ============================================================
   AL-FALAAH — frontend interactions (vanilla JS)
   ============================================================ */
(function () {
  "use strict";

  /* ---------- Lucide icons ---------- */
  window.addEventListener("load", function () {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
  });

  document.addEventListener("DOMContentLoaded", function () {
    initHeader();
    initNav();
    initLoginDropdown();
    initReveal();
    initCounters();
    initTabs();
    initHashTab();
    initAccordion();
    initSlider();
    initForms();
    initNewsletter();
    initBackToTop();
    initActiveLink();
    initHeroSlider();
    initPillarHover();
  });

  /* ---------- Pillar top-line reveal ---------- */
  function initPillarHover() {
    document.querySelectorAll(".pillar").forEach(function (card) {
      card.addEventListener("mouseenter", function () { card.classList.add("is-hover"); });
      card.addEventListener("mouseleave", function () { card.classList.remove("is-hover"); });
    });
  }

  /* ---------- Hero background slider ---------- */
  function initHeroSlider() {
    var slides = document.querySelectorAll(".hero__slide");
    if (slides.length < 2) return;
    var current = 0;
    setInterval(function () {
      slides[current].classList.remove("is-active");
      current = (current + 1) % slides.length;
      slides[current].classList.add("is-active");
    }, 5000);
  }

  /* ---------- Header scroll state ---------- */
  function initHeader() {
    var header = document.getElementById("siteHeader");
    if (!header) return;
    var onScroll = function () {
      header.classList.toggle("is-scrolled", window.scrollY > 10);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* ---------- Mobile nav ---------- */
  function initNav() {
    var toggle = document.getElementById("navToggle");
    var nav = document.getElementById("nav");
    if (!toggle || !nav) return;
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    nav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        nav.classList.remove("is-open");
        toggle.classList.remove("is-open");
      });
    });
  }

  /* ---------- Portal login dropdown ---------- */
  function initLoginDropdown() {
    var wraps = document.querySelectorAll("[data-dropdown]");
    if (!wraps.length) return;
    function close(wrap) {
      wrap.classList.remove("is-open");
      var t = wrap.querySelector("[data-dropdown-toggle]");
      if (t) t.setAttribute("aria-expanded", "false");
    }
    wraps.forEach(function (wrap) {
      var toggle = wrap.querySelector("[data-dropdown-toggle]");
      if (!toggle) return;
      toggle.addEventListener("click", function (e) {
        e.stopPropagation();
        var open = wrap.classList.toggle("is-open");
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
      });
      wrap.querySelectorAll("a").forEach(function (link) { link.addEventListener("click", function () { close(wrap); }); });
    });
    document.addEventListener("click", function (e) {
      wraps.forEach(function (wrap) {
        if (wrap.classList.contains("is-open") && !wrap.contains(e.target)) close(wrap);
      });
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") wraps.forEach(function (wrap) { if (wrap.classList.contains("is-open")) close(wrap); });
    });
  }

  /* ---------- Activate a tab from URL hash (e.g. portal.html#panelParent) ---------- */
  function initHashTab() {
    var hash = window.location.hash;
    if (!hash) return;
    var target = document.getElementById(hash.slice(1));
    if (!target) return;
    var wrap = target.closest("[data-tabs]");
    if (!wrap) return;
    var btnSel = wrap.getAttribute("data-tabs") || ".tab-btn";
    wrap.querySelectorAll(btnSel).forEach(function (b) {
      if (b.getAttribute("data-target") === hash.slice(1)) b.click();
    });
  }

  /* ---------- Scroll reveal ---------- */
  function initReveal() {
    var items = document.querySelectorAll(".reveal");
    if (!items.length) return;
    if (!("IntersectionObserver" in window)) {
      items.forEach(function (el) { el.classList.add("is-in"); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var el = entry.target;
          var delay = el.getAttribute("data-delay");
          if (delay) el.style.transitionDelay = delay + "ms";
          el.classList.add("is-in");
          io.unobserve(el);
        }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
    items.forEach(function (el) { io.observe(el); });
  }

  /* ---------- Animated counters ---------- */
  function initCounters() {
    var nums = document.querySelectorAll("[data-count]");
    if (!nums.length) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        io.unobserve(el);
        var target = parseInt(el.getAttribute("data-count"), 10);
        var duration = 1800;
        var start = null;
        function step(ts) {
          if (!start) start = ts;
          var p = Math.min((ts - start) / duration, 1);
          var eased = 1 - Math.pow(1 - p, 3);
          el.textContent = Math.round(target * eased);
          if (p < 1) requestAnimationFrame(step);
          else el.textContent = target;
        }
        requestAnimationFrame(step);
      });
    }, { threshold: 0.5 });
    nums.forEach(function (el) { io.observe(el); });
  }

  /* ---------- Tabs (classes, portal roles) ---------- */
  function initTabs() {
    document.querySelectorAll("[data-tabs]").forEach(function (wrap) {
      var btnSel = wrap.getAttribute("data-tabs") || ".tab-btn";
      var panelSel = wrap.getAttribute("data-panels") || ".tab-panel";
      var btns = wrap.querySelectorAll(btnSel);
      var panels = wrap.querySelectorAll(panelSel);
      btns.forEach(function (btn) {
        btn.addEventListener("click", function () {
          btns.forEach(function (b) { b.classList.remove("is-active"); });
          panels.forEach(function (p) { p.classList.remove("is-active"); });
          btn.classList.add("is-active");
          var target = document.getElementById(btn.getAttribute("data-target"));
          if (target) target.classList.add("is-active");
        });
      });
    });
  }

  /* ---------- FAQ accordion ---------- */
  function initAccordion() {
    document.querySelectorAll(".accordion").forEach(function (wrap) {
      var items = wrap.querySelectorAll(".accordion__item");
      var bodies = wrap.querySelectorAll(".accordion__body");
      items.forEach(function (item, i) {
        var head = item.querySelector(".accordion__head");
        if (!head) return;
        head.addEventListener("click", function () {
          var isOpen = item.classList.contains("is-open");
          items.forEach(function (it, j) {
            it.classList.remove("is-open");
            it.querySelector(".accordion__body").style.maxHeight = null;
          });
          if (!isOpen) {
            item.classList.add("is-open");
            var body = bodies[i];
            body.style.maxHeight = body.scrollHeight + "px";
          }
        });
      });
    });
  }

  /* ---------- Testimonials slider ---------- */
  function initSlider() {
    var slider = document.querySelector("[data-slider]");
    if (!slider) return;
    var track = slider.querySelector(".slider__track");
    var slides = slider.querySelectorAll(".slide");
    var dotsWrap = slider.querySelector(".slider__dots");
    var prevBtn = slider.querySelector("[data-prev]");
    var nextBtn = slider.querySelector("[data-next]");
    var index = 0;

    function render() {
      track.style.transform = "translateX(-" + index * 100 + "%)";
      if (dotsWrap) {
        dotsWrap.querySelectorAll(".slider__dot").forEach(function (d, i) {
          d.classList.toggle("is-active", i === index);
        });
      }
    }
    function go(i) {
      index = (i + slides.length) % slides.length;
      render();
    }

    if (dotsWrap) {
      slides.forEach(function (_, i) {
        var dot = document.createElement("button");
        dot.type = "button";
        dot.className = "slider__dot" + (i === 0 ? " is-active" : "");
        dot.setAttribute("aria-label", "Go to slide " + (i + 1));
        dot.addEventListener("click", function () { go(i); });
        dotsWrap.appendChild(dot);
      });
    }
    prevBtn && prevBtn.addEventListener("click", function () { go(index - 1); });
    nextBtn && nextBtn.addEventListener("click", function () { go(index + 1); });

    var timer = setInterval(function () { go(index + 1); }, 6500);
    slider.addEventListener("mouseenter", function () { clearInterval(timer); });
    slider.addEventListener("mouseleave", function () {
      timer = setInterval(function () { go(index + 1); }, 6500);
    });
    render();
  }

  /* ---------- Forms (frontend demo with validation) ---------- */
  function initForms() {
    document.querySelectorAll("[data-form]").forEach(function (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var ok = true;
        form.querySelectorAll("[required]").forEach(function (input) {
          var valid = input.value.trim() !== "";
          if (input.type === "email") valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim());
          if (input.type === "tel") valid = input.value.trim().length >= 7;
          input.classList.toggle("is-invalid", !valid);
          if (!valid) ok = false;
        });
        var msg = form.querySelector(".form-msg");
        if (!msg) return;
        if (ok) {
          msg.classList.remove("form-msg--err");
          msg.classList.add("form-msg--ok", "is-visible");
          msg.textContent = "Thank you! This is a frontend demo — your application has been captured. The full system (submission, portals and records) arrives with the backend update. Assalamu Alaikum. Shukran!";
          form.reset();
          msg.scrollIntoView({ behavior: "smooth", block: "nearest" });
        } else {
          msg.classList.remove("form-msg--ok");
          msg.classList.add("form-msg--err", "is-visible");
          msg.textContent = "Please complete the required fields highlighted in red.";
        }
        window.scrollTo && msg.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
    });
  }

  /* ---------- Footer newsletter ---------- */
  function initNewsletter() {
    document.querySelectorAll("[data-newsletter]").forEach(function (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        form.querySelector(".newsletter-msg").textContent = "Alhamdulillah! You're subscribed to Al-Falaah updates.";
        form.reset();
      });
    });
  }

  /* ---------- Back to top ---------- */
  function initBackToTop() {
    var bt = document.getElementById("toTop");
    if (!bt) return;
    window.addEventListener("scroll", function () {
      bt.classList.toggle("is-visible", window.scrollY > 600);
    }, { passive: true });
    bt.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  /* ---------- Highlight current page in nav ---------- */
  function initActiveLink() {
    var page = window.location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll(".nav__link").forEach(function (a) {
      var href = a.getAttribute("href");
      if (href === page || (page === "" && href === "index.html")) {
        a.classList.add("is-active");
      }
    });
  }
})();