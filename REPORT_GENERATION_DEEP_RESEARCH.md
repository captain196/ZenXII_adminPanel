# Report Generation — Deep Research

Full inventory of every report/document/export in the ZenX codebase, benchmarked against Edunext,
ZenoxERP, and **Fedena** (new in this pass), plus a library recommendation for premium report design.
Research only — findings feed the prototypes in `samples/reporting/`.

---

## 1. What your codebase actually generates today (full inventory)

Source-verified across every controller. **Three real engines exist**, but most "reports" in the
system are not files at all.

| Engine | Where used |
|---|---|
| **Dompdf** (wrapped by `application/libraries/Pdf_generator.php`) | Result.php report cards (**6 design templates** + batch-ZIP), Admission receipts + poster, Sis.php credential handout, Hr.php payslip. Accounting.php also calls raw `\Dompdf\Dompdf` directly (not the shared library — inconsistent). |
| **PhpSpreadsheet** | Accounting.php financial statements (XLSX), Hr.php payroll register/dept/payment reports, Staff.php import template. |
| **Custom OOXML writer** (`B2_xlsx_export.php`, dependency-free `ZipArchive`) | Superadmin cross-tenant analytics exports only. |
| **Browser-print-only** (`window.print()`, no server file) | Fee receipt, Transfer Certificate, Student ID card, **all 4 certificate types**, Teacher ID card, event circular, marks sheet, class result, cumulative result, tabulation, merit list. |
| **JSON-dashboard-only** (no file producible at all) | Fee ledger, defaulter report, cash book, bank statement, library/inventory/hostel/homework/audit-log "reports", **the entire Transport module**. |

### By module (existing reports, condensed)

| Module | Real PDF/Excel | Print-only / gap |
|---|---|---|
| **Fees** | Admission fee receipt + application receipt w/ QR (Dompdf) | Payment receipt, ledger, defaulter report — **screen/print only, no export** |
| **Accounting** | 5 statements × PDF + XLSX (Day Book/TB/P&L/BS/Cash Flow) | Cash book, bank statement — JSON only |
| **HR/Payroll** | Payslip (PDF), 3 payroll registers (XLSX) | Staff ID card — print only; no payroll-register PDF |
| **Exam/Result** | **6-template report card** PDF + batch-ZIP (best-built report in the app) | Class result, tabulation, merit list, marks sheet — all print-only |
| **SIS/Certificates** | Credential handout PDF | **TC, Student ID card, Bonafide/Transfer/Character/Custom certificates — ALL print-only**, despite Dompdf being one controller away |
| **Transport** | — | **Zero report/export code anywhere** (12 controllers, all JSON dashboards) |
| **Attendance** | — | Register/summary PDF button exists but is **hardcoded "coming soon"**; CSV is client-side JS Blob |
| **Superadmin** | 4 export types (CSV/XLSX) | — |

### Confirmed gaps (no generation code anywhere)
Bonafide/Transfer/Character certificate **PDFs**, **Exam Admit Card / Hall Ticket** (doesn't exist at
all), fee defaulter/ledger export, fee collection register, refund receipt document, staff attendance
register, staff ID card PDF, library/hostel/inventory registers, visitor/gate pass, audit-log export,
**all Transport reports**, attendance register PDF.

**The single most glaring gap:** `Certificates.php` already defines a **placeholder-token system**
(`{student_name}`, `{father_name}`, `{admission_number}`, `{issue_date}`, …) for Bonafide/Transfer/
Character/Custom certificates — but the output is still `window.print()`. Dompdf sits unused one
controller-family away. This is the highest-value, lowest-risk upgrade in the whole app.

---

## 2. How Edunext, ZenoxERP, and Fedena approach this

| | Edunext | ZenoxERP | **Fedena** (new) |
|---|---|---|---|
| Reports | 6 named reports (Enquiry/Registration/Selection/Documents/Fee/Vacancy) | management reports, auto receipts | reports across 20 core modules |
| **ID cards** | — | — | **Dedicated ID Card Generator**: template designer (front/back), background images, "**field codes**" for dynamic data, bulk or individual generation, 2 output sizes (A4 sheet or card-size) |
| **Certificates** | — | — | Built-in Bonafide/Transfer Certificate generation as a first-class module |
| **Report cards** | — | — | CBSE/ICSE/IB-aware; customization via **toggle fields** (show/hide Name, Photo, Remarks, etc.) rather than a full drag-drop designer |

**Fedena's "field codes" are the same idea as your `Certificates.php::PLACEHOLDERS`** — you've already
independently built the core mechanism competitors market as a feature. The gap isn't the templating
concept; it's that yours never reaches a downloadable PDF, and there's no equivalent for ID cards,
admit cards, or Transport.

---

## 3. Library research — what to add, and why

Confirmed via your `composer.json`: you already have **dompdf ^2.0**, **phpoffice/phpspreadsheet ^2.1**,
and — important find — **`chillerlan/php-qrcode` ^5.0** (a real, dependency-free QR generator, already
used for the admission-receipt QR). This means ID cards / admit cards can use a **genuine scannable QR**,
not a decorative placeholder.

| Library | Status | Verdict |
|---|---|---|
| dompdf | ✅ installed | keep as default; best for tabular/simple English docs |
| PhpSpreadsheet | ✅ installed | keep; extend to Fees/Attendance/Transport exports |
| **chillerlan/php-qrcode** | ✅ installed (underused) | reuse everywhere a QR is needed — admit cards, ID cards, certificates (verification QR) |
| mPDF | ❌ add | report cards/certificates in Hindi/regional scripts, official watermark + letterhead |
| Browsershot (headless Chrome) | ❌ add, selectively | ID cards, posters — pixel-perfect CSS |
| `picqer/php-barcode-generator` | ❌ optional add | ~0 deps, if you ever want linear barcodes (library cards, gate passes) alongside QR |
| **JasperReports** | investigated previously | **not recommended** — Java/JVM + SQL-centric, fights your PHP+Firestore stack (see prior analysis) |
| wkhtmltopdf / Laravel Snappy | investigated | **avoid** — binary is archived/unmaintained upstream; Browsershot is the modern replacement |

**No new "designer" tool needed.** Your placeholder-token pattern in `Certificates.php` **is** the
Fedena-style field-code system — the fix is plumbing (route it through Dompdf/`Pdf_generator`), not a
new framework.

---

## 4. What the new prototypes demonstrate

Given the gap analysis above, `samples/reporting/` now includes 4 new prototypes targeting the highest-value gaps, on top of the 3 already built (fee receipt, report card, ID card):

| # | Prototype | Gap closed | Engine |
|---|---|---|---|
| 05 | **Bonafide/Transfer Certificate** | print-only → real PDF; **uses the exact `{placeholder}` tokens already in `Certificates.php`** so it's a drop-in | dompdf |
| 06 | **Exam Admit Card / Hall Ticket** | did not exist at all | dompdf + **real chillerlan QR** |
| 07 | **Fee Defaulter Report** | screen-only grid → exportable PDF | dompdf (landscape) |
| 08 | **Transport Trip Manifest** | entire module has zero reports | PhpSpreadsheet |
| — | ID card (04) | decorative CSS "QR" → **real scannable QR** via chillerlan | Browsershot |

See `samples/reporting/README.md` for the full run instructions and module map.
