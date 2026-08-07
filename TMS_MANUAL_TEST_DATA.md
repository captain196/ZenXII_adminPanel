# TMS Manual Test Data — Realistic Seed Set

**Purpose:** copy-paste values for manually populating and end-to-end testing the Transport Management System across the admin panel, Teacher app, and Parent app.
**Tenant assumed:** your existing test school (e.g. `SCH_D94FE8F7AD` / Ankit Test School) on session `2026-27`.
**No data is written for you.** Use these as form-fill inputs. Nothing in this file executes.
**Do not use in production tenants.** Reset via admin UI after testing.

---

## Section 0 — Prerequisites (should already exist in your test tenant)

- At least **10 active students** in class 6th–10th (any sections).
- At least **6 active staff members** you can promote to driver / helper roles.
- At least **1 admin login** with `Super Admin` or `School Super Admin` role.
- Fee heads: create a fee head named exactly **"Transport Fee"** (category "Transport", type "Monthly") if you don't already have one.

---

## Section 1 — Vendors (F1 → Transport → Vendors)

**Actual form fields (from Add Vendor modal):** Vendor Name*, Contact Person, Phone, Email, GSTIN, Address, Notes. Status defaults to Active. Vendor code + Type + PAN + Contract dates are NOT captured on this form — use the Notes field to record any non-schema info if you want to remember it.

Add 2 vendors so you can attach vehicles to different ownership models.

### Vendor 1 — Owned fleet (internal)

| Form field | Value |
|---|---|
| Vendor Name * | ZenX School Transport (Owned) |
| Contact Person | Rajesh Kumar |
| Phone | 9876500001 |
| Email | fleet@zenxschool.test |
| GSTIN | 24AABCZ1234K1Z5 |
| Address | Vidyanagar, Sector 12, Ahmedabad, Gujarat 380009 |
| Notes | Type: Owned · Code: ZST-OWN · PAN: AABCZ1234K |

### Vendor 2 — Contracted vendor

| Form field | Value |
|---|---|
| Vendor Name * | Shree Ganesh Travels |
| Contact Person | Nitin Patel |
| Phone | 9876500002 |
| Email | nitin@sgtravels.test |
| GSTIN | 24AABCS9876L1Z3 |
| Address | Bopal Cross Roads, Ahmedabad, Gujarat 380058 |
| Notes | Type: Contracted · Code: SGT-EXT · PAN: AABCS9876L · Contract: 2026-04-01 → 2027-03-31 · Value: ₹12,00,000/yr |

---

## Section 2 — Vehicles (F1 → Transport → Vehicles)

**Actual form fields (Add Vehicle modal):** Vehicle Number*, Type (Bus/Van/Auto/Car — no "Mini Bus"), Capacity, Fuel Type (Diesel/Petrol/CNG/Electric/Hybrid), Driver Staff ID + Conductor Staff ID (HR link), Driver Name + Phone (fallback), Ownership (School/Contract), Vendor (only if Contract), Monthly Hire + Contract Start/End (only if Contract), Insurance No + Expiry, Permit No + Expiry, Fitness Expiry, PUC Expiry, Road Tax Expiry, Service KM Interval, GPS Enabled + GPS Device ID.

**Form does NOT capture:** Make, Model, Year, Chassis, Engine, Odometer, RC expiry, Seating (form uses "Capacity"). Skip these — no field to fill.

Add 4 vehicles across both vendors.

### Vehicle 1 — Main school bus (owned)

| Form field | Value |
|---|---|
| Vehicle Number * | GJ01AB1234 |
| Type | Bus |
| Capacity | 40 |
| Fuel Type | Diesel |
| Driver Staff ID | (blank if unknown — set after Section 4) |
| Conductor Staff ID | (blank if unknown) |
| Driver Name (fallback) | Ramesh Solanki |
| Driver Phone (fallback) | 9876500011 |
| Ownership | School |
| Vendor | (leave blank — School ownership) |
| Insurance No. | 3001/HD/GJ01AB1234/2026 |
| Insurance Expiry | 2027-03-31 |
| Permit No. | PRMT-GJ01AB1234 |
| Permit Expiry | 2027-06-30 |
| Fitness Expiry | 2027-11-30 |
| Pollution (PUC) Expiry | **2026-07-25** (near expiry — for testing expiry-cache alerts) |
| Road Tax Expiry | 2027-03-31 |
| Service KM Interval | 10000 |
| GPS Enabled | No |
| GPS Device ID | (leave blank — Phase 5 populates) |

### Vehicle 2 — Second bus (owned)

