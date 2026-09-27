# Calendar — Browser Verification Checklist

**NOT PERFORMED.** No authenticated browser interaction has taken place. Route
reachability is proven by HTTP (`/student_calendar` → 307 → `admin_login`,
identical to `/attendance`; a bogus route → 404); nothing beyond that is.

Everything below needs a real operator login. Roughly 15 minutes.

## Before you start

- Open DevTools, **Console** and **Network** tabs, and keep them open. A red
  console line is a failure even if the screen looks correct — the one defect
  already found in this programme was a JavaScript syntax error that rendered
  nothing visibly wrong until the feature was used.
- Note the school and session shown in the header (e.g. `VIKRANT PUBLIC` /
  `2026-27`).
- **Serious failure** below means: stop, capture the console and the Network
  entry, and report. Do not work around it.

---

### 1 · Page load

**URL** `localhost/ZenX/school/student_calendar` — or sidebar **Attendance → School Week**
**Prerequisite** logged in with Attendance *view*
**Action** load the page
**Expect** an "In force today" card; the "Loading the school week…" text disappears
**Serious failure** 404; a redirect to login while already logged in; a permanent
spinner; any console error

### 2 · Permissions / read-only

**Prerequisite** a user with Attendance **view but not manage**
**Action** load the page
**Expect** an amber banner saying the week can be viewed but not changed; every
input and the Save button **disabled**; history still readable
**Serious failure** the form is usable, or Save is clickable

### 3 · Legacy (unconfigured) state

**Prerequisite** a school that has never saved a school week
**Action** read the "In force today" card
**Expect** Source = *"Original rule (not yet configured)"*; In force since = *Not set*;
Non-teaching days = **Sunday** only; history shows its empty-state text
**Serious failure** it claims to be configured, or shows Saturday closed

### 4 · Configured state

**Prerequisite** a school with a saved week
**Action** read the same card
**Expect** Source = *"Configured by your school"*, a real "In force since" date,
and the history row for that date marked **in force**
**Serious failure** Source still says "Original rule" despite saved versions

### 5 · Divergence banner

**Prerequisite** Attendance Settings holds a `working_days` different from the
active week (e.g. saved Mon–Fri while attendance runs six days)
**Action** load the page
**Expect** an amber banner naming both, and stating *"Nothing has been changed"*
**Serious failure** no banner when the two genuinely differ

### 6 · Divergence loads without saving

**Action** press **Load these days into the editor**
**Expect** the weekday toggles change; a warning appears saying it is **not saved
yet**; the preview updates; **history gains no row**; Network shows **no**
`save_version` call
**Serious failure** a `save_version` request fires, or history gains a row. This
is the single most important check on the page — silently adopting stale
configuration is the exact bug the flow exists to prevent.

### 7 · Preview

**Action** tick **Sat**; then set an alternating rule *Sat, weeks 2 and 4*
**Expect** with Sat ticked, every Saturday shades. With the nth rule instead,
**only the 2nd and 4th** Saturdays shade — the 1st and 3rd stay open. Each shaded
day carries a reason label
**Serious failure** grid unchanged; all Saturdays shade under an nth rule; an
error under the calendar

### 8 · Holiday visibility in preview

**Prerequisite** a declared student holiday in the previewed month
**Action** preview that month
**Expect** that date shaded and labelled with the **holiday's own name**, not
"Weekly off"
**Serious failure** a declared holiday shows as a normal teaching day

### 9 · Special working day

**Prerequisite** a `special_working_day` declared on a Sunday
**Action** preview that month
**Expect** that Sunday is **NOT** shaded — a declared makeup day is a teaching day
**Serious failure** it stays shaded; the override is being ignored

### 10 · Past / settled date is refused

**Action** open the "Starts from" picker; try to choose a date in a past month.
If the picker blocks it, type one directly and press Save
**Expect** the picker's minimum is the day **after** the settled date. A typed
past date is refused with an explanation naming the settled date
**Serious failure** **it saves.** Report immediately — that is a history rewrite,
the invariant this whole programme is built around.

### 11 · Successful save

**Action** choose a valid future date, optionally a reason, press Save
**Expect** green confirmation naming the date and saying past dates are
unchanged; history gains exactly one row; **earlier rows unchanged**
**Serious failure** a blank page or nothing at all (check Network for a 403 →
CSRF); an existing history row changes

### 12 · CSRF rotation

**Action** immediately after the save in step 11, save **again** with a different
future date — without reloading
**Expect** the second save also succeeds
**Serious failure** the second save fails with 403 while the first succeeded —
the token is not being rotated from the response

### 13 · Conflict handling

**Prerequisite** two browser sessions on the page (two admins)
**Action** in A press Save; then in B (stale) press Save
**Expect** B is refused with a message about someone else changing settings, and
is offered **Reload** — never a silent retry
**Serious failure** B's save succeeds and A's version disappears

### 14 · Substitute warning + explicit confirmation

**URL** Academic Planner → Substitutes
**Prerequisite** a teacher with periods, on a **declared student holiday**
**Action** select the teacher and that date
**Expect** an amber note saying no classes are scheduled; **periods still listed**.
Press Save → a confirm dialog appears. **Cancel** → nothing saved, a "Cover not
arranged" message. Repeat → **OK** → the cover saves
**Serious failure** it saves with no dialog; the dialog never appears (check
console — a JS error here is the class already fixed once); Cancel still saves

### 15 · Substitute on a normal day is unaffected

**Action** same screen, pick an ordinary teaching day
**Expect** no amber note, no dialog, save proceeds directly
**Serious failure** the confirmation appears on a normal teaching day — the gate
is firing when it should not

### 16 · Network and console sweep

**Action** having done all the above, review both tabs
**Expect** zero red console entries; `get_policy`, `preview`, `save_version` all
200; `save_version` 409 **only** in step 13
**Serious failure** any uncaught JS error; any 500; a request pending beyond ~10s
(note: a slow **first** calendar load is a known platform latency issue, not a
Calendar defect — see the PLATFORM-FIRESTORE-LATENCY handoff)

---

## If everything passes

That covers rendering and interaction. It does **not** cover multi-tenant
behaviour, long-run history, or production data volume — those need a second
tenant and real usage.
