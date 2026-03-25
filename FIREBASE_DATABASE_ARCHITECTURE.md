# Smart School System — Firebase RTDB Architecture Blueprint
### Version 2.0 | Designed: 2026-03-24
### Principal Architect: AI Systems Architect

---

## 1. ARCHITECTURE PHILOSOPHY

### Core Principles

| # | Principle | Why |
|---|-----------|-----|
| 1 | **Flat-First** | Firebase downloads ALL children of a node. Deep nesting = wasted bandwidth. Every entity gets its own top-level path. |
| 2 | **School-Partitioned** | Every data path includes `{sId}` (school ID) as the first segment after entity type. Enables multi-school and clean security rules. |
| 3 | **Hot/Cold Separation** | Real-time data (GPS, chat, live attendance) in separate lightweight nodes from rarely-changing data (profiles, config). |
| 4 | **Pre-Computed Dashboards** | Mobile clients NEVER aggregate raw data. Cloud Functions write dashboard summaries. One read = full dashboard. |
| 5 | **Fan-Out Writes** | A single user action (e.g., "mark absent") triggers a multi-path atomic write updating attendance, notifications, dashboard, and parent feed simultaneously. |
| 6 | **Index Nodes** | Dedicated `/Idx/` paths for every cross-entity lookup. No client-side joins. |
| 7 | **Append-Only Audit** | Every mutation logged to `/AuditLog/`. Immutable. Financial nodes double-logged. |
| 8 | **Offline-First Mobile** | Critical paths (dashboard, timetable, attendance) designed for Firebase persistence cache — small nodes, predictable structure. |
| 9 | **Denormalized Summaries** | Store `{name, photo}` alongside foreign keys. Avoids extra reads at the cost of fan-out writes on profile change. |
| 10 | **Academic Year Scoping** | Transactional data (marks, fees, attendance) scoped by `{ayId}` (academic year). Historical data preserved, current year fast. |

### Auth Split
- **MongoDB (Render)**: User authentication only — login, register, JWT tokens, password reset
- **Firebase RTDB**: ALL application data — profiles, academics, operations, communication, everything else

### Key Notation
```
{sId}    = School ID (e.g., "SCH_9738C22243")
{ayId}   = Academic Year ID (e.g., "2026-27")
{uid}    = Firebase Auth UID / MongoDB user ID
{secKey} = Section composite key (e.g., "9_A" = Class 9, Section A)
```

---

## 2. DATA TIER CLASSIFICATION

| Tier | Update Frequency | Examples | Cache Strategy |
|------|-----------------|----------|---------------|
| **T0 — Static** | Monthly/Yearly | School config, class list, fee structure | Cache aggressively, refresh on app start |
| **T1 — Warm** | Weekly | Student profiles, staff profiles, curriculum | Cache with TTL (1 hour) |
| **T2 — Hot** | Daily | Attendance, homework, marks entry | Real-time listener during active use |
| **T3 — Live** | Seconds | GPS tracking, chat messages, online status | Persistent listener, no cache |
| **T4 — Batch** | On-demand | Results computation, report cards, analytics | Computed by Cloud Functions, read-only for clients |

---

## 3. COMPLETE NODE TREE

```
Firebase RTDB Root
│
├── /System/                                        ← Global platform config
│   ├── /Config                                     ← Platform settings
│   ├── /Plans/{planId}                             ← Subscription plans
│   ├── /Stats                                      ← Platform-wide statistics
│   ├── /Maintenance                                ← Maintenance mode flags
│   └── /Versions                                   ← Min app version enforcement
│
├── /Schools/{sId}/                                 ← School identity & config
│   ├── /Meta                                       ← Profile, branding, contact
│   ├── /Config                                     ← Academic config (classes, board, exams, streams)
│   ├── /FeeConfig/{ayId}                           ← Fee structure templates
│   ├── /Roles                                      ← RBAC role definitions
│   ├── /StaffRoles                                 ← Staff role config
│   ├── /AcademicYears/{ayId}                       ← Year metadata + status
│   ├── /Departments/{deptId}                       ← Department registry
│   ├── /Rooms/{roomId}                             ← Physical room registry
│   ├── /GradingScales/{scaleId}                    ← Grading configurations
│   └── /Sessions/{sessionId}                       ← Active sessions
│
├── /Users/{uid}                                    ← Thin auth profile (role→entity link)
│
├── /Staff/{sId}/{staffId}                          ← All staff (teaching + non-teaching)
├── /Students/{sId}/{studentId}                     ← 360° student profile
├── /Parents/{sId}/{parentId}                       ← Parent profile + child links
│
├── /Sections/{sId}/{ayId}/{secKey}/                ← Class-section metadata
│   ├── /roster                                     ← Student list (denormalized names)
│   ├── /subjects                                   ← Subject-teacher assignments
│   └── /timetable/{day}                            ← Period schedule
│
├── /Curriculum/{sId}/{ayId}/{subjectId}/           ← Syllabus → calendar mapping
│   └── /{chapterId}                                ← Chapter details + completion %
│
├── /LessonPlans/{sId}/{planId}                     ← Teacher lesson plans
├── /QuestionBank/{sId}/{qId}                       ← Tagged question repository
│
├── /Attendance/{sId}/{ayId}/{date}/{secKey}         ← Daily attendance (granular)
├── /AttendancePeriod/{sId}/{ayId}/{date}/{secKey}/{period}  ← Period-wise
├── /AttendanceSummary/{sId}/{ayId}/{month}/{studentId}      ← Pre-computed monthly
├── /StaffAttendance/{sId}/{date}/{staffId}          ← Staff daily attendance
├── /StaffAttendanceSummary/{sId}/{month}/{staffId}  ← Staff monthly summary
├── /LeaveApplications/{sId}/{leaveId}               ← Student leave requests
│
├── /Homework/{sId}/{hwId}                           ← Homework definitions
├── /Submissions/{sId}/{hwId}/{studentId}            ← Student submissions
├── /HomeworkFeed/{sId}/{ayId}/{secKey}/{hwId}        ← Class-section homework index
│
├── /Exams/{sId}/{ayId}/{examId}                     ← Exam definitions + date sheet
├── /HallTickets/{sId}/{ayId}/{examId}/{studentId}   ← Generated hall tickets
├── /Marks/{sId}/{ayId}/{examId}/{secKey}/{studentId} ← Raw marks entry
├── /MarksLock/{sId}/{ayId}/{examId}/{secKey}         ← Lock status per section
├── /Results/{sId}/{ayId}/{studentId}/{examId}        ← Computed results per exam
├── /ReportCards/{sId}/{ayId}/{studentId}             ← Term/Annual report card
├── /MeritList/{sId}/{ayId}/{examId}/{secKey}         ← Ranked student list
│
├── /FeeAllocation/{sId}/{ayId}/{studentId}           ← Per-student fee demand
├── /Transactions/{sId}/{txnId}                       ← Payment transactions
├── /FeeLedger/{sId}/{ayId}/{studentId}/{entryId}     ← Double-entry ledger
├── /FeeDefaulters/{sId}/{ayId}/{studentId}           ← Defaulter flags
├── /Concessions/{sId}/{concessionId}                 ← Fee concession requests
├── /OnlineOrders/{sId}/{orderId}                     ← Payment gateway orders
├── /Receipts/{sId}/{receiptId}                       ← Receipt index
├── /AccountBook/{sId}/{ayId}/{voucherId}             ← Accounting vouchers
├── /Salary/{sId}/{month}/{staffId}                   ← Payroll disbursement
│
├── /ChatMeta/{chatId}                                ← Chat room metadata
├── /Chats/{chatId}/{msgId}                           ← Chat messages
├── /UserChats/{uid}/{chatId}                         ← User's chat index
├── /Circulars/{sId}/{circId}                         ← Notices & circulars
├── /CircularReads/{sId}/{circId}/{uid}               ← Read receipts
├── /Notifications/{uid}/{notifId}                    ← Per-user notification feed
├── /NotifBadge/{uid}                                 ← Unread counts by category
├── /MsgTemplates/{sId}/{templateId}                  ← Reusable message templates
│
├── /Routes/{sId}/{routeId}                           ← Transport route definitions
├── /Vehicles/{sId}/{vehicleId}                       ← Vehicle registry
├── /VehicleLive/{sId}/{vehicleId}                    ← LIVE GPS (updated every 10s)
├── /StudentRoutes/{sId}/{studentId}                  ← Student-route assignment
├── /TripLogs/{sId}/{vehicleId}/{date}                ← Trip records
├── /SOSAlerts/{sId}/{alertId}                        ← Emergency alerts
├── /GeoFences/{sId}/{fenceId}                        ← Geo-fence definitions
│
├── /Hostel/{sId}/Rooms/{roomId}                      ← Room allocation
├── /Hostel/{sId}/Allocations/{studentId}             ← Student-room mapping
├── /Hostel/{sId}/MealMenu/{weekKey}                  ← Weekly meal menu
├── /Hostel/{sId}/MealAttendance/{date}/{meal}        ← Meal tracking
├── /Hostel/{sId}/Visitors/{logId}                    ← Visitor log
├── /Hostel/{sId}/Complaints/{ticketId}               ← Maintenance tickets
├── /Hostel/{sId}/WeekendLeave/{leaveId}              ← Leave requests
├── /Hostel/{sId}/CurfewCheck/{date}                  ← Lights-out check
│
├── /Library/{sId}/Catalog/{bookId}                   ← Book catalog
├── /Library/{sId}/Issues/{txnId}                     ← Issue/return log
├── /Library/{sId}/StudentBooks/{studentId}            ← Books with student
├── /Library/{sId}/Fines/{fineId}                     ← Overdue fines
├── /Library/{sId}/EBooks/{ebookId}                   ← E-library
├── /Library/{sId}/Reservations/{resId}               ← Book reservations
│
├── /Incidents/{sId}/{incidentId}                     ← Behavior incidents
├── /MeritPoints/{sId}/{studentId}/{entryId}          ← Merit/demerit ledger
├── /Escalations/{sId}/{escalationId}                 ← Escalation chain
├── /CounselorNotes/{sId}/{studentId}/{noteId}        ← Confidential notes
├── /Detentions/{sId}/{detentionId}                   ← Detention records
├── /BehaviorSummary/{sId}/{ayId}/{studentId}         ← Pre-computed behavior score
│
├── /HR/{sId}/LeaveBalances/{ayId}/{staffId}          ← Leave quotas
├── /HR/{sId}/Appraisals/{ayId}/{staffId}             ← Performance reviews
├── /HR/{sId}/Training/{trainingId}                   ← PD sessions
├── /HR/{sId}/Claims/{claimId}                        ← Reimbursement claims
├── /HR/{sId}/Recruitment/{vacancyId}                 ← Job postings
├── /HR/{sId}/Contracts/{staffId}                     ← Contract tracking
│
├── /Admissions/{sId}/{ayId}/Config                   ← Admission settings
├── /Admissions/{sId}/{ayId}/Applications/{appId}     ← Application pipeline
├── /Admissions/{sId}/{ayId}/MeritList/{classKey}     ← Auto-ranked list
├── /Admissions/{sId}/{ayId}/Analytics                ← Funnel metrics
│
├── /Assets/{sId}/{assetId}                           ← Asset register
├── /Inventory/{sId}/{itemId}                         ← Consumable stock
├── /PurchaseOrders/{sId}/{poId}                      ← Purchase workflow
├── /Vendors/{sId}/{vendorId}                         ← Vendor directory
├── /Maintenance/{sId}/{scheduleId}                   ← Preventive maintenance
│
├── /Events/{sId}/{eventId}                           ← Calendar events
├── /PTM/{sId}/{ptmId}                                ← PTM configuration
├── /PTMSlots/{sId}/{ptmId}/{staffId}/{slotId}        ← Available slots
├── /PTMBookings/{sId}/{ptmId}/{studentId}            ← Booked slots
├── /Surveys/{sId}/{surveyId}                         ← Feedback forms
├── /SurveyResponses/{sId}/{surveyId}/{uid}           ← Responses
├── /LostFound/{sId}/{itemId}                         ← Lost & found board
│
├── /Dashboards/{sId}/Admin                           ← Admin dashboard cache
├── /Dashboards/{sId}/Teacher/{staffId}               ← Teacher dashboard cache
├── /Dashboards/{sId}/Parent/{parentId}               ← Parent dashboard cache
├── /Dashboards/{sId}/Student/{studentId}             ← Student dashboard cache
├── /Analytics/{sId}/{reportType}/{period}            ← Analytics snapshots
│
├── /Idx/                                              ← ALL INDEXES
│   ├── /StudentBySection/{sId}/{ayId}/{secKey}/{studentId}
│   ├── /SectionByStudent/{sId}/{ayId}/{studentId}
│   ├── /TeacherSections/{sId}/{ayId}/{staffId}/{secKey}
│   ├── /ClassTeacher/{sId}/{ayId}/{secKey}
│   ├── /ParentChildren/{sId}/{parentId}/{studentId}
│   ├── /StudentParent/{sId}/{studentId}/{parentId}
│   ├── /SiblingGroup/{sId}/{groupId}/{studentId}
│   ├── /StaffByDept/{sId}/{deptId}/{staffId}
│   ├── /StudentByRoute/{sId}/{routeId}/{studentId}
│   ├── /StudentByHostel/{sId}/{roomId}/{studentId}
│   ├── /PhoneToUid/{normalizedPhone}
│   ├── /EmailToUid/{encodedEmail}
│   ├── /SchoolCodes/{code}
│   ├── /SchoolNames/{encodedName}
│   └── /AdmissionNoToStudent/{sId}/{admNo}
│
├── /AuditLog/{sId}/{logId}                           ← Immutable action log
├── /FinanceAudit/{sId}/{logId}                       ← Financial audit (separate)
├── /SecurityEvents/{sId}/{eventId}                   ← Security-specific events
├── /RateLimit/{uid}                                   ← Rate limiting counters
│
├── /Exits/{phone}                                     ← Exit tracking (existing)
├── /HomeworkStatus/{sId}                              ← Legacy compatibility
└── /StudentFlags/{sId}                                ← Legacy compatibility
```

---

## 4. DETAILED SCHEMA — SYSTEM & GLOBAL

