# Is what we issue *signed*? — IT Act ss.3/3A/5, DSC, and Aadhaar e-Sign

Companion to `electronic-evidence-s63.md`. That file asked whether a document we render is
**admissible**. This one asks the prior question: whether it is **signed** at all, and what it would
cost to make it so.

**Method.** WebSearch was unavailable for this session. Everything below was retrieved by direct
fetch of `indiankanoon.org` (bare Act + judgments) and `cca.gov.in` (the Controller of Certifying
Authorities, MeitY), including the gazette PDFs the CCA itself hosts. Failures are recorded in §9.

**Grades.** **[A]** primary (statute text, gazette notification, judgment) · **[B]** official
government site, not the instrument itself · **[C]** secondary · **[D]** my inference ·
**NOT FOUND**.

---

## 0 · The answer in four lines

1. **A typed name plus a rendered seal image is not a signature in law, and is not an electronic
   signature under the IT Act.** It is neither s.3 (no asymmetric crypto, no key pair) nor s.3A (not
   a technique in the Second Schedule). **[D from A]**
2. **It is also not, by itself, *invalid*.** Nothing in the IT Act forbids it. The question is
   whether the *governing school rule* demands a signature, and what happens when a relying party
   refuses to accept the artefact.
3. **"Signed by the Head of the school himself" is about *who*, not about *ink*.** The Supreme Court
   line of authority treats the word *"himself"* as excluding signature-by-agent — and the Allahabad
   High Court has held a statutory "shall be signed" is satisfied "**whether physically or
   digitally**." §6.
4. **The cheapest compliant option is a Class 3 individual DSC in the Head's own name**, used to
   sign the rendered PDF. **A Document Signer Certificate issued to the school is explicitly *not*
   a substitute** — the CCA says so in terms. §3.

---

## 1 · ss.3, 3A and 5 — verbatim

Source: *The Information Technology Act, 2000* as hosted at `indiankanoon.org/doc/1965344/`
(bare Act, consolidated). **[A]**

### 1.1 · The two definitions that do the work

> **s.2(1)(p)** *"digital signature" means authentication of any electronic record by a subscriber by
> means of an electronic method or procedure in accordance with the provisions of section 3;*

> **s.2(1)(ta)** *"electronic signature" means authentication of any electronic record by a
> subscriber by means of the electronic technique specified in the Second Schedule and includes
> digital signature;*

> **s.2(1)(d)** *"affixing electronic signature", with its grammatical variations and cognate
> expressions means adoption of any methodology or procedure by a person for the purpose of
> authenticating an electronic record by means of electronic signature;*

> **s.2(1)(zg)** *"subscriber" means a person in whose name the Electronic Signature Certificate is
> issued;*

**Read those together and the architecture is closed.** "Electronic signature" is not an open
category of "anything that looks like assent". It is *exclusively* (a) a digital signature under
s.3, or (b) a technique **listed in the Second Schedule**. There are presently **two** Second
Schedule entries (§2), and a typed name in a PDF is neither.

### 1.2 · s.3 — digital signature (asymmetric crypto + hash)

> **3. Authentication of electronic records.—**
> **(1)** *Subject to the provisions of this section, any subscriber may authenticate an electronic
> record by affixing his digital signature.*
>
> **(2)** *The authentication of the electronic record shall be effected by the use of asymmetric
> crypto system and hash function which envelop and transform the initial electronic record into
> another electronic record.*
>
> ***Explanation.—** For the purposes of this sub-section, "hash function" means an algorithm mapping
> or translation of one sequence of bits into another, generally smaller, set known as "hash result"
> such that an electronic record yields the same hash result every time the algorithm is executed
> with the same electronic record as its input making it computationally infeasible—*
> *(a) to derive or reconstruct the original electronic record from the hash result produced by the
> algorithm;*
> *(b) that two electronic records can produce the same hash result using the algorithm.*
>
> **(3)** *Any person by the use of a public key of the subscriber can verify the electronic record.*
>
> **(4)** *The private key and the public key are unique to the subscriber and constitute a
> functioning key pair.* **[A]**

**Note the overlap with `electronic-evidence-s63.md`.** The s.3 Explanation's definition of a hash
function is almost word-for-word the property the BSA s.63 Schedule certificate wants recorded
("hash value and algorithm"). **The same primitive serves both regimes** — and the module already
computes `'sha256:' . hash('sha256', $pdf)`. But s.3 needs the hash *inside a key-pair operation*;
storing a digest in Firestore is not signing.

### 1.3 · s.3A — electronic signature (the Second Schedule route)

> **3A. Electronic signature.—**
> **(1)** *Notwithstanding anything contained in section 3, but subject to the provisions of
> sub-section (2), a subscriber may authenticate any electronic record by such electronic signature
> or electronic authentication technique which—*
> *(a) is considered reliable; and*
> *(b) may be specified in the Second Schedule.*
>
> **(2)** *For the purposes of this section any electronic signature or electronic authentication
> technique shall be considered reliable if—*
> *(a) the signature creation data or the authentication data are, within the context in which they
> are used, linked to the signatory or, as the case may be, the authenticator and to no other
> person;*
> *(b) the signature creation data or the authentication data were, at the time of signing, under the
> control of the signatory or, as the case may be, the authenticator and of no other person;*
> *(c) any alteration to the electronic signature made after affixing such signature is detectable;*
> *(d) any alteration to the information made after its authentication by electronic signature is
> detectable; and*
> *(e) it fulfils such other conditions which may be prescribed.*
>
> **(3)** *The Central Government may prescribe the procedure for the purpose of ascertaining whether
> electronic signature is that of the person by whom it is purported to have been affixed or
> authenticated.*
>
> **(4)** *The Central Government may, by notification in the Official Gazette, add to or omit any
> electronic signature or electronic authentication technique and the procedure for affixing such
> signature from the Second Schedule: Provided that no electronic signature or authentication
> technique shall be specified in the Second Schedule unless such signature or technique is
> reliable.*
>
> **(5)** *Every notification issued under sub-section (4) shall be laid before each House of
> Parliament.* **[A]**

