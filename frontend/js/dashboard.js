/* ============================================================
   AL-FALAAH — PORTAL DASHBOARD
   Vanilla JS, no build step. Every record rendered here comes
   from the authenticated JSON API; nothing is hard-coded.
   ============================================================ */
(function () {
  "use strict";

  /* ============================================================
     1. HELPERS
     ============================================================ */
  var ESC_MAP = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return ESC_MAP[ch];
    });
  }

  var dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];

  function money(amount) {
    return "\u20A6" + asNumber(amount).toLocaleString("en-NG");
  }

  function ordinal(n) {
    var num = Number(n);
    var rem100 = num % 100;
    if (rem100 >= 11 && rem100 <= 13) return num + "th";
    switch (num % 10) {
      case 1: return num + "st";
      case 2: return num + "nd";
      case 3: return num + "rd";
      default: return num + "th";
    }
  }

  function firstName(name) {
    return String(name).split(" ")[0];
  }

  function splitTime(time) {
    var parts = String(time).split("\u2013").map(function (p) { return p.trim(); });
    return { start: parts[0] || time, end: parts[1] || "" };
  }

  /* ============================================================
     2. RUNTIME DATA
     These structures are hydrated from the JSON API before the
     dashboard renders. They are intentionally empty at load time so
     no fictional record can ever be shown as school data.
     ============================================================ */
  var SCHOOL = {
    session: "",
    term: "",
    week: "",
    label: ""
  };

  var STUDENTS = {};
  var ANNOUNCEMENTS = [];

  var TEACHER = {
    name: "",
    initials: "",
    email: "",
    role: "Teaching staff",
    formClass: "",
    stats: {},
    schedule: [],
    pendingRegisters: [],
    classPerformance: [],
    classes: [],
    resultSheets: []
  };

  var ADMIN = {
    name: "",
    initials: "",
    email: "",
    role: "School administrator",
    stats: {},
    levels: [],
    pipeline: [],
    approvals: [],
    students: [],
    teachers: [],
    admissions: [],
    finance: {},
    classes: [],
    terms: [],
    resultSheets: [],
    announcements: [],
    messages: [],
    subscribers: []
  };

  /* ============================================================
     3. ROLES + NAVIGATION
     ============================================================ */
  var ROLES = {
    student: {
      label: "Student",
      userName: "",
      shortName: "",
      initials: "",
      meta: "",
      email: "",
      studentId: null,
      tabbar: ["overview", "timetable", "results", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "My school" },
        { key: "timetable", label: "Timetable", icon: "calendar-days", group: "My school" },
        { key: "results", label: "Results", icon: "graduation-cap", group: "My school" },
        { key: "attendance", label: "Attendance", icon: "clipboard-check", group: "My school" },
        { key: "fees", label: "Fees & payments", icon: "wallet", group: "Services", tabLabel: "Fees" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "Services" }
      ],
      notifications: []
    },

    parent: {
      label: "Parent",
      userName: "",
      shortName: "",
      initials: "",
      meta: "",
      email: "",
      childIds: [],
      tabbar: ["overview", "results", "attendance", "fees"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "My children" },
        { key: "results", label: "Results", icon: "graduation-cap", group: "My children" },
        { key: "attendance", label: "Attendance", icon: "clipboard-check", group: "My children" },
        { key: "timetable", label: "Timetable", icon: "calendar-days", group: "My children" },
        { key: "fees", label: "Fees & payments", icon: "wallet", group: "Services", tabLabel: "Fees" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "Services" }
      ],
      notifications: []
    },

    teacher: {
      label: "Teacher",
      userName: "",
      shortName: "",
      initials: "",
      meta: "Teaching staff",
      email: "",
      tabbar: ["overview", "attendance", "results", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "Teaching" },
        { key: "classes", label: "My classes", icon: "users", group: "Teaching" },
        { key: "attendance", label: "Attendance", icon: "clipboard-check", group: "Teaching" },
        { key: "results", label: "Results entry", icon: "file-check-2", group: "Teaching" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "School" }
      ],
      notifications: []
    },

    admin: {
      label: "Administrator",
      userName: "",
      shortName: "",
      initials: "",
      meta: "School administrator",
      email: "",
      tabbar: ["overview", "students", "admissions", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "Administration" },
        { key: "students", label: "Students", icon: "users", group: "Administration" },
        { key: "staff", label: "Staff", icon: "id-card", group: "Administration" },
        { key: "academics", label: "Academics", icon: "book-open", group: "Administration" },
        { key: "results", label: "Results", icon: "file-check-2", group: "Administration" },
        { key: "admissions", label: "Admissions", icon: "user-plus", group: "Administration" },
        { key: "finance", label: "Fees & finance", icon: "wallet", group: "Administration" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "School" },
        { key: "messages", label: "Messages", icon: "mail", group: "School" },
        { key: "subscribers", label: "Newsletter", icon: "send", group: "School" }
      ],
      notifications: []
    }
  };

  /* ============================================================
     7. STATE
     ============================================================ */
  var state = {
    role: "",
    section: "overview",
    childId: null,
    annFilter: "All",
    ready: false,
    attendance: null,
    resultSheet: null,
    adminTimetable: null
  };

  var DATA = {
    dashboard: null,
    announcements: [],
    notifications: [],
    timetables: {},
    classes: [],
    terms: [],
    subjects: [],
    adminStudents: [],
    adminTeachers: [],
    adminAdmissions: [],
    adminFinance: null,
    adminResultSheets: [],
    adminAnnouncements: [],
    adminMessages: [],
    adminSubscribers: []
  };

  var API = window.AlFalaahApi;

  /* ============================================================
     7b. API DATA ADAPTERS
     ============================================================ */
  function asNumber(value, fallback) {
    var num = Number(value);
    return Number.isFinite(num) ? num : (fallback === undefined ? 0 : fallback);
  }

  function numberText(value, decimals) {
    var num = Number(value);
    if (!Number.isFinite(num)) return "\u2014";
    return num.toFixed(decimals === undefined ? 1 : decimals);
  }

  function compactMoney(amount) {
    var value = asNumber(amount);
    if (value >= 1000000) return "\u20A6" + (value / 1000000).toFixed(value % 1000000 === 0 ? 0 : 1) + "m";
    if (value >= 1000) return "\u20A6" + Math.round(value / 1000) + "k";
    return money(value);
  }

  function timeLabel(value) {
    if (!value) return "";
    var parts = String(value).split(":");
    var hour = Number(parts[0]);
    var minute = parts[1] || "00";
    if (!Number.isFinite(hour)) return String(value);
    var suffix = hour >= 12 ? "pm" : "am";
    hour = hour % 12 || 12;
    return hour + ":" + minute + suffix;
  }

  function longDate(value) {
    if (!value) return "\u2014";
    var date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
  }

  function academicWeek(term) {
    if (!term || !term.starts_on) return "";
    var start = new Date(term.starts_on + "T00:00:00");
    var now = new Date();
    if (Number.isNaN(start.getTime())) return "";
    var days = Math.floor((now - start) / 86400000);
    if (days < 0) return "Before term";
    return "Week " + (Math.floor(days / 7) + 1);
  }

  function todayISO() {
    var now = new Date();
    var month = String(now.getMonth() + 1).padStart(2, "0");
    var day = String(now.getDate()).padStart(2, "0");
    return now.getFullYear() + "-" + month + "-" + day;
  }

  function setFormMessage(form, message, tone) {
    var box = form.querySelector(".form-msg");
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

  function setButtonBusy(button, busy, label) {
    if (!button) return;
    if (!button.getAttribute("data-label")) {
      button.setAttribute("data-label", button.innerHTML);
    }
    button.disabled = busy;
    if (busy) {
      button.innerHTML = '<i data-lucide="loader-circle"></i> ' + esc(label || "Working\u2026");
      icons();
    } else {
      button.innerHTML = button.getAttribute("data-label");
      icons();
    }
  }

  function statusTone(status) {
    return {
      draft: "info",
      not_started: "info",
      submitted: "warn",
      approved: "ok",
      published: "ok",
      returned: "danger"
    }[status] || "info";
  }

  function statusLabel(status, fallback) {
    if (fallback) return fallback;
    return String(status || "unknown").replace(/_/g, " ").replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  }

  function emptyTimetable() {
    var periods = [1, 2, 3, 4, 5, 6].map(function (period) {
      return { period: period, label: "Period " + period, time: "" };
    });
    var days = {};
    ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"].forEach(function (day) {
      days[day] = periods.map(function () {
        return { subject: "Free", teacher: "", room: "" };
      });
    });
    return { periods: periods, days: days };
  }

  function normalizeTimetable(payload) {
    var timetable = payload && payload.data ? payload.data.timetable : null;
    if (!timetable) return emptyTimetable();

    var periods = Array.isArray(timetable.periods) && timetable.periods.length
      ? timetable.periods.map(function (period) {
          return {
            period: period.period,
            label: period.label || "Period " + period.period,
            time: period.time || ""
          };
        })
      : emptyTimetable().periods;

    var days = {};
    ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"].forEach(function (day) {
      var source = timetable.days && Array.isArray(timetable.days[day]) ? timetable.days[day] : [];
      var byPeriod = {};
      source.forEach(function (slot) { byPeriod[slot.period] = slot; });

      days[day] = periods.map(function (period) {
        var slot = byPeriod[period.period];
        if (!slot) return { subject: "Free", teacher: "", room: "" };
        return {
          subject: slot.subject && slot.subject.name ? slot.subject.name : "Lesson",
          teacher: slot.teacher || "Unassigned",
          room: slot.room || ""
        };
      });
    });

    return { periods: periods, days: days };
  }

  function normalizeResults(results) {
    if (!results) {
      return { term: "No published results", published: null, subjects: [], average: null, position: null, classSize: null };
    }
    return {
      term: results.term || "Current term",
      published: results.published || null,
      subjects: Array.isArray(results.subjects) ? results.subjects : [],
      average: results.average === null || results.average === undefined ? null : asNumber(results.average),
      position: results.position === null || results.position === undefined ? null : asNumber(results.position),
      classSize: results.class_size === null || results.class_size === undefined ? null : asNumber(results.class_size)
    };
  }

  function normalizeAttendance(attendance) {
    var value = attendance || {};
    return {
      rate: asNumber(value.rate),
      days: asNumber(value.days),
      present: asNumber(value.present),
      absent: asNumber(value.absent),
      late: asNumber(value.late),
      excused: asNumber(value.excused),
      weeks: Array.isArray(value.weeks) ? value.weeks : [],
      recent: Array.isArray(value.recent) ? value.recent : []
    };
  }

  function normalizeFees(fees) {
    var value = fees || {};
    return {
      term: value.term || (SCHOOL.term + " " + SCHOOL.session).trim(),
      total: asNumber(value.total),
      paid: asNumber(value.paid),
      balance: asNumber(value.balance),
      dueDate: value.due_date || "",
      invoices: Array.isArray(value.invoices) ? value.invoices : [],
      payments: Array.isArray(value.payments) ? value.payments : []
    };
  }

  function normalizeStudent(student, extras) {
    extras = extras || {};
    var first = student.first_name || "";
    var last = student.last_name || "";
    var classroom = student.class || {};
    return {
      id: student.id,
      name: student.name || (first + " " + last).trim(),
      initials: student.initials || ((first.charAt(0) + last.charAt(0)).toUpperCase() || "?"),
      klass: classroom.name || student.level || "Unassigned",
      level: classroom.level_label || student.level || "",
      admissionNo: student.admission_no || "\u2014",
      house: student.house || "\u2014",
      formTeacher: student.form_teacher || "Not assigned",
      guardian: student.guardian || { name: "Not provided", relation: "", phone: "", email: "" },
      attendance: normalizeAttendance(extras.attendance),
      results: normalizeResults(extras.results),
      timetable: extras.timetable || emptyTimetable(),
      fees: normalizeFees(extras.fees),
      assignments: Array.isArray(extras.assignments) ? extras.assignments : [],
      upNext: Array.isArray(extras.upNext) ? extras.upNext : []
    };
  }

  function normalizeAnnouncements(list) {
    return (Array.isArray(list) ? list : []).map(function (item) {
      return {
        id: item.id,
        day: item.day || "\u2014",
        month: item.month || "",
        date: item.date || "",
        tag: item.tag || "General",
        pinned: !!item.pinned,
        audiences: Array.isArray(item.audiences) ? item.audiences : [],
        title: item.title || "Untitled notice",
        body: item.body || ""
      };
    });
  }

  function normalizeNotifications(payload) {
    var data = payload && payload.data ? payload.data : {};
    var items = Array.isArray(data.items) ? data.items : [];
    var icons = {
      assignment: "book-open",
      announcement: "megaphone",
      result: "graduation-cap",
      fee: "wallet",
      attendance: "clipboard-check",
      admission: "user-plus"
    };
    var labels = {
      assignment: "Assignments",
      announcement: "Announcement",
      result: "Results",
      fee: "Accounts",
      attendance: "Attendance",
      admission: "Admissions"
    };
    return items.map(function (item) {
      return {
        id: item.id,
        icon: icons[item.type] || "bell",
        title: item.title || "Notification",
        meta: (item.age ? item.age + " \u00B7 " : "") + (labels[item.type] || "School"),
        unread: !item.is_read,
        type: item.type || "",
        relatedType: item.related_type || "",
        relatedId: item.related_id || null
      };
    });
  }

  function setNavBadge(role, key, value) {
    var nav = ROLES[role] && ROLES[role].nav ? ROLES[role].nav : [];
    nav.forEach(function (item) {
      if (item.key === key) {
        if (value) item.badge = value;
        else delete item.badge;
      }
    });
  }

  function hydrateStudent(dash, timetablePayload) {
    var student = normalizeStudent(dash.student, {
      attendance: dash.attendance,
      results: dash.results,
      fees: dash.fees,
      assignments: dash.assignments,
      upNext: dash.up_next,
      timetable: normalizeTimetable(timetablePayload)
    });
    STUDENTS[student.id] = student;
    ROLES.student.studentId = student.id;
    ROLES.student.meta = student.klass + " \u00B7 Student";
    return student;
  }

  function hydrateParent(dash, timetableMap) {
    var children = Array.isArray(dash.children) ? dash.children : [];
    var ids = [];

    children.forEach(function (child) {
      var person = child.student || {};
      var student = normalizeStudent(person, {
        attendance: child.attendance,
        results: child.results,
        fees: child.fees,
        assignments: child.assignments,
        upNext: child.up_next,
        timetable: normalizeTimetable(timetableMap[person.id] || null)
      });
      STUDENTS[student.id] = student;
      ids.push(student.id);
    });

    ROLES.parent.childIds = ids;
    ROLES.parent.meta = "Parent of " + ids.length + (ids.length === 1 ? " child" : " children");
    var active = dash.active_child && dash.active_child.id ? dash.active_child.id : ids[0];
    state.childId = ids.indexOf(active) !== -1 ? active : ids[0] || null;
  }

  function hydrateTeacher(dash) {
    var stats = dash.stats || {};
    var classes = Array.isArray(dash.classes) ? dash.classes : [];
    var classById = {};
    classes.forEach(function (item) { classById[item.id] = item; });

    TEACHER = {
      name: (dash.teacher && dash.teacher.name) || dash.user.display_name || "",
      initials: (dash.teacher && dash.teacher.initials) || dash.user.initials || "",
      email: dash.user.email || "",
      role: "Teaching staff",
      formClass: "",
      stats: stats,
      schedule: (Array.isArray(dash.schedule) ? dash.schedule : []).map(function (row) {
        return {
          period: "Period " + row.period,
          time: row.time || (timeLabel(row.starts_at) + " \u2013 " + timeLabel(row.ends_at)),
          subject: row.subject ? row.subject.name : "",
          klass: row.class ? row.class.name : "",
          room: row.room || "",
          startsAt: row.starts_at || ""
        };
      }),
      pendingRegisters: (Array.isArray(dash.pending_registers) ? dash.pending_registers : []).map(function (row) {
        var classroom = classById[row.class_id] || {};
        return {
          classId: row.class_id,
          klass: row.class_name,
          period: "Period " + row.period,
          time: timeLabel(row.starts_at),
          students: classroom.students || 0
        };
      }),
      classPerformance: classes.filter(function (row) { return row.average !== null; }).map(function (row) {
        return { klass: row.name, average: asNumber(row.average) };
      }),
      classes: classes,
      resultSheets: Array.isArray(dash.result_sheets) ? dash.result_sheets : []
    };

    ROLES.teacher.meta = stats.classes + " classes \u00B7 " + stats.students + " students";
    setNavBadge("teacher", "attendance", stats.pending_registers || 0);
    setNavBadge("teacher", "results", stats.sheets_outstanding || 0);
  }

  function normalizeApproval(row) {
    var tones = { submitted: "warn", approved: "ok", published: "ok", draft: "info", returned: "danger" };
    return {
      id: row.id,
      klass: row.class ? row.class.name : "\u2014",
      subject: row.subject ? row.subject.name : "\u2014",
      teacher: row.teacher || "\u2014",
      submitted: row.submitted_at ? longDate(row.submitted_at) : "Not submitted",
      status: row.status_label || row.status || "Draft",
      tone: tones[row.status] || "info"
    };
  }

  function hydrateAdmin(dash, extras) {
    var stats = dash.stats || {};
    var admissions = dash.admissions || {};
    var pipeline = admissions.pipeline || {};
    var labels = {
      new: "New applications",
      assessment: "Assessment booked",
      offer: "Offers issued",
      enrolled: "Enrolled",
      declined: "Declined"
    };

    ADMIN = {
      name: dash.user.display_name || "",
      initials: dash.user.initials || "",
      email: dash.user.email || "",
      role: "School administrator",
      stats: {
        students: asNumber(stats.students),
        teachers: asNumber(stats.teachers),
        staff: asNumber(stats.staff),
        attendance: asNumber(stats.attendance_rate),
        attendanceDate: stats.attendance_date || "",
        collected: asNumber(stats.fees_collected),
        outstanding: asNumber(stats.fees_outstanding),
        collectionRate: asNumber(stats.fees_collection_rate),
        pendingApprovals: asNumber(stats.pending_approvals)
      },
      levels: (Array.isArray(dash.levels) ? dash.levels : []).map(function (level) {
        return { label: level.label, count: asNumber(level.students), classes: asNumber(level.classes) };
      }),
      pipeline: Object.keys(labels).map(function (key) {
        return { label: labels[key], count: asNumber(pipeline[key]) };
      }),
      approvals: (Array.isArray(dash.approvals) ? dash.approvals : []).map(normalizeApproval),
      students: extras.students || [],
      teachers: extras.teachers || [],
      admissions: extras.admissions || [],
      finance: extras.finance || null,
      classes: extras.classes || [],
      terms: extras.terms || [],
      resultSheets: extras.resultSheets || [],
      announcements: extras.announcements || [],
      messages: extras.messages || [],
      subscribers: extras.subscribers || []
    };

    ROLES.admin.meta = "School administrator";
    setNavBadge("admin", "admissions", asNumber(pipeline.new) || (Array.isArray(ADMIN.admissions) ? ADMIN.admissions.filter(function (a) { return a.status === "new"; }).length : 0));
    setNavBadge("admin", "results", ADMIN.stats.pendingApprovals);
    setNavBadge("admin", "messages", ADMIN.messages.filter(function (message) { return message.status === "new"; }).length || "");
    setNavBadge("admin", "finance", "");
  }

  async function loadPortalData() {
    var dashPayload = await API.get("/dashboard");
    var dash = dashPayload && dashPayload.data ? dashPayload.data : null;
    if (!dash || !dash.role) throw new Error("The portal returned an incomplete dashboard.");

    var role = dash.role;
    var requests = [
      { key: "announcements", call: API.get("/announcements") },
      { key: "notifications", call: API.get("/notifications") }
    ];

    if (role === "student" && dash.student) {
      requests.push({ key: "timetable", call: API.get("/students/" + dash.student.id + "/timetable") });
    }

    if (role === "parent") {
      (Array.isArray(dash.children) ? dash.children : []).forEach(function (child) {
        if (child.student && child.student.id) {
          requests.push({
            key: "timetable:" + child.student.id,
            call: API.get("/students/" + child.student.id + "/timetable")
          });
        }
      });
    }

    if (role === "admin") {
      requests.push(
        { key: "adminStudents", call: API.get("/admin/students?per_page=100") },
        { key: "adminTeachers", call: API.get("/admin/teachers") },
        { key: "adminAdmissions", call: API.get("/admin/admissions?per_page=100") },
        { key: "adminFinance", call: API.get("/admin/finance") },
        { key: "adminResultSheets", call: API.get("/admin/result-sheets") },
        { key: "adminAnnouncements", call: API.get("/admin/announcements") },
        { key: "adminMessages", call: API.get("/admin/messages?per_page=100") },
        { key: "adminSubscribers", call: API.get("/admin/subscribers?per_page=100") },
        { key: "classes", call: API.get("/classes") },
        { key: "terms", call: API.get("/terms") },
        { key: "subjects", call: API.get("/subjects") }
      );
    }

    var settled = await Promise.allSettled(requests.map(function (req) { return req.call; }));
    var extras = {};
    var failures = [];

    settled.forEach(function (result, index) {
      var key = requests[index].key;
      if (result.status === "fulfilled") {
        extras[key] = result.value;
      } else {
        failures.push(result.reason);
      }
    });

    var authFailure = failures.filter(function (error) { return API.isAuthError(error); })[0];
    if (authFailure) throw authFailure;

    return {
      dash: dash,
      announcements: extras.announcements && extras.announcements.data
        ? extras.announcements.data
        : (dash.announcements || []),
      notifications: extras.notifications || { data: { items: [] } },
      timetable: extras.timetable || null,
      timetables: extras,
      adminStudents: extras.adminStudents && extras.adminStudents.data ? extras.adminStudents.data : [],
      adminTeachers: extras.adminTeachers && extras.adminTeachers.data ? extras.adminTeachers.data : [],
      adminAdmissions: extras.adminAdmissions && extras.adminAdmissions.data ? extras.adminAdmissions.data : [],
      adminFinance: extras.adminFinance && extras.adminFinance.data ? extras.adminFinance.data : null,
      adminResultSheets: extras.adminResultSheets && extras.adminResultSheets.data ? extras.adminResultSheets.data : [],
      adminAnnouncements: extras.adminAnnouncements && extras.adminAnnouncements.data ? extras.adminAnnouncements.data : [],
      adminMessages: extras.adminMessages && extras.adminMessages.data ? extras.adminMessages.data : [],
      adminSubscribers: extras.adminSubscribers && extras.adminSubscribers.data ? extras.adminSubscribers.data : [],
      classes: extras.classes && extras.classes.data ? extras.classes.data : [],
      terms: extras.terms && extras.terms.data ? extras.terms.data : [],
      subjects: extras.subjects && extras.subjects.data ? extras.subjects.data : [],
      failures: failures
    };
  }

  function hydratePortal(data) {
    var dash = data.dash;
    state.role = dash.role;
    state.ready = true;
    DATA.dashboard = dash;
    DATA.announcements = data.announcements;
    DATA.notifications = data.notifications;
    DATA.timetables = data.timetables;
    DATA.adminStudents = data.adminStudents;
    DATA.adminTeachers = data.adminTeachers;
    DATA.adminAdmissions = data.adminAdmissions;
    DATA.adminFinance = data.adminFinance;
    DATA.adminResultSheets = data.adminResultSheets;
    DATA.adminAnnouncements = data.adminAnnouncements;
    DATA.adminMessages = data.adminMessages;
    DATA.adminSubscribers = data.adminSubscribers;
    DATA.classes = data.classes;
    DATA.terms = data.terms;
    DATA.subjects = data.subjects;

    SCHOOL.session = dash.term && dash.term.session ? dash.term.session.name : "";
    SCHOOL.term = dash.term ? dash.term.name : "";
    SCHOOL.label = dash.term ? dash.term.label : "";
    SCHOOL.week = academicWeek(dash.term);

    var user = dash.user || {};
    var cfg = ROLES[state.role];
    cfg.userName = user.display_name || ((user.first_name || "") + " " + (user.last_name || "")).trim();
    cfg.shortName = user.first_name || cfg.userName;
    cfg.initials = user.initials || "";
    cfg.email = user.email || "";

    ANNOUNCEMENTS = normalizeAnnouncements(data.announcements);
    cfg.notifications = normalizeNotifications(data.notifications);

    if (state.role === "student") {
      var student = hydrateStudent(dash, data.timetable);
      cfg.meta = student.klass + " \u00B7 Student";
    } else if (state.role === "parent") {
      var timetableMap = {};
      Object.keys(data.timetables).forEach(function (key) {
        if (key.indexOf("timetable:") === 0) {
          timetableMap[key.slice("timetable:".length)] = data.timetables[key];
        }
      });
      hydrateParent(dash, timetableMap);
    } else if (state.role === "teacher") {
      hydrateTeacher(dash);
    } else if (state.role === "admin") {
      hydrateAdmin(dash, {
        students: data.adminStudents,
        teachers: data.adminTeachers,
        admissions: data.adminAdmissions,
        finance: data.adminFinance,
        classes: data.classes,
        terms: data.terms,
        resultSheets: data.adminResultSheets,
        subscribers: data.adminSubscribers
      });
    } else {
      throw new Error("This account type cannot use the portal.");
    }

    var key = hashKey();
    state.section = sectionExists(key) ? key : "overview";
    if (state.role === "parent" && !state.childId) {
      state.childId = ROLES.parent.childIds[0] || null;
    }
  }

  function renderLoadError(message, canRetry) {
    var root = document.getElementById("viewRoot");
    if (!root) return;
    root.innerHTML = '<section class="panel"><div class="panel__body">' +
      '<div class="empty-state">' +
        '<span class="empty-state__icon"><i data-lucide="cloud-off"></i></span>' +
        "<h3>We could not load your portal</h3>" +
        "<p>" + esc(message || "Please try again in a moment.") + "</p>" +
        (canRetry ? '<button class="btn btn--primary mt-2" type="button" data-retry-load><i data-lucide="refresh-cw"></i> Try again</button>' : "") +
        '<a class="btn btn--ghost mt-2" href="portal.html" data-logout><i data-lucide="log-in"></i> Return to sign in</a>' +
      "</div></div></section>";
    icons();
  }

  /* ============================================================
     8. SMALL UI BUILDERS
     ============================================================ */
  function pageHead(opts) {
    return '<section class="page-head">' +
      '<div class="page-head__text">' +
        (opts.eyebrow
          ? '<div class="page-head__flags"><span class="eyebrow">' + esc(opts.eyebrow) + "</span></div>"
          : "") +
        '<h1 class="page-title">' + opts.title + "</h1>" +
        (opts.lead ? '<p class="page-lead">' + opts.lead + "</p>" : "") +
      "</div>" +
      (opts.actions ? '<div class="page-head__actions">' + opts.actions + "</div>" : "") +
      "</section>";
  }

  function panel(opts) {
    return '<section class="panel' + (opts.className ? " " + opts.className : "") + '">' +
      '<div class="panel__head"><div>' +
        "<h2>" + esc(opts.title) + "</h2>" +
        (opts.sub ? '<p class="panel__sub">' + esc(opts.sub) + "</p>" : "") +
      "</div>" + (opts.action || "") + "</div>" +
      '<div class="panel__body">' + (opts.body || "") + "</div>" +
      "</section>";
  }

  function stat(o) {
    return '<div class="stat' + (o.alert ? " stat--alert" : "") + '">' +
      '<span class="stat__icon"><i data-lucide="' + o.icon + '"></i></span>' +
      '<div><span class="stat__label">' + esc(o.label) + "</span>" +
      '<span class="stat__value">' + esc(o.value) + "</span>" +
      '<span class="stat__meta">' + esc(o.meta) + "</span></div></div>";
  }

  function statGrid(items) {
    return '<div class="stat-grid">' + items.join("") + "</div>";
  }

  function statusPill(text, tone) {
    return '<span class="status' + (tone ? " status--" + tone : "") + '">' + esc(text) + "</span>";
  }

  function callout(icon, html) {
    return '<div class="callout"><i data-lucide="' + icon + '"></i><p>' + html + "</p></div>";
  }

  function meterRow(label, value, scoreText) {
    var score = scoreText === undefined ? value : scoreText;
    return '<div class="meter-row">' +
      '<span class="meter-row__label">' + esc(label) + "</span>" +
      '<div class="meter"><span class="meter__fill" data-meter="' + Number(value) + '"></span></div>' +
      '<span class="meter-row__score">' + esc(score) + "</span></div>";
  }

  function table(headers, rows, label) {
    var head = headers.map(function (h) {
      return '<th scope="col"' + (h.align ? ' class="ta-' + h.align + '"' : "") + ">" + esc(h.label) + "</th>";
    }).join("");
    var body = rows.map(function (row) {
      var cells = row;
      var attrs = "";
      if (!Array.isArray(row)) {
        cells = row.cells || [];
        attrs = row.attrs || "";
      }
      return "<tr" + attrs + ">" + cells.map(function (cell, i) {
        var h = headers[i] || {};
        return "<td" + (h.align ? ' class="ta-' + h.align + '"' : "") + ">" + cell + "</td>";
      }).join("") + "</tr>";
    }).join("");
    return '<div class="table-wrap" tabindex="0" role="region" aria-label="' + esc(label || "Data table") + '">' +
      '<table class="data-table"><thead><tr>' + head + "</tr></thead><tbody>" + body + "</tbody></table></div>";
  }

  function announcementList(list, full) {
    return '<ul class="announce-list">' + list.map(function (a) {
      return '<li class="announce' + (full ? " announce--full" : "") + '">' +
        '<div class="announce__date"><strong>' + esc(a.day) + "</strong><span>" + esc(a.month) + "</span></div>" +
        '<div class="announce__body">' +
          '<div class="announce__meta"><span class="tag">' + esc(a.tag) + "</span><span aria-hidden=\"true\">\u00B7</span><span>" + esc(a.date) + "</span>" +
          (a.pinned ? statusPill("Pinned", "gold") : "") + "</div>" +
          "<h3>" + esc(a.title) + "</h3>" +
          (full ? "<p>" + esc(a.body) + "</p>" : "") +
        "</div></li>";
    }).join("") + "</ul>";
  }

  function taskList(tasks) {
    return '<ul class="task-list">' + tasks.map(function (t) {
      return "<li>" +
        '<span class="task__icon"><i data-lucide="book-open"></i></span>' +
        '<div class="task__body"><strong>' + esc(t.title) + "</strong><span>" + esc(t.subject) + " \u00B7 " + esc(t.due) + "</span></div>" +
        statusPill(t.status, t.tone) + "</li>";
    }).join("") + "</ul>";
  }

  function weekBars(weeks) {
    return '<div class="bars" role="img" aria-label="Weekly attendance: ' +
      esc(weeks.map(function (w) { return w.label + " " + w.value + " percent"; }).join(", ")) + '">' +
      weeks.map(function (w) {
        return '<div class="bars__col" title="' + esc(w.label + ": " + w.value + "%") + '">' +
          '<div class="bars__track"><span class="bars__fill" data-bar="' + w.value + '"></span></div>' +
          '<span class="bars__label">' + esc(w.label) + "</span></div>";
      }).join("") + "</div>";
  }

  function emptyState(icon, title, text) {
    return '<div class="empty-state"><span class="empty-state__icon"><i data-lucide="' + icon + '"></i></span>' +
      "<h3>" + esc(title) + "</h3><p>" + esc(text) + "</p></div>";
  }

  function childSwitch() {
    if (state.role !== "parent") return "";
    return '<div class="child-switch" role="group" aria-label="Select a child">' +
      ROLES.parent.childIds.map(function (id) {
        var child = STUDENTS[id];
        var active = state.childId === id;
        return '<button class="child-switch__btn' + (active ? " is-active" : "") + '" type="button" data-child="' +
          esc(id) + '" aria-pressed="' + (active ? "true" : "false") + '">' +
          '<span class="avatar">' + esc(child.initials) + "</span>" + esc(firstName(child.name)) + "</button>";
      }).join("") + "</div>";
  }

  function currentStudent() {
    if (state.role === "parent") {
      return STUDENTS[state.childId] || STUDENTS[ROLES.parent.childIds[0]];
    }
    return STUDENTS[ROLES.student.studentId];
  }

  function feeStat(student) {
    if (student.fees.balance > 0) {
      return stat({
        icon: "wallet", label: "Outstanding fees", value: money(student.fees.balance),
        meta: "Due " + student.fees.dueDate, alert: true
      });
    }
    return stat({
      icon: "wallet", label: "Fees", value: "Cleared",
      meta: money(student.fees.total) + " paid for the term"
    });
  }

  /* ============================================================
     9. CHROME / NAVIGATION RENDERING
     ============================================================ */
  function icons() {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
  }

  function sectionExists(key) {
    var cfg = ROLES[state.role];
    if (!cfg) return false;
    return cfg.nav.some(function (item) { return item.key === key; });
  }

  function currentItem() {
    var cfg = ROLES[state.role];
    return cfg.nav.filter(function (item) { return item.key === state.section; })[0] || null;
  }

  function hashKey() {
    return (window.location.hash || "").replace(/^#\/?/, "");
  }

  function sideLink(item) {
    var active = state.section === item.key;
    return '<li><a class="side-link' + (active ? " is-active" : "") + '" href="#' + esc(item.key) + '" data-view="' +
      esc(item.key) + '"' + (active ? ' aria-current="page"' : "") + ">" +
      '<i data-lucide="' + item.icon + '"></i><span>' + esc(item.label) + "</span>" +
      (item.badge ? '<span class="side-link__badge">' + esc(item.badge) + "</span>" : "") +
      "</a></li>";
  }

  function renderSidebar() {
    var cfg = ROLES[state.role];
    var groups = [];
    cfg.nav.forEach(function (item) {
      var group = groups.filter(function (g) { return g.name === item.group; })[0];
      if (!group) {
        group = { name: item.group, items: [] };
        groups.push(group);
      }
      group.items.push(item);
    });

    document.getElementById("portalNav").innerHTML = groups.map(function (group) {
      return '<div class="nav-group"><p class="nav-label">' + esc(group.name) + "</p>" +
        '<ul class="side-list">' + group.items.map(sideLink).join("") + "</ul></div>";
    }).join("");

    document.querySelector("[data-role-chip]").innerHTML =
      '<span class="role-chip"><span class="role-chip__dot" aria-hidden="true"></span>' + esc(cfg.label) + " account</span>";

    document.querySelector("[data-sidebar-user]").innerHTML =
      '<div class="user-card"><span class="avatar avatar--gold">' + esc(cfg.initials || "?") + "</span>" +
      '<div class="user-card__meta"><strong>' + esc(cfg.userName || "Loading\u2026") + "</strong><span>" + esc(cfg.meta) + "</span></div>" +
      '<button class="user-card__out" type="button" data-logout aria-label="Sign out of the portal" title="Sign out"><i data-lucide="log-out"></i></button></div>';
  }

  function renderChrome() {
    var cfg = ROLES[state.role];
    document.querySelector("[data-top-avatar]").textContent = cfg.initials || "?";
    document.querySelector("[data-top-name]").textContent = cfg.shortName || "Portal";
    var termChip = document.querySelector("[data-term-label]");
    if (termChip) {
      termChip.innerHTML = '<i data-lucide="calendar-days"></i> ' + esc(SCHOOL.label || SCHOOL.term || "Current term");
    }
    document.title = cfg.label + " Dashboard \u2014 Al-Falaah School";
  }

  function renderTabbar() {
    var cfg = ROLES[state.role];
    var items = cfg.tabbar.map(function (key) {
      return cfg.nav.filter(function (item) { return item.key === key; })[0];
    }).filter(Boolean);

    document.getElementById("tabbar").innerHTML = items.map(function (item) {
      var active = state.section === item.key;
      var label = item.tabLabel || item.label;
      return '<a class="tabbar__link' + (active ? " is-active" : "") + '" href="#' + esc(item.key) + '" data-view="' +
        esc(item.key) + '"' + (active ? ' aria-current="page"' : "") + ">" +
        '<i data-lucide="' + item.icon + '"></i><span>' + esc(label) + "</span></a>";
    }).join("");
  }

  function unreadCount() {
    var notifications = ROLES[state.role] ? ROLES[state.role].notifications : [];
    return notifications.filter(function (n) { return n.unread; }).length;
  }

  function renderNotifications() {
    var cfg = ROLES[state.role];
    var panelEl = document.querySelector("[data-notif-panel]");
    var bell = document.querySelector("[data-notif-toggle]");
    var dot = document.querySelector("[data-notif-dot]");
    var unread = unreadCount();
    var list = cfg.notifications || [];

    panelEl.innerHTML =
      '<div class="popover__head"><h3>Notifications</h3>' +
        (unread ? '<button class="text-btn" type="button" data-read-all>Mark all read</button>'
                : '<span class="small muted">All caught up</span>') +
      "</div>" +
      '<div class="popover__list">' + (list.length ? list.map(function (n) {
        return '<button class="notif' + (n.unread ? " is-unread" : "") +
          '" type="button" data-notification="' + esc(n.id) + '">' +
          '<span class="notif__icon"><i data-lucide="' + n.icon + '"></i></span>' +
          '<span class="notif__body"><strong>' + esc(n.title) + "</strong><span>" + esc(n.meta) + "</span></span>" +
          (n.unread ? '<span class="notif__dot" aria-hidden="true"></span>' : "") +
          "</button>";
      }).join("") : '<div class="popover__empty">No notifications right now.</div>') + "</div>" +
      '<div class="popover__foot"><a class="link-arrow" href="#announcements" data-view="announcements">View all announcements <i data-lucide="arrow-right"></i></a></div>';

    if (dot) dot.hidden = unread === 0;
    if (bell) bell.setAttribute("aria-label", unread ? "Notifications, " + unread + " unread" : "Notifications");
  }

  function renderProfile() {
    var cfg = ROLES[state.role];
    document.querySelector("[data-profile-panel]").innerHTML =
      '<div class="profile-pop__head"><span class="avatar avatar--gold">' + esc(cfg.initials || "?") + "</span>" +
        '<div class="profile-pop__id"><strong>' + esc(cfg.userName) + "</strong><span>" + esc(cfg.email) + "</span></div></div>" +
      '<div class="popover__list popover__list--menu">' +
        '<div class="menu-link menu-link--static"><i data-lucide="shield-check"></i> ' + esc(cfg.label) + " account</div>" +
        '<div class="menu-sep"></div>' +
        '<a class="menu-link" href="index.html"><i data-lucide="globe"></i> Back to website</a>' +
        '<a class="menu-link menu-link--danger" href="portal.html" data-logout><i data-lucide="log-out"></i> Sign out</a>' +
      "</div>";
  }

  function renderView() {
    var root = document.getElementById("viewRoot");
    var item = currentItem();
    var html;

    if (!item) {
      html = '<section class="panel"><div class="panel__body">' +
        emptyState("compass", "This section does not exist for your role", "Use the navigation to return to your dashboard.") +
        "</div></section>";
    } else {
      switch (item.key) {
        case "overview": html = overviewView(); break;
        case "results":
          html = state.role === "teacher" ? teacherResultsView()
            : state.role === "admin" ? adminResultsView()
            : resultsView();
          break;
        case "attendance":
          html = state.role === "teacher" ? teacherAttendanceView() : attendanceView();
          break;
        case "classes": html = teacherClassesView(); break;
        case "timetable": html = timetableView(); break;
        case "fees": html = feesView(); break;
        case "students": html = adminStudentsView(); break;
        case "staff": html = adminStaffView(); break;
        case "academics": html = adminAcademicsView(); break;
        case "admissions": html = adminAdmissionsView(); break;
        case "finance": html = adminFinanceView(); break;
        case "announcements": html = announcementsView(); break;
        case "messages": html = adminMessagesView(); break;
        case "subscribers": html = adminSubscribersView(); break;
        default: html = overviewView();
      }
    }

    root.innerHTML = html;
    hydrateCharts();
    afterViewRender();
  }

  function afterViewRender() {
    if (state.role === "teacher" && state.section === "attendance") {
      loadAttendanceWorkspace(false);
    }
    if ((state.role === "teacher" || state.role === "admin") && state.section === "results" && state.resultSheet) {
      loadResultSheetWorkspace();
    }
    if (state.role === "admin" && state.section === "academics") {
      loadAdminTimetable(false);
    }
  }

  function hydrateCharts() {
    window.requestAnimationFrame(function () {
      document.querySelectorAll("[data-meter]").forEach(function (el) {
        var value = Math.max(0, Math.min(100, Number(el.getAttribute("data-meter")) || 0));
        el.style.width = value + "%";
      });
      document.querySelectorAll("[data-bar]").forEach(function (el) {
        var value = Math.max(0, Math.min(100, Number(el.getAttribute("data-bar")) || 0));
        el.style.height = value + "%";
      });
      document.querySelectorAll("[data-ring]").forEach(function (el) {
        var value = Math.max(0, Math.min(100, Number(el.getAttribute("data-ring")) || 0));
        el.style.setProperty("--p", value);
      });
    });
  }

  function render() {
    renderSidebar();
    renderChrome();
    renderNotifications();
    renderProfile();
    renderView();
    renderTabbar();
    icons();
  }

  /* ============================================================
     10. NAVIGATION / ROLE SWITCHING
     ============================================================ */
  function focusMain() {
    var main = document.getElementById("mainContent");
    var announce = document.getElementById("viewAnnounce");
    var item = currentItem();
    if (announce) announce.textContent = item ? item.label + " section loaded" : "";
    if (main) main.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function navigate(key) {
    if (!state.ready || !sectionExists(key)) return;
    closeDrawer(false);
    closeAllPopovers();
    if (window.location.hash === "#" + key) {
      state.section = key;
      state.annFilter = "All";
      render();
      focusMain();
    } else {
      window.location.hash = key;
    }
  }

  function onHashChange() {
    if (!state.ready) return;
    var key = hashKey();
    if (!sectionExists(key)) {
      state.section = "overview";
      state.annFilter = "All";
      try { window.history.replaceState(null, "", "#overview"); } catch (e) { /* ignore */ }
      render();
      focusMain();
      return;
    }
    state.section = key;
    state.annFilter = "All";
    render();
    focusMain();
  }

  function setChild(id) {
    if (!STUDENTS[id] || state.role !== "parent") return;
    state.childId = id;
    renderView();
    icons();
    var announce = document.getElementById("viewAnnounce");
    if (announce) announce.textContent = "Now viewing " + STUDENTS[id].name;
    var next = document.querySelector('[data-child="' + id + '"]');
    if (next) next.focus();
  }

  /* ============================================================
     11. POPOVERS / DRAWER / TOASTS
     ============================================================ */
  function closeAllPopovers(except) {
    document.querySelectorAll("[data-notif-panel], [data-profile-panel]").forEach(function (panelEl) {
      if (panelEl === except) return;
      panelEl.hidden = true;
      var wrap = panelEl.closest(".popover-wrap");
      if (wrap) {
        var btn = wrap.querySelector("[data-notif-toggle], [data-profile-toggle]");
        if (btn) btn.setAttribute("aria-expanded", "false");
      }
    });
  }

  function togglePopover(panelEl, btn) {
    var willOpen = panelEl.hidden;
    closeAllPopovers(panelEl);
    panelEl.hidden = !willOpen;
    btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
    if (willOpen) icons();
  }

  function openDrawer() {
    document.body.classList.add("drawer-open");
    var open = document.querySelector("[data-drawer-open]");
    if (open) open.setAttribute("aria-expanded", "true");
    var close = document.querySelector("[data-drawer-close]");
    if (close) close.focus();
  }

  function closeDrawer(returnFocus) {
    if (!document.body.classList.contains("drawer-open")) return;
    document.body.classList.remove("drawer-open");
    var open = document.querySelector("[data-drawer-open]");
    if (open) {
      open.setAttribute("aria-expanded", "false");
      if (returnFocus) open.focus();
    }
  }

  function toast(message) {
    var stack = document.getElementById("toastStack");
    if (!stack) return;
    var el = document.createElement("div");
    el.className = "toast";
    el.innerHTML = '<i data-lucide="info"></i><span>' + esc(message) + "</span>";
    stack.appendChild(el);
    icons();
    window.setTimeout(function () {
      el.classList.add("is-out");
      window.setTimeout(function () { el.remove(); }, 260);
    }, 4200);
  }

  async function markAllRead() {
    var cfg = ROLES[state.role];
    try {
      await API.post("/notifications/read-all", {});
      cfg.notifications.forEach(function (notification) { notification.unread = false; });
      renderNotifications();
      icons();
      toast("All notifications marked as read.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
    }
  }

  async function markNotificationRead(button) {
    var id = button.getAttribute("data-notification");
    var cfg = ROLES[state.role];
    var notification = cfg.notifications.filter(function (item) { return item.id === id; })[0];
    if (!notification) return;

    if (notification.unread) {
      try {
        await API.patch("/notifications/" + encodeURIComponent(id) + "/read", {});
        notification.unread = false;
        renderNotifications();
        icons();
      } catch (error) {
        if (API.isAuthError(error)) return handleAuthFailure();
        toast(API.message(error));
        return;
      }
    }

    closeAllPopovers();
    if (notification.type === "announcement") navigate("announcements");
    else if (notification.type === "result") navigate("results");
    else if (notification.type === "fee") navigate("fees");
    else if (notification.type === "attendance") navigate(state.role === "teacher" ? "attendance" : "attendance");
    else navigate("overview");
  }

  function handleAuthFailure() {
    closeAllPopovers();
    window.location.replace("portal.html");
  }

  async function signOut() {
    try {
      await API.logout();
    } catch (error) {
      /* The local session is cleared even when the network call fails. */
    }
    window.location.replace("portal.html");
  }

  /* ============================================================
     12. EVENT HANDLERS
     ============================================================ */
  function onDocumentClick(event) {
    if (event.target.closest("[data-drawer-open]")) { openDrawer(); return; }
    if (event.target.closest("[data-drawer-close]")) { closeDrawer(true); return; }
    if (event.target.closest("[data-scrim]")) { closeDrawer(true); return; }

    var notifBtn = event.target.closest("[data-notif-toggle]");
    if (notifBtn) {
      event.preventDefault();
      togglePopover(document.querySelector("[data-notif-panel]"), notifBtn);
      return;
    }

    var profileBtn = event.target.closest("[data-profile-toggle]");
    if (profileBtn) {
      event.preventDefault();
      togglePopover(document.querySelector("[data-profile-panel]"), profileBtn);
      return;
    }

    if (event.target.closest("[data-read-all]")) { event.preventDefault(); markAllRead(); return; }

    var notificationBtn = event.target.closest("[data-notification]");
    if (notificationBtn) {
      event.preventDefault();
      markNotificationRead(notificationBtn);
      return;
    }

    if (event.target.closest("[data-logout]")) {
      event.preventDefault();
      signOut();
      return;
    }

    if (event.target.closest("[data-retry-load]")) {
      event.preventDefault();
      initPortal();
      return;
    }

    if (event.target.closest("[data-print]")) {
      event.preventDefault();
      window.print();
      return;
    }

    if (event.target.closest("[data-attendance-retry]")) {
      event.preventDefault();
      loadAttendanceWorkspace(true);
      return;
    }

    var resultOpen = event.target.closest("[data-result-open]");
    if (resultOpen) {
      event.preventDefault();
      openResultSheet(resultOpen);
      return;
    }

    if (event.target.closest("[data-result-back]")) {
      event.preventDefault();
      state.resultSheet = null;
      renderView();
      icons();
      return;
    }

    if (event.target.closest("[data-result-retry]")) {
      event.preventDefault();
      if (state.resultSheet) {
        state.resultSheet.error = null;
        state.resultSheet.loading = false;
      }
      loadResultSheetWorkspace();
      return;
    }

    var resultAction = event.target.closest("[data-result-action]");
    if (resultAction) {
      event.preventDefault();
      var action = resultAction.getAttribute("data-result-action");
      if (action === "submit") submitCurrentResultSheet(resultAction);
      if (action === "approve") approveCurrentResultSheet(resultAction);
      if (action === "return") returnCurrentResultSheet(resultAction);
      return;
    }

    if (event.target.closest("[data-publish-term]")) {
      event.preventDefault();
      publishCurrentTerm(event.target.closest("[data-publish-term]"));
      return;
    }

    if (event.target.closest("[data-admin-dialog-close]")) {
      event.preventDefault();
      closeAdminDialog();
      return;
    }

    var adminDialog = document.getElementById("adminDialog");
    if (adminDialog && event.target === adminDialog) {
      closeAdminDialog();
      return;
    }

    var adminAction = event.target.closest("[data-admin-action]");
    if (adminAction) {
      event.preventDefault();
      handleAdminAction(adminAction);
      return;
    }

    if (event.target.closest("[data-tt-refresh]")) {
      event.preventDefault();
      loadAdminTimetable(true);
      return;
    }

    var viewLink = event.target.closest("[data-view]");
    if (viewLink) {
      event.preventDefault();
      navigate(viewLink.getAttribute("data-view"));
      return;
    }

    var childBtn = event.target.closest("[data-child]");
    if (childBtn) {
      event.preventDefault();
      setChild(childBtn.getAttribute("data-child"));
      return;
    }

    var filterBtn = event.target.closest("[data-filter]");
    if (filterBtn) {
      event.preventDefault();
      var tag = filterBtn.getAttribute("data-filter");
      state.annFilter = tag;
      renderView();
      icons();
      var activeChip = document.querySelector('[data-filter="' + tag + '"]');
      if (activeChip) activeChip.focus();
      return;
    }

    if (!event.target.closest(".popover-wrap")) closeAllPopovers();
  }

  function onDocumentSubmit(event) {
    var form = event.target;
    if (form.matches("[data-admin-form]")) {
      event.preventDefault();
      submitAdminForm(form);
      return;
    }
    if (form.matches("[data-attendance-filter]")) {
      event.preventDefault();
      readAttendanceFilter(form);
      loadAttendanceWorkspace(true);
      return;
    }
    if (form.matches("[data-attendance-form]")) {
      event.preventDefault();
      submitAttendance(form);
      return;
    }
    if (form.matches("[data-result-form]")) {
      event.preventDefault();
      saveResultSheet(form);
    }
  }

  function onDocumentChange(event) {
    var timetableFilter = event.target.closest("[data-admin-timetable-filter]");
    if (timetableFilter) {
      var timetable = adminTimetableState();
      var classField = timetableFilter.querySelector('[name="class_id"]');
      var termField = timetableFilter.querySelector('[name="term_id"]');
      if (classField && classField.value) timetable.classId = classField.value;
      if (termField && termField.value) timetable.termId = termField.value;
      loadAdminTimetable(true);
      return;
    }

    var invoiceField = event.target.closest("[data-payment-invoice]");
    if (invoiceField) {
      var invoice = findById((ADMIN.finance && ADMIN.finance.invoices) || [], invoiceField.value);
      var amountField = invoiceField.closest("form").querySelector('[name="amount"]');
      if (invoice && amountField) amountField.value = invoice.balance;
      return;
    }

    var filter = event.target.closest("[data-attendance-filter]");
    if (filter) {
      readAttendanceFilter(filter);
      loadAttendanceWorkspace(true);
      return;
    }

    var score = event.target.closest("[data-result-score]");
    if (score) {
      updateResultRowTotal(score);
    }
  }

  function bindEvents() {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("submit", onDocumentSubmit);
    document.addEventListener("change", onDocumentChange);
    window.addEventListener("hashchange", onHashChange);
    window.addEventListener("resize", function () {
      if (window.innerWidth > 1024) closeDrawer(false);
    });
    window.addEventListener("load", icons);

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;
      closeAllPopovers();
      closeDrawer(true);
    });
  }

  /* ============================================================
     13. INIT
     ============================================================ */
  var eventsBound = false;
  var loading = false;

  async function initPortal() {
    if (loading) return;
    loading = true;
    state.ready = false;

    var root = document.getElementById("viewRoot");
    if (root) {
      root.innerHTML = '<div class="view-loading"><span class="loading-dot" aria-hidden="true"></span> Loading your secure portal\u2026</div>';
    }

    if (!eventsBound) {
      bindEvents();
      eventsBound = true;
    }

    if (!API) {
      renderLoadError("The portal client did not load. Refresh the page to try again.", true);
      loading = false;
      return;
    }

    try {
      var user = await API.bootstrap();
      if (!user) {
        window.location.replace("portal.html");
        return;
      }

      var data = await loadPortalData();
      hydratePortal(data);
      if (data.failures && data.failures.length) {
        toast("Some optional portal data could not be refreshed.");
      }
      render();
    } catch (error) {
      if (API.isAuthError(error)) {
        handleAuthFailure();
        return;
      }
      if (error && error.status === 403) {
        renderLoadError("Your secure session needs to be refreshed. Sign in again to continue.", false);
      } else {
        renderLoadError(API.message(error), true);
      }
    } finally {
      loading = false;
    }
  }

  function init() {
    initPortal();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  /* ============================================================
     14. OVERVIEW VIEWS
     ============================================================ */
  function overviewView() {
    switch (state.role) {
      case "parent": return parentOverview();
      case "teacher": return teacherOverview();
      case "admin": return adminOverview();
      default: return studentOverview();
    }
  }

  function profileBanner(student, perspective) {
    var side = perspective === "parent"
      ? statusPill("Enrolled", "gold") +
        "<p>Guardian<strong>" + esc(student.guardian.name) + "</strong></p>"
      : statusPill("Active student", "gold") +
        "<p>Form teacher<strong>" + esc(student.formTeacher) + "</strong></p>";

    return '<section class="profile-banner">' +
      '<div class="profile-banner__main">' +
        '<span class="avatar avatar--xl avatar--gold">' + esc(student.initials) + "</span>" +
        "<div>" +
          '<span class="profile-banner__kicker">Student profile</span>' +
          '<h2 class="profile-banner__name">' + esc(student.name) + "</h2>" +
          '<ul class="profile-banner__meta">' +
            '<li><i data-lucide="graduation-cap"></i>' + esc(student.klass) + "</li>" +
            '<li><i data-lucide="hash"></i>' + esc(student.admissionNo) + "</li>" +
            '<li><i data-lucide="home"></i>' + esc(student.house) + "</li>" +
          "</ul>" +
        "</div>" +
      "</div>" +
      '<div class="profile-banner__side">' + side + "</div>" +
    "</section>";
  }

  function resultsSnapshotPanel(student) {
    var r = student.results;
    var rows = r.subjects.slice(0, 5).map(function (subject) {
      return meterRow(subject.name, subject.total);
    }).join("");

    return panel({
      title: "Results snapshot",
      sub: r.published ? r.term + " \u00B7 published " + r.published : r.term,
      action: '<a class="link-arrow" href="#results" data-view="results">Full results <i data-lucide="arrow-right"></i></a>',
      body: r.subjects.length
        ? '<div class="meter-list">' + rows + "</div>" +
          '<div class="average-strip"><span>Term average</span><strong>' + numberText(r.average) +
          "%</strong><em>" + (r.position ? ordinal(r.position) + " of " + r.classSize : "Position pending") + "</em></div>"
        : emptyState("graduation-cap", "No published results yet", "Results appear here after the term is approved and published.")
    });
  }

  function announcementsFor() {
    return ANNOUNCEMENTS;
  }

  function announcementsPanel(roleKey, title, sub, limit) {
    var list = announcementsFor(roleKey);
    return panel({
      title: title || "Announcements",
      sub: sub || "Latest from the school",
      action: '<a class="link-arrow" href="#announcements" data-view="announcements">View all <i data-lucide="arrow-right"></i></a>',
      body: list.length
        ? announcementList(list.slice(0, limit || 3), false)
        : emptyState("megaphone", "No announcements yet", "New notices from the school will appear here.")
    });
  }

  function parentExtras() {
    return state.role === "parent" ? childSwitch() : "";
  }

  function feePanel(student) {
    var f = student.fees;
    var pct = f.total > 0 ? Math.round((f.paid / f.total) * 100) : 0;
    var balanceRow = f.balance > 0
      ? '<strong class="is-balance">' + money(f.balance) + "</strong>"
      : '<strong class="is-cleared">Cleared</strong>';

    var action = f.balance > 0
      ? '<p class="small muted mt-1">Settle at the school accounts office or by bank transfer.</p>'
      : '<p class="small muted mt-1">Fully paid \u2014 shukran.</p>';

    return panel({
      title: "Fees & payments",
      sub: SCHOOL.term + " " + SCHOOL.session,
      className: "fee-panel",
      action: '<a class="link-arrow" href="#fees" data-view="fees">Details <i data-lucide="arrow-right"></i></a>',
      body: '<div class="fee-lines">' +
          "<div><span>Total billed</span><strong>" + money(f.total) + "</strong></div>" +
          "<div><span>Paid to date</span><strong>" + money(f.paid) + "</strong></div>" +
          "<div><span>Balance</span>" + balanceRow + "</div>" +
        "</div>" +
        '<div class="meter"><span class="meter__fill" data-meter="' + pct + '"></span></div>' +
        '<p class="small muted mt-1">' + pct + "% of this term's fees paid.</p>" + action
    });
  }

  function attendancePanel(student) {
    var a = student.attendance;
    return panel({
      title: "Attendance",
      sub: a.rate + "% this term",
      action: '<a class="link-arrow" href="#attendance" data-view="attendance">Details <i data-lucide="arrow-right"></i></a>',
      body: '<div class="attendance-grid">' +
          '<div class="ring" data-ring="' + a.rate + '"><div class="ring__inner"><strong>' + a.rate + '%</strong><span>Present</span></div></div>' +
          weekBars(a.weeks) +
        "</div>" +
        '<p class="small muted mt-1">' + a.present + " present \u00B7 " + a.late + " late \u00B7 " + a.absent + " absent</p>"
    });
  }

  function upNextList(student) {
    if (!student.upNext.length) {
      return emptyState("calendar-check", "No upcoming lessons", "The timetable will show the next lessons when school is in session.");
    }
    return '<ol class="lesson-list">' + student.upNext.map(function (lesson, index) {
      return '<li class="lesson' + (index === 0 ? " is-next" : "") + '">' +
        '<span class="lesson__time">' + esc(splitTime(lesson.time).start) + "<small>" + esc(splitTime(lesson.time).end) + "</small></span>" +
        '<div class="lesson__body"><strong>' + esc(lesson.subject) + "</strong>" +
        "<span>" + esc(lesson.date_label || "") + " \u00B7 " + esc(lesson.teacher || "") + " \u00B7 " + esc(lesson.room || "") + "</span></div>" +
        (index === 0 ? statusPill("Next", "gold") : '<span class="lesson__period">Period ' + esc(lesson.period) + "</span>") +
        "</li>";
    }).join("") + "</ol>";
  }

  function resultsStats(student) {
    var r = student.results;
    return [
      stat({ icon: "clipboard-check", label: "Attendance", value: student.attendance.rate + "%", meta: student.attendance.present + " of " + student.attendance.days + " school days" }),
      stat({ icon: "trending-up", label: "Term average", value: r.average === null ? "\u2014" : numberText(r.average) + "%", meta: r.term }),
      stat({ icon: "award", label: "Class position", value: r.position ? ordinal(r.position) : "\u2014", meta: r.classSize ? "of " + r.classSize + " students" : "Not published" }),
      feeStat(student)
    ];
  }

  /* ---------- Student ---------- */
  function studentOverview() {
    var student = currentStudent();

    return pageHead({
      eyebrow: "Student dashboard",
      title: "Assalamu Alaikum, " + esc(firstName(student.name)),
      lead: "Here is your school day at a glance.",
      actions: '<span class="term-chip"><i data-lucide="calendar-clock"></i>' + esc((SCHOOL.week ? SCHOOL.week + " \u00B7 " : "") + SCHOOL.term) + "</span>" +
        '<button class="btn btn--gold" type="button" data-print><i data-lucide="printer"></i> Print results</button>'
    }) +
    profileBanner(student, "student") +
    statGrid(resultsStats(student)) +
    '<div class="dash-grid dash-grid--main">' +
      panel({
        title: "Up next",
        sub: student.upNext.length ? student.upNext[0].date_label + " \u00B7 " + student.upNext.length + " lessons" : "Your next lessons",
        action: '<a class="link-arrow" href="#timetable" data-view="timetable">Full timetable <i data-lucide="arrow-right"></i></a>',
        body: upNextList(student)
      }) +
      resultsSnapshotPanel(student) +
    "</div>" +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Homework & tasks",
        sub: student.assignments.length + " active tasks",
        body: student.assignments.length
          ? taskList(student.assignments)
          : emptyState("book-open", "No tasks right now", "New assignments from your teachers will appear here.")
      }) +
      announcementsPanel("student", "Announcements", "Latest from the school", 3) +
    "</div>";
  }

  /* ---------- Parent ---------- */
  function parentOverview() {
    var student = currentStudent();

    return pageHead({
      eyebrow: "Parent dashboard",
      title: "Assalamu Alaikum, " + esc(ROLES.parent.shortName),
      lead: "You are viewing " + esc(student.name) + "'s records for " + esc(SCHOOL.label || SCHOOL.term) + ".",
      actions: childSwitch() +
        '<button class="btn btn--gold" type="button" data-print><i data-lucide="printer"></i> Print report</button>'
    }) +
    profileBanner(student, "parent") +
    statGrid(resultsStats(student)) +
    '<div class="dash-grid dash-grid--main">' +
      resultsSnapshotPanel(student) +
      feePanel(student) +
    "</div>" +
    '<div class="dash-grid dash-grid--2">' +
      attendancePanel(student) +
      panel({
        title: "Up next",
        sub: student.upNext.length ? student.upNext[0].date_label : "Next lessons",
        action: '<a class="link-arrow" href="#timetable" data-view="timetable">Full timetable <i data-lucide="arrow-right"></i></a>',
        body: upNextList(student)
      }) +
    "</div>" +
    announcementsPanel("parent", "Announcements", "Notices for parents", 3);
  }

  /* ---------- Teacher ---------- */
  function teacherOverview() {
    var t = TEACHER;
    var stats = t.stats || {};

    var schedule = t.schedule.length
      ? '<ol class="lesson-list">' + t.schedule.map(function (lesson, index) {
          return '<li class="lesson' + (index === 0 ? " is-next" : "") + '">' +
            '<span class="lesson__time">' + esc(splitTime(lesson.time).start) + "<small>" + esc(splitTime(lesson.time).end) + "</small></span>" +
            '<div class="lesson__body"><strong>' + esc(lesson.klass) + " \u00B7 " + esc(lesson.subject) + "</strong>" +
            "<span>" + esc(lesson.period) + " \u00B7 Room " + esc(lesson.room) + "</span></div>" +
            '<span class="lesson__period">' + esc(lesson.period) + "</span>" +
            "</li>";
        }).join("") + "</ol>"
      : emptyState("calendar-check", "No lessons scheduled", "Your timetable for today is clear.");

    var registers = t.pendingRegisters.length
      ? '<ul class="register-list">' + t.pendingRegisters.map(function (r) {
          return '<li class="register">' +
            '<span class="register__icon"><i data-lucide="clipboard-check"></i></span>' +
            '<div class="register__body"><strong>' + esc(r.klass) + "</strong><span>" + esc(r.period) + " \u00B7 " + esc(r.time) + " \u00B7 " + r.students + " students</span></div>" +
            '<button class="btn btn--ghost" type="button" data-view="attendance">Mark</button>' +
            "</li>";
        }).join("") + "</ul>"
      : emptyState("check-circle-2", "All registers marked", "There are no pending attendance registers for today.");

    var performance = t.classPerformance.length
      ? '<div class="meter-list">' + t.classPerformance.map(function (row) {
          return meterRow(row.klass, row.average, numberText(row.average) + "%");
        }).join("") + "</div>"
      : emptyState("trending-up", "No class averages yet", "Class performance appears after results are submitted.");

    return pageHead({
      eyebrow: "Teacher dashboard",
      title: "Assalamu Alaikum, " + esc(ROLES.teacher.shortName),
      lead: "Your classes and tasks for today.",
      actions: '<button class="btn btn--gold" type="button" data-view="attendance"><i data-lucide="clipboard-check"></i> Mark register</button>' +
        '<button class="btn btn--ghost" type="button" data-view="results"><i data-lucide="file-check-2"></i> Results entry</button>'
    }) +
    statGrid([
      stat({ icon: "presentation", label: "Lessons today", value: asNumber(stats.lessons_today), meta: "Across " + asNumber(stats.classes) + " classes" }),
      stat({ icon: "clipboard-check", label: "Registers pending", value: asNumber(stats.pending_registers), meta: t.pendingRegisters.length ? t.pendingRegisters.map(function (r) { return r.klass; }).join(" and ") : "All marked", alert: asNumber(stats.pending_registers) > 0 }),
      stat({ icon: "file-check-2", label: "Result sheets due", value: asNumber(stats.sheets_outstanding), meta: SCHOOL.label || SCHOOL.term || "This term" }),
      stat({ icon: "users", label: "Students taught", value: asNumber(stats.students), meta: "This term" })
    ]) +
    '<div class="dash-grid dash-grid--main">' +
      panel({
        title: "Today's schedule",
        sub: t.schedule.length + " lesson" + (t.schedule.length === 1 ? "" : "s"),
        body: schedule
      }) +
      panel({
        title: "Registers to mark",
        sub: t.pendingRegisters.length + " classes pending",
        body: registers
      }) +
    "</div>" +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Class performance",
        sub: "Average score by class this term",
        body: performance
      }) +
      announcementsPanel("teacher", "Staff notices", "From the administration", 3) +
    "</div>";
  }

  /* ---------- Admin ---------- */
  function adminOverview() {
    var a = ADMIN;
    var maxLevel = Math.max.apply(null, a.levels.map(function (l) { return l.count; }).concat([1]));

    var levels = a.levels.length
      ? '<div class="meter-list">' + a.levels.map(function (level) {
          return meterRow(level.label, Math.round((level.count / maxLevel) * 100), level.count);
        }).join("") + "</div>" +
        '<p class="small muted mt-2">' + a.stats.students + " students enrolled across " + a.levels.length + " levels.</p>"
      : emptyState("users", "No enrolment data", "Student counts will appear here once records are available.");

    var pipeline = '<div class="pipeline">' + a.pipeline.map(function (step) {
      return '<div class="pipeline__step"><span class="pipeline__num">' + step.count + "</span>" +
        '<span class="pipeline__label">' + esc(step.label) + "</span></div>";
    }).join("") + "</div>";

    var approvalRows = a.approvals.map(function (row) {
      return [
        "<strong>" + esc(row.klass) + "</strong>",
        esc(row.subject),
        esc(row.teacher),
        esc(row.submitted),
        statusPill(row.status, row.tone),
        '<button class="btn btn--ghost btn--sm" type="button" data-view="results">Review</button>'
      ];
    });

    var quickActions = [
      { icon: "users", title: "Student register", meta: "Browse every learner", view: "students" },
      { icon: "megaphone", title: "Announcements", meta: "School-wide notices", view: "announcements" },
      { icon: "wallet", title: "Fees & finance", meta: "Invoices and payments", view: "finance" },
      { icon: "clipboard-check", title: "Result approvals", meta: "Submitted sheets", view: "results" }
    ];

    return pageHead({
      eyebrow: "Administration dashboard",
      title: "Assalamu Alaikum, " + esc(ROLES.admin.shortName),
      lead: "A live view of the school at a glance.",
      actions: '<button class="btn btn--gold" type="button" data-view="announcements"><i data-lucide="megaphone"></i> Announcements</button>' +
        '<button class="btn btn--ghost" type="button" data-view="students"><i data-lucide="users"></i> Students</button>'
    }) +
    statGrid([
      stat({ icon: "users", label: "Students", value: a.stats.students, meta: "Nursery to Senior Secondary" }),
      stat({ icon: "id-card", label: "Staff", value: a.stats.staff, meta: asNumber(a.stats.teachers) + " teaching staff" }),
      stat({ icon: "clipboard-check", label: "Attendance today", value: a.stats.attendance + "%", meta: a.stats.attendanceDate ? "Recorded " + longDate(a.stats.attendanceDate) : "No register yet" }),
      stat({ icon: "wallet", label: "Fees collected", value: compactMoney(a.stats.collected), meta: numberText(a.stats.collectionRate, 0) + "% of " + compactMoney(a.stats.collected + a.stats.outstanding) + " billed" })
    ]) +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Enrolment by level",
        sub: "Current session " + SCHOOL.session,
        body: levels
      }) +
      panel({
        title: "Admissions pipeline",
        sub: "Applications this term",
        body: pipeline
      }) +
    "</div>" +
    panel({
      title: "Result approvals",
      sub: "Sheets submitted by teaching staff",
      action: statusPill(a.approvals.length + " pending", a.approvals.length ? "warn" : "ok"),
      body: approvalRows.length
        ? table(
            [
              { label: "Class" }, { label: "Subject" }, { label: "Teacher" },
              { label: "Submitted" }, { label: "Status" }, { label: "Action", align: "right" }
            ],
            approvalRows,
            "Result sheets awaiting approval"
          )
        : emptyState("check-circle-2", "No sheets awaiting approval", "Submitted result sheets will appear here for review.")
    }) +
    '<div class="dash-grid dash-grid--2 dash-grid--flush mt-2">' +
      panel({
        title: "Quick actions",
        sub: "Common administrative tasks",
        body: '<div class="quick-grid">' + quickActions.map(function (item) {
          return '<button class="quick" type="button" data-view="' + item.view + '">' +
            '<span class="quick__icon"><i data-lucide="' + item.icon + '"></i></span>' +
            "<span><strong>" + esc(item.title) + "</strong><span>" + esc(item.meta) + "</span></span></button>";
        }).join("") + "</div>"
      }) +
      announcementsPanel("admin", "School announcements", "Latest notices", 3) +
    "</div>";
  }

  /* ============================================================
     15. RESULTS VIEW
     ============================================================ */
  function resultsView() {
    var student = currentStudent();
    var r = student.results;

    var rows = r.subjects.map(function (subject) {
      return [
        "<strong>" + esc(subject.name) + "</strong>",
        numberText(subject.ca),
        numberText(subject.exam),
        "<strong>" + numberText(subject.total, 0) + "</strong>",
        '<span class="grade grade--' + String(subject.grade || "").toLowerCase() + '">' + esc(subject.grade || "\u2014") + "</span>",
        esc(subject.remark || "")
      ];
    });

    var body = rows.length
      ? table(
          [
            { label: "Subject" },
            { label: "CA (40)", align: "right" },
            { label: "Exam (60)", align: "right" },
            { label: "Total", align: "right" },
            { label: "Grade", align: "center" },
            { label: "Remark" }
          ],
          rows,
          "Subject results for " + student.name
        ) + '<p class="small muted mt-2">Grading scale: A 70\u2013100 \u00B7 B 60\u201369 \u00B7 C 50\u201359 \u00B7 D 45\u201349 \u00B7 E 40\u201344 \u00B7 F below 40.</p>'
      : emptyState("graduation-cap", "No published results", "Your report will appear here once the term results are approved and published.");

    return pageHead({
      eyebrow: "Academics",
      title: "Results & report cards",
      lead: "Published results for " + esc(student.name) + " \u00B7 " + esc(student.klass) + ".",
      actions: parentExtras() +
        '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(r.term) + "</span>" +
        '<button class="btn btn--gold" type="button" data-print><i data-lucide="printer"></i> Print report</button>'
    }) +
    statGrid([
      stat({ icon: "trending-up", label: "Term average", value: r.average === null ? "\u2014" : numberText(r.average) + "%", meta: r.term }),
      stat({ icon: "award", label: "Class position", value: r.position ? ordinal(r.position) : "\u2014", meta: r.classSize ? "of " + r.classSize + " students" : "Not published" }),
      stat({ icon: "book-open", label: "Subjects offered", value: r.subjects.length, meta: "All assessed" }),
      stat({ icon: "check-circle-2", label: "Result status", value: r.published ? "Published" : "Pending", meta: r.published ? "Released " + r.published : "Awaiting publication" })
    ]) +
    panel({
      title: "Subject performance",
      sub: r.published ? r.term + " \u00B7 published " + r.published : r.term,
      action: statusPill(r.published ? "Approved" : "Pending", r.published ? "ok" : "warn"),
      body: body
    }) +
    callout("info", "<strong>" + esc(SCHOOL.label || SCHOOL.term) + ":</strong> results are published only after the term's result sheets are approved by the administration. Contact the school office if a published result appears incorrect.");
  }

  /* ============================================================
     16. ATTENDANCE VIEW
     ============================================================ */
  function attendanceView() {
    var student = currentStudent();
    var a = student.attendance;

    var rows = a.recent.map(function (record) {
      return [
        esc(record.date),
        esc(record.day),
        statusPill(record.status, record.tone),
        esc(record.note)
      ];
    });

    return pageHead({
      eyebrow: "Academics",
      title: "Attendance record",
      lead: "Daily attendance for " + esc(student.name) + " \u00B7 " + esc(student.klass) + ".",
      actions: parentExtras() +
        '<button class="btn btn--ghost" type="button" data-print><i data-lucide="printer"></i> Print record</button>'
    }) +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Attendance summary",
        sub: a.rate + "% this term \u00B7 " + a.days + " school days",
        body: '<div class="attendance-grid">' +
            '<div class="ring" data-ring="' + a.rate + '"><div class="ring__inner"><strong>' + a.rate + '%</strong><span>Present</span></div></div>' +
            '<div class="attendance-facts">' +
              "<div><strong>" + a.present + "</strong><span>Present</span></div>" +
              "<div><strong>" + a.absent + "</strong><span>Absent</span></div>" +
              "<div><strong>" + a.late + "</strong><span>Late</span></div>" +
            "</div>" +
          "</div>"
      }) +
      panel({
        title: "Weekly record",
        sub: "Attendance by week this term",
        body: weekBars(a.weeks) + '<p class="small muted mt-1">The most recent week is highlighted in gold.</p>'
      }) +
    "</div>" +
    panel({
      title: "Recent records",
      sub: "Last 8 school days",
      action: statusPill("Live record", "info"),
      body: rows.length
        ? table(
            [{ label: "Date" }, { label: "Day" }, { label: "Status" }, { label: "Note" }],
            rows,
            "Recent attendance records for " + student.name
          )
        : emptyState("clipboard-check", "No attendance records", "Daily registers will appear here once classes begin.")
    });
  }

  /* ============================================================
     17. TIMETABLE VIEW
     ============================================================ */
  function timetableTable(student) {
    var days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
    var head = '<th scope="col">Period</th>' + days.map(function (day) {
      return '<th scope="col">' + day + "</th>";
    }).join("");

    var rows = student.timetable.periods.map(function (period, index) {
      var cells = days.map(function (day) {
        var lesson = student.timetable.days[day][index];
        return "<td>" +
          '<div class="tt__cell"><strong>' + esc(lesson.subject) + "</strong>" +
          "<span>" + esc(lesson.teacher) + "</span>" +
          "<small>" + esc(lesson.room) + "</small></div></td>";
      }).join("");
      return '<tr><th scope="row"><span class="tt__period">' + esc(period.label) + "</span>" +
        '<span class="tt__time">' + esc(period.time) + "</span></th>" + cells + "</tr>";
    }).join("");

    return '<div class="table-wrap" tabindex="0" role="region" aria-label="Weekly timetable for ' + esc(student.name) + '">' +
      '<table class="data-table timetable">' +
        '<caption class="sr-only">Weekly timetable for ' + esc(student.name) + ", " + esc(student.klass) + "</caption>" +
        "<thead><tr>" + head + "</tr></thead><tbody>" + rows + "</tbody></table></div>" +
      '<div class="tt-notes"><span><strong>Assembly</strong> 7:45\u20138:00</span>' +
        "<span><strong>Break</strong> 10:00\u201310:20</span>" +
        "<span><strong>Lunch</strong> 11:40\u201312:20</span>" +
        "<span><strong>Close</strong> 1:00pm</span></div>";
  }

  function timetableView() {
    var student = currentStudent();
    return pageHead({
      eyebrow: "Academics",
      title: "Weekly timetable",
      lead: esc(student.klass) + " \u00B7 " + SCHOOL.term + " " + SCHOOL.session + ".",
      actions: parentExtras() +
        '<button class="btn btn--ghost" type="button" data-print><i data-lucide="printer"></i> Print timetable</button>'
    }) +
    panel({
      title: "Class timetable",
      sub: esc(student.klass) + " \u00B7 6 periods a day",
      action: statusPill("Monday to Friday", "info"),
      body: timetableTable(student)
    });
  }

  /* ============================================================
     18. FEES VIEW
     ============================================================ */
  function invoiceTone(status) {
    if (status === "Paid") return "ok";
    if (status === "Unpaid") return "warn";
    return "info";
  }

  function feesView() {
    var student = currentStudent();
    var f = student.fees;
    var pct = f.total > 0 ? Math.round((f.paid / f.total) * 100) : 0;

    var invoiceRows = f.invoices.map(function (invoice) {
      return [
        '<span class="amount">' + esc(invoice.ref) + "</span>",
        esc(invoice.item),
        esc(invoice.date),
        '<span class="amount">' + money(invoice.amount) + "</span>",
        statusPill(invoice.status, invoiceTone(invoice.status))
      ];
    });

    var paymentRows = f.payments.map(function (payment) {
      return [
        esc(payment.date),
        '<span class="amount">' + esc(payment.ref) + "</span>",
        esc(payment.method),
        '<span class="amount">' + money(payment.amount) + "</span>"
      ];
    });

    var dueLine = f.balance > 0
      ? statusPill("Due " + f.dueDate, "warn") + " Please settle at the school accounts office or by bank transfer."
      : statusPill("Fully paid", "ok") + " Shukran. Your receipts are listed below.";

    return pageHead({
      eyebrow: "Accounts",
      title: "Fees & payments",
      lead: "Fee statement for " + esc(student.name) + " \u00B7 " + esc(student.klass) + ".",
      actions: parentExtras() +
        '<button class="btn btn--gold" type="button" data-print><i data-lucide="printer"></i> Print statement</button>'
    }) +
    '<section class="fee-hero">' +
      '<div class="fee-hero__main">' +
        '<span class="fee-hero__label">Outstanding balance \u00B7 ' + SCHOOL.term + " " + SCHOOL.session + "</span>" +
        '<strong class="fee-hero__value">' + money(f.balance) + "</strong>" +
        "<p>" + dueLine + "</p>" +
      "</div>" +
      '<div class="fee-hero__side">' +
        '<div class="fee-hero__row"><span>Total billed</span><strong>' + money(f.total) + "</strong></div>" +
        '<div class="fee-hero__row"><span>Paid to date</span><strong>' + money(f.paid) + "</strong></div>" +
        '<div class="meter meter--on-dark"><span class="meter__fill" data-meter="' + pct + '"></span></div>' +
        '<p class="small" style="color:rgba(255,255,255,.78);">' + (f.balance > 0
          ? "Payments are receipted at the school accounts office or by bank transfer."
          : "Fully paid \u2014 shukran.") + "</p>" +
      "</div>" +
    "</section>" +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Invoices",
        sub: SCHOOL.term + " " + SCHOOL.session,
        action: statusPill(pct + "% paid", pct === 100 ? "ok" : "warn"),
        body: table(
          [
            { label: "Reference" }, { label: "Description" }, { label: "Date" },
            { label: "Amount", align: "right" }, { label: "Status" }
          ],
          invoiceRows,
          "Fee invoices"
        )
      }) +
      panel({
        title: "Payment history",
        sub: "Receipted payments this term",
        body: f.payments.length
          ? table(
              [
                { label: "Date" }, { label: "Reference" }, { label: "Method" }, { label: "Amount", align: "right" }
              ],
              paymentRows,
              "Payment history"
            )
          : emptyState("wallet", "No payments yet", "Receipted payments will appear here.")
      }) +
    "</div>" +
    callout("info", "All payments are receipted by the school accounts office. Keep your payment reference for any enquiry.");
  }

  /* ============================================================
     19. ANNOUNCEMENTS VIEW
     ============================================================ */
  function announcementsView() {
    if (state.role === "admin") return adminAnnouncementsView();

    var list = announcementsFor(state.role);
    var tags = ["All"];
    list.forEach(function (a) {
      if (tags.indexOf(a.tag) === -1) tags.push(a.tag);
    });
    if (tags.indexOf(state.annFilter) === -1) state.annFilter = "All";

    var filtered = state.annFilter === "All"
      ? list
      : list.filter(function (a) { return a.tag === state.annFilter; });

    var chips = tags.map(function (tag) {
      var active = state.annFilter === tag;
      return '<button class="filter-chip' + (active ? " is-active" : "") + '" type="button" data-filter="' +
        esc(tag) + '" aria-pressed="' + (active ? "true" : "false") + '">' + esc(tag) + "</button>";
    }).join("");

    return pageHead({
      eyebrow: "School life",
      title: "Announcements",
      lead: "Notices, events and important information for " + esc(ROLES[state.role].label.toLowerCase()) + "s.",
      actions: statusPill(list.length + " notices", "info")
    }) +
    '<div class="filter-chips" role="group" aria-label="Filter announcements by category">' + chips + "</div>" +
    panel({
      title: state.annFilter === "All" ? "All announcements" : state.annFilter + " announcements",
      sub: filtered.length + " notice" + (filtered.length === 1 ? "" : "s"),
      body: filtered.length
        ? announcementList(filtered, true)
        : emptyState("megaphone", "Nothing here yet", "There are no announcements in this category.")
    });
  }

  /* ============================================================
     20. TEACHER VIEWS
     ============================================================ */
  function teacherClassesView() {
    var t = TEACHER;
    var rows = t.classes.map(function (classroom) {
      return [
        "<strong>" + esc(classroom.name) + "</strong>",
        esc(classroom.level_label || classroom.level || ""),
        String(asNumber(classroom.students)),
        classroom.average === null || classroom.average === undefined
          ? '<span class="muted">\u2014</span>'
          : numberText(classroom.average) + "%"
      ];
    });

    return pageHead({
      eyebrow: "Teaching",
      title: "My classes",
      lead: "The classes and subjects assigned to you for " + esc(SCHOOL.label || SCHOOL.term) + ".",
      actions: '<button class="btn btn--gold" type="button" data-view="attendance"><i data-lucide="clipboard-check"></i> Mark register</button>' +
        '<button class="btn btn--ghost" type="button" data-view="results"><i data-lucide="file-check-2"></i> Results entry</button>'
    }) +
    statGrid([
      stat({ icon: "presentation", label: "Classes", value: asNumber(t.stats.classes), meta: "This term" }),
      stat({ icon: "users", label: "Students", value: asNumber(t.stats.students), meta: "Active learners" }),
      stat({ icon: "calendar-check", label: "Lessons today", value: asNumber(t.stats.lessons_today), meta: "Monday timetable" }),
      stat({ icon: "file-check-2", label: "Sheets outstanding", value: asNumber(t.stats.sheets_outstanding), meta: "Awaiting entry or submission", alert: asNumber(t.stats.sheets_outstanding) > 0 })
    ]) +
    panel({
      title: "Class list",
      sub: rows.length + " class" + (rows.length === 1 ? "" : "es"),
      body: rows.length
        ? table(
            [{ label: "Class" }, { label: "Level" }, { label: "Students", align: "right" }, { label: "Average", align: "right" }],
            rows,
            "Classes assigned to you"
          )
        : emptyState("users", "No classes assigned", "Speak to the school administration if a class is missing.")
    });
  }

  function attendanceState() {
    if (!state.attendance) {
      state.attendance = {
        classId: null,
        date: todayISO(),
        data: null,
        loadedKey: "",
        loading: false,
        error: null
      };
    }
    var attendance = state.attendance;
    if (!attendance.classId) {
      var pending = TEACHER.pendingRegisters[0];
      attendance.classId = pending ? pending.classId : (TEACHER.classes[0] ? TEACHER.classes[0].id : "");
    }
    return attendance;
  }

  function readAttendanceFilter(form) {
    var attendance = attendanceState();
    var classField = form.querySelector('[name="class_id"]');
    var dateField = form.querySelector('[name="date"]');
    if (classField && classField.value) attendance.classId = classField.value;
    if (dateField && dateField.value) attendance.date = dateField.value;
  }

  function teacherAttendanceView() {
    var attendance = attendanceState();
    var term = DATA.dashboard && DATA.dashboard.term ? DATA.dashboard.term : {};
    var key = attendance.classId + "|" + attendance.date;
    var options = TEACHER.classes.map(function (classroom) {
      return '<option value="' + esc(classroom.id) + '"' + (classroom.id === attendance.classId ? " selected" : "") + ">" +
        esc(classroom.name) + " \u00B7 " + esc(classroom.level_label || classroom.level || "") + "</option>";
    }).join("");

    var workspace;
    if (attendance.error) {
      workspace = '<div class="panel"><div class="panel__body">' +
        emptyState("cloud-off", "The register could not be loaded", attendance.error) +
        '<div class="empty-state__actions"><button class="btn btn--primary" type="button" data-attendance-retry><i data-lucide="refresh-cw"></i> Try again</button></div>' +
        "</div></div>";
    } else if (attendance.data && attendance.loadedKey === key) {
      workspace = attendanceRegisterPanel(attendance.data);
    } else {
      workspace = '<div class="view-loading"><span class="loading-dot" aria-hidden="true"></span> Loading register\u2026</div>';
    }

    return pageHead({
      eyebrow: "Teaching",
      title: "Attendance register",
      lead: "Mark the daily register for one of your classes. Every active student must be marked before saving.",
      actions: '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(SCHOOL.label || SCHOOL.term) + "</span>"
    }) +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Choose a register",
        sub: "Class and school day",
        body: '<form data-attendance-filter>' +
          '<div class="form-grid">' +
            '<div class="field"><label for="attendanceClass">Class</label><select id="attendanceClass" name="class_id">' + options + "</select></div>" +
            '<div class="field"><label for="attendanceDate">Date</label><input id="attendanceDate" name="date" type="date" value="' + esc(attendance.date) + '"' +
              (term.starts_on ? ' min="' + esc(term.starts_on) + '"' : "") +
              (term.ends_on ? ' max="' + esc(term.ends_on) + '"' : "") + " required></div>" +
          "</div>" +
          '<p class="small muted mt-2">Only weekdays inside the current term can be marked.</p>' +
        "</form>"
      }) +
      panel({
        title: "How registers work",
        sub: "A complete record every school day",
        body: callout("info", "Attendance is visible to the student, their guardians and the administration. Choose <strong>Present</strong>, <strong>Late</strong>, <strong>Absent</strong> or <strong>Excused</strong> for every learner, then save.") +
          '<div class="legend-row"><span class="status status--ok">Present</span><span class="status status--warn">Late</span><span class="status status--danger">Absent</span><span class="status status--info">Excused</span></div>'
      }) +
    "</div>" +
    '<div id="attendanceWorkspace" class="mt-2">' + workspace + "</div>";
  }

  function attendanceRegisterPanel(data) {
    var students = Array.isArray(data.students) ? data.students : [];
    var classInfo = data.class || {};
    var statuses = [
      { value: "present", label: "Present" },
      { value: "late", label: "Late" },
      { value: "absent", label: "Absent" },
      { value: "excused", label: "Excused" }
    ];
    var markedCount = students.filter(function (student) { return !!student.status; }).length;

    if (!students.length) {
      return panel({
        title: (classInfo.name || "Class") + " register",
        sub: longDate(data.date),
        body: emptyState("users", "No active students", "There are no active learners in this class yet.")
      });
    }

    var rows = students.map(function (student) {
      var current = String(student.status || "Present").toLowerCase();
      var options = statuses.map(function (status) {
        return '<option value="' + status.value + '"' + (status.value === current ? " selected" : "") + ">" + status.label + "</option>";
      }).join("");
      return {
        attrs: ' data-attendance-row data-student-id="' + esc(student.student_id) + '"',
        cells: [
          '<div class="cell-person"><strong>' + esc(student.name) + "</strong><span>" + esc(student.admission_no || "") + "</span></div>",
          '<select class="table-input" data-attendance-status aria-label="Attendance status for ' + esc(student.name) + '">' + options + "</select>",
          '<input class="table-input" data-attendance-note type="text" maxlength="190" value="' + esc(student.note || "") + '" placeholder="Optional note" aria-label="Attendance note for ' + esc(student.name) + '">'
        ]
      };
    });

    return panel({
      title: classInfo.name + " register",
      sub: longDate(data.date) + " \u00B7 " + esc(data.term ? data.term.label : SCHOOL.label),
      action: statusPill(data.marked ? "Saved" : "Unsaved", data.marked ? "ok" : "warn"),
      body: '<form data-attendance-form data-class-id="' + esc(classInfo.id || "") + '" data-term-id="' + esc(data.term ? data.term.id : "") + '" data-date="' + esc(data.date) + '">' +
        '<div class="register-summary"><span><strong>' + students.length + "</strong> students</span><span><strong>" + markedCount + "</strong> already marked</span>" +
          (data.marked_at ? "<span>Last saved " + esc(data.marked_at) + "</span>" : "") + "</div>" +
        table(
          [{ label: "Student" }, { label: "Status" }, { label: "Note" }],
          rows,
          "Attendance register for " + classInfo.name
        ) +
        '<div class="form-actions">' +
          '<button class="btn btn--primary" type="submit"><i data-lucide="save"></i> Save register</button>' +
          '<p class="form-msg" role="status"></p>' +
        "</div>" +
      "</form>"
    });
  }

  async function loadAttendanceWorkspace(force) {
    var workspace = document.getElementById("attendanceWorkspace");
    if (!workspace) return;
    var attendance = attendanceState();
    var key = attendance.classId + "|" + attendance.date;

    if (!force && attendance.data && attendance.loadedKey === key) {
      workspace.innerHTML = attendanceRegisterPanel(attendance.data);
      icons();
      return;
    }
    if (attendance.loading && !force) return;
    if (!attendance.classId) {
      attendance.error = "Choose a class to load its register.";
      workspace.innerHTML = '<div class="panel"><div class="panel__body">' +
        emptyState("users", "No class selected", attendance.error) + "</div></div>";
      icons();
      return;
    }

    attendance.loading = true;
    attendance.error = null;
    attendance.requestKey = key;
    workspace.innerHTML = '<div class="view-loading"><span class="loading-dot" aria-hidden="true"></span> Loading register\u2026</div>';

    try {
      var payload = await API.get(
        "/teacher/attendance?class_id=" + encodeURIComponent(attendance.classId) +
        "&date=" + encodeURIComponent(attendance.date)
      );
      if (attendance.requestKey !== key) return;
      attendance.data = payload && payload.data ? payload.data : null;
      attendance.loadedKey = key;
      if (state.role === "teacher" && state.section === "attendance") {
        workspace.innerHTML = attendanceRegisterPanel(attendance.data);
        hydrateCharts();
        icons();
      }
    } catch (error) {
      if (attendance.requestKey !== key) return;
      if (API.isAuthError(error)) return handleAuthFailure();
      attendance.error = API.message(error);
      if (state.role === "teacher" && state.section === "attendance") {
        workspace.innerHTML = '<div class="panel"><div class="panel__body">' +
          emptyState("cloud-off", "The register could not be loaded", attendance.error) +
          '<div class="empty-state__actions"><button class="btn btn--primary" type="button" data-attendance-retry><i data-lucide="refresh-cw"></i> Try again</button></div>' +
          "</div></div>";
        icons();
      }
    } finally {
      if (attendance.requestKey === key) attendance.loading = false;
    }
  }

  async function submitAttendance(form) {
    var button = form.querySelector('button[type="submit"]');
    var rows = Array.prototype.slice.call(form.querySelectorAll("[data-attendance-row]"));
    var records = [];

    setFormMessage(form, "");
    rows.forEach(function (row) {
      var status = row.querySelector("[data-attendance-status]");
      var note = row.querySelector("[data-attendance-note]");
      if (status && status.value) {
        records.push({
          student_id: row.getAttribute("data-student-id"),
          status: status.value,
          note: note && note.value.trim() ? note.value.trim() : null
        });
      }
    });

    if (!records.length) {
      setFormMessage(form, "There are no students to mark in this class.");
      return;
    }

    setButtonBusy(button, true, "Saving\u2026");
    try {
      await API.post("/teacher/attendance", {
        class_id: form.getAttribute("data-class-id"),
        term_id: form.getAttribute("data-term-id"),
        date: form.getAttribute("data-date"),
        records: records
      });

      var attendance = attendanceState();
      if (attendance.date === todayISO()) {
        TEACHER.pendingRegisters = TEACHER.pendingRegisters.filter(function (register) {
          return register.classId !== attendance.classId;
        });
        TEACHER.stats.pending_registers = TEACHER.pendingRegisters.length;
        setNavBadge("teacher", "attendance", TEACHER.pendingRegisters.length);
        renderSidebar();
      }

      toast("Attendance register saved.");
      await loadAttendanceWorkspace(true);
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      setFormMessage(form, API.message(error));
      setButtonBusy(button, false);
    }
  }

  function resultSheetWorkspace(role) {
    var result = state.resultSheet;
    var back = '<button class="btn btn--ghost" type="button" data-result-back><i data-lucide="arrow-left"></i> All result sheets</button>';

    if (result && result.data) return resultSheetDetailView(result.data, role);
    if (result && result.error) {
      return pageHead({
        eyebrow: role === "admin" ? "Administration" : "Results entry",
        title: "Result sheet",
        lead: "The requested sheet could not be opened.",
        actions: back
      }) +
      '<section class="panel"><div class="panel__body">' +
        emptyState("cloud-off", "Unable to load the result sheet", result.error) +
        '<div class="empty-state__actions"><button class="btn btn--primary" type="button" data-result-retry><i data-lucide="refresh-cw"></i> Try again</button></div>' +
      "</div></section>";
    }

    return pageHead({
      eyebrow: role === "admin" ? "Administration" : "Results entry",
      title: "Result sheet",
      lead: "Loading the latest scores\u2026",
      actions: back
    }) +
    '<div id="resultSheetWorkspace" class="view-loading"><span class="loading-dot" aria-hidden="true"></span> Loading result sheet\u2026</div>';
  }

  function teacherResultsView() {
    if (state.resultSheet) return resultSheetWorkspace("teacher");

    var sheets = TEACHER.resultSheets;
    var stats = TEACHER.stats || {};
    var rows = sheets.map(function (sheet) {
      var status = sheet.status || "not_started";
      var actionLabel = status === "not_started" ? "Start sheet" : "Open sheet";
      return [
        '<div class="cell-person"><strong>' + esc(sheet.class.name) + "</strong><span>" + esc(sheet.class.level || "") + "</span></div>",
        esc(sheet.subject.name),
        statusPill(statusLabel(status, sheet.status_label), statusTone(status)),
        asNumber(sheet.entered) + " / " + asNumber(sheet.students),
        sheet.submitted_at ? longDate(sheet.submitted_at) : '<span class="muted">\u2014</span>',
        '<button class="btn btn--ghost btn--sm" type="button" data-result-open data-sheet-id="' + esc(sheet.sheet_id || "") +
          '" data-class-id="' + esc(sheet.class.id) + '" data-subject-id="' + esc(sheet.subject.id) +
          '" data-status="' + esc(status) + '">' + actionLabel + "</button>"
      ];
    });

    return pageHead({
      eyebrow: "Teaching",
      title: "Results entry",
      lead: "Enter continuous assessment and examination scores for your assigned subjects.",
      actions: '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(SCHOOL.label || SCHOOL.term) + "</span>" +
        '<button class="btn btn--ghost" type="button" data-view="overview"><i data-lucide="layout-dashboard"></i> Overview</button>'
    }) +
    statGrid([
      stat({ icon: "file-check-2", label: "Sheets outstanding", value: asNumber(stats.sheets_outstanding), meta: "Draft or not started", alert: asNumber(stats.sheets_outstanding) > 0 }),
      stat({ icon: "check-circle-2", label: "Submitted", value: sheets.filter(function (sheet) { return sheet.status === "submitted"; }).length, meta: "Awaiting administration" }),
      stat({ icon: "award", label: "Approved", value: sheets.filter(function (sheet) { return sheet.status === "approved" || sheet.status === "published"; }).length, meta: "Ready for families" }),
      stat({ icon: "book-open", label: "Subjects", value: sheets.length, meta: "Assigned this term" })
    ]) +
    panel({
      title: "My result sheets",
      sub: (SCHOOL.label || SCHOOL.term || "This term") + " \u00B7 draft, submit and track approval",
      body: rows.length
        ? table(
            [{ label: "Class" }, { label: "Subject" }, { label: "Status" }, { label: "Entered", align: "center" }, { label: "Submitted" }, { label: "Action", align: "right" }],
            rows,
            "Result sheets assigned to you"
          )
        : emptyState("file-check-2", "No result sheets", "You have no assigned subjects for this term.")
    }) +
    callout("info", "Scores use CA out of 40 and examination out of 60. A sheet can be edited while it is a draft; once submitted it is locked until the administration approves it or returns it for correction.");
  }

  function scoreInput(student, kind, max) {
    var value = student[kind] === null || student[kind] === undefined ? "" : numberText(student[kind], 2);
    return '<input class="table-input table-input--score" data-result-score="' + kind + '" type="number" min="0" max="' +
      max + '" step="0.01" value="' + esc(value) + '" aria-label="' + kind.toUpperCase() + ' score for ' + esc(student.name) + '">';
  }

  function resultSheetDetailView(data, role) {
    var sheet = data.sheet || {};
    var students = Array.isArray(data.students) ? data.students : [];
    var editable = role === "teacher" && sheet.status === "draft";
    var status = sheet.status || "draft";

    var rows = students.map(function (student) {
      var total = student.ca_score !== null && student.ca_score !== undefined &&
        student.exam_score !== null && student.exam_score !== undefined
        ? numberText(asNumber(student.ca_score) + asNumber(student.exam_score), 2)
        : "\u2014";

      return {
        attrs: editable ? ' data-result-row data-student-id="' + esc(student.id) + '"' : "",
        cells: [
          '<div class="cell-person"><strong>' + esc(student.name) + "</strong><span>" + esc(student.admission_no || "") + "</span></div>",
          editable ? scoreInput(student, "ca_score", 40) : numberText(student.ca_score),
          editable ? scoreInput(student, "exam_score", 60) : numberText(student.exam_score),
          '<strong data-row-total>' + total + "</strong>",
          esc(student.grade || "\u2014"),
          esc(student.remark || "")
        ]
      };
    });

    var scoreTable = table(
      [{ label: "Student" }, { label: "CA (40)", align: "right" }, { label: "Exam (60)", align: "right" }, { label: "Total", align: "right" }, { label: "Grade", align: "center" }, { label: "Remark" }],
      rows,
      "Scores for " + (sheet.class ? sheet.class.name : "") + " " + (sheet.subject ? sheet.subject.name : "")
    );

    var body = scoreTable;
    if (editable) {
      body = '<form data-result-form data-sheet-id="' + esc(sheet.id) + '">' +
        scoreTable +
        '<div class="form-actions">' +
          '<button class="btn btn--primary" type="submit"><i data-lucide="save"></i> Save draft</button>' +
          '<button class="btn btn--gold" type="button" data-result-action="submit"><i data-lucide="send"></i> Submit for approval</button>' +
          '<p class="form-msg" role="status"></p>' +
        "</div>" +
      "</form>";
    }

    var reviewPanel = "";
    if (role === "admin") {
      if (status === "submitted") {
        reviewPanel = panel({
          title: "Review decision",
          sub: "Approve the scores or return them to the teacher",
          body: '<div class="field"><label for="reviewNote">Return note</label>' +
            '<textarea id="reviewNote" data-review-note rows="3" maxlength="500" placeholder="Required only when returning the sheet for correction"></textarea></div>' +
            '<div class="form-actions">' +
              '<button class="btn btn--primary" type="button" data-result-action="approve"><i data-lucide="check"></i> Approve sheet</button>' +
              '<button class="btn btn--ghost" type="button" data-result-action="return"><i data-lucide="undo-2"></i> Return for correction</button>' +
              '<p class="form-msg" role="status"></p>' +
            "</div>"
        });
      } else {
        reviewPanel = callout(
          status === "approved" || status === "published" ? "info" : "warn",
          status === "approved" || status === "published"
            ? "<strong>Approved.</strong> This sheet is ready for term publication."
            : "<strong>Draft.</strong> The teacher is still preparing this sheet."
        );
      }
    }

    return pageHead({
      eyebrow: role === "admin" ? "Administration" : "Results entry",
      title: (sheet.class ? sheet.class.name : "Class") + " \u00B7 " + (sheet.subject ? sheet.subject.name : "Subject"),
      lead: (sheet.term ? sheet.term.label : SCHOOL.label) + " \u00B7 " + asNumber(sheet.entered) + " of " + asNumber(sheet.students) + " students entered.",
      actions: '<button class="btn btn--ghost" type="button" data-result-back><i data-lucide="arrow-left"></i> All result sheets</button>' +
        statusPill(statusLabel(status, sheet.status_label), statusTone(status))
    }) +
    statGrid([
      stat({ icon: "users", label: "Students", value: asNumber(sheet.students), meta: "Active in this class" }),
      stat({ icon: "file-check-2", label: "Entered", value: asNumber(sheet.entered), meta: "Students with scores" }),
      stat({ icon: "shield-check", label: "Status", value: statusLabel(status, sheet.status_label), meta: sheet.submitted_at ? "Submitted " + longDate(sheet.submitted_at) : "Not submitted" }),
      stat({ icon: "user-check", label: "Teacher", value: sheet.teacher || ROLES[state.role].shortName, meta: role === "admin" ? "Subject teacher" : "You" })
    ]) +
    (sheet.review_note ? callout("warn", "<strong>Administration note:</strong> " + esc(sheet.review_note)) : "") +
    panel({
      title: "Score sheet",
      sub: editable ? "Enter both scores for every student, then save or submit." : "Scores are read-only at this stage.",
      action: statusPill(statusLabel(status, sheet.status_label), statusTone(status)),
      body: body
    }) +
    reviewPanel;
  }

  async function loadResultSheetWorkspace() {
    var result = state.resultSheet;
    if (!result || result.loading || result.data) return;
    if (result.error) return;
    if (!result.id && !result.classId) return;

    result.loading = true;
    try {
      var payload;
      if (result.id) {
        payload = await API.get("/teacher/result-sheets/" + encodeURIComponent(result.id));
      } else {
        payload = await API.post("/teacher/result-sheets", {
          class_id: result.classId,
          subject_id: result.subjectId,
          term_id: DATA.dashboard && DATA.dashboard.term ? DATA.dashboard.term.id : null
        });
      }
      result.data = payload && payload.data ? payload.data : null;
      result.id = result.data && result.data.sheet ? result.data.sheet.id : result.id;
      if (state.role === "teacher" && result.data && result.data.sheet) {
        updateTeacherSheet(result.data.sheet);
      }
      result.loading = false;
      if ((state.role === "teacher" || state.role === "admin") && state.section === "results") {
        renderView();
        icons();
      }
    } catch (error) {
      result.loading = false;
      if (API.isAuthError(error)) return handleAuthFailure();
      result.error = API.message(error);
      if ((state.role === "teacher" || state.role === "admin") && state.section === "results") {
        renderView();
        icons();
      }
    }
  }

  function openResultSheet(button) {
    state.resultSheet = {
      id: button.getAttribute("data-sheet-id") || null,
      classId: button.getAttribute("data-class-id"),
      subjectId: button.getAttribute("data-subject-id"),
      status: button.getAttribute("data-status"),
      data: null,
      loading: false,
      error: null
    };
    if (window.location.hash === "#results") {
      renderView();
      icons();
    } else {
      navigate("results");
    }
  }

  function updateResultRowTotal(input) {
    var row = input.closest("[data-result-row]");
    if (!row) return;
    var ca = row.querySelector('[data-result-score="ca_score"]');
    var exam = row.querySelector('[data-result-score="exam_score"]');
    var totalCell = row.querySelector("[data-row-total]");
    if (!ca || !exam || !totalCell) return;
    var caValue = ca.value.trim();
    var examValue = exam.value.trim();
    totalCell.textContent = caValue !== "" && examValue !== ""
      ? numberText(Number(caValue) + Number(examValue), 2)
      : "\u2014";
  }

  async function saveResultSheet(form) {
    var result = state.resultSheet;
    if (!result || !result.data) return;
    var button = form.querySelector('button[type="submit"]');
    var rows = Array.prototype.slice.call(form.querySelectorAll("[data-result-row]"));
    var records = [];
    var invalid = false;

    setFormMessage(form, "");
    rows.forEach(function (row) {
      var ca = row.querySelector('[data-result-score="ca_score"]');
      var exam = row.querySelector('[data-result-score="exam_score"]');
      ca.classList.remove("is-invalid");
      exam.classList.remove("is-invalid");
      if (!ca || !exam) return;

      var caRaw = ca.value.trim();
      var examRaw = exam.value.trim();
      if (caRaw === "" && examRaw === "") return;
      var caValue = Number(caRaw);
      var examValue = Number(examRaw);

      if (caRaw === "" || !Number.isFinite(caValue) || caValue < 0 || caValue > 40) {
        ca.classList.add("is-invalid");
        invalid = true;
      }
      if (examRaw === "" || !Number.isFinite(examValue) || examValue < 0 || examValue > 60) {
        exam.classList.add("is-invalid");
        invalid = true;
      }
      if (caRaw !== "" && examRaw !== "" && Number.isFinite(caValue) && Number.isFinite(examValue) && caValue >= 0 && caValue <= 40 && examValue >= 0 && examValue <= 60) {
        records.push({
          student_id: row.getAttribute("data-student-id"),
          ca_score: caValue,
          exam_score: examValue
        });
      }
    });

    if (invalid) {
      setFormMessage(form, "Enter a complete CA score (0\u201340) and exam score (0\u201360) for each student.");
      return;
    }
    if (!records.length) {
      setFormMessage(form, "Enter at least one student's scores before saving.");
      return;
    }

    setButtonBusy(button, true, "Saving\u2026");
    try {
      var payload = await API.patch("/teacher/result-sheets/" + encodeURIComponent(result.data.sheet.id), { results: records });
      result.data = payload && payload.data ? payload.data : result.data;
      updateTeacherSheet(result.data.sheet);
      renderView();
      icons();
      toast("Result draft saved.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      setFormMessage(form, API.message(error));
      setButtonBusy(button, false);
    }
  }

  function updateTeacherSheet(sheet) {
    var match = TEACHER.resultSheets.filter(function (row) {
      return row.sheet_id === sheet.id || (row.class.id === sheet.class.id && row.subject.id === sheet.subject.id);
    })[0];
    if (!match) return;
    match.sheet_id = sheet.id;
    match.status = sheet.status;
    match.status_label = sheet.status_label;
    match.entered = sheet.entered;
    match.students = sheet.students;
    match.submitted_at = sheet.submitted_at;
    TEACHER.stats.sheets_outstanding = TEACHER.resultSheets.filter(function (row) {
      return row.status === "draft" || row.status === "not_started";
    }).length;
    setNavBadge("teacher", "results", TEACHER.stats.sheets_outstanding);
  }

  async function submitCurrentResultSheet(button) {
    var result = state.resultSheet;
    if (!result || !result.data) return;
    var sheet = result.data.sheet;
    if (!window.confirm("Submit " + sheet.class.name + " " + sheet.subject.name + " to the administration? You will not be able to edit it while it is under review.")) return;

    setButtonBusy(button, true, "Submitting\u2026");
    try {
      var payload = await API.post("/teacher/result-sheets/" + encodeURIComponent(sheet.id) + "/submit", {});
      result.data = payload && payload.data ? payload.data : result.data;
      updateTeacherSheet(result.data.sheet);
      renderView();
      icons();
      toast("Result sheet submitted for approval.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
      setButtonBusy(button, false);
    }
  }

  /* ============================================================
     21. ADMIN VIEWS
     ============================================================ */
  /* ============================================================
     20b. ADMIN MANAGEMENT HELPERS
     ============================================================ */
  var adminFieldSeq = 0;

  function adminField(label, control, full) {
    var nameMatch = /name="([^"]+)"/.exec(control);
    var id = "";
    if (nameMatch) {
      adminFieldSeq += 1;
      id = "adminField" + adminFieldSeq + "_" + nameMatch[1].replace(/[^A-Za-z0-9_-]/g, "");
      control = control.replace(/^<(input|select|textarea)\b/i, '<$1 id="' + id + '"');
    }
    return '<div class="field' + (full ? " full" : "") + '"><label' +
      (id ? ' for="' + id + '"' : "") + ">" + esc(label) + "</label>" + control + "</div>";
  }

  function adminInput(name, type, value, attrs) {
    return '<input name="' + esc(name) + '" type="' + esc(type) + '" value="' + esc(value == null ? "" : value) + '"' +
      (attrs ? " " + attrs : "") + ">";
  }

  function adminSelect(name, options, selected, attrs) {
    return '<select name="' + esc(name) + '"' + (attrs ? " " + attrs : "") + ">" +
      options.map(function (option) {
        return '<option value="' + esc(option.value) + '"' +
          (String(option.value) === String(selected == null ? "" : selected) ? " selected" : "") + ">" +
          esc(option.label) + "</option>";
      }).join("") + "</select>";
  }

  function adminTextarea(name, value, attrs) {
    return '<textarea name="' + esc(name) + '"' + (attrs ? " " + attrs : "") + ">" +
      esc(value == null ? "" : value) + "</textarea>";
  }

  function adminForm(marker, fields, submitLabel, hidden) {
    return '<form data-admin-form="' + marker + '" novalidate>' + (hidden || "") +
      '<div class="form-grid">' + fields + "</div>" +
      '<div class="form-actions"><button class="btn btn--primary" type="submit">' + submitLabel + "</button>" +
      '<p class="form-msg" role="status"></p></div></form>';
  }

  function openAdminDialog(title, eyebrow, body) {
    var dialog = document.getElementById("adminDialog");
    if (!dialog) return;
    dialog.querySelector("[data-dialog-title]").textContent = title;
    dialog.querySelector("[data-dialog-eyebrow]").textContent = eyebrow || "";
    dialog.querySelector("[data-dialog-body]").innerHTML = body;
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "open");
    icons();
    var first = dialog.querySelector(".admin-dialog__body input:not([type=hidden]):not([type=checkbox]), .admin-dialog__body select, .admin-dialog__body textarea");
    if (first) first.focus();
  }

  function closeAdminDialog() {
    var dialog = document.getElementById("adminDialog");
    if (!dialog) return;
    if (typeof dialog.close === "function" && dialog.open) dialog.close();
    else dialog.removeAttribute("open");
    dialog.querySelector("[data-dialog-body]").innerHTML = "";
  }

  function adminFormData(form) {
    var data = {};
    new FormData(form).forEach(function (value, key) {
      data[key] = typeof value === "string" ? value.trim() : value;
    });
    return data;
  }

  function markFormFields(form, fields) {
    if (!fields || typeof fields !== "object") return;
    Object.keys(fields).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (input) input.classList.add("is-invalid");
    });
  }

  function classOptions(selected) {
    return DATA.classes.map(function (classroom) {
      return { value: classroom.id, label: classroom.name };
    });
  }

  function termOptions(selected) {
    return DATA.terms.map(function (term) {
      return { value: term.id, label: term.label || (term.name + (term.session ? ", " + term.session.name : "")) };
    });
  }

  function subjectOptions() {
    return DATA.subjects.map(function (subject) {
      return { value: subject.id, label: subject.name };
    });
  }

  function teacherOptions(includeBlank) {
    var options = includeBlank === false ? [] : [{ value: "", label: "No teacher assigned" }];
    return options.concat(ADMIN.teachers.map(function (teacher) {
      return { value: teacher.id, label: teacher.name };
    }));
  }

  async function reloadAdminStudents() {
    var payload = await API.get("/admin/students?per_page=100");
    DATA.adminStudents = payload && payload.data ? payload.data : [];
    ADMIN.students = DATA.adminStudents;
  }

  async function reloadAdminAnnouncements() {
    var results = await Promise.allSettled([
      API.get("/admin/announcements"),
      API.get("/announcements")
    ]);
    if (results[0].status === "fulfilled") {
      DATA.adminAnnouncements = results[0].value && results[0].value.data ? results[0].value.data : [];
      ADMIN.announcements = DATA.adminAnnouncements;
    }
    if (results[1].status === "fulfilled" && results[1].value && results[1].value.data) {
      ANNOUNCEMENTS = normalizeAnnouncements(results[1].value.data);
    }
  }

  async function reloadAdminMessages() {
    var payload = await API.get("/admin/messages?per_page=100");
    DATA.adminMessages = payload && payload.data ? payload.data : [];
    ADMIN.messages = DATA.adminMessages;
  }

  async function reloadAdminSubscribers() {
    var payload = await API.get("/admin/subscribers?per_page=100");
    DATA.adminSubscribers = payload && payload.data ? payload.data : [];
    ADMIN.subscribers = DATA.adminSubscribers;
  }

  async function toggleAdminSubscriber(id, currentStatus) {
    var target = currentStatus === "subscribed" ? "unsubscribed" : "subscribed";
    var prompt = target === "subscribed"
      ? "Re-subscribe this email to the newsletter?"
      : "Unsubscribe this email from the newsletter?";
    if (!window.confirm(prompt)) return;

    try {
      var payload = await API.patch("/admin/subscribers/" + encodeURIComponent(id), { status: target });
      var fresh = payload && payload.data ? payload.data : null;
      if (fresh) {
        DATA.adminSubscribers = DATA.adminSubscribers.map(function (row) {
          return row.id === fresh.id ? fresh : row;
        });
        ADMIN.subscribers = DATA.adminSubscribers;
      }
      toast("Subscriber " + (target === "subscribed" ? "restored." : "unsubscribed."));
      renderView();
      icons();
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
    }
  }

  async function reloadAdminFinance() {
    var payload = await API.get("/admin/finance");
    DATA.adminFinance = payload && payload.data ? payload.data : null;
    ADMIN.finance = DATA.adminFinance;
  }

  function refreshAdminBadges() {
    setNavBadge("admin", "admissions", (ADMIN.admissions || []).filter(function (row) { return row.status === "new"; }).length || "");
    setNavBadge("admin", "messages", (ADMIN.messages || []).filter(function (row) { return row.status === "new"; }).length || "");
    renderSidebar();
  }

  function findById(list, id) {
    return (list || []).filter(function (row) { return row.id === id; })[0] || null;
  }

  function handleAdminAction(button) {
    var action = button.getAttribute("data-admin-action");
    var id = button.getAttribute("data-id");

    if (action === "student-create") return openStudentDialog(null);
    if (action === "student-edit") return openStudentDialog(findById(ADMIN.students, id));
    if (action === "announcement-create") return openAnnouncementDialog(null);
    if (action === "announcement-edit") return openAnnouncementDialog(findById(DATA.adminAnnouncements, id));
    if (action === "announcement-archive") return archiveAdminAnnouncement(id);
    if (action === "admission-edit") return openAdmissionDialog(findById(ADMIN.admissions, id));
    if (action === "admission-enroll") return openEnrollDialog(findById(ADMIN.admissions, id));
    if (action === "message-edit") return openMessageDialog(findById(DATA.adminMessages, id));
    if (action === "subscriber-toggle") return toggleAdminSubscriber(id, button.getAttribute("data-status"));
    if (action === "invoice-create") return openInvoiceDialog();
    if (action === "payment-create") return openPaymentDialog(button.getAttribute("data-invoice-id") || "");
    if (action === "timetable-add") {
      return openTimetableDialog(button.getAttribute("data-day"), button.getAttribute("data-period"));
    }
    if (action === "timetable-delete") return deleteTimetableSlot(id);
  }

  function studentFormBody(form) {
    var data = adminFormData(form);
    var body = {
      first_name: data.first_name,
      last_name: data.last_name,
      class_id: data.class_id,
      date_of_birth: data.date_of_birth || null,
      gender: data.gender || null,
      house: data.house || null,
      status: data.status,
      admitted_on: data.admitted_on || null
    };
    if (data.admission_no) body.admission_no = data.admission_no;
    return body;
  }

  async function submitAdminForm(form) {
    var kind = form.getAttribute("data-admin-form");
    var button = form.querySelector('button[type="submit"]');
    var data = adminFormData(form);

    setButtonBusy(button, true);
    try {
      if (kind === "student-create" || kind === "student-edit") {
        var studentBody = studentFormBody(form);
        if (kind === "student-create") {
          await API.post("/admin/students", studentBody);
          toast("Student record created.");
        } else {
          await API.patch("/admin/students/" + encodeURIComponent(data.id), studentBody);
          toast("Student record updated.");
        }
        await reloadAdminStudents();
      } else if (kind === "announcement-create" || kind === "announcement-edit") {
        var audiences = [];
        form.querySelectorAll('input[name="audiences"]:checked').forEach(function (box) {
          audiences.push(box.value);
        });
        var announcementBody = {
          title: data.title,
          body: data.body,
          category: data.category,
          audiences: audiences,
          status: data.status,
          is_pinned: !!(form.querySelector('[name="is_pinned"]') && form.querySelector('[name="is_pinned"]').checked)
        };
        if (kind === "announcement-create") {
          await API.post("/admin/announcements", announcementBody);
          toast("Announcement saved.");
        } else {
          await API.patch("/admin/announcements/" + encodeURIComponent(data.id), announcementBody);
          toast("Announcement updated.");
        }
        await reloadAdminAnnouncements();
      } else if (kind === "admission-edit") {
        var admission = await API.patch("/admin/admissions/" + encodeURIComponent(data.id), {
          status: data.status,
          notes: data.notes || null
        });
        var freshAdmission = admission && admission.data ? admission.data : null;
        if (freshAdmission) {
          DATA.adminAdmissions = DATA.adminAdmissions.map(function (row) {
            return row.id === freshAdmission.id ? freshAdmission : row;
          });
          ADMIN.admissions = DATA.adminAdmissions;
        }
        refreshAdminBadges();
        toast("Admission updated.");
      } else if (kind === "admission-enroll") {
        var enrolled = await API.post("/admin/admissions/" + encodeURIComponent(data.id) + "/enroll", {
          first_name: data.first_name,
          last_name: data.last_name,
          class_id: data.class_id,
          admission_no: data.admission_no || null,
          date_of_birth: data.date_of_birth || null,
          gender: data.gender || null,
          house: data.house || null,
          admitted_on: data.admitted_on || null
        });
        var enrolledAdmission = enrolled && enrolled.data ? enrolled.data : null;
        if (enrolledAdmission) {
          DATA.adminAdmissions = DATA.adminAdmissions.map(function (row) {
            return row.id === enrolledAdmission.id ? enrolledAdmission : row;
          });
          ADMIN.admissions = DATA.adminAdmissions;
        }
        await reloadAdminStudents();
        refreshAdminBadges();
        toast("Student enrolled and added to the register.");
      } else if (kind === "invoice-create") {
        await API.post("/admin/invoices", {
          student_id: data.student_id,
          term_id: data.term_id || null,
          description: data.description,
          amount: data.amount,
          issued_on: data.issued_on,
          due_on: data.due_on
        });
        await reloadAdminFinance();
        toast("Fee invoice created.");
      } else if (kind === "payment-create") {
        await API.post("/admin/payments", {
          invoice_id: data.invoice_id,
          amount: data.amount,
          method: data.method,
          paid_on: data.paid_on,
          reference: data.reference || null,
          note: data.note || null
        });
        await reloadAdminFinance();
        toast("Payment recorded.");
      } else if (kind === "timetable-add") {
        await API.post("/admin/timetable", {
          class_id: data.class_id,
          term_id: data.term_id,
          subject_id: data.subject_id,
          teacher_id: data.teacher_id || null,
          day_of_week: Number(data.day_of_week),
          period_no: Number(data.period_no),
          room: data.room
        });
        toast("Timetable slot added.");
        await loadAdminTimetable(true);
      } else if (kind === "message-edit") {
        var updated = await API.patch("/admin/messages/" + encodeURIComponent(data.id), { status: data.status });
        var freshMessage = updated && updated.data ? updated.data : null;
        if (freshMessage) {
          DATA.adminMessages = DATA.adminMessages.map(function (row) {
            return row.id === freshMessage.id ? freshMessage : row;
          });
          ADMIN.messages = DATA.adminMessages;
        }
        refreshAdminBadges();
        toast("Message status updated.");
      } else {
        throw new Error("Unknown admin form.");
      }

      closeAdminDialog();
      renderView();
      icons();
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      setFormMessage(form, API.message(error), "err");
      markFormFields(form, error && error.fields);
      setButtonBusy(button, false);
    }
  }

  function adminStudentsView() {
    var rows = ADMIN.students.map(function (student) {
      var classroom = student.class || {};
      var account = student.account || {};
      return [
        '<div class="cell-person"><strong>' + esc(student.name) + "</strong><span>" + esc(student.admission_no) + "</span></div>",
        esc(classroom.name || "\u2014"),
        esc(student.level || classroom.level_label || "\u2014"),
        esc(account.email || "No portal account"),
        statusPill(statusLabel(student.status), student.status === "active" ? "ok" : "info"),
        '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="student-edit" data-id="' + esc(student.id) + '">Edit</button>'
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Students",
      lead: "The live student register for " + esc(SCHOOL.session || "the current session") + ".",
      actions: '<button class="btn btn--gold" type="button" data-admin-action="student-create"><i data-lucide="user-plus"></i> Add student</button>' +
        statusPill(ADMIN.students.length + " records", "info")
    }) +
    statGrid([
      stat({ icon: "users", label: "Students", value: ADMIN.students.length, meta: "All records" }),
      stat({ icon: "graduation-cap", label: "Nursery / Primary", value: ADMIN.students.filter(function (s) { return s.level === "Nursery" || s.level === "Primary"; }).length, meta: "Foundation years" }),
      stat({ icon: "school", label: "Secondary", value: ADMIN.students.filter(function (s) { return s.level === "Junior Secondary" || s.level === "Senior Secondary"; }).length, meta: "Junior and senior" }),
      stat({ icon: "user-check", label: "Portal accounts", value: ADMIN.students.filter(function (s) { return s.account && s.account.email; }).length, meta: "Active sign-in access" })
    ]) +
    panel({
      title: "Student register",
      sub: "Name, class, level and portal account",
      body: rows.length
        ? table([{ label: "Student" }, { label: "Class" }, { label: "Level" }, { label: "Portal email" }, { label: "Status" }, { label: "Action", align: "right" }], rows, "Student register")
        : emptyState("users", "No student records", "Student records will appear here once they are enrolled.")
    });
  }

  function openStudentDialog(student) {
    if (!DATA.classes.length) {
      toast("Create a class before adding students.");
      return;
    }
    var editing = !!student;
    var currentClassId = student && student.class ? student.class.id : DATA.classes[0].id;
    var statuses = [
      { value: "active", label: "Active" },
      { value: "graduated", label: "Graduated" },
      { value: "withdrawn", label: "Withdrawn" }
    ];
    var genders = [
      { value: "", label: "Not specified" },
      { value: "male", label: "Male" },
      { value: "female", label: "Female" }
    ];
    var houses = [
      { value: "", label: "Not assigned" },
      { value: "Green", label: "Green" },
      { value: "Gold", label: "Gold" },
      { value: "Blue", label: "Blue" },
      { value: "Red", label: "Red" }
    ];

    var body = adminForm(
      editing ? "student-edit" : "student-create",
      (editing ? adminInput("id", "hidden", student.id) : "") +
        adminField("First name", adminInput("first_name", "text", editing ? student.first_name : "", 'maxlength="80" required')) +
        adminField("Last name", adminInput("last_name", "text", editing ? student.last_name : "", 'maxlength="80" required')) +
        adminField("Admission number", adminInput("admission_no", "text", editing ? student.admission_no : "", 'maxlength="30" placeholder="Leave blank to generate"')) +
        adminField("Class", adminSelect("class_id", classOptions(), currentClassId, "required")) +
        adminField("Date of birth", adminInput("date_of_birth", "date", editing ? student.date_of_birth || "" : "")) +
        adminField("Gender", adminSelect("gender", genders, editing ? student.gender || "" : "")) +
        adminField("House", adminSelect("house", houses, editing ? student.house || "" : "")) +
        adminField("Status", adminSelect("status", statuses, editing ? student.status : "active")) +
        adminField("Admitted on", adminInput("admitted_on", "date", editing ? student.admitted_on || "" : todayISO())),
      editing ? "Save student" : "Add student"
    );

    openAdminDialog(editing ? "Edit student" : "Add student", "Student register", body);
  }

  function adminStaffView() {
    var rows = ADMIN.teachers.map(function (teacher) {
      return ['<div class="cell-person"><strong>' + esc(teacher.name) + "</strong><span>Teaching staff</span></div>", esc(teacher.email)];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Staff",
      lead: "Teaching staff with active portal accounts.",
      actions: statusPill(ADMIN.teachers.length + " teachers", "info")
    }) +
    statGrid([
      stat({ icon: "id-card", label: "Total staff", value: asNumber(ADMIN.stats.staff), meta: "Teaching and non-teaching" }),
      stat({ icon: "presentation", label: "Teaching staff", value: asNumber(ADMIN.stats.teachers), meta: "Active teachers" }),
      stat({ icon: "users", label: "Students", value: asNumber(ADMIN.stats.students), meta: "Across all levels" }),
      stat({ icon: "calendar-check", label: "Attendance today", value: ADMIN.stats.attendance + "%", meta: longDate(ADMIN.stats.attendanceDate) })
    ]) +
    panel({
      title: "Teaching staff",
      sub: "Names and staff email addresses",
      body: rows.length
        ? table([{ label: "Teacher" }, { label: "Email" }], rows, "Teaching staff")
        : emptyState("id-card", "No staff records", "Teaching staff will appear here once accounts are created.")
    });
  }

  function adminAcademicsView() {
    var timetable = adminTimetableState();
    var classRows = DATA.classes.map(function (classroom) {
      return [
        "<strong>" + esc(classroom.name) + "</strong>",
        esc(classroom.level_label || classroom.level || ""),
        esc(classroom.form_teacher || "Not assigned"),
        asNumber(classroom.students)
      ];
    });
    var termRows = DATA.terms.map(function (term) {
      return [
        "<strong>" + esc(term.label || (term.name + ", " + (term.session ? term.session.name : ""))) + "</strong>",
        longDate(term.starts_on) + " \u2013 " + longDate(term.ends_on),
        term.is_current ? statusPill("Current", "gold") : '<span class="muted">Closed</span>',
        term.results_published_at ? statusPill("Results published", "ok") : statusPill("Not published", "info")
      ];
    });

    var canBuild = DATA.classes.length > 0 && DATA.terms.length > 0 && DATA.subjects.length > 0;
    var classSelect = adminSelect("class_id", classOptions(), timetable.classId, 'id="ttClass"' + (canBuild ? " required" : " disabled"));
    var termSelect = adminSelect("term_id", termOptions(), timetable.termId, 'id="ttTerm"' + (canBuild ? " required" : " disabled"));

    return pageHead({
      eyebrow: "Administration",
      title: "Academics",
      lead: "Build class timetables and review form teachers and academic terms.",
      actions: (canBuild
          ? '<button class="btn btn--gold" type="button" data-admin-action="timetable-add"><i data-lucide="calendar-plus"></i> Add lesson</button>'
          : "") +
        statusPill(DATA.classes.length + " classes", "info")
    }) +
    panel({
      title: "Timetable workspace",
      sub: "Choose a class and term, then add or remove lessons",
      body: '<form class="admin-toolbar" data-admin-timetable-filter>' +
          '<div class="field"><label for="ttClass">Class</label>' + classSelect + "</div>" +
          '<div class="field"><label for="ttTerm">Term</label>' + termSelect + "</div>" +
          '<div class="field admin-toolbar__action"><button class="btn btn--ghost" type="button" data-tt-refresh><i data-lucide="refresh-cw"></i> Refresh</button></div>' +
        "</form>" +
        '<div id="adminTimetableWorkspace" class="admin-timetable-workspace">' + adminTimetableWorkspace() + "</div>"
    }) +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Classes",
        sub: "Form teacher and active enrolment",
        body: classRows.length
          ? table([{ label: "Class" }, { label: "Level" }, { label: "Form teacher" }, { label: "Students", align: "right" }], classRows, "Classes")
          : emptyState("school", "No classes", "Class records will appear here.")
      }) +
      panel({
        title: "Academic terms",
        sub: "Sessions and publication status",
        body: termRows.length
          ? table([{ label: "Term" }, { label: "Dates" }, { label: "Status" }, { label: "Results" }], termRows, "Academic terms")
          : emptyState("calendar-days", "No terms", "Academic terms will appear here.")
      }) +
    "</div>";
  }

  /* ============================================================
     21b. ADMIN ANNOUNCEMENTS
     ============================================================ */
  function audienceLabel(audience) {
    return {
      student: "Students",
      parent: "Parents",
      teacher: "Teachers",
      admin: "Administrators"
    }[audience] || statusLabel(audience);
  }

  function adminAnnouncementsView() {
    var list = Array.isArray(DATA.adminAnnouncements) ? DATA.adminAnnouncements : [];
    var rows = list.map(function (item) {
      var audienceText = (item.audiences || []).map(audienceLabel).join(", ") || "\u2014";
      return [
        '<div class="cell-person"><strong>' + esc(item.title) + "</strong><span>" + esc(item.tag || "General") + "</span></div>",
        esc(audienceText),
        statusPill(statusLabel(item.status), item.status === "published" ? "ok" : item.status === "archived" ? "info" : "warn"),
        esc(item.author || "\u2014"),
        item.published_at ? longDate(item.published_at) : '<span class="muted">Not published</span>',
        '<div class="row-actions">' +
          '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="announcement-edit" data-id="' + esc(item.id) + '">Edit</button>' +
          (item.status === "archived"
            ? ""
            : '<button class="btn btn--ghost btn--sm is-danger" type="button" data-admin-action="announcement-archive" data-id="' + esc(item.id) + '">Archive</button>') +
        "</div>"
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Announcements",
      lead: "Publish notices to students, parents, teachers and administrators.",
      actions: '<button class="btn btn--gold" type="button" data-admin-action="announcement-create"><i data-lucide="megaphone"></i> New announcement</button>' +
        statusPill(list.length + " notices", "info")
    }) +
    panel({
      title: "All announcements",
      sub: "Drafts, published notices and archived posts",
      body: rows.length
        ? table(
            [{ label: "Notice" }, { label: "Audience" }, { label: "Status" }, { label: "Author" }, { label: "Published" }, { label: "Action", align: "right" }],
            rows,
            "All announcements"
          )
        : emptyState("megaphone", "No announcements yet", "Create the first notice for the school community.")
    });
  }

  async function archiveAdminAnnouncement(id) {
    if (!window.confirm("Archive this announcement? It will no longer be visible to families.")) return;
    try {
      await API.del("/admin/announcements/" + encodeURIComponent(id));
      await reloadAdminAnnouncements();
      renderView();
      icons();
      toast("Announcement archived.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
    }
  }

  function openAnnouncementDialog(announcement) {
    var editing = !!announcement;
    var statuses = editing
      ? [{ value: "draft", label: "Draft" }, { value: "published", label: "Published" }, { value: "archived", label: "Archived" }]
      : [{ value: "draft", label: "Draft" }, { value: "published", label: "Publish now" }];
    var selectedAudiences = announcement && Array.isArray(announcement.audiences) ? announcement.audiences : ["student", "parent"];
    var audienceChecks = ["student", "parent", "teacher", "admin"].map(function (audience) {
      return '<label class="check"><input type="checkbox" name="audiences" value="' + audience + '"' +
        (selectedAudiences.indexOf(audience) !== -1 ? " checked" : "") + "> " + esc(audienceLabel(audience)) + "</label>";
    }).join("");

    var body = adminForm(
      editing ? "announcement-edit" : "announcement-create",
      (editing ? adminInput("id", "hidden", announcement.id) : "") +
        adminField("Title", adminInput("title", "text", editing ? announcement.title : "", 'maxlength="160" required'), true) +
        adminField("Category", adminInput("category", "text", editing ? announcement.tag : "School", 'maxlength="30" list="announcementCategories" required'), true) +
        '<datalist id="announcementCategories"><option value="School"><option value="Academics"><option value="Events"><option value="Admissions"><option value="Staff"><option value="Welfare"></datalist>' +
        adminField("Message", adminTextarea("body", editing ? announcement.body : "", 'rows="6" maxlength="5000" required'), true) +
        '<fieldset class="field full admin-fieldset"><legend>Audience</legend><div class="check-grid">' + audienceChecks + "</div></fieldset>" +
        adminField("Status", adminSelect("status", statuses, editing ? announcement.status : "draft")) +
        adminField("Pin to top", '<label class="check"><input type="checkbox" name="is_pinned"' +
          (editing && announcement.pinned ? " checked" : "") + "> Show this notice above others</label>"),
      editing ? "Save announcement" : "Create announcement"
    );

    openAdminDialog(editing ? "Edit announcement" : "New announcement", "School community", body);
  }

  /* ============================================================
     21c. ADMIN MESSAGES
     ============================================================ */
  function messageTone(status) {
    return {
      new: "gold",
      in_progress: "warn",
      resolved: "ok",
      archived: "info"
    }[status] || "info";
  }

  function adminMessagesView() {
    var list = Array.isArray(ADMIN.messages) ? ADMIN.messages : [];
    var counts = { new: 0, in_progress: 0, resolved: 0, archived: 0 };
    list.forEach(function (row) { counts[row.status] = (counts[row.status] || 0) + 1; });

    var rows = list.map(function (row) {
      var excerpt = String(row.message || "");
      if (excerpt.length > 120) excerpt = excerpt.slice(0, 117) + "\u2026";
      return [
        '<div class="cell-person"><strong>' + esc(row.name) + "</strong><span>" + esc(row.email) + (row.phone ? " \u00B7 " + esc(row.phone) : "") + "</span></div>",
        '<div class="cell-person"><strong>' + esc(row.subject) + "</strong><span>" + esc(excerpt) + "</span></div>",
        statusPill(row.status_label || statusLabel(row.status), messageTone(row.status)),
        longDate(row.created_at),
        '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="message-edit" data-id="' + esc(row.id) + '">Manage</button>'
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Messages",
      lead: "Enquiries submitted through the public contact form.",
      actions: statusPill(counts.new + " new", counts.new ? "gold" : "info")
    }) +
    statGrid([
      stat({ icon: "mail", label: "New messages", value: counts.new, meta: "Awaiting first response", alert: counts.new > 0 }),
      stat({ icon: "message-circle", label: "In progress", value: counts.in_progress, meta: "Being handled" }),
      stat({ icon: "check-circle-2", label: "Resolved", value: counts.resolved, meta: "Closed successfully" }),
      stat({ icon: "archive", label: "Archived", value: counts.archived, meta: "Kept for records" })
    ]) +
    panel({
      title: "Contact inbox",
      sub: list.length + " message" + (list.length === 1 ? "" : "s"),
      body: rows.length
        ? table(
            [{ label: "Sender" }, { label: "Message" }, { label: "Status" }, { label: "Received" }, { label: "Action", align: "right" }],
            rows,
            "Contact messages"
          )
        : emptyState("mail", "No messages yet", "Enquiries from the public contact form will appear here.")
    });
  }

  function openMessageDialog(message) {
    if (!message) return;
    var statuses = [
      { value: "new", label: "New" },
      { value: "in_progress", label: "In progress" },
      { value: "resolved", label: "Resolved" },
      { value: "archived", label: "Archived" }
    ];
    var body =
      '<div class="admin-message">' +
        '<dl class="admin-message__meta">' +
          "<div><dt>From</dt><dd>" + esc(message.name) + "</dd></div>" +
          "<div><dt>Email</dt><dd><a href=\"mailto:" + esc(message.email) + "\">" + esc(message.email) + "</a></dd></div>" +
          "<div><dt>Phone</dt><dd>" + esc(message.phone || "\u2014") + "</dd></div>" +
          "<div><dt>Received</dt><dd>" + longDate(message.created_at) + "</dd></div>" +
        "</dl>" +
        "<h3>" + esc(message.subject) + "</h3>" +
        '<p class="admin-message__body">' + esc(message.message) + "</p>" +
      "</div>" +
      adminForm(
        "message-edit",
        adminInput("id", "hidden", message.id) +
          adminField("Status", adminSelect("status", statuses, message.status)),
        "Update message"
      );

    openAdminDialog("Contact message", "Message inbox", body);
  }

  /* ============================================================
     21d. ADMIN NEWSLETTER SUBSCRIBERS
     ============================================================ */
  function adminSubscribersView() {
    var list = Array.isArray(ADMIN.subscribers) ? ADMIN.subscribers : [];
    var subscribed = list.filter(function (row) { return row.status === "subscribed"; });
    var unsubscribed = list.filter(function (row) { return row.status === "unsubscribed"; });

    var rows = list.map(function (row) {
      var isActive = row.status === "subscribed";
      return [
        '<div class="cell-person"><strong>' + esc(row.email) + "</strong><span>" +
          (row.source ? "Via " + esc(row.source) : "Website signup") + "</span></div>",
        statusPill(row.status_label || statusLabel(row.status), isActive ? "ok" : "info"),
        esc(row.source ? row.source.replace(/^./, function (ch) { return ch.toUpperCase(); }) : "\u2014"),
        longDate(row.created_at),
        '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="subscriber-toggle" data-id="' +
          esc(row.id) + '" data-status="' + esc(row.status) + '">' +
          (isActive ? "Unsubscribe" : "Subscribe") + "</button>"
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Newsletter",
      lead: "Email addresses collected from the footer signup forms across the website.",
      actions: statusPill(subscribed.length + " active", subscribed.length ? "ok" : "info")
    }) +
    statGrid([
      stat({ icon: "send", label: "Active subscribers", value: subscribed.length, meta: "Receiving school updates", alert: subscribed.length > 0 }),
      stat({ icon: "users", label: "Total signups", value: list.length, meta: "All time" }),
      stat({ icon: "user-minus", label: "Unsubscribed", value: unsubscribed.length, meta: "Opted out" }),
      stat({ icon: "globe", label: "Source", value: "Website", meta: "Footer signup forms" })
    ]) +
    panel({
      title: "Subscriber list",
      sub: list.length + " address" + (list.length === 1 ? "" : "es"),
      body: rows.length
        ? table(
            [{ label: "Email" }, { label: "Status" }, { label: "Source" }, { label: "Added" }, { label: "Action", align: "right" }],
            rows,
            "Newsletter subscribers"
          )
        : emptyState("send", "No subscribers yet", "Emails entered in the website footer will appear here.")
    });
  }

  /* ============================================================
     21e. ADMIN TIMETABLE BUILDER
     ============================================================ */
  function adminTimetableState() {
    if (!state.adminTimetable) {
      var currentTerm = DATA.terms.filter(function (term) { return term.is_current; })[0] || DATA.terms[0];
      state.adminTimetable = {
        classId: DATA.classes[0] ? DATA.classes[0].id : "",
        termId: currentTerm ? currentTerm.id : "",
        data: null,
        loadedKey: "",
        loading: false,
        error: null
      };
    }
    return state.adminTimetable;
  }

  function adminTimetableWorkspace() {
    var timetable = adminTimetableState();
    if (!DATA.classes.length || !DATA.terms.length || !DATA.subjects.length) {
      return emptyState("calendar-days", "Set up classes, terms and subjects first", "Add at least one class, one academic term and one subject before building a timetable.");
    }
    if (timetable.error) {
      return '<div class="empty-state"><span class="empty-state__icon"><i data-lucide="cloud-off"></i></span>' +
        "<h3>The timetable could not be loaded</h3><p>" + esc(timetable.error) + "</p>" +
        '<div class="empty-state__actions"><button class="btn btn--primary" type="button" data-tt-refresh><i data-lucide="refresh-cw"></i> Try again</button></div></div>';
    }
    if (timetable.loading || !timetable.data || timetable.loadedKey !== timetable.classId + "|" + timetable.termId) {
      return '<div class="view-loading"><span class="loading-dot" aria-hidden="true"></span> Loading timetable\u2026</div>';
    }

    var days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
    var payload = timetable.data.timetable || {};
    var periods = Array.isArray(payload.periods) ? payload.periods : [];
    var head = '<th scope="col">Period</th>' + days.map(function (day) {
      return '<th scope="col">' + esc(day) + "</th>";
    }).join("");

    var body = periods.map(function (period) {
      var cells = days.map(function (day) {
        var dayIndex = dayNames.indexOf(day);
        var slots = Array.isArray(payload.days && payload.days[day]) ? payload.days[day] : [];
        var slot = slots[period.period - 1] || null;
        if (!slot) {
          return '<td><button class="tt-add" type="button" data-admin-action="timetable-add" data-day="' + (dayIndex + 1) +
            '" data-period="' + esc(period.period) + '" aria-label="Add lesson for ' + esc(day) + " " + esc(period.label) + '">' +
            '<i data-lucide="plus"></i><span>Add</span></button></td>';
        }
        return "<td>" +
          '<div class="tt__cell"><strong>' + esc(slot.subject ? slot.subject.name : "Lesson") + "</strong>" +
            "<span>" + esc(slot.teacher || "Unassigned") + "</span>" +
            "<small>" + esc(slot.room || "") + "</small>" +
            '<button class="tt-remove" type="button" data-admin-action="timetable-delete" data-id="' + esc(slot.id) +
              '" aria-label="Remove ' + esc(slot.subject ? slot.subject.name : "lesson") + " on " + esc(day) + '"><i data-lucide="x"></i></button>' +
          "</div></td>";
      }).join("");
      return '<tr><th scope="row"><span class="tt__period">' + esc(period.label) + "</span>" +
        '<span class="tt__time">' + esc(period.time || "") + "</span></th>" + cells + "</tr>";
    }).join("");

    if (!periods.length) {
      return emptyState("calendar-days", "No periods defined", "The timetable periods could not be loaded.");
    }

    return '<div class="table-wrap" tabindex="0" role="region" aria-label="Timetable builder">' +
      '<table class="data-table timetable timetable--admin">' +
        "<thead><tr>" + head + "</tr></thead><tbody>" + body + "</tbody></table></div>" +
      '<p class="small muted mt-2">Each class has six periods a day, Monday to Friday. Click <strong>Add</strong> in an empty period to schedule a lesson, or the close icon to remove one.</p>';
  }

  function renderAdminTimetableWorkspace() {
    var workspace = document.getElementById("adminTimetableWorkspace");
    if (!workspace) return;
    workspace.innerHTML = adminTimetableWorkspace();
    icons();
  }

  async function loadAdminTimetable(force) {
    var timetable = adminTimetableState();
    if (!timetable.classId || !timetable.termId) {
      renderAdminTimetableWorkspace();
      return;
    }
    var key = timetable.classId + "|" + timetable.termId;
    if (!force && timetable.data && timetable.loadedKey === key) return;
    if (timetable.loading) return;

    timetable.loading = true;
    timetable.error = null;
    renderAdminTimetableWorkspace();

    try {
      var payload = await API.get("/admin/timetable?class_id=" + encodeURIComponent(timetable.classId) +
        "&term_id=" + encodeURIComponent(timetable.termId));
      timetable.data = payload && payload.data ? payload.data : null;
      timetable.loadedKey = key;
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      timetable.error = API.message(error);
    } finally {
      timetable.loading = false;
      renderAdminTimetableWorkspace();
    }
  }

  function openTimetableDialog(day, period) {
    var timetable = adminTimetableState();
    if (!timetable.classId || !timetable.termId) {
      toast("Choose a class and term first.");
      return;
    }
    var days = [
      { value: 1, label: "Monday" },
      { value: 2, label: "Tuesday" },
      { value: 3, label: "Wednesday" },
      { value: 4, label: "Thursday" },
      { value: 5, label: "Friday" }
    ];
    var periods = [1, 2, 3, 4, 5, 6].map(function (number) {
      return { value: number, label: "Period " + number };
    });
    var selectedDay = day ? Number(day) : 1;
    var selectedPeriod = period ? Number(period) : 1;
    if (timetable.data && timetable.data.timetable) {
      var occupied = timetable.data.timetable.slots || [];
      var free = periods.filter(function (candidate) {
        return !occupied.some(function (slot) {
          return slot.day === dayNames[selectedDay - 1] && Number(slot.period) === Number(candidate.value);
        });
      });
      if (free.length && free.every(function (candidate) { return Number(candidate.value) !== selectedPeriod; })) {
        selectedPeriod = Number(free[0].value);
      }
    }

    var body = adminForm(
      "timetable-add",
      adminField("Class", adminSelect("class_id", classOptions(), timetable.classId, "required")) +
        adminField("Term", adminSelect("term_id", termOptions(), timetable.termId, "required")) +
        adminField("Subject", adminSelect("subject_id", subjectOptions(), "", "required")) +
        adminField("Teacher", adminSelect("teacher_id", teacherOptions())) +
        adminField("Day", adminSelect("day_of_week", days, selectedDay, "required")) +
        adminField("Period", adminSelect("period_no", periods, selectedPeriod, "required")) +
        adminField("Room", adminInput("room", "text", "", 'maxlength="60" placeholder="e.g. Room 4" required'), true),
      "Add lesson"
    );

    openAdminDialog("Add timetable lesson", "Timetable builder", body);
  }

  async function deleteTimetableSlot(id) {
    if (!window.confirm("Remove this lesson from the timetable?")) return;
    try {
      await API.del("/admin/timetable/" + encodeURIComponent(id));
      toast("Timetable lesson removed.");
      await loadAdminTimetable(true);
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
    }
  }

  function admissionTone(status) {
    return {
      new: "gold",
      assessment: "warn",
      offer: "info",
      enrolled: "ok",
      declined: "danger"
    }[status] || "info";
  }

  function adminAdmissionsView() {
    var admissions = ADMIN.admissions;
    var counts = {};
    admissions.forEach(function (row) { counts[row.status] = (counts[row.status] || 0) + 1; });
    var rows = admissions.map(function (row) {
      return [
        '<div class="cell-person"><strong>' + esc(row.applicant) + "</strong><span>" + esc(row.email) + "</span></div>",
        esc(row.guardian || "\u2014"),
        esc(row.level_label || row.level || "\u2014"),
        statusPill(row.status_label || statusLabel(row.status), admissionTone(row.status)),
        longDate(row.created_at),
        esc(row.notes || "\u2014"),
        '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="admission-edit" data-id="' + esc(row.id) + '">Manage</button>'
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Admissions",
      lead: "Applications and the current admissions pipeline.",
      actions: statusPill(admissions.length + " applications", "info")
    }) +
    statGrid([
      stat({ icon: "user-plus", label: "New applications", value: counts.new || 0, meta: "Awaiting first contact", alert: (counts.new || 0) > 0 }),
      stat({ icon: "calendar-check", label: "Assessment", value: counts.assessment || 0, meta: "Booked for assessment" }),
      stat({ icon: "file-check-2", label: "Offers issued", value: counts.offer || 0, meta: "Awaiting family response" }),
      stat({ icon: "check-circle-2", label: "Enrolled", value: counts.enrolled || 0, meta: "Joined the school" })
    ]) +
    panel({
      title: "Applications",
      sub: "Newest applications first",
      body: rows.length
        ? table(
            [{ label: "Applicant" }, { label: "Guardian" }, { label: "Level" }, { label: "Status" }, { label: "Received" }, { label: "Notes" }, { label: "Action", align: "right" }],
            rows,
            "Admissions applications"
          )
        : emptyState("user-plus", "No applications", "New admission enquiries will appear here.")
    });
  }

  function openAdmissionDialog(admission) {
    if (!admission) return;
    var statuses = [
      { value: "new", label: "New" },
      { value: "assessment", label: "Assessment" },
      { value: "offer", label: "Offer issued" },
      { value: "enrolled", label: "Enrolled" },
      { value: "declined", label: "Declined" }
    ];
    var facts = [
      ["Guardian", admission.guardian || "\u2014"],
      ["Email", admission.email || "\u2014"],
      ["Phone", admission.phone || "\u2014"],
      ["Level", admission.level_label || admission.level || "\u2014"],
      ["Preferred class", admission.class_label || "\u2014"],
      ["Date of birth", admission.date_of_birth ? longDate(admission.date_of_birth) : "\u2014"],
      ["Gender", admission.gender ? statusLabel(admission.gender) : "\u2014"],
      ["Previous school", admission.previous_school || "\u2014"],
      ["Student record", admission.student
        ? admission.student.name + " (" + admission.student.admission_no + ", " + admission.student.class + ")"
        : (admission.status === "enrolled" ? "No linked student record" : "Not enrolled yet")]
    ];
    var canEnroll = !admission.student && (admission.status === "offer" || admission.status === "enrolled");
    var callout = canEnroll
      ? '<div class="admin-callout">' +
          "<div><strong>Create the student record</strong>" +
          "<p>Convert this application into an active student. You can adjust the name, class and admission number first.</p></div>" +
          '<button class="btn btn--gold" type="button" data-admin-action="admission-enroll" data-id="' +
            esc(admission.id) + '"><i data-lucide="user-check"></i> Enroll student</button>' +
        "</div>"
      : "";
    var body =
      '<div class="admin-facts">' + facts.map(function (fact) {
        return '<div><span>' + esc(fact[0]) + "</span><strong>" + esc(fact[1]) + "</strong></div>";
      }).join("") + "</div>" +
      callout +
      adminForm(
        "admission-edit",
        adminInput("id", "hidden", admission.id) +
          adminField("Status", adminSelect("status", statuses, admission.status)) +
          adminField("Notes", adminTextarea("notes", admission.notes || "", 'rows="5" maxlength="4000"'), true),
        "Save admission"
      );

    openAdminDialog(admission.applicant, "Admission application", body);
  }

  function splitApplicantName(name) {
    var parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    var first = parts.shift() || "";
    return [first, parts.join(" ")];
  }

  function admissionClassOptions(level) {
    var matching = DATA.classes.filter(function (classroom) { return classroom.level === level; });
    var list = matching.length ? matching : DATA.classes;
    return list.map(function (classroom) {
      return { value: classroom.id, label: classroom.name };
    });
  }

  function openEnrollDialog(admission) {
    if (!admission || admission.student) return;
    if (!DATA.classes.length) {
      toast("Create a class before enrolling students.");
      return;
    }

    var names = splitApplicantName(admission.applicant);
    var classes = admissionClassOptions(admission.level);
    var genders = [
      { value: "", label: "Not specified" },
      { value: "male", label: "Male" },
      { value: "female", label: "Female" }
    ];
    var houses = [
      { value: "", label: "Not assigned" },
      { value: "Green", label: "Green" },
      { value: "Gold", label: "Gold" },
      { value: "Blue", label: "Blue" },
      { value: "Red", label: "Red" }
    ];

    var body = adminForm(
      "admission-enroll",
      adminInput("id", "hidden", admission.id) +
        adminField("First name", adminInput("first_name", "text", names[0], 'maxlength="80" required')) +
        adminField("Last name", adminInput("last_name", "text", names[1], 'maxlength="80" required')) +
        adminField("Class", adminSelect("class_id", classes, classes[0].value, "required")) +
        adminField("Admission number", adminInput("admission_no", "text", "", 'maxlength="30" placeholder="Leave blank to generate"')) +
        adminField("Date of birth", adminInput("date_of_birth", "date", admission.date_of_birth || "")) +
        adminField("Gender", adminSelect("gender", genders, admission.gender || "")) +
        adminField("House", adminSelect("house", houses, "")) +
        adminField("Admitted on", adminInput("admitted_on", "date", todayISO())),
      "Enroll student"
    );

    openAdminDialog("Enroll " + admission.applicant, "Admission \u2192 Student", body);
  }

  function financeTone(status) {
    return {
      paid: "ok",
      partial: "warn",
      unpaid: "danger",
      overdue: "danger"
    }[status] || "info";
  }

  function adminFinanceView() {
    var finance = ADMIN.finance || {};
    var summary = finance.summary || {};
    var invoices = Array.isArray(finance.invoices) ? finance.invoices : [];
    var payments = Array.isArray(finance.recent_payments) ? finance.recent_payments : [];
    var visibleInvoices = invoices.slice(0, 30);

    var invoiceRows = visibleInvoices.map(function (invoice) {
      return [
        '<div class="cell-person"><strong>' + esc(invoice.student ? invoice.student.name : "\u2014") + "</strong><span>" + esc(invoice.ref) + "</span></div>",
        esc(invoice.item || "\u2014"),
        money(invoice.amount),
        money(invoice.paid),
        money(invoice.balance),
        statusPill(invoice.status_label || statusLabel(invoice.status), financeTone(invoice.status)),
        longDate(invoice.due_on),
        invoice.balance > 0
          ? '<button class="btn btn--ghost btn--sm" type="button" data-admin-action="payment-create" data-invoice-id="' + esc(invoice.id) + '">Record payment</button>'
          : '<span class="muted">Settled</span>'
      ];
    });

    var paymentRows = payments.map(function (payment) {
      return [
        '<div class="cell-person"><strong>' + esc(payment.student ? payment.student.name : "\u2014") + "</strong><span>" + esc(payment.ref) + "</span></div>",
        money(payment.amount),
        esc(payment.method || "\u2014"),
        longDate(payment.paid_on)
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Fees & finance",
      lead: "Term billing, collections and recent receipts.",
      actions: '<button class="btn btn--gold" type="button" data-admin-action="invoice-create"><i data-lucide="file-plus"></i> New invoice</button>' +
        '<button class="btn btn--ghost" type="button" data-admin-action="payment-create"><i data-lucide="receipt"></i> Record payment</button>' +
        '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(SCHOOL.label || SCHOOL.term) + "</span>"
    }) +
    statGrid([
      stat({ icon: "wallet", label: "Billed", value: compactMoney(summary.billed), meta: "Invoices this term" }),
      stat({ icon: "trending-up", label: "Collected", value: compactMoney(summary.collected), meta: numberText(summary.collection_rate, 0) + "% collection rate" }),
      stat({ icon: "alert-circle", label: "Outstanding", value: compactMoney(summary.outstanding), meta: "Still to be settled", alert: asNumber(summary.outstanding) > 0 }),
      stat({ icon: "receipt", label: "Recent payments", value: payments.length, meta: "Latest receipted payments" })
    ]) +
    panel({
      title: "Fee invoices",
      sub: invoices.length > visibleInvoices.length
        ? "Showing the latest " + visibleInvoices.length + " of " + invoices.length
        : invoices.length + " invoices",
      body: invoiceRows.length
        ? table(
            [{ label: "Student" }, { label: "Item" }, { label: "Amount", align: "right" }, { label: "Paid", align: "right" }, { label: "Balance", align: "right" }, { label: "Status" }, { label: "Due" }, { label: "Action", align: "right" }],
            invoiceRows,
            "Fee invoices"
          )
        : emptyState("wallet", "No invoices", "Term invoices will appear here once raised.")
    }) +
    panel({
      title: "Recent payments",
      sub: "Latest receipted transactions",
      body: paymentRows.length
        ? table([{ label: "Student" }, { label: "Amount", align: "right" }, { label: "Method" }, { label: "Date" }], paymentRows, "Recent payments")
        : emptyState("receipt", "No payments", "Receipted payments will appear here.")
    });
  }

  function openInvoiceDialog() {
    if (!ADMIN.students.length) {
      toast("Add a student before raising an invoice.");
      return;
    }
    var students = ADMIN.students.map(function (student) {
      return { value: student.id, label: student.name + " \u00B7 " + (student.class ? student.class.name : "No class") };
    });
    var currentTerm = DATA.terms.filter(function (term) { return term.is_current; })[0];
    var dueOn = currentTerm && currentTerm.ends_on ? currentTerm.ends_on : "";

    var body = adminForm(
      "invoice-create",
      adminField("Student", adminSelect("student_id", students, students[0].value, "required")) +
        adminField("Term", adminSelect("term_id", termOptions(), currentTerm ? currentTerm.id : "", "required")) +
        adminField("Description", adminInput("description", "text", "", 'maxlength="160" placeholder="e.g. First Term tuition" required'), true) +
        adminField("Amount", adminInput("amount", "number", "", 'min="0.01" step="0.01" required')) +
        adminField("Issued on", adminInput("issued_on", "date", todayISO(), "required")) +
        adminField("Due on", adminInput("due_on", "date", dueOn, "required"), true),
      "Create invoice"
    );

    openAdminDialog("New fee invoice", "Fees & finance", body);
  }

  function openPaymentDialog(invoiceId) {
    var finance = ADMIN.finance || {};
    var invoices = Array.isArray(finance.invoices) ? finance.invoices : [];
    var outstanding = invoices.filter(function (invoice) { return invoice.balance > 0; });
    if (!outstanding.length) {
      toast("There are no outstanding invoices to record a payment against.");
      return;
    }
    var options = outstanding.map(function (invoice) {
      return {
        value: invoice.id,
        label: (invoice.student ? invoice.student.name : "Student") + " \u00B7 " + invoice.ref + " \u00B7 " + money(invoice.balance) + " balance"
      };
    });
    var selected = invoiceId && outstanding.some(function (invoice) { return invoice.id === invoiceId; })
      ? invoiceId
      : outstanding[0].id;
    var selectedInvoice = outstanding.filter(function (invoice) { return invoice.id === selected; })[0];
    var methods = ["Cash", "Bank transfer", "POS", "Cheque", "Online"].map(function (method) {
      return { value: method, label: method };
    });

    var body = adminForm(
      "payment-create",
      adminField("Invoice", adminSelect("invoice_id", options, selected, "required data-payment-invoice")) +
        adminField("Amount", adminInput("amount", "number", selectedInvoice ? selectedInvoice.balance : "", 'min="0.01" step="0.01" required')) +
        adminField("Method", adminSelect("method", methods, "Cash", "required")) +
        adminField("Paid on", adminInput("paid_on", "date", todayISO(), "required")) +
        adminField("Reference", adminInput("reference", "text", "", 'maxlength="40" placeholder="Bank or receipt reference"')) +
        adminField("Note", adminInput("note", "text", "", 'maxlength="190" placeholder="Optional note"'), true),
      "Record payment"
    );

    openAdminDialog("Record fee payment", "Fees & finance", body);
  }

  function adminResultsView() {
    if (state.resultSheet) return resultSheetWorkspace("admin");

    var termInfo = DATA.adminResultSheets && DATA.adminResultSheets.term ? DATA.adminResultSheets.term : (DATA.dashboard ? DATA.dashboard.term : {});
    var sheets = DATA.adminResultSheets && Array.isArray(DATA.adminResultSheets.sheets) ? DATA.adminResultSheets.sheets : [];
    var termPublished = !!(termInfo.results_published_at || (DATA.dashboard && DATA.dashboard.term && DATA.dashboard.term.results_published_at));
    var submitted = sheets.filter(function (sheet) { return sheet.status === "submitted"; }).length;
    var approved = sheets.filter(function (sheet) { return sheet.status === "approved"; }).length;
    var published = sheets.filter(function (sheet) { return sheet.status === "published"; }).length;

    var rows = sheets.map(function (sheet) {
      return [
        '<div class="cell-person"><strong>' + esc(sheet.class.name) + "</strong><span>" + esc(sheet.subject.name) + "</span></div>",
        esc(sheet.teacher || "\u2014"),
        asNumber(sheet.entered) + " / " + asNumber(sheet.students),
        statusPill(statusLabel(sheet.status, sheet.status_label), statusTone(sheet.status)),
        sheet.submitted_at ? longDate(sheet.submitted_at) : '<span class="muted">\u2014</span>',
        '<button class="btn btn--ghost btn--sm" type="button" data-result-open data-sheet-id="' + esc(sheet.id) +
          '" data-class-id="' + esc(sheet.class.id) + '" data-subject-id="' + esc(sheet.subject.id) +
          '" data-status="' + esc(sheet.status) + '">Review</button>'
      ];
    });

    return pageHead({
      eyebrow: "Administration",
      title: "Results",
      lead: "Review submitted result sheets and publish the term when every sheet is approved.",
      actions: '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(termInfo.label || SCHOOL.label) + "</span>" +
        (termPublished
          ? '<button class="btn btn--ghost" type="button" disabled title="Results for this term have already been published"><i data-lucide="check-circle-2"></i> Results published</button>'
          : '<button class="btn btn--gold" type="button" data-publish-term><i data-lucide="send"></i> Publish results</button>')
    }) +
    statGrid([
      stat({ icon: "file-check-2", label: "Submitted", value: submitted, meta: "Awaiting your review", alert: submitted > 0 }),
      stat({ icon: "check-circle-2", label: "Approved", value: approved, meta: "Ready for publication" }),
      stat({ icon: "megaphone", label: "Published", value: published, meta: "Visible to families" }),
      stat({ icon: "files", label: "All sheets", value: sheets.length, meta: "For this term" })
    ]) +
    panel({
      title: "Result sheets",
      sub: "Submitted and approved sheets for " + (termInfo.label || SCHOOL.label),
      body: rows.length
        ? table(
            [{ label: "Class / subject" }, { label: "Teacher" }, { label: "Entered", align: "center" }, { label: "Status" }, { label: "Submitted" }, { label: "Action", align: "right" }],
            rows,
            "Result sheets for the current term"
          )
        : emptyState("file-check-2", "No result sheets", "Submitted sheets will appear here for review.")
    }) +
    callout("info", "Results become visible to students and parents only after every submitted sheet is approved or returned and the term is published. Returning a sheet unlocks it for the teacher to correct.");
  }

  async function approveCurrentResultSheet(button) {
    var result = state.resultSheet;
    if (!result || !result.data) return;
    var sheet = result.data.sheet;
    if (!window.confirm("Approve " + sheet.class.name + " " + sheet.subject.name + "? Approve only after checking the scores.")) return;

    var noteField = document.querySelector("[data-review-note]");
    var note = noteField && noteField.value.trim() ? noteField.value.trim() : null;
    setButtonBusy(button, true, "Approving\u2026");
    try {
      var payload = await API.post("/admin/result-sheets/" + encodeURIComponent(sheet.id) + "/approve", note ? { note: note } : {});
      result.data = payload && payload.data ? payload.data : result.data;
      updateAdminSheet(result.data.sheet);
      renderView();
      icons();
      toast("Result sheet approved.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
      setButtonBusy(button, false);
    }
  }

  async function returnCurrentResultSheet(button) {
    var result = state.resultSheet;
    if (!result || !result.data) return;
    var sheet = result.data.sheet;
    var noteField = document.querySelector("[data-review-note]");
    var note = noteField ? noteField.value.trim() : "";
    if (note.length < 3) {
      toast("Add a short note explaining what needs correction.");
      if (noteField) noteField.focus();
      return;
    }

    setButtonBusy(button, true, "Returning\u2026");
    try {
      var payload = await API.post("/admin/result-sheets/" + encodeURIComponent(sheet.id) + "/return", { note: note });
      result.data = payload && payload.data ? payload.data : result.data;
      updateAdminSheet(result.data.sheet);
      renderView();
      icons();
      toast("Result sheet returned to the teacher.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
      setButtonBusy(button, false);
    }
  }

  function updateAdminSheet(sheet) {
    var sheets = DATA.adminResultSheets && Array.isArray(DATA.adminResultSheets.sheets) ? DATA.adminResultSheets.sheets : [];
    var match = sheets.filter(function (row) { return row.id === sheet.id; })[0];
    if (match) {
      match.status = sheet.status;
      match.status_label = sheet.status_label;
      match.entered = sheet.entered;
      match.students = sheet.students;
      match.submitted_at = sheet.submitted_at;
      match.approved_at = sheet.approved_at;
    }
    ADMIN.stats.pendingApprovals = sheets.filter(function (row) { return row.status === "submitted"; }).length;
    ADMIN.approvals = (ADMIN.approvals || []).filter(function (row) { return row.id !== sheet.id; });
    setNavBadge("admin", "results", ADMIN.stats.pendingApprovals);
    renderSidebar();
  }

  async function publishCurrentTerm(button) {
    var term = DATA.dashboard && DATA.dashboard.term ? DATA.dashboard.term : null;
    if (!term || !term.id) return;
    var termInfo = DATA.adminResultSheets && DATA.adminResultSheets.term ? DATA.adminResultSheets.term : null;
    if (term.results_published_at || (termInfo && termInfo.results_published_at)) {
      toast("Results for this term are already published.");
      return;
    }
    if (!window.confirm("Publish " + (term.label || term.name) + " results to students and parents? This notifies families and should only be done after every sheet is approved.")) return;

    setButtonBusy(button, true, "Publishing\u2026");
    try {
      var payload = await API.post("/admin/terms/" + encodeURIComponent(term.id) + "/publish", {});
      var published = payload && payload.data ? payload.data.published_sheets : 0;
      var sheets = DATA.adminResultSheets && Array.isArray(DATA.adminResultSheets.sheets) ? DATA.adminResultSheets.sheets : [];
      sheets.forEach(function (sheet) {
        if (sheet.status === "approved") {
          sheet.status = "published";
          sheet.status_label = "Published";
        }
      });
      term.results_published_at = new Date().toISOString();
      if (termInfo) termInfo.results_published_at = term.results_published_at;
      ADMIN.stats.pendingApprovals = 0;
      ADMIN.approvals = [];
      setNavBadge("admin", "results", 0);
      renderSidebar();
      renderView();
      icons();
      toast(published + " result sheet" + (published === 1 ? "" : "s") + " published.");
    } catch (error) {
      if (API.isAuthError(error)) return handleAuthFailure();
      toast(API.message(error));
      setButtonBusy(button, false);
    }
  }

})();
