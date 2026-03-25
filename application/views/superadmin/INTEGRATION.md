# Super Admin SaaS Control Panel — Integration Guide

## Files Created

### Controllers
| File | Description |
|------|-------------|
| `application/core/MY_Superadmin_Controller.php` | Base controller — auth guard, CSRF, sa_log(), safe_segment() |
| `application/controllers/Superadmin_login.php` | Login / logout (extends CI_Controller, not MY_Superadmin) |
| `application/controllers/Superadmin.php` | Dashboard + refresh_stats |
| `application/controllers/Superadmin_schools.php` | School CRUD, onboard, toggle_status, assign_plan |
| `application/controllers/Superadmin_plans.php` | Plan CRUD with module toggles |
| `application/controllers/Superadmin_reports.php` | Students, revenue, activity, plan distribution |
| `application/controllers/Superadmin_monitor.php` | Log viewer, system health |
| `application/controllers/Superadmin_backups.php` | Backup, restore, download |

### Views
| File | Description |
|------|-------------|
| `application/views/superadmin/login.php` | Standalone login page (no header/footer) |
| `application/views/superadmin/include/sa_header.php` | Purple AdminLTE shell with SA sidebar |
| `application/views/superadmin/include/sa_footer.php` | Script footer + saToast() helper |
| `application/views/superadmin/dashboard.php` | Stats, expiry alerts, recent activity |
| `application/views/superadmin/schools/index.php` | School list with filters |
| `application/views/superadmin/schools/create.php` | 3-step onboarding wizard |
| `application/views/superadmin/schools/view.php` | School detail / edit / subscription |
| `application/views/superadmin/plans/index.php` | Plan cards + create/edit modal |
| `application/views/superadmin/reports/index.php` | 4-tab global reports |
| `application/views/superadmin/monitor/index.php` | Log viewer + system health |
| `application/views/superadmin/backups/index.php` | Backup manager |

---

## Step-by-Step Deployment

### 1. Run the MySQL Migration
Open phpMyAdmin → select your school database → run:
```
application/views/superadmin/sa_migration.sql
```
This creates the `sa_rate_limits` table.

### 2. Create the First Super Admin Account in Firebase
In Firebase Console, manually create this node:

```
System/
  SuperAdmin/
    Auth/
      sa_001/
        email:      "superadmin@yourplatform.com"
        password:   "<bcrypt_hash>"          ← see below
        name:       "Super Admin"
        role:       "superadmin"
        created_at: "2026-01-01 00:00:00"
```

Generate the bcrypt hash using PHP (run in XAMPP shell or a test script):
```php
echo password_hash('YourSecurePassword123!', PASSWORD_BCRYPT);
```
Paste the output as the `password` value in Firebase.

### 3. Create the Backup Directory
```
mkdir application/backups
```
The `Superadmin_backups` controller will create per-school subdirectories automatically.
The `.htaccess` is written automatically on first backup creation.

### 4. Verify Routes
Routes were added to `application/config/routes.php` under the
`// Super Admin SaaS Control Panel` block. All routes begin with `superadmin/`.

### 5. Access the Panel
Navigate to: `http://localhost/Grader/school/superadmin/login`

---

## Integration Points with Existing School Admin

### Subscription Check (Critical)
`MY_Controller.php` checks:
```
Users/Schools/{firebase_key}/subscription/status
```
The SA panel writes to this exact path in:
- `Superadmin_schools::toggle_status()` — sets `status` to active/inactive/suspended
- `Superadmin_schools::assign_plan()` — updates plan_id, expiry_date, status

Changes take effect **immediately** for the school admin (no cache delay).

### Session Isolation
SA sessions use completely separate keys:
- `sa_id`, `sa_name`, `sa_role`, `sa_email`
School admin sessions use: `admin_id`, `admin_name`, `admin_role`

There is NO overlap. A school admin visiting `/superadmin/*` will be redirected to `/superadmin/login`.

### School Sidebar Link
`application/views/include/header.php` now shows an "SA Panel" link in the school admin sidebar
when a valid `sa_id` session key is present. This allows jumping back to the SA panel
without re-logging in (since both sessions can coexist in the same browser).

---

## Firebase Node Structure (SA-owned nodes)

```
System/
  SuperAdmin/Auth/{sa_id}/        ← SA user accounts
  Schools/{school_uid}/            ← SA record per school
    profile/                       ← name, city, email, phone, subdomain, firebase_key, status
    subscription/                  ← plan_id, expiry_date, status, revenue
    stats_cache/                   ← total_students, total_staff, last_updated
  Plans/{plan_id}/                 ← subscription plan definitions
    modules/{module_key}: true|false
  Stats/Summary/                   ← cached global totals (refreshed on demand)
  Logs/Activity/{date}/{id}/       ← SA action log
  Logs/Errors/{date}/{id}/         ← error log
  Backups/{school_uid}/{backup_id}/ ← backup metadata
```

School data lives at:
```
Schools/{firebase_key}/{session_year}/   ← read/written by school admin panel
Users/Schools/{firebase_key}/            ← subscription node read by MY_Controller
```

---

## Security Notes

- SA login is rate-limited: 10 failures = 30-minute IP lockout (MySQL `sa_rate_limits`)
- All SA POST routes go through `MY_Superadmin_Controller::_verify_csrf()` (X-CSRF-Token header)
- Backup download uses path-traversal protection: `preg_replace('/[^A-Za-z0-9_]/', '', ...)` on all path segments
- SA panel emits `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection: 1; mode=block`
- Restore requires typing `RESTORE` in a modal input (double confirmation)
- Backup deletion is permanent and confirmed via modal

---

## Available Modules (Plan Feature Flags)

The following module keys can be toggled per plan in `Superadmin_plans::AVAILABLE_MODULES`:

| Key | Label |
|-----|-------|
| student_management | Student Management |
| staff_management | Staff Management |
| class_management | Class Management |
| subject_management | Subject Management |
| exam_management | Exam Management |
| result_management | Result Management |
| fees_management | Fees Management |
| account_management | Account Management |
| notice_announcement | Notice & Announcement |
| school_management | School Management |
| attendance | Attendance |
| timetable | Timetable |
| id_cards | ID Cards |
| parent_app | Parent App Access |
| teacher_app | Teacher App Access |

The school admin header.php reads the school's plan modules from
`Users/Schools/{firebase_key}/subscription/modules/` and passes them as
`$school_features` to restrict sidebar navigation.