| Form field | Value |
|---|---|
| Vehicle Number * | GJ01CD5678 |
| Type | Bus |
| Capacity | 34 |
| Fuel Type | Diesel |
| Driver Name (fallback) | Mahesh Vaghela |
| Driver Phone (fallback) | 9876500012 |
| Ownership | School |
| Insurance No. | 3001/HD/GJ01CD5678/2026 |
| Insurance Expiry | 2027-01-15 |
| Permit No. | PRMT-GJ01CD5678 |
| Permit Expiry | 2027-08-15 |
| Fitness Expiry | 2027-05-25 |
| Pollution (PUC) Expiry | 2026-10-10 |
| Road Tax Expiry | 2027-01-15 |
| Service KM Interval | 10000 |
| GPS Enabled | No |

### Vehicle 3 — Van (contracted, F1 test)

| Form field | Value |
|---|---|
| Vehicle Number * | GJ01EF9012 |
| Type | Van |
| Capacity | 26 |
| Fuel Type | Diesel |
| Driver Name (fallback) | Kiran Prajapati |
| Driver Phone (fallback) | 9876500013 |
| Ownership | **Contract** (this reveals Monthly Hire + Contract Start/End rows) |
| Vendor | Shree Ganesh Travels |
| Monthly Hire (Rs) | 45000 |
| Contract Start | 2026-04-01 |
| Contract End | 2027-03-31 |
| Insurance Expiry | 2027-02-28 |
| Permit Expiry | 2027-11-05 |
| Fitness Expiry | 2027-08-20 |
| Pollution (PUC) Expiry | **2026-07-18** (near expiry) |
| Road Tax Expiry | 2027-02-28 |
| Service KM Interval | 10000 |
| GPS Enabled | No |

### Vehicle 4 — Small van (contracted, event-only)

| Form field | Value |
|---|---|
| Vehicle Number * | GJ01GH3456 |
| Type | Van |
| Capacity | 7 |
| Fuel Type | Petrol |
| Ownership | **Contract** |
| Vendor | Shree Ganesh Travels |
| Monthly Hire (Rs) | 18000 |
| Contract Start | 2026-04-01 |
| Contract End | 2027-03-31 |
| Insurance Expiry | 2027-06-30 |
| Permit Expiry | 2027-12-31 |
| Fitness Expiry | 2027-09-30 |
| Pollution (PUC) Expiry | 2026-11-15 |
| Road Tax Expiry | 2027-06-30 |
| Service KM Interval | 10000 |
| GPS Enabled | No |

---

## Section 3 — Routes & Stops (F2 → Transport → Routes & Stops)

**Route form fields (Add Route modal):** Route Name*, Vehicle (dropdown), Monthly Fee (Rs) — one fee per route, not per stop, Start Point, End Point, Distance (km).
**Stop form fields (Add Stop modal):** Route*, Stop Name*, Pickup Time, Drop Time, Order.
**Form does NOT capture:** Route code, Direction (Two-way inferred), Estimated duration, per-stop landmark, per-stop fee, per-stop direction. Fold landmark into the Stop Name if you want it visible ("Vastrapur Lake Gate — near jogging park entrance").

Add 2 routes, each with 4 stops.

### Route 1 — North Circle

| Form field | Value |
|---|---|
| Route Name * | North Circle |
| Vehicle | GJ01AB1234 |
| Monthly Fee (Rs) | 1500 |
| Start Point | Vastrapur Lake Gate |
| End Point | ISCON Signal |
| Distance (km) | 22 |

**Stops for Route 1 (add via "Add Stop" after route is saved):**

| Route | Stop Name * | Pickup Time | Drop Time | Order |
|---|---|---|---|---|
| North Circle | Vastrapur Lake Gate | 06:50 | 15:15 | 1 |
| North Circle | Prahladnagar Cross Roads | 07:05 | 15:00 | 2 |
| North Circle | Satellite Square | 07:20 | 14:45 | 3 |
| North Circle | ISCON Signal | 07:35 | 14:30 | 4 |

### Route 2 — South Circle

| Form field | Value |
|---|---|
| Route Name * | South Circle |
| Vehicle | GJ01CD5678 |
| Monthly Fee (Rs) | 1400 |
| Start Point | Maninagar Railway Colony |
| End Point | Ghodasar BRTS Stop |
| Distance (km) | 18 |

**Stops for Route 2:**

