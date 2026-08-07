# Transport UAT — Step-by-Step Execution Guide (with data + expected output)

**How to use:** run the steps **in order** (later steps reuse data created earlier). For each step: *Data to insert* → *Where/Steps* → *Expected output*. Record the actual output + a status against the matching Test ID in `TMS_TRANSPORT_MODULE_INVESTIGATION_UAT.md`. This spans **Admin Website + Teacher App + Parent App**.

> ⚠ **App-login rule:** the Parent/Teacher apps need **existing real accounts** (do not create demo accounts). Pick a **driver staff** who already has a Teacher-app login, and a **student** whose parent already has a Parent-app login. You'll wire the test bus to that driver + student.

---

## PART 0 — Prerequisites (gather, don't create)
| # | Data to note | Where to find it |
|---|---|---|
| P0-1 | **Driver staff id** (e.g. `STA0007`) — an HR-Active staff with a **Teacher-app login** and driver fields (licence/medical/badge valid, `can_drive_transport`) | Admin → Staff/HR |
| P0-2 | **Student id** (e.g. `STU0003`) with a working **Parent-app login** | Admin → Students; log into the Parent app as that student to confirm |
| P0-3 | Note the student's **class/section** and **parent login id** | Student profile |

If the chosen driver's licence/medical/badge is expired or `operational_status` ≠ Active, trip create/start will be **correctly rejected** (LOG-1) — use a ready driver, or that becomes a LOG-1 test.

---

## PART 1 — Admin fixture setup (build the test bus)

### Step 1.1 — Create the test vehicle *(TR-F1-01)*
**Data:** Number `UAT-BUS-01` · Type `bus` · Capacity `40` · **Driver (staff_id) = P0-1 driver** · Driver name `UAT Driver` · Insurance expiry `2027-12-31` · Fitness expiry `2027-12-31` · Permit expiry `2027-12-31` · Pollution expiry `2027-12-31` · Road-tax expiry `2027-12-31`
**Steps:** Admin → **Transport → Vehicles → Add Vehicle** → fill above → Save.
**Expected:** Vehicle `UAT-BUS-01` appears in the list; lifecycle = Active; driver shows the P0-1 driver. *(Backend: `vehicles/{schoolId}_VH…`, `number=UAT-BUS-01`, `staff_id=<driver>`.)*

### Step 1.2 — Blank-field validation *(TR-F1-02)*
**Data:** blank number, capacity `0`. **Steps:** Add Vehicle → Save. **Expected:** rejected with a validation message; nothing created.

### Step 1.3 — Create route + 2 stops *(TR-F2-01)*
**Data:** Route name `UAT Route Alpha` · Status Active · assign **vehicle = UAT-BUS-01**. Stops: `UAT Stop 1` (order 1, pickup 07:30), `UAT Stop 2` (order 2, pickup 07:45).
**Steps:** Transport → **Routes → Add Route** (set vehicle) → add the 2 stops.
**Expected:** Route + 2 stops created; route shows vehicle `UAT-BUS-01`.

### Step 1.4 — Assign the P0-2 student to the route *(TR-F3-01)*
**Data:** Student = P0-2 · Route `UAT Route Alpha` · Stop `UAT Stop 1` · subscription_type `monthly` · direction `both` · fee_category `regular`.
**Steps:** Transport → **Assignments → Assign Student** → fill → Save.
**Expected:** Assignment created; capacity cache current_count = 1; a **transport fee demand** is generated for the student for the current session. *(Backend: `studentRoutes`, `busCapacityCache`, `feeDemands`.)*

### Step 1.5 — Student search *(TR-F3-06, NF-1)*
**Data:** type the first 2–3 letters of the P0-2 student's name.
**Steps:** In the Assign-Student picker, type the search term.
**Expected:** matching students returned (name/id) — **no 500 error**. Typing 1 char → empty, no error *(TR-F3-07)*.

---

## PART 2 — Trip lifecycle (Admin → Teacher App → Parent App)

### Step 2.1 — Create a trip *(TR-F7-01)*
**Data:** Vehicle `UAT-BUS-01` · Route `UAT Route Alpha` · Trip date **today** · Direction `Pickup` · Kind `Regular`.
**Steps:** Transport → **Trips → Create Trip** → fill → Save.
**Expected:** Trip created in **Ready** state; checkpoints snapshot the 2 stops; roster includes the P0-2 student. Note the **trip id** (e.g. `TRP0005`).

### Step 2.2 — (Negative) Dispatch on a suspended route *(TR-F2-04, LOG-2)*
**Data:** suspend `UAT Route Alpha` (Routes → Suspend: from today, reason "UAT"). Then try Create Trip on it.
**Expected:** **Rejected** — "Route currently has an active suspension. Resume before dispatching." Then **Resume** the route (Routes → Resume) before continuing. *(Expected on resume: `active_suspension_ref` cleared.)*

