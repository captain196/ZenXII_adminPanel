# M5 UAT — OPERATOR PROCEDURE

**You execute this. I do not run live financial generation.**
Tenant **`SCH_B56BB9A401`** only · base URL `http://localhost:8080`

---

## ⚠ Read first — three things that matter

1. **This creates real data.** A UAT admission writes a real student, real fee demands, and **consumes
   SRNO 1**. That is intended — you previously decided the first genuine UAT admission should take
   SRNO 1 — but it is not free to undo. R5 showed cleanup costs more than it looks.
2. **Class 9th / Section A only.** That is the only chart in this tenant carrying `feeHeadRef`
   (4 rows: Tuition, Library, Transport, Admission). Admitting into any other class with the flag ON
   will **fail closed** — correctly, but it will look like a bug.
3. **One admission validates two things** — M5 classification *and* the pending SRNO-1 end-to-end
   check. Run the SRNO verifier alongside.

---

## STEP 0 — Baseline (before touching anything)

```bash
cd c:/xampp/htdocs/ZenX/school
php scripts/uat_srno1_admission_verify.php --pre        # SRNO preconditions + snapshot
php scripts/m3_verify_fee_head_registry.php             # registry 24 docs intact
```

Expect: pointer ABSENT, srNoIndex 0, 23 students, feeDemands 26, registry 12+12.
**If any differs, stop — do not proceed.**

## STEP 1 — Turn the flag ON (local only)

Open **`application/config/fee_head_registry.php`** and change the last line:

```php
$config['USE_FEE_HEAD_REGISTRY'] = true;    // UAT ONLY — revert at Step 6
```

Save. No restart needed (PHP reads it per request).

**Do not commit this change.**

## STEP 2 — Chart durability (the prerequisite, verified through the UI)

1. Browse to **`http://localhost:8080/fees/class_fees`**
2. Select **Class 9th → Section A**.
3. Change one amount — e.g. Tuition Fee `600` → `650`.
4. Click **Save** (posts to `fees/save_updated_fees`).

Then:

```bash
php scripts/m3_verify_fee_head_registry.php
```

**PASS =** all 4 rows still carry `feeHeadRef`. **FAIL =** any row lost it → stop and tell me.

*(Optional: set the amount back to 600 and save again — the ref must survive both saves.)*

## STEP 3 — Admission (the main test)

1. Browse to **`http://localhost:8080/sis/admission`**
2. Fill the form:
   - **Class 9th**, **Section A** ← mandatory, see warning 2
   - a clearly-marked test name, e.g. `M5 UAT Student`
   - **leave SR Number blank** — allocation must be automatic
3. Submit.

**Expect: success**, message naming the new student id.

If it returns *"fee head unresolved"* — that is M5 failing closed. Send me the exact message; do not retry.

### Verify

```bash
php scripts/uat_srno1_admission_verify.php --post
```

**Expect all PASS**, including pointer = 1, `srNoIndex/SCH_B56BB9A401_1`, student `srNo = 1`, the 23
legacy students untouched and still SRNO-less, and STU0012's 26 demands intact.

Then check the new demands carry the registry stamp — read any of the new student's demands in the
Firebase console (collection `feeDemands`, filter `studentId`) and confirm on each:

| Field | Expected |
| --- | --- |
| `feeHeadRef` | `FH_…` (e.g. Tuition → `FH_DF3C200439E2`) |
| `category` | `academic` for Tuition/Library/Admission, **`transport` for Transport Fee** |
| `categorySource` | **`registry`** |
| `feeHead` | the display name, as a snapshot |
| `demandId` | **must NOT contain the `FH_…` ref** |

## STEP 4 — Module fee (Transport)

1. Browse to **`http://localhost:8080/transport/assignments`**
2. Assign the new UAT student to any active route.

**Expect:** a Transport demand with `category = transport`, `categorySource = registry`, and a demand
id ending **`_TRANSPORT_FEE_TRANSPORT`** — byte-identical in shape to the existing ones.

**If the assignment silently raises no fee**, check the log for `ACC_FEEHEAD_UNRESOLVED path=module`
— that is fail-closed, and I need to see it.

## STEP 5 — Negative test (proves fail-closed works)

This is the most valuable step; it proves the safety net is real.

1. Go back to **`/fees/class_fees`**, Class 9th / Section A.
2. **Add a new fee title** that is *not* in the registry — e.g. `UAT Unregistered Fee`, amount `100`.
   (Add it via **`/fee_management/categories`** → fee titles if the chart screen requires the title to
   exist first.)
3. Save the chart.
4. Now run a monthly generation: **`http://localhost:8080/fees/generate_monthly_demands`** (or the
   *Generate Demands* button on the fees dashboard) for Class 9th / Section A.

**Expect: generation REFUSES** with `fee_head_unresolved`, and the log shows
`ACC_FEEHEAD_UNRESOLVED path=monthly … head=[UAT Unregistered Fee]`.

**Expect: NO demand was created for that head** — that is the whole point.

Then **remove the unregistered title** before continuing.

## STEP 6 — Flag OFF again (mandatory)

Revert `application/config/fee_head_registry.php` to:

```php
$config['USE_FEE_HEAD_REGISTRY'] = false;
```

Then confirm legacy behaviour is restored — repeat Step 2's chart save and check it still succeeds.

## STEP 7 — Report back

Send me:

- the admission response message and the new student id
- output of `uat_srno1_admission_verify.php --post`
- the field values from one new demand (Step 3 table)
- the Transport demand id and category
- the Step 5 refusal message
- any `ACC_FEEHEAD_UNRESOLVED` lines from `application/logs/`

---

## What I will NOT do, and why

I won't run Steps 2–5 myself: they need an authenticated admin session, and driving them from a script
would bypass the CSRF, RBAC and session guards that are part of what's being validated. I also won't
flip the flag in any committed state — it stays `false` in the repo.

## If anything goes wrong

Set the flag back to `false` first — that alone restores every previous behaviour. Nothing in M5
modifies an existing demand, so there is no financial state to repair. Then send me the symptom.