**s.3A(2)(a) and (b) are the death of the current artefact even as an argument.** A typed name is
not "linked to the signatory and to no other person" and there is no "signature creation data under
the control of the signatory". Any clerk with panel access produces a byte-identical document.

**s.3A(2)(c)/(d) is the death of the seal image.** Nothing about a rendered PNG makes post-hoc
alteration detectable.

### 1.4 · s.5 — the deeming provision, and its Explanation

This is the pivotal section for §6.

> **5. Legal recognition of electronic signature.—**
> *Where any law provides that information or any other matter shall be authenticated by affixing the
> signature or any document shall be signed or bear the signature of any person, then,
> **notwithstanding anything contained in such law**, such requirement shall be deemed to have been
> satisfied, if such information or matter is authenticated by means of electronic signature affixed
> in such manner as may be prescribed by the Central Government.*
>
> ***Explanation.—** For the purposes of this section, "signed", with its grammatical variations and
> cognate expressions, shall, with reference to a person, mean affixing of his hand written signature
> or any mark on any document and the expression "signature" shall be construed accordingly.*
> **[A]**

**Three things follow, and they matter individually.**

1. **The Explanation confirms the baseline is ink.** In the absence of s.5, "signed" in a school rule
   means *"affixing of his hand written signature or any mark"*. A typed name is neither a
   handwritten signature nor a mark affixed by the person.
2. **"Notwithstanding anything contained in such law" is the override.** s.5 does not politely
   supplement a signature rule; it displaces it. A State education rule that says "signed" cannot, on
   its own wording, defeat s.5.
3. **But s.5 only fires for a real electronic signature** — *"affixed in such manner as may be
   prescribed by the Central Government."* It does nothing for a typed name. **s.5 is the bridge we
   are not currently standing on.**

**Partial gap — the prescribing rules.** s.5's "in such manner as may be prescribed" points at
Central Government rules (the CCA gazette index lists *IT (Use of Electronic Records and Digital
Signatures) Rules, 2004*, G.S.R. 582(E), and *Digital Signature (End entity) Rules, 2015*,
G.S.R. 660(E)). **I could not read G.S.R. 582(E): the CCA's hosted PDF is an image-only scan with no
text layer** (§9). So the precise prescribed *manner* is **NOT ESTABLISHED** here; that it exists,
and where, is [B].

### 1.5 · Two neighbours that change the shape of the answer

> **s.4. Legal recognition of electronic records.—** *Where any law provides that information or any
> other matter shall be in writing or in the typewritten or printed form, then, notwithstanding
> anything contained in such law, such requirement shall be deemed to have been satisfied if such
> information or matter is— (a) rendered or made available in an electronic form; and (b) accessible
> so as to be usable for a subsequent reference.* **[A]**

> **s.15. Secure electronic signature.—** *An electronic signature shall be deemed to be a secure
> electronic signature if— (i) the signature creation data, at the time of affixing signature, was
> under the exclusive control of signatory and no other person; and (ii) the signature creation data
> was stored and affixed in such exclusive manner as may be prescribed.* ***Explanation.—** In case of
> digital signature, the "signature creation data" means the private key of the subscriber.* **[A]**

**s.15 is the one to design against, not s.3A.** "Secure electronic signature" is the higher tier,
and the difference is *exclusive control of the private key*. An ERP that holds a key and signs on
the Principal's behalf **fails s.15(i) by construction** — the key is not under the signatory's
exclusive control. That single sentence rules out the architecture most convenient for us.

> **s.9. Sections 6, 7 and 8 not to confer right to insist document should be accepted in electronic
> form.—** *Nothing contained in sections 6, 7 and 8 shall confer a right upon any person to insist
> that any Ministry or Department of the Central Government or the State Government or any authority
> or body established by or under any law or controlled or funded by the Central or State Government
> should accept, issue, create, retain and preserve any document in the form of electronic records or
> effect any monetary transaction in the electronic form.* **[A]**

**s.9 is the statutory sibling of conflict C-66.** The Orissa HC in *Hritika Mitra* refused to make a
university accept a DigiLocker School Leaving Certificate. s.9 is why that is not an outlier: the
IT Act deliberately declines to create a right to be accepted electronically. **Making our PDF
validly signed does not make a receiving institution take it.** Those are different problems and
only one of them is solvable in code.

### 1.6 · s.10A — contracts, and why it does not reach a certificate

> **10A. Validity of contracts formed through electronic means.—** *Where in a contract formation,
> the communication of proposals, the acceptance of proposals, the revocation of proposals and
> acceptances, as the case may be, are expressed in electronic form or by means of an electronic
> record, such contract shall not be deemed to be unenforceable solely on the ground that such
> electronic form or means was used for that purpose.* **[A]** (no proviso)

**s.10A does not bear on certificates.** A transfer certificate, bonafide certificate or character
certificate is **not a contract** — there is no proposal and no acceptance; it is a unilateral
official statement of fact by the institution. **s.10A is the wrong tool and citing it would be an
error.** The provisions that do the work for us are ss.4 (writing), 5 (signature) and 3A (technique).

> **Dependency flagged, as the brief asks.** Everything in §5 and §6 turns on how the artefact is
> *characterised*. If a TC were ever held to be a "document" of one of the First Schedule kinds, or
> to form part of a contract, the analysis changes. I have found nothing suggesting either, and §5
> shows the First Schedule list is short and specific — but the characterisation is an assumption,
> not a finding.

---

## 2 · The Second Schedule — what is actually in it

> **THE SECOND SCHEDULE** *[See sub-section (1) of section 3A]*
> **ELECTRONIC SIGNATURE OR ELECTRONIC AUTHENTICATION TECHNIQUE AND PROCEDURE** **[A]**

### 2.1 · Entry 1 — e-authentication using Aadhaar **or other** e-KYC services

Inserted by **G.S.R. 61(E), 27 January 2015**, *Electronic Signature or Electronic Authentication
Technique and Procedure Rules, 2015*, made under s.3A(4). Verbatim from the gazette PDF the CCA
hosts **[A]**:

> *"1. **e-authentication technique using Aadhaar e-KYC services** — Authentication of an electronic
> record by e-authentication Technique which shall be done by—*
> *(a) the applicable use of e-authentication, hash, and asymmetric crypto system techniques, leading
> to issuance of Digital Signature Certificate by Certifying Authority*
> *(b) a trusted third party service by subscriber's key pair-generation, storing of key pairs on
> hardware security module and creation of digital signature provided that the trusted third party
> shall be offered by the certifying authority…*
> *(c) Issuance of Digital Signature Certificate by Certifying Authority shall be based on
> e-authentication, particulars specified in Form C of Schedule IV of the Information Technology
> (Certifying Authorities) Rules, 2000, digitally signed verified information from Aadhaar e-KYC
> services **and electronic consent of Digital Signature Certificate applicant**…"*

**Amendment history, from the notifications themselves [A]:**

| notification | date | what it did |
|---|---|---|
| G.S.R. 61(E) | 27.01.2015 | **inserted entry 1** (Aadhaar e-KYC) |
| G.S.R. 539(E) | 30.06.2015 | amendment "w.r.t. HSM secure storage" **[B]** — text not read |
| G.S.R. 446(E) | 27.04.2016 | *Electronic Signature or Electronic Authentication Technique and Procedure Rules, 2016*, "linking End-entity signature rules" **[B]** — text not read |
| **S.O. 1119(E)** | **01.03.2019** | *"under column (2), after the word **"Aadhaar"**, the words **"or other"** shall be inserted"* — and in item (e), after "subscriber's key pair", *"and other e-KYC services"* **[A]** |
| **S.O. 3472(E)** | **29.09.2020** | **inserted entry 2** (below) **[A]** |

**So Aadhaar is *in* the Second Schedule, and since 2019 it is no longer alone** — the entry now
reads "Aadhaar **or other** e-KYC services", which is what lets CAs run bank-KYC and PAN-based
e-Sign as well as Aadhaar.

### 2.2 · Entry 2 — remote key storage by a trusted third party

Inserted by **S.O. 3472(E), 29 September 2020 [A]**, verbatim from the gazette:

> *"2. **e-authentication technique and procedure for creating and accessing subscriber's signature
> key facilitated by trusted third party** — Authentication of an electronic record by
> e-authentication technique which shall be done by—*
> *(a) the applicable use of e-authentication, hash and asymmetric crypto system techniques leading to
> issuance of Digital Signature Certificate by Certifying Authority, **provided that Certifying
> Authority shall ensure the subscriber identity verification, secure storage of the keys by trusted
> third party and subscriber's sole authentication control to the signature key**…*
> *(d) a trusted third party shall (i) facilitate Identity verification…; (ii) **establish secure
> storage for subscriber to have sole control for creation and subsequent usage of subscriber's
> signature key by sole authentication of subscriber**…"*

**This is the entry that makes a workable product possible.** Entry 1 mints a throwaway 30-minute
certificate per signature. Entry 2 lets a **long-lived key live in a CA-operated HSM** while the
subscriber keeps sole control through authentication — no USB token in the Principal's drawer.
**"Sole authentication control" is repeated three times in the entry.** It is the non-negotiable
term, and it is what forbids the ERP from signing unattended.

### 2.3 · A staleness trap worth recording

The indiankanoon rendering of the Act shows the Second Schedule with entry 1 followed by two rows
reading **"Omitted / Omitted"**. **No notification I retrieved omits any Second Schedule entry** —
S.O. 1119(E) amends entry 1 and S.O. 3472(E) *inserts* entry 2. Those "Omitted" rows appear to be a
rendering artefact of the aggregator, not the law. **Do not cite indiankanoon's schedule rendering
as authority; cite the gazette PDFs.** Marked **[D]** and left open.

---

## 3 · A DSC and the CCA route — what a school would actually have to do

### 3.1 · Who issues

> *"The Office of Controller of Certifying Authorities (CCA), issues Certificate only to Certifying
> Authorities (CAs). CAs issue Digital Signature Certificates to end-entities."* **[B]**

**Licensed CAs (23 listed, cca.gov.in/licensed_ca.html) [B]:** Safescrypt (Sify), IDRBT,
(n)Code Solutions (GNFC), eMudhra, CDAC, Capricorn, Protean (ex-NSDL e-Gov), Vsign (Verasys),
Indian Air Force, CSC, RISL (RajComp), Indian Army, IDSign, CDSL Ventures, Panta Sign, XtraTrust,
Indian Navy, ProDigiSign, SignX, Care 4 Sign, IGCAR, Speed Sign, Assam Rifles.

Statutory basis: *"'Certifying Authority' means a person who has been granted a licence to issue a
electronic signature Certificate under section 24"* (s.2(1)(g)) **[A]**; application and fee under
**s.35**, which caps the CA's fee at *"not exceeding twenty-five thousand rupees as may be
prescribed by the Central Government"* **[A]** — note this is the statutory ceiling on the
*prescribed* fee, **not a market price**, and not what a school pays.

### 3.2 · The classes

From the CCA's own FAQ **[B]**, which points to §1.3.5 of the *X.509 Certificate Policy for India
PKI* (CCA-CP), read directly **[B]**:

| class | identity verification | key storage |
|---|---|---|
| **Class 1** | Aadhaar eKYC biometric, **or** paper form + documents, **or** Aadhaar eKYC OTP + video verification | **software** permitted |
| **Class 2** | same options | **hardware crypto device, FIPS 140-2 level 2** |
| **Class 3** | Aadhaar eKYC biometric, **or** paper form + documents **and** (physical personal appearance before the CA **or** video verification), **or** Aadhaar eKYC OTP + video verification | **hardware crypto device, FIPS 140-2 level 2** |
| **eKYC-OTP / eKYC-Biometric** | the e-Sign classes — see §4 | keys created on HSM, **destroyed after one-time use** |

CCA-CP §1.3.5 **[B]**: Class 1 is *"a basic level of assurance … not considered to be of major
significance"*; Class 3 *"will be issued to individuals as well as organizations … high assurance
certificates"*.