### Step 2.3 — Teacher App: start the trip *(TR-F7-02, XMOD-1)*
**Login:** Teacher App as the **P0-1 driver**.
**Steps:** Driver Home → today's trip for `UAT-BUS-01` → **Start Trip**.
**Expected:** Trip → **InProgress**; Driver Home shows the **route badge** (currentRouteId now populated). *(Backend: `vehicles.currentRouteId = <route>`.)*

### Step 2.4 — Teacher App: board the student *(TR-F7-03)*
**Steps:** Open the trip → student list → **Mark P0-2 student Boarded** at Stop 1.
**Expected:** boarding recorded. *(Backend: `transportAttendance/{schoolId}_{tripId}_{studentId}_boarded_pickup`.)*

### Step 2.5 — Teacher App: no-show a NON-rostered student *(TR-F7-04, LOG-5)*
**Data:** attempt to mark **no-show for a student who is NOT on this trip's roster** (if the UI allows selecting one).
**Expected:** **Rejected** — "not on the frozen roster for trip". *(If the app only lists rostered students, note that as UI-enforced and mark N/A.)*

### Step 2.6 — Teacher App: complete the trip *(TR-F7-07)*
**Steps:** mark both checkpoints Reached → **Complete Trip**.
**Expected:** Trip → **Completed**; summary shows boarded=1, no_show=0; Driver Home route badge **clears** (currentRouteId back to empty).

### Step 2.7 — Admin: live-ops onboard count *(TR-F7-09, B1)*
**Timing:** do this **while a trip is InProgress with a boarded student** (repeat 2.1→2.4 without completing, OR check during 2.4).
**Steps:** Admin → **Transport → Analytics / Dashboard → Live Ops**.
**Expected:** **students_currently_onboard = 1** (boarded − dropped), **NOT 0**. This is the B1 fix — the number must reflect real boardings.

### Step 2.8 — Admin: trip ops notice in the bell *(TR-F7-10, B3)*
**Steps:** After starting/completing a trip, open the **Admin notification bell** (top header).
**Expected:** `trip.started` / `trip.completed` entries **appear** in the bell (they now carry `timestamp`). Pre-fix they were invisible.

---

## PART 3 — SOS (Teacher App → Admin)

### Step 3.1 — Teacher App: raise SOS *(TR-F6-01, XMOD-1)*
**Login:** Teacher App as P0-1 driver (ideally during an InProgress trip).
**Data:** Alert type `Breakdown` (or as offered) · Severity `High` · Message `UAT SOS test`.
**Steps:** SOS screen → **Trigger SOS** → confirm.
**Expected:** SOS submitted; `sosAlerts` created (active). Route context attached if on a trip.

### Step 3.2 — Admin: SOS in the bell *(TR-F6-02, B3)*
**Steps:** Admin (as Admin/TM/Principal) → notification bell.
**Expected:** `sos.raised` (URGENT) **visible** with vehicle/route/message.
> ⚠ **Platform observation (TR-P-08 / C1):** if you log the Parent app in and query notices, this SOS is *currently readable by parents* — record the actual behavior; this is a **platform finding**, not a Transport fix.

### Step 3.3 — Admin: respond → resolve *(TR-F6-03)*
**Steps:** Transport → SOS → Respond, then Resolve.
**Expected:** status transitions Active → Responded → Resolved; `sos.responded`/`sos.resolved` ops notices in the bell.

---

## PART 4 — Expenses + Accounting (FIN-3) — Admin

