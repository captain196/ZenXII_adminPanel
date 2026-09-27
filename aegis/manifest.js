/**
 * ZenXii Aegis — Module & Contract Manifest
 * =========================================
 * The single source of truth for "who depends on whom". Hand-declared because
 * the dangerous edges (cross-surface Firestore contracts) are invisible to any
 * single-language parser.
 *
 * This is a plain JS module (not YAML) so it loads with zero dependencies and
 * supports comments. Every `regression_targets` entry is a bug you already paid
 * for — see the citations. Grow this file as modules harden.
 *
 * FIELD GUIDE (per module)
 *   match              : keywords/path-fragments that classify a changed file into this module
 *   surfaces           : which surfaces implement this module
 *   firestore          : Firestore collections the module reads/writes
 *   rules_blocks       : firestore.rules match-block names guarding those collections
 *   push_marks         : push MARK_REGISTRY marks this module emits
 *   contracts          : cross-surface invariants that MUST hold (see `contracts` below)
 *   consumers          : modules that read this module's data (blast radius edges)
 *   regression_targets : modules to re-verify when this one changes (the fan-out)
 */

const modules = {
  auth: {
    match: ['login', 'auth', 'MY_Controller', 'claim', 'custom_claims', 'set_password', 'session_year'],
    surfaces: ['admin', 'backend', 'teacher', 'parent'],
    firestore: ['schools', 'Users', 'Parents'],
    rules_blocks: ['schools', 'users'],
    contracts: ['claim_key', 'force_set_password'],
    consumers: ['*'], // auth underpins everything
    regression_targets: ['dashboard', 'attendance', 'homework', 'fees', 'chat', 'stories'],
    notes: 'Synthetic-email Firebase Auth; claims-as-contract. Blast radius = whole app.'
  },

  attendance: {
    match: ['attendance', 'Attendance', 'geofence', 'punch', 'regulariz'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['attendance', 'attendanceEventsFired', 'staff_metrics_daily'],
    rules_blocks: ['attendance', 'attendanceEventsFired'],
    push_marks: ['ATTENDANCE_MARKED', 'LEAVE_APPROVED'],
    contracts: ['session_scope', 'claim_key'],
    consumers: ['dashboard', 'reports', 'analytics', 'payroll', 'parent_home'],
    regression_targets: ['login', 'dashboard', 'payroll', 'parent_home', 'reports', 'analytics'],
    notes: 'attendance feeds payroll — clobber risk. Parent "Vacation today" false-positive class. world-open attendanceEventsFired rule (fixed).'
  },

  homework: {
    match: ['homework', 'Homework', 'assignment', 'Assignment'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['homework'],
    rules_blocks: ['homework'],
    push_marks: ['HOMEWORK_ASSIGNED'],
    contracts: ['session_scope'],
    consumers: ['dashboard', 'parent_home', 'analytics'],
    regression_targets: ['login', 'dashboard', 'parent_home'],
    notes: 'session-filter reads, end-of-day dueDate, Parent overdue/deep-link. DB = graderadmin/(default).'
  },

  exam: {
    match: ['exam', 'Exam', 'result', 'Result', 'marks', 'Marks', 'grade'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['exams', 'marks', 'results'],
    rules_blocks: ['exams', 'marks', 'results'],
    push_marks: ['RESULT_PUBLISHED'],
    contracts: ['session_scope', 'publish_gate'],
    consumers: ['dashboard', 'reports', 'parent_home', 'analytics'],
    regression_targets: ['login', 'dashboard', 'reports', 'parent_home'],
    notes: 'marks/results IDOR + result-forgery rules; staging→promote publish gate. DEPLOY-PENDING 4 indexes.'
  },

  fees: {
    match: ['fee', 'Fee', 'payment', 'Payment', 'razorpay', 'Razorpay', 'invoice', 'concession'],
    surfaces: ['admin', 'parent', 'backend'],
    firestore: ['fees', 'feeInvoices', 'payments'],
    rules_blocks: ['fees', 'payments'],
    push_marks: ['FEE_DUE', 'FEE_PAID'],
    contracts: ['payment_verify'],
    consumers: ['dashboard', 'reports', 'analytics', 'parent_home'],
    regression_targets: ['login', 'dashboard', 'reports', 'parent_home'],
    notes: 'P0, 4-platform, freeze choreography. Payment mock-fail-closed / amount-reverify. Razorpay proguard gotcha.'
  },

  stories: {
    match: ['story', 'Story', 'stories', 'Stories', 'onStoryCreated', 'storyAudience', 'storyCounters', 'storiesCleanup'],
    surfaces: ['admin', 'teacher', 'parent', 'functions'],
    // `viewers` and `reactions` are collection-GROUP matches
    // (`match /{path=**}/viewers/{uid}`), so they carry no `story` in the name
    // and only an explicit mapping attributes them correctly.
    firestore: ['stories', 'storyAudience', 'storyReactions', 'storyViews', 'viewers', 'reactions'],
    rules_blocks: ['stories', 'storyAudience', 'storyReactions', 'viewers', 'reactions'],
    push_marks: ['STORY_POSTED'],
    contracts: ['push_mark_registry', 'storage_mime'],
    consumers: ['parent_home', 'teacher_home'],
    regression_targets: ['login', 'parent_home', 'push'],
    notes: '13 canonical files. push drop/deep-link, delete media leak, storage MIME gate. G1 = 6 CFs/6 indexes DEPLOY-PENDING.'
  },

  notice: {
    match: ['notice', 'Notice', 'circular', 'Circular', 'communication', 'Communication'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['notices', 'circulars'],
    rules_blocks: ['notices', 'circulars'],
    push_marks: ['NOTICE_POSTED', 'CIRCULAR_POSTED'],
    contracts: ['push_mark_registry', 'audience_scope'],
    consumers: ['parent_home', 'teacher_home', 'dashboard'],
    regression_targets: ['login', 'parent_home', 'push'],
    notes: 'audience over-exposure, double-encode, real-time+deep-link. Entangled with rules deploy.'
  },

  leave: {
    match: ['leave', 'Leave', 'vacation'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['leaveRequests', 'leaveBalances'],
    rules_blocks: ['leaveRequests', 'leaveBalances'],
    push_marks: ['LEAVE_APPROVED', 'LEAVE_REJECTED'],
    contracts: ['session_scope'],
    consumers: ['attendance', 'payroll', 'dashboard', 'parent_home'],
    regression_targets: ['login', 'attendance', 'payroll', 'parent_home'],
    notes: 'self-approval + cross-cohort leak, IDOR, cancel docId corruption, balance CAS+zero-floor, half-day 0.5.'
  },

  timetable: {
    match: ['timetable', 'Timetable', 'period', 'Period', 'subjectAssignment', 'substitute'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['timetables', 'subjectAssignments'],
    rules_blocks: ['timetables', 'subjectAssignments'],
    contracts: ['teacher_id_sync'],
    consumers: ['dashboard', 'attendance', 'parent_home'],
    regression_targets: ['login', 'dashboard', 'attendance'],
    notes: 'timetables carry stale teacherId vs subjectAssignments → blank timetable. Times are 12h "10:45AM" strings.'
  },

  chat: {
    match: ['chat', 'Chat', 'message', 'Message', 'Push_service'],
    surfaces: ['admin', 'teacher', 'parent', 'backend'],
    firestore: ['chats', 'messages'],
    rules_blocks: ['chats', 'messages'],
    push_marks: ['CHAT_MESSAGE'],
    contracts: ['push_mark_registry', 'claim_key'],
    consumers: ['parent_home', 'teacher_home'],
    regression_targets: ['login', 'push'],
    notes: 'stale STA0067 teacherId on account-switch breaks Chat. PHP Push_service sends chat marks.'
  },

  gallery: {
    match: ['gallery', 'Gallery', 'album', 'Album', 'event', 'Event', 'media', 'Media'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['galleryAlbums', 'galleryMedia'],
    rules_blocks: ['galleryAlbums', 'galleryMedia'],
    contracts: ['storage_mime'],
    consumers: ['parent_home', 'teacher_home'],
    regression_targets: ['login', 'parent_home'],
    notes: 'deleteMedia IDOR, uploadMedia type-bypass, Storage gate. C2 RTDB world-open OPEN.'
  },

  redflags: {
    match: ['redflag', 'RedFlag', 'flag', 'Flag', 'studentFlags'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['studentFlags'],
    rules_blocks: ['studentFlags'],
    push_marks: ['FLAG_RESOLVED'],
    contracts: ['push_mark_registry'],
    consumers: ['dashboard', 'parent_home'],
    regression_targets: ['login', 'dashboard', 'parent_home'],
    notes: 'RBAC grantable, FLAG_RESOLVED push, docId-drift. undeployed studentFlags indexes.'
  },

  push: {
    match: ['push', 'Push', 'fcm', 'FCM', 'notification', 'Notification', 'pushRequests', 'emit_push'],
    surfaces: ['admin', 'backend', 'functions', 'teacher', 'parent'],
    firestore: ['pushRequests'],
    rules_blocks: ['pushRequests'],
    contracts: ['push_mark_registry'],
    consumers: ['*'],
    regression_targets: ['stories', 'notice', 'chat', 'attendance', 'homework', 'fees', 'leave', 'redflags'],
    notes: 'Universal dispatcher: ALL push on one CF pushRequests MARK_REGISTRY (19 marks). HARD no-double-send.'
  },

  dashboard: {
    match: ['dashboard', 'Dashboard', 'home', 'Home', 'analytics_rollup'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['schools', 'analytics'],
    consumers: [],
    regression_targets: ['login'],
    notes: 'Teacher dashboard = today-schedule agenda w/ live-period lift. Rollup totalSchools historical-inflation bug (B2.3.4).'
  },

  payroll: {
    match: ['payroll', 'Payroll', 'salary', 'Salary'],
    surfaces: ['admin'],
    firestore: ['payroll', 'staff_metrics_daily'],
    rules_blocks: ['payroll'],
    contracts: ['session_scope'],
    consumers: ['reports', 'analytics'],
    regression_targets: ['attendance', 'leave'],
    notes: 'attendance/leave feed payroll — clobber risk. No RBAC gate (latent, layer-2).'
  },

  admission: {
    match: ['admission', 'Admission', 'crm', 'ats', 'Ats', 'enroll', 'lead', 'applicant', 'inquiry', 'inquir', 'waitlist', 'pipeline'],
    surfaces: ['admin'], // admin-only; public_form is a public microsite but still admin-owned. Certs OUT.
    firestore: ['crmApplications', 'crmInquiries', 'admissionPayments', 'crmEnrollLocks'],
    rules_blocks: ['crmApplications', 'crmInquiries', 'admissionPayments', 'crmEnrollLocks'],
    contracts: ['payment_verify'],
    consumers: ['fees', 'dashboard', 'analytics', 'reports'], // an enrolled applicant becomes a student + generates fees
    regression_targets: ['login', 'fees', 'dashboard', 'analytics'],
    notes: 'Admin-only CRM. 2026-07-14 watertight pass: payment mock-fail-closed/rate-limit/token-gate/amount-reverify/checked-writes + CRM RBAC + XSS esc + enroll-lock + stage-lockstep. DEFERRED: enroll-orphan, webhook, feeSettings-secret. IA: 8 flat items → 6 funnel sections (Leads+Pipeline → Applications views). enroll → fees.'
  },

  // ── Aggregator / cross-cutting nodes (targets of many modules' regression fan-out) ──
  login: {
    match: ['login', 'Login', 'signin', 'sign_in', 'SignIn'],
    surfaces: ['admin', 'teacher', 'parent'],
    contracts: ['claim_key', 'force_set_password'],
    consumers: [],
    regression_targets: ['dashboard'],
    notes: 'Login entry flow — claims are read here. First-login force set-password.'
  },
  parent_home: {
    match: ['parent_home', 'ParentHome', 'ParentHomeScreen', 'HomeFeed'],
    surfaces: ['parent'],
    consumers: [],
    regression_targets: ['login'],
    notes: 'Parent app home aggregator — surfaces homework/attendance/stories/notice/fees.'
  },
  teacher_home: {
    match: ['teacher_home', 'TeacherHome', 'TeacherHomeScreen'],
    surfaces: ['teacher'],
    consumers: [],
    regression_targets: ['login', 'dashboard'],
    notes: 'Teacher app home — today-schedule + module entry points.'
  },
  reports: {
    match: ['report', 'Report', 'Reports'],
    surfaces: ['admin'],
    firestore: ['reports'],
    consumers: [],
    regression_targets: [],
    notes: 'Admin reporting surface — consumes attendance/exam/fees/payroll.'
  },
  analytics: {
    match: ['analytics', 'Analytics', 'rollup', 'Rollup', '_analytics_'],
    surfaces: ['admin'],
    firestore: ['analytics'],
    consumers: [],
    regression_targets: [],
    notes: 'Cross-tenant analytics rollups. B2.3.4 totalSchools historical-inflation bug.'
  },

  // ── Added for the Rules Sentinel ────────────────────────────────────────
  // These four guard the most security-critical blocks in firestore.rules but
  // had no module, so every change to them was reported as "unmapped" — no
  // blast radius, no regression targets. Collections listed here are the ones
  // the rules file actually declares.

  sis: {
    match: ['student', 'Student', 'staff', 'Staff', 'section', 'Section', 'subject', 'Subject', 'roster', 'import'],
    surfaces: ['admin', 'teacher', 'parent'],
    firestore: ['students', 'staff', 'staffPrivate', 'sections', 'subjects', 'subjectAssignments', 'submissions'],
    rules_blocks: ['students', 'staff', 'staffPrivate', 'sections', 'subjects', 'subjectAssignments'],
    contracts: ['claim_key', 'session_scope'],
    consumers: ['*'], // the roster underpins every module that scopes by class/section
    regression_targets: ['attendance', 'homework', 'exam', 'fees', 'timetable', 'leave', 'payroll'],
    notes: 'Student/staff roster — the identity spine. staffPrivate holds split PII (owner-readable only); never merge it back into staff. Doc keys are {schoolId}_{entityId}.'
  },

  rbac: {
    match: ['rbac', 'RBAC', 'capabilit', 'Capabilit', 'permission', 'staffRoles', 'role_access'],
    surfaces: ['admin', 'functions', 'teacher', 'parent'],
    firestore: ['rbacRoles', 'staffCapabilities'],
    rules_blocks: ['rbacRoles', 'staffCapabilities'],
    contracts: ['claim_key'],
    consumers: ['*'],
    regression_targets: ['auth', 'sis', 'attendance', 'fees', 'exam', 'payroll'],
    notes: 'One unified catalogue (schools.staffRoles) serving admin RBAC + staff HR roles; mirrored to functions/rbac_modules.json. hasCapability() FAILS OPEN when staffCapabilities is absent — adding a gate cannot lock out un-migrated accounts.'
  },

  operations: {
    match: ['library', 'Library', 'transport', 'Transport', 'route', 'vehicle', 'hostel', 'inventory', 'asset'],
    surfaces: ['admin'],
    firestore: ['libraryBooks', 'libraryIssues', 'libraryFines', 'routes', 'vehicles', 'studentRoutes'],
    rules_blocks: ['libraryBooks', 'libraryIssues', 'libraryFines', 'routes', 'vehicles', 'studentRoutes'],
    consumers: ['dashboard', 'reports'],
    regression_targets: ['login', 'dashboard', 'sis'],
    notes: 'Split out of the coarse Operations permission into children with umbrella inherit. DEPLOY CODE BEFORE the data migration or existing users lock out.'
  },

  superadmin: {
    match: ['superadmin', 'superAdmin', 'tenant', 'Tenant', 'plan', 'subscription', 'billing', 'schoolControl'],
    surfaces: ['admin'],
    firestore: ['superAdmins', 'saIpRateLimit', 'schoolControl', 'plans', 'subscriptions', 'invoices',
                'revenueByPeriod', 'schoolSsa', 'tenantAudit', 'schoolCodeIndex', 'schoolNameIndex',
                'billingClaims', 'tenantPublic', 'superadminAuthState'],
    rules_blocks: ['superAdmins', 'schoolControl', 'plans', 'subscriptions', 'tenantPublic'],
    consumers: ['auth'],
    regression_targets: ['auth', 'login'],
    notes: 'Cross-tenant control plane — the ONLY place rules legitimately read across schools. Every new superadmin/* AJAX POST must be added to csrf_exclude_uris or CI CSRF returns a blank 403.'
  }
};

/**
 * CONTRACTS — cross-surface invariants. Each maps to a contract check in contracts/.
 * on_break: BLOCK = release gate hard-stops; WARN = surfaced but non-blocking.
 */
const contracts = {
  claim_key: {
    invariant: 'Every custom-claims writer MUST dual-emit school_id (snake, for rules) AND schoolId (camel, for admin login).',
    check: 'claim_key',
    breakers: [
      'application/core/MY_Controller.php',
      'application/controllers/Admin_login.php',
      'application/controllers/Auth_api.php',
      'application/controllers/Auth_claims_backfill.php',
      'application/controllers/Superadmin_login.php'
    ],
    on_break: 'BLOCK',
    why: 'Mismatch = silent PERMISSION_DENIED for every app read. Real homework DB = graderadmin/(default).'
  },
  push_mark_registry: {
    invariant: 'Each push mark is emitted by EXACTLY ONE sender (CF | PHP poller | Push_service). Never dup, never drop.',
    check: 'push_mark_registry',
    on_break: 'BLOCK',
    why: 'Duplicate = double notification; drop = silent no-notify. 19 marks partition the mark-space.'
  },
  session_scope: {
    invariant: 'Reads of attendance/homework/exam/leave MUST filter by session_year (and schoolId).',
    check: 'session_scope',
    on_break: 'WARN',
    why: 'Unscoped read leaks prior-year data or shows blank current-year.'
  },
  rules_deny_default: {
    invariant: 'firestore.rules stays deny-by-default. No match block allows read/write without auth + schoolId.',
    check: 'rules_deny_default',
    on_break: 'BLOCK',
    why: 'A world-open block exposes every tenant. Rules ship as one whole file — one bad block ships all.'
  },
  firestore_index: {
    invariant: 'Any composite query (multiple where / where+orderBy) has a matching index deployed BEFORE the code.',
    check: 'firestore_index',
    on_break: 'WARN',
    why: 'Missing index = query 500 in prod. Indexes deploy separately + build async.'
  },
  responsive_scroll: {
    invariant: 'Compose dialogs/sheets/forms scroll and fit small + landscape screens.',
    check: 'responsive_scroll',
    on_break: 'WARN',
    why: 'Non-scroll clipping is a recurring Teacher/Parent bug on small + landscape.'
  },
  // Declared invariants without a dedicated static check yet (documented, surfaced in impact notes):
  force_set_password: { invariant: 'Force set-new-password on first login for new + reset users.', on_break: 'WARN', check: null },
  publish_gate:       { invariant: 'Exam results go staging→promote; never publish directly.', on_break: 'WARN', check: null },
  storage_mime:       { invariant: 'Uploaded media MIME-gated server-side before Storage write.', on_break: 'WARN', check: null },
  audience_scope:     { invariant: 'Notice/circular audience scoping enforced (no over-exposure).', on_break: 'WARN', check: null },
  teacher_id_sync:    { invariant: 'timetable.teacherId stays in sync with subjectAssignments.', on_break: 'WARN', check: null },
  payment_verify:     { invariant: 'Payment amount re-verified server-side; mock fails closed; token-gated.', on_break: 'WARN', check: null }
};

module.exports = { modules, contracts };
