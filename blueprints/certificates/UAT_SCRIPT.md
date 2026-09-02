# Certificate Designer — UAT script

**Status:** ready to run · **Blocks:** P9.7 (sign-off by the P1 clerk and P2 principal personas)
**Updated:** 2026-09-02

Everything below is a thing a person does and a thing they should see. Nothing
here can be run by an agent: P9.7 exists precisely because a module can pass
every automated check and still be unusable by the person it was built for.

## Before you start

| | |
|---|---|
| Where | `localhost:8080/doc_templates` — **not** production |
| Who | one account with `Certificates: manage`, one with `Certificates: view` only |
| Data | a school with at least one student record; no live certificate must depend on this school |
| Watch | the browser console. **Zero errors is part of passing.** |

> ⚠️ **If the console says `[zxdt] no boot payload — running OFFLINE`, stop.**
> The designer is on its built-in fixtures and NOTHING you do is being saved.
> That message exists because a designer that silently stopped saving looks
> exactly like one that works.

---

## A · Authoring (persona P1, the clerk)

| # | Do this | It passes if |
|---|---|---|
| A1 | Open Certificates. | Your school's real templates are listed — not "TC — main letterhead" or "Bonafide — classic", which are the built-in fixtures. Seeing those means A0 failed. |
| A2 | Open a Transfer Certificate starter. | The designer opens and the breadcrumb names your school. |
| A3 | Type into a field, then **reload the page after 3 seconds**. | Your text is still there. This is the single most important check in the script: before this build, it never was. |
| A4 | Type, then close the tab **immediately**. | The browser asks whether to leave. |
| A5 | Drag a text block. Undo. Redo. | Position returns, then re-applies, once per gesture — not per pixel. |
| A6 | Upload a school crest. | It appears. Re-upload the same file — it does not duplicate. |
| A7 | Upload a `.txt` renamed to `.png`. | **Refused**, naming the real type. If it is accepted, stop the UAT and report it. |
| A8 | Switch to हिन्दी. | Devanagari renders as joined conjuncts, not boxes or broken matras. |

## B · The proof gate (P1)

| # | Do this | It passes if |
|---|---|---|
| B1 | Try to publish before proofing. | Publish is **disabled**, and says why. |
| B2 | Render a proof. | It reports real page counts and a hash. The hash is not `sha256:` followed by obvious noise. |
| B3 | Move a block **after** proofing, then publish. | **Refused** — "the design changed after the proof was rendered". |
| B4 | Re-proof, then publish. | Succeeds, and offers activation as a separate step. |
| B5 | Start a proof and click Render again immediately. | The second click does nothing — the button is disabled while it runs. |
| B6 | Disconnect the network, then render a proof. | It says it failed. Publish stays **blocked**. It must not appear to succeed. |

## C · Publish and activate (persona P2, the principal)

| # | Do this | It passes if |
|---|---|---|
| C1 | Publish. Read the dialog. | It is clear that publishing has **not** changed what prints. |
| C2 | Decline activation. | Nothing about which template is active has changed. |
| C3 | Activate. | Exactly one template shows as active for that document type. |
| C4 | Activate a *different* template of the same type. | The first stops being active in the same action. Never two. Never zero. |
| C5 | Open version history. | Every published version is listed with its hash, font manifest and mPDF version. |

## D · Permissions (the `view`-only account)

| # | Do this | It passes if |
|---|---|---|
| D1 | Open Certificates. | You can look. |
| D2 | Try to edit, publish or activate. | Refused — and the refusal **says so**. A silent no-op is a failure here. |
| D3 | POST to `/doc_templates/activate` from the console. | Refused by the server, not only by the hidden button. |

## E · Two people at once (needs two browsers)

| # | Do this | It passes if |
|---|---|---|
| E1 | Open the same template as two users. Both edit. Both wait for autosave. | The second is told someone else saved it. **Neither person's work is silently overwritten.** |
| E2 | Take E1's "reload their version". | You see their template; you were warned first that your unsaved changes go. |

---

## Recording the result

For each row: **pass**, **fail**, or **not run** — and for a fail, what you saw,
not what you concluded. "B3 published anyway" is worth more than "proof gate
broken".

A single fail in **B3, B6, C4, D2, D3 or E1** blocks the module. Those are the
rows where the failure is silent and the consequence is a school issuing a
certificate it did not approve.
