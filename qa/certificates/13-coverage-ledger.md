# 13 · Coverage ledger

**41 rows: T0 ×17 · T1 ×9 · T2 ×10 · T3 ×5.** All ship `NOT TESTED`.

Kept deliberately short. Exhaustive coverage produces rows nobody executes,
including the ones that mattered — and coverage that is not executed is zero.

## Covered

| Area | Rows | Depth |
|---|---|---|
| Tenant isolation / authorization | T0-08, 09, 10, 13, 15 | **Strong** — includes direct-POST bypass of the UI |
| The publish gate | T0-03, 04, T2-07 | **Strong** — stale proof, no proof, server-side re-validation |
| Activation invariant (I1) | T0-01, 02 | Good; the concurrent case is inherently hard to execute by hand |
| Persistence | T1-01, 02, T2-06, 08 | **Strong** — this is the capability that did not exist before this build |
| Fail-closed | T0-07, T2-04, 08 | Strong |
| Immutability | T0-05 | Adequate — one row, but the property is binary |
| Data exposure | T0-14 | One row, and it can only be run on the server |
| Upload safety | T2-01, 02 | Adequate |
| Multilingual rendering | T1-08 | **Thin** — one row for the only requirement automation cannot judge |
| Legacy collision | T0-16, 17 | Documents the baseline, does not certify it |
| UI/UX | T3-01…05 | Thin by design |

## NOT covered, and why

| Gap | Why | Mitigation |
|---|---|---|
| Both Android apps | The module does not exist there. One row (T3-05) records the baseline | Nothing to test |
| Issuance end to end | Does not exist — `CON-NO_PRINT_IMPL` | Design-time risks R4, R5 |
| Load / 10× | No seeded school of realistic size. T2-10 is a proxy | R11 open |
| Firestore atomicity under real contention | Cannot be produced reliably by hand. T0-02 is a best effort | R9 open; needs an emulator drill with a control |
| Restore drill | Needs a real test school (plan P9.5, blocked on B4) | Open |
| Every merge field | 30 fields; the contract is parity-tested in CI | Automation covers it better |

## Confidence

**Moderate, and asymmetric.** High on the panel's server-side logic, which is
heavily unit-tested and was read directly. **Low on everything runtime**: not one
row has been executed, and the online client↔server path has never run against a
signed-in session. Three security defects were found *after* the module was
called production-ready, all in code paths the unit tests replaced with doubles —
which is the honest measure of how much E2 is worth here.