**The CCA also says the price is not regulated:** *"The price of the certificate may however vary
from CA to CA."* **[B]** Any rupee figure is therefore a vendor quote, not a fact I can establish —
see §9.

### 3.3 · The finding that decides our architecture

**A school's instinct is to buy one certificate for the institution and let the ERP sign every
certificate with it. The CCA has answered that question directly, and the answer is no.**

> *"The document signer certificate is issued for use with the software of an organisation for
> automated authenticated response. **Document signer certificate is not a replacement for the
> signature of the authorised signatory of the organisation.**"* — CCA FAQ, *DSC for Organisational
> person* **[B]**

And the same FAQ closes the other shortcut:

> *"The keys corresponding to Class 2 and Class 3 certificates are to be mandatorily stored in
> FIPS 140-2 level 2 validated crypto Token **which is in the custody of the subscriber**. The
> requirements for the storage of key pairs of subscribers are not in full compliance when using HSM
> for Class 2 and Class 3 certificates."* **[B]**

> *"Whether Digital Signature certificate & signing keys of an employee can be retained by
> organisation upon the subscribers exiting the organisation? **No.** The Digital Signature
> Certificate should be revoked and keys should be destroyed by the subscriber."* **[B]**

**Read with s.15's "exclusive control of signatory", this is a coherent single rule: the signature
belongs to a human being, not to an institution or its software.** CCA-CP §6.1.1 does contemplate a
"Document Signer" certificate type (Class 3, hardware key storage) **[B]** — but per the FAQ its
role is *automated authenticated response*, i.e. proving the document came from our system
unaltered. **That is a tamper-evidence seal, not the Head's signature.**

### 3.4 · So, concretely, for one school

1. The **Head of the institution, as an individual**, applies to any licensed CA for a **Class 3
   individual (signature) DSC**, verified by Aadhaar eKYC biometric, or by paper form plus personal
   appearance/video verification.
2. The private key is generated on and kept in a **FIPS 140-2 level 2 crypto token in the Head's own
   custody** — or, under Second Schedule entry 2, in a CA-operated HSM under the Head's **sole
   authentication control**.
3. Certificates are typically valid 2–3 years (**[B]**, CCA eSign FAQ describing the traditional
   route) and **must be revoked and the keys destroyed when the Head leaves** — which makes
   **succession a first-class product requirement, not an afterthought**. A school changes Principal
   more often than it changes ERP.
4. The school may *additionally* hold a **Document Signer certificate** for the ERP, to prove the
   PDF came from ZenXii unaltered. **Additionally — never instead.**

---

## 4 · Aadhaar e-Sign: how it works, and whether a school could use it for every certificate

### 4.1 · What e-Sign is, in the CCA's words **[B]**

> *"eSign is an online electronic signature service which can be integrated with service delivery
> applications via an API to facilitate an eSign user to digitally sign a document. Using
> authentication of the eSign user through e-KYC service, online electronic signature service is
> facilitated."*

> *"eSign ensures the privacy of the signer by requiring that **only the thumbprint (hash) of the
> document** be submitted for signature function instead of the whole document."*

> *"To enhance security and prevent misuse, eSign user's private keys are created on Hardware
> Security Module (HSM) and **destroyed immediately after one time use**."*

> *"The Digital Signature Certificate used to verify the signature will be **valid for 30 minutes**
> and the private key will be immediately deleted after signing."* (eSign FAQ Q26)

**The hash-only design is an unexpected fit for us.** We already compute a SHA-256 over real PDF
bytes. e-Sign wants exactly that and nothing more — the certificate's contents never leave our
infrastructure. It also means an e-Sign integration and the BSA s.63 Part A hash are **the same
engineering work done once**.

### 4.2 · Who the providers are

**Empanelled eSign Service Providers (ESPs)** — all are themselves licensed CAs, and the CCA is
explicit that *"As of now, only CAs are allowed to operate as eSign Service Providers"* **[B]**:
Safescrypt, (n)Code Solutions, eMudhra, C-DAC, Capricorn, JPSL (Jio Payment Solutions), Speed Sign,
Protean, Verasys, CSC, RajCOMP (RISL), Panta Sign, Care 4 Sign, IDSign, CDSL Ventures, XtraTrust,
ProDigiSign, SignX, IGCAR. **[B]** (`cca.gov.in/service-providers.html`)

**ZenXii's role would be ASP, not ESP.** The eSign FAQ lists who may integrate: *"A legal entity
registered in India"* qualifies, and *"Educational Institutions"* are named as potential ASPs
**[B]**. But: *"ASP can avail service from ESP for integrating eSign service **only for applications
owned or operated by them**. The functions of obtaining consent from the signer and its logs should
be with ASP only."* **[B]**

**That is a real commercial question for a multi-tenant SaaS.** Is ZenXii the ASP for 300 schools,
or is each school its own ASP? The prohibition on **subletting** is explicit. **Unresolved — and it
is a contracting question for counsel and an ESP, not a research question.** Flagged for
`AUTHORITY_BACKLOG.md`.

### 4.3 · Consent — and why it cannot be pre-collected

> **CCA e-authentication guidelines v1.9, 09.09.2026** (current), §4.6: *"**The OTP and Mobile Access
> token verified along with PURPOSE text is deemed as consent.**"* **[B]**

> §2.2(6): *"The consent of the eSign user for getting a Digital Signature Certificate should be
> obtained electronically."* §2.5(1): *"The consent of the eSign user for digital signing of
> electronic record would have already been obtained electronically."* §2.5(2): *"eSign user should
> be given an option to **reject** the Digital Signature Certificate."* **[B]**

**Consent *is* the authentication event.** There is no such thing as standing consent — the OTP the
Head types, bound to a PURPOSE string, *is* the consent for that transaction. **A design in which
the Principal consents once in September and the system signs until March is not a permitted
design.**

### 4.4 · The hard ceiling on bulk signing — 10 documents, 10 minutes

This is the single most operationally decisive sentence I found, and it is current
(e-authentication guidelines v1.9, §10, remote key storage) **[B]**:

