# Assets, signatures, and what else is possible

**Date:** 2026-08-18 · **Status:** DESIGN + working prototype. Nothing committed, nothing deployed.

The question was: does the designer support drag-and-drop of images and signatures, and what else
could it do? The honest answer to the first half was **no** — `image` was a dashed placeholder with
a label. Building it properly turned up four things worth more than the feature itself.

---

## 1 · There are two kinds of image, and one object type was hiding it

| | Static asset | Data-bound image |
|---|---|---|
| Examples | School crest, principal's signature, seal, watermark | Student photograph, verification QR |
| Uploaded | Once, per school | Never — resolved per document |
| Lives with | Reusable blocks | The merge-field contract |
| Same on every certificate? | Yes | No |

The prototype had one `image` type that could only be the first kind, so a bonafide certificate
carrying a student photograph was unrepresentable. Now an image object is either **static**
(an uploaded asset) or **bound** to an image-typed contract field, and the inspector makes you say
which. `student.photo` and `doc.verifyQr` are image-typed fields in the contract.

**A signature is static. A photograph is not.** They look identical in a layout and behave nothing
alike at issuance.

---

## 2 · Placement — the patterns, borrowed rather than invented

- **Drop on empty paper** → creates an image object at the drop point, sized to the file's own
  aspect ratio.