> **Precondition:** for the *posted-expense* tests (4.2/4.3) the expense must actually **post** to Accounting (auto-post depends on the school's Chart-of-Accounts + posting mode = auto). If auto-post stays **pending** in your tenant, the immutability guard won't trigger (pending expenses are editable by design) — note that and treat 4.2/4.3 as "not reproducible without live accounting."

### Step 4.1 — Create an expense *(TR-F5-01)*
**Data:** Type `Vehicle_Fuel` · Amount `1500` · Date **today** · Payee type `External` · Payee `UAT Fuel Station` · Payment `Cash` · force-post = yes (if the UI offers it).
**Steps:** Transport → **Expenses → Add Expense** → Save.
**Expected:** Expense created; posting status shows `posted` (if accounting live) or `pending`.

### Step 4.2 — Edit posted amount is blocked *(TR-F5-02, FIN-3)*
**Data:** on the **posted** expense, change amount to `2000`.
**Expected:** **Rejected** — "Cannot change the amount, tax, payment method, type, or date of a posted expense … Void and create a new one."

### Step 4.3 — Edit posted **date** is blocked *(TR-F5-03, A1)*
**Data:** on the **posted** expense, change expense_date to `2026-01-01`.
**Expected:** **Rejected** — same message (date now protected). Record unchanged.

### Step 4.4 — Non-financial edit allowed *(TR-F5-04)*
**Data:** on the posted expense, edit **Notes** = "UAT note".
**Expected:** Saved; journal ref preserved; no re-post.

### Step 4.5 — Void posted expense *(TR-F5-05, FIN-4)*
**Steps:** Void the posted expense with reason "UAT void" (accounting live).
**Expected:** status **Voided**; a reversing journal is posted (GL nets to zero).

---

## PART 5 — Events (Admin → Parent App)

### Step 5.1 — Create event + assign student *(TR-F8-01)*
**Data:** Title `UAT Picnic` · type `Excursion` · event date **+3 days** · destination `UAT Park` · assign the **P0-2 student**.
**Steps:** Transport → **Events → Create Event** → assign student.
**Expected:** Event created (Planned); `eventTripAssignments` with consent = Pending.

### Step 5.2 — Add a terminated escort is blocked *(TR-F8-03, LOG-4)*
**Data:** add an escort whose staff record is **Inactive**.
**Expected:** **Rejected** — "Escort … is not Active in HR." Add an **Active** staff escort instead *(TR-F8-02: accepted)*.

### Step 5.3 — Schedule the event *(TR-F8-04)*
**Steps:** ensure a vehicle + active escort + roster, then **Schedule**.
**Expected:** Scheduled; `event.scheduled` notice → the P0-2 student's parent inbox.

### Step 5.4 — Parent App: event + consent *(TR-F8-06)*
**Login:** Parent App as P0-2's parent.
**Steps:** Transport → **Events**.
**Expected:** `UAT Picnic` visible with date/destination; consent status shown.

---

## PART 6 — Parent App verification

| Step | Test ID | Steps | Expected |
|---|---|---|---|
| 6.1 | TR-P-01 | Parent App → **My Bus** | Route `UAT Route Alpha`, stop, vehicle shown. *(Note: bus **number** may be blank pending app-side XMOD-2 mapping — record it.)* |
| 6.2 | TR-P-02 | **Trips today** | today's trip + status |
| 6.3 | TR-P-03 | **Attendance** | the boarding from Step 2.4 appears (own child only) |
| 6.4 | TR-P-04 | **Notifications inbox** | the event/trip parent notice appears (delivered to this student's `userId`) — **recipient-contract check** |
| 6.5 | TR-P-05 | **Preferences** → mute a non-safety category | muted category stops arriving; safety (SOS) still arrives |
| 6.6 | TR-P-06 | **Transport Fees** | the pending demand from Step 1.4 + any receipts |
| 6.7 | TR-P-07 | (if parent has 2 children) open transport | only own children's data — no other student |

---

## PART 7 — Analytics, RBAC, regression — Admin

| Step | Test ID | Steps | Expected |
|---|---|---|---|
| 7.1 | TR-F11-01 | Transport → Analytics | KPI tiles / bundle render |
| 7.2 | TR-F11-02 | Log in as a role **without** the Operations RBAC grant → open Analytics | empty/denied (SEC-3) |
| 7.3 | TR-F11-03 | Hit `/transport_sos`, `/transport_trip`, `/transport_analytics/bundle` as TM | endpoints respond (SEC-4 — no phantom `role` fail-closed) |
| 7.4 | TR-F11-04 | Health / trend / top-drivers / per-route widgets | load without stalling |
| 7.5 | TR-X-05 | (Ops/CLI) `SCHOOL_ID=… php index.php transport_verifier verify` | **94 gates PASS** (5 pre-existing data FAILs) |

---

## Cleanup (after UAT)
Delete/void the UAT fixtures in reverse order: void the UAT expense, cancel/complete open trips, unassign the student, delete stops + route, delete `UAT-BUS-01`. (Or leave them — they're clearly `UAT-`prefixed test data.)

---

## Known preconditions / non-blockers to expect during UAT
- **Driver readiness (LOG-1):** the vehicle's driver must be HR-Active with valid licence/medical/badge, else trip create/start is *correctly* rejected.
- **Accounting live for FIN-3/FIN-4 posting:** if auto-post stays `pending`, the posted-expense immutability (4.2/4.3) and reversal (4.5) can't be exercised — note as environment-dependent.
- **App-side residuals (record, don't fail Transport):** bus **number** blank in Parent/Teacher (XMOD-2), driver/expiry blank in Teacher VehicleDoc (XMOD-3) — these are **app-release** items, not admin defects.
- **Platform observation (C1):** ops notices (SOS/expense/driver) are currently readable by same-school parents — a **platform finding**, recorded via TR-P-08, not fixed here.

*Working guide; not committed to git.*