### /System/Config
```json
{
  "platformName": "SchoolSync",
  "maintenanceMode": false,
  "maintenanceMessage": "",
  "minAppVersion": {
    "android_parent": "2.0.0",
    "android_teacher": "2.0.0",
    "ios_parent": "2.0.0",
    "ios_teacher": "2.0.0"
  },
  "featureFlags": {
    "enableChat": true,
    "enableTransportTracking": true,
    "enableOnlineExam": false,
    "enableHostelModule": true,
    "enableLibraryModule": true,
    "enableBiometricAttendance": false
  },
  "apiEndpoints": {
    "authApi": "https://project2-2-80nu.onrender.com",
    "storageBaseUrl": "https://firebasestorage.googleapis.com/v0/b/graders-1c047.appspot.com"
  },
  "updatedAt": 1711234567890
}
```
**Read**: All apps on startup | **Write**: Super admin only

### /System/Plans/{planId}
```json
{
  "name": "Premium",
  "maxStudents": 5000,
  "maxStaff": 500,
  "modules": ["attendance", "fees", "homework", "exams", "transport", "hostel", "library", "hr"],
  "price": { "monthly": 9999, "yearly": 99999, "currency": "INR" },
  "features": {
    "smsCredits": 10000,
    "storageGB": 50,
    "whatsappEnabled": true,
    "customReportCards": true,
    "apiAccess": true
  }
}
```

### /System/Versions
```json
{
  "latestParentApp": "2.1.0",
  "latestTeacherApp": "2.1.0",
  "forceUpdate": false,
  "updateUrl": {
    "android": "https://play.google.com/store/apps/details?id=com.schoolsync.parent",
    "ios": "https://apps.apple.com/app/schoolsync-parent/id..."
  }
}
```

---

## 5. DETAILED SCHEMA — SCHOOL IDENTITY & CONFIG

### /Schools/{sId}/Meta
```json
{
  "displayName": "Ankit School",
  "code": "10005",
  "email": "a@gmail.com",
  "phone": "+919235381907",
  "website": "googlex.com",
  "address": {
    "line1": "Durga Colony",
    "city": "Etawah",
    "state": "Uttar Pradesh",
    "pincode": "206001",
    "lat": 26.7856,
    "lng": 79.0220
  },
  "principalName": "Himanshu Singh",
  "establishedYear": 1997,
  "affiliationBoard": "CBSE",
  "affiliationNo": "1243572",
  "logoUrl": "https://firebasestorage.googleapis.com/...",
  "bannerUrl": null,
  "academicCalendarUrl": "https://firebasestorage.googleapis.com/...",
  "holidayCalendarUrl": "https://firebasestorage.googleapis.com/...",
  "activePlan": "premium",
  "activeSession": "2026-27",
  "timezone": "Asia/Kolkata",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```

### /Schools/{sId}/Config
```json
{
  "board": {
    "type": "CBSE",
    "gradingPattern": "marks",
    "passingMarks": 33,
    "updatedAt": "2026-03-22 09:37:22"
  },
  "classes": [
    {
      "key": "Playgroup", "label": "Playgroup", "order": 0,
      "type": "foundational", "streamsEnabled": false, "deleted": false
    },
    {
      "key": "9", "label": "Class 9th", "order": 12,
      "type": "secondary", "streamsEnabled": false, "deleted": false
    },
    {
      "key": "11", "label": "Class 11th", "order": 14,
      "type": "senior", "streamsEnabled": true, "deleted": false
    }
  ],
  "sections": ["A", "B", "C", "D"],
  "streams": {
    "Science":  { "key": "Science",  "label": "Science",  "enabled": true },
    "Commerce": { "key": "Commerce", "label": "Commerce", "enabled": true },
    "Arts":     { "key": "Arts",     "label": "Arts",     "enabled": true }
  },
  "periods": {
    "count": 8,
    "duration": 45,
    "startTime": "08:00",
    "breaks": [
      { "afterPeriod": 2, "duration": 15, "label": "Short Break" },
      { "afterPeriod": 5, "duration": 30, "label": "Lunch" }
    ]
  },
  "attendance": {
    "mode": "period_wise",
    "graceMinutes": 15,
    "lateCountAsAbsent": 3,
    "minPercentForExam": 75,
    "autoNotifyParentOnAbsent": true,
    "notifyMethod": ["push", "sms"]
  },
  "exams": {
    "EXAM001": {
      "name": "Unit Test 1", "type": "Unit Test",
      "maxTheory": 80, "maxPractical": 20, "maxTotal": 100,
      "weight": 0.15, "status": "Closed"
    },
    "EXAM002": {
      "name": "Mid-Term Exam", "type": "Mid-Term",
      "maxTheory": 80, "maxPractical": 20, "maxTotal": 100,
      "weight": 0.30, "status": "Closed"
    },
    "EXAM003": {
      "name": "Unit Test 2", "type": "Unit Test",
      "maxTheory": 80, "maxPractical": 20, "maxTotal": 100,
      "weight": 0.15, "status": "Upcoming"
    }
  },
  "reportCardTemplate": "classic",
  "admissionFee": {
    "amount": 5000, "currency": "INR", "enabled": true
  },
  "staffRoles": {
    "ROLE_TEACHER":    { "label": "Teacher",       "category": "Teaching",       "attendanceType": "standard", "isSystem": true },
    "ROLE_ACCOUNTANT": { "label": "Accountant",    "category": "Administrative", "attendanceType": "standard", "isSystem": true },
    "ROLE_LIBRARIAN":  { "label": "Librarian",     "category": "Non-Teaching",   "attendanceType": "standard", "isSystem": true },
    "ROLE_DRIVER":     { "label": "Driver",         "category": "Support",        "attendanceType": "shift",    "isSystem": true },
    "ROLE_SECURITY":   { "label": "Security",       "category": "Support",        "attendanceType": "shift",    "isSystem": true },
    "ROLE_WARDEN":     { "label": "House Warden",   "category": "Non-Teaching",   "attendanceType": "standard", "isSystem": false }
  }
}
```

### /Schools/{sId}/FeeConfig/{ayId}
```json
{
  "feeHeads": {
    "TUITION":   { "label": "Tuition Fee",   "amount": 2500, "frequency": "monthly", "taxable": false },
    "TRANSPORT":  { "label": "Transport Fee",  "amount": 800,  "frequency": "monthly", "taxable": false, "conditional": "transport_assigned" },
    "LIBRARY":    { "label": "Library Fee",    "amount": 200,  "frequency": "monthly", "taxable": false },
    "LAB":        { "label": "Lab Fee",        "amount": 300,  "frequency": "monthly", "taxable": false, "applicableClasses": ["9","10","11","12"] },
    "COMPUTER":   { "label": "Computer Fee",   "amount": 250,  "frequency": "monthly", "taxable": false },
    "SPORTS":     { "label": "Sports Fee",     "amount": 150,  "frequency": "monthly", "taxable": false },
    "ADMISSION":  { "label": "Admission Fee",  "amount": 5000, "frequency": "once",    "taxable": false },
    "HOSTEL":     { "label": "Hostel Fee",     "amount": 3000, "frequency": "monthly", "taxable": false, "conditional": "hostel_assigned" },
    "ANNUAL":     { "label": "Annual Charges", "amount": 5000, "frequency": "yearly",  "taxable": false }
  },
  "classFeeOverrides": {
    "Playgroup": { "TUITION": 1500 },
    "11":        { "TUITION": 3500, "LAB": 500 },
    "12":        { "TUITION": 3500, "LAB": 500 }
  },
  "concessionTypes": {
    "SIBLING":    { "label": "Sibling Discount",   "type": "percentage", "value": 10, "autoApply": true },
    "MERIT":      { "label": "Merit Scholarship",  "type": "percentage", "value": 25, "autoApply": false },
    "RTE":        { "label": "RTE Quota",           "type": "full",      "value": 100, "autoApply": false },
    "STAFF_WARD": { "label": "Staff Ward",          "type": "percentage", "value": 50, "autoApply": true }
  },
  "lateFee": {
    "enabled": true,
    "graceDays": 15,
    "type": "fixed",
    "amount": 100,
    "maxAmount": 500
  },
  "installmentPlans": {
    "MONTHLY":    { "label": "Monthly",    "intervals": 12, "discount": 0 },
    "QUARTERLY":  { "label": "Quarterly",  "intervals": 4,  "discount": 2 },
    "HALF_YEARLY":{ "label": "Half Yearly","intervals": 2,  "discount": 5 },
    "ANNUAL":     { "label": "Annual",     "intervals": 1,  "discount": 8 }
  },
  "paymentGateway": {
    "provider": "razorpay",
    "keyId": "rzp_live_...",
    "autoReconcile": true
  },
  "updatedAt": 1711234567890,
  "updatedBy": "admin_uid"
}
```

### /Schools/{sId}/Roles
```json
{
  "Principal": {
    "modules": ["*"],
    "permissions": ["*"],
    "maxUsers": 2
  },
  "Admin": {
    "modules": ["students", "staff", "attendance", "fees", "communication", "exams", "reports"],
    "permissions": ["read", "write", "delete", "approve"],
    "maxUsers": 5
  },
  "Accountant": {
    "modules": ["fees", "salary", "accounts"],
    "permissions": ["read", "write", "approve_finance"],
    "maxUsers": 3
  },
  "Academic_Coordinator": {
    "modules": ["attendance", "homework", "exams", "curriculum", "timetable"],
    "permissions": ["read", "write", "approve_academic"],
    "maxUsers": 5
  },
  "HR_Manager": {
    "modules": ["staff", "leave", "salary", "appraisal", "recruitment"],
    "permissions": ["read", "write", "approve_hr"],
    "maxUsers": 2
  }
}
```

### /Schools/{sId}/GradingScales/{scaleId}
```json
{
  "name": "CBSE 9-10 Scale",
  "type": "grade_point",
  "applicableClasses": ["9", "10"],
  "grades": [
    { "grade": "A1", "minMarks": 91, "maxMarks": 100, "gp": 10, "remark": "Outstanding" },
    { "grade": "A2", "minMarks": 81, "maxMarks": 90,  "gp": 9,  "remark": "Excellent" },
    { "grade": "B1", "minMarks": 71, "maxMarks": 80,  "gp": 8,  "remark": "Very Good" },
    { "grade": "B2", "minMarks": 61, "maxMarks": 70,  "gp": 7,  "remark": "Good" },
    { "grade": "C1", "minMarks": 51, "maxMarks": 60,  "gp": 6,  "remark": "Above Average" },
    { "grade": "C2", "minMarks": 41, "maxMarks": 50,  "gp": 5,  "remark": "Average" },
    { "grade": "D",  "minMarks": 33, "maxMarks": 40,  "gp": 4,  "remark": "Below Average" },
    { "grade": "E",  "minMarks": 0,  "maxMarks": 32,  "gp": 0,  "remark": "Needs Improvement" }
  ]
}
```

---

## 6. DETAILED SCHEMA — PEOPLE

### /Users/{uid}
**Purpose**: Thin auth-linked profile. One read tells the app "who am I and where do I go?"
```json
{
  "name": "Yuvraj's Parent",
  "phone": "+919876543210",
  "email": "parent@example.com",
  "avatar": "https://...",
  "role": "parent",
  "roles": {
    "parent": { "schoolId": "SCH_9738C22243", "entityId": "PAR0001" }
  },
  "schoolId": "SCH_9738C22243",
  "entityId": "PAR0001",
  "status": "active",
  "lastSeen": 1711234567890,
  "fcmTokens": {
    "-deviceHash1": { "token": "fcm_token_...", "platform": "android", "updatedAt": 1711234567890 },
    "-deviceHash2": { "token": "fcm_token_...", "platform": "ios", "updatedAt": 1711234567890 }
  },
  "preferences": {
    "language": "en",
    "notif_attendance": true,
    "notif_fees": true,
    "notif_homework": true,
    "notif_transport": true,
    "notif_circular": true,
    "notif_exam": true,
    "notif_chat": true
  },
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```
**Read**: Own user on login | **Write**: Self (preferences, FCM), Admin (role, status)
**Why `roles` map?**: Supports future multi-role (teacher who is also a parent).

### /Staff/{sId}/{staffId}
**Purpose**: Complete staff profile — teaching AND non-teaching in one collection.
```json
{
  "profile": {
    "name": "Ravindra Jadeja",
    "email": "ravindra.jadeja@gmail.com",
    "phone": "8765432114",
    "gender": "Male",
    "dob": "1988-12-06",
    "photo": "https://...",
    "address": {
      "line1": "45 Sports Colony",
      "city": "Etawah",
      "state": "Uttar Pradesh",
      "pincode": "206001"
    },
    "qualification": "M.P.Ed",
    "experience": 12,
    "aadhaarMasked": "XXXX-XXXX-4567",
    "panMasked": "XXXXX567X",
    "bloodGroup": "O+",
    "emergencyContact": "+91..."
  },
  "employment": {
    "empId": "EMP2020045",
    "joinDate": "2020-04-01",
    "department": "Physical Education",
    "designation": "Sports Teacher",
    "roleKey": "ROLE_TEACHER",
    "category": "Teaching",
    "contractType": "Permanent",
    "probationEnd": null,
    "contractExpiry": null,
    "salaryStructureId": "SAL_L3",
    "shiftType": "standard",
    "reportingTo": "STA0001",
    "bankAccount": {
      "bankName": "SBI",
      "branch": "Etawah Main",
      "accountNo": "XXXX4567",
      "ifsc": "SBIN000XXXX"
    }
  },
  "teaching": {
    "subjects": ["Physical Education", "Sports Science"],
    "assignedSections": {
      "9_A": true, "9_B": true, "10_A": true, "10_B": true
    },
    "isClassTeacherOf": "9_A",
    "maxPeriodsPerDay": 6,
    "maxPeriodsPerWeek": 30
  },
  "uid": "firebase_uid_or_mongo_uid",
  "status": "Active",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```
**Note**: `teaching` block is `null` for non-teaching staff.
**Read**: Self, Admin, assigned parents (name + photo only via denormalized) | **Write**: Admin, self (limited)