| Route | Stop Name * | Pickup Time | Drop Time | Order |
|---|---|---|---|---|
| South Circle | Maninagar Railway Colony | 06:55 | 15:10 | 1 |
| South Circle | Kankaria East Gate | 07:10 | 14:55 | 2 |
| South Circle | Isanpur Circle | 07:25 | 14:40 | 3 |
| South Circle | Ghodasar BRTS Stop | 07:40 | 14:25 | 4 |

### Route 3 (optional — for event dispatch testing) — Central Loop

Only if you want a 3rd route for event testing:

| Form field | Value |
|---|---|
| Route Name * | Central Loop |
| Vehicle | GJ01EF9012 |
| Monthly Fee (Rs) | 1200 |
| Start Point | School Main Gate |
| End Point | Science City |
| Distance (km) | 15 |

Stops: add 3 short ones — e.g. "School Main Gate" (Order 1), "Sola Overbridge" (Order 2), "Science City Gate" (Order 3), any times.

---

## Section 4 — Drivers (F4 → Transport → Drivers → open a driver → Profile tab)

**How the Drivers tab works:** the tab lists staff members who have a driver-appropriate role in HR. There is **no "Add Driver" button** — HR owns identity. To make someone a driver: (1) create/edit them in Staff module with a Transport-related role, (2) they appear in the Drivers list, (3) click their row to open the Driver Modal → **Profile tab** shows an editable `driver_meta` block with these fields:

**Editable driver_meta form fields:** Licence No., Class (LMV/HMV/Bus/Auto/Multi), Licence Issued, Licence Expires, Issuing Authority, Years Experience, Medical Last Check, Medical Expires, Badge No., Badge Expires, Can Drive Types (comma), Operational Status (Available/Unavailable/On_Leave/Medical_Leave/Training/Suspended), Emergency Contact Name, Emergency Contact Phone, Replacement Availability (No/Yes).