> *"Each secure session initiated by the subscriber to access private key functions shall:*
> *(a) Be valid for a maximum lifetime of **10 minutes***
> *(b) Be cryptographically bound to the subscriber's IP address, eKYC ID, and transaction ID*
> *(c) Be valid for a single operation (e.g., AuthPIN set, sign), or **for a defined set of not more
> than 10 documents** where the Session Token is single-use and cryptographically bound to the exact
> list of document hashes explicitly authorised by the subscriber, their count, and the transaction
> ID.*
> *Any deviation in parameters, document hashes, document count, transaction ID, reuse of the Session
> Token, or timeout must result in invalidation of the Session Token, session termination and full
> re-authentication."*

**Feasibility, computed from that rule [D from B]:**

| scenario | signatures | sessions at ≤10/session | Head's authentications |
|---|---|---|---|
| bonafide certificates, ~20/month | 20 | 2 | 2 OTPs/month — **trivially feasible** |
| TCs at end of session, 40 leavers | 40 | 4 | 4 OTPs in one sitting — **feasible** |
| a 1,500-pupil school issuing annual progress/character certs to all | 1,500 | 150 | **150 authentications** — **not feasible as a manual ritual** |

**So the honest answer to "could a school realistically e-Sign every certificate it issues" is:
yes for the leaver/bonafide workload, which is what this module is actually for; no for
issue-to-everyone documents.** And the constraint has a clean shape in the UI: **a signing queue
batched in tens**, each batch one OTP, each batch bound to an exact list of document hashes. That is
buildable. What is *not* buildable is a "sign all" button.

### 4.5 · Cost

**NOT FOUND — and deliberately not guessed.** The CCA declines to publish a price:

> *"How much does it cost to use eSign? **Application service providers can do a price discovery and
> get the best offer from any of the providers.** Depending on the volume and usage, pricing may
> vary."* **[B]**

Any per-signature rupee figure must come from an ESP quote. **Do not put a number in a plan citing
this file.**

---

## 5 · The s.1(4) / First Schedule exclusions — is a school certificate caught?

> **s.1(4)** *Nothing in this Act shall apply to documents or transactions specified in the First
> Schedule: Provided that the Central Government may, by notification in the Official Gazette, amend
> the First Schedule by way of addition or deletion of entries thereto.*
> **s.1(5)** *Every notification issued under sub-section (4) shall be laid before each House of
> Parliament.* **[A]**

### 5.1 · The list, current as amended

> **THE FIRST SCHEDULE** *[See sub-section (4) of section 1]*
> **DOCUMENTS OR TRANSACTIONS TO WHICH THE ACT SHALL NOT APPLY** **[A]**

| # | description | status |
|---|---|---|
| 1 | *A negotiable instrument (other than a cheque…) as defined in section 13 of the Negotiable Instruments Act, 1881* | **substituted** by S.O. 4720(E) to carve out demand promissory notes / bills of exchange in favour of RBI-, NHB-, SEBI-, IRDAI- and PFRDA-regulated entities |
| 2 | *A power-of-attorney as defined in section 1A of the Powers-of-Attorney Act, 1882* | **narrowed** by S.O. 4720(E) — *"but excluding those power-of-attorney that empower an entity regulated by [RBI/NHB/SEBI/IRDAI/PFRDA] to act for, on behalf of, and in the name of the person executing them"* |
| 3 | *A trust as defined in section 3 of the Indian Trusts Act, 1882* | unchanged |
| 4 | *A will as defined in clause (h) of section 2 of the Indian Succession Act, 1925 … including any other testamentary disposition by whatever name called* | unchanged |
| ~~5~~ | ~~*Any contract for the sale or conveyance of immovable property or any interest in such property*~~ | **OMITTED** by S.O. 4720(E), 26.09.2022 |

**S.O. 4720(E), 26 September 2022, verbatim [A]:**

> *"In exercise of the powers conferred by the proviso to sub-section (4) of section 1 of the
> Information Technology Act, 2000 … (iii) **serial number 5 and the entries relating thereto shall
> be omitted**."*

**A second staleness trap.** The indiankanoon consolidation still prints entry 5. **It was removed
in 2022.** The corpus should treat aggregator renderings of the Schedules as unreliable and go to
the gazette.

### 5.2 · Is a school certificate caught?

**No. [D from A, and the inference is short.]** A transfer certificate, bonafide certificate or
character certificate is not a negotiable instrument, not a power-of-attorney, not a trust, and not
a will. The First Schedule is a **closed, specific list of four instrument types** — not a category
like "official documents" or "records of educational institutions".

**Therefore the IT Act's electronic-signature regime applies to school certificates in full**, and
s.5's deeming provision is available to us. **This is the load-bearing negative finding of the whole
file**: had a certificate been caught by s.1(4), no amount of e-Sign engineering would have made it
signed, and the only lawful artefact would be ink on paper.

**The caveat, stated rather than buried.** This conclusion depends on the characterisation flagged
in §1.6. It is an inference from the Schedule's text, not a holding — **I found no judgment
characterising a school certificate for First Schedule purposes** (§9).

---

## 6 · Does *"signed by the Head of the school himself"* survive an electronic signature?

The sharpest question in the brief. **The answer is yes — with a condition that is not the one
people expect.**

### 6.1 · The requirement, as the corpus established it

> *"No leaving certificate is valid unless it is in the form prescribed by the Director of Education
> and **is signed by the Head of the school himself**."* — Goa, Daman and Diu School Education Rules
> 1986, **r.127** (recorded at `west-central-india.md:1331`, `gap-closure-west-east.md:101`) **[A,
> via this corpus]**

> Gujarat, **r.108**: *"No leaving certificate is valid unless it is in the form prescribed by the
> Director of Education and is signed by the **head of the school himself/herself**."*
> (`west-central-india.md:1500`) **[A, via this corpus]**

**Note the consequence clause in both: "No leaving certificate is *valid* unless…".** This is not
advisory. A defective signature voids the document.

### 6.2 · What "himself" does — and it is not what it looks like

