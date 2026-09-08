# 16 — DEPLOY RUNBOOK · Student AI Assistant

Everything here is a human step. The deploy is blocked in the assistant's session by permission
policy, and the two console actions cannot be done from a CLI at all.

Read §17 and §18 of `15-certification-report.md` first if you want to know *why* each of these
matters. This file is the sequence.

---

## 0 · Before you start — what is live vs. what is ready

Production currently runs the function as of commit `5414f14` (2026-08-31). **Everything found and
fixed since then is committed but NOT deployed.** That includes, live in production right now:

| | Live defect |
|---|---|
| **HW-1** | A student is told *"you have no current pending homework"* while an overdue assignment sits on their own dashboard |
| **TZ-1** | Between 00:00 and 05:30 IST, every day, the assistant reports **yesterday** — and serves yesterday's timetable |
| **B-1** | `get_attendance_summary` has no session filter, so a previous session's record can be narrated as current |
| **B-3** | Quota is charged before validation, so a rejected message still costs a unit with no refund |
| **B-4** | A generation truncated at the token cap is returned as a finished answer and logged `ok: true` |
| **B-2** | Failures refund themselves, so the daily cap provides no backpressure during an outage |
| Category | The model can emit `other`, which `firestore.rules` rejects at write time |

---

## 1 · Deploy the Cloud Function

```bash
cd ~/Desktop/Zennxii_adminPanel/firebase-rules
firebase deploy --only functions:studentAssistant --project graderadmin
```

**Scoped to one function on purpose.** `--only functions` redeploys all 20 in that directory; there
is no reason to widen the blast radius for a single-file change.

**Before you run it**, confirm the disk is what you think it is — `firebase deploy` ships from disk,
not from git, and this checkout is shared with other workstreams:

```bash
cd ~/Desktop/Zennxii_adminPanel
git status --porcelain functions/          # must be empty
grep -c "DAILY_ATTEMPTS\|homeworkState\|IST_OFFSET_MS" functions/studentAssistant.js   # must be ≥ 3
```

If `functions/` is dirty, someone else's uncommitted work is about to ship. Stop and find out whose.

**Rollback**, if the deploy makes things worse:

```bash
cd ~/Desktop/Zennxii_adminPanel
git stash list                             # make sure you are not sitting on someone's work
git checkout 5414f14 -- functions/studentAssistant.js
cd firebase-rules && firebase deploy --only functions:studentAssistant --project graderadmin
git checkout HEAD -- ../functions/studentAssistant.js    # put the tree back
```

---

## 2 · Verify the deploy — do not skip this

Two defects are verifiable in under two minutes on a device, and both were *found* this way rather
than by reading logs.

**HW-1.** Open the Parent app → Categories → AI सहायक → ask *"what homework is pending for me"*.
Compare against the dashboard's own counter and the Homework screen.
**Pass:** the reply names the overdue item. **Fail:** it says nothing is pending while the dashboard
shows a count. That is exactly the bug.

**TZ-1.** Ask *"what is my timetable today"* and check the date it states against the phone's clock.
Most easily proven between 00:00 and 05:30 IST, when the old build says yesterday.
**Pass:** the date and weekday match the phone.

If either fails, the deploy did not take. Check `firebase functions:log --only studentAssistant`
for a new instance start.

---

## 3 · Create the `assistantLogs` TTL policy — console, ~2 minutes

**This has never been done.** `expiresAt` has been written on every row for weeks and is deleting
nothing, because the field alone does not expire anything — Firestore needs a policy on the field.

Firestore → **TTL** → Create policy
- Collection group: `assistantLogs`
- Timestamp field: `expiresAt`

Until this exists, children's question text is retained indefinitely with no subject-access path and
no defensible basis. It is the cheapest open compliance item by a wide margin.

---

## 4 · Pilot review — only when you start the pilot

Fabrication is the one risk in this module with no automated oracle: a plausible, confident, wrong
answer looks exactly like a correct one. Reviewing it needs the replies, and the audit log
deliberately does not store them.

To turn it on for one school:

```
schools/{schoolId}.ai_assistant_pilot_review = true
```

Off by default and per school, because storing a child's answers for ninety days needs a reason.
When the pilot ends, **clear the flag** — the existing `expiresAt` then removes what was already
written on its own schedule. Requires §3 to be done first, or nothing expires at all.

---

## 5 · The Parent app

The app changes are committed and pushed to `main` (`e592031`), and reach nobody until a Play
release. That is a separate track with its own open item: the Data Safety declaration flagged in the
2026-08-23 audit is still wrong.

Until that release ships, the deployed function is talking to the **old client**, which is safe —
the server fixes are all server-side behaviour — but the UI fixes (hanging indent, copy, the
category prefill, the accessibility work) are not in users' hands.

---

## 6 · Not this workstream's, but blocking someone

**SUP-1.** The Parent app offers a support category chip `other`; `firestore.rules` allows nine
values and `other` is not among them. **Any parent who picks Other and taps Send gets
`PERMISSION_DENIED`** — after typing their whole complaint. The rules comment claims the list
matches the app's `categoryLabel()` "exactly", and
`tests/support_desk_input_bounds.test.js` asserts "allowlist of 9" without ever probing `other`, so
the test agrees with the bug.

Owner: Support Desk. Not fixed here — `firestore.rules` is shared, needs the rules protocol, and
this workstream did not touch it.

---

## 7 · Still open, and honestly so

- **Vertex Cloud terms for under-18 use**, and the **DPDP consent posture**. The provider choice
  rests on a clause being *absent* from Google Cloud's terms. If that reading is wrong this is a
  rewrite, not a fix — which is why it should not wait for the engineering to finish.
- **Telugu and Tamil register.** Specific findings recorded with string names and line numbers, left
  for a native reviewer. Editing a translation on an agent's say-so in a language you cannot read is
  how you make it worse.
- **Login / ForceChangePassword keyboard behaviour.** Five of seven screens verified on device; these
  two need a throwaway account, because reaching them means logging out of a live parent account.
- **Billing recurrence.** The Sep 3 outage was a UPI QR auto-charge declining for insufficient funds
  with no backup method. ₹476 of credit defers it; the push-only recurring method is unchanged, so
  it will happen again.
