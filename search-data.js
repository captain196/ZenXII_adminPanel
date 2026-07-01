// search-data.js — static index for the landing-page global search.
//   • MODULES       : the 21 core platform modules (School ERP spec).
//   • FEATURE_INDEX : mirror of the FEATURES object in feature.html — keep in sync.
//   • FAQ_INDEX     : the FAQ questions in index.html (#faq) — keep in sync with that section.
//   • PAGES         : the top-level sections/pages of the site (nav anchors + standalone pages).
//   • Blog results come from POSTS in blog-data.js (loaded separately on the landing page).
// Module results link to the #features section; features → feature.html?key=…; blog → blog-post.html?id=…
// PRIVACY: this index is 100% public marketing content — no school, student, staff or tenant data. Never add any.

const MODULES = [
  { name: "Admissions & Enquiry CRM", desc: "Lead capture to enrollment.", keywords: "leads enquiry walk-in website social referral funnel online application document verification interview merit list offer letter batch enrollment migration crm" },
  { name: "Student Information System (SIS)", desc: "Complete lifetime student record.", keywords: "student master profile personal family medical academic history attendance fee behaviour certificates documents transport hostel lifecycle promotion alumni sis" },
  { name: "Attendance Management", desc: "Every attendance source, one view.", keywords: "manual rfid biometric face recognition qr daily period-wise teacher staff analytics defaulter parent notifications" },
  { name: "Academic Management", desc: "Curriculum and lesson planning.", keywords: "boards classes sections subjects combinations assessment structures lesson plans teaching schedules progress tracking curriculum" },
  { name: "Timetable Management", desc: "Build timetables without clashes.", keywords: "drag drop builder conflict detection teacher availability room allocation laboratory scheduling substitute auto generation timetable" },
  { name: "Homework Management", desc: "Assign, attach, track completion.", keywords: "homework creation file image video attachments submission tracking completion analytics parent visibility late submission" },
  { name: "Examination Management", desc: "Exams, marks, results, ranks.", keywords: "exam scheduling subject mapping marks entry grade calculation result processing ranking hall ticket internal assessment practical examination" },
  { name: "Report Card Management", desc: "Grades to delivered report cards.", keywords: "term annual reports cumulative grading performance analytics pdf generation parent delivery digital archive report card" },
  { name: "Fees Management", desc: "Structures, collection, defaulters.", keywords: "fee structure class category installment online offline cash cheque upi payment gateway demands concessions scholarships exemptions refunds defaulter fees" },
  { name: "Accounting & Finance", desc: "Double-entry books and reports.", keywords: "accounting double entry general ledger chart of accounts trial balance profit loss balance sheet cash flow bank reconciliation period locking audit voucher finance" },
  { name: "HR & Payroll", desc: "People, pay and statutory.", keywords: "employee profiles documents contract payroll salary structures processing statutory deductions pf esi salary slips leave appraisals promotions exit hr human resource" },
  { name: "Recruitment (ATS)", desc: "Hire from vacancy to onboarding.", keywords: "vacancy publishing candidate applications resume screening interview scheduling evaluation workflow offer management candidate onboarding ats recruitment hiring" },
  { name: "Communication Center", desc: "Reach everyone, every channel.", keywords: "push notifications sms email in-app messaging announcements circulars parent teacher emergency broadcasts engagement communication" },
  { name: "Transport Management", desc: "Routes, vehicles, live tracking.", keywords: "route planning vehicle management driver gps tracking student allocation pickup drop geo-fencing route attendance transport bus" },
  { name: "Hostel Management", desc: "Rooms, occupancy, mess, safety.", keywords: "room allocation occupancy tracking meal plans complaints hostel attendance visitor management warden boarding" },
  { name: "Library Management", desc: "Catalog, circulation, fines.", keywords: "catalog management book circulation issue return fine management digital catalog search member management library" },
  { name: "Inventory & Asset Management", desc: "Track assets and stock.", keywords: "asset registry vendor management purchase orders stock tracking depreciation asset lifecycle inventory procurement" },
  { name: "PTM Management", desc: "Parent-teacher meetings made simple.", keywords: "parent slot booking teacher schedules attendance recording ptm feedback follow-up actions meeting" },
  { name: "Events & Media Gallery", desc: "Calendar, events and memories.", keywords: "school calendar event planning photo galleries video stories event registrations media gallery" },
  { name: "Certificates & Documents", desc: "Generate and verify documents.", keywords: "bonafide character conduct transfer certificate custom templates qr verification documents tc" },
  { name: "Analytics & Reporting", desc: "Dashboards and 50+ reports.", keywords: "dashboards admissions attendance fee academic hr transport reporting operational reports excel pdf exports scheduled analytics insights" }
];

