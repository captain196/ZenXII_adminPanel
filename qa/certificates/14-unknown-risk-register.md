# 14 · Unknown-risk register

What we know we do not know. Recorded so it is not mistaken for absence of risk.

| ID | Unknown | Why it is unknown | If it goes wrong |
|---|---|---|---|
| **U1** | **Does `uploads/.htaccess` actually take effect in production?** | Depends on Apache `AllowOverride` in the Ohio vhost, which is not in git. If it is `None`, the file is inert and R2 is still open | Proof PDFs — letterhead, crest, signatures — remain downloadable with no login |
| **U2** | **Does the online path work at all?** | No row has been executed; there has never been a signed-in run | Anything from "it works" to "nothing saves". Unknown either way |
| **U3** | **Firestore atomicity under real contention** | `:commit` is documented as atomic; not observed here | Two active templates, or none |
| **U4** | **mPDF memory and time on the Ohio box** | The box has OOM history. Locally a proof peaks around 84 MB for ONE document; `fee_demand_note` is bulk by nature | A proof that works in dev OOMs in production, or takes the request down |
| **U5** | **Whether Indic output is READABLE, not merely present** | Automation proves ink on the page and the right embedded font. Only a reader of the script can judge conjuncts and matras | A certificate is issued in a language nobody checked |
| **U6** | **Assets remain web-served** | Accepted: names are sha256, so unguessable — but unguessable is not private, and a URL once shared is permanent | A signature graphic leaks via a shared link |
| **U7** | **Does any other session or teammate hold work in these files?** | The repo is worked concurrently; `firestore.rules` especially | A deploy ships someone's half-finished work |
| **U8** | **Whether `Doc_block_service` has other unowned write paths** | Only `save()` was audited and guarded; `acceptOffer`/`declineOffer` were not traced for ownership | The same class of cross-tenant write as R1, in the block library |

## The honest summary

Three P0/P1 security defects were found in code that had already been described
as production-ready, by reading the paths that unit tests replace with doubles.
**U8 is the direct admission that the same audit has not been completed for the
block service.** The right conclusion from R1–R3 is not "the module is now
secure" — it is that the review that found them is not finished.
