/* ============================================================
   AL-FALAAH — PORTAL DASHBOARD (design preview)
   Vanilla JS, no build step. All data below is fictional and
   exists only to demonstrate the interface. Replace with API
   responses when the backend is connected.
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

  function money(amount) {
    return "\u20A6" + Number(amount).toLocaleString("en-NG");
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

  function gradeFor(total) {
    if (total >= 85) return { grade: "A", remark: "Excellent" };
    if (total >= 75) return { grade: "A", remark: "Very good" };
    if (total >= 70) return { grade: "A", remark: "Good" };
    if (total >= 60) return { grade: "B", remark: "Credit" };
    if (total >= 50) return { grade: "C", remark: "Pass" };
    if (total >= 45) return { grade: "D", remark: "Fair" };
    if (total >= 40) return { grade: "E", remark: "Weak" };
    return { grade: "F", remark: "Needs improvement" };
  }

  function makeResults(term, published, rows, position, classSize) {
    var subjects = rows.map(function (row) {
      var total = row[1] + row[2];
      var grade = gradeFor(total);
      return {
        name: row[0], ca: row[1], exam: row[2], total: total,
        grade: grade.grade, remark: grade.remark
      };
    });
    var sum = subjects.reduce(function (acc, s) { return acc + s.total; }, 0);
    return {
      term: term,
      published: published,
      subjects: subjects,
      average: sum / subjects.length,
      position: position,
      classSize: classSize
    };
  }

  function roomFor(subject, klass) {
    if (subject === "Computer Studies" || subject === "Basic Technology") return "ICT Lab";
    if (subject === "Basic Science" || subject === "Agricultural Science") return "Science Lab";
    if (subject === "Islamic Studies" || subject === "Arabic") return "Assembly Hall";
    return klass;
  }

  function buildTimetable(subjectsByDay, klass) {
    var periods = [
      { label: "Period 1", time: "8:00 \u2013 8:40" },
      { label: "Period 2", time: "8:40 \u2013 9:20" },
      { label: "Period 3", time: "9:20 \u2013 10:00" },
      { label: "Period 4", time: "10:20 \u2013 11:00" },
      { label: "Period 5", time: "11:00 \u2013 11:40" },
      { label: "Period 6", time: "12:20 \u2013 1:00" }
    ];
    var days = {};
    Object.keys(subjectsByDay).forEach(function (day) {
      days[day] = subjectsByDay[day].map(function (subject) {
        return {
          subject: subject,
          teacher: TEACHERS[subject] || "Class teacher",
          room: roomFor(subject, klass)
        };
      });
    });
    return { periods: periods, days: days };
  }

  /* ============================================================
     2. SCHOOL + CURRICULUM DATA
     ============================================================ */
  var SCHOOL = {
    session: "2026/2027",
    term: "First Term",
    week: "Week 8"
  };

  var TEACHERS = {
    "Mathematics": "Ustadh Sulaiman Bello",
    "Further Mathematics": "Ustadh Sulaiman Bello",
    "English Studies": "Mrs. Grace Adeyemi",
    "Basic Science": "Mr. Tunde Ojo",
    "Islamic Studies": "Ustadh Ibrahim Musa",
    "Arabic": "Ustadh Abdulrahman Yusuf",
    "Social Studies": "Mrs. Funke Balogun",
    "Computer Studies": "Mr. Samuel Eze",
    "Business Studies": "Mrs. Ngozi Okeke",
    "Yoruba": "Mr. Adewale Ogunleye",
    "Basic Technology": "Mr. Kunle Adebayo",
    "Agricultural Science": "Mrs. Bola Ajayi",
    "P.H.E.": "Coach Danjuma",
    "Civic Education": "Mrs. Halima Sadiq",
    "Creative Arts": "Ms. Amaka Nwosu",
    "Library": "Mrs. F. Lawal"
  };

  var JUNIOR_DAYS = {
    Monday: ["Mathematics", "English Studies", "Islamic Studies", "Basic Science", "Arabic", "Computer Studies"],
    Tuesday: ["English Studies", "Mathematics", "Social Studies", "Business Studies", "Yoruba", "Basic Technology"],
    Wednesday: ["Islamic Studies", "Basic Science", "Mathematics", "English Studies", "Computer Studies", "Arabic"],
    Thursday: ["Mathematics", "English Studies", "Agricultural Science", "Social Studies", "Islamic Studies", "P.H.E."],
    Friday: ["Arabic", "Islamic Studies", "Mathematics", "English Studies", "Civic Education", "Library"]
  };

  var PRIMARY_DAYS = {
    Monday: ["English Studies", "Mathematics", "Basic Science", "Islamic Studies", "Arabic", "Creative Arts"],
    Tuesday: ["Mathematics", "English Studies", "Social Studies", "Computer Studies", "Yoruba", "P.H.E."],
    Wednesday: ["Islamic Studies", "Mathematics", "English Studies", "Basic Science", "Arabic", "Agricultural Science"],
    Thursday: ["English Studies", "Mathematics", "Social Studies", "Computer Studies", "Islamic Studies", "Creative Arts"],
    Friday: ["Arabic", "Islamic Studies", "Mathematics", "English Studies", "Civic Education", "Library"]
  };

  /* ============================================================
     3. STUDENTS (sample records)
     ============================================================ */
  var STUDENTS = {
    aisha: {
      id: "stu-1001",
      name: "Aisha Abdullahi",
      initials: "AA",
      klass: "JSS 2B",
      level: "Junior Secondary",
      admissionNo: "AF/2024/0187",
      house: "Al-Farooq House",
      formTeacher: "Ustadh Sulaiman Bello",
      guardian: { name: "Mr. Ibrahim Abdullahi", relation: "Father", phone: "+234 803 000 0000" },
      attendance: {
        rate: 96,
        days: 72,
        present: 67,
        absent: 3,
        late: 2,
        weeks: [
          { label: "W1", value: 100 },
          { label: "W2", value: 94 },
          { label: "W3", value: 100 },
          { label: "W4", value: 89 },
          { label: "W5", value: 100 },
          { label: "W6", value: 96 },
          { label: "W7", value: 100 }
        ],
        recent: [
          { date: "Fri, 23 Oct 2026", day: "Friday", status: "Present", tone: "ok", note: "Signed in at 7:52am" },
          { date: "Thu, 22 Oct 2026", day: "Thursday", status: "Present", tone: "ok", note: "Signed in at 7:49am" },
          { date: "Wed, 21 Oct 2026", day: "Wednesday", status: "Late", tone: "warn", note: "Arrived at 8:14am" },
          { date: "Tue, 20 Oct 2026", day: "Tuesday", status: "Present", tone: "ok", note: "Signed in at 7:55am" },
          { date: "Mon, 19 Oct 2026", day: "Monday", status: "Present", tone: "ok", note: "Signed in at 7:47am" },
          { date: "Fri, 16 Oct 2026", day: "Friday", status: "Excused", tone: "info", note: "Medical appointment \u2014 guardian note received" },
          { date: "Thu, 15 Oct 2026", day: "Thursday", status: "Present", tone: "ok", note: "Signed in at 7:50am" },
          { date: "Wed, 14 Oct 2026", day: "Wednesday", status: "Present", tone: "ok", note: "Signed in at 7:56am" }
        ]
      },
      results: makeResults("Third Term, 2025/2026", "18 Jul 2026", [
        ["English Studies", 35, 53],
        ["Mathematics", 37, 57],
        ["Basic Science", 33, 49],
        ["Islamic Studies", 36, 53],
        ["Arabic", 35, 51],
        ["Social Studies", 33, 47],
        ["Computer Studies", 38, 52],
        ["Business Studies", 29, 42],
        ["Yoruba", 34, 48]
      ], 3, 38),
      timetable: buildTimetable(JUNIOR_DAYS, "JSS 2B"),
      fees: {
        term: "First Term 2026/2027",
        total: 185000,
        paid: 185000,
        balance: 0,
        dueDate: "30 Sep 2026",
        invoices: [
          { ref: "INV-26-0412", item: "Tuition (First Term)", date: "02 Sep 2026", amount: 120000, status: "Paid" },
          { ref: "INV-26-0413", item: "Books & learning materials", date: "02 Sep 2026", amount: 35000, status: "Paid" },
          { ref: "INV-26-0414", item: "Transport (First Term)", date: "02 Sep 2026", amount: 30000, status: "Paid" }
        ],
        payments: [
          { date: "04 Sep 2026", ref: "PSK-8841203", method: "Bank transfer", amount: 150000 },
          { date: "18 Sep 2026", ref: "PSK-8890311", method: "Card payment", amount: 35000 }
        ]
      },
      assignments: [
        { title: "Quadratic equations \u2014 exercise 4B", subject: "Mathematics", due: "Tomorrow, 26 Oct", tone: "warn", status: "Due soon" },
        { title: "Memorise Surah Al-Mulk (verses 1\u201315)", subject: "Islamic Studies", due: "Thu, 29 Oct", tone: "info", status: "In progress" },
        { title: "Water filtration lab report", subject: "Basic Science", due: "Submitted 22 Oct", tone: "ok", status: "Submitted" }
      ]
    },
    yusuf: {
      id: "stu-1002",
      name: "Yusuf Abdullahi",
      initials: "YA",
      klass: "Primary 5A",
      level: "Primary",
      admissionNo: "AF/2025/0321",
      house: "Al-Farooq House",
      formTeacher: "Mrs. Grace Adeyemi",
      guardian: { name: "Mr. Ibrahim Abdullahi", relation: "Father", phone: "+234 803 000 0000" },
      attendance: {
        rate: 93,
        days: 72,
        present: 64,
        absent: 5,
        late: 3,
        weeks: [
          { label: "W1", value: 100 },
          { label: "W2", value: 88 },
          { label: "W3", value: 94 },
          { label: "W4", value: 83 },
          { label: "W5", value: 100 },
          { label: "W6", value: 92 },
          { label: "W7", value: 96 }
        ],
        recent: [
          { date: "Fri, 23 Oct 2026", day: "Friday", status: "Present", tone: "ok", note: "Signed in at 7:58am" },
          { date: "Thu, 22 Oct 2026", day: "Thursday", status: "Present", tone: "ok", note: "Signed in at 8:01am" },
          { date: "Wed, 21 Oct 2026", day: "Wednesday", status: "Absent", tone: "danger", note: "No notice received" },
          { date: "Tue, 20 Oct 2026", day: "Tuesday", status: "Present", tone: "ok", note: "Signed in at 7:59am" },
          { date: "Mon, 19 Oct 2026", day: "Monday", status: "Late", tone: "warn", note: "Arrived at 8:05am" },
          { date: "Fri, 16 Oct 2026", day: "Friday", status: "Present", tone: "ok", note: "Signed in at 7:57am" },
          { date: "Thu, 15 Oct 2026", day: "Thursday", status: "Present", tone: "ok", note: "Signed in at 8:00am" },
          { date: "Wed, 14 Oct 2026", day: "Wednesday", status: "Late", tone: "warn", note: "Arrived at 8:03am" }
        ]
      },
      results: makeResults("Third Term, 2025/2026", "18 Jul 2026", [
        ["English Studies", 32, 46],
        ["Mathematics", 35, 50],
        ["Basic Science", 33, 48],
        ["Islamic Studies", 36, 52],
        ["Arabic", 31, 45],
        ["Social Studies", 30, 44],
        ["Computer Studies", 34, 49],
        ["Agricultural Science", 32, 47]
      ], 5, 32),
      timetable: buildTimetable(PRIMARY_DAYS, "Primary 5A"),
      fees: {
        term: "First Term 2026/2027",
        total: 145000,
        paid: 120000,
        balance: 25000,
        dueDate: "30 Sep 2026",
        invoices: [
          { ref: "INV-26-0528", item: "Tuition (First Term)", date: "02 Sep 2026", amount: 95000, status: "Paid" },
          { ref: "INV-26-0529", item: "Books & learning materials", date: "02 Sep 2026", amount: 25000, status: "Paid" },
          { ref: "INV-26-0530", item: "Transport (First Term)", date: "02 Sep 2026", amount: 25000, status: "Unpaid" }
        ],
        payments: [
          { date: "05 Sep 2026", ref: "PSK-8847120", method: "Bank transfer", amount: 95000 },
          { date: "17 Sep 2026", ref: "PSK-8887742", method: "POS at school office", amount: 25000 }
        ]
      },
      assignments: [
        { title: "Fractions workbook, pages 24\u201326", subject: "Mathematics", due: "Tomorrow, 26 Oct", tone: "warn", status: "Due soon" },
        { title: "Arabic handwriting practice", subject: "Arabic", due: "Fri, 30 Oct", tone: "info", status: "Pending" }
      ]
    }
  };

  /* ============================================================
     4. ANNOUNCEMENTS + EVENTS
     ============================================================ */
  var ANNOUNCEMENTS = [
    {
      day: "24", month: "Oct", date: "24 Oct 2026", tag: "Events", pinned: true,
      audience: ["student", "parent", "teacher", "admin"],
      title: "First Term mid-term break",
      body: "The school closes on Friday, 30 October and reopens on Monday, 9 November 2026, in shaa Allah. Please plan pick-up accordingly."
    },
    {
      day: "21", month: "Oct", date: "21 Oct 2026", tag: "Academics",
      audience: ["parent", "teacher", "admin"],
      title: "Parent\u2013Teacher meeting",
      body: "Our termly Parent\u2013Teacher meeting holds on Saturday, 7 November, from 9:00am to 1:00pm in the main hall. Reports and subject feedback will be shared."
    },
    {
      day: "20", month: "Oct", date: "20 Oct 2026", tag: "Academics",
      audience: ["student", "parent"],
      title: "Third Term results are now published",
      body: "Parents and students can now view the Third Term 2025/2026 results in the portal. Report cards are available for download."
    },
    {
      day: "19", month: "Oct", date: "19 Oct 2026", tag: "Staff",
      audience: ["teacher", "admin"],
      title: "Result entry deadline for teaching staff",
      body: "All First Term continuous assessment scores must be submitted through the portal by 5:00pm on Wednesday, 28 October."
    },
    {
      day: "16", month: "Oct", date: "16 Oct 2026", tag: "Islamic",
      audience: ["student", "parent", "teacher", "admin"],
      title: "Annual Qur\u2019an competition",
      body: "Registration is open for the annual Qur\u2019an recitation competition. Interested students should see their Islamic Studies teacher before 31 October."
    },
    {
      day: "12", month: "Oct", date: "12 Oct 2026", tag: "General",
      audience: ["student", "parent", "teacher", "admin"],
      title: "New titles in the school library",
      body: "Over 120 new books across science, history and Islamic literature are now available in the school library."
    }
  ];

  var EVENTS = [
    { day: "30", month: "Oct", title: "Mid-term break begins", meta: "Friday \u00B7 School closes" },
    { day: "07", month: "Nov", title: "Parent\u2013Teacher meeting", meta: "Saturday \u00B7 9:00am \u00B7 Main hall" },
    { day: "13", month: "Nov", title: "Qur\u2019an competition finals", meta: "Friday \u00B7 10:00am \u00B7 Assembly hall" }
  ];

  /* ============================================================
     5. STAFF + ADMINISTRATION (sample records)
     ============================================================ */
  var TEACHER = {
    name: "Ustadh Sulaiman Bello",
    initials: "SB",
    email: "s.bello@alfalaah.edu.ng",
    role: "Mathematics Teacher",
    formClass: "JSS 2B",
    stats: { lessons: 4, registers: 2, sheets: 1, students: 128 },
    schedule: [
      { period: "Period 1", time: "8:00 \u2013 8:40", subject: "Mathematics", klass: "JSS 2B", room: "JSS 2B", next: true },
      { period: "Period 2", time: "8:40 \u2013 9:20", subject: "Mathematics", klass: "JSS 1A", room: "JSS 1A" },
      { period: "Period 4", time: "10:20 \u2013 11:00", subject: "Mathematics", klass: "JSS 3A", room: "JSS 3A" },
      { period: "Period 5", time: "11:00 \u2013 11:40", subject: "Further Mathematics", klass: "SSS 1B", room: "SSS 1B" }
    ],
    pendingRegisters: [
      { klass: "JSS 1A", period: "Period 2", time: "8:40 \u2013 9:20", students: 34 },
      { klass: "JSS 3A", period: "Period 4", time: "10:20 \u2013 11:00", students: 29 }
    ],
    classPerformance: [
      { klass: "JSS 2B", average: 82 },
      { klass: "JSS 1A", average: 76 },
      { klass: "JSS 3A", average: 71 },
      { klass: "SSS 1B", average: 68 }
    ]
  };

  var ADMIN = {
    name: "Mrs. Hauwa Sanni",
    initials: "HS",
    email: "h.sanni@alfalaah.edu.ng",
    role: "Head of Administration",
    stats: { students: 428, staff: 32, attendance: 94, collected: 12400000 },
    levels: [
      { label: "Nursery", count: 64 },
      { label: "Primary", count: 182 },
      { label: "Junior Secondary", count: 104 },
      { label: "Senior Secondary", count: 78 }
    ],
    pipeline: [
      { label: "New applications", count: 18 },
      { label: "Assessment booked", count: 7 },
      { label: "Offers issued", count: 5 },
      { label: "Enrolled", count: 3 }
    ],
    approvals: [
      { klass: "JSS 2B", subject: "Mathematics", teacher: "Ustadh S. Bello", submitted: "24 Oct 2026", status: "Pending", tone: "warn" },
      { klass: "Primary 5A", subject: "English Studies", teacher: "Mrs. G. Adeyemi", submitted: "23 Oct 2026", status: "Pending", tone: "warn" },
      { klass: "SSS 2A", subject: "Biology", teacher: "Mrs. B. Okonkwo", submitted: "23 Oct 2026", status: "In review", tone: "info" },
      { klass: "JSS 3A", subject: "Islamic Studies", teacher: "Ustadh I. Musa", submitted: "22 Oct 2026", status: "Approved", tone: "ok" }
    ]
  };

  /* ============================================================
     6. ROLES + NAVIGATION
     ============================================================ */
  var ROLES = {
    student: {
      label: "Student",
      userName: "Aisha Abdullahi",
      shortName: "Aisha",
      initials: "AA",
      meta: "JSS 2B \u00B7 Student",
      email: "aisha.abdullahi@student.alfalaah.edu.ng",
      studentId: "aisha",
      tabbar: ["overview", "timetable", "results", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "My school" },
        { key: "timetable", label: "Timetable", icon: "calendar-days", group: "My school" },
        { key: "results", label: "Results", icon: "graduation-cap", group: "My school" },
        { key: "attendance", label: "Attendance", icon: "clipboard-check", group: "My school" },
        { key: "fees", label: "Fees & payments", icon: "wallet", group: "Services", tabLabel: "Fees" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "Services", badge: 2 }
      ],
      notifications: [
        { icon: "graduation-cap", title: "Third Term results published", meta: "Results \u00B7 2 hours ago", unread: true },
        { icon: "calendar-days", title: "Mid-term break: 30 Oct \u2013 9 Nov", meta: "Announcement \u00B7 1 day ago", unread: true },
        { icon: "book-open", title: "Mathematics homework due tomorrow", meta: "Assignments \u00B7 2 days ago", unread: false }
      ]
    },

    parent: {
      label: "Parent",
      userName: "Mr. Ibrahim Abdullahi",
      shortName: "Mr. Abdullahi",
      initials: "IA",
      meta: "Parent of 2 children",
      email: "ibrahim.abdullahi@example.com",
      childIds: ["aisha", "yusuf"],
      tabbar: ["overview", "results", "attendance", "fees"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "My children" },
        { key: "results", label: "Results", icon: "graduation-cap", group: "My children" },
        { key: "attendance", label: "Attendance", icon: "clipboard-check", group: "My children" },
        { key: "timetable", label: "Timetable", icon: "calendar-days", group: "My children" },
        { key: "fees", label: "Fees & payments", icon: "wallet", group: "Services", tabLabel: "Fees" },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "Services" }
      ],
      notifications: [
        { icon: "wallet", title: "\u20A625,000 fee balance for Yusuf", meta: "Accounts \u00B7 3 hours ago", unread: true },
        { icon: "users", title: "Parent\u2013Teacher meeting on 7 November", meta: "Announcement \u00B7 1 day ago", unread: true },
        { icon: "graduation-cap", title: "Aisha\u2019s Third Term results published", meta: "Results \u00B7 2 days ago", unread: false }
      ]
    },

    teacher: {
      label: "Teacher",
      userName: "Ustadh Sulaiman Bello",
      shortName: "Ustadh Sulaiman",
      initials: "SB",
      meta: "Mathematics \u00B7 JSS 2B",
      email: "s.bello@alfalaah.edu.ng",
      tabbar: ["overview", "attendance", "results", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "Teaching" },
        {
          key: "classes", label: "My classes", icon: "users", group: "Teaching",
          module: {
            summary: "Every class you teach, its roster and its timetable in one place.",
            features: [
              "Class rosters with student photographs and guardian contacts",
              "Class-specific timetables and lesson planning notes",
              "Attendance history for each class and term",
              "Direct messaging with form teachers and parents"
            ],
            backend: "Requires class-teacher assignments, student enrolment records and a read-only timetable endpoint."
          }
        },
        {
          key: "attendance", label: "Attendance", icon: "clipboard-check", group: "Teaching", badge: 2,
          module: {
            summary: "Mark registers from any device and keep an auditable attendance history.",
            features: [
              "Daily register with present, absent and late states",
              "Guardian notifications for unexplained absences",
              "Termly attendance summaries per student and class",
              "Exportable attendance reports for the administration"
            ],
            backend: "Requires attendance tables keyed by class, date and student, plus role-scoped write access."
          }
        },
        {
          key: "results", label: "Results entry", icon: "file-check-2", group: "Teaching",
          module: {
            summary: "Enter continuous assessment and exam scores, then submit for approval.",
            features: [
              "Subject score entry with CA and examination columns",
              "Automatic totals, grades and class positions",
              "Submission workflow with administrator approval",
              "Locked sheets after publication to protect records"
            ],
            backend: "Requires subject-teacher assignments, assessment schemas and an approval audit trail."
          }
        },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "School" }
      ],
      notifications: [
        { icon: "clipboard-check", title: "2 attendance registers pending", meta: "Attendance \u00B7 Today", unread: true },
        { icon: "file-check-2", title: "Result entry closes 28 October", meta: "Academics \u00B7 Today", unread: true },
        { icon: "megaphone", title: "Staff briefing moved to 8:00am", meta: "Announcement \u00B7 1 day ago", unread: false }
      ]
    },

    admin: {
      label: "Administrator",
      userName: "Mrs. Hauwa Sanni",
      shortName: "Mrs. Hauwa",
      initials: "HS",
      meta: "Head of Administration",
      email: "h.sanni@alfalaah.edu.ng",
      tabbar: ["overview", "admissions", "students", "announcements"],
      nav: [
        { key: "overview", label: "Overview", icon: "layout-dashboard", group: "Administration" },
        {
          key: "students", label: "Students", icon: "users", group: "Administration",
          module: {
            summary: "The complete student register \u2014 enrolment, classes, guardians and records.",
            features: [
              "Enrol, transfer and graduate students with a full history",
              "Guardian and emergency-contact records",
              "Class allocation, houses and admission numbers",
              "Bulk import and export of student data"
            ],
            backend: "Requires a students table with guardian relations, class history and safe CSV import."
          }
        },
        {
          key: "staff", label: "Staff", icon: "id-card", group: "Administration",
          module: {
            summary: "Manage teaching and non-teaching staff, roles and permissions.",
            features: [
              "Staff directory with subject and class assignments",
              "Role-based permissions for portal access",
              "Account provisioning and password resets",
              "Employment records and document storage"
            ],
            backend: "Requires users, roles and permissions tables with audit logging."
          }
        },
        {
          key: "academics", label: "Academics", icon: "book-open", group: "Administration",
          module: {
            summary: "Classes, subjects, timetables and the academic calendar.",
            features: [
              "Class and subject management per level",
              "Timetable builder with teacher conflict checks",
              "Term and session calendar management",
              "Approval workflow for published results"
            ],
            backend: "Requires classes, subjects, timetable slots and session tables."
          }
        },
        {
          key: "admissions", label: "Admissions", icon: "user-plus", group: "Administration", badge: 18,
          module: {
            summary: "Track applications from enquiry to enrolment without losing a single family.",
            features: [
              "Application pipeline with statuses and owners",
              "Assessment scheduling and outcome records",
              "Offer letters and acceptance tracking",
              "Conversion of accepted applicants into student records"
            ],
            backend: "Requires an applications table, status transitions and secure file storage for documents."
          }
        },
        { key: "announcements", label: "Announcements", icon: "megaphone", group: "School" }
      ],
      notifications: [
        { icon: "file-check-2", title: "4 result sheets awaiting approval", meta: "Academics \u00B7 1 hour ago", unread: true },
        { icon: "user-plus", title: "18 new admission applications", meta: "Admissions \u00B7 Today", unread: true },
        { icon: "wallet", title: "\u20A612.4m collected this term", meta: "Accounts \u00B7 Today", unread: false }
      ]
    }
  };

  /* ============================================================
     7. STATE
     ============================================================ */
  var state = {
    role: "student",
    section: "overview",
    childId: "aisha",
    annFilter: "All"
  };

  var readAll = { student: false, parent: false, teacher: false, admin: false };

  /* ============================================================
     8. SMALL UI BUILDERS
     ============================================================ */
  function pageHead(opts) {
    return '<section class="page-head">' +
      '<div class="page-head__text">' +
        (opts.eyebrow
          ? '<div class="page-head__flags">' +
              '<span class="eyebrow">' + esc(opts.eyebrow) + "</span>" +
              '<span class="demo-flag" title="Interactive design preview using sample school data">Prototype</span>' +
            "</div>"
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
      '<span class="stat__value">' + o.value + "</span>" +
      '<span class="stat__meta">' + esc(o.meta) + "</span></div></div>";
  }

  function statGrid(items) {
    return '<div class="stat-grid">' + items.join("") + "</div>";
  }

  function statusPill(text, tone) {
    return '<span class="status' + (tone ? " status--" + tone : "") + '">' + esc(text) + "</span>";
  }

  function demoBtn(label, icon, message, variant) {
    return '<button class="btn btn--' + (variant || "ghost") + '" type="button" data-demo="' +
      esc(message) + '"><i data-lucide="' + icon + '"></i> ' + esc(label) + "</button>";
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
    var body = rows.map(function (cells) {
      return "<tr>" + cells.map(function (cell, i) {
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

  function eventList() {
    return '<ul class="event-list">' + EVENTS.map(function (e) {
      return '<li class="event">' +
        '<div class="event__date"><strong>' + esc(e.day) + "</strong><span>" + esc(e.month) + "</span></div>" +
        '<div class="event__body"><strong>' + esc(e.title) + "</strong><span>" + esc(e.meta) + "</span></div></li>";
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

  function lessonRow(lesson, time, isNext, periodLabel) {
    var parts = splitTime(time);
    return '<li class="lesson' + (isNext ? " is-next" : "") + '">' +
      '<span class="lesson__time">' + esc(parts.start) + "<small>" + esc(parts.end) + "</small></span>" +
      '<div class="lesson__body"><strong>' + esc(lesson.subject) + "</strong>" +
      "<span>" + esc(lesson.teacher) + " \u00B7 " + esc(lesson.room) + "</span></div>" +
      (isNext ? statusPill("Next", "gold") : '<span class="lesson__period">' + esc(periodLabel) + "</span>") +
      "</li>";
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
      '<span class="role-chip"><span class="role-chip__dot" aria-hidden="true"></span>' + esc(cfg.label) + " view</span>";

    document.querySelector("[data-sidebar-user]").innerHTML =
      '<div class="user-card"><span class="avatar avatar--gold">' + esc(cfg.initials) + "</span>" +
      '<div class="user-card__meta"><strong>' + esc(cfg.userName) + "</strong><span>" + esc(cfg.meta) + "</span></div>" +
      '<a class="user-card__out" href="portal.html" aria-label="Sign out of the portal" title="Sign out"><i data-lucide="log-out"></i></a></div>';
  }

  function renderChrome() {
    var cfg = ROLES[state.role];
    document.querySelector("[data-top-avatar]").textContent = cfg.initials;
    document.querySelector("[data-top-name]").textContent = cfg.shortName;
    var select = document.getElementById("roleSelect");
    if (select) select.value = state.role;
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
    if (readAll[state.role]) return 0;
    return ROLES[state.role].notifications.filter(function (n) { return n.unread; }).length;
  }

  function renderNotifications() {
    var cfg = ROLES[state.role];
    var panelEl = document.querySelector("[data-notif-panel]");
    var bell = document.querySelector("[data-notif-toggle]");
    var dot = document.querySelector("[data-notif-dot]");
    var unread = unreadCount();

    panelEl.innerHTML =
      '<div class="popover__head"><h3>Notifications</h3>' +
        (unread ? '<button class="text-btn" type="button" data-read-all>Mark all read</button>'
                : '<span class="small muted">All caught up</span>') +
      "</div>" +
      '<div class="popover__list">' + cfg.notifications.map(function (n) {
        return '<button class="notif' + (n.unread && !readAll[state.role] ? " is-unread" : "") +
          '" type="button" data-demo="This notification will open its related record once the portal is connected to the backend.">' +
          '<span class="notif__icon"><i data-lucide="' + n.icon + '"></i></span>' +
          '<span class="notif__body"><strong>' + esc(n.title) + "</strong><span>" + esc(n.meta) + "</span></span>" +
          (n.unread && !readAll[state.role] ? '<span class="notif__dot" aria-hidden="true"></span>' : "") +
          "</button>";
      }).join("") + "</div>" +
      '<div class="popover__foot"><a class="link-arrow" href="#announcements" data-view="announcements">View all announcements <i data-lucide="arrow-right"></i></a></div>';

    if (dot) dot.hidden = unread === 0;
    if (bell) bell.setAttribute("aria-label", unread ? "Notifications, " + unread + " unread" : "Notifications");
  }

  function renderProfile() {
    var cfg = ROLES[state.role];
    document.querySelector("[data-profile-panel]").innerHTML =
      '<div class="profile-pop__head"><span class="avatar avatar--gold">' + esc(cfg.initials) + "</span>" +
        '<div class="profile-pop__id"><strong>' + esc(cfg.userName) + "</strong><span>" + esc(cfg.email) + "</span></div></div>" +
      '<div class="popover__list popover__list--menu">' +
        '<a class="menu-link" href="#" data-demo="Profile management arrives with the backend build."><i data-lucide="user"></i> My profile</a>' +
        '<a class="menu-link" href="#" data-demo="Account settings, password changes and notification preferences arrive with the backend build."><i data-lucide="settings"></i> Account settings</a>' +
        '<div class="menu-sep"></div>' +
        '<a class="menu-link" href="index.html"><i data-lucide="globe"></i> Back to website</a>' +
        '<a class="menu-link menu-link--danger" href="portal.html"><i data-lucide="log-out"></i> Sign out</a>' +
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
    } else if (item.module) {
      html = moduleView(item);
    } else {
      switch (item.key) {
        case "overview": html = overviewView(); break;
        case "results": html = resultsView(); break;
        case "attendance": html = attendanceView(); break;
        case "timetable": html = timetableView(); break;
        case "fees": html = feesView(); break;
        case "announcements": html = announcementsView(); break;
        default: html = overviewView();
      }
    }

    root.innerHTML = html;
    hydrateCharts();
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
    if (!sectionExists(key)) return;
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

  function setRole(role) {
    if (!ROLES[role] || role === state.role) return;
    state.role = role;
    state.annFilter = "All";
    var cfg = ROLES[role];
    if (cfg.childIds) {
      state.childId = cfg.childIds.indexOf(state.childId) !== -1 ? state.childId : cfg.childIds[0];
    }
    if (!sectionExists(state.section)) state.section = "overview";
    try { window.localStorage.setItem("alfalaah.portal.role", role); } catch (e) { /* ignore */ }

    var url = new URL(window.location.href);
    url.searchParams.set("role", role);
    url.hash = state.section;
    try { window.history.replaceState(null, "", url.toString()); } catch (e) { /* ignore */ }

    closeAllPopovers();
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

  function markAllRead() {
    readAll[state.role] = true;
    renderNotifications();
    icons();
    toast("All notifications marked as read.");
  }

  /* ============================================================
     12. EVENTS
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

    if (event.target.closest("[data-read-all]")) { markAllRead(); return; }

    var viewLink = event.target.closest("[data-view]");
    if (viewLink) {
      event.preventDefault();
      navigate(viewLink.getAttribute("data-view"));
      return;
    }

    var demo = event.target.closest("[data-demo]");
    if (demo) {
      event.preventDefault();
      toast(demo.getAttribute("data-demo"));
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

  function bindEvents() {
    document.addEventListener("click", onDocumentClick);
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

    var select = document.getElementById("roleSelect");
    if (select) {
      select.addEventListener("change", function (event) {
        setRole(event.target.value);
      });
    }
  }

  /* ============================================================
     13. INIT
     ============================================================ */
  function init() {
    var params = new URLSearchParams(window.location.search);
    var roleParam = params.get("role");

    if (ROLES[roleParam]) {
      state.role = roleParam;
    } else {
      try {
        var saved = window.localStorage.getItem("alfalaah.portal.role");
        if (ROLES[saved]) state.role = saved;
      } catch (e) { /* ignore */ }
    }

    var cfg = ROLES[state.role];
    state.childId = cfg.childIds ? cfg.childIds[0] : null;

    var key = hashKey();
    state.section = sectionExists(key) ? key : "overview";

    bindEvents();
    render();
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
      sub: r.term + " \u00B7 published " + r.published,
      action: '<a class="link-arrow" href="#results" data-view="results">Full results <i data-lucide="arrow-right"></i></a>',
      body: '<div class="meter-list">' + rows + "</div>" +
        '<div class="average-strip"><span>Term average</span><strong>' + r.average.toFixed(1) +
        "%</strong><em>" + ordinal(r.position) + " of " + r.classSize + "</em></div>"
    });
  }

  function announcementsFor(roleKey) {
    return ANNOUNCEMENTS.filter(function (a) {
      return a.audience.indexOf(roleKey) !== -1;
    });
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
    var pct = Math.round((f.paid / f.total) * 100);
    var balanceRow = f.balance > 0
      ? '<strong class="is-balance">' + money(f.balance) + "</strong>"
      : '<strong class="is-cleared">Cleared</strong>';

    var action = f.balance > 0
      ? '<button class="btn btn--gold btn--sm btn--block mt-2" type="button" data-demo="Online fee payment arrives with the backend. Meanwhile, payments are receipted at the school accounts office.">Pay ' + money(f.balance) + "</button>"
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

  /* ---------- Student ---------- */
  function studentOverview() {
    var student = currentStudent();
    var monday = student.timetable.days.Monday;
    var periods = student.timetable.periods;

    var upNext = '<ol class="lesson-list">' + monday.slice(0, 4).map(function (lesson, i) {
      return lessonRow(lesson, periods[i].time, i === 0, periods[i].label);
    }).join("") + "</ol>";

    return pageHead({
      eyebrow: "Student dashboard",
      title: "Assalamu Alaikum, " + esc(firstName(student.name)),
      lead: "Here is your school day at a glance.",
      actions: '<span class="term-chip"><i data-lucide="calendar-clock"></i>' + SCHOOL.week + " \u00B7 " + SCHOOL.term + "</span>" +
        demoBtn("Report card", "download", "Report cards are generated from published results in the backend build.", "gold")
    }) +
    profileBanner(student, "student") +
    statGrid([
      stat({ icon: "clipboard-check", label: "Attendance", value: student.attendance.rate + "%", meta: student.attendance.present + " of " + student.attendance.days + " school days" }),
      stat({ icon: "trending-up", label: "Term average", value: student.results.average.toFixed(1) + "%", meta: student.results.term }),
      stat({ icon: "award", label: "Class position", value: ordinal(student.results.position), meta: "of " + student.results.classSize + " students" }),
      feeStat(student)
    ]) +
    '<div class="dash-grid dash-grid--main">' +
      panel({
        title: "Up next",
        sub: "Monday, 26 October \u00B7 6 periods",
        action: '<a class="link-arrow" href="#timetable" data-view="timetable">Full timetable <i data-lucide="arrow-right"></i></a>',
        body: upNext
      }) +
      resultsSnapshotPanel(student) +
    "</div>" +
    '<div class="dash-grid dash-grid--2">' +
      panel({
        title: "Homework & tasks",
        sub: student.assignments.length + " active tasks",
        body: taskList(student.assignments)
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
      lead: "You are viewing " + esc(student.name) + "'s records for " + SCHOOL.term + " " + SCHOOL.session + ".",
      actions: childSwitch() +
        demoBtn("Report card", "download", "Report cards are generated from published results in the backend build.", "gold")
    }) +
    profileBanner(student, "parent") +
    statGrid([
      stat({ icon: "clipboard-check", label: "Attendance", value: student.attendance.rate + "%", meta: student.attendance.present + " of " + student.attendance.days + " school days" }),
      stat({ icon: "trending-up", label: "Term average", value: student.results.average.toFixed(1) + "%", meta: student.results.term }),
      stat({ icon: "award", label: "Class position", value: ordinal(student.results.position), meta: "of " + student.results.classSize + " students" }),
      feeStat(student)
    ]) +
    '<div class="dash-grid dash-grid--main">' +
      resultsSnapshotPanel(student) +
      feePanel(student) +
    "</div>" +
    '<div class="dash-grid dash-grid--2">' +
      attendancePanel(student) +
      panel({
        title: "Upcoming events",
        sub: "Dates for your diary",
        body: eventList()
      }) +
    "</div>" +
    announcementsPanel("parent", "Announcements", "Notices for parents", 3);
  }

  /* ---------- Teacher ---------- */
  function teacherOverview() {
    var t = TEACHER;

    var schedule = '<ol class="lesson-list">' + t.schedule.map(function (lesson) {
      return '<li class="lesson' + (lesson.next ? " is-next" : "") + '">' +
        '<span class="lesson__time">' + esc(splitTime(lesson.time).start) + "<small>" + esc(splitTime(lesson.time).end) + "</small></span>" +
        '<div class="lesson__body"><strong>' + esc(lesson.klass) + " \u00B7 " + esc(lesson.subject) + "</strong>" +
        "<span>" + esc(lesson.time) + " \u00B7 Room " + esc(lesson.room) + "</span></div>" +
        (lesson.next ? statusPill("Next", "gold") : '<span class="lesson__period">' + esc(lesson.period) + "</span>") +
        "</li>";
    }).join("") + "</ol>";

    var registers = '<ul class="register-list">' + t.pendingRegisters.map(function (r) {
      return '<li class="register">' +
        '<span class="register__icon"><i data-lucide="clipboard-check"></i></span>' +
        '<div class="register__body"><strong>' + esc(r.klass) + "</strong><span>" + esc(r.period) + " \u00B7 " + esc(r.time) + " \u00B7 " + r.students + " students</span></div>" +
        '<button class="btn btn--ghost" type="button" data-demo="Attendance marking opens with the backend build.">Mark</button>' +
        "</li>";
    }).join("") + "</ul>";

    var performance = '<div class="meter-list">' + t.classPerformance.map(function (row) {
      return meterRow(row.klass, row.average, row.average + "%");
    }).join("") + "</div>";

    return pageHead({
      eyebrow: "Teacher dashboard",
      title: "Assalamu Alaikum, " + esc(ROLES.teacher.shortName),
      lead: "Your classes and tasks for today.",
      actions: demoBtn("Mark register", "clipboard-check", "Attendance marking opens with the backend build.", "gold") +
        demoBtn("Enter results", "file-check-2", "Results entry opens with the backend build.")
    }) +
    statGrid([
      stat({ icon: "presentation", label: "Lessons today", value: t.stats.lessons, meta: "Across 4 classes" }),
      stat({ icon: "clipboard-check", label: "Registers pending", value: t.stats.registers, meta: "JSS 1A and JSS 3A", alert: true }),
      stat({ icon: "file-check-2", label: "Result sheets due", value: t.stats.sheets, meta: "Due Wed, 28 Oct" }),
      stat({ icon: "users", label: "Students taught", value: t.stats.students, meta: "This term" })
    ]) +
    '<div class="dash-grid dash-grid--main">' +
      panel({
        title: "Today's schedule",
        sub: "Monday, 26 October \u00B7 4 lessons",
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
    var maxLevel = Math.max.apply(null, a.levels.map(function (l) { return l.count; }));

    var levels = '<div class="meter-list">' + a.levels.map(function (level) {
      return meterRow(level.label, Math.round((level.count / maxLevel) * 100), level.count);
    }).join("") + "</div>" +
    '<p class="small muted mt-2">' + a.stats.students + " students enrolled across four levels.</p>";

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
        '<button class="btn btn--ghost btn--sm" type="button" data-demo="Result approval workflow arrives with the backend build.">Review</button>'
      ];
    });

    var quickActions = [
      { icon: "user-plus", title: "Add student", meta: "Enrol a new learner", msg: "Student enrolment opens with the backend build." },
      { icon: "megaphone", title: "Publish announcement", meta: "Notify parents & staff", msg: "Publishing announcements opens with the backend build." },
      { icon: "wallet", title: "Record payment", meta: "Log a fee payment", msg: "Fee recording opens with the backend build." },
      { icon: "clipboard-check", title: "Review results", meta: "Approve submitted sheets", msg: "Result approval opens with the backend build." }
    ];

    return pageHead({
      eyebrow: "Administration dashboard",
      title: "Assalamu Alaikum, " + esc(ROLES.admin.shortName),
      lead: "A live view of the school at a glance.",
      actions: demoBtn("Publish announcement", "megaphone", "Publishing announcements opens with the backend build.", "gold") +
        demoBtn("Add student", "user-plus", "Student enrolment opens with the backend build.")
    }) +
    statGrid([
      stat({ icon: "users", label: "Students", value: a.stats.students, meta: "Nursery to Senior Secondary" }),
      stat({ icon: "id-card", label: "Staff", value: a.stats.staff, meta: "Teaching and non-teaching" }),
      stat({ icon: "clipboard-check", label: "Attendance today", value: a.stats.attendance + "%", meta: "402 of 428 present" }),
      stat({ icon: "wallet", label: "Fees collected", value: "\u20A612.4m", meta: "First Term to date" })
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
      action: statusPill("2 pending", "warn"),
      body: table(
        [
          { label: "Class" }, { label: "Subject" }, { label: "Teacher" },
          { label: "Submitted" }, { label: "Status" }, { label: "Action", align: "right" }
        ],
        approvalRows,
        "Result sheets awaiting approval"
      )
    }) +
    '<div class="dash-grid dash-grid--2 dash-grid--flush mt-2">' +
      panel({
        title: "Quick actions",
        sub: "Common administrative tasks",
        body: '<div class="quick-grid">' + quickActions.map(function (item) {
          return '<button class="quick" type="button" data-demo="' + esc(item.msg) + '">' +
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
        String(subject.ca),
        String(subject.exam),
        "<strong>" + subject.total + "</strong>",
        '<span class="grade grade--' + subject.grade.toLowerCase() + '">' + subject.grade + "</span>",
        esc(subject.remark)
      ];
    });

    return pageHead({
      eyebrow: "Academics",
      title: "Results & report cards",
      lead: "Published results for " + esc(student.name) + " \u00B7 " + esc(student.klass) + ".",
      actions: parentExtras() +
        '<span class="term-chip"><i data-lucide="calendar-days"></i>' + esc(r.term) + "</span>" +
        demoBtn("Download report card", "download", "Report card generation and PDF download arrive with the backend build.", "gold")
    }) +
    statGrid([
      stat({ icon: "trending-up", label: "Term average", value: r.average.toFixed(1) + "%", meta: r.term }),
      stat({ icon: "award", label: "Class position", value: ordinal(r.position), meta: "of " + r.classSize + " students" }),
      stat({ icon: "book-open", label: "Subjects offered", value: r.subjects.length, meta: "All assessed" }),
      stat({ icon: "check-circle-2", label: "Result status", value: "Published", meta: "Released " + r.published })
    ]) +
    panel({
      title: "Subject performance",
      sub: r.term + " \u00B7 published " + r.published,
      action: statusPill("Approved", "ok"),
      body: table(
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
    }) +
    callout("info", "<strong>First Term " + SCHOOL.session + ":</strong> mid-term assessment is in progress. Results will be published here after the term examinations and approved by the administration.");
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
        demoBtn("Download record", "download", "Attendance export arrives with the backend build.")
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
      body: table(
        [{ label: "Date" }, { label: "Day" }, { label: "Status" }, { label: "Note" }],
        rows,
        "Recent attendance records for " + student.name
      )
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
        demoBtn("Print timetable", "printer", "Printing opens with the backend build.")
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
    var pct = Math.round((f.paid / f.total) * 100);

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
        demoBtn("Download statement", "download", "Fee statements will be generated from live payment records in the backend build.", "gold")
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
        (f.balance > 0
          ? '<button class="btn btn--gold" type="button" data-demo="Online fee payment arrives with the backend build. Payments are currently receipted at the school accounts office.">Pay ' + money(f.balance) + "</button>"
          : '<button class="btn btn--gold" type="button" data-demo="Receipt download arrives with the backend build.">Download receipt</button>') +
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
    callout("info", "All payments are receipted by the school accounts office. Online payment and downloadable statements will be enabled once the backend is connected.");
  }

  /* ============================================================
     19. ANNOUNCEMENTS VIEW
     ============================================================ */
  function announcementsView() {
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
     20. MODULE PREVIEW (teacher + admin)
     ============================================================ */
  function moduleView(item) {
    var mod = item.module || { summary: "", features: [], backend: "" };

    return pageHead({
      eyebrow: ROLES[state.role].label + " module",
      title: item.label,
      lead: mod.summary,
      actions: statusPill("Planned for the backend", "gold")
    }) +
    '<section class="panel module-card"><div class="panel__body">' +
      '<div class="module-card__top">' +
        '<span class="module-card__icon"><i data-lucide="' + item.icon + '"></i></span>' +
        "<div><h2>What this module will include</h2><p>" + esc(mod.summary) + "</p></div>" +
      "</div>" +
      '<ul class="module-card__list">' + mod.features.map(function (feature) {
        return '<li><i data-lucide="check"></i><span>' + esc(feature) + "</span></li>";
      }).join("") + "</ul>" +
      callout("database", "<strong>Backend contract:</strong> " + esc(mod.backend)) +
      '<button class="btn btn--primary mt-3" type="button" data-view="overview"><i data-lucide="arrow-left"></i> Back to overview</button>' +
    "</div></section>";
  }

})();