const FEATURE_INDEX = [
  { key: "student-academics",   app: "ZenXii Admin Panel", title: "Student & academics" },
  { key: "staff-hr",            app: "ZenXii Admin Panel", title: "Staff & HR" },
  { key: "finance",             app: "ZenXii Admin Panel", title: "Finance & accounting" },
  { key: "communication-admin", app: "ZenXii Admin Panel", title: "Communication & engagement" },
  { key: "operations",          app: "ZenXii Admin Panel", title: "Operations" },
  { key: "security",            app: "ZenXii Admin Panel", title: "Administration & security" },
  { key: "superadmin",          app: "ZenXii Admin Panel", title: "Superadmin (multi-school)" },
  { key: "public",              app: "ZenXii Admin Panel", title: "Public surfaces" },
  { key: "t-classroom",         app: "ZenXii Teacher",     title: "Classroom" },
  { key: "t-communication",     app: "ZenXii Teacher",     title: "Communication" },
  { key: "t-self-service",      app: "ZenXii Teacher",     title: "Self-service" },
  { key: "t-school-life",       app: "ZenXii Teacher",     title: "School life" },
  { key: "p-day",               app: "ZenXii Parent",      title: "My child's school day" },
  { key: "p-family",            app: "ZenXii Parent",      title: "Family & engagement" },
  { key: "p-money",             app: "ZenXii Parent",      title: "Money & operations" }
];

// FAQ questions shown in index.html's #faq section. Search jumps the user to #faq.
// Keep the questions/keywords in sync with that section.
const FAQ_INDEX = [
  { q: "How do parents reset their password?", keywords: "parent password reset forgot recover login office principal super admin no otp self-service recovery" },
  { q: "What happens if a parent has multiple children in the school?", keywords: "parent multiple children siblings one login single account discover automatic family" },
  { q: "Can teachers see or edit each other's classes?", keywords: "teacher see edit other classes data scope subject assignment permission access privacy isolation" }
];

// Top-level sections / pages of the site. Search takes the user straight to the anchor or page.
const PAGES = [
  { title: "Products",       desc: "Admin panel, Teacher app, Parent app",      href: "#products", keywords: "products apps admin panel teacher app parent app three platform download" },
  { title: "Features",       desc: "Everything ZenXii does",                    href: "#features", keywords: "features modules capabilities what does functionality overview" },
  { title: "About",          desc: "About ZenXii",                              href: "#about",    keywords: "about company story mission who we are team founder" },
  { title: "Benefits",       desc: "Why schools choose ZenXii",                 href: "#benefits", keywords: "benefits why value outcomes results advantages roi save time" },
  { title: "How it works",   desc: "Onboarding & setup",                        href: "#how",      keywords: "how it works onboarding setup process steps getting started implementation rollout migrate" },
  { title: "Who it's for",   desc: "Schools, principals, teachers, parents",    href: "#who",      keywords: "who for schools principals teachers parents administrators audience use cases" },
  { title: "FAQ",            desc: "Common questions",                          href: "#faq",      keywords: "faq questions answers help support common doubts" },
  { title: "Contact / Book a demo", desc: "Talk to the team",                   href: "#contact",  keywords: "contact demo sales email phone address get in touch book schedule call talk pricing quote" },
  { title: "Blog",           desc: "Articles & updates",                        href: "blog.html", keywords: "blog articles news updates writing posts stories insights" }
];