### /Students/{sId}/{studentId}
**Purpose**: 360° student profile — the single source of truth.
```json
{
  "profile": {
    "name": "Yuvraj Singh",
    "dob": "2012-05-15",
    "gender": "Male",
    "bloodGroup": "B+",
    "photo": "https://...",
    "aadhaarMasked": "XXXX-XXXX-1234",
    "nationality": "Indian",
    "religion": "Hindu",
    "category": "General",
    "motherTongue": "Hindi",
    "address": {
      "line1": "123 Main Street",
      "line2": "",
      "city": "Etawah",
      "state": "Uttar Pradesh",
      "pincode": "206001"
    },
    "medical": {
      "allergies": ["peanuts"],
      "conditions": ["mild asthma"],
      "medications": [],
      "doctorName": "Dr. Sharma",
      "doctorPhone": "+91...",
      "emergencyContact": "+91...",
      "lastCheckup": "2026-01-15",
      "vaccinationRecord": {
        "BCG": "2012-06-01",
        "Polio": "2012-07-15",
        "COVID19": "2024-03-01"
      }
    }
  },
  "academic": {
    "admissionNo": "ADM2024001",
    "admissionDate": "2024-04-01",
    "srNo": "SR/2024/001",
    "currentAY": "2026-27",
    "currentClass": "9",
    "currentSection": "A",
    "currentStream": null,
    "rollNo": 1,
    "house": "Blue",
    "previousSchool": null,
    "board": "CBSE"
  },
  "parentIds": {
    "PAR0001": true
  },
  "siblingGroupId": "SIB_001",
  "transport": {
    "assigned": true,
    "routeId": "RTE001",
    "stopId": "STP005",
    "vehicleId": "VEH001",
    "pickupTime": "07:30",
    "dropTime": "14:30"
  },
  "hostel": {
    "assigned": false,
    "roomId": null,
    "bedNo": null,
    "mealPlan": null
  },
  "library": {
    "cardNo": "LIB2024001",
    "maxBooks": 3,
    "currentlyIssued": 1
  },
  "documents": {
    "birthCertificate": "https://...",
    "aadhaarCard": "https://...",
    "transferCertificate": null,
    "previousMarksheet": "https://...",
    "passportPhoto": "https://..."
  },
  "flags": {
    "rteQuota": false,
    "specialNeeds": false,
    "sportsQuota": false,
    "staffWard": false
  },
  "riskScore": 12,
  "riskFactors": [],
  "status": "Active",
  "statusHistory": [
    { "status": "Active", "date": "2024-04-01", "remark": "Admitted" }
  ],
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```
**Read**: Admin (full), Teacher (academic + medical flags), Parent (own child) | **Write**: Admin only

### /Parents/{sId}/{parentId}
```json
{
  "profile": {
    "fatherName": "Rajesh Singh",
    "motherName": "Sunita Singh",
    "guardianName": null,
    "fatherPhone": "+919876543210",
    "motherPhone": "+919876543211",
    "guardianPhone": null,
    "email": "rajesh.singh@gmail.com",
    "fatherOccupation": "Business",
    "motherOccupation": "Homemaker",
    "annualIncome": "5-10 LPA",
    "address": {
      "line1": "123 Main Street",
      "city": "Etawah",
      "state": "Uttar Pradesh",
      "pincode": "206001"
    },
    "photo": "https://..."
  },
  "children": {
    "STU0004": { "name": "Yuvraj Singh",  "class": "9", "section": "A", "rollNo": 1, "photo": "https://..." },
    "STU0008": { "name": "Priya Singh",   "class": "6", "section": "B", "rollNo": 12, "photo": "https://..." }
  },
  "authorizedPickup": {
    "APU001": {
      "name": "Mohan Singh",
      "relation": "Grandfather",
      "phone": "+91...",
      "photo": "https://...",
      "aadhaarMasked": "XXXX-XXXX-7890",
      "active": true
    }
  },
  "uid": "firebase_uid_or_mongo_uid",
  "siblingGroupId": "SIB_001",
  "primaryContactUid": "uid_father",
  "status": "Active",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```
**Key Design**: `children` map has denormalized `{name, class, section, rollNo, photo}` — the parent app can show the child switcher without any extra reads.
**Fan-out**: When a student's class changes (promotion), Cloud Function updates `children.{studentId}.class` here too.

---

## 7. DETAILED SCHEMA — ACADEMIC STRUCTURE

### /Sections/{sId}/{ayId}/{secKey}
**`secKey`** = `"9_A"` (classKey + "_" + sectionLetter). This is the CENTRAL academic node.
```json
{
  "classKey": "9",
  "classLabel": "Class 9th",
  "section": "A",
  "stream": null,
  "classTeacherId": "TEA0002",
  "classTeacherName": "Ravindra Jadeja",
  "strength": 45,
  "createdAt": 1711234567890,

  "roster": {
    "STU0004": { "name": "Yuvraj Singh",  "rollNo": 1, "gender": "Male" },
    "STU0005": { "name": "Priya Sharma",  "rollNo": 2, "gender": "Female" },
    "STU0006": { "name": "Rahul Kumar",   "rollNo": 3, "gender": "Male" },
    "STU0007": { "name": "Ananya Patel",  "rollNo": 4, "gender": "Female" },
    "STU0008": { "name": "Arjun Verma",   "rollNo": 5, "gender": "Male" }
  },

  "subjects": {
    "SUB_MATH":    { "label": "Mathematics",  "teacherId": "TEA0005", "teacherName": "Mr. Sharma",  "periodsPerWeek": 6 },
    "SUB_SCI":     { "label": "Science",       "teacherId": "TEA0006", "teacherName": "Ms. Iyer",    "periodsPerWeek": 5 },
    "SUB_ENG":     { "label": "English",       "teacherId": "TEA0007", "teacherName": "Mrs. Patel",  "periodsPerWeek": 5 },
    "SUB_HINDI":   { "label": "Hindi",         "teacherId": "TEA0008", "teacherName": "Mr. Dubey",   "periodsPerWeek": 4 },
    "SUB_SST":     { "label": "Social Science","teacherId": "TEA0009", "teacherName": "Ms. Roy",     "periodsPerWeek": 4 },
    "SUB_COMP":    { "label": "Computer",      "teacherId": "TEA0010", "teacherName": "Mr. Jain",    "periodsPerWeek": 2 },
    "SUB_PE":      { "label": "P.T.",          "teacherId": "TEA0002", "teacherName": "Coach Raj",   "periodsPerWeek": 3 },
    "SUB_ART":     { "label": "Art",           "teacherId": "TEA0011", "teacherName": "Ms. Sen",     "periodsPerWeek": 1 }
  },

  "timetable": {
    "Monday": {
      "1": { "subjectId": "SUB_MATH",  "teacherId": "TEA0005", "room": "204", "time": "08:00-08:45" },
      "2": { "subjectId": "SUB_ENG",   "teacherId": "TEA0007", "room": "108", "time": "08:45-09:30" },
      "B1":{ "type": "break", "time": "09:30-09:45" },
      "3": { "subjectId": "SUB_SCI",   "teacherId": "TEA0006", "room": "Lab 2", "time": "09:45-10:30" },
      "4": { "subjectId": "SUB_HINDI", "teacherId": "TEA0008", "room": "204", "time": "10:30-11:15" },
      "5": { "subjectId": "SUB_SST",   "teacherId": "TEA0009", "room": "306", "time": "11:15-12:00" },
      "L": { "type": "break", "time": "12:00-12:30" },
      "6": { "subjectId": "SUB_COMP",  "teacherId": "TEA0010", "room": "Lab 1", "time": "12:30-13:15" },
      "7": { "subjectId": "SUB_ART",   "teacherId": "TEA0011", "room": "Art Room", "time": "13:15-14:00" }
    }
  }
}
```
**Why bundle roster + subjects + timetable here?**
- Teacher app opens → reads ONE node → gets everything: student list, today's timetable, subject list.
- Parent app reads this for child's timetable.
- Roster is denormalized (name + rollNo only). Full profile stays in /Students/.
- Average section ~50 students × 3 fields = ~2KB. Timetable ~3KB. Total ~6KB per section. Very cacheable.

### /Curriculum/{sId}/{ayId}/{subjectId}/{chapterId}
```json
{
  "name": "Linear Equations in Two Variables",
  "unit": "Algebra",
  "order": 5,
  "targetWeek": "2026-W28",
  "targetStartDate": "2026-07-06",
  "targetEndDate": "2026-07-18",
  "periods": 8,
  "status": "not_started",
  "completedDate": null,
  "completedBy": null,
  "resources": [
    { "type": "pdf", "title": "Worksheet 5.1", "url": "https://..." },
    { "type": "video", "title": "Introduction", "url": "https://..." }
  ],
  "learningOutcomes": [
    "Solve linear equations graphically",
    "Find solutions of linear equations"
  ],
  "bloomLevel": "Application"
}
```

### /LessonPlans/{sId}/{planId}
```json
{
  "teacherId": "TEA0005",
  "subjectId": "SUB_MATH",
  "sectionKeys": ["9_A", "9_B"],
  "chapterId": "CH005",
  "title": "Introduction to Linear Equations",
  "date": "2026-07-06",
  "period": 3,
  "objectives": ["Understand the concept of linear equation in 2 variables"],
  "methodology": "Interactive + Board Work",
  "resources": ["Textbook Ch5", "Graph Paper", "GeoGebra"],
  "activities": [
    { "duration": 10, "description": "Recap previous chapter" },
    { "duration": 25, "description": "New concept introduction with examples" },
    { "duration": 10, "description": "Student practice" }
  ],
  "assessment": "Quick 5-question quiz",
  "homework": "Ex 5.1 Q1-Q10",
  "status": "submitted",
  "reviewedBy": null,
  "reviewRemarks": null,
  "approvedAt": null,
  "createdAt": 1711234567890
}
```

### /QuestionBank/{sId}/{qId}
```json
{
  "subject": "Mathematics",
  "chapter": "Linear Equations",
  "board": "CBSE",
  "classKey": "9",
  "type": "MCQ",
  "difficulty": "Medium",
  "bloomLevel": "Application",
  "marks": 1,
  "question": "Which of the following is a linear equation in two variables?",
  "options": ["x² + y = 5", "2x + 3y = 7", "xy = 10", "x³ = 8"],
  "correctOption": 1,
  "explanation": "A linear equation has degree 1 for all variables",
  "tags": ["algebra", "linear-equations", "definitions"],
  "createdBy": "TEA0005",
  "createdAt": 1711234567890,
  "usedCount": 3,
  "lastUsedIn": "EXAM002"
}
```

---

## 8. DETAILED SCHEMA — ATTENDANCE

### /Attendance/{sId}/{ayId}/{date}/{secKey}
**The daily attendance node. One per section per day.**
```json
{
  "_meta": {
    "date": "2026-03-24",
    "section": "9_A",
    "strength": 45,
    "present": 40,
    "absent": 3,
    "late": 2,
    "onLeave": 0,
    "markedBy": "TEA0002",
    "markedAt": 1711267200000,
    "locked": true,
    "lockedAt": 1711270800000,
    "lockedBy": "TEA0002"
  },
  "STU0004": { "s": "P", "in": "08:02" },
  "STU0005": { "s": "P", "in": "07:55" },
  "STU0006": { "s": "A", "notified": true, "notifiedAt": 1711270800000 },
  "STU0007": { "s": "LT", "in": "08:25", "lateMinutes": 25 },
  "STU0008": { "s": "L", "leaveId": "LV0023", "leaveType": "medical" }
}
```
**Status codes**: `P` = Present, `A` = Absent, `LT` = Late, `L` = On Leave, `HD` = Half Day, `H` = Holiday
**Size**: ~45 students × 50 bytes = ~2.2KB per section per day. Very efficient.

### /AttendancePeriod/{sId}/{ayId}/{date}/{secKey}/{period}
**Period-wise attendance — only if school enables period_wise mode.**
```json
{
  "_meta": {
    "period": 3,
    "subjectId": "SUB_SCI",
    "teacherId": "TEA0006",
    "markedAt": 1711274400000
  },
  "STU0004": "P",
  "STU0005": "P",
  "STU0006": "A",
  "STU0007": "P",
  "STU0008": "P"
}
```
**Ultra-compact**: Just status per student. ~45 × 10 bytes = ~450 bytes per period.

### /AttendanceSummary/{sId}/{ayId}/{month}/{studentId}
**Pre-computed by Cloud Function. Consumed by parent app, teacher app, and dashboards.**
```json
{
  "totalWorkingDays": 24,
  "present": 20,
  "absent": 3,
  "late": 1,
  "onLeave": 0,
  "halfDay": 0,
  "percentage": 83.33,
  "cumulativePercentage": 87.5,
  "dayWise": {
    "1": "P", "2": "P", "3": "A", "4": "P", "5": "H",
    "6": "V", "7": "P", "8": "P", "9": "LT", "10": "P"
  },
  "subjectWise": {
    "SUB_MATH":  { "total": 28, "present": 25, "pct": 89.3 },
    "SUB_SCI":   { "total": 24, "present": 22, "pct": 91.7 },
    "SUB_ENG":   { "total": 24, "present": 20, "pct": 83.3 },
    "SUB_HINDI": { "total": 20, "present": 18, "pct": 90.0 }
  },
  "examEligible": true,
  "belowThreshold": false,
  "consecutiveAbsent": 0,
  "updatedAt": 1711234567890
}
```
**V** = Vacation/Holiday/Sunday. Calendar view in parent app uses `dayWise` map directly.

### /StaffAttendance/{sId}/{date}/{staffId}
```json
{
  "status": "P",
  "checkIn": "07:45",
  "checkOut": "14:30",
  "method": "biometric",
  "shiftId": "MORNING",
  "overtime": 0,
  "remarks": null
}
```

### /LeaveApplications/{sId}/{leaveId}
**Both student and staff leaves in one collection, distinguished by `applicantType`.**
```json
{
  "applicantType": "student",
  "applicantId": "STU0004",
  "applicantName": "Yuvraj Singh",
  "sectionKey": "9_A",
  "appliedBy": "PAR0001",
  "leaveType": "medical",
  "fromDate": "2026-03-25",
  "toDate": "2026-03-27",
  "days": 3,
  "reason": "Fever and cold",
  "attachments": ["https://...medical_certificate.pdf"],
  "status": "pending",
  "approvalChain": [
    { "role": "classTeacher", "staffId": "TEA0002", "status": "pending", "at": null },
    { "role": "admin", "staffId": null, "status": "pending", "at": null }
  ],
  "currentApprover": "TEA0002",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```

