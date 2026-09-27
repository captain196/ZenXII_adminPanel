# Reporting prototypes — one template, the right engine per report

Runnable prototypes for each reporting option, mapped to **your** ERP modules. The pattern throughout:
**you design the report once in an HTML/CSS template** (`templates/*.php`) and hand it to whichever
engine fits — that is the "designer experience." Only the *engine call* differs.

```
samples/reporting/
  _bootstrap.php                        autoload + sample data + helpers
  templates/
    fee_receipt.php                     ← edit HTML/CSS here, not PHP  (the "designer" layer)
    report_card.php                     ← Hindi/Devanagari report card
    id_card.php                         ← pixel-perfect ID card
  01_dompdf_fee_receipt.php             RUNS TODAY  (dompdf installed)
  02_phpspreadsheet_fee_defaulters.php  RUNS TODAY  (phpspreadsheet installed)
  03_mpdf_report_card.php               needs: composer require mpdf/mpdf
  04_chrome_id_card.php                 needs: composer require spatie/browsershot + npm i -g puppeteer
  out/                                  generated PDFs / XLSX / PNG
```

Run from the repo root:
```bash
php samples/reporting/01_dompdf_fee_receipt.php            # → out/fee_receipt_dompdf.pdf   ✅ verified
php samples/reporting/02_phpspreadsheet_fee_defaulters.php # → out/fee_defaulters.xlsx        ✅ verified
composer require mpdf/mpdf && php samples/reporting/03_mpdf_report_card.php
composer require spatie/browsershot && php samples/reporting/04_chrome_id_card.php
```

---

## What you already have vs. what to add

| Engine | Installed? | Verdict |
|---|---|---|
| **dompdf** ^2.0 | ✅ yes (used in Accounting) | keep as the default PDF engine |
| **PhpSpreadsheet** ^2.1 | ✅ yes (used in Accounting/HR/SIS/Staff) | **you are NOT missing Excel** — just extend it to Fees/Attendance reports |
| **mPDF** | ❌ no | add for report cards / certificates (Devanagari + official docs) |
| **Browsershot (headless Chrome)** | ❌ no | add ONLY for ID cards / posters / dashboard snapshots (fidelity-critical) |

---

## Module → report → engine map (tailored to your ERP)

| Module | Report | Engine | Why |
|---|---|---|---|
| **Fees** | Fee receipt, payment voucher | **dompdf** | simple, English, already your pattern |
| **Fees** | Defaulters, collection register, day-wise collection | **PhpSpreadsheet** | office needs to sort/filter/pivot; totals |
| **Accounting** | Day book, cash flow, ledger, trial balance | **dompdf** (PDF) / **PhpSpreadsheet** (Excel) | already done in `Accounting.php` — reuse |
| **Exam / Result** | **Report card, marksheet, consolidated result** | **mPDF** ⭐ | Hindi/Devanagari, complex tables, watermark, letterhead |
| **Certificates** | TC, Bonafide, character cert, **offer/admission letter** | **mPDF** ⭐ | official Unicode docs, page header/footer, `@page` |
| **SIS** | **Student ID card** (+ staff ID) | **headless Chrome** ⭐ | gradients, rounded photo, web fonts, QR — pixel-perfect |
| **SIS / HR** | Student register, staff directory, admission register | **PhpSpreadsheet** | bulk tabular export |
| **HR / Payroll** | Payslip | **dompdf** (or **mPDF** if multi-language) | tabular; mPDF if regional names/scripts |
| **HR / Payroll** | Salary register, PF/ESI/bank-advice sheets | **PhpSpreadsheet** | Excel is the expected format |
| **Attendance** | Monthly register, defaulter list | **PhpSpreadsheet** (grid) / **dompdf** (printout) | grid = Excel; printout = PDF |
| **Admission** | Fee receipt / ack (have) | **dompdf** | already built |
| **Admission** | "Scan to Apply" poster | **dompdf** (have) → **Chrome** if you want it marketing-grade | fidelity |
| **Transport** | Route sheet, trip manifest, vehicle log | **dompdf** (print) / **PhpSpreadsheet** (data) | mixed |
| **Analytics** | Dashboard / chart export to PDF-PNG | **headless Chrome** ⭐ | charts must look exactly like the browser |

⭐ = where **designer experience matters most** (brand/layout/script-sensitive, parent- or govt-facing).

---

## Where you need the most "designer experience"

Rank order of design investment (and therefore where HTML/CSS templates + the right engine pay off):

1. **Report cards / marksheets** (Exam) — parents keep these; must be branded, bilingual, print-clean → **mPDF**.
2. **Certificates & offer/admission letters** (Certificates/Admission) — official, letterhead, signatures, watermark → **mPDF**.
3. **ID cards** (SIS) — the single most design-heavy artifact (photo, gradient, QR, exact card size) → **Chrome**.
4. **Fee receipts / payslips** — professional but simple; your dompdf template is enough → **dompdf**.
5. **Registers / defaulters / payroll sheets** — function over form; style lightly in Excel → **PhpSpreadsheet**.

**Design once, render anywhere:** `templates/fee_receipt.php` feeds dompdf here, but the *same* file
works in mPDF or Chrome unchanged. So your team edits HTML/CSS (or a Blade-like partial in CI3 views)
and you pick the engine per report — no engine lock-in, no learning a proprietary designer like Jasper.

---

## Engine trade-offs at a glance

| | dompdf | mPDF | headless Chrome | PhpSpreadsheet |
|---|---|---|---|---|
| Output | PDF | PDF | PDF + PNG | XLSX/CSV |
| Runtime deps | none (pure PHP) | none (pure PHP) | **Node + Chromium** | none (pure PHP) |
| Devanagari/Indic | weak | **excellent** | excellent | n/a |
| CSS fidelity (flex/grid/gradient) | low | medium | **browser-perfect** | n/a |
| Speed | fast | fast | ~0.5–1s + boot | fast |
| Install weight | (have) | ~40MB | ~150MB Chromium | (have) |
| Best for | receipts, ledgers | report cards, certs | ID cards, posters, dashboards | registers, exports |

> On your memory-tight Lightsail box, prefer the pure-PHP engines (dompdf/mPDF/PhpSpreadsheet) for
> everyday reports and reserve Chrome for the few fidelity-critical artifacts (ID cards, poster,
> analytics snapshots). This keeps 90%+ of reports JVM/Node-free — the opposite of adopting Jasper.

*Prototypes are self-contained samples with dummy data. To productionize: move a template into your
CI3 `views/`, feed it your Firestore data in the controller, and `stream()`/`Output()` to the browser
(each prototype's footer shows the one-line controller variant).*