There is a settled Supreme Court line on exactly this word. From
**Commissioner of Agricultural Income Tax v. Keshab Chandra Mandal**, 1950 SCR 435 : AIR 1950 SC 265
: (1950) 18 ITR 569, as extracted at length by the Allahabad High Court (below) **[A]**:

> *"…when the word 'sign' or 'signature' is used by itself and unless there be a clear indication
> requiring the personal signature by the hand of the person concerned, the provision would be
> satisfied by a person signing by the hand of an agent."* (the common-law rule *qui facit per alium
> facit per se*)
>
> *"…unless a particular statute expressly or by necessary implication or intendment excludes the
> common law rule, the latter must prevail."*
>
> *"In all these cases the common law rule was not applied, evidently because the particular statutes
> were held to indicate that the intention was to exclude that rule. **This intention was gathered
> from the use of the word 'himself' or 'by him' or 'under his hand' or 'personally.'**"*

**So "himself" is a rule about *agency*, not about *ink*.** Its legal work is to stop the clerk, the
vice-principal or the office superintendent signing for the Head. The English authorities the
Supreme Court collected make the same point on the same word — *Monks v. Jackson* (1876), where
delivery *"by the candidate himself"* was not satisfied by an agent; *The Queen v. Mansel Jones*
(1889), where a right to be *"heard by himself"* excluded counsel.

**Nothing in that line says anything about the *medium*.** None of those cases was about ink versus
electrons; they were all about person A versus person B.

### 6.3 · And the medium question has been answered the other way

**Vikas Gupta v. Union of India and 3 Others**, Allahabad High Court, 8 September 2022
(Surya Prakash Kesarwani J.), on Income-tax Act s.282A(1) *"shall be signed"* **[A]**:

> *"Thus the expression 'shall be signed' used in Section 282A(1) of the Act 1961 makes the signing of
> the notice or other document by that authority a **mandatory requirement. It is not a ministerial
> act or an empty formality which can be dispensed with.** 'Signed' means to sign one's name; to
> signify assent or adhesion to by signing one's name; to attest by signing or when a person is
> unable to write his name then affixation of 'mark' by such person. The document must be signed or
> mark must be affixed in such a way as to make it appear that the person signing it or affixing his
> mark is the author of it. Therefore, a notice or other document as referred in Section 282A (1) of
> the Act, 1961 **will take legal effect only after it is signed by that Income Tax Authority,
> whether physically or digitally.** The usage of the word 'shall' make it a mandatory requirement."*

**"Whether physically or digitally" is the holding we need**, and it sits inside a judgment that is
otherwise *strict* about signing — which makes it stronger, not weaker, as authority.

### 6.4 · The synthesis

Putting §6.2, §6.3 and s.5 together **[D from A]**:

| the requirement | satisfied by | not satisfied by |
|---|---|---|
| *"signed"* | the Head's **own** DSC or e-Sign, affixed by the Head | a typed name; a scanned image of a signature |
| *"…himself"* | an act **the Head personally authenticates** | the vice-principal's key; a delegate's e-Sign |
| *"…himself"* — additionally | — | **an ERP holding a key and signing unattended** |

**A statutory requirement that the Head sign "himself" therefore survives electronic signature
intact. What it does *not* survive is automation.** The word "himself" is the exact legal obstacle
to the feature a school will ask for first — *"can the system just sign them all overnight?"*

**And the three regimes converge on the same prohibition**, which is why I hold this conclusion with
some confidence:

- **s.15(i)** — signature creation data under the **exclusive control of the signatory**;
- **Second Schedule entry 2** — *"subscriber's **sole authentication control** to the signature key"*;
- **CCA FAQ** — *"Document signer certificate is **not a replacement for the signature** of the
  authorised signatory"*;
- **Keshab Chandra Mandal** — *"himself"* **excludes signature by another hand**.

**Four independent sources, one rule: a human being signs, per act of signing.**

**Honest limits.** (i) *Vikas Gupta* is a High Court decision on a different statute; I found **no
judgment construing "signed by the Head of the school himself"** in an education rule (§9). (ii) The
s.5 override is only available for an electronic signature *"affixed in such manner as may be
prescribed"*, and I could not read the prescribing 2004 rules (§1.4). (iii) A State could in
principle prescribe a form that demands ink — the Goa rule additionally requires *"the form
prescribed by the Director of Education"*, and `gap-closure-west-east.md` records that **no Goa LC
form is annexed to the Rules and its text is unpublished**. **If that unpublished form has an ink
signature line, it, not r.127, is the obstacle.** That is a document-retrieval task for a person in
Goa, not something this research can close.

---

## 7 · Case law on typed, printed and scanned signatures

### 7.1 · A printed name can be *deemed* authentication — but only where a statute says so

The clearest worked example in Indian law is Income-tax Act **s.282A**, quoted in *Vikas Gupta*
**[A]**:

> *"(1) Where this Act requires a notice or other document to be issued, served or given by any
> income-tax authority, such notice or other document shall be **signed** by that authority in
> accordance with such procedure as may be prescribed.*
> *(2) Every notice or other document to be issued, served or given for the purposes of this Act by
> any income-tax authority, shall be **deemed to be authenticated if the name and office of a
> designated income-tax authority is printed, stamped or otherwise written thereon**."*

and Rule 127A of the Income-tax Rules 1962, which extends the same deeming to e-mail and to an
electronic record displayed on a designated website **[A]**.

**The Revenue argued precisely our fact pattern** — that a printed name on a system-generated
electronic document was enough, and *"affixation of digital signature is not a precondition for
validation of the document."*

**The Court rejected it, on the structure of the section [A]:**

> *"The word 'and' has been used in sub-Section (1), in conjunctive sense, meaning thereby that such
> notice or other document has **first to be signed by the authority and thereafter** it may be
> issued either in paper form or may be communicated in electronic form…"*

**The lesson, and it is directly ours [D from A]: a "printed name = authenticated" deeming provision
does not substitute for a signing requirement that sits beside it.** Authentication of *origin* and
*signature* are two different things, and satisfying the first leaves the second unsatisfied. Our
typed-name-plus-seal is at best an origin marker — **and unlike the income-tax authority, we have no
statute deeming even that much.**