---

## 9. DETAILED SCHEMA — HOMEWORK & SUBMISSIONS

### /Homework/{sId}/{hwId}
```json
{
  "title": "Linear Equations Practice Set",
  "description": "Solve exercises 5.1 and 5.2 from the textbook. Show all working.",
  "subjectId": "SUB_MATH",
  "subjectName": "Mathematics",
  "sectionKeys": ["9_A", "9_B"],
  "classKey": "9",
  "assignedBy": "TEA0005",
  "assignedByName": "Mr. Sharma",
  "assignedDate": "2026-03-24",
  "dueDate": "2026-03-26",
  "dueTime": "08:00",
  "attachments": [
    { "name": "worksheet.pdf", "url": "https://...", "type": "pdf", "size": 245000 }
  ],
  "type": "assignment",
  "maxMarks": 20,
  "isGraded": true,
  "allowLateSubmission": true,
  "latePenalty": 10,
  "submissionType": "file_upload",
  "stats": {
    "totalStudents": 90,
    "submitted": 45,
    "reviewed": 20,
    "averageScore": 15.5
  },
  "status": "active",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```
**`stats`** is updated via fan-out when submissions come in. Parent app shows "45/90 submitted".

### /Submissions/{sId}/{hwId}/{studentId}
```json
{
  "studentName": "Yuvraj Singh",
  "sectionKey": "9_A",
  "submittedAt": 1711324800000,
  "isLate": false,
  "files": [
    { "name": "page1.jpg", "url": "https://...", "type": "image", "size": 1200000 },
    { "name": "page2.jpg", "url": "https://...", "type": "image", "size": 980000 }
  ],
  "text": "",
  "score": 18,
  "maxMarks": 20,
  "grade": null,
  "feedback": "Excellent work! Minor error in Q5.",
  "reviewedBy": "TEA0005",
  "reviewedAt": 1711411200000,
  "status": "reviewed"
}
```
**Status flow**: `not_submitted` → `submitted` → `reviewed` | `late_submitted` → `reviewed`

### /HomeworkFeed/{sId}/{ayId}/{secKey}/{hwId}
**Lightweight index for listing homework per section.**
```json
{
  "title": "Linear Equations Practice Set",
  "subject": "Mathematics",
  "dueDate": "2026-03-26",
  "assignedBy": "Mr. Sharma",
  "submitted": 45,
  "total": 90,
  "status": "active",
  "createdAt": 1711234567890
}
```
**Why separate?** Teacher app lists homework per section without downloading full homework objects with attachments.

---

## 10. DETAILED SCHEMA — EXAMS, MARKS & RESULTS

### /Exams/{sId}/{ayId}/{examId}
```json
{
  "name": "Mid-Term Examination",
  "type": "Mid-Term",
  "term": 1,
  "weight": 0.30,
  "maxTheory": 80,
  "maxPractical": 20,
  "maxTotal": 100,
  "startDate": "2026-09-15",
  "endDate": "2026-09-25",
  "status": "scheduled",
  "applicableClasses": ["1","2","3","4","5","6","7","8","9","10","11","12"],
  "dateSheet": {
    "2026-09-15": [
      { "subjectId": "SUB_MATH",  "time": "09:00-12:00", "duration": 180, "room": "Hall A" },
      { "subjectId": "SUB_HINDI", "time": "14:00-17:00", "duration": 180, "room": "Hall B" }
    ],
    "2026-09-16": [
      { "subjectId": "SUB_SCI",   "time": "09:00-12:00", "duration": 180, "room": "Hall A" },
      { "subjectId": "SUB_ENG",   "time": "14:00-17:00", "duration": 180, "room": "Hall B" }
    ]
  },
  "settings": {
    "allowAbsentees": false,
    "minAttendanceRequired": 75,
    "marksEntryDeadline": "2026-10-05",
    "resultPublishDate": null
  },
  "createdBy": "admin_uid",
  "createdAt": 1711234567890
}
```

### /Marks/{sId}/{ayId}/{examId}/{secKey}/{studentId}
```json
{
  "studentName": "Yuvraj Singh",
  "rollNo": 1,
  "subjects": {
    "SUB_MATH":  { "theory": 72, "practical": 18, "total": 90, "grade": "A1", "absent": false },
    "SUB_SCI":   { "theory": 65, "practical": 16, "total": 81, "grade": "A2", "absent": false },
    "SUB_ENG":   { "theory": 78, "practical": null, "total": 78, "grade": "B1", "absent": false },
    "SUB_HINDI": { "theory": 55, "practical": null, "total": 55, "grade": "C1", "absent": false },
    "SUB_SST":   { "theory": 68, "practical": null, "total": 68, "grade": "B2", "absent": false }
  },
  "totalMarks": 372,
  "maxMarks": 500,
  "percentage": 74.4,
  "overallGrade": "B1",
  "rank": null,
  "status": "verified",
  "enteredBy": "TEA0005",
  "enteredAt": 1711234567890,
  "verifiedBy": "STA0001",
  "verifiedAt": 1711324800000
}
```
**Status flow**: `draft` → `submitted` → `verified` → `locked`
**Marks entry is per-student, not per-subject** — teacher enters all subjects for a student in one write. This avoids partial data.

### /MarksLock/{sId}/{ayId}/{examId}/{secKey}
```json
{
  "locked": true,
  "lockedBy": "STA0001",
  "lockedAt": 1711411200000,
  "totalStudents": 45,
  "entered": 45,
  "verified": 45,
  "published": false
}
```
**When `locked: true`**: No more marks edits. Admin can publish to parents.

### /Results/{sId}/{ayId}/{studentId}/{examId}
**Pre-computed by Cloud Function when marks are locked.**
```json
{
  "examName": "Mid-Term Examination",
  "examType": "Mid-Term",
  "sectionKey": "9_A",
  "subjects": {
    "SUB_MATH":  { "marks": 90, "max": 100, "grade": "A1", "classAvg": 72.3, "classHighest": 95, "rank": 2 },
    "SUB_SCI":   { "marks": 81, "max": 100, "grade": "A2", "classAvg": 68.1, "classHighest": 88, "rank": 3 },
    "SUB_ENG":   { "marks": 78, "max": 100, "grade": "B1", "classAvg": 71.2, "classHighest": 92, "rank": 5 },
    "SUB_HINDI": { "marks": 55, "max": 100, "grade": "C1", "classAvg": 62.5, "classHighest": 85, "rank": 18 },
    "SUB_SST":   { "marks": 68, "max": 100, "grade": "B2", "classAvg": 65.8, "classHighest": 89, "rank": 8 }
  },
  "aggregate": {
    "totalMarks": 372,
    "maxMarks": 500,
    "percentage": 74.4,
    "grade": "B1",
    "gpa": 7.6,
    "classRank": 5,
    "sectionRank": 3,
    "classPercentile": 88.9,
    "result": "PASS"
  },
  "attendanceAtExam": {
    "percentage": 87.5,
    "eligible": true
  },
  "remarks": "Good performance. Needs improvement in Hindi.",
  "aiRemarks": "Yuvraj shows strong aptitude in Mathematics and Science. Consistent performer with upward trend. Recommended: additional Hindi practice materials.",
  "publishedAt": 1711497600000,
  "computedAt": 1711411200000
}
```
**`aiRemarks`**: Generated by AI based on marks trends, attendance, behavior. This is the "AI-Powered Report Cards" feature.

### /ReportCards/{sId}/{ayId}/{studentId}
**Annual/Term cumulative report card — combines all exams.**
```json
{
  "studentName": "Yuvraj Singh",
  "class": "Class 9th",
  "section": "A",
  "rollNo": 1,
  "exams": {
    "EXAM001": { "name": "Unit Test 1",  "percentage": 78.2, "rank": 4, "weight": 0.15 },
    "EXAM002": { "name": "Mid-Term",      "percentage": 74.4, "rank": 5, "weight": 0.30 },
    "EXAM003": { "name": "Unit Test 2",  "percentage": 82.0, "rank": 3, "weight": 0.15 },
    "EXAM004": { "name": "Final Exam",    "percentage": null, "rank": null, "weight": 0.40 }
  },
  "weightedPercentage": 77.3,
  "overallGrade": "B1",
  "overallRank": 4,
  "attendance": { "percentage": 87.5, "totalDays": 220, "present": 192 },
  "behavior": { "meritPoints": 45, "demeritPoints": 5, "netScore": 40, "grade": "A" },
  "coScholastic": {
    "sports": "A",
    "art": "B",
    "music": "B+",
    "discipline": "A"
  },
  "principalRemarks": "",
  "classTeacherRemarks": "Dedicated student with good potential.",
  "aiSummary": "Consistent improvement across terms. Strong in STEM subjects.",
  "promotionStatus": null,
  "pdfUrl": null,
  "qrVerificationCode": "RC-2027-SCH9738-STU0004-XXXX",
  "generatedAt": null,
  "template": "classic"
}
```

### /MeritList/{sId}/{ayId}/{examId}/{secKey}
```json
{
  "examName": "Mid-Term Examination",
  "classLabel": "Class 9th",
  "section": "A",
  "rankedStudents": [
    { "rank": 1, "studentId": "STU0010", "name": "Aryan Gupta",    "percentage": 92.4, "grade": "A1" },
    { "rank": 2, "studentId": "STU0004", "name": "Yuvraj Singh",   "percentage": 74.4, "grade": "B1" },
    { "rank": 3, "studentId": "STU0005", "name": "Priya Sharma",   "percentage": 71.2, "grade": "B1" }
  ],
  "classAverage": 68.7,
  "passPercentage": 91.1,
  "topScorer": { "studentId": "STU0010", "name": "Aryan Gupta", "percentage": 92.4 },
  "computedAt": 1711411200000
}
```

---

## 11. DETAILED SCHEMA — FEES & FINANCE

### /FeeAllocation/{sId}/{ayId}/{studentId}
**Per-student fee demand — generated from FeeConfig + applicable concessions.**
```json
{
  "studentName": "Yuvraj Singh",
  "classKey": "9",
  "sectionKey": "9_A",
  "installmentPlan": "MONTHLY",
  "feeHeads": {
    "TUITION":  { "label": "Tuition Fee",   "baseAmount": 2500, "frequency": "monthly", "concession": 0, "netAmount": 2500 },
    "TRANSPORT":{ "label": "Transport Fee",  "baseAmount": 800,  "frequency": "monthly", "concession": 0, "netAmount": 800 },
    "LIBRARY":  { "label": "Library Fee",    "baseAmount": 200,  "frequency": "monthly", "concession": 0, "netAmount": 200 },
    "LAB":      { "label": "Lab Fee",        "baseAmount": 300,  "frequency": "monthly", "concession": 0, "netAmount": 300 },
    "COMPUTER": { "label": "Computer Fee",   "baseAmount": 250,  "frequency": "monthly", "concession": 0, "netAmount": 250 },
    "SPORTS":   { "label": "Sports Fee",     "baseAmount": 150,  "frequency": "monthly", "concession": 0, "netAmount": 150 }
  },
  "monthlyTotal": 4200,
  "annualTotal": 50400,
  "concessions": [],
  "exemptedHeads": ["LIBRARY"],
  "summary": {
    "totalDemand": 50400,
    "totalPaid": 25200,
    "totalWaived": 0,
    "totalLateFee": 200,
    "balance": 25400,
    "advance": 0,
    "lastPaymentDate": "2026-02-15",
    "nextDueDate": "2026-04-01",
    "nextDueAmount": 4200
  },
  "monthlyStatus": {
    "April":     { "demand": 4200, "paid": 4200, "date": "2026-04-05", "txnId": "TXN001", "status": "paid" },
    "May":       { "demand": 4200, "paid": 4200, "date": "2026-05-03", "txnId": "TXN015", "status": "paid" },
    "June":      { "demand": 4200, "paid": 4200, "date": "2026-06-10", "txnId": "TXN032", "status": "paid" },
    "July":      { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "overdue" },
    "August":    { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "overdue" },
    "September": { "demand": 4200, "paid": 4200, "date": "2026-09-02", "txnId": "TXN078", "status": "paid" },
    "October":   { "demand": 4200, "paid": 4200, "date": "2026-10-05", "txnId": "TXN095", "status": "paid" },
    "November":  { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "pending" },
    "December":  { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "upcoming" },
    "January":   { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "upcoming" },
    "February":  { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "upcoming" },
    "March":     { "demand": 4200, "paid": 0, "date": null, "txnId": null, "status": "upcoming" }
  },
  "updatedAt": 1711234567890
}
```
**This is the CORE fee node.** Parent app reads this ONE node to show entire fee dashboard.

### /Transactions/{sId}/{txnId}
```json
{
  "studentId": "STU0004",
  "studentName": "Yuvraj Singh",
  "parentId": "PAR0001",
  "amount": 4200,
  "mode": "online",
  "gateway": "razorpay",
  "gatewayOrderId": "order_XXXXX",
  "gatewayPaymentId": "pay_XXXXX",
  "gatewaySignature": "sig_XXXXX",
  "feeBreakdown": {
    "TUITION": 2500,
    "TRANSPORT": 800,
    "LIBRARY": 200,
    "LAB": 300,
    "SPORTS": 150,
    "COMPUTER": 250
  },
  "appliedToMonths": ["October"],
  "lateFee": 0,
  "receiptNo": "RCT/2026-27/0095",
  "status": "success",
  "reconciled": true,
  "reconciledAt": 1711411200000,
  "ip": "103.xx.xx.xx",
  "userAgent": "SchoolSyncParent/2.1.0 Android",
  "createdAt": 1711234567890
}
```

### /FeeLedger/{sId}/{ayId}/{studentId}/{entryId}
**Double-entry accounting per student.**
```json
{
  "date": "2026-10-05",
  "type": "credit",
  "description": "October fee payment",
  "refType": "transaction",
  "refId": "TXN095",
  "debit": 0,
  "credit": 4200,
  "balance": 25400,
  "voucherNo": "VCH/2026/0095",
  "createdBy": "system",
  "createdAt": 1711234567890
}
```

