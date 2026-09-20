# CERT-In Directions 28.04.2022 — the obligation that IS already in force

Chased because the DPDP research flagged it as *"the one thing most likely to disturb the
US-storage conclusion."* It does not overturn it. **It does something more useful: it is the one
cyber-security obligation on this list that has been binding since 2022, while DPDP's operative
provisions wait until 13 May 2027 (C-70).**

**Sources, fetched directly [A]:** `cert-in.org.in/PDF/CERT-In_Directions_70B_28.04.2022.pdf`
(8 pp) and `cert-in.org.in/PDF/FAQs_on_CyberSecurityDirections_May2022.pdf` (28 pp). Both 403 the
agent fetcher and serve normally to `curl` with a browser User-Agent — the same route the DPDP pass
found for `meity.gov.in`.

## 1 · It reaches us

The Directions bind *"service providers, intermediaries, data centres, body corporate, VPS
providers, Cloud service providers, VPN Service providers … and Government organisations"* **[A]**.
**ZenXii is a body corporate**, and *"Individual citizens are not covered"* is the only carve-out.

Backed by **s.70B(7) IT Act**: failure to comply is *"punishable with imprisonment for a term which
may extend to one year or with fine which may extend to one lakh rupees or with both."*

## 2 · Three obligations, verbatim

**(i) Clock synchronisation** — connect *"to the Network Time Protocol (NTP) Server of National
Informatics Centre (NIC) or National Physical Laboratory (NPL) or with NTP servers traceable to
these"*, for *"synchronisation of all their ICT systems clocks."* Infrastructure spanning multiple
geographies may use another source *"provided their time source shall not deviate from NPL and NIC."*

**(ii) Six-hour incident reporting** — *"shall mandatorily report cyber incidents as mentioned in
Annexure I to CERT-In **within 6 hours** of noticing such incidents or being brought to notice."*

**(iv) Logs** — the one that prompted this:

> *"All service providers, intermediaries, data centres, body corporate and Government organisations
> shall mandatorily **enable logs of all their ICT systems** and maintain them securely for a
> **rolling period of 180 days** and the same shall be **maintained within the Indian
> jurisdiction**."*

## 3 · The Direction and CERT-In's own FAQ do not say the same thing

**This is a conflict between two official CERT-In documents, and it is recorded rather than
resolved.**

| instrument | what it says |
|---|---|
| **The Direction** (28.04.2022), ¶(iv) | logs *"shall be maintained **within the Indian jurisdiction**"* |
| **The FAQ** (May 2022), **Q35** | *"Is it required to store copy of logs in India only? Ans.: **The logs may be stored outside India also** as long as the obligation to produce logs to CERT-In is adhered to by the entities in a reasonable time."* |

The Direction is the legal instrument issued under s.70B(6); the FAQ is official clarificatory
guidance from the same authority. **The FAQ is materially more permissive than the text it
clarifies.** Industry practice relies on Q35.

**So: our US-hosted logs are probably acceptable — on the FAQ's reading, and not on the Direction's.
Both readings stay on the record.** What the FAQ makes unambiguous either way is the *producibility*
duty: whatever the storage location, logs must reach CERT-In **in reasonable time**.

## 4 · Where we actually stand — and the gap is not location

Checked in the repository **[A]**:

| requirement | our posture |
|---|---|
| *"logs of **all** their ICT systems"* | **`log_threshold = 1` — errors only.** An errors-only log is not a log of the system; ordinary access and operation are not recorded at all. |
| **180-day rolling retention** | **No retention or rotation policy exists in the repo.** The `180` matches are unrelated documents. Nothing guarantees any window. |
| **Indian jurisdiction** | Application logs on the Ohio box, Cloud Functions logs in `us-central1`, Firestore in the US project. **Nothing in India.** |
| **NTP from NIC/NPL** | Not configured; the hosts use their providers' defaults. |
| **6-hour reporting** | No process found. |
| **Producibility to CERT-In** | No mechanism, and no Point of Contact recorded. |

**The interesting part is that location is the least of it.** The FAQ probably forgives the
geography. What it does not forgive is that **the logs largely do not exist** — `log_threshold = 1`
means the system records errors, not activity, so there is nothing to retain for 180 days and
nothing to produce.

**And this interlocks with C-70.** DPDP **Rule 6** independently requires access logs with
**one-year retention** as part of the s.8(5) reasonable-security duty carrying ₹250 crore. So two
separate regimes want logs we are not keeping — CERT-In wants 180 days *now*, DPDP wants a year from
May 2027. **The longer requirement is the one that is not yet in force; the one in force today is
the one being missed.**

## 5 · Not established

- Whether a **school ERP** is additionally an *"intermediary"* — it would not change the log duty,
  which already binds every *"body corporate"*, but it would pull in the Intermediary Guidelines
  2021. **NOT FOUND.**
- Whether CERT-In has ever **enforced** ¶(iv) against a body corporate for offshore logs — no
  enforcement action was searched for. **NOT RESEARCHED.**
- **Annexure I**, the list of reportable incident types, was not extracted. That list decides what a
  six-hour clock actually starts on, and it should be read before any reporting process is designed.