### 7.2 · The meaning of "signed", as the courts state it

- **General Clauses Act 1897, s.3(56)**, quoted in *Vikas Gupta* **[A]**: *"'sign', with its
  grammatical variations and cognate expressions, shall, **with reference to a person who is unable
  to write his name**, include 'mark'…"* — the statutory extension is for people who cannot write,
  **not a general licence for marks in place of signatures**.
- **Hindustan Construction Co. Ltd. v. Union of India**, 1967 (1) SCR 543 : AIR 1967 SC 526, ¶7
  **[A]**: *"This provision indicates that **signing means writing one's name on some document or
  paper**."*
- **Mohesh Lal v. Busunt Kumaree**, (1881) ILR 6 Cal 340, as quoted there **[A]**: *"the document must
  be signed in such a way as to make it appear that the person signing it is the author of it, and if
  that appears it does not matter what the form of the instrument is, or in what part of it the
  signature occurs."*
- **Dakshin Haryana Bijli Vitran Nigam Ltd. v. Navigant Technologies (P) Ltd.**, (2021) 7 SCC 657,
  ¶¶25–26 **[A]**: *"An award takes legal effect only after it is signed by the arbitrators, **which
  gives it authentication**. … It is **not merely a ministerial act, or an empty formality which can
  be dispensed with**."*

*Mohesh Lal* is the most useful of these for us and cuts **in our favour on form**: what matters is
that the signature shows the signer is the **author** — not where it sits or what the instrument
looks like. Combined with *Vikas Gupta*'s *"whether physically or digitally"*, the case law is
**medium-agnostic and author-focused**. Our problem is not that our signature is in the wrong place;
it is that **there is no act of authorship by the Head anywhere in the pipeline**.

### 7.3 · What I could not find

**NOT FOUND: an Indian judgment squarely holding that a scanned or image signature on an official
document is, or is not, valid.** Searches on `indiankanoon.org` for "scanned signature", "facsimile
signature" and combinations around image signatures returned statutory provisions (municipal Acts
and election rules that *expressly authorise* facsimile signatures for specified officers) and
factually unrelated judgments. **That statutory pattern is itself weak evidence [D]: where Indian
law wants a facsimile signature to count, it says so by name — which implies it does not count by
default.** But that is an inference from silence and I am not dressing it as a holding.

**NOT FOUND: any judgment on e-Sign or DSC in an education-certificate context.** The nearest thing
in this corpus remains **C-66** (*Hritika Mitra v. Registrar, Ravenshaw University*, Orissa HC,
W.P.(C) 8537/2021), where a **School Leaving Certificate** specifically was held to require
production in original notwithstanding DigiLocker Rule 9A. **That is the most on-point Indian
authority we have about our exact document, and it points away from digital acceptance** — though,
as C-66 records, it is an admission-requirement case, not a signature case.

---

## 8 · What this means for the PDF we render today

### 8.1 · The current artefact

`COLLECTION_SHAPES.md` models certificate chrome as `blockType: "letterhead" | "signature" | "seal"`
and records TC-shaped documents as `requiredSignatures: ["class_teacher", "checked_by",
"principal"]` with a `countersignature` when the origin board is not CBSE; output is mPDF 8.3.1.
**The "signature" block is a layout region — a typed name, or at most an uploaded image. There is no
key, no certificate and no cryptographic operation anywhere in the pipeline.**

### 8.2 · The verdict, stated plainly

| question | answer |
|---|---|
| Is our PDF **signed** within the IT Act? | **No.** Not s.3 (no key pair), not s.3A (no Second Schedule technique). **[D from A]** |
| Is it a **secure electronic signature** (s.15)? | **No**, and not close — nothing is under anyone's exclusive control. **[D from A]** |
| Does s.5 deem it to satisfy a rule requiring a signature? | **No.** s.5 fires only for an electronic signature affixed in the prescribed manner. **[D from A]** |
| Is it therefore **unlawful to issue**? | **Not established.** The IT Act does not prohibit it. The exposure is that a rule like Goa r.127 says such a certificate is **"not valid"**, and that a relying party may refuse it (s.9, and C-66). **[D]** |
| Does the **seal image** help? | **No.** It carries no legal weight the typed name does not; s.3A(2)(c)/(d) is unsatisfied either way. **[D from A]** |
| Does signing fix **admissibility**? | **No.** BSA s.63 Part A hash + Part B expert certification are a **separate requirement**. A perfectly e-Signed PDF with no s.63 certificate is still inadmissible. **[A, per `electronic-evidence-s63.md`]** |

### 8.3 · The three ladders, priced in engineering rather than rupees

**Rung 0 — where we are.** Typed name + seal image. Zero cost, zero legal standing, and it silently
*looks* signed, which is the part that should worry us: **the artefact asserts an authority it does
not have.**

**Rung 1 — Document Signer certificate on the ERP (Class 3, hardware).** The school or ZenXii holds a
certificate; every rendered PDF is signed automatically. **This buys real tamper-evidence and real
provenance — "this came from ZenXii and has not been altered" — and it satisfies s.3A(2)(c)/(d).**
It does **not** satisfy "signed by the Head himself" (§3.3). **Correctly described as a seal, and
never described to a school as a signature.**

**Rung 2 — the Head's own signature.** A Class 3 individual DSC, or e-Sign under Second Schedule
entry 1, or a long-lived key under entry 2 with sole authentication control. **This is the only rung
that satisfies a "signed by the Head himself" rule.** Product shape is forced by §4.4: a **signing
queue, batched in tens, one authentication per batch, each batch bound to the exact list of document
hashes**, with an explicit PURPOSE string. Plus **succession handling** — revoke and destroy on the
Head's exit (§3.3).

**Rungs 1 and 2 are not alternatives.** Rung 1 proves the document came from the system; rung 2
proves the Head signed it. A mature artefact carries both, plus the BSA s.63 hash of *that issued
document* which `electronic-evidence-s63.md` already identified as missing.

### 8.4 · The timing argument, restated