### /Concessions/{sId}/{concessionId}
```json
{
  "studentId": "STU0004",
  "studentName": "Yuvraj Singh",
  "type": "MERIT",
  "label": "Merit Scholarship",
  "discountType": "percentage",
  "discountValue": 25,
  "applicableFeeHeads": ["TUITION"],
  "reason": "School topper in Class 8",
  "supportingDocs": ["https://...marksheet.pdf"],
  "status": "approved",
  "appliedBy": "PAR0001",
  "appliedAt": 1711234567890,
  "verifiedBy": "STA0003",
  "verifiedAt": 1711324800000,
  "approvedBy": "STA0001",
  "approvedAt": 1711411200000,
  "effectiveFrom": "April",
  "effectiveTo": "March",
  "ayId": "2026-27"
}
```

### /AccountBook/{sId}/{ayId}/{voucherId}
**Double-entry journal voucher for school-level accounting.**
```json
{
  "date": "2026-10-05",
  "type": "receipt",
  "narration": "Fee collection - October batch",
  "entries": [
    { "account": "CASH_AT_BANK",   "debit": 125000, "credit": 0 },
    { "account": "TUITION_INCOME", "debit": 0,      "credit": 75000 },
    { "account": "TRANSPORT_INCOME","debit": 0,      "credit": 24000 },
    { "account": "OTHER_INCOME",   "debit": 0,      "credit": 26000 }
  ],
  "totalDebit": 125000,
  "totalCredit": 125000,
  "refType": "batch_collection",
  "refId": "BATCH_2026_10_05",
  "createdBy": "STA0003",
  "createdAt": 1711234567890
}
```

### /Salary/{sId}/{month}/{staffId}
```json
{
  "staffName": "Ravindra Jadeja",
  "empId": "EMP2020045",
  "department": "Physical Education",
  "structureId": "SAL_L3",
  "earnings": {
    "basic": 25000,
    "hra": 10000,
    "da": 5000,
    "transportAllowance": 2000,
    "specialAllowance": 3000
  },
  "deductions": {
    "pf": 3000,
    "esi": 750,
    "tds": 2500,
    "professionalTax": 200,
    "lwpDeduction": 0
  },
  "grossEarnings": 45000,
  "totalDeductions": 6450,
  "netPayable": 38550,
  "workingDays": 26,
  "presentDays": 25,
  "lwpDays": 1,
  "overtimeHours": 0,
  "overtimePay": 0,
  "arrears": 0,
  "status": "disbursed",
  "bankRefNo": "NEFT/2026/XXXX",
  "disbursedAt": 1711234567890,
  "generatedAt": 1711234567890,
  "approvedBy": "STA0001"
}
```

---

## 12. DETAILED SCHEMA — COMMUNICATION

### /ChatMeta/{chatId}
```json
{
  "type": "direct",
  "schoolId": "SCH_9738C22243",
  "participants": {
    "uid_parent": { "name": "Rajesh Singh", "role": "parent", "avatar": "https://..." },
    "uid_teacher": { "name": "Ravindra Jadeja", "role": "teacher", "avatar": "https://..." }
  },
  "participantUids": ["uid_parent", "uid_teacher"],
  "context": {
    "studentId": "STU0004",
    "studentName": "Yuvraj Singh",
    "sectionKey": "9_A"
  },
  "lastMessage": {
    "text": "Thank you for the update",
    "sender": "uid_parent",
    "timestamp": 1711234567890
  },
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```

### /Chats/{chatId}/{msgId}
```json
{
  "sender": "uid_teacher",
  "senderName": "Ravindra Jadeja",
  "type": "text",
  "text": "Yuvraj did very well in today's sports day practice.",
  "media": null,
  "replyTo": null,
  "readBy": {
    "uid_parent": 1711238167890
  },
  "timestamp": 1711234567890
}
```
For media messages:
```json
{
  "sender": "uid_parent",
  "senderName": "Rajesh Singh",
  "type": "image",
  "text": "Medical certificate attached",
  "media": { "url": "https://...", "type": "image/jpeg", "size": 245000, "thumbnail": "https://..." },
  "timestamp": 1711234567890
}
```
For voice notes:
```json
{
  "type": "voice",
  "media": { "url": "https://...", "type": "audio/m4a", "duration": 15, "size": 120000 },
  "timestamp": 1711234567890
}
```

### /UserChats/{uid}/{chatId}
**Per-user chat index. Listener here gives real-time chat list.**
```json
{
  "otherParty": { "name": "Mr. Jadeja", "avatar": "https://...", "role": "teacher" },
  "context": "Yuvraj Singh - 9A",
  "lastMessage": "Thank you for the update",
  "lastTimestamp": 1711234567890,
  "unread": 0,
  "muted": false,
  "archived": false,
  "pinned": false
}
```

### /Circulars/{sId}/{circId}
```json
{
  "title": "Annual Day Celebration - Invitation",
  "body": "Dear Parents, We are pleased to invite you to the Annual Day...",
  "bodyHtml": "<p>Dear Parents...</p>",
  "attachments": [
    { "name": "invitation.pdf", "url": "https://...", "type": "pdf" }
  ],
  "priority": "normal",
  "target": {
    "type": "class",
    "classes": ["9", "10"],
    "sections": ["A", "B", "C"],
    "roles": ["parent", "teacher"]
  },
  "requireAcknowledgement": true,
  "totalRecipients": 450,
  "readCount": 312,
  "acknowledgedCount": 280,
  "channels": ["push", "sms", "email"],
  "sentBy": "STA0001",
  "sentByName": "Admin",
  "sentAt": 1711234567890,
  "scheduledAt": null,
  "expiresAt": null,
  "status": "sent"
}
```

### /Notifications/{uid}/{notifId}
```json
{
  "type": "attendance_absent",
  "title": "Attendance Alert",
  "body": "Yuvraj Singh was marked absent today (24 Mar 2026)",
  "icon": "attendance",
  "priority": "high",
  "data": {
    "screen": "attendance_detail",
    "studentId": "STU0004",
    "date": "2026-03-24"
  },
  "read": false,
  "createdAt": 1711234567890,
  "expiresAt": 1711838400000
}
```

### /NotifBadge/{uid}
**Ultra-lightweight node — single listener gives badge counts.**
```json
{
  "total": 5,
  "attendance": 1,
  "homework": 2,
  "fees": 1,
  "circular": 1,
  "chat": 0,
  "exam": 0,
  "transport": 0,
  "general": 0
}
```

### /MsgTemplates/{sId}/{templateId}
```json
{
  "name": "Fee Reminder",
  "channel": "sms",
  "body": "Dear {parent_name}, fee of Rs. {amount} for {student_name} ({class}) is due on {due_date}. Please pay to avoid late fee. - {school_name}",
  "mergeFields": ["parent_name", "amount", "student_name", "class", "due_date", "school_name"],
  "category": "fees",
  "createdBy": "STA0001",
  "createdAt": 1711234567890
}
```

---

## 13. DETAILED SCHEMA — TRANSPORT

### /Routes/{sId}/{routeId}
```json
{
  "name": "Route 1 - Durga Colony → School",
  "routeNo": "R01",
  "type": "pickup",
  "vehicleId": "VEH001",
  "driverId": "STA0006",
  "attendantId": "STA0010",
  "stops": [
    { "id": "STP001", "name": "Durga Colony Gate",  "lat": 26.7850, "lng": 79.0200, "time": "07:00", "order": 1 },
    { "id": "STP002", "name": "Civil Lines",        "lat": 26.7870, "lng": 79.0230, "time": "07:10", "order": 2 },
    { "id": "STP003", "name": "Station Road",       "lat": 26.7900, "lng": 79.0250, "time": "07:20", "order": 3 },
    { "id": "STP004", "name": "Main Market",        "lat": 26.7920, "lng": 79.0280, "time": "07:30", "order": 4 },
    { "id": "STP005", "name": "School Gate",         "lat": 26.7956, "lng": 79.0320, "time": "07:45", "order": 5 }
  ],
  "totalStudents": 35,
  "totalDistance": 8.5,
  "estimatedDuration": 45,
  "active": true,
  "updatedAt": 1711234567890
}
```

### /Vehicles/{sId}/{vehicleId}
```json
{
  "registrationNo": "UP74 AB 1234",
  "type": "bus",
  "make": "Tata",
  "model": "Starbus",
  "year": 2022,
  "capacity": 45,
  "color": "Yellow",
  "currentRouteId": "RTE001",
  "driverId": "STA0006",
  "driverName": "Ramesh Kumar",
  "driverPhone": "+91...",
  "compliance": {
    "insuranceExpiry": "2027-01-15",
    "pucExpiry": "2026-09-30",
    "fitnessExpiry": "2027-03-31",
    "permitExpiry": "2028-06-15",
    "gpsDeviceId": "GPS_DEVICE_001"
  },
  "status": "active",
  "updatedAt": 1711234567890
}
```

### /VehicleLive/{sId}/{vehicleId}  ⚡ HOT NODE — Updated every 10 seconds
```json
{
  "lat": 26.7893,
  "lng": 79.0245,
  "speed": 25,
  "heading": 45,
  "accuracy": 5,
  "altitude": 160,
  "status": "en_route",
  "currentStopIndex": 3,
  "nextStop": { "id": "STP004", "name": "Main Market", "eta": 5 },
  "tripId": "TRIP_2026_03_24_AM",
  "timestamp": 1711234567890,
  "batteryLevel": 78,
  "networkType": "4G"
}
```
**Design note**: This is the LIGHTEST possible node (~200 bytes). Parent app puts a real-time listener here.
**`eta`**: Estimated time of arrival in minutes to next stop. Computed on device or Cloud Function.

### /StudentRoutes/{sId}/{studentId}
```json
{
  "routeId": "RTE001",
  "stopId": "STP002",
  "stopName": "Civil Lines",
  "vehicleId": "VEH001",
  "vehicleNo": "UP74 AB 1234",
  "driverName": "Ramesh Kumar",
  "driverPhone": "+91...",
  "pickupTime": "07:10",
  "dropTime": "14:40",
  "monthlyFee": 800
}
```

### /TripLogs/{sId}/{vehicleId}/{date}
```json
{
  "morning": {
    "startTime": "06:45",
    "endTime": "07:50",
    "startOdometer": 45230,
    "endOdometer": 45239,
    "distance": 9,
    "fuelUsed": null,
    "stopsCompleted": 5,
    "alerts": []
  },
  "afternoon": {
    "startTime": "14:15",
    "endTime": "15:20",
    "startOdometer": 45239,
    "endOdometer": 45248,
    "distance": 9,
    "fuelUsed": null,
    "stopsCompleted": 5,
    "alerts": []
  }
}
```

### /SOSAlerts/{sId}/{alertId}
```json
{
  "triggeredBy": "STA0006",
  "triggeredByRole": "driver",
  "vehicleId": "VEH001",
  "routeId": "RTE001",
  "location": { "lat": 26.7880, "lng": 79.0225 },
  "type": "emergency",
  "message": "Vehicle breakdown",
  "status": "active",
  "respondedBy": null,
  "resolvedAt": null,
  "notifiedParents": 35,
  "createdAt": 1711234567890
}
```

### /GeoFences/{sId}/{fenceId}
```json
{
  "name": "School Campus",
  "type": "school_zone",
  "center": { "lat": 26.7956, "lng": 79.0320 },
  "radius": 200,
  "triggers": {
    "onEnter": { "notify": ["admin"], "message": "Bus entered school zone" },
    "onExit":  { "notify": ["admin", "parents"], "message": "Bus left school zone" }
  },
  "active": true
}
```

---

## 14. DETAILED SCHEMA — HOSTEL

### /Hostel/{sId}/Rooms/{roomId}
```json
{
  "floor": 2,
  "wing": "A",
  "roomNumber": "A-201",
  "type": "dormitory",
  "capacity": 4,
  "occupied": 3,
  "beds": {
    "A": { "studentId": "STU0015", "name": "Student Name" },
    "B": { "studentId": "STU0016", "name": "Student Name" },
    "C": { "studentId": "STU0017", "name": "Student Name" },
    "D": { "studentId": null }
  },
  "wardenId": "STA0012",
  "amenities": ["fan", "locker", "study_table"],
  "status": "active"
}
```

### /Hostel/{sId}/MealMenu/{weekKey}
```json
{
  "weekStart": "2026-03-23",
  "weekEnd": "2026-03-29",
  "meals": {
    "Monday": {
      "breakfast": { "items": ["Paratha", "Curd", "Tea"], "time": "07:30-08:30", "type": "veg" },
      "lunch":     { "items": ["Rice", "Dal", "Sabzi", "Roti", "Salad"], "time": "12:30-13:30", "type": "veg" },
      "snacks":    { "items": ["Samosa", "Tea"], "time": "16:00-16:30", "type": "veg" },
      "dinner":    { "items": ["Roti", "Paneer", "Dal", "Rice"], "time": "20:00-21:00", "type": "veg" }
    }
  },
  "createdBy": "STA0012"
}
```

### /Hostel/{sId}/Visitors/{logId}
```json
{
  "studentId": "STU0015",
  "studentName": "Student Name",
  "visitorName": "Parent Name",
  "relation": "Father",
  "phone": "+91...",
  "photo": "https://...",
  "purpose": "Weekend visit",
  "entryTime": "2026-03-24T10:30:00",
  "exitTime": "2026-03-24T12:45:00",
  "approvedBy": "STA0012",
  "parentNotified": true
}
```

### /Hostel/{sId}/Complaints/{ticketId}
```json
{
  "raisedBy": "STU0015",
  "roomId": "RM_A201",
  "category": "maintenance",
  "subCategory": "electrical",
  "description": "Fan not working in room A-201",
  "photos": ["https://..."],
  "priority": "medium",
  "status": "in_progress",
  "assignedTo": "maintenance_staff_id",
  "resolvedAt": null,
  "resolution": null,
  "createdAt": 1711234567890
}
```

---

## 15. DETAILED SCHEMA — LIBRARY

### /Library/{sId}/Catalog/{bookId}
```json
{
  "title": "Introduction to Algorithms",
  "authors": ["Thomas H. Cormen", "Charles E. Leiserson"],
  "isbn": "978-0-262-03384-8",
  "publisher": "MIT Press",
  "edition": "3rd",
  "year": 2009,
  "category": "Computer Science",
  "subject": "Algorithms",
  "language": "English",
  "pages": 1312,
  "barcode": "LIB-2024-00156",
  "rfidTag": "RFID-00156",
  "location": { "shelf": "CS-03", "row": 2 },
  "totalCopies": 3,
  "availableCopies": 1,
  "issuedCopies": 2,
  "coverImage": "https://...",
  "status": "available",
  "addedAt": 1711234567890
}
```