**Form does NOT capture:** Assigned vehicle (that's set on the Vehicle form via Driver Staff ID), Assigned route (routes bind to vehicles), Blood group, Police verification. Fold blood group + police verification into the staff HR Notes if needed.

### Driver 1 — Primary bus driver (North Circle vehicle GJ01AB1234)

Pre-req: a staff record with driver role, e.g. "Ramesh Solanki". Open their Driver row → Profile tab.

| Form field | Value |
|---|---|
| Licence No. | GJ0120220012345 |
| Class | HMV |
| Licence Issued | 2018-05-10 |
| Licence Expires | 2028-05-09 |
| Issuing Authority | RTO Ahmedabad (Subhash Bridge) |
| Years Experience | 12 |
| Medical Last Check | 2026-01-15 |
| Medical Expires | 2027-01-15 |
| Badge No. | ZST-DRV-01 |
| Badge Expires | 2028-05-09 |
| Can Drive Types | Bus,Van |
| Operational Status | Available |
| Emergency Contact Name | Suresh Solanki (brother) |
| Emergency Contact Phone | 9876500011 |
| Replacement Availability | Yes |

### Driver 2 — Secondary bus driver (South Circle vehicle GJ01CD5678)

Staff: "Mahesh Vaghela".

| Form field | Value |
|---|---|
| Licence No. | GJ0120200034567 |
| Class | HMV |
| Licence Issued | 2015-08-22 |
| Licence Expires | **2026-08-30** (near expiry — stress-tests expiry alerts) |
| Issuing Authority | RTO Ahmedabad (Subhash Bridge) |
| Years Experience | 15 |
| Medical Last Check | 2026-04-20 |
| Medical Expires | 2027-04-20 |
| Badge No. | ZST-DRV-02 |
| Badge Expires | 2027-08-30 |
| Can Drive Types | Bus |
| Operational Status | Available |
| Emergency Contact Name | Ritaben Vaghela (wife) |
| Emergency Contact Phone | 9876500022 |
| Replacement Availability | No |

### Driver 3 — Van driver (contracted)

Staff: "Kiran Prajapati".

| Form field | Value |
|---|---|
| Licence No. | GJ0120210056789 |
| Class | LMV |
| Licence Issued | 2020-01-15 |
| Licence Expires | 2030-01-14 |
| Issuing Authority | RTO Gandhinagar |
| Years Experience | 6 |
| Medical Last Check | 2026-06-30 |
| Medical Expires | 2027-06-30 |
| Badge No. | SGT-DRV-01 |
| Badge Expires | 2028-01-14 |
| Can Drive Types | Van,Auto |
| Operational Status | Available |
| Emergency Contact Name | Meena Prajapati (mother) |
| Emergency Contact Phone | 9876500033 |
| Replacement Availability | Yes |

**Link driver to vehicle:** after saving driver_meta, go back to Vehicles tab → edit each vehicle → set "Driver Staff ID" to the corresponding STA... ID. That's the HR-canonical link.

---

## Section 5 — Student Assignments (F3 → Transport → Assignments)

**Actual form fields (Assign Student modal):** Student (search box), Route*, Stop, Type (Both / Pickup Only / Drop Only).
**Fee is derived** from the Route's Monthly Fee (Section 3) — the form does not ask for a fee amount. All 8 students on Route 1 → ₹1500/mo; all on Route 2 → ₹1400/mo.

**Bulk-assign path (Bulk Assign modal):** paste a JSON array (up to 500 rows). Each row: `{"student_id":"STU0001","route_id":"RT0001","stop_id":"STP0001","type":"both","subscription_type":"Permanent","fee_category":"Standard"}`.

Assign 8 students, one at a time via the modal.

### North Circle assignments (Route 1 / GJ01AB1234)

| Student (pick from search) | Stop * | Type | Fee (auto from route) |
|---|---|---|---|
| 6th A student #1 | Vastrapur Lake Gate | Both | ₹1500/mo |
| 7th B student #1 | Vastrapur Lake Gate | Both | ₹1500/mo |
| 8th A student #1 | Prahladnagar Cross Roads | Both | ₹1500/mo |
| 9th C student #1 | Satellite Square | Both | ₹1500/mo |

### South Circle assignments (Route 2 / GJ01CD5678)

| Student (pick from search) | Stop * | Type | Fee (auto from route) |
|---|---|---|---|
| 6th B student #1 | Maninagar Railway Colony | Both | ₹1400/mo |
| 7th A student #1 | Kankaria East Gate | Pickup Only | ₹1400/mo (or half if server halves — check) |
| 8th C student #1 | Isanpur Circle | Both | ₹1400/mo |
| 10th A student #1 | Ghodasar BRTS Stop | Drop Only | ₹1400/mo (or half — check) |

**After saving:** confirm each student has a `feeDemands/{demandId}` created with `category="transport"` (verify via Fees module) — this is the F3/F10 integration point. `subscription_type` and `fee_category` default via server for the single-assign path; use Bulk Assign JSON if you want to override.

---

## Section 6 — Fuel Log entries (F1 → Transport → Vehicles → click vehicle row → Manage Vehicle modal → Fuel sub-tab)

**Path:** Fuel entries live inside the **Manage Vehicle** modal (Fuel sub-tab), not on the top-level Expenses tab. Open a vehicle from the Vehicles list, click the "Fuel" tab, fill "Record Fuel".

**Actual form fields (Manage Vehicle → Fuel):** Fuel Date, Odometer (km), Litres*, Rs / litre, Total (Rs), Station, Notes.

**Form does NOT capture:** Bill number as a discrete field — fold it into Notes.

Add 3 fuel logs.

### Fuel entry 1 — GJ01AB1234

| Form field | Value |
|---|---|
| Fuel Date | 2026-07-01 |
| Odometer (km) | 18500 |
| Litres * | 45.2 |
| Rs / litre | 92.50 |
| Total (Rs) | 4181 |
| Station | HP Vastrapur |
| Notes | Bill HP/26/07/00123 |

### Fuel entry 2 — GJ01CD5678

| Form field | Value |
|---|---|
| Fuel Date | 2026-07-01 |
| Odometer (km) | 42300 |
| Litres * | 52.8 |
| Rs / litre | 92.50 |
| Total (Rs) | 4884 |
| Station | HP Maninagar |
| Notes | Bill HP/26/07/00124 |

### Fuel entry 3 — GJ01AB1234 (2nd fill — will show rolling kmpl)

| Form field | Value |
|---|---|
| Fuel Date | 2026-07-05 |
| Odometer (km) | 18820 |
| Litres * | 40.0 |
| Rs / litre | 92.75 |
| Total (Rs) | 3710 |
| Station | BPC Prahladnagar |
| Notes | Bill BPC/26/07/00456 |

**Expected after entry 3:** rolling kmpl label appears at the bottom of the form: `(18820-18500) / 45.2 ≈ 7.08 kmpl`.

---

## Section 7 — Other Expenses (F5 → Transport → Expenses → Record Expense)

**Actual form fields (Expense modal):** Expense Type* (dropdown — options loaded from config: Fuel/Maintenance/Insurance/Toll/Parking/Salary/Vendor_Hire/Tyre/Battery/Repair/Other), Expense Date*, Custom Type Description (only if type=Other), Amount (INR)*, Tax Amount, Tax Rate %, Payment Method* (dropdown: Cash/Cheque/Bank_Transfer/UPI/Card/Online), Payee Type* (dropdown: Vendor/Staff/Other/Bank/Petty_Cash), Payee ID (e.g. VND0001 / STA0007), Payee Name, Vehicle (optional dropdown), Payment Reference (cheque/txn no.), Related Maintenance ID (MNT000001), Related Fuel ID (FUEL000001), Notes, "Force Post to Accounting Now" checkbox, plus dynamic Attachments block (Receipt/Invoice/Quotation/Workshop_Estimate/Warranty/Approval_Letter/Other).

**Form does NOT capture:** Sub-type as a distinct field — fold into Notes. Bill number goes into Payment Reference. Policy number goes into Notes.

Add 4 non-fuel expenses.

### Expense 1 — Maintenance (brake pads)

| Form field | Value |
|---|---|
| Expense Type * | Maintenance |
| Expense Date * | 2026-06-28 |
| Amount (INR) * | 4500 |
| Tax Amount | 0 |
| Tax Rate % | 0 |
| Payment Method * | Bank_Transfer |
| Payee Type * | Vendor |
| Payee ID | (blank or VND ID of Sagar Auto Works if it's set up as a vendor) |
| Payee Name | Sagar Auto Works |
| Vehicle | GJ01AB1234 |
| Payment Reference | SAW/26/06/0891 |
| Notes | Front brake pad set replacement (all 4) |
| Force Post to Accounting Now | ☐ (leave off — respect default posting policy) |

### Expense 2 — Tyre replacement

| Form field | Value |
|---|---|
| Expense Type * | Tyre |
| Expense Date * | 2026-07-02 |
| Amount (INR) * | 24000 |
| Payment Method * | Cheque |
| Payee Type * | Vendor |
| Payee Name | MRF Muzzafar Tyres |
| Vehicle | GJ01CD5678 |
| Payment Reference | MMT/26/07/0234 |
| Notes | 2× rear tyres MRF Steel Muscle |

### Expense 3 — Insurance renewal (large, one-time)

| Form field | Value |
|---|---|
| Expense Type * | Insurance |
| Expense Date * | 2026-07-04 |
| Amount (INR) * | 32500 |
| Payment Method * | Online |
| Payee Type * | Vendor |
| Payee Name | HDFC ERGO |
| Vehicle | GJ01EF9012 |
| Payment Reference | HDFC/PAY/26070400234 |
| Notes | Policy 3001/HD/GJ01EF9012/2026 — comprehensive insurance renewal FY 26-27 |
| Force Post to Accounting Now | ☑ (force post — large expense worth verifying journal immediately) |

### Expense 4 — Toll & Parking (petty cash)

| Form field | Value |
|---|---|
| Expense Type * | Toll |
| Expense Date * | 2026-07-06 |
| Amount (INR) * | 340 |
| Payment Method * | Cash |
| Payee Type * | Petty_Cash |
| Vehicle | GJ01AB1234 |
| Notes | Sarkhej-Gandhinagar highway toll (student exhibition trip) |

**After saving all expenses:** the F5 verifier check `CH-TRN-F5-4` should confirm every doc has a valid `accounting_journal_ref` (either 'pending' or a real journal ID). Total transport spend this month should now be ~₹73,500.

---

## Section 8 — Driver Incidents (F4 → Transport → Drivers → open driver → Incidents tab → "Record Incident")

**Actual form fields (Record Driver Incident modal):** Type* (Accident/Complaint/Traffic_Violation/Late_Arrival/Absenteeism/Safety_Breach/Positive_Recognition), Severity* (Info/Minor/Major/Critical), Incident Date*, Incident Time, Related Vehicle (dropdown), Related Trip ID (TRP…… — F7 populates), Location, Description* (textarea).

**Form does NOT capture:** "Action taken" at record time — it's captured later in the **Resolve Incident** mini-modal (Resolution + Action Taken fields).

### Incident 1 — Minor (Driver 1, on GJ01AB1234)

| Form field | Value |
|---|---|
| Type * | Late_Arrival |
| Severity * | Minor |
| Incident Date * | 2026-06-20 |
| Incident Time | 07:55 |
| Related Vehicle | GJ01AB1234 |
| Related Trip ID | (blank — no trip captured for that day) |
| Location | Iskcon flyover, Ahmedabad |
| Description * | Reached school 20 min late due to unexpected road closure on Iskcon flyover. All students safe on bus. Parents informed via ad-hoc broadcast. |

**Optional follow-up:** open Incidents list → Resolve → Resolution: "Warning issued; alternate route documented", Action Taken: "Toolbox talk 2026-06-21".

### Incident 2 — Critical (Driver 2, on GJ01CD5678) — fires notification

| Form field | Value |
|---|---|
| Type * | Traffic_Violation |
| Severity * | **Critical** (dropdown label reads "Critical — will notify HR + Admin + Transport Manager") |
| Incident Date * | 2026-06-25 |
| Incident Time | 14:35 |
| Related Vehicle | GJ01CD5678 |
| Related Trip ID | (blank or TRP...... if you've completed one) |
| Location | Isanpur circle |
| Description * | Signal jump at Isanpur circle observed by parent (photo submitted via WhatsApp). No collision. Driver claims signal was amber; parent asserts red. Under investigation. |

**Expected:** severity=Critical fires a `driver.critical_incident` notification to Admin + HR Manager + Transport Manager. Verify via Communication module.

---

## Section 9 — Trip creation (F7 → Transport → Trips → "Create Trip")

**Actual form fields (Create Trip modal):** Trip Date*, Direction* (Pickup / Drop), Vehicle*, Route*, Scheduled Start (ISO timestamp like `2026-07-08T06:45:00+05:30`, optional), Notes.

**Form does NOT capture:** Driver (inferred from vehicle's Driver Staff ID), Scheduled End (auto), Status (always Ready on create — start it via row action).

### Trip 1 — Morning Pickup (North Circle, today)

| Form field | Value |
|---|---|
| Trip Date * | (today's date) |
| Direction * | Pickup |
| Vehicle * | GJ01AB1234 |
| Route * | North Circle |
| Scheduled Start (ISO) | 2026-07-08T06:45:00+05:30 (adjust date) |
| Notes | Manual test — morning pickup smoke |

**After creation the trip is Ready.** Then from the Trips list row:
1. Click **Start Trip** → status flips to InProgress; Trip Manage modal opens.
2. In Trip Manage → **Checkpoints panel**, click **Reached** on each of the 4 stops one by one.
3. In Trip Manage → **Roster panel**, click **Boarded** on 3 of the 4 students; skip the 4th (leave as No Show).
4. Click **Complete Trip** → fill End Odometer (18820), Distance (22), Fuel Used (blank OK), Notes ("smoke test"). Confirm.

Verify: `tripLogs/{id}.stopsCompleted=4`, `attendedCount=3`, and the 3 boarded students have `transportAttendance` docs.

### Trip 2 — Afternoon Drop (South Circle, today)

| Form field | Value |
|---|---|
| Trip Date * | (today's date) |
| Direction * | Drop |
| Vehicle * | GJ01CD5678 |
| Route * | South Circle |
| Scheduled Start (ISO) | 2026-07-08T14:15:00+05:30 (adjust date) |
| Notes | Manual test — will be started from Teacher app (Section 12) |

**Do NOT start Trip 2 from admin.** Leave it in Ready state so you can test the Teacher-app driver flow in Section 12.

---

## Section 10 — SOS Events (F6 → Transport → SOS → "Raise SOS")

**Actual form fields (Raise SOS Alert modal):** Alert Type* (Emergency/Breakdown/Accident/Medical/Other), Vehicle (optional), Route (optional), Latitude, Longitude, Message* (textarea).

**Form does NOT capture:** Severity as a distinct field on the **admin** path — severity is implied by the alert type and is captured on the **driver mobile** path (F9 Teacher app). On admin, just pick Alert Type + write the Message. The parents/staff broadcast fires unedited.

### SOS 1 — Breakdown (raise from admin)

| Form field | Value |
|---|---|
| Alert Type * | Breakdown |
| Vehicle | GJ01AB1234 |
| Route | North Circle |
| Latitude | 23.028510 (Satellite Square approx.) |
| Longitude | 72.517430 |
| Message * | Right rear tyre puncture near Satellite Square. Replacement in progress. All students safe on bus. Estimated 20-min delay to school arrival. Parents notified. |

**Expected:** URGENT broadcast fires to Admin + Transport Manager + Principal + all parents whose child is on North Circle. Visible in Parent app inbox and admin Communication module.

### SOS 2 — Fire from Teacher app (Section 12)

The second SOS is fired from the **driver mobile flow** in Section 12, step 9. That path DOES include a client-side severity picker (Low/Medium/High/Critical). Use severity=High + alert_type=medical to differentiate.

---

## Section 11 — Transport Event (F8 → Transport → Events → "Create Event")

**Actual form fields (Create Event modal):** Title*, Type* (Educational_Tour / Sports / Annual_Function / Competition / Excursion / Examination / Field_Trip / Cultural_Event / Other), Description, Event Date (start)*, Event End Date (multi-day), Destination, Charter Type* (Own_Fleet / Chartered / Mixed), Pickup Location, Drop Location, Expected Students Count, Budget (INR, informational), Assigned Vehicles* (comma-separated VH IDs like "VH0001, VH0002"), Notes.

**Roster + escorts + trips are added AFTER create** via the **Manage Event** modal:
- Roster: student_id + optional vehicle → click + button
- Teacher Escort form fields: Staff ID* (STA0001 format), Role* (Lead_Teacher / Assistant / Nurse / Security / Chaperone / Coordinator / Other), Notes
- Dispatch Trip form fields: Vehicle* (must be assigned to event), Route (optional — blank = free-form no route), Direction* (Pickup / Drop), Scheduled Start (ISO, optional)

**Form does NOT capture:** Departure/Return time on the create modal (those go on the dispatched trip's scheduled start). Consent required flag (always on for events). Status (always Planned on create).

### Create the event

| Form field | Value |
|---|---|
| Title * | Class 8 STEM Exhibition — Science City |
| Type * | Field_Trip |
| Description | Full-day STEM exhibition tour for Class 8 A + C |
| Event Date (start) * | 2026-07-20 |
| Event End Date | (blank — single-day) |
| Destination | Gujarat Science City, Hebatpur |
| Charter Type * | Own_Fleet |
| Pickup Location | School main gate |
| Drop Location | School main gate (return) |
| Expected Students Count | 60 |
| Budget (INR) | 12000 |
| Assigned Vehicles * | VH0003, VH0004 (use the actual VH IDs of GJ01EF9012 and GJ01GH3456 — check Vehicles tab) |
| Notes | 08:30 dep · 15:00 return · packed lunch |

### Then — in the Manage Event modal (auto-opens after create):

**Add roster** (Roster panel → student_id input + vehicle (opt) → + button):
- Add 8-15 student IDs (one at a time, or use Bulk assign JSON in earlier section as reference format)
- Vehicle assignment per student is optional — leave blank for auto-distribute

**Add escorts** (Escort panel → + Add):

| Escort | Staff ID * | Role * | Notes |
|---|---|---|---|
| 1 | STA0001 (pick actual Class 8 lead teacher) | Lead_Teacher | Overall in charge |
| 2 | STA0002 (pick a co-teacher) | Assistant | Assists Lead |
| 3 | STA0003 (nurse if you have one) | Nurse | First aid |

**Schedule** — click Schedule button (bottom of manage modal). This fires `event.scheduled` notification to all roster parents with consent request.

**Dispatch a trip** (Dispatch panel → + Dispatch):

| Form field | Value |
|---|---|
| Vehicle * | VH0003 (GJ01EF9012) |
| Route | (blank — free-form event trip, no fixed route) |
| Direction * | Pickup |
| Scheduled Start (ISO) | 2026-07-20T08:30:00+05:30 |

Repeat dispatch for VH0004 if you want a 2nd trip.

**Complete** — after all dispatched trips are Completed (via Trip Manage or Teacher app), click **Complete** on Manage Event modal. Enter Notes ("event completed on time"). Cost tally computes; over-budget generates a warning (not a block).

---

## Section 12 — Teacher App (F9) driver flow — real device test

Log into the Teacher app on your device as **Driver 2's staff account** (from Section 4).

| Step | Expected UI |
|---|---|
| 1. Open More nav → "Bus" | DriverHomeScreen appears |
| 2. Vehicle card | Shows GJ01CD5678 assigned |
| 3. Today's trips | Trip 2 (South Circle Drop) shown as Ready |
| 4. Tap "Start Trip" | Status → InProgress; UI shows checkpoint list |
| 5. At each checkpoint, tap Reached | Checkpoint state updates; roster expands per stop |
| 6. Mark each of 4 students as "Dropped" | `transportAttendance` docs created with `marked_by_role='driver'` (verify via admin dashboard) |
| 7. Tap "Fuel Log" | Form appears with vehicle pre-filled to GJ01CD5678 |
| 8. Submit fuel: 30L, ₹92.75/L | Doc lands in Firestore; visible on admin Expenses tab within seconds |
| 9. Tap "SOS" — pick severity Medium, alert_type mechanical | Alert fires; admin Communication inbox receives; SOS doc has `triggeredByRole='driver'` + client metadata |
| 10. Tap "Complete Trip" | Status → Completed |

---

## Section 13 — Parent App (F10) parent flow — real device test

Log into the Parent app on your device as a parent whose child is on North Circle Route 1.

| Screen | Expected content |
|---|---|
| Dashboard bento | Teal "Transport" tile visible in shortcuts |
| Tap Transport tile | Per-child selector (if multiple children) → tap child |
| MyBusScreen | Vehicle GJ01AB1234; Driver 1 name+phone; Route "North Circle"; Stop "Vastrapur Lake Gate"; pickup 06:50, drop 15:15 |
| Attendance history | Today's boarding record from Trip 1 (Section 9) |
| Events tab | STEM Exhibition event with "Consent pending" badge if child is class 8 |
| Fees tab | Transport Fee demand ₹1500 pending for this month (North Circle monthly fee) |
| Notification Preferences | List of categories; SOS is disabled from muting (safety-critical); toggle "route_change" off → server prefs doc updates |

---

## Section 14 — Cross-module verifications (spot-checks)

After the above steps:

| Verification | Where to look | Expected |
|---|---|---|
| Transport fee demand created | Fees module → student ledger | ₹1400/month with `category=transport` |
| Fuel logs appear in Expenses | Admin → Transport → Expenses → Fuel filter | 3 fuel + 1 mobile submission = 4 rows |
| Accounting journal per expense | Admin → Accounting → Journal register | Every non-blank `accounting_journal_ref` matches a real journal doc |
| SOS in parent inbox | Parent app → Notifications | 2 SOS notifications visible (Section 10) |
| Event notification in parent inbox | Parent app → Notifications | `event.scheduled` visible if child is a class 8 student |
| Driver critical incident notification | Admin → Communication → Sent to Transport Manager | `driver.critical_incident` for Driver 2's signal jump |
| Fleet Health Score on dashboard | Admin dashboard (as Super Admin) → Transport tile | Score in range 0-100 with tier label; low docs_currency subscore expected because we set near-expiry PUCs and one near-expiry driver licence |
| Live Ops snapshot | Admin → Transport Analytics → Live Ops | trips_in_progress ≥ 0, active_sos_events ≥ 2, students_currently_onboard reflects live boarding state |
| SA cross-tenant KPI | Log in as Super Admin → Fleet overview | Your test tenant appears in leaderboard with fuel_spend_this_month ≈ ₹12,775 |
| 24-month expense trend | Analytics → Expense trend | Current month bar visible; prior months empty (expected for a fresh setup) |
| No RTDB writes | Firebase Console → Realtime DB | Zero writes to `VehicleLive/*` or `sosAlerts/*` |
| No live-GPS fields written | Query any `tripLogs` doc | `live_position`, `driver_position`, `gps_last_ping` all NULL |

---

## Section 15 — Reset / cleanup after testing

**Recommended reset order:**
1. Cancel/void the 2 open SOS events (Admin → SOS → mark resolved).
2. Void the 4 expenses (Admin → Expenses → void; accounting journals reverse).
3. Void the transport fee demands via Fees module.
4. Delete student assignments (Transport → Assignments → remove).
5. Delete trips (they auto-purge from `tripLogs/history` after 30 days; safe to leave).
6. Delete the event (Admin → Events → cancel).
7. Deactivate the vehicles you added (do NOT hard-delete — set status=Inactive; preserves audit trail).
8. Deactivate the drivers (revert to non-driver via Staff → clear `can_drive_transport`).
9. Deactivate vendors (soft-delete).
10. Manually verify `analyticsRollups/{YYYY-MM}.transportFleetRollup` for your tenant now reads 0 active vehicles.

**Do not** attempt to delete Firestore docs directly from console — go through admin UI so `accounting_journal_ref`, `feeDemands` cascades, and denormalised caches all reconcile properly.

---

## Appendix — Quick reference

**Total spend for a clean run:** ₹74,115 (₹12,775 fuel + ₹4,500 maintenance + ₹24,000 tyre + ₹32,500 insurance + ₹340 toll — before event costs)
**Total students assigned:** 8
**Total active vehicles:** 4
**Total active routes:** 2 (+ optional 3rd for events)
**Total active drivers:** 3
**Expected fleet_health_score tier** after full setup: **Fair or Attention** (because we intentionally set 2 near-expiry PUCs + 1 near-expiry licence + 1 critical incident to stress-test the score).
**Expected active_sos_events on live-ops:** **2** (from Section 10).
**Expected trips_completed_today after Section 9 walkthrough:** **1 or 2**.

---

*This is a manual-fill helper. Nothing gets seeded automatically. Use only against a non-production test tenant.*