`electronic-evidence-s63.md` made the point and it applies with equal force here: **no Document
Engine print path is wired, so nothing has been issued yet.** There is no backlog of unsigned
certificates in the field. **Adding a signature slot to the issued-document record now costs a
field; retrofitting it after a year of issuance means a year of certificates that are neither signed
nor hashable.** The same is true of the `signature` block type — deciding *now* that it may carry a
cryptographic signature rather than a picture is nearly free.

### 8.5 · One thing we should stop doing regardless

**The rendered seal image and the typed name together produce a document that presents as signed.**
Whatever we decide about DSC and e-Sign, a document that carries no signature should not *look* like
it carries one. That is a design decision available immediately, at no legal or infrastructural
cost, and it is the only recommendation in this file that needs nobody's budget.

---

## 9 · NOT FOUND register

**Sources that refused this fetcher**

| source | attempted | result |
|---|---|---|
| `indiacode.nic.in` | (per brief, not re-attempted) | **HTTP 403** |
| `meity.gov.in/static/uploads/2024/02/it_amendment_act2008.pdf` | IT (Amendment) Act 2008, for the Schedules as enacted | **HTTP 403** — MeitY blocks this fetcher entirely; **cca.gov.in is the working route to the same gazettes** |
| `cca.gov.in/sites/files/pdf/ACT/GSR582.pdf` | *IT (Use of Electronic Records and Digital Signatures) Rules, 2004* | fetched (639 KB) but is an **image-only scan with no text layer**; **not read** |

**Substantive gaps — recorded honestly, not papered over**

1. **The "manner prescribed" under s.5.** s.5 deems a signature requirement satisfied only for an
   electronic signature *"affixed in such manner as may be prescribed by the Central Government."*
   The prescribing instruments appear to be G.S.R. 582(E) (2004) and the *Digital Signature (End
   entity) Rules, 2015* (G.S.R. 660(E)) **[B, from the CCA gazette index]**. **Neither text was
   read.** Until one is, the precise conditions on which s.5 bites are **NOT ESTABLISHED**.
2. **G.S.R. 539(E) (2015) and G.S.R. 446(E) (2016).** Both amend the Second Schedule; I have the
   CCA's one-line descriptions (*"modification w.r.t. HSM secure storage"*, *"linking End-entity
   signature rules"*) **[B]** but **not their operative text [A]**. The consolidated entry 1 quoted
   in §2.1 is therefore G.S.R. 61(E) as originally made, **not** guaranteed to be the fully
   consolidated current text.
3. **The "Omitted / Omitted" rows** in indiankanoon's Second Schedule rendering are unexplained. No
   retrieved notification omits a Second Schedule entry. **Treated as an aggregator artefact [D];
   not resolved.**
4. **No judgment found construing "signed … himself" in a school education rule.** §6 reasons by
   analogy from *Keshab Chandra Mandal* (a general canon of construction, which is strong) and
   *Vikas Gupta* (a different statute, which is weaker). **A direct authority would be better and
   does not appear to exist in the searchable corpus.**
5. **No judgment found on the validity of a scanned or image signature** on an official document
   either way (§7.3).
6. **No judgment found characterising a school certificate for First Schedule / s.1(4) purposes.**
   §5.2's conclusion is inference from the Schedule's closed list, not a holding.
7. **Per-signature e-Sign cost: NOT FOUND, and unobtainable from official sources** — the CCA
   expressly leaves it to *"price discovery"* (§4.5). **Any figure in any downstream document citing
   this file is a vendor quote and must be labelled as one.**
8. **Whether ZenXii can be ASP for many schools, or each school must be its own ASP.** The
   anti-subletting rule is quoted at §4.2 but the multi-tenant question is **unresolved** and is a
   contracting question, not a research one.
9. **The Goa LC form prescribed by the Director of Education** remains unpublished
   (`gap-closure-west-east.md`). **If it carries an ink signature line, it — not r.127 — is the
   binding obstacle**, and §6's conclusion would need revisiting for Goa specifically.
10. **DigiLocker / Rule 9A interaction with signing: not re-opened here.** Conflict **C-66** stands:
    Rule 9A is permissive, and the Orissa HC declined to enforce it **for a School Leaving
    Certificate**. Nothing in this file disturbs that, and **§1.5 (s.9) supplies the statutory reason
    it is not an aberration.**

---

## Sources

**Primary [A]** — *Information Technology Act, 2000* (bare Act, `indiankanoon.org/doc/1965344/`;
sections also at `/doc/1869099/` s.3, `/doc/166473284/` s.3A, `/doc/292738/` s.5, `/doc/123351751/`
s.10A) · G.S.R. 61(E) 27.01.2015 · S.O. 1119(E) 01.03.2019 · S.O. 3472(E) 29.09.2020 ·
S.O. 4720(E) 26.09.2022 (all gazette PDFs at `cca.gov.in/sites/files/pdf/ACT/`) ·
*Vikas Gupta v. Union of India and 3 Others*, Allahabad HC, 08.09.2022 (`indiankanoon.org/doc/192504406/`),
and the authorities it extracts: *Commissioner of Agricultural Income Tax v. Keshab Chandra Mandal*
(1950), *Hindustan Construction Co. v. UOI* (1967), *Dakshin Haryana Bijli Vitran Nigam v. Navigant
Technologies* (2021), *Mohesh Lal v. Busunt Kumaree* (1881) · General Clauses Act 1897 s.3(56).

**Official site [B]** — CCA (MeitY): `eSign.html`, `licensed_ca.html`, `service-providers.html`,
`digital_signature.html`, `classes_of_certificates.html`, `dsc_organisational.html`,
`eSign_service_faq.html`, `eSign_gazette_notification.html`; *eSign FAQ* PDF; *e-authentication
guidelines for eSign* **v1.9, 09.09.2026**; *X.509 Certificate Policy for India PKI* (CCA-CP).

**This corpus** — `electronic-evidence-s63.md` (BSA s.63) · `CONFLICTS.md` C-66 (DigiLocker Rule 9A,
*Hritika Mitra*) · `west-central-india.md` §Goa r.127, §Gujarat r.108 · `gap-closure-west-east.md` ·
`COLLECTION_SHAPES.md` (what we render today).