### /Library/{sId}/Issues/{txnId}
```json
{
  "bookId": "BOOK001",
  "bookTitle": "Introduction to Algorithms",
  "barcode": "LIB-2024-00156",
  "borrowerId": "STU0004",
  "borrowerName": "Yuvraj Singh",
  "borrowerType": "student",
  "issueDate": "2026-03-20",
  "dueDate": "2026-04-03",
  "returnDate": null,
  "renewals": 0,
  "maxRenewals": 2,
  "fine": 0,
  "status": "issued",
  "issuedBy": "STA0009",
  "returnedTo": null
}
```

### /Library/{sId}/EBooks/{ebookId}
```json
{
  "title": "NCERT Mathematics Class 9",
  "fileUrl": "https://...",
  "fileType": "pdf",
  "fileSize": 15000000,
  "coverImage": "https://...",
  "subject": "Mathematics",
  "applicableClasses": ["9"],
  "accessRoles": ["student", "teacher"],
  "downloadable": false,
  "totalViews": 234,
  "addedAt": 1711234567890
}
```

---

## 16. DETAILED SCHEMA — BEHAVIOR & DISCIPLINE

### /Incidents/{sId}/{incidentId}
```json
{
  "studentId": "STU0004",
  "studentName": "Yuvraj Singh",
  "sectionKey": "9_A",
  "date": "2026-03-24",
  "time": "10:30",
  "category": "tardiness",
  "severity": "minor",
  "description": "Late to class by 15 minutes after lunch break",
  "location": "Classroom 204",
  "witnesses": ["TEA0005"],
  "reportedBy": "TEA0005",
  "reportedByName": "Mr. Sharma",
  "pointsImpact": -2,
  "action": "verbal_warning",
  "parentNotified": false,
  "attachments": [],
  "escalation": {
    "level": 1,
    "currentHandler": "TEA0002",
    "chain": [
      { "level": 1, "role": "classTeacher", "staffId": "TEA0002", "status": "pending" },
      { "level": 2, "role": "coordinator", "staffId": "STA0001", "status": "waiting" },
      { "level": 3, "role": "principal", "staffId": "STA0001", "status": "waiting" }
    ]
  },
  "resolution": null,
  "status": "open",
  "createdAt": 1711234567890
}
```

### /MeritPoints/{sId}/{studentId}/{entryId}
```json
{
  "date": "2026-03-24",
  "type": "demerit",
  "points": -2,
  "runningTotal": 38,
  "category": "tardiness",
  "reason": "Late to class after lunch",
  "incidentId": "INC001",
  "awardedBy": "TEA0005",
  "awardedByName": "Mr. Sharma"
}
```

### /BehaviorSummary/{sId}/{ayId}/{studentId}
**Pre-computed by Cloud Function.**
```json
{
  "totalMerit": 45,
  "totalDemerit": 7,
  "netScore": 38,
  "grade": "B+",
  "totalIncidents": 3,
  "incidentsByCategory": {
    "tardiness": 2,
    "uniform_violation": 1
  },
  "trend": "stable",
  "lastIncidentDate": "2026-03-24",
  "detentionCount": 0,
  "counselorReferrals": 0,
  "updatedAt": 1711234567890
}
```

### /CounselorNotes/{sId}/{studentId}/{noteId}
**CONFIDENTIAL — Only accessible by counselor + principal.**
```json
{
  "date": "2026-03-24",
  "counselorId": "STA0015",
  "sessionType": "scheduled",
  "concern": "Academic stress",
  "summary": "Student expressed anxiety about upcoming exams...",
  "actionPlan": "Weekly check-in, coordinate with class teacher for support",
  "followUpDate": "2026-03-31",
  "confidentialityLevel": "high",
  "parentInformed": false,
  "createdAt": 1711234567890
}
```

---

## 17. DETAILED SCHEMA — HR & PAYROLL

### /HR/{sId}/LeaveBalances/{ayId}/{staffId}
```json
{
  "staffName": "Ravindra Jadeja",
  "balances": {
    "CL": { "label": "Casual Leave",    "total": 12, "used": 4, "remaining": 8, "carryForward": 0 },
    "EL": { "label": "Earned Leave",    "total": 15, "used": 2, "remaining": 13, "carryForward": 5 },
    "SL": { "label": "Sick Leave",      "total": 10, "used": 1, "remaining": 9, "carryForward": 0 },
    "ML": { "label": "Maternity Leave", "total": 0,  "used": 0, "remaining": 0, "carryForward": 0 },
    "LWP":{ "label": "Leave W/O Pay",   "total": 0,  "used": 1, "remaining": 0, "carryForward": 0 }
  },
  "updatedAt": 1711234567890
}
```

### /HR/{sId}/Appraisals/{ayId}/{staffId}
```json
{
  "cycle": "2026-27",
  "staffName": "Ravindra Jadeja",
  "department": "Physical Education",
  "reviewPeriod": { "from": "2026-04-01", "to": "2027-03-31" },
  "kras": [
    {
      "id": "KRA01",
      "area": "Student Sports Performance",
      "weight": 30,
      "target": "Win 3 inter-school competitions",
      "selfScore": 4,
      "managerScore": null,
      "peerFeedback": null
    },
    {
      "id": "KRA02",
      "area": "Physical Fitness Programs",
      "weight": 25,
      "target": "Improve class fitness test pass rate to 90%",
      "selfScore": 3,
      "managerScore": null,
      "peerFeedback": null
    }
  ],
  "overallSelfRating": 3.8,
  "overallManagerRating": null,
  "overallRating": null,
  "status": "self_review_submitted",
  "selfSubmittedAt": 1711234567890,
  "managerReviewedAt": null,
  "finalizedAt": null
}
```

### /HR/{sId}/Recruitment/{vacancyId}
```json
{
  "title": "Mathematics Teacher - Senior Secondary",
  "department": "Academics",
  "roleKey": "ROLE_TEACHER",
  "qualification": "M.Sc Mathematics, B.Ed",
  "experience": "3-5 years",
  "salary": "30000-40000",
  "vacancies": 1,
  "jobDescription": "Teaching Mathematics to Class 11-12...",
  "status": "applications_open",
  "postedDate": "2026-03-01",
  "closingDate": "2026-03-31",
  "applications": {
    "APP001": { "name": "Candidate 1", "status": "shortlisted", "appliedAt": 1711234567890 },
    "APP002": { "name": "Candidate 2", "status": "interview_scheduled", "appliedAt": 1711234567890 }
  },
  "totalApplications": 12,
  "shortlisted": 4,
  "interviewScheduled": 2,
  "selected": 0
}
```

---

## 18. DETAILED SCHEMA — ADMISSIONS

### /Admissions/{sId}/{ayId}/Config
```json
{
  "status": "open",
  "openDate": "2026-01-15",
  "closeDate": "2026-04-30",
  "classesOpen": ["Playgroup", "Nursery", "LKG", "UKG", "1", "6", "9", "11"],
  "seatMatrix": {
    "Playgroup": { "total": 40, "filled": 12, "waitlisted": 3, "available": 28 },
    "Nursery":   { "total": 80, "filled": 45, "waitlisted": 8, "available": 35 },
    "LKG":       { "total": 120, "filled": 89, "waitlisted": 15, "available": 31 }
  },
  "ageRules": {
    "Playgroup": { "minAge": 2, "maxAge": 3, "asOfDate": "2026-04-01" },
    "Nursery":   { "minAge": 3, "maxAge": 4, "asOfDate": "2026-04-01" },
    "LKG":       { "minAge": 4, "maxAge": 5, "asOfDate": "2026-04-01" }
  },
  "applicationFee": 500,
  "requiredDocuments": ["birth_certificate", "aadhaar", "passport_photo", "previous_marksheet"],
  "stages": ["inquiry", "application", "document_verification", "entrance_test", "interview", "offer", "enrolled"],
  "selectionCriteria": "merit_based"
}
```

### /Admissions/{sId}/{ayId}/Applications/{appId}
```json
{
  "applicantName": "New Student Name",
  "dob": "2020-08-15",
  "gender": "Female",
  "applyingForClass": "LKG",
  "parentName": "Parent Name",
  "parentPhone": "+91...",
  "parentEmail": "parent@email.com",
  "address": { "city": "Etawah", "state": "UP" },
  "previousSchool": null,
  "documents": {
    "birth_certificate": { "url": "https://...", "verified": true },
    "aadhaar": { "url": "https://...", "verified": false },
    "passport_photo": { "url": "https://...", "verified": true }
  },
  "stage": "entrance_test",
  "stageHistory": [
    { "stage": "inquiry",     "date": "2026-02-01", "by": "STA0003" },
    { "stage": "application", "date": "2026-02-05", "by": "system" },
    { "stage": "document_verification", "date": "2026-02-10", "by": "STA0003" },
    { "stage": "entrance_test", "date": "2026-02-20", "by": "system" }
  ],
  "entranceTestScore": 78,
  "interviewScore": null,
  "meritRank": 5,
  "ageValid": true,
  "feePaymentId": "TXN_ADM_001",
  "source": "walk_in",
  "referral": null,
  "status": "active",
  "createdAt": 1711234567890,
  "updatedAt": 1711234567890
}
```

---

## 19. DETAILED SCHEMA — INVENTORY & ASSETS

### /Assets/{sId}/{assetId}
```json
{
  "name": "Dell Projector XPS",
  "category": "Electronics",
  "subCategory": "Projector",
  "assetTag": "AST-2024-0156",
  "serialNo": "DL-XPS-2024-XXXX",
  "purchaseDate": "2024-06-15",
  "purchasePrice": 45000,
  "vendor": "Dell Technologies",
  "warranty": { "until": "2027-06-15", "type": "comprehensive" },
  "location": "Computer Lab 1",
  "custodian": "TEA0010",
  "custodianName": "Mr. Jain",
  "condition": "good",
  "depreciation": {
    "method": "straight_line",
    "ratePercent": 15,
    "currentValue": 31875
  },
  "maintenanceSchedule": "yearly",
  "lastMaintenanceDate": "2025-06-15",
  "nextMaintenanceDate": "2026-06-15",
  "status": "in_use"
}
```

### /PurchaseOrders/{sId}/{poId}
```json
{
  "poNumber": "PO/2026/0034",
  "vendor": { "id": "VND001", "name": "Office Supplies Co.", "gstin": "09XXXXX..." },
  "items": [
    { "description": "A4 Paper (500 sheets)", "qty": 100, "unitPrice": 350, "total": 35000 },
    { "description": "Whiteboard Marker (set)", "qty": 50, "unitPrice": 120, "total": 6000 }
  ],
  "subtotal": 41000,
  "gst": 7380,
  "grandTotal": 48380,
  "status": "approved",
  "requestedBy": "STA0003",
  "approvedBy": "STA0001",
  "approvedAt": 1711234567890,
  "grnReceived": false,
  "paymentStatus": "pending"
}
```

---

## 20. DETAILED SCHEMA — EVENTS & CALENDAR

### /Events/{sId}/{eventId}
```json
{
  "title": "Annual Sports Day",
  "description": "Inter-house sports competition",
  "type": "event",
  "category": "sports",
  "startDate": "2026-11-15",
  "endDate": "2026-11-16",
  "startTime": "08:00",
  "endTime": "16:00",
  "venue": "School Ground",
  "target": {
    "classes": ["*"],
    "roles": ["student", "teacher", "parent"]
  },
  "organizer": "STA0002",
  "organizerName": "Coach Raj",
  "coverImage": "https://...",
  "isHoliday": false,
  "rsvpEnabled": true,
  "rsvpCount": 234,
  "mediaGallery": ["https://...", "https://..."],
  "status": "upcoming",
  "createdAt": 1711234567890
}
```

### /PTM/{sId}/{ptmId}
```json
{
  "title": "First Term Parent-Teacher Meeting",
  "date": "2026-10-20",
  "startTime": "09:00",
  "endTime": "14:00",
  "slotDuration": 15,
  "breakBetweenSlots": 5,
  "applicableClasses": ["*"],
  "status": "booking_open",
  "totalSlots": 200,
  "bookedSlots": 145,
  "createdAt": 1711234567890
}
```

### /PTMSlots/{sId}/{ptmId}/{staffId}/{slotId}
```json
{
  "startTime": "09:00",
  "endTime": "09:15",
  "booked": true,
  "studentId": "STU0004",
  "parentId": "PAR0001",
  "parentName": "Rajesh Singh",
  "studentName": "Yuvraj Singh",
  "sectionKey": "9_A"
}
```

### /Surveys/{sId}/{surveyId}
```json
{
  "title": "Parent Satisfaction Survey - Term 1",
  "description": "Please share your feedback about the school...",
  "targetRoles": ["parent"],
  "targetClasses": ["*"],
  "questions": [
    { "id": "Q1", "type": "rating", "text": "How satisfied are you with the teaching quality?", "scale": 5 },
    { "id": "Q2", "type": "mcq", "text": "Which area needs most improvement?", "options": ["Infrastructure", "Teaching", "Communication", "Fees", "Transport"] },
    { "id": "Q3", "type": "text", "text": "Any additional feedback?", "maxLength": 500 }
  ],
  "anonymous": true,
  "deadline": "2026-10-31",
  "totalResponses": 234,
  "status": "active"
}
```

### /LostFound/{sId}/{itemId}
```json
{
  "type": "lost",
  "description": "Blue water bottle with yellow cap",
  "category": "water_bottle",
  "reportedBy": "STU0004",
  "reportedByName": "Yuvraj Singh",
  "date": "2026-03-24",
  "location": "Playground",
  "photo": "https://...",
  "claimed": false,
  "claimedBy": null,
  "status": "open",
  "createdAt": 1711234567890
}
```

---

## 21. DETAILED SCHEMA — DASHBOARDS (Pre-Computed by Cloud Functions)