- **Drop onto an existing image** → **replaces it and keeps the box** (Figma's drop-to-replace).
  The whole desk highlights while a file is over it; the specific target object outlines separately.
- **Paste** an image from the clipboard — onto the selected image object if there is one, otherwise
  as a new object.
- **Double-click an empty placeholder** → file picker.
- Up to four files at once, each offset so they do not stack invisibly.

**The filename is a hint worth using.** A file called `principal-signature.png` is created as a
*signature*, `seal.png` as a *seal*. A clerk who has just scanned three files should not have to
classify each one by hand.

---

## 3 · Print resolution is a design-time property, and nothing was checking it

An image at 200 px placed 40 mm wide is **127 dpi**. It looks perfect on screen and soft on paper,
and nobody discovers it until the office has printed a stack of them.

```
effective dpi = pixelWidth ÷ (widthMm ÷ 25.4)
```

The Asset panel shows it live — *"76 dpi at 40 mm — soft in print"* with an amber dot, green above
150 — and it re-evaluates when the object is resized, because dpi is a function of the *printed*
size, not of the file. It is a **warning, not a blocker**: a decorative watermark at 90 dpi is fine
and the tool should not pretend otherwise.

---

## 4 · Transparency, and a collision worth knowing about

A signature or seal scanned as an opaque JPEG prints as **a white rectangle over the ruled line**.
So the prototype checks the alpha channel and, for a signature, says so plainly.

But this runs straight into the archival format question:

> mPDF can be configured for **PDF/A-1b** and **PDF/X-1a**, but its own manual says it *"is not
> guaranteed to produce fully compliant files in all circumstances"* and that it is the user's
> responsibility to verify. And PDF/A-1b, to guarantee reproducible appearance, permits
> **no transparent or semi-transparent objects at all**.

**So a transparent seal and a PDF/A-1b archive are mutually exclusive.** Either the seal composites
correctly over the ruled line, or the file is archival-compliant. That is a decision for whoever
owns the register, and it should be made deliberately rather than discovered. A workable middle
path is to flatten transparency at render time for the archived copy while keeping the alpha source
in Storage — but that is a design decision with a visible consequence, not a default.

*(Related: PDF/A-1**a** additionally requires tagged structure — accessibility. mPDF has no tagging,
so an accessible certificate PDF is not reachable with this renderer at all.)*

---

## 5 · What we refuse, and why

**SVG is rejected outright.** It is XML that can carry `<script>`, `on*` event handlers, XXE entity
references and `foreignObject`, and it is a well-documented stored-XSS vector when accepted into an
upload store. A certificate asset library — inside a school ERP, holding student records — is not
where you want to find out whether your sanitiser is complete. PNG, JPEG, WebP only. If SVG is ever
wanted, it must be sanitised **server-side** with a vetted SVG profile and stored only in its
sanitised form.

Also enforced: 8 MB ceiling; the type is checked by decoding the file, not by trusting its
extension; and the production rules the ADR already sets stand — **Storage refs, never URLs**
(mPDF fetches remote images server-side, which makes a URL field an SSRF primitive), the whitelist
lives in the renderer rather than the UI, and EXIF should be stripped on ingest — a scanned
signature can carry the GPS coordinates of wherever it was photographed.

---

## 6 · Signatures: the finding that matters most

A school will paste a scanned signature and believe the document is "digitally signed". It is not,
and the gap is legal, not cosmetic.

Under the **Information Technology Act 2000**:

- **s.3 — digital signature**: an asymmetric-cryptosystem signature, in practice a **DSC** issued
  after eKYC by a licensed Certifying Authority.
- **s.3A — electronic signature**: a signature satisfying the conditions in the **Second Schedule**,
  which is the route **Aadhaar eSign** / OTP+eKYC signing takes.
- **s.5**: where a law requires a signature, an electronic signature recognised under the Act is
  legally equivalent to a handwritten one.

**A raster image of a signature is neither.** It is a picture of a signature, with no s.3 or s.3A
status, and the inspector says exactly that whenever an object's kind is *signature*.

### 6.1 And the renderer cannot sign

mPDF — chosen by the blueprint specifically because it is the only pure-PHP renderer with an Indic
shaper — **has no digital-signature support**. The request has been open on the project since 2017,
labelled *feature/enhancement · help wanted*. TCPDF has `setSignature()`; TCPDF cannot shape Indic
scripts. **The two capabilities do not exist in the same PHP library**, and the blueprint's
renderer decision silently chose shaping over signing.

Three ways out, none free:

| Option | Shape | Cost |
|---|---|---|
| **Sign as a post-render step** | mPDF renders, a separate signer applies the PKCS#7 signature | Another dependency, and the private key has to live somewhere a web process can reach — the exact thing every guide warns against |
| **Aadhaar eSign via a licensed ASP/ESP** | The signed PDF comes back from the service | An integration, a contract, and per-signature cost — but the key never touches our infrastructure, which is the strongest argument for it |
| **Don't sign; verify instead** | The QR + HMAC verification endpoint the ADR already designs (§7) carries authenticity | Already planned. Verifies *our* copy is genuine; does not make the PDF self-proving offline |

**The third is what we already have, and the product copy must match it.** "Verify this certificate
at zenxii.com/verify/…" is true. "Digitally signed" would not be.

---

## 7 · What else is possible

Ranked by value to a school office, with an honest note on what each actually costs.

### Near — **built** (§9 for how each behaves)

| Capability | Why it matters | State |
|---|---|---|
| **"DUPLICATE" mark** | **Legally required**, not decorative: KER r.22 *"should be clearly marked 'Duplicate'"*; TNER r.44 *"in red ink… issued only once"*; CBSE r.8(vi) *"it shall always be so marked"* | **Done** — blocking if absent, colour-checked where the statute names one |
| **Draw-a-signature** | A principal without a scanner signs with a trackpad; the output is transparent **by construction**, which sidesteps §4 entirely | **Done** — auto-cropped to the ink |
| **Student photograph** | Bonafide and several state certificates carry one | **Done** — image-typed contract field |
| **Signature order validation** | CBSE prescribes Class Teacher → Checked by → Principal | **Done** — missing role blocks, wrong order warns |
| **Asset library per school** | Upload the crest once, reuse across every type | Open — blocks exist; assets are not yet block members (S4) |

### Mid — real work, clear payoff

| Capability | Why | Cost |
|---|---|---|
| **Pre-printed stationery mode** | v1.1 already: tracing background, chrome suppression, calibration offset | Deferred deliberately; the mm system it needs is built |
| **Bulk issue as one merged PDF** | 800 report cards is one print job, not 800 | Needs the range-allocated numbering the ADR flags as must-ship-first |
| **Stationery serial capture** | CBSE TCs come in pre-printed books with Book No. / Sl. No. — a second numbering axis that must reconcile with ours | ADR §9A.1 |
| **PDF/A-1b archival copy** | A statutory register arguably wants one | §4's transparency collision must be resolved first |

### Far — worth knowing, not worth assuming

| Capability | Status |
|---|---|
| **DigiLocker issuance** | The ADR records the delivery mechanism as **unverified** and three related claims as *refuted*. Do not design against it until someone reads the ASP onboarding spec |
| **DSC / eSign signing** | §6.1 — an integration decision, not a feature toggle |
| **Tagged / accessible PDF** | Not reachable with mPDF |
| **OCR an existing certificate to bootstrap a template** | Attractive demo, weak payoff: the hard part is binding fields to a contract, which OCR does not do |

---

## 8 · Open

| # | Question | Blocks |
|---|---|---|
| S1 | Is a PDF/A archival copy required for the register? If yes, transparency must be flattened at render and seals will composite differently | §4 |
| S2 | DSC, eSign, or verification-only? This is a business decision with a per-signature cost, and it determines what the product may claim | §6 |
| S3 | Does any board or state prescribe a **minimum print resolution** or a signature size? Nothing found; our 150 dpi threshold is Level D — ours | §3 |
| S4 | Should assets be members of reusable blocks, so a letterhead carries its crest? | §7 |
| S5 | EXIF stripping and server-side type sniffing are specified here but belong in the ingest pipeline, which does not exist yet | §5 |


---

## 9 · The three built after this research

### 9.1 Duplicate marking — a statute that specifies rendering

Three authorities require a reissue to be **marked**, and Tamil Nadu specifies the **ink colour**:

| Authority | Requirement |
|---|---|
| KER r.22 | *"Duplicate certificate issued should be clearly marked 'Duplicate'."* |
| TNER r.44 | *"…shall clearly bear the mark duplicate in red ink. It shall be issued only once."* |
| CBSE r.8(vi) | *"…it shall always be so marked."* |

This is the only place in the whole corpus where a statute reaches into how the document is
**drawn**, so it is enforced rather than suggested:

- An authority's docType rule may declare `duplicateMark: {required, text, colour?, onceOnly?}`.
- The mark itself is an ordinary object with `showWhen: "doc.isDuplicate"` — the conditional
  visibility mechanism already built, driven by an issuance flag rather than merge data.
- **No mark on the template ⇒ blocking**, with the citation and a one-click fix that inserts one
  in the colour the applicable statute prescribes.
- Where a statute names a colour, a mark in the wrong colour **warns**, citing the rule. The check
  is a real red test on the hex value, not a string comparison, so `#C0392B` passes and `#14100D`
  does not.
- A status-bar toggle previews the document **as an original or as a duplicate**, so the mark can
  be positioned while visible — otherwise it is invisible chrome nobody ever checks.

`onceOnly` is recorded on the TN rule and not yet enforced: *"issued only once"* is a constraint on
**issuance**, and this module does not issue. It belongs to the Document Engine, and the flag is
carried so that engine inherits it rather than rediscovering it.

### 9.2 Draw a signature

A canvas pad (pointer events, so trackpad, mouse and touch all work) that auto-crops to the ink and
emits a transparent PNG. Verified: a drawn stroke on a 1600×500 pad crops to 1129×149 with
`hasAlpha: true` and 637 dpi at its placed size, and the alpha warning does not fire.

This is the cleanest answer to §4 — the transparency problem disappears when the asset is drawn
rather than scanned. The modal still carries the IT Act note, because a drawn signature is exactly
as much of a legal signature as a scanned one: none.

### 9.3 Signature presence and order

`requiredSignatures` was already an **ordered** list on each profile, and nothing read it. Now:

- A prescribed role with no signature block in the template is **blocking** — *"Signature block
  missing — checked by"*.
- Blocks laid out in the wrong sequence **warn**, showing both: *"laid out principal → checked by →
  class teacher; prescribed class teacher → checked by → principal"*. Order is read top-to-bottom
  then left-to-right, which is how a page is read.

Warning rather than blocking, deliberately: the CBSE order is prescribed for the *form*, and we have
not verified that a horizontal arrangement in a different left-to-right sequence is itself a defect.
Overstating that would be exactly the Level-D-inheriting-a-Level-A-citation error the corpus warns
about.
