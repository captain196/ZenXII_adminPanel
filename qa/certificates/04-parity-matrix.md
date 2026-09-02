# 04 · Cross-surface parity matrix — Document Engine (Certificates)

**Evidence ceiling: E2 (source-read).** Panel established directly; app rows
from A3 · MOBILE-SPEC with file:line citations.

Divergence priority order (v3 §A7): **authorization > business rules >
validation > data behaviour > synchronization > error handling > UX.**

## Capability matrix

| Capability | Admin panel | Teacher app | Parent app |
|---|---|---|---|
| Design / edit a template | **PRESENT** | ABSENT | ABSENT |
| Publish · activate · archive | **PRESENT** | ABSENT | ABSENT |
| Reads `documentTemplates` | **PRESENT** | **ABSENT** | **ABSENT** |
| Any document/certificate feature | design only | ABSENT | ABSENT |
| Issue a document to a student | **ABSENT — `CON-NO_PRINT_IMPL`** | ABSENT | ABSENT |
| View an issued document | ABSENT | ABSENT | ABSENT |
| Generic attachment open (Storage → `ACTION_VIEW`) | n/a | PRESENT `util/AttachmentUrlValidator.kt` | PRESENT `util/AttachmentUrlValidator.kt` |
| **Native PDF generation** | mPDF, server-side | ABSENT | **PRESENT — `util/ReceiptPdfGenerator.kt`** |
| Fee receipt view / download / share | *(A1 reporting)* | ABSENT (`FeesTeacherScreen.kt`: no receipt/pdf hits) | **PRESENT — `ui/fees/ReceiptDetailScreen.kt`, route `receipt_detail/{receiptId}`** |
| RBAC gate for documents | `Certificates` module key | **ABSENT** from `ModuleGate.kt:44-61` `ROUTE_MODULE` | n/a — Parent has no RBAC by design |
| Certificate-adjacent UX | — | "TC" only as a *student status* enum | Support Desk category `certificates` → "Certificates & Documents"; `FeeBlockedBanner.kt` references a not-yet-built TC screen |

## Divergences that matter

### D1 · **A second, independent receipt renderer already exists** — *business rules* — **P1**

The Parent app generates fee receipts **on the device** from
`FeeReceiptDoc`, using `android.graphics.pdf.PdfDocument`, with its own
hardcoded layout: school header, itemised breakdown, and a three-box
Cashier/Accountant/Principal signature row
(`ZenXII_Parent/.../util/ReceiptPdfGenerator.kt`, `ui/fees/ReceiptDetailScreen.kt:165–202`).

The print-point registry declares `fee_receipt` as a Document Engine type owned
by Accounts (`document_targets.php`). **If that is built without addressing
this, the same school will have two receipt documents that cannot agree**: one
designed, versioned and activated by an administrator; one compiled into an APK.
Changing the activated template would not change what a parent downloads, and
nobody would be told.

This is the free-oracle case in reverse: two implementations of one document, and
the divergence is invisible because each surface only ever shows its own.

**Consequence for the seam, recorded now rather than discovered later:** whoever
wires `fee_receipt` must first decide whether the on-device generator is
retired, or whether receipts are explicitly out of the Document Engine's scope.
It cannot be both.

### D2 · **The de-facto certificate request path today is a support ticket** — *UX / business rules* — **P2**

Parents ask for certificates through the Support Desk category `certificates`
("Certificates & Documents") — `SupportFirestoreRepository.kt:80,508`. That is a
manual, offline, human-handled route with no numbering, no register and no
audit link to any template. It is the process the Document Engine is meant to
replace, and it is live now. Any issuance design must account for the migration,
and for tickets already in flight.

### D3 · **No `Documents` RBAC module key exists in the Teacher app** — *authorization* — **P2**

`ModuleGate.kt:44-61` has no documents/certificates key, and the gate **fails
open** when capabilities have not loaded (`ModuleGate.kt:29,87,120,133`). Any
future staff-side document screen needs a key added here *and* in
`functions/rbac_modules.json`, or it is ungated in the UI. Firestore rules
remain the real boundary — but the panel uses the Admin SDK and bypasses them,
so this is UI-only either way.

### D4 · **Neither app declares the document collections** — *data* — **P3, informational**

`object Firestore` in both `util/Constants.kt` has no `documentTemplates` /
`issuedDocuments` entry. Per the repo contract, collection names live there and
not as string literals — so this is the first change either app would need.

## Parity conclusion

There is **no parity problem in the Document Engine today, because there is
nothing to compare**: the module exists on exactly one surface. The parity risk
is entirely prospective, and D1 is the one that is already real — a receipt
renderer that predates the engine and does not know it exists.