### /Dashboards/{sId}/Admin
```json
{
  "schoolStats": {
    "totalStudents": 850,
    "totalStaff": 65,
    "totalTeachers": 42,
    "totalClasses": 16,
    "totalSections": 35,
    "activePlan": "premium"
  },
  "todayAttendance": {
    "date": "2026-03-24",
    "students": { "present": 780, "absent": 50, "late": 20, "total": 850, "percentage": 91.8 },
    "staff":    { "present": 60, "absent": 3, "late": 2, "total": 65, "percentage": 92.3 }
  },
  "feesSummary": {
    "annualDemand": 42840000,
    "collected": 28560000,
    "pending": 14280000,
    "collectionPercent": 66.7,
    "defaulterCount": 45,
    "todayCollection": 125000,
    "monthCollection": 2850000
  },
  "academics": {
    "lastExamName": "Mid-Term",
    "avgPassPercentage": 91.2,
    "homeworkCompletionRate": 78.5,
    "syllabusCompletionAvg": 62.3
  },
  "recentActivity": [
    { "type": "fee_payment", "text": "Rs. 4200 received from Yuvraj Singh (9A)", "at": 1711234567890 },
    { "type": "admission", "text": "New application for LKG", "at": 1711234000000 },
    { "type": "incident", "text": "Behavior incident reported in 10B", "at": 1711233500000 }
  ],
  "alerts": [
    { "type": "compliance", "text": "3 vehicle PUC expiring this month", "priority": "high" },
    { "type": "finance", "text": "45 students defaulting on fees > 2 months", "priority": "medium" },
    { "type": "attendance", "text": "12 students below 75% attendance threshold", "priority": "high" }
  ],
  "updatedAt": 1711234567890
}
```

### /Dashboards/{sId}/Teacher/{staffId}
```json
{
  "staffName": "Ravindra Jadeja",
  "todaySchedule": [
    { "period": 1, "subjectId": "SUB_PE", "subject": "P.T.", "sectionKey": "9_A", "class": "9A", "room": "Ground", "time": "08:00-08:45" },
    { "period": 3, "subjectId": "SUB_PE", "subject": "P.T.", "sectionKey": "10_B", "class": "10B", "room": "Ground", "time": "09:45-10:30" }
  ],
  "pendingTasks": {
    "attendanceNotMarked": ["9_A"],
    "homeworkNotReviewed": 5,
    "marksNotEntered": 0,
    "leaveApprovals": 1,
    "incidentsPending": 0
  },
  "classTeacherOf": {
    "sectionKey": "9_A",
    "strength": 45,
    "presentToday": 42,
    "defaulterCount": 3,
    "pendingSubmissions": 8
  },
  "quickStats": {
    "totalStudents": 180,
    "avgAttendance": 89.5,
    "avgClassScore": 72.3,
    "pendingSubmissions": 12
  },
  "recentNotifications": [
    { "type": "substitute", "text": "You are substitute for TEA0005 (Period 5, 10A)", "at": 1711234567890 }
  ],
  "updatedAt": 1711234567890
}
```

### /Dashboards/{sId}/Parent/{parentId}
```json
{
  "children": {
    "STU0004": {
      "name": "Yuvraj Singh",
      "class": "9",
      "section": "A",
      "rollNo": 1,
      "photo": "https://...",
      "attendanceToday": "P",
      "attendancePercent": 87.5,
      "attendanceTrend": "stable",
      "pendingFees": 8400,
      "nextDueDate": "2026-04-01",
      "lastExamResult": { "name": "Mid-Term", "percentage": 74.4, "rank": 5, "grade": "B1" },
      "homeworkPending": 2,
      "homeworkDueToday": 1,
      "behaviorScore": 38,
      "nextExam": { "name": "Unit Test 2", "date": "2026-04-10" },
      "busStatus": { "vehicleId": "VEH001", "status": "at_school", "eta": null },
      "todayTimetable": [
        { "period": 1, "subject": "Maths", "teacher": "Mr. Sharma" },
        { "period": 2, "subject": "English", "teacher": "Mrs. Patel" }
      ]
    },
    "STU0008": {
      "name": "Priya Singh",
      "class": "6",
      "section": "B",
      "attendanceToday": "P",
      "attendancePercent": 92.1,
      "pendingFees": 3600,
      "lastExamResult": { "name": "Mid-Term", "percentage": 81.2, "rank": 8, "grade": "A2" },
      "homeworkPending": 0
    }
  },
  "announcements": [
    { "id": "CIRC001", "title": "Annual Day Celebration", "date": "2026-03-24", "read": false }
  ],
  "pendingActions": [
    { "type": "fee_due", "text": "Fee due for Yuvraj - Rs. 4200 by Apr 1", "priority": "high" },
    { "type": "homework", "text": "2 homework pending for Yuvraj", "priority": "medium" }
  ],
  "updatedAt": 1711234567890
}
```
**This single node (~2KB) powers the ENTIRE parent app home screen. One read. Zero joins.**

---

## 22. INDEX STRATEGY

### Purpose of Each Index

| Index Path | Purpose | Written By | Read By |
|-----------|---------|-----------|---------|
| `/Idx/StudentBySection/{sId}/{ayId}/{secKey}/{studentId}` | List students in a section | Admin (on enrollment) | Teacher (attendance, marks) |
| `/Idx/SectionByStudent/{sId}/{ayId}/{studentId}` | Find which section a student is in | Admin (on enrollment) | All apps |
| `/Idx/TeacherSections/{sId}/{ayId}/{staffId}/{secKey}` | Find sections a teacher teaches | Admin (on assignment) | Teacher app |
| `/Idx/ClassTeacher/{sId}/{ayId}/{secKey}` | Find class teacher for a section | Admin | All apps |
| `/Idx/ParentChildren/{sId}/{parentId}/{studentId}` | Find all children of a parent | Admin | Parent app |
| `/Idx/StudentParent/{sId}/{studentId}/{parentId}` | Find parents of a student | Admin | Teacher app (to message parent) |
| `/Idx/SiblingGroup/{sId}/{groupId}/{studentId}` | Find siblings | Admin | Fee module (auto-concession) |
| `/Idx/StaffByDept/{sId}/{deptId}/{staffId}` | Staff directory by department | Admin | HR module |
| `/Idx/StudentByRoute/{sId}/{routeId}/{studentId}` | Students on a transport route | Admin | Transport module, SOS |
| `/Idx/StudentByHostel/{sId}/{roomId}/{studentId}` | Students in a hostel room | Admin | Hostel module |
| `/Idx/PhoneToUid/{normalizedPhone}` | Phone → UID lookup | Auth system | Login flow |
| `/Idx/EmailToUid/{encodedEmail}` | Email → UID lookup | Auth system | Login flow |
| `/Idx/SchoolCodes/{code}` | School code → school ID | System | Registration |
| `/Idx/AdmissionNoToStudent/{sId}/{admNo}` | Admission no → student ID | Admin | Search |

### Index Value Format
All indexes store **minimal data** — just `true` or a small summary:
```json
// Simple boolean index
"/Idx/StudentBySection/SCH_XXX/2026-27/9_A/STU0004": true

// Summary index (when listing needs display data)
"/Idx/TeacherSections/SCH_XXX/2026-27/TEA0002/9_A": {
  "classLabel": "Class 9th - A",
  "subject": "Physical Education",
  "isClassTeacher": true
}

// Reverse lookup
"/Idx/SectionByStudent/SCH_XXX/2026-27/STU0004": {
  "classKey": "9",
  "section": "A",
  "secKey": "9_A",
  "stream": null
}
```

---

## 23. FAN-OUT WRITE PATTERNS

These are the critical atomic multi-path writes that keep all three platforms in sync.

### Pattern 1: Mark Attendance → Notify Parents
**Trigger**: Teacher marks a student absent
**Atomic write paths**:
```
1. /Attendance/{sId}/{ayId}/{date}/{secKey}/{studentId}    → { s: "A", ... }
2. /Attendance/{sId}/{ayId}/{date}/{secKey}/_meta           → update absent count
3. /Notifications/{parentUid}/{notifId}                      → "Your child was marked absent"
4. /NotifBadge/{parentUid}/attendance                        → increment
5. /NotifBadge/{parentUid}/total                              → increment
6. /Dashboards/{sId}/Parent/{parentId}/children/{studentId}/attendanceToday → "A"
```

### Pattern 2: Assign Homework → Class Feed + Parent Notifications
**Trigger**: Teacher creates homework
**Atomic write paths**:
```
1. /Homework/{sId}/{hwId}                                     → full homework object
2. /HomeworkFeed/{sId}/{ayId}/{secKey}/{hwId}                  → summary for listing
3. FOR EACH parent in section:
   /Notifications/{parentUid}/{notifId}                        → "New homework assigned"
   /NotifBadge/{parentUid}/homework                            → increment
   /Dashboards/{sId}/Parent/{parentId}/children/{stuId}/homeworkPending → increment
```
**Note**: For large classes, use Cloud Function to fan-out parent notifications (avoid 50+ path atomic write).

### Pattern 3: Lock Marks → Compute Results → Publish to Parents
**Trigger**: Admin locks marks for an exam-section
**Cloud Function pipeline**:
```
Step 1 (trigger): /MarksLock/{sId}/{ayId}/{examId}/{secKey}.locked → true
Step 2 (compute): Read all /Marks/{sId}/{ayId}/{examId}/{secKey}/*
Step 3 (write results): FOR EACH student:
   /Results/{sId}/{ayId}/{studentId}/{examId}                  → computed result
Step 4 (write merit list):
   /MeritList/{sId}/{ayId}/{examId}/{secKey}                   → ranked list
Step 5 (notify parents): FOR EACH student:
   /Notifications/{parentUid}/{notifId}                        → "Results published"
   /Dashboards/{sId}/Parent/{parentId}/children/{stuId}/lastExamResult → update
```

### Pattern 4: Fee Payment → Update Ledger + Receipt + Dashboard
**Trigger**: Payment gateway callback confirms payment
```
1. /Transactions/{sId}/{txnId}                                 → full transaction record
2. /FeeLedger/{sId}/{ayId}/{studentId}/{entryId}               → credit entry
3. /FeeAllocation/{sId}/{ayId}/{studentId}/summary             → update paid/balance
4. /FeeAllocation/{sId}/{ayId}/{studentId}/monthlyStatus/{month} → mark paid
5. /Receipts/{sId}/{receiptId}                                 → receipt index entry
6. /Dashboards/{sId}/Parent/{parentId}/children/{stuId}/pendingFees → update
7. /Dashboards/{sId}/Admin/feesSummary                          → update collected amount
8. /FinanceAudit/{sId}/{logId}                                  → audit log entry
```

### Pattern 5: GPS Update → Parent ETA Notification
**Trigger**: Driver device sends GPS update
```
1. /VehicleLive/{sId}/{vehicleId}                              → lat, lng, speed, eta
2. IF approaching a stop:
   FOR EACH student at next stop:
     /Notifications/{parentUid}/{notifId}                       → "Bus is 5 min away"
```

### Pattern 6: Circular Published → Fan-out to All Recipients
**Trigger**: Admin sends a circular
```
1. /Circulars/{sId}/{circId}                                    → circular content
2. Cloud Function reads target audience (classes, roles)
3. FOR EACH recipient UID:
   /Notifications/{uid}/{notifId}                                → circular notification
   /NotifBadge/{uid}/circular                                    → increment
```

### Pattern 7: Student Promoted → Update Everything
**Trigger**: Admin executes annual promotion
```
1. /Students/{sId}/{studentId}/academic/currentClass            → new class
2. /Students/{sId}/{studentId}/academic/currentSection           → new section
3. /Parents/{sId}/{parentId}/children/{studentId}/class          → update
4. /Parents/{sId}/{parentId}/children/{studentId}/section        → update
5. Remove from old: /Idx/StudentBySection/{sId}/{ayId}/{oldSecKey}/{studentId}
6. Add to new:      /Idx/StudentBySection/{sId}/{newAyId}/{newSecKey}/{studentId}
7. Update:          /Idx/SectionByStudent/{sId}/{newAyId}/{studentId}
8. New fee allocation: /FeeAllocation/{sId}/{newAyId}/{studentId}
```

---

## 24. SECURITY RULES ARCHITECTURE

```json
{
  "rules": {
    // ==================== SYSTEM (Super Admin Only) ====================
    "System": {
      ".read": "auth != null && root.child('Users').child(auth.uid).child('role').val() === 'superadmin'",
      ".write": "auth != null && root.child('Users').child(auth.uid).child('role').val() === 'superadmin'"
    },

    // ==================== SCHOOL CONFIG (Admin of that school) ====================
    "Schools": {
      "$sId": {
        ".read": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId",
        "Meta":   { ".write": "root.child('Schools').child($sId).child('Roles').child('Admin').child(root.child('Users').child(auth.uid).child('entityId').val()).exists()" },
        "Config": { ".write": "root.child('Schools').child($sId).child('Roles').child('Admin').child(root.child('Users').child(auth.uid).child('entityId').val()).exists()" }
      }
    },

    // ==================== USERS (Self or Admin) ====================
    "Users": {
      "$uid": {
        ".read": "auth != null && (auth.uid === $uid || root.child('Users').child(auth.uid).child('role').val() === 'admin')",
        ".write": "auth != null && auth.uid === $uid",
        "preferences": { ".write": "auth != null && auth.uid === $uid" },
        "fcmTokens":   { ".write": "auth != null && auth.uid === $uid" },
        "role":         { ".write": false },
        "schoolId":     { ".write": false }
      }
    },

    // ==================== STUDENTS (Admin write, Teacher/Parent read) ====================
    "Students": {
      "$sId": {
        ".read": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId",
        ".write": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId && (root.child('Users').child(auth.uid).child('role').val() === 'admin')",
        "$studentId": {
          ".read": "auth != null && (root.child('Users').child(auth.uid).child('schoolId').val() === $sId || root.child('Idx/StudentParent').child($sId).child($studentId).child(root.child('Users').child(auth.uid).child('entityId').val()).exists())"
        }
      }
    },

    // ==================== ATTENDANCE (Teacher write for assigned sections) ====================
    "Attendance": {
      "$sId": {
        "$ayId": {
          "$date": {
            "$secKey": {
              ".read": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId",
              ".write": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId && (root.child('Users').child(auth.uid).child('role').val() === 'admin' || root.child('Idx/TeacherSections').child($sId).child($ayId).child(root.child('Users').child(auth.uid).child('entityId').val()).child($secKey).exists())"
            }
          }
        }
      }
    },

    // ==================== MARKS (Teacher write, Admin lock) ====================
    "Marks": {
      "$sId": {
        "$ayId": {
          "$examId": {
            "$secKey": {
              ".read": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId",
              ".write": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId && !root.child('MarksLock').child($sId).child($ayId).child($examId).child($secKey).child('locked').val() && (root.child('Users').child(auth.uid).child('role').val() === 'admin' || root.child('Idx/TeacherSections').child($sId).child($ayId).child(root.child('Users').child(auth.uid).child('entityId').val()).child($secKey).exists())"
            }
          }
        }
      }
    },

    // ==================== CHATS (Participants only) ====================
    "Chats": {
      "$chatId": {
        ".read": "auth != null && root.child('ChatMeta').child($chatId).child('participantUids').val().contains(auth.uid)",
        ".write": "auth != null && root.child('ChatMeta').child($chatId).child('participantUids').val().contains(auth.uid)"
      }
    },

    // ==================== NOTIFICATIONS (Self only) ====================
    "Notifications": {
      "$uid": {
        ".read": "auth != null && auth.uid === $uid",
        ".write": false
      }
    },

    // ==================== VEHICLE LIVE (Driver write, School read) ====================
    "VehicleLive": {
      "$sId": {
        "$vehicleId": {
          ".read": "auth != null && root.child('Users').child(auth.uid).child('schoolId').val() === $sId",
          ".write": "auth != null && root.child('Users').child(auth.uid).child('role').val() === 'driver'"
        }
      }
    },

    // ==================== COUNSELOR NOTES (Counselor + Principal only) ====================
    "CounselorNotes": {
      "$sId": {
        "$studentId": {
          ".read": "auth != null && (root.child('Staff').child($sId).child(root.child('Users').child(auth.uid).child('entityId').val()).child('employment/roleKey').val() === 'ROLE_COUNSELOR' || root.child('Schools').child($sId).child('Roles/Principal').child(root.child('Users').child(auth.uid).child('entityId').val()).exists())",
          ".write": "auth != null && root.child('Staff').child($sId).child(root.child('Users').child(auth.uid).child('entityId').val()).child('employment/roleKey').val() === 'ROLE_COUNSELOR'"
        }
      }
    },

    // ==================== DASHBOARDS (Read-only, Cloud Functions write) ====================
    "Dashboards": {
      "$sId": {
        "Admin":   { ".read": "auth != null && root.child('Users').child(auth.uid).child('role').val() === 'admin' && root.child('Users').child(auth.uid).child('schoolId').val() === $sId" },
        "Teacher": {
          "$staffId": { ".read": "auth != null && root.child('Users').child(auth.uid).child('entityId').val() === $staffId" }
        },
        "Parent": {
          "$parentId": { ".read": "auth != null && root.child('Users').child(auth.uid).child('entityId').val() === $parentId" }
        },
        ".write": false
      }
    },

    // ==================== AUDIT LOG (Append only, no delete) ====================
    "AuditLog": {
      "$sId": {
        ".read": "auth != null && root.child('Users').child(auth.uid).child('role').val() === 'admin'",
        "$logId": {
          ".write": "auth != null && !data.exists()"
        }
      }
    },

    // ==================== INDEXES (System write, restricted read) ====================
    "Idx": {
      ".read": "auth != null",
      ".write": false
    }
  }
}
```
**Note**: These rules are a TEMPLATE. Production rules should use Custom Claims for role checking (faster than reading /Users on every request). Cloud Functions write to Dashboards, Notifications, and Indexes using Admin SDK (bypasses rules).

---

## 25. CLOUD FUNCTIONS MANIFEST

| Function Name | Trigger | Purpose |
|--------------|---------|---------|
| `onAttendanceMarked` | `/Attendance/{sId}/{ayId}/{date}/{secKey}` write | Fan-out: notify absent students' parents, update dashboard |
| `computeAttendanceSummary` | Scheduled (daily 11 PM) | Aggregate daily → monthly summaries for all students |
| `onHomeworkCreated` | `/Homework/{sId}/{hwId}` create | Fan-out: create HomeworkFeed entry, notify parents |
| `onSubmissionReceived` | `/Submissions/{sId}/{hwId}/{stuId}` create | Update homework stats (submitted count) |
| `onMarksLocked` | `/MarksLock/{sId}/{ayId}/{examId}/{secKey}` write | Compute results, merit list, notify parents |
| `generateReportCard` | HTTP callable | Generate PDF report card, upload to Storage, write URL |
| `onPaymentConfirmed` | `/Transactions/{sId}/{txnId}` create | Update fee allocation, ledger, receipt, dashboard |
| `feeReminderCron` | Scheduled (daily 9 AM) | Check due dates, send reminders to parents |
| `feeDefaulterCheck` | Scheduled (weekly) | Flag defaulters, update /FeeDefaulters |
| `onGPSUpdate` | `/VehicleLive/{sId}/{vehicleId}` write | Check geo-fences, send ETA notifications |
| `refreshAdminDashboard` | Scheduled (every 15 min) | Recompute /Dashboards/{sId}/Admin |
| `refreshTeacherDashboard` | Scheduled (daily 6 AM) | Recompute all teacher dashboards for the day |
| `refreshParentDashboard` | Event-driven (on relevant changes) | Update specific fields in parent dashboard |
| `onIncidentCreated` | `/Incidents/{sId}/{incId}` create | Update merit points, trigger escalation, notify parent |
| `onCircularSent` | `/Circulars/{sId}/{circId}` create | Fan-out notifications to all target recipients |
| `onLeaveRequested` | `/LeaveApplications/{sId}/{leaveId}` create | Notify approver (class teacher / admin) |
| `promotionEngine` | HTTP callable | Bulk promote students, update all indexes and allocations |
| `studentRiskScoring` | Scheduled (weekly) | Compute risk scores from attendance, grades, behavior |
| `salaryGeneration` | HTTP callable | Generate monthly payslips from structures + attendance |
| `admissionMeritList` | HTTP callable | Rank admission applicants, update merit list |
| `cleanupExpiredNotifs` | Scheduled (daily) | Delete notifications older than 30 days |
| `onProfileUpdated` | `/Students/{sId}/{stuId}/profile` write | Fan-out: update denormalized names in Parents, Sections, etc. |
| `syncExamEligibility` | Pre-exam trigger | Check attendance % vs threshold, block hall tickets |
| `backupDatabase` | Scheduled (daily 2 AM) | Export database to Cloud Storage for backup |

---

## 26. MIGRATION PLAN — Current Schema → New Schema

### Phase 1: Parallel Write (Week 1-2)
- Deploy Cloud Functions that write to BOTH old and new paths
- New data goes to both schemas simultaneously
- No reads from new schema yet

### Phase 2: Backfill (Week 2-3)
- Migration script reads all existing data from old paths and transforms to new schema
- Key transformations:
  ```
  OLD: /Schools/{sId}/2026-27/Class 9th/Section A/Students/STU0004/Attendance/March 2026 → "PPPHAV..."
  NEW: /Attendance/{sId}/2026-27/2026-03-01/9_A/STU0004 → { s: "P", ... }  (per day)
       /AttendanceSummary/{sId}/2026-27/March/STU0004 → { totalDays: 24, present: 20, ... }

  OLD: /Users/Teachers/{sId}/{staffId} → { name, email, phone, department, position }
  NEW: /Staff/{sId}/{staffId} → full staff profile
       /Users/{uid} → thin auth profile

  OLD: /Schools/{sId}/2026-27/Class 9th/Section A/Students/List → { STU0004: { Name, Gender, Roll_no } }
  NEW: /Sections/{sId}/2026-27/9_A/roster → { STU0004: { name, rollNo, gender } }
       /Idx/StudentBySection/{sId}/2026-27/9_A/STU0004 → true

  OLD: /Schools/{sId}/2026-27/Class 9th/Section A/Time_table
  NEW: /Sections/{sId}/2026-27/9_A/timetable (with teacher IDs instead of names)
  ```

### Phase 3: Switch Reads (Week 3-4)
- Update admin panel to read from new paths
- Update mobile apps to read from new paths
- Old paths still receive writes for safety

### Phase 4: Deprecate Old Paths (Week 5+)
- Remove writes to old paths
- Keep old data as archive for 3 months
- Clean up after verification

### Key Migration Mappings

| Old Path | New Path | Notes |
|----------|----------|-------|
| `/Schools/{sId}/Config` | `/Schools/{sId}/Config` | Restructure fields, keep location |
| `/Schools/{sId}/2026-27/Class X/Section Y/Students` | `/Sections/{sId}/2026-27/X_Y/roster` + Indexes | Flatten |
| `/Schools/{sId}/2026-27/Class X/Section Y/Attendance` | `/Attendance/{sId}/2026-27/{date}/{secKey}` | Explode string → per-day |
| `/Schools/{sId}/2026-27/Class X/Section Y/Marks` | `/Marks/{sId}/2026-27/{examId}/{secKey}` | Restructure |
| `/Schools/{sId}/2026-27/Class X/Section Y/Time_table` | `/Sections/{sId}/2026-27/X_Y/timetable` | Add teacher IDs |
| `/Schools/{sId}/2026-27/Class X/Section Y/Homework` | `/Homework/{sId}/{hwId}` + `/HomeworkFeed/` | Flatten + index |
| `/Users/Teachers/{sId}/{staffId}` | `/Staff/{sId}/{staffId}` + `/Users/{uid}` | Split |
| `/Users/Parents/{sId}/{studentId}` | `/Parents/{sId}/{parentId}` + `/Users/{uid}` | Re-key by parentId |
| `/Schools/{sId}/2026-27/Fees` | `/FeeAllocation/` + `/Transactions/` + `/FeeLedger/` | Full restructure |
| `/Schools/{sId}/Communication/Notices` | `/Circulars/{sId}/` | Rename + restructure |
| `/Schools/{sId}/HR` | `/HR/{sId}/` | Move out of school node |
| `/Schools/{sId}/Events` | `/Events/{sId}/` | Move out of school node |
| `/Schools/{sId}/Social/Stories` | `/Staff/{sId}/{staffId}` stories feature | Integrate |
| `/User_ids_pno` | `/Idx/PhoneToUid/` | Rename to standard index |

---

## 27. PERFORMANCE OPTIMIZATION NOTES

### Firebase RTDB Limits to Watch
| Metric | Limit | Our Design Target |
|--------|-------|------------------|
| Max depth | 32 levels | **Max 6 levels** |
| Max node size (write) | 16 MB | **< 100 KB per write** |
| Max concurrent listeners | 100 per device | **< 15 per screen** |
| Bandwidth (read) | Per GB charged | **Dashboard = 2KB read** |
| Indexing | `.indexOn` per node | **Pre-indexed via /Idx/** |

### Critical Optimizations
1. **Dashboard caching**: Parent app reads `/Dashboards/{sId}/Parent/{parentId}` on launch — ~2KB. Puts real-time listener only on this node. No other listeners until user navigates.
2. **Attendance batch write**: Teacher marks all 45 students → single `update()` call with 45 paths. Not 45 separate writes.
3. **Chat pagination**: `/Chats/{chatId}/` uses push IDs (time-sorted). Client uses `limitToLast(20)` for initial load, `endBefore()` for pagination.
4. **GPS throttling**: `/VehicleLive/` updates every 10 seconds. Client listener automatically receives latest. If >100 parents listening, Firebase handles it efficiently since node is ~200 bytes.
5. **Notification TTL**: Cloud Function deletes notifications older than 30 days. Prevents `/Notifications/{uid}` from growing unbounded.
6. **Academic year archiving**: At year-end, old `/Attendance/`, `/Marks/`, `/Results/` nodes for previous `{ayId}` are exported to Cloud Storage and optionally deleted from RTDB.
7. **Section node = teacher's workbench**: A teacher reads ONE `/Sections/{sId}/{ayId}/{secKey}` node and has roster + timetable + subjects. No other reads needed for daily operations.

### Listener Strategy by App

**Parent App** (minimal battery drain):
- Persistent: `/Dashboards/{sId}/Parent/{parentId}` (main dashboard)
- Persistent: `/NotifBadge/{uid}` (badge count)
- On-screen only: `/VehicleLive/{sId}/{vehicleId}` (when viewing bus tracker)
- On-screen only: `/Chats/{chatId}` (when in chat)

**Teacher App**:
- Persistent: `/Dashboards/{sId}/Teacher/{staffId}` (main dashboard)
- Persistent: `/NotifBadge/{uid}` (badge count)
- On-screen only: `/Attendance/{sId}/{ayId}/{date}/{secKey}` (when marking)
- On-screen only: `/Chats/{chatId}` (when in chat)

**Admin Panel** (web — more generous):
- Persistent: `/Dashboards/{sId}/Admin` (dashboard widgets)
- On-demand: Everything else fetched on navigation

---

## 28. ESTIMATED DATA SIZE

| Node | Per-Entity Size | Total for 1000 Students | Growth Rate |
|------|----------------|------------------------|-------------|
| `/Students/` | ~2 KB | 2 MB | Slow (on admission) |
| `/Staff/` | ~1.5 KB | 100 KB (65 staff) | Very slow |
| `/Parents/` | ~1 KB | 800 KB | Slow |
| `/Attendance/` (daily) | ~2 KB/section | ~70 KB/day (35 sections) | 70KB × 220 days = 15 MB/year |
| `/Marks/` (per exam) | ~500 bytes/student | 500 KB/exam | ~3 MB/year (6 exams) |
| `/Dashboards/` | ~2 KB/parent | 1.6 MB | Updated frequently, constant size |
| `/VehicleLive/` | ~200 bytes | 2 KB (10 vehicles) | Overwritten, no growth |
| `/Chats/` | ~200 bytes/msg | Varies | ~500 KB/month active school |
| **TOTAL ANNUAL** | | | **~50-80 MB/year per school** |

Firebase RTDB free tier = 1 GB storage, 10 GB/month download.
For a school with 1000 students, this design stays well within limits for years.

---

*END OF ARCHITECTURE BLUEPRINT*
*Designed for: SchoolSync Smart School System*
*Firebase Project: graders-1c047*
*Database: https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app*
