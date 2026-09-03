/* ZenXii Certificate Designer — ported from blueprints/certificates/design/prototype.html
   No bundler (UX_SPEC §14). Plain <script> include. Do not add build steps. */

"use strict";
/* ==========================================================================
   1 · MODEL — mirrors blueprints/certificates/COLLECTION_SHAPES.md §3
   ========================================================================== */
const PAPER = {A4:[210,297], A5:[148,210], Letter:[215.9,279.4], Legal:[215.9,355.6]};

/* P7.2 — the embeddable faces, identical to Doc_serializer::FONT_FACES,
   Doc_renderer::fontData() and the @font-face block in doctemplates.css.
   DocFontParityTest pins those three; this list is what the picker offers.
   It previously offered only 3 of the 7 Lohit families, so a template could
   legitimately reference lohitgujr and no one could select it. */
const FONTS = ["dejavusans","lohitdeva","lohitbeng","lohitgujr","lohitknda",
               "lohitmlym","lohittaml","lohittelu"];

/* Failure to load must show an ERROR, never a silent reflow in a system font.
   A silent fallback is the worst outcome here: the canvas looks fine, the
   designer positions against metrics that will never print, and the divergence
   only surfaces on a real certificate. document.fonts.load() resolves even when
   the face is unavailable, so each family is checked with .check() afterwards. */
async function verifyFonts(){
  if(!document.fonts || !document.fonts.load) return;
  const missing=[];
  for(const f of FONTS){
    if(f==="dejavusans") continue;            // mPDF-bundled; no web face
    try{ await document.fonts.load(`12px "${f}"`); }catch(e){}
    if(!document.fonts.check(`12px "${f}"`)) missing.push(f);
  }
  if(missing.length){
    toast("Font failed to load: "+missing.join(", ")
         +" — the canvas is NOT showing what will print. Do not trust the layout.", true);
    console.error("[doctemplates] font load failed:", missing);
  }
  return missing;
}

const CONTRACT = [
  {key:"school.name",              label:"School name",             sample:"Delhi Public School, Ranchi", p95:"Shri Guru Harkrishan Public Senior Secondary School, Ranchi", maxLen:120},
  {key:"school.address",           label:"School address",          sample:"South Office Para, Doranda, Ranchi 834002", maxLen:160},
  {key:"school.affiliationNo",     label:"Affiliation number",      sample:"3430006", maxLen:16},
  {key:"doc.bookNo",               label:"Book No.",                sample:"14", maxLen:8},
  {key:"doc.slNo",                 label:"Serial No.",              sample:"0207", maxLen:12},
  {key:"student.admissionNumber",  label:"Admission number",        sample:"DPSR/2019/0412", maxLen:32},
  {key:"student.fullName",         label:"Student name",            sample:"Aarav Sharma", p95:"Lakshmi Priyadarshini Venkataraman", maxLen:80},
  {key:"student.fatherName",       label:"Father's name",           sample:"Rakesh Sharma", p95:"Venkataraman Subrahmanya Iyer", maxLen:80},
  {key:"student.motherName",       label:"Mother's name",           sample:"Sunita Sharma", maxLen:80},
  {key:"student.dob",              label:"Date of birth",           sample:"14/08/2011", maxLen:12},
  {key:"student.dobWords",         label:"Date of birth in words",  sample:"Fourteenth August Two Thousand Eleven", maxLen:96},
  {key:"tc.dateOfFirstAdmission",  label:"Date of first admission", sample:"02/04/2019", maxLen:12},
  {key:"tc.lastClassStudied",      label:"Class last studied",      sample:"IX — B", maxLen:24},
  {key:"attendance.workingDays",   label:"Working days",            sample:"221", calc:"attendance", maxLen:4},
  {key:"attendance.daysPresent",   label:"Days present",            sample:"198", calc:"attendance", maxLen:4},
  {key:"result.promotionEligible", label:"Qualified for promotion", sample:"Yes — promoted to Class X", calc:"result", maxLen:64},
  {key:"tc.reasonForLeaving",      label:"Reason for leaving",      sample:"Parent transferred out of station",
     p95:"Parent transferred out of station on Government service; the family is relocating to Bengaluru with effect from the close of the current academic session, and the pupil will continue his studies at the Kendriya Vidyalaya nearest to the new place of posting as intimated by the guardian in writing", maxLen:400},
  {key:"tc.conductRemark",         label:"General conduct",         sample:"Good", maxLen:64},
  {key:"tc.duesPaidUpto",          label:"Fees paid up to",         sample:"March 2026", maxLen:32},
  {key:"tc.dateOfLeaving",         label:"Date of leaving school",  sample:"31/03/2026", maxLen:12},
  {key:"doc.issueDate",            label:"Date of issue",           sample:"04/04/2026", maxLen:12},
  {key:"doc.station",              label:"Station",                 sample:"Ranchi", maxLen:48},
  {key:"sec.fromDate",             label:"Pupil of this school from", sample:"02/04/2019", maxLen:12},
  {key:"sec.toDate",               label:"…to",                     sample:"31/03/2026", maxLen:12},
  {key:"sec.outcome",              label:"Manner of leaving",       sample:"left after having passed from Standard",
     p95:"was removed from the rolls due to long absence while studying in Standard", maxLen:160},
  {key:"student.ageAtLeaving",     label:"Age at leaving",          sample:"16", p95:"21", type:"int", maxLen:3},
  {key:"student.removedFromRolls", label:"Removed from the rolls",  sample:"No",  p95:"Yes", type:"enum", maxLen:8},
  {key:"student.photo",            label:"Student photograph",      sample:"[photo]", type:"image"},
  {key:"doc.verifyQr",             label:"Verification QR",         sample:"[qr]",    type:"image"},
  {key:"doc.isDuplicate",          label:"Issued as a duplicate",   sample:"No",      type:"flag"}
];
const FIELD = Object.fromEntries(CONTRACT.map(f=>[f.key,f]));

/* mergeFieldContracts are PER DOCUMENT TYPE. One global field list is a bug:
   it offers a Kerala school-education certificate the attendance and promotion
   fields of a CBSE transfer certificate, which its contract never declares.
   "The picker is the contract" only means something if the contract is scoped. */
const CONTRACTS = {
  transfer_certificate:["doc.isDuplicate","school.name","school.address","school.affiliationNo","doc.bookNo","doc.slNo",
    "student.admissionNumber","student.fullName","student.fatherName","student.motherName",
    "student.dob","student.dobWords","tc.dateOfFirstAdmission","tc.lastClassStudied",
    "attendance.workingDays","attendance.daysPresent","result.promotionEligible",
    "tc.reasonForLeaving","tc.conductRemark","tc.duesPaidUpto","tc.dateOfLeaving","doc.issueDate"],
  leaving_certificate_5a:["doc.isDuplicate","school.name","school.address","school.affiliationNo","student.admissionNumber","student.fullName",
    "student.fatherName","student.motherName","student.dob","student.dobWords",
    "tc.dateOfFirstAdmission","tc.lastClassStudied","student.ageAtLeaving","student.removedFromRolls",
    "sec.outcome","tc.dateOfLeaving","doc.issueDate","doc.station"],
  school_education_certificate:["doc.isDuplicate","school.name","school.address","school.affiliationNo","student.fullName","student.fatherName",
    "sec.fromDate","sec.toDate","sec.outcome","tc.lastClassStudied","student.dobWords",
    "doc.station","doc.issueDate"],
  bonafide:["school.name","school.address","school.affiliationNo","doc.slNo","student.admissionNumber",
    "student.fullName","student.fatherName","student.dob","tc.lastClassStudied","doc.issueDate",
    "student.photo"],
  character:["doc.isDuplicate","school.name","school.address","school.affiliationNo","student.fullName","student.fatherName",
    "tc.lastClassStudied","tc.conductRemark","tc.dateOfLeaving","doc.issueDate"],
  study:["school.name","student.fullName","student.fatherName","tc.lastClassStudied","doc.issueDate"]
};
/* NOTE (found by this check on its first run): a reusable block imposes its
   bound keys on EVERY document type that uses it. The shared letterhead binds
   school.affiliationNo, so every contract whose starter uses that block must
   declare it. Blocks and contracts are coupled, and the coupling is one-way. */
function contractFor(docType){
  const keys=CONTRACTS[docType||(S.tpl&&S.tpl.docType)||S.docType];
  return keys ? keys.map(k=>FIELD[k]).filter(Boolean) : CONTRACT;
}
/* a key a template uses that its own contract does not declare — the
   mail-merge failure the append-only rule exists to prevent */
function offContractKeys(){
  const declared=new Set(contractFor().map(f=>f.key));
  return [...boundKeys()].filter(k=>!declared.has(k));
}

/* documentTypes/{typeId}.complianceProfiles — ILLUSTRATIVE.
   The real CBSE requiredKeys list is transcribed at gate 0.3, signed off at 0.8. */
const PROFILES = {
  cbse:{
    id:"cbse", version:4, name:"CBSE — Transfer Certificate",
    scope:"Board: CBSE · all states",
    authority:"CBSE Examination Bye-Laws, Annexure-I",
    evidence:"A", verifiedOn:"2026-08-18", owner:"platform-compliance", reviewMonths:12,
    requiredKeys:[
      "school.name","doc.bookNo","doc.slNo","student.admissionNumber","student.fullName",
      "student.fatherName","student.motherName","student.dob","student.dobWords",
      "tc.dateOfFirstAdmission","tc.lastClassStudied","attendance.workingDays",
      "attendance.daysPresent","result.promotionEligible","tc.reasonForLeaving",
      "tc.conductRemark","tc.duesPaidUpto","tc.dateOfLeaving","doc.issueDate"
    ],
    requiredSignatures:["class_teacher","checked_by","principal"],
    sealRequired:true, illustrative:true
  },
  generic:{
    id:"generic", version:1, name:"Generic — no verified profile",
    scope:"Fallback · any board or state with no transcribed authority",
    authority:null, evidence:null, verifiedOn:null, owner:null,
    requiredKeys:[], requiredSignatures:[], sealRequired:false
  }
};
const LANGS = {en:{native:"English"}, hi:{native:"हिन्दी"}};

/* ==========================================================================
   1b · COMPLIANCE AUTHORITIES — a STACK, not one profile
   A school is never under a single authority. A CBSE school in Kerala is bound
   by CBSE's bye-laws AND the Kerala Education Rules AND the RTE Act, each with
   its own scope. One `complianceProfileId` cannot express that, so the model
   here is: national ∪ board ∪ state, resolved from where the school actually is.
   Every rule renders the authority it came from and its evidence level.
   ========================================================================== */
const BOARDS = ["CBSE","CISCE (ICSE)","State Board","NIOS","Other / unaffiliated"];
const STATES = ["Andhra Pradesh","Assam","Bihar","Delhi","Gujarat","Haryana","Jharkhand",
  "Karnataka","Kerala","Madhya Pradesh","Maharashtra","Odisha","Punjab","Rajasthan",
  "Tamil Nadu","Telangana","Uttar Pradesh","West Bengal"];
const STAGES = {elementary:"Classes I–VIII (elementary)", secondary:"Classes IX–XII (secondary)", both:"Classes I–XII"};

/* evidence levels: A verified primary text · B cited, primary text not read
   this pass · C practice · D our recommendation */
const AUTHORITIES = [
  {
    id:"rte", tier:"national", label:"RTE Act 2009",
    authority:"Right of Children to Free and Compulsory Education Act 2009, s.5(3)",
    evidence:"A", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.stage!=="secondary",
    scopeNote:"Elementary stage only — classes I–VIII. Does not reach IX–XII.",
    docs:{ transfer_certificate:{ requiredKeys:[], constraints:[
      "The transfer certificate must be issued immediately on request. It cannot be withheld for any reason, including unpaid fees.",
      "Delay or refusal exposes the head teacher to disciplinary action.",
      "No numeric turnaround deadline and no issuance register are set by the Act — any SLA we ship is our own recommendation, not law."
    ]}}
  },
  {
    id:"cbse", tier:"board", label:"CBSE",
    authority:"CBSE Examination Bye-Laws, Annexure-I",
    evidence:"A", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.board==="CBSE",
    scopeNote:"Binds CBSE-affiliated schools in every state.",
    fieldListVerified:false,
    docs:{
      transfer_certificate:{
        requiredKeys:["school.name","doc.bookNo","doc.slNo","student.admissionNumber","student.fullName",
          "student.fatherName","student.motherName","student.dob","student.dobWords",
          "tc.dateOfFirstAdmission","tc.lastClassStudied","attendance.workingDays",
          "attendance.daysPresent","result.promotionEligible","tc.reasonForLeaving",
          "tc.conductRemark","tc.duesPaidUpto","tc.dateOfLeaving","doc.issueDate"],
        requiredSignatures:["class_teacher","checked_by","principal"], sealRequired:true,
        constraints:[
          "22 mandated fields, plus pre-printed Book No. and Sl. No. on the stationery.",
          "Signature block is Class Teacher → Checked by → Principal, plus a school seal.",
          "A TC originating outside CBSE additionally needs a countersignature (r.8(vii))."
        ],
        duplicateMark:{required:true, text:"Duplicate", citation:"CBSE r.8(vi)",
          quote:"…it shall always be so marked."},
        illustrative:true
      },
      bonafide:{requiredKeys:[], constraints:[]},
      character:{requiredKeys:[], constraints:[]}
    }
  },
  {
    id:"cisce", tier:"board", label:"CISCE (ICSE)",
    authority:"CISCE Regulations, January 2026 edition",
    evidence:"A", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.board==="CISCE (ICSE)",
    scopeNote:"Binds CISCE-affiliated schools.",
    docs:{ transfer_certificate:{ requiredKeys:[], constraints:[
      "CISCE prescribes no TC format. It regulates the TC's CONTENT, not its layout.",
      "\"Promoted to Class X\" may not be printed unless promotion criteria are met — at least 33% in five subjects including English, and at least 75% attendance.",
      "Corrections route only through the Head of Institution, never direct from a student or parent, and require three attested artefacts."
    ]}}
  },
  {
    id:"ker", tier:"state", label:"Kerala Education Rules 1959", state:"Kerala",
    authority:"Kerala Education Rules 1959, Chapter VI",
    evidence:"A", verifiedOn:"2026-08-18", owner:"platform-compliance",
    sourceRef:"education.kerala.gov.in/wp-content/uploads/2019/11/Chapter_6.pdf",
    appliesWhen:sc=>sc.state==="Kerala",
    scopeNote:"Binds schools in Kerala, alongside any board affiliation.",
    docs:{
      transfer_certificate:{
        form:"Form 5", requiredKeys:[], fieldListVerified:false,
        constraints:[
          "r.17(1) — the transfer certificate is issued in FORM 5, by the Headmaster, during the summer vacation or at other times for sufficient reason; at any time for pupils who have sat a public examination.",
          "r.17(2) — \"No transfer certificate shall be issued to a pupil from whom there are any dues to the school.\" ⚖ Read down by the courts: Kerala HC 2025:KER:69076 refused to let a dues rule justify withholding; Madras HC (DB) 22.07.2024 held a TC \"is not a tool for the schools to collect arrear fees\". Never default-on, and impossible for classes I–VIII.",
          "r.17(3) — a pupil removed from the rolls who is over 20 gets NOT a transfer certificate but a leaving certificate in FORM 5A. These are different instruments; do not alias them.",
          "r.20 — refusal or delay gives the parent a right of appeal to the Educational Officer.",
          "r.21 — where the Director has grouped neighbouring schools, TCs between them may be barred.",
          "r.22 — a duplicate may issue on loss or irremediable damage, on a fee and an attestation by a Gazetted Officer, local-authority President, MLA or MP, and \"should be clearly marked 'Duplicate'\"."
        ],
        duplicateMark:{required:true, text:"Duplicate", citation:"KER r.22",
          quote:"Duplicate certificate issued should be clearly marked 'Duplicate'."},
        note:"Form 5's own field list is printed in an appendix we have not retrieved. Nothing is enforced here beyond the constraints above.",
        /* the first rule that changes WHICH document you are issuing, not what
           it must contain. Compliance as routing, not just validation. */
        routesTo:[{
          toType:"leaving_certificate_5a",
          label:"Leaving Certificate (Form 5A)",
          citation:"KER r.17(3)",
          plain:"A pupil removed from the rolls who is over 20 may not be given a transfer certificate at all.",
          test:()=>{
            const age=parseInt(fieldValue("student.ageAtLeaving")||"0",10);
            const rem=String(fieldValue("student.removedFromRolls")||"").toLowerCase();
            return age>20 && rem.startsWith("y");
          },
          testLabel:"age at leaving > 20 and removed from the rolls"
        }]
      },
      leaving_certificate_5a:{
        form:"Form 5A", requiredKeys:[], fieldListVerified:false,
        constraints:[
          "r.17(3) — issued in place of a transfer certificate to a pupil removed from the rolls who is over 20. It is a distinct statutory instrument, not a renamed TC.",
          "Because it is issued where a TC may NOT be, aliasing the two would collapse two different legal documents into one."
        ],
        note:"Form 5A's field list is in the same unretrieved appendix as Form 5. Nothing is enforced beyond the constraints above."
      },
      school_education_certificate:{
        form:"r.22A", requiredKeys:["student.fullName","student.fatherName","sec.fromDate","sec.toDate",
          "sec.outcome","tc.lastClassStudied","student.dobWords","doc.station","doc.issueDate"],
        requiredSignatures:["headmaster"], sealRequired:true, fieldListVerified:true,
        constraints:[
          "r.22A — issued to a pupil who left before appearing for the S.S.L.C. examination, on application and on remittance of the prescribed fee.",
          "The daughters of widows are exempt from the fee where the certificate supports an application for marriage financial assistance — and the certificate must say that this is its purpose.",
          "The form's wording is prescribed in the rule itself: name in block capitals with full address, parentage, the from/to period, the manner of leaving (passed / removed for long absence / discontinued after failing), the standard in words, and the date of birth in words as per school records."
        ]
      }
    }
  },
  {
    id:"tner", tier:"state", label:"Tamil Nadu Educational Rules", state:"Tamil Nadu",
    authority:"Tamil Nadu Educational Rules, rr. 34, 40–42, 44 (Appendix 5-B)",
    evidence:"B", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.state==="Tamil Nadu",
    scopeNote:"Cited from the research corpus; the primary rule text was not read in this pass.",
    docs:{
      transfer_certificate:{requiredKeys:[],
        duplicateMark:{required:true, text:"Duplicate", colour:"#C0392B", onceOnly:true,
          citation:"TNER r.44",
          quote:"…shall clearly bear the mark duplicate in red ink. It shall be issued only once."},
        constraints:[
        "rr.40–42 govern transfer certificates.",
        "r.44 — a duplicate \"shall clearly bear the mark duplicate in red ink. It shall be issued only once.\" The reissue count is therefore a gated field, not a counter.",
        "App. 5 field 15(a) carries attendance as certified content inside the TC."
      ]},
      character:{requiredKeys:[], constraints:[
        "r.34 / Appendix 5-B prescribes a \"Conduct Certificate\" that certifies conduct AND character in one form. This is why we treat character and conduct as one entity with a configurable name."
      ]}
    }
  },
  {
    id:"dser", tier:"state", label:"Delhi School Education Rules 1973", state:"Delhi",
    authority:"Delhi School Education Act & Rules 1973, rr.139, 167",
    evidence:"A", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.state==="Delhi",
    scopeNote:"A verified NEGATIVE finding — the absence of a rule is itself the finding.",
    docs:{ transfer_certificate:{requiredKeys:[], constraints:[
      "r.139 lists transfer certificate / school leaving certificate / leaving certificate as alternative names for one instrument.",
      "The Act and Rules contain NO provision on issuing transfer certificates at all (Delhi HC, LPA 393/2014). r.167 only permits striking a name off the rolls.",
      "Consequence: a Delhi school has no state rule to rely on for a no-dues gate. Offering one here would assert law that does not exist."
    ]}}
  },
  {
    id:"apgo", tier:"state", label:"Andhra Pradesh G.O.P. 646", state:"Andhra Pradesh",
    authority:"A.P. G.O.P. 646 dt. 10.07.1979, para 9 (under Art. 371-D)",
    evidence:"B", verifiedOn:"2026-08-16", owner:"platform-compliance",
    appliesWhen:sc=>sc.state==="Andhra Pradesh",
    scopeNote:"Names the Study Certificate as a distinct, retrospective instrument.",
    docs:{ study:{requiredKeys:[], constraints:[
      "The Study Certificate is retrospective and year-by-year, and is named in law. It is NOT the same document as a bonafide certificate, which is present-tense and has no statutory basis we could find."
    ]}}
  }
];

const SCHOOL_DEFAULT = {name:"Delhi Public School, Ranchi", board:"CBSE", state:"Jharkhand", stage:"both"};

/* the school's own templates. Exactly one may be ACTIVE per document type —
   that is the one every print point resolves, so activation is a real act
   with a real consequence, and it gets its own confirmation. */
const LIB = {
  transfer_certificate:[
    {id:"TPL0007", name:"TC — main letterhead", starter:"tc_cbse",
     status:"published", version:3, publishedVersion:2, edited:"2 days ago"},
    {id:"TPL0011", name:"TC — bilingual (हिन्दी)", starter:"tc_cbse", lang:"hi",
     status:"published", version:2, publishedVersion:1, edited:"6 weeks ago"}
  ],
  bonafide:[
    {id:"TPL0009", name:"Bonafide — classic", starter:"bonafide",
     status:"published", version:1, publishedVersion:1, edited:"3 weeks ago"}
  ],
  character:[],
  school_education_certificate:[]
};
const ACTIVE = {transfer_certificate:"TPL0007", bonafide:"TPL0009"};

/* resolution: national ∪ board ∪ state, minus anything the operator switched off */
function resolveStack(docType, sc){
  sc = sc || S.school;
  const dt = docType || S.docType;
  const out=[];
  AUTHORITIES.forEach(a=>{
    if(!a.appliesWhen(sc)) return;
    const rule=a.docs[dt];
    if(!rule) return;
    out.push({a, rule, off: !!S.layerOff[a.id]});
  });
  return out;
}
function stackActive(docType, sc){ return resolveStack(docType, sc).filter(l=>!l.off); }
function stateHasAuthority(sc){
  return AUTHORITIES.some(a=>a.tier==="state" && a.appliesWhen(sc||S.school));
}
function requiredKeysOf(docType){
  const set=new Set();
  stackActive(docType).forEach(l=>(l.rule.requiredKeys||[]).forEach(k=>set.add(k)));
  return [...set];
}
function keyAuthority(key, docType){
  const l=stackActive(docType).find(x=>(x.rule.requiredKeys||[]).includes(key));
  return l ? l.a : null;
}
/* Two kinds of image, which one "image" object type wrongly conflates:
   STATIC school chrome (crest, signature, seal, watermark) — uploaded once,
   identical on every document, belongs with reusable blocks; and DATA-BOUND
   images (student photograph, verification QR) — resolved per document from
   the merge contract. A signature is static. A photograph is not. */
const ASSET_KINDS = {
  crest:     {label:"School crest",     hint:"Letterhead chrome. Same on every document."},
  signature: {label:"Signature",        hint:"A scanned image is NOT an electronic signature — see the note below."},
  seal:      {label:"Seal / stamp",     hint:"Prefer a transparent PNG so the ruled line shows through."},
  watermark: {label:"Watermark",        hint:"Prints behind the text. Keep it light."},
  photo:     {label:"Photograph",       hint:"Usually data-bound, not uploaded once."}
};
const MIN_DPI = 150;                       /* below this, print quality shows */
function assetDpi(o){
  if(!o.asset || !o.asset.wPx || !o.wMm) return null;
  return Math.round(o.asset.wPx / (o.wMm/25.4));
}

const EVIDENCE_RANK={A:4,B:3,C:2,D:1};

/* Language is a MODE, resolved by inheritance, not a flat per-object map.
   Figma: an object set to Auto walks up to the nearest ancestor with an
   explicit mode, then falls back to the collection default. Ours walks
   object -> region -> template. That makes "Hindi letterhead over an English
   body" one template with one string set per object, which the blueprint had
   costed as a high-effort v2 item. */
function langOf(o){
  if(o && o.lang) return o.lang;
  const rl=(S.tpl.regionLang||{})[o&&o.region?o.region:"body"];
  if(rl) return rl;
  return S.lang;
}
const langExplicit = o => !!(o && (o.lang || (S.tpl.regionLang||{})[o.region||"body"]));
/* compatibility shim — the rest of the UI still asks for "the profile" */
function prof(){
  const st=stackActive();
  if(!st.length) return PROFILES.generic;
  const best=st.slice().sort((x,y)=>(EVIDENCE_RANK[y.a.evidence]||0)-(EVIDENCE_RANK[x.a.evidence]||0))[0];
  const sigs=new Set(); let seal=false, illus=false;
  st.forEach(l=>{ (l.rule.requiredSignatures||[]).forEach(x=>sigs.add(x));
    if(l.rule.sealRequired) seal=true; if(l.rule.illustrative) illus=true; });
  return {
    id:"stack", name:st.map(l=>l.a.label).join(" + "),
    scope:st.map(l=>l.a.scopeNote).join(" "),
    authority:best.a.authority, evidence:best.a.evidence, verifiedOn:best.a.verifiedOn,
    owner:best.a.owner, reviewMonths:12,
    requiredKeys:requiredKeysOf(), requiredSignatures:[...sigs], sealRequired:seal, illustrative:illus
  };
}

/* reusableBlocks — a platform/school edit bumps `version`; consumers are
   OFFERED the change, never silently mutated (see FIGMA_ARCHITECTURE_STUDY §1) */
const BLOCKS = [
  {id:"BLK0001", type:"letterhead", name:"Main letterhead", version:3, objects:4, usedBy:3},
  {id:"BLK0002", type:"signature",  name:"Signature block", version:1, objects:3, usedBy:2},
  {id:"BLK0003", type:"seal",       name:"School seal",     version:2, objects:1, usedBy:3}
];

/* run model — a compressed stand-in for a Quill Delta.
   {t:"..."} literal (optional b/i/u) · {f:"key"} a MergeField embed (void node) */
const T = (...runs)=>({runs});

function starterTC(){
  const rows = ["student.admissionNumber","student.fullName","student.fatherName","student.motherName",
    "student.dob","student.dobWords","tc.dateOfFirstAdmission","tc.lastClassStudied",
    "attendance.workingDays","attendance.daysPresent","result.promotionEligible",
    "tc.conductRemark","tc.duesPaidUpto","tc.dateOfLeaving"];
  return {
    templateId:"TPL0007", schoolId:"SCH_D94FE8F7AD", docType:"transfer_certificate",
    name:"TC — main letterhead", status:"draft", version:3, publishedVersion:2,
    activeVersion:2, lockVersion:17,
    complianceProfileId:"cbse", complianceProfileVersion:4, contractRef:"transfer_certificate_v3",
    languages:["en","hi"], defaultLanguage:"en", languageFallback:"block",
    page:{size:"A4", orientation:"portrait", marginsMm:{t:42,r:15,b:16,l:15}, pageMode:"single"},
    objects:[
      {id:"h_logo", name:"School crest", region:"header", type:"image", xMm:15,yMm:8,wMm:20,hMm:20, z:1,
       height:"fixed", content:{label:"School crest"}, style:{}},
      {id:"h_name", name:"School name", region:"header", type:"text", xMm:40,yMm:9,wMm:155,hMm:9, z:2,
       height:"auto", requiredKey:"school.name",
       style:{sizePt:16, lineHeight:1.2, weight:700, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({f:"school.name"}), hi:T({f:"school.name"})}}},
      {id:"h_addr", name:"Address line", region:"header", type:"text", xMm:40,yMm:20,wMm:155,hMm:9, z:2,
       height:"auto",
       style:{sizePt:8, lineHeight:1.35, weight:400, align:"left", colour:"#4A3C33"},
       content:{i18n:{
         en:T({f:"school.address"},{t:"  ·  Affiliation No. "},{f:"school.affiliationNo"}),
         hi:T({f:"school.address"},{t:"  ·  संबद्धता क्रमांक "},{f:"school.affiliationNo"})}}},
      {id:"h_rule", name:"Letterhead rule", region:"header", type:"shape", xMm:15,yMm:33,wMm:180,hMm:0.6, z:1,
       height:"fixed", content:{shape:"line"}, style:{colour:"#14100D"}},

      {id:"t_title", name:"Title", type:"text", xMm:15,yMm:46,wMm:180,hMm:8, z:3, height:"auto",
       style:{sizePt:14, lineHeight:1.25, weight:700, align:"center", colour:"#14100D", track:".14em"},
       content:{i18n:{en:T({t:"TRANSFER CERTIFICATE"}), hi:T({t:"स्थानांतरण प्रमाणपत्र"})}}},
      {id:"t_book", name:"Book / Sl. No.", type:"text", xMm:15,yMm:57,wMm:180,hMm:6, z:3, height:"auto",
       requiredKey:"doc.bookNo",
       style:{sizePt:9, lineHeight:1.35, weight:600, align:"left", colour:"#14100D"},
       content:{i18n:{
         en:T({t:"Book No. "},{f:"doc.bookNo"},{t:"          Sl. No. "},{f:"doc.slNo"}),
         hi:T({t:"पुस्तक सं. "},{f:"doc.bookNo"},{t:"          क्रम सं. "},{f:"doc.slNo"})}}},
      {id:"t_table", name:"Particulars table", type:"table", xMm:15,yMm:66,wMm:180,hMm:120, z:3, height:"auto",
       style:{sizePt:9, lineHeight:1.55, colour:"#14100D"},
       content:{rows:rows.map(k=>({key:k}))}},
      {id:"t_reason", name:"Reason for leaving", type:"text", xMm:15,yMm:190,wMm:180,hMm:6, z:3, height:"auto",
       anchorTo:"t_table", anchorGapMm:5, requiredKey:"tc.reasonForLeaving", maxHMm:12,
       style:{sizePt:9, lineHeight:1.55, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{
         en:T({t:"15.  Reason for leaving the school: "},{f:"tc.reasonForLeaving"}),
         hi:T({t:"15.  विद्यालय छोड़ने का कारण: "},{f:"tc.reasonForLeaving"})}}},
      {id:"t_issue", name:"Date of issue", type:"text", xMm:15,yMm:204,wMm:180,hMm:6, z:3, height:"auto",
       anchorTo:"t_reason", anchorGapMm:4, requiredKey:"doc.issueDate",
       style:{sizePt:9, lineHeight:1.55, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{
         en:T({t:"16.  Date of issue of this certificate: "},{f:"doc.issueDate"}),
         hi:T({t:"16.  प्रमाणपत्र जारी करने की तिथि: "},{f:"doc.issueDate"})}}},
      {id:"t_declare", name:"Declaration", type:"text", xMm:15,yMm:214,wMm:180,hMm:10, z:3, height:"auto",
       anchorTo:"t_issue", anchorGapMm:6,
       style:{sizePt:8, lineHeight:1.5, weight:400, align:"left", colour:"#4A3C33"},
       content:{i18n:{
         en:T({t:"Certified that the above particulars are true to the best of my knowledge and are taken from the Admission and Withdrawal Register of this school."}),
         hi:T({t:"प्रमाणित किया जाता है कि उपर्युक्त विवरण मेरी जानकारी के अनुसार सत्य हैं तथा विद्यालय के प्रवेश एवं निष्कासन पंजिका से लिए गए हैं।"})}}},

      {id:"sig_ct", sigRole:"class_teacher", name:"Sig · Class Teacher", type:"text", xMm:15,yMm:252,wMm:52,hMm:8, z:3, height:"fixed",
       style:{sizePt:8, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Class Teacher"}), hi:T({t:"कक्षा अध्यापक"})}}},
      {id:"sig_ck", sigRole:"checked_by", name:"Sig · Checked by", type:"text", xMm:79,yMm:252,wMm:52,hMm:8, z:3, height:"fixed",
       showWhen:"tc.duesPaidUpto",
       style:{sizePt:8, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Checked by"}), hi:T({t:"जाँचकर्ता"})}}},
      {id:"sig_pr", sigRole:"principal", name:"Sig · Principal", type:"text", xMm:143,yMm:252,wMm:52,hMm:8, z:3, height:"fixed",
       style:{sizePt:8, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Principal"}), hi:T({t:"प्रधानाचार्य"})}}},
      {id:"o_seal", name:"School seal", type:"shape", xMm:88,yMm:228,wMm:34,hMm:34, z:2, height:"fixed",
       content:{shape:"seal"}, style:{}},

      {id:"t_dup", name:"Duplicate mark", type:"text", xMm:130,yMm:38,wMm:65,hMm:8, z:8,
       height:"auto", showWhen:"doc.isDuplicate", isDuplicateMark:true,
       style:{sizePt:15, lineHeight:1.2, weight:700, align:"right", colour:"#C0392B", track:".22em"},
       content:{i18n:{en:T({t:"DUPLICATE"}), hi:T({t:"द्वितीय प्रति"})}}},
      {id:"f_no", name:"Page number", region:"footer", type:"pageNumber", xMm:15,yMm:4,wMm:180,hMm:5, z:1,
       height:"fixed", style:{sizePt:7.5, lineHeight:1.3, align:"center", colour:"#7A6A60"},
       content:{format:"Page {n} of {t}"}}
    ]
  };
}

/* ── starter templates ──────────────────────────────────────────────────
   Authored, not sliced. Each declares the board/state it is written for, and
   the gallery offers only the ones that match the school's resolved basis. */
function hdr(){
  return [
    {id:"h_logo", name:"School crest", region:"header", type:"image", xMm:15,yMm:8,wMm:20,hMm:20, z:1,
     height:"fixed", assetKind:"crest", asset:null, content:{label:"School crest"}, style:{}},
    {id:"h_name", name:"School name", region:"header", type:"text", xMm:40,yMm:9,wMm:155,hMm:9, z:2,
     height:"auto", requiredKey:"school.name",
     style:{sizePt:16, lineHeight:1.2, weight:700, align:"left", colour:"#14100D"},
     content:{i18n:{en:T({f:"school.name"}), hi:T({f:"school.name"})}}},
    {id:"h_addr", name:"Address line", region:"header", type:"text", xMm:40,yMm:20,wMm:155,hMm:9, z:2,
     height:"auto",
     style:{sizePt:8, lineHeight:1.35, weight:400, align:"left", colour:"#4A3C33"},
     content:{i18n:{
       en:T({f:"school.address"},{t:"  ·  Affiliation No. "},{f:"school.affiliationNo"}),
       hi:T({f:"school.address"},{t:"  ·  संबद्धता क्रमांक "},{f:"school.affiliationNo"})}}},
    {id:"h_rule", name:"Letterhead rule", region:"header", type:"shape", xMm:15,yMm:33,wMm:180,hMm:0.6, z:1,
     height:"fixed", content:{shape:"line"}, style:{colour:"#14100D"}}
  ];
}
const ftr = ()=>[{id:"f_no", name:"Page number", region:"footer", type:"pageNumber",
  xMm:15,yMm:4,wMm:180,hMm:5, z:1, height:"fixed",
  style:{sizePt:7.5, lineHeight:1.3, align:"center", colour:"#7A6A60"},
  content:{format:"Page {n} of {t}"}}];

/* Kerala Education Rules 1959, r.22A — the wording below is the form printed
   in the rule itself, transcribed from education.kerala.gov.in Chapter VI.
   This is the one state document whose field list we hold at Level A. */
function starterKeralaSEC(){
  return {
    templateId:"TPL0021", schoolId:"SCH_D94FE8F7AD", docType:"school_education_certificate",
    name:"Certificate of School Education — KER r.22A", status:"draft", version:1,
    publishedVersion:null, activeVersion:null, lockVersion:1,
    contractRef:"school_education_certificate_v1",
    languages:["en"], defaultLanguage:"en", languageFallback:"block",
    page:{size:"A4", orientation:"portrait", marginsMm:{t:42,r:18,b:16,l:18}, pageMode:"single"},
    objects:[...hdr(),
      {id:"t_title", name:"Title", type:"text", xMm:18,yMm:50,wMm:174,hMm:8, z:3, height:"auto",
       style:{sizePt:13, lineHeight:1.3, weight:700, align:"center", colour:"#14100D", track:".12em"},
       content:{i18n:{en:T({t:"CERTIFICATE OF SCHOOL EDUCATION"})}}},
      {id:"t_body", name:"Certifying paragraph", type:"text", xMm:18,yMm:66,wMm:174,hMm:20, z:3,
       height:"auto", maxHMm:44, requiredKey:"student.fullName",
       style:{sizePt:10.5, lineHeight:1.9, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T(
         {t:"This is to certify that "},{f:"student.fullName"},
         {t:" son/daughter of "},{f:"student.fatherName"},
         {t:" was pupil of this school from "},{f:"sec.fromDate"},
         {t:" to "},{f:"sec.toDate"},{t:" and that he/she "},{f:"sec.outcome"},
         {t:" "},{f:"tc.lastClassStudied"},{t:" (in words)."})}}},
      {id:"t_dob", name:"Date of birth", type:"text", xMm:18,yMm:96,wMm:174,hMm:8, z:3, height:"auto",
       anchorTo:"t_body", anchorGapMm:6, requiredKey:"student.dobWords",
       style:{sizePt:10.5, lineHeight:1.9, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"His/Her date of birth is "},{f:"student.dobWords"},
         {t:" (in words) as per school records."})}}},
      {id:"t_station", name:"Station", type:"text", xMm:18,yMm:150,wMm:70,hMm:7, z:3, height:"auto",
       requiredKey:"doc.station",
       style:{sizePt:10, lineHeight:1.8, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"Station: "},{f:"doc.station"})}}},
      {id:"t_date", name:"Date", type:"text", xMm:18,yMm:158,wMm:70,hMm:7, z:3, height:"auto",
       requiredKey:"doc.issueDate",
       style:{sizePt:10, lineHeight:1.8, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"Date: "},{f:"doc.issueDate"})}}},
      {id:"o_seal", name:"School seal", type:"shape", xMm:96,yMm:146,wMm:28,hMm:28, z:2,
       height:"fixed", content:{shape:"seal"}, style:{}},
      {id:"sig_hm", sigRole:"headmaster", name:"Sig · Headmaster", type:"text", xMm:132,yMm:166,wMm:60,hMm:8, z:3,
       height:"fixed",
       style:{sizePt:9.5, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Headmaster"})}}},
      {id:"t_note", name:"Footnote", type:"text", xMm:18,yMm:196,wMm:174,hMm:8, z:3, height:"auto",
       style:{sizePt:7.5, lineHeight:1.5, weight:400, align:"left", colour:"#6B5346"},
       content:{i18n:{en:T({t:"* Enter the name of the pupil in block capitals with full address. Issued to a pupil who left before appearing for the S.S.L.C. Examination, on application and on remittance of the prescribed fee. The daughters of widows are exempt from the fee where the certificate supports an application for marriage financial assistance, and the certificate must say so."})}}},
      ...ftr()]
  };
}

function starterBonafide(){
  return {
    templateId:"TPL0031", schoolId:"SCH_D94FE8F7AD", docType:"bonafide",
    name:"Bonafide — classic", status:"draft", version:1,
    publishedVersion:null, activeVersion:null, lockVersion:1,
    contractRef:"bonafide_v1", languages:["en","hi"], defaultLanguage:"en", languageFallback:"block",
    page:{size:"A4", orientation:"portrait", marginsMm:{t:42,r:18,b:16,l:18}, pageMode:"single"},
    objects:[...hdr(),
      {id:"t_title", name:"Title", type:"text", xMm:18,yMm:52,wMm:174,hMm:8, z:3, height:"auto",
       style:{sizePt:13, lineHeight:1.3, weight:700, align:"center", colour:"#14100D", track:".12em"},
       content:{i18n:{en:T({t:"BONAFIDE CERTIFICATE"}), hi:T({t:"बोनाफाइड प्रमाणपत्र"})}}},
      {id:"t_no", name:"Reference no.", type:"text", xMm:18,yMm:66,wMm:174,hMm:6, z:3, height:"auto",
       style:{sizePt:9, lineHeight:1.5, weight:600, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"Ref. No. "},{f:"doc.slNo"},{t:"          Date: "},{f:"doc.issueDate"}),
                      hi:T({t:"संदर्भ सं. "},{f:"doc.slNo"},{t:"          दिनांक: "},{f:"doc.issueDate"})}}},
      {id:"t_body", name:"Certifying paragraph", type:"text", xMm:18,yMm:80,wMm:174,hMm:18, z:3,
       height:"auto", maxHMm:40,
       style:{sizePt:10.5, lineHeight:1.9, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{
         en:T({t:"This is to certify that "},{f:"student.fullName"},{t:", son/daughter of "},
              {f:"student.fatherName"},{t:", bearing Admission No. "},{f:"student.admissionNumber"},
              {t:", is a bonafide student of this school and is presently studying in Class "},
              {f:"tc.lastClassStudied"},{t:". His/Her date of birth as recorded in the Admission Register is "},
              {f:"student.dob"},{t:"."}),
         hi:T({t:"प्रमाणित किया जाता है कि "},{f:"student.fullName"},{t:", पिता/माता "},
              {f:"student.fatherName"},{t:", प्रवेश संख्या "},{f:"student.admissionNumber"},
              {t:", इस विद्यालय के नियमित छात्र/छात्रा हैं तथा वर्तमान में कक्षा "},
              {f:"tc.lastClassStudied"},{t:" में अध्ययनरत हैं।"})}}},
      {id:"t_purpose", name:"Purpose", type:"text", xMm:18,yMm:104,wMm:174,hMm:8, z:3, height:"auto",
       anchorTo:"t_body", anchorGapMm:6,
       style:{sizePt:10.5, lineHeight:1.9, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"This certificate is issued on request for official purposes."}),
                      hi:T({t:"यह प्रमाणपत्र अनुरोध पर शासकीय प्रयोजन हेतु जारी किया गया है।"})}}},
      {id:"o_seal", name:"School seal", type:"shape", xMm:24,yMm:150,wMm:28,hMm:28, z:2,
       height:"fixed", content:{shape:"seal"}, style:{}},
      {id:"sig_pr", name:"Sig · Principal", type:"text", xMm:132,yMm:168,wMm:60,hMm:8, z:3, height:"fixed",
       style:{sizePt:9.5, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Principal"}), hi:T({t:"प्रधानाचार्य"})}}},
      ...ftr()]
  };
}

/* Tamil Nadu Educational Rules r.34 / Appendix 5-B certifies conduct AND
   character in one form — which is why this is one entity, not two. */
function starterConduct(){
  return {
    templateId:"TPL0041", schoolId:"SCH_D94FE8F7AD", docType:"character",
    name:"Conduct Certificate — TNER shape", status:"draft", version:1,
    publishedVersion:null, activeVersion:null, lockVersion:1,
    contractRef:"character_v1", languages:["en"], defaultLanguage:"en", languageFallback:"block",
    page:{size:"A4", orientation:"portrait", marginsMm:{t:42,r:18,b:16,l:18}, pageMode:"single"},
    objects:[...hdr(),
      {id:"t_title", name:"Title", type:"text", xMm:18,yMm:52,wMm:174,hMm:8, z:3, height:"auto",
       style:{sizePt:13, lineHeight:1.3, weight:700, align:"center", colour:"#14100D", track:".12em"},
       content:{i18n:{en:T({t:"CONDUCT AND CHARACTER CERTIFICATE"})}}},
      {id:"t_body", name:"Certifying paragraph", type:"text", xMm:18,yMm:70,wMm:174,hMm:18, z:3,
       height:"auto", maxHMm:40,
       style:{sizePt:10.5, lineHeight:1.9, weight:400, align:"left", colour:"#14100D"},
       content:{i18n:{en:T({t:"This is to certify that "},{f:"student.fullName"},
         {t:", son/daughter of "},{f:"student.fatherName"},{t:", studied in this school up to Class "},
         {f:"tc.lastClassStudied"},{t:" and left on "},{f:"tc.dateOfLeaving"},
         {t:". During the period of study his/her conduct and character were "},
         {f:"tc.conductRemark"},{t:"."})}}},
      {id:"o_seal", name:"School seal", type:"shape", xMm:24,yMm:140,wMm:28,hMm:28, z:2,
       height:"fixed", content:{shape:"seal"}, style:{}},
      {id:"sig_pr", name:"Sig · Principal", type:"text", xMm:132,yMm:158,wMm:60,hMm:8, z:3, height:"fixed",
       style:{sizePt:9.5, lineHeight:1.4, weight:600, align:"center", colour:"#14100D", topRule:true},
       content:{i18n:{en:T({t:"Principal"})}}},
      ...ftr()]
  };
}

/* a state-board TC: no Book/Sl. No. axis, prose form, nothing enforced beyond
   whatever the resolved state authority contributes */
function starterTCplain(){
  const t=starterTC();
  t.templateId="TPL0051"; t.name="TC — plain letterhead"; t.version=1;
  t.publishedVersion=null; t.activeVersion=null;
  t.objects=t.objects.filter(o=>o.id!=="t_book");
  t.objects.forEach(o=>{ if(o.id==="t_title")
    o.content.i18n.en=T({t:"TRANSFER / SCHOOL LEAVING CERTIFICATE"}); });
  return t;
}

function starterForm5A(){
  const t=starterTC();
  t.templateId="TPL0061"; t.docType="leaving_certificate_5a";
  t.name="Leaving Certificate — Form 5A"; t.version=1;
  t.publishedVersion=null; t.activeVersion=null; t.languages=["en"];
  /* a TC's promotion and attendance block does not belong on a Form 5A: it is
     issued to a pupil removed from the rolls, not one being promoted onward */
  t.objects=t.objects.filter(o=>!["t_book"].includes(o.id));
  t.objects.forEach(o=>{
    if(o.id==="t_title") o.content.i18n.en=T({t:"LEAVING CERTIFICATE"});
    if(o.id==="t_table") o.content.rows=[
      {key:"student.admissionNumber"},{key:"student.fullName"},{key:"student.fatherName"},
      {key:"student.dob"},{key:"student.dobWords"},{key:"tc.dateOfFirstAdmission"},
      {key:"tc.lastClassStudied"},{key:"student.ageAtLeaving"},{key:"tc.dateOfLeaving"}];
    if(o.id==="t_reason"){
      o.requiredKey=null;
      o.content.i18n.en=T({t:"Reason for removal from the rolls: "},{f:"sec.outcome"});
    }
    if(o.id==="t_declare") o.content.i18n.en=T({t:"Issued under Rule 17(3) of the Kerala Education Rules 1959 in place of a transfer certificate, the pupil having been removed from the rolls while over twenty years of age."});
  });
  return t;
}

/* A starter that narrows `languages` inherits its objects from a broader one,
   so the runs of the dropped languages linger as dead data. They cannot render
   — but they used to still bind the merge contract, which made Form 5A
   permanently unpublishable over a Hindi run it did not declare and the UI
   could not show. Every starter is therefore normalised on the way out. */
function pruneLanguages(t){
  if(!t || !t.languages) return t;
  t.objects.forEach(o=>{
    if(!o.content || !o.content.i18n) return;
    Object.keys(o.content.i18n).forEach(L=>{
      if(!t.languages.includes(L)) delete o.content.i18n[L];
    });
  });
  return t;
}

const STARTERS = [
  {id:"lc_5a", docType:"leaving_certificate_5a", name:"Leaving Certificate — Form 5A",
   meta:"Kerala · r.17(3) · field list not retrieved", states:["Kerala"], build:()=>pruneLanguages(starterForm5A())},
  {id:"tc_cbse",   docType:"transfer_certificate", name:"Annexure-I form",
   meta:"CBSE · 22 fields · Level A", boards:["CBSE"], build:()=>pruneLanguages(starterTC())},
  {id:"tc_plain",  docType:"transfer_certificate", name:"Plain letterhead",
   meta:"any board · prose form", boards:null, build:()=>pruneLanguages(starterTCplain())},
  {id:"sec_ker",   docType:"school_education_certificate", name:"Certificate of School Education",
   meta:"Kerala · r.22A · field list Level A", states:["Kerala"], build:()=>pruneLanguages(starterKeralaSEC())},
  {id:"bonafide",  docType:"bonafide", name:"Classic bonafide",
   meta:"no statutory basis found · free format", boards:null, build:()=>pruneLanguages(starterBonafide())},
  {id:"conduct",   docType:"character", name:"Conduct and character",
   meta:"TNER r.34 / App. 5-B shape", boards:null, build:()=>pruneLanguages(starterConduct())}
];
function startersFor(docType){
  const sc=S.school;
  return STARTERS.filter(x=>x.docType===docType)
    .filter(x=>(!x.boards || x.boards.includes(sc.board)) && (!x.states || x.states.includes(sc.state)));
}

/* ==========================================================================
   2 · STATE
   ========================================================================== */
const S = {
  screen:"hub", docType:"transfer_certificate", tpl:null,
  sel:[], lang:"en", data:"typical", zoom:0.70,
  grid:false, anchors:true, guides:{v:[],h:[]},
  tool:"move", editing:null, clipboard:null,
  undo:[], redo:[], proofed:null, dirty:false, measured:{}, hidden:{},
  clamped:{}, blockRefs:{BLK0001:3}, blockIgnored:{}, baseline:null, issuance:{duplicate:false},
  school:null, layerOff:{}, overrideReason:{}, lib:null, active:null,
  cmode:"edit",  // Content pane: "edit" (all fields) | "read" (proofread)
  loading:false, // true while the real library is still being fetched
  conflict:false,      // a save was refused; stop attempting until reload
  conflictShown:false  // the dialog is shown once, not once per attempt
};

/* ==========================================================================
   1b · SERVER — the one door to the panel
   ==========================================================================

   The designer used to be entirely self-contained: it booted from the
   constants above and never spoke to anything, so every template a clerk
   designed lived until the next reload and no further.

   BOOT comes from the <script id="zxdt-boot"> the view renders. When it is
   absent — the E2E harness page, which deliberately ships no payload — the
   designer stays offline and drives its own constants, which is what lets the
   suite test the state machine without a server. Offline is a TEST condition,
   never a production fallback: it says so out loud in the console, because a
   designer that silently stopped saving would look exactly like one that
   works.                                                                    */

const BOOT = (() => {
  const el = document.getElementById("zxdt-boot");
  if (!el) return null;
  try { return JSON.parse(el.textContent); } catch (e) {
    console.error("[zxdt] boot payload is not valid JSON — refusing to guess", e);
    return null;
  }
})();

const SRV = {
  online: !!(BOOT && BOOT.base),
  base:   BOOT && BOOT.base || "",
  csrf:   { name: BOOT && BOOT.csrfName || "", hash: BOOT && BOOT.csrfHash || "" },
  can:    { edit: !!(BOOT && BOOT.canEdit), manage: !!(BOOT && BOOT.canManage) },
  inflight: 0
};
if (!SRV.online) {
  console.warn("[zxdt] no boot payload — running OFFLINE on built-in fixtures. " +
               "Nothing you do here is saved. This is the harness mode.");
}

/** A server call that failed. `code` carries the HTTP status. */
class ApiError extends Error {
  constructor(message, code, body) { super(message); this.code = code; this.body = body; }
}

/**
 * The only way this file talks to the panel.
 *
 * FAILS CLOSED, because fetch() does not reject on 403 or 500 — it resolves
 * with ok:false, and a helper that forgets to look reports a denied action as
 * done. That is a bug class this codebase has already been bitten by, so every
 * one of r.ok, a parseable body, and status !== "error" has to hold before a
 * caller sees a result. Anything else throws.
 */
async function api(action, opts) {
  opts = opts || {};
  if (!SRV.online) throw new ApiError("offline: no server to call", 0, null);

  const url = SRV.base + "/" + action + (opts.query ? "?" + new URLSearchParams(opts.query) : "");
  const init = { method: opts.method || "GET", credentials: "same-origin",
                 headers: { "X-Requested-With": "XMLHttpRequest" } };

  if (init.method === "POST") {
    /* CSRF stays ON for these routes. They are NOT in csrf_exclude_uris, and
       must not be: excluding them would let a forged cross-site POST flip a
       school's active Transfer Certificate template. */
    const body = opts.body instanceof FormData ? opts.body : new FormData();
    if (!(opts.body instanceof FormData)) {
      Object.entries(opts.body || {}).forEach(([k, v]) =>
        body.append(k, typeof v === "string" ? v : JSON.stringify(v)));
    }
    if (SRV.csrf.name) body.append(SRV.csrf.name, SRV.csrf.hash);
    init.body = body;
  }

  SRV.inflight++;
  let r, body = null;
  try {
    r = await fetch(url, init);
    try { body = await r.json(); } catch (e) { /* handled below */ }
  } catch (e) {
    SRV.inflight--;
    throw new ApiError("Could not reach the server — check your connection.", 0, null);
  }
  SRV.inflight--;

  // Keep the rotating token current even on a failure response.
  if (body && body.csrf_token) SRV.csrf.hash = body.csrf_token;

  if (!r.ok || !body || body.status === "error") {
    throw new ApiError(
      (body && body.message) || "The action could not be completed (HTTP " + r.status + ").",
      r.status, body);
  }
  return body.data || {};
}

/** Report a server failure to the person, never swallow it. */
function apiFail(e, what) {
  const msg = e instanceof ApiError ? e.message : String(e && e.message || e);
  console.error("[zxdt] " + what + " failed", e);
  toast(what + " failed — " + msg);
  return null;
}

/* ---- the calls, one per endpoint ------------------------------------- */

const srv = {
  templates: docType => api("get_templates", { query: docType ? { docType } : {} }),
  template:  id       => api("get_template",  { query: { templateId: id } }),
  blocks:    type     => api("get_blocks",    { query: type ? { blockType: type } : {} }),
  versions:  id       => api("get_versions",  { query: { templateId: id } }),

  create: (docType, seed) =>
    api("create", { method: "POST", body: { docType, seed } }),

  save: (id, patch, lockVersion) =>
    api("save", { method: "POST", body: { templateId: id, patch, lockVersion: String(lockVersion) } }),

  validate: tpl => api("validate", { method: "POST", body: { template: tpl } }),

  proof: id => api("proof_pdf", { method: "POST", body: { templateId: id } }),

  publish:  id => api("publish",  { method: "POST", body: { templateId: id } }),
  activate: (id, version) => api("activate", { method: "POST",
    body: version == null ? { templateId: id } : { templateId: id, version: String(version) } }),
  archive:  id => api("archive",  { method: "POST", body: { templateId: id } }),
  deactivate: id => api("deactivate", { method: "POST", body: { templateId: id } }),
  remove:   id => api("delete",     { method: "POST", body: { templateId: id } }),

  uploadAsset: file => {
    const fd = new FormData(); fd.append("file", file);
    return api("upload_asset", { method: "POST", body: fd });
  }
};

/**
 * Persist the current draft.
 *
 * A CONFLICT IS NOT AN ERROR TO RETRY. lockVersion moving means another person
 * saved this template while it was open here; retrying would overwrite work
 * nobody agreed to lose. Two clerks editing a statutory template are not
 * editing the same sentence by coincidence. So the save stops, says who has to
 * decide, and leaves both versions intact.
 */
let __saveTimer = null;

/**
 * Mark the draft changed, and schedule a save.
 *
 * Debounced rather than per-keystroke: a save per character would be one
 * request per letter typed and would make lockVersion churn so fast that a
 * second person editing could never land a save at all.
 *
 * It does NOT save while a modal is open. Publish and activate read the state
 * the person is looking at, and a save landing underneath a confirmation
 * dialog would move it after they read it.
 */
function markDirty() {
  S.dirty = true;
  /* ANY design change invalidates the proof, because the server decides
     publishability by hashing the design and comparing it to the hash recorded
     when the proof was rendered.
     
     Without this the two disagreed: the server correctly REFUSED a publish
     after an edit, but the client left the Publish button enabled and the
     person saw no refusal at all — observed live. A gate the server enforces
     and the UI does not reflect is a gate people learn to distrust. */
  S.proofed = null;
  if (!SRV.online) return;
  /* A save scheduled while create() is still in flight fires against the LOCAL
     placeholder id and fails with "no template TPL4453" — the template is
     created fine, and the clerk is told their work was not saved. Observed on
     the very first real run against a live session. */
  if (S.creating) return;
  /* Nor once a save has conflicted: the stored lockVersion has moved, every
     further attempt with ours fails the same way, and re-attempting is what
     turned one dialog into an endless one. */
  if (S.conflict) return;
  /* Nor for a template that has no server document yet — the hub seeds S.tpl
     with a starter purely so it has something to draw. */
  if (!S.tpl || !isPersistedId(S.tpl.templateId, "autosave")) return;
  clearTimeout(__saveTimer);
  __saveTimer = setTimeout(() => {
    if (!S.dirty) return;
    const scrim = document.getElementById("scrim");
    if (scrim && scrim.classList.contains("is-on")) return;   // a dialog is open
    srvSaveDraft(true);
  }, 1500);
}

/* An unsaved draft must never leave quietly. The debounce means there is
   always a window where the screen is ahead of the server. */
window.addEventListener("beforeunload", e => {
  /* Only for a REAL template with real unsaved work.
     S.dirty can be true for a template that has no server document — the hub
     seeds one to draw with — and warning "you have unsaved changes" about
     something that was never going to be saved teaches people to click through
     the warning, which is the one dialog that must not become noise. */
  if (SRV.online && S.dirty && S.tpl && isPersistedId(S.tpl.templateId, "unload")) {
    e.preventDefault(); e.returnValue = "";
  }
});

/**
 * Is this the DOCUMENT id, or the short entity id?
 *
 * Two different values are both called `templateId`: the document id
 * `{schoolId}_{TPL####}` that every endpoint takes, and the stored field
 * `TPL####`. Sending the short one produces "no template 'TPL0001'" from the
 * server — a real refusal for a real reason, which reads like the template was
 * deleted rather than like a client bug. Catch it here, where it is obvious.
 */
/**
 * Is this a SERVER-BACKED template, or one that only exists on screen?
 *
 * `S.tpl` is never empty: BOOT seeds it with a starter so the hub has something
 * to draw, and openStarter() fills it with a local placeholder id before
 * create() has returned. Neither of those is persisted, and neither should ever
 * be saved.
 *
 * This started life as a hard assertion that toasted "Something is wrong with
 * this template's id" — which was right for the bug it was written for (the
 * short id being sent instead of the document id) and WRONG as a user-facing
 * message, because it fires in the perfectly ordinary state of sitting on the
 * hub with no template open. A diagnostic aimed at me was alarming the person
 * using the product.
 *
 * So: a quiet predicate. Not persisted means there is nothing to save, which is
 * not an error and must not look like one. The genuine developer mistake —
 * holding a persisted template under its SHORT id — still logs loudly, because
 * that one silently does nothing when it should have saved.
 */
function isPersistedId(id, where) {
  if (typeof id !== "string" || id === "") return false;
  if (id.indexOf("_") > 0) return true;                 // {schoolId}_TPL####

  if (/^TPL\d+$/.test(id) && S.screen === "designer" && S.tpl && S.tpl.publishedVersion != null) {
    // A template that HAS been published is server-backed, so a short id here
    // means something overwrote the document id. That is the real bug.
    console.error("[zxdt] " + where + " has the SHORT id '" + id + "' for a template that is "
                + "already published. Endpoints take {schoolId}_TPL####.");
  }
  return false;
}

async function srvSaveDraft(silent) {
  if (!SRV.online || !S.tpl || !S.tpl.templateId) return false;
  // Nothing to save for a template that was never created on the server.
  if (!isPersistedId(S.tpl.templateId, "save")) return false;
  /* Strip the preview data URL. It exists so the canvas can show the image the
     instant it is dropped; the stored document references the uploaded path
     instead. Persisting it would fail the XSS filter, bloat the document and
     still not render. */
  const objects = (S.tpl.objects || []).map(o => {
    if (!o.asset || !o.asset.dataUrl) return o;
    const asset = Object.assign({}, o.asset); delete asset.dataUrl;
    return Object.assign({}, o, {asset});
  });
  const patch = {
    name: S.tpl.name, page: S.tpl.page, header: S.tpl.header, footer: S.tpl.footer,
    objects, languages: S.tpl.languages,
    defaultLanguage: S.tpl.defaultLanguage
  };
  try {
    const out = await srv.save(S.tpl.templateId, patch, S.tpl.lockVersion || 0);
    S.tpl.lockVersion = out.lockVersion;
    S.dirty = false;
    paintStatus();
    if (!silent) toast("Saved");
    return true;
  } catch (e) {
    if (e instanceof ApiError && e.code === 409) {
      /* STOP SAVING, AND ASK ONCE.
      
         This used to show the dialog and return — leaving S.dirty true, so the
         next debounced autosave fired, conflicted again, and reopened it. Every
         keystroke queued another copy. Dismissing it did nothing except buy a
         second and a half, which is not a choice, it is a nag.
      
         A conflict is not transient: the stored lockVersion has moved and every
         further save with this one WILL fail. So enter a conflicted state, stop
         attempting, and say so persistently in the status bar rather than
         interrupting again. The person keeps editing — nothing is taken away —
         but the screen stops pretending a save is coming. */
      S.conflict = true;
      paintStatus();
      if (S.conflictShown) return false;      // one dialog, not one per attempt
      S.conflictShown = true;

      modal("Someone else saved this template",
        "Your changes are still on screen. They have not been saved, and theirs have not been lost.",
        `<p class="note">This template was changed by someone else while you had it open.
         Saving now would overwrite their work, so it was stopped — and it will keep being
         stopped, so nothing here is saving until this is resolved.</p>
         <p class="note" style="margin-bottom:0">Reload to see their version — your unsaved
         changes will be gone, so copy anything you need first.</p>`,
        `<button class="btn" data-close>Keep editing (nothing will save)</button><span class="spacer"></span>
         <button class="btn btn--primary" id="cfReload">Reload their version</button>`, true);
      const b = document.getElementById("cfReload");
      if (b) b.onclick = () => location.reload();
      return false;
    }
    apiFail(e, "Save");
    return false;
  }
}

const TYPES = [
  {id:"transfer_certificate", name:"Transfer Certificate", alias:"School Leaving Certificate · Leaving Certificate", statutory:true},
  {id:"bonafide", name:"Bonafide Certificate", alias:"present-tense · no statutory basis found", statutory:false},
  {id:"character", name:"Character Certificate", alias:"Conduct Certificate — one instrument, configurable name", statutory:false},
  {id:"school_education_certificate", name:"Certificate of School Education",
   alias:"Kerala · KER r.22A — for a pupil who left before the S.S.L.C. examination",
   requiresState:"Kerala", statutory:true},
  {id:"leaving_certificate_5a", name:"Leaving Certificate (Form 5A)",
   alias:"Kerala · KER r.17(3) — issued where a transfer certificate may not be",
   requiresState:"Kerala", statutory:true},
  {id:"study", name:"Study Certificate", alias:"retrospective, year-by-year · A.P. G.O.P. 646",
   requiresState:"Andhra Pradesh", statutory:true},
  {id:"migration", name:"Migration Certificate", alias:"board-issued — never merged with a TC", disabled:true},
  {id:"fee_receipt", name:"Fee Receipt", alias:"needs repeating rows — v2", disabled:true}
];
/* ==========================================================================
   Reading a template's state in plain words
   ==========================================================================

   These screens were showing three different version numbers on one card
   ("published v3 · draft v4", plus chips "Active · v3" and "Draft v4") and raw
   machine timestamps ("2026-09-02T20:02:55+00:00"). Every number was correct
   and the card was still unreadable: nothing told you, in words, what the
   template IS or whether the change you just made is live.

   So: one status, said plainly, with the version as a detail rather than the
   headline. A school administrator thinks "is this the one we issue?", not
   "which integer is the active pointer?".                                   */

/* Object types are stored as identifiers and were shown as identifiers —
   the inspector chip read "PAGENUMBER". Say it the way a person would. */
const TYPE_LABEL = {text:"Text", image:"Image", shape:"Line", table:"Table",
                    qr:"QR code", pageNumber:"Page number"};
const typeLabel = t => TYPE_LABEL[t] || String(t||"");

/**
 * A stored asset path -> a URL the browser can fetch.
 *
 * Paths are stored school-relative ("uploads/SCH_x/doctemplates/assets/ab12.png")
 * because that is what the PDF renderer resolves against the filesystem. The
 * browser needs it ROOT-relative: left as-is on /doc_templates/design/... it
 * would resolve to /doc_templates/uploads/... and 404.
 */
function assetUrl(src){
  const s=String(src||"");
  if(/^(https?:)?\/\//.test(s) || s.startsWith("data:")) return s;   // already absolute
  return s.startsWith("/") ? s : "/" + s;
}

function relTime(iso){
  if(!iso) return "just now";
  const t = Date.parse(iso);
  if(isNaN(t)) return String(iso);          // already human, or unparseable
  const secs = Math.round((Date.now()-t)/1000);
  if(secs < 60)    return "just now";
  if(secs < 3600)  return Math.floor(secs/60)+" min ago";
  if(secs < 86400) { const h=Math.floor(secs/3600); return h+(h===1?" hour ago":" hours ago"); }
  if(secs < 172800) return "yesterday";
  if(secs < 604800) return Math.floor(secs/86400)+" days ago";
  return new Date(t).toLocaleDateString(undefined,{day:"numeric",month:"short",year:"numeric"});
}

/**
 * One state per template, in the words a person would use.
 *
 * `unsaved` is deliberately separate from the state: a template can be in use
 * AND have edits nobody has published, and hiding that is how somebody changes
 * a certificate and never notices it did not take effect.
 */
function templateState(row, isActive){
  const published = row.publishedVersion || null;
  const hasDraft  = (row.version || 1) > (published || 0);

  if(isActive){
    const live = row.activeVersion || published;
    /* A THIRD STATE THE CARD USED TO HIDE: published, but NOT the one in use.
       You can publish v4 while v3 is still live — publishing deliberately does
       not activate. The card only knew "in use" and "draft", so v4 was
       invisible: the screen kept saying v3 and the work looked lost. */
    const waiting = published && published > live;
    return {
      key:"active", label:"In use", tone:"active",
      detail: waiting
        ? `Version ${live} is in use · version ${published} is published and waiting`
        : `Version ${live} is what every print point resolves`,
      waiting: waiting ? published : null,
      unsaved: hasDraft ? "You have unpublished changes" : null
    };
  }
  if(published) return {
    key:"ready", label:"Ready — not in use", tone:"published",
    detail:`Version ${published} is published but nothing resolves it`,
    unsaved: hasDraft ? "You have unpublished changes" : null
  };
  return {
    key:"draft", label:"Draft", tone:"draft",
    detail:"Never published — it cannot be issued yet", unsaved:null
  };
}

function typeEnabled(t){
  if(t.disabled) return false;
  if(t.requiresState) return S.school.state===t.requiresState;
  return true;
}
function typeBasis(t){
  const st=stackActive(t.id);
  if(!st.length) return {label:"Not checked", evidence:null};
  const best=st.slice().sort((x,y)=>(EVIDENCE_RANK[y.a.evidence]||0)-(EVIDENCE_RANK[x.a.evidence]||0))[0];
  return {label:st.map(l=>l.a.label).join(" + "), evidence:best.a.evidence};
}
const libOf = id=>(S.lib[id]||[]);
const activeTpl = id=>libOf(id).find(t=>t.id===S.active[id]) || null;


/* ==========================================================================
   3 · helpers
   ========================================================================== */
/* NOT named `$`.
   These were `$` and `$$`, declared as top-level `const` in a classic script —
   which puts them in the GLOBAL LEXICAL SCOPE, where they shadow window.$ for
   every classic script that loads after. On the panel page that broke the
   panel's own inline code: `$.ajaxSetup is not a function`, and custom.js
   threw, because the page's jQuery calls resolved to this helper instead.
   Same footgun class as the .att-grid utility collision. */
const zq  = (s,r=document)=>r.querySelector(s);
const zqa = (s,r=document)=>[...r.querySelectorAll(s)];
const el = (t,c,h)=>{const n=document.createElement(t); if(c)n.className=c; if(h!=null)n.innerHTML=h; return n;};
const esc = s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const pxPerMm = ()=>S.zoom*96/25.4;
const mm = v=>Math.round(v*10)/10;
const obj = id=>S.tpl.objects.find(o=>o.id===id);
const bodyObjects = ()=>S.tpl.objects.filter(o=>!o.region);
function pageDims(){
  const [w,h]=PAPER[S.tpl.page.size]||PAPER.A4;
  return S.tpl.page.orientation==="landscape" ? {w:h,h:w} : {w,h};
}
/* Figma's X/Y/W/H fields accept "+10", "*2", "(210/2)-90". On a millimetre
   field that removes a calculator from the clerk's desk. */
function evalMm(v, fallback){
  if(typeof v==="number") return v;
  const t=String(v==null?"":v).trim();
  if(!t) return fallback;
  if(/^-?\d*\.?\d+$/.test(t)) return parseFloat(t);
  if(!/^[-0-9+*/^().\s]+$/.test(t)) return fallback;
  try{
    const r=Function('"use strict";return ('+t.replace(/\^/g,"**")+')')();
    return (typeof r==="number" && isFinite(r)) ? Math.round(r*100)/100 : fallback;
  }catch(e){ return fallback; }
}
function toast(msg, seal){
  const t=zq("#toast"); t.className="toast is-on"+(seal?" toast--seal":""); t.textContent=msg;
  clearTimeout(toast._t); toast._t=setTimeout(()=>t.className="toast",3200);
}
function fieldValue(key){
  const f=FIELD[key]; if(!f) return "⟨unknown⟩";
  if(S.data==="off") return null;
  /* issuance state, not merge data — but it resolves like a field, so the
     existing showWhen machinery can drive a statutory duplicate mark */
  if(key==="doc.isDuplicate") return S.issuance.duplicate ? "Yes" : "";
  return S.data==="p95" ? (f.p95||f.sample) : f.sample;
}
function boundKeys(){
  const set=new Set();
  /* Only languages the template DECLARES can bind the contract. A run in a
     language that was dropped never renders, so it is dead data — but it used
     to still count as "bound", which made Form 5A permanently unpublishable:
     the starter declares en only and rewrites the English run, while a
     leftover Hindi run kept binding tc.reasonForLeaving, a key the 5A contract
     does not declare. The result was an offContract error naming a field the
     UI could not show, in a language the template did not have. */
  const langs=(S.tpl && S.tpl.languages) || null;
  for(const o of S.tpl.objects){
    if(o.type==="table") o.content.rows.forEach(r=>set.add(r.key));
    else if(o.content&&o.content.i18n)
      for(const L of Object.keys(o.content.i18n)){
        if(langs && !langs.includes(L)) continue;
        o.content.i18n[L].runs.forEach(r=>{ if(r.f) set.add(r.f); });
      }
  }
  return set;
}
function objectForKey(key){
  for(const o of S.tpl.objects){
    if(o.type==="table" && o.content.rows.some(r=>r.key===key)) return o;
    if(o.content&&o.content.i18n)
      for(const L of Object.keys(o.content.i18n))
        if(o.content.i18n[L].runs.some(r=>r.f===key)) return o;
  }
  return null;
}
function translationCoverage(lang){
  /* an object pinned to another language is not "untranslated" in this one —
     it is deliberately not in this one, and counting it as missing would
     nag forever on a bilingual template */
  const txt=S.tpl.objects.filter(o=>o.type==="text" && !(o.lang && o.lang!==lang));
  let done=0;
  txt.forEach(o=>{ const d=o.content.i18n[lang]; if(d && d.runs.length) done++; });
  return {done, total:txt.length};
}
/* command stack — one command per gesture, never one per mousemove */
function push(label, before, after){
  S.undo.push({label, before, after}); if(S.undo.length>80) S.undo.shift();
  S.redo.length=0; markDirty();
}
const snapshot = ()=>JSON.stringify({o:S.tpl.objects, r:S.tpl.regionLang||{}});
const restore  = j=>{ const d=JSON.parse(j); S.tpl.objects=d.o; S.tpl.regionLang=d.r||{}; };
function undo(){ const c=S.undo.pop(); if(!c) return toast("Nothing to undo");
  restore(c.before); S.redo.push(c); S.sel=S.sel.filter(id=>obj(id)); render(); toast("Undo — "+c.label); }
function redo(){ const c=S.redo.pop(); if(!c) return toast("Nothing to redo");
  restore(c.after); S.undo.push(c); S.sel=S.sel.filter(id=>obj(id)); render(); toast("Redo — "+c.label); }

/* ==========================================================================
   4 · CONTENT RENDERING
   ========================================================================== */
function chipHTML(key, live){
  const f=FIELD[key]||{label:key};
  if(live){
    const v=fieldValue(key);
    if(v!=null) return `<span class="mf mf--live${S.data==="p95"?" mf--stress":""}" data-key="${esc(key)}">${esc(v)}</span>`;
  }
  return `<span class="mf" contenteditable="false" data-key="${esc(key)}">${esc(f.label)}</span>`;
}
function runsHTML(runs, live){
  return runs.map(r=>{
    if(r.f!=null) return chipHTML(r.f, live);
    let h=esc(r.t==null?"":r.t).replace(/\n/g,"<br>");
    if(r.u) h=`<u>${h}</u>`;
    if(r.i) h=`<i>${h}</i>`;
    if(r.b) h=`<b>${h}</b>`;
    return h;
  }).join("");
}
/* DOM → runs. Chips are atomic; b/i/u come from ancestors. */
function parseRuns(root){
  const runs=[];
  (function walk(node, ctx){
    node.childNodes.forEach(n=>{
      if(n.nodeType===3){ if(n.nodeValue) runs.push(Object.assign({t:n.nodeValue}, ctx)); return; }
      if(n.nodeType!==1) return;
      if(n.classList && n.classList.contains("mf")){ runs.push({f:n.dataset.key}); return; }
      const tag=n.tagName;
      if(tag==="BR"){ runs.push({t:"\n"}); return; }
      const c=Object.assign({},ctx);
      if(tag==="B"||tag==="STRONG") c.b=1;
      if(tag==="I"||tag==="EM")     c.i=1;
      if(tag==="U")                 c.u=1;
      const block = (tag==="DIV"||tag==="P");
      if(block && runs.length) runs.push({t:"\n"});
      walk(n,c);
    });
  })(root,{});
  /* merge adjacent text runs with identical formatting */
  const out=[];
  runs.forEach(r=>{
    const p=out[out.length-1];
    if(p && r.f==null && p.f==null && !!p.b===!!r.b && !!p.i===!!r.i && !!p.u===!!r.u) p.t+=r.t;
    else out.push(r);
  });
  while(out.length && out[out.length-1].f==null && /^\s*$/.test(out[out.length-1].t)) out.pop();
  return out;
}
function objectHTML(o, forEdit){
  const st=o.style||{};
  if(o.type==="text"){
    const d=o.content.i18n[langOf(o)];
    if(!d) return `<span style="opacity:.35">— untranslated —</span>`;
    return runsHTML(d.runs, forEdit?false:S.data!=="off") || "&#8203;";
  }
  if(o.type==="table"){
    return o.content.rows.map((r,i)=>{
      const f=FIELD[r.key]||{label:r.key};
      return `<div class="tblrow"><span class="tblrow__n">${i+1}.</span>`+
        `<span class="tblrow__l">${esc(f.label)}</span>`+
        `<span class="tblrow__v">${chipHTML(r.key, S.data!=="off")}</span></div>`;
    }).join("");
  }
  if(o.type==="image"){
    if(o.bindKey){
      const v=fieldValue(o.bindKey);
      return `<div class="ph">${esc((FIELD[o.bindKey]||{}).label||o.bindKey)}${v==null?"":" · per document"}</div>`;
    }
    /* Freshly dropped: the in-memory data URL, so it appears the instant you
       drop it, before the upload has finished. */
    if(o.asset && o.asset.dataUrl)
      return `<img class="asset-img" src="${o.asset.dataUrl}" alt="">`;
    /* SAVED: the uploaded path. This branch was missing, and it is why an
       image vanished from the canvas after a reload.
    
       The canvas only ever rendered asset.dataUrl — the preview copy — and I
       stopped persisting that copy when images began uploading properly, so
       there was nothing left to draw from. The picture was saved, stored under
       its content hash, and printed correctly in every proof PDF; the one place
       it did not appear was the screen where you had just put it. Worse than
       losing it, because everything downstream said it was fine. */
    if(o.content && o.content.src)
      return `<img class="asset-img" src="${assetUrl(o.content.src)}" alt="">`;
    const k=ASSET_KINDS[o.assetKind]||{label:o.content.label||"image"};
    return `<div class="ph">${esc(k.label)}<br><span style="opacity:.7">drop a file</span></div>`;
  }
  if(o.type==="shape"){
    if(o.content.shape==="line") return `<div style="width:100%;height:100%;background:${st.colour||"#14100D"}"></div>`;
    if(o.content.shape==="seal") return `<div class="ph" style="border-radius:50%;border-style:dashed">seal</div>`;
    return `<div style="width:100%;height:100%;border:1px solid rgba(42,28,20,.4)"></div>`;
  }
  if(o.type==="qr") return `<div class="ph">QR · verify</div>`;
  if(o.type==="pageNumber") return esc((o.content.format||"{n}").replace("{n}","1").replace("{t}","1"));
  return "";
}

/* ==========================================================================
   5 · LAYOUT PASS
   ========================================================================== */
const regionTop = o=>{
  const k=pxPerMm(), P=pageDims();
  return o.region==="footer" ? (P.h-S.tpl.page.marginsMm.b)*k : 0;
};
const resolvedY = o=> o._y!=null ? o._y : o.yMm;
const nodeFor   = id=> zq('.obj[data-id="'+id+'"]');

function layoutPage(){
  /* A render while editing would wipe the contenteditable node and lose the
     keystrokes with it — so harvest the live DOM into the model first. */
  if(S.editing){
    const en=nodeFor(S.editing), eo=obj(S.editing);
    if(en&&eo){ const ei=zq(".obj__in",en);
      if(ei && ei.isContentEditable) eo.content.i18n[langOf(eo)]={runs:parseRuns(ei)}; }
  }
  const P=zq("#page"), k=pxPerMm(), m=S.tpl.page.marginsMm, D=pageDims();
  zq("#stage").style.width=(D.w*k)+"px";
  P.style.width=(D.w*k)+"px"; P.style.height=(D.h*k)+"px"; P.innerHTML="";

  const grid=el("div","page__grid"+(S.grid?" is-on":""));
  grid.style.backgroundImage=
    "linear-gradient(to right,rgba(42,28,20,.07) 1px,transparent 1px),"+
    "linear-gradient(to bottom,rgba(42,28,20,.07) 1px,transparent 1px)";
  grid.style.backgroundSize=(5*k)+"px "+(5*k)+"px";
  P.appendChild(grid);

  const mg=el("div","page__margins");
  mg.style.left=(m.l*k)+"px"; mg.style.top=(m.t*k)+"px";
  mg.style.width=((D.w-m.l-m.r)*k)+"px"; mg.style.height=((D.h-m.t-m.b)*k)+"px";
  P.appendChild(mg);
  const hb=el("div","band band--head",'<span class="band__tag">header · repeats every page</span>');
  hb.style.height=(m.t*k)+"px"; P.appendChild(hb);
  const fb=el("div","band band--foot",'<span class="band__tag">footer</span>');
  fb.style.height=(m.b*k)+"px"; P.appendChild(fb);

  /* pass 1 — place at the authored width, THEN measure. Measuring a
     shrink-to-fit box never wraps, so every auto object reports one line. */
  const nodes={};
  S.tpl.objects.slice().sort((a,b)=>(a.z||0)-(b.z||0)).forEach(o=>{
    const n=buildObject(o); nodes[o.id]=n;
    n.style.left=(o.xMm*k)+"px";
    n.style.top=(regionTop(o)+resolvedY(o)*k)+"px";
    n.style.width=(o.wMm*k)+"px";
    n.style.height=o.height==="auto" ? "auto" : (o.hMm*k)+"px";
    P.appendChild(n);
  });
  S.measured={}; S.clamped={};
  S.tpl.objects.forEach(o=>{
    const inner=zq(".obj__in",nodes[o.id]);
    let h = o.height==="auto" ? Math.max(o.hMm, inner.getBoundingClientRect().height/k) : o.hMm;
    /* Figma's fourth resizing state: a ceiling on top of hug/auto. Without it
       an over-long statutory field pushes the signature block off the sheet. */
    if(o.height==="auto" && o.maxHMm && h > o.maxHMm){
      S.clamped[o.id] = h - o.maxHMm;
      h = o.maxHMm;
    }
    S.measured[o.id] = h;
  });

  /* pass 2 — anchor chains hold a GAP, not an absolute Y */
  let guard=0, moved=true;
  while(moved && guard++<12){
    moved=false;
    S.tpl.objects.forEach(o=>{
      if(!o.anchorTo) return;
      const a=obj(o.anchorTo); if(!a) return;
      const y=resolvedY(a)+S.measured[a.id]+(o.anchorGapMm||0);
      if(Math.abs(y-resolvedY(o))>0.05){ o._y=y; moved=true; }
    });
  }

  /* pass 3 — final geometry + state badges */
  S.tpl.objects.forEach(o=>{
    const n=nodes[o.id], y=resolvedY(o), h=S.measured[o.id];
    n.style.top=(regionTop(o)+y*k)+"px";
    n.style.height=(h*k)+"px";
    n.classList.toggle("is-sel", S.sel.includes(o.id));
    n.classList.toggle("is-req", !!o.requiredKey);
    n.classList.toggle("is-hidden", !!S.hidden[o.id]);
    n.classList.toggle("is-edit", S.editing===o.id);
    if(S.clamped[o.id]) n.appendChild(el("span","badge-clamp","CLAMPED −"+mm(S.clamped[o.id])+"mm"));
    else if(o.height==="auto" && h>o.hMm+0.4) n.appendChild(el("span","badge-grow","GROWS +"+mm(h-o.hMm)+"mm"));
    if(o.showWhen){
      n.classList.add("is-cond");
      const on = fieldValue(o.showWhen)!=null && fieldValue(o.showWhen)!=="";
      if(S.data!=="off" && !on) n.classList.add("is-cond-off");
      n.appendChild(el("span","badge-if","IF"));
    }
    if(o.height==="fixed"){
      const inner=zq(".obj__in",n);
      if(inner.scrollHeight>inner.clientHeight+2){ n.classList.add("is-over"); n.appendChild(el("span","badge-over","OVERFLOW")); }
    }
    if(S.sel.includes(o.id) && S.sel.length===1 && !o.locked && S.editing!==o.id)
      ["nw","ne","sw","se","n","s","w","e"].forEach(p=>n.appendChild(el("span","h "+p)));
  });

  if(S.anchors) S.tpl.objects.filter(o=>o.anchorTo).forEach(o=>{
    const a=obj(o.anchorTo); if(!a) return;
    const y0=regionTop(a)+(resolvedY(a)+S.measured[a.id])*k, y1=regionTop(o)+resolvedY(o)*k;
    const l=el("div","anchorline");
    l.style.left=((o.xMm-3)*k)+"px"; l.style.top=y0+"px"; l.style.height=Math.max(2,y1-y0)+"px";
    P.appendChild(l);
  });
  /* ruler guides sit above objects but below the marquee */
  S.guides.v.forEach((x,i)=>{
    const g=el("div","rguide rguide--v",'<i></i>');
    g.style.left=(x*k)+"px"; g.dataset.axis="v"; g.dataset.idx=i; P.appendChild(g);
  });
  S.guides.h.forEach((y,i)=>{
    const g=el("div","rguide rguide--h",'<i></i>');
    g.style.top=(y*k)+"px"; g.dataset.axis="h"; g.dataset.idx=i; P.appendChild(g);
  });

  /* one bounding box for a multi-selection, as Figma does */
  if(S.sel.length>1){
    const bs=S.sel.map(obj).filter(Boolean).map(o=>({
      x:o.xMm, y:resolvedY(o)+regionTop(o)/k, w:o.wMm, h:S.measured[o.id]||o.hMm}));
    const x0=Math.min(...bs.map(b=>b.x)), y0=Math.min(...bs.map(b=>b.y));
    const x1=Math.max(...bs.map(b=>b.x+b.w)), y1=Math.max(...bs.map(b=>b.y+b.h));
    const sb=el("div","selbox");
    sb.style.cssText=`left:${x0*k-2}px;top:${y0*k-2}px;width:${(x1-x0)*k+4}px;height:${(y1-y0)*k+4}px`;
    P.appendChild(sb);
  }

  if(S.editing) restoreCaret();
  positionCtxbar();
}

/* the caret is stored as a character offset so it survives a rebuild */
let caretOffset=null;
function readCaret(){
  const n=nodeFor(S.editing); if(!n) return;
  const inner=zq(".obj__in",n), sel=getSelection();
  if(!sel.rangeCount || !inner.contains(sel.anchorNode)) return;
  const r=sel.getRangeAt(0).cloneRange();
  r.selectNodeContents(inner); r.setEnd(sel.anchorNode, sel.anchorOffset);
  caretOffset=r.toString().length;
}
function restoreCaret(){
  const n=nodeFor(S.editing); if(!n) return;
  const inner=zq(".obj__in",n);
  inner.focus({preventScroll:true});
  const want=caretOffset==null?Infinity:caretOffset;
  let seen=0, done=false;
  const walk=document.createTreeWalker(inner, NodeFilter.SHOW_TEXT);
  let node;
  while((node=walk.nextNode())){
    if(seen+node.nodeValue.length>=want){
      const r=document.createRange();
      r.setStart(node, Math.max(0,Math.min(node.nodeValue.length, want-seen)));
      r.collapse(true);
      const s=getSelection(); s.removeAllRanges(); s.addRange(r); done=true; break;
    }
    seen+=node.nodeValue.length;
  }
  if(!done){ const r=document.createRange(); r.selectNodeContents(inner); r.collapse(false);
    const s=getSelection(); s.removeAllRanges(); s.addRange(r); }
}

function buildObject(o){
  const n=el("div","obj"+(o.locked?" is-locked":"")); n.dataset.id=o.id;
  const inner=el("div","obj__in");
  inner.innerHTML=objectHTML(o, S.editing===o.id);
  const st=o.style||{};
  inner.style.fontSize=((st.sizePt||9)*S.zoom*96/72)+"px";
  inner.style.lineHeight=st.lineHeight||1.4;
  inner.style.fontWeight=st.weight||400;
  inner.style.textAlign=st.align||"left";
  inner.style.color=st.colour||"#14100D";
  if(st.track) inner.style.letterSpacing=st.track;
  if(st.topRule) inner.style.borderTop="1px solid rgba(42,28,20,.5)";
  if(S.editing===o.id){ inner.contentEditable="true"; inner.spellcheck=false; }
  n.appendChild(inner);
  n.appendChild(el("div","obj__hit"));
  n.appendChild(el("span","obj__nm",esc(o.name||o.id)));
  return n;
}

function paintRulers(){
  const k=pxPerMm(), D=pageDims(), H=zq("#rulerH"), V=zq("#rulerV");
  H.style.width=(D.w*k)+"px"; V.style.height=(D.h*k)+"px";
  H.innerHTML=""; V.innerHTML="";
  const step = S.zoom<0.5 ? 20 : 10;
  for(let x=0;x<=D.w;x+=step){
    const t=el("i"); t.style.left=(x*k)+"px"; t.style.bottom="0"; t.style.width="1px"; t.style.height="5px"; H.appendChild(t);
    const b=el("b",null,String(x)); b.style.left=(x*k+2)+"px"; b.style.top="1px"; H.appendChild(b);
  }
  for(let y=0;y<=D.h;y+=step){
    const t=el("i"); t.style.top=(y*k)+"px"; t.style.right="0"; t.style.height="1px"; t.style.width="5px"; V.appendChild(t);
    const b=el("b",null,String(y)); b.style.top=(y*k+2)+"px"; b.style.left="2px"; V.appendChild(b);
  }
}

/* ==========================================================================
   6 · SCREENS
   ========================================================================== */
function go(screen){
  S.screen=screen;
  zqa(".screen").forEach(s=>s.classList.remove("is-on"));
  zq("#screen-"+screen).classList.add("is-on");
  hideCtxbar(); zq("#ctxmenu").classList.remove("is-on");
  paintCrumb(); paintTopActions();
  if(screen==="hub") paintHub();
  if(screen==="gallery") paintGallery();
  if(screen==="designer"){ render(); requestAnimationFrame(zoomFit); }
}
function paintCrumb(){
  const c=zq("#crumb"); c.innerHTML="";
  const t=TYPES.find(x=>x.id===S.docType);
  if(S.screen==="hub"){ c.innerHTML='<span class="crumb__now">Certificates</span>'; return; }
  c.insertAdjacentHTML("beforeend",'<button data-go="hub">Certificates</button><span class="crumb__sep">›</span>');
  if(S.screen==="gallery"){ c.insertAdjacentHTML("beforeend",`<span class="crumb__now">${esc(t.name)}</span>`); return; }
  c.insertAdjacentHTML("beforeend",`<button data-go="gallery">${esc(t.name)}</button><span class="crumb__sep">›</span>`);
  const nm=el("span","crumb__now",esc(S.tpl.name || (S.loading?"Loading…":"Untitled template")));
  nm.contentEditable="true"; nm.spellcheck=false; nm.id="tplName"; nm.title="Click to rename";
  nm.addEventListener("blur",()=>{ S.tpl.name=nm.textContent.trim()||"Untitled template"; markDirty(); paintStatus(); });
  nm.addEventListener("keydown",e=>{ if(e.key==="Enter"){ e.preventDefault(); nm.blur(); } });
  c.appendChild(nm);
  /* THE STATE, TRUTHFULLY.
     This said only "Draft v4" — which was true and hid the fact that matters
     most: version 3 of this same template is LIVE right now. Someone editing it
     had no way to know from the header that a certificate is being issued from
     it as they work. */
  if(S.loading && !S.tpl.name) return;      // nothing true to say about it yet
  const active = S.tpl.activeVersion != null;
  const pub    = S.tpl.publishedVersion || null;
  const ahead  = (S.tpl.version || 1) > (pub || 0);

  if(active){
    c.insertAdjacentHTML("beforeend",
      `<span class="chip chip--active" style="margin-left:8px" title="Every print point resolves this version"><span class="dot"></span>In use · v${S.tpl.activeVersion}</span>`);
    /* Published but not live — the state that made a published version look
       lost, because the header kept naming the older one. */
    if(pub && pub > S.tpl.activeVersion) c.insertAdjacentHTML("beforeend",
      `<span class="chip chip--published" style="margin-left:6px" title="Published, but not what prints yet — activate it from History"><span class="dot"></span>v${pub} ready</span>`);
  }
  else if(pub)   c.insertAdjacentHTML("beforeend",
    `<span class="chip chip--published" style="margin-left:8px"><span class="dot"></span>Published · v${pub}</span>`);
  else           c.insertAdjacentHTML("beforeend",
    `<span class="chip chip--draft" style="margin-left:8px"><span class="dot"></span>Draft</span>`);

  /* The third pill said "Unpublished changes" and pushed the header to three
     long badges. The status bar already reports save state continuously, and
     the gallery card — where there is room — still spells it out. Here it is a
     short one. */
  if(ahead && (active || pub)) c.insertAdjacentHTML("beforeend",
    `<span class="chip chip--draft" style="margin-left:6px" title="You are editing draft v${S.tpl.version}; publish to make it live"><span class="dot"></span>draft v${S.tpl.version}</span>`);
}
function paintTopActions(){
  const a=zq("#topActions"); a.innerHTML="";
  if(S.screen!=="designer") return;
  /* ACTIONS ONLY — things that change the document, in the order they are
     done: undo/redo, look back, prove, publish. The view toggles moved to the
     status bar, which is where the other view state already lives. */
  a.innerHTML = `
    <button class="btn btn--ghost btn--ico btn--sm" id="undoBtn" title="Undo — ⌘Z">↺</button>
    <button class="btn btn--ghost btn--ico btn--sm" id="redoBtn" title="Redo — ⌘⇧Z">↻</button>
    <span class="topbar__div"></span>
    <button class="btn btn--ghost btn--sm" id="histBtn">History</button>
    <button class="btn btn--sm" id="proofBtn">Proof PDF <span class="btn__hint">~2s</span></button>
    <button class="btn btn--primary btn--sm" id="pubBtn">Publish</button>`;
}

/** Language, sample data and translation coverage — what you are LOOKING at. */
function paintViewStrip(){
  const v=zq("#viewStrip"); if(!v) return;
  if(S.screen!=="designer"){ v.innerHTML=""; return; }
  const cov=translationCoverage("hi");
  v.innerHTML = `
    <span class="viewstrip__lbl">Preview</span>
    <div class="seg seg--sm" title="Preview language">
      <button data-lang="en" class="${S.lang==="en"?"is-on":""}">EN</button>
      <button data-lang="hi" class="${S.lang==="hi"?"is-on":""}">हिन्दी</button>
    </div>
    <span class="sb mono" title="Text objects with content in हिन्दी">${cov.done}/${cov.total} translated</span>
    <div class="seg seg--sm" title="Sample data shown on the canvas">
      <button data-data="off"     class="${S.data==="off"?"is-on":""}">Field names</button>
      <button data-data="typical" class="${S.data==="typical"?"is-on":""}">Typical</button>
      <button data-data="p95"     class="${S.data==="p95"?"is-on":""}">p95</button>
    </div>`;
}
function schematic(objs, host){
  host.innerHTML="";
  const D={w:210,h:297};
  objs.forEach(o=>{
    const i=el("i");
    i.className = o.requiredKey ? "req" : (o.type==="shape"?"rule":"");
    const yOff = o.region==="footer" ? (D.h-16) : 0;
    i.style.left=(o.xMm/D.w*100)+"%";
    i.style.top=((o.yMm+yOff)/D.h*100)+"%";
    i.style.width=(o.wMm/D.w*100)+"%";
    i.style.height=Math.max(0.6,(o.hMm/D.h*100))+"%";
    if(o.type==="shape"&&o.content.shape==="seal") i.style.borderRadius="50%";
    host.appendChild(i);
  });
}
function paintHub(){
  const g=zq("#typeGrid"), o=zq("#typeGridOff"); g.innerHTML=""; o.innerHTML="";
  TYPES.filter(typeEnabled).forEach(t=>{
    const basis=typeBasis(t), act=activeTpl(t.id), n=libOf(t.id).length;
    const c=el("button","type-card");
    c.innerHTML=`
      <div class="type-card__top">
        <div><div class="type-card__name">${esc(t.name)}</div><div class="type-card__sub">${esc(t.alias)}</div></div>
        <div class="glyph"></div>
      </div>
      <div class="type-card__meta">
        <div class="type-card__row"><span>Compliance basis</span><b>${esc(basis.label)}</b></div>
        <div class="type-card__row"><span>Active template</span><b>${S.loading?"…":(act?esc(act.name):"— none —")}</b></div>
        <div class="type-card__row"><span>Templates</span><b>${S.loading?"…":(n===0?"none yet":n+(n===1?" template":" templates")+" · edited "+esc(relTime(libOf(t.id)[0].edited)))}</b></div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${t.statutory?'<span class="chip chip--statutory"><span class="dot"></span>Statutory format</span>'
                    :'<span class="chip">Free format</span>'}
        ${basis.evidence?`<span class="lvl lvl--${basis.evidence}">Level ${basis.evidence}</span>`
                        :'<span class="chip">Not checked</span>'}
        ${act?'<span class="chip chip--active"><span class="dot"></span>Active</span>'
             :'<span class="chip chip--draft">No active template</span>'}
      </div>`;
    if(act){ const prev=S.docType; S.docType=t.id; schematic(buildTpl(act).objects, zq(".glyph",c)); S.docType=prev; }
    c.onclick=()=>{ S.docType=t.id; go("gallery"); };
    g.appendChild(c);
  });
  TYPES.filter(t=>!typeEnabled(t)).forEach(t=>{
    const c=el("div","type-card type-card--off");
    const why = t.requiresState ? `Applies in ${esc(t.requiresState)} — this school is in ${esc(S.school.state)}`
                                : esc(t.alias);
    c.innerHTML=`<div class="type-card__top"><div><div class="type-card__name">${esc(t.name)}</div>
      <div class="type-card__sub">${why}</div></div></div>
      <div><span class="chip">${t.requiresState?"Not applicable here":"Not enabled"}</span></div>`;
    o.appendChild(c);
  });
}

/* a library row -> a full template object */
function buildTpl(row){
  const st=STARTERS.find(x=>x.id===row.starter) || STARTERS[0];
  const t=st.build();
  t.templateId=row.id; t.name=row.name;
  t.status=row.status; t.version=row.version; t.publishedVersion=row.publishedVersion;
  t.activeVersion = (S.active[t.docType]===row.id) ? row.publishedVersion : null;
  return t;
}
/**
 * Open a saved template — by FETCHING IT.
 *
 * This used to do `S.tpl = buildTpl(row)`: rebuild a template from the summary
 * row plus the STARTER FIXTURE it was cloned from, and never ask the server for
 * the document. So pressing Open in the list gave you the starter's content
 * instead of your saved design — no uploaded logo, none of your edits, none of
 * your Hindi — and a lockVersion belonging to whatever was loaded before.
 *
 * That is why every save then conflicted ("you read lockVersion 17, it is now
 * 2" — a token from a different template entirely), why Render Proof did
 * nothing, and why the work looked lost: it was never loaded.
 *
 * And it was one successful save away from being much worse. Had the token
 * happened to match, saving would have written the STARTER'S objects over the
 * real template and destroyed the design.
 *
 * Only the deep link (/design/{id}) ever fetched the document, which is exactly
 * why it worked in testing while the list did not — every check I ran went in
 * through the URL.
 */
async function openTemplate(row){
  if(!row) return;

  if(!SRV.online){                       // harness: the fixtures are the data
    S.tpl=buildTpl(row);
    S.lang=row.lang||"en"; S.sel=[]; S.undo=[]; S.redo=[]; S.proofed=null; S.dirty=false; S.tool="move";
    S.baseline=JSON.parse(JSON.stringify(S.tpl.objects));
    S.blockRefs={BLK0001:3}; S.blockIgnored={};
    return go("designer");
  }

  /* Show the shell straight away — a 2s fetch behind a frozen list reads as a
     dead click — but with NO identity until the real one arrives, so it never
     announces a template it has not got. */
  S.tpl=starterTC(); S.tpl.name=""; S.tpl.templateId=row.id;
  S.tpl.publishedVersion=null; S.tpl.activeVersion=null; S.tpl.lockVersion=null;
  S.loading=true; S.sel=[]; S.undo=[]; S.redo=[]; S.proofed=null; S.dirty=false; S.tool="move";
  go("designer");

  try{
    const r=await srv.template(row.id);
    adoptTemplate(r.template, row.id);
  }catch(e){
    apiFail(e, "Opening “"+(row.name||row.id)+"”");
    S.loading=false;
    go("gallery");                       // never sit on a template we could not load
  }
  S.loading=false;
  render();
}
function openStarter(st){
  const t=st.build();
  t.name=st.name+" (copy)";
  /* Offline the id is a local placeholder. Online the SERVER mints it: a
     client-chosen id could collide with an existing template and overwrite it,
     and the random one used here would do exactly that often enough to matter. */
  t.templateId="TPL"+Math.floor(1000+Math.random()*8999);
  t.status="draft"; t.version=1; t.publishedVersion=null; t.activeVersion=null;
  S.tpl=t; S.lang=t.defaultLanguage||"en";
  S.sel=[]; S.undo=[]; S.redo=[]; S.proofed=null; markDirty(); S.tool="move";
  S.baseline=JSON.parse(JSON.stringify(t.objects));
  S.blockRefs={BLK0001:3}; S.blockIgnored={};
  go("designer");
  if(SRV.online) return createOnServer(st);
  toast("Cloned into your school — the starter is never linked live");
}

/**
 * Register the freshly cloned starter with the server and adopt ITS id.
 *
 * Until this returns the template exists only on screen, so the designer is
 * put in a saving state rather than pretending it is already a real template:
 * a clerk who edited for ten minutes and then hit a create failure would
 * otherwise lose the lot with no warning that it had never been saved.
 */
async function createOnServer(st){
  S.creating = true;
  try{
    const seed={
      name:S.tpl.name, page:S.tpl.page, header:S.tpl.header, footer:S.tpl.footer,
      objects:S.tpl.objects, languages:S.tpl.languages,
      defaultLanguage:S.tpl.defaultLanguage, starterId:st&&st.id||null,
      complianceLayers:S.tpl.complianceLayers||[]
    };
    const out=await srv.create(S.docType, seed);
    S.tpl.templateId=out.templateId;
    S.tpl.lockVersion=(out.template&&out.template.lockVersion)||0;
    S.tpl.version=(out.template&&out.template.version)||1;
    S.dirty=false;
    paintStatus(); render();
    toast("Cloned into your school — the starter is never linked live");
  }catch(e){
    apiFail(e, "Creating the template");
    toast("NOT SAVED — this template only exists on screen. Fix the error and try again.");
  }finally{
    S.creating = false;
    // Anything edited WHILE create was in flight still needs saving.
    if (S.dirty) markDirty();
  }
}

function paintGallery(){
  const t=TYPES.find(x=>x.id===S.docType), basis=typeBasis(t);
  const rows=libOf(S.docType), starters=startersFor(S.docType), act=activeTpl(S.docType);
  zq("#galEyebrow").textContent=t.name;
  zq("#galSub").innerHTML = S.loading
    ? `Checking which template is active…`
    : act
    /* activeVersion, NOT publishedVersion. This sentence said "resolves … (v4)"
       while the row beneath it correctly said "In use · v3" — the same
       substitution that made the card claim the newest published version was
       live. A page that contradicts itself two lines apart is worse than one
       that says nothing: the reader cannot tell which half to believe. */
    ? `Every print point resolves <b>${esc(act.name)}</b> (v${act.activeVersion ?? act.publishedVersion}). Exactly one template is active per document type — activating another replaces it everywhere at once.`
    : `<b>Nothing is active for this type yet.</b> A template must be published, then activated, before any print point can resolve it.`;

  const mine=zq("#mineGrid"), st=zq("#starterGrid"); mine.innerHTML=""; st.innerHTML="";
  /* Saved templates render as a LIST; the starters below stay a card grid. */
  mine.classList.add("is-list");

  const card=(o)=>{
    const c=el("div","tpl-card");
    c.innerHTML=`<div class="tpl-card__thumb"></div><div class="tpl-card__body">
      <div class="tpl-card__name">${esc(o.name)}</div>
      <div class="tpl-card__meta">${esc(o.meta)}</div>
      ${o.when?`<div class="tpl-card__when">Edited ${esc(o.when)}</div>`:""}
      <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:auto">${o.chips||""}</div>
      ${o.actions||""}</div>`;
    schematic(o.objects, zq(".tpl-card__thumb",c));
    const th=zq(".tpl-card__thumb",c), nm=zq(".tpl-card__name",c);
    th.style.cursor="pointer"; nm.style.cursor="pointer";
    th.onclick=o.onclick; nm.onclick=o.onclick;
    return c;
  };

  if(!rows.length)
    /* "You have none" and "I have not looked yet" are different answers, and
       showing the first while the second is true is how someone concludes their
       work is gone. */
    mine.appendChild(el("div","note", S.loading
      ? "Loading your templates…"
      : "No templates of this type yet. Start from a starter below, or from a blank page — then publish it and set it active."));

  /* YOUR TEMPLATES IS A LIST, NOT A WALL OF CARDS.
  
     As cards it was unusable at the width the panel gives us: three status
     chips stacked one per line, the third action button CLIPPED OFF the card
     edge, the descriptive line set in the MONO face so prose read like code,
     and 40% of the card spent on a schematic thumbnail that looks the same for
     every template of a type — so the one thing a card is for, telling two
     templates apart, it could not do.
  
     A school has a handful of templates per type and needs to scan them:
     which is live, what changed, what do I do next. A row answers that in one
     line and leaves room for the actions. Starters below stay as cards — there
     the preview IS the difference between them. */
  rows.forEach(row=>{
    const isActive = S.active[S.docType]===row.id;
    const st = templateState(row, isActive);

    const r = el("div","tpl-row" + (isActive?" is-live":""));
    r.innerHTML = `
      <div class="tpl-row__thumb"></div>
      <div class="tpl-row__main">
        <div class="tpl-row__top">
          <span class="tpl-row__name">${esc(row.name)}</span>
          <span class="chip chip--${st.tone}"><span class="dot"></span>${st.label}${
            st.key==="active"&&row.activeVersion?" · v"+row.activeVersion:""}</span>
          ${st.waiting?`<span class="chip chip--published"><span class="dot"></span>v${st.waiting} ready</span>`:""}
          ${st.unsaved?`<span class="chip chip--draft"><span class="dot"></span>draft v${row.version}</span>`:""}
        </div>
        <div class="tpl-row__detail">${esc(st.detail)}</div>
        <div class="tpl-row__when">Edited ${esc(relTime(row.edited))}${
          row.editedBy?` by ${esc(row.editedBy)}`:""}</div>
      </div>
      <div class="tpl-row__acts">
        ${st.waiting
          ? `<button class="btn btn--primary btn--sm" data-act="${row.id}">Make v${st.waiting} live</button>`
          : (!isActive && row.publishedVersion
              ? `<button class="btn btn--primary btn--sm" data-act="${row.id}">Make live</button>`
              /* A never-published template shows the action DISABLED rather
                 than hiding it. Offering nothing leaves the reader to work out
                 why this row has fewer buttons than the one above it; a
                 disabled control with a reason says what is missing. My first
                 pass omitted it, and the suite caught the omission. */
              : (!isActive
                  ? `<button class="btn btn--sm" disabled title="Publish this template first — only a published version can go live">Make live</button>`
                  : ""))}
        <button class="btn btn--sm" data-open="${row.id}">Open</button>
        ${isActive?`<button class="btn btn--ghost btn--sm" data-deact="${row.id}">Deactivate</button>`:""}
        ${(!isActive && !row.publishedVersion && SRV.can.manage)
          /* Only a never-published draft. Anything with a published version
             carries the record of what certificates issued from it said, so it
             is archived, not deleted — and the server enforces that too. */
          ?`<button class="btn btn--ghost btn--sm" data-del="${row.id}" title="Delete this draft">Delete</button>`:""}
      </div>`;
    schematic(buildTpl(row).objects, zq(".tpl-row__thumb", r));
    zq(".tpl-row__name", r).onclick = () => openTemplate(row);
    zq(".tpl-row__thumb", r).onclick = () => openTemplate(row);
    mine.appendChild(r);
  });

  const blank=el("button","tpl-card tpl-card--new");
  blank.innerHTML=`<div class="tpl-card__thumb">＋</div><div class="tpl-card__body">
    <div class="tpl-card__name">Blank canvas</div>
    <div class="tpl-card__meta">A4 portrait · letterhead and page furniture only</div></div>`;
  blank.onclick=()=>{
    const base=starters[0]||STARTERS.find(x=>x.docType===S.docType)||STARTERS[0];
    const tt=base.build(); tt.name="Untitled template"; tt.version=1;
    tt.objects=tt.objects.filter(o=>o.region||o.requiredKey);
    tt.templateId="TPL"+Math.floor(1000+Math.random()*8999);
    tt.status="draft"; tt.publishedVersion=null; tt.activeVersion=null;
    S.tpl=tt; S.sel=[]; S.undo=[]; S.redo=[]; S.proofed=null; markDirty();
    S.baseline=JSON.parse(JSON.stringify(tt.objects)); go("designer");
  };
  st.appendChild(blank);
  starters.forEach(x=>{
    const gap=starterGap(x);
    const chips='<span class="chip">Starter · cloned on use</span>'
      + (gap.length
          ? `<span class="chip" style="color:var(--seal);border-color:var(--seal)" title="${esc(gap.join(", "))}">`
            + `Needs ${gap.length} more field${gap.length>1?"s":""} for ${esc(S.school.board)}</span>`
          : "");
    st.appendChild(card({
      name:x.name,
      meta:x.meta + (gap.length ? " · still to bind: "+esc(gap.map(k=>(FIELD[k]||{label:k}).label).join(", ")) : ""),
      chips, objects:x.build().objects, onclick:()=>openStarter(x)
    }));
  });
  if(!starters.length)
    st.appendChild(el("div","note","No starter is written for this document type under "+esc(S.school.board)+" · "+esc(S.school.state)+" yet. Start from a blank canvas."));
}

/* P8.3 — what will this starter STILL need under the active compliance stack?
   A generic starter is not wrong for carrying fewer fields: tc_plain omits
   CBSE's pre-printed Book No. / Sl. No. precisely because they are CBSE
   artifacts, and adding them would stop it being generic. What IS wrong is
   offering it to a CBSE school with no warning and letting the gap surface at
   publish, several screens later, as two red rows.

   So the gap is named on the card, before the choice — the same reasoning as
   "Set active" being disabled with "Publish it first" rather than failing on
   click. */
function starterGap(x){
  const keep={docType:S.docType, tpl:S.tpl};
  try{
    S.docType=x.docType; S.tpl=x.build();
    const bound=boundKeys(), req=requiredKeysOf();
    return req.filter(k=>!bound.has(k));
  }catch(e){ return []; }
  finally{ S.docType=keep.docType; S.tpl=keep.tpl; }
}

/* ── activation — the act that makes a template the one that prints ───── */
/**
 * Delete a draft.
 *
 * Named plainly and confirmed once. The dialog says what CANNOT be recovered
 * rather than asking "are you sure?" — a question nobody answers no to.
 */
function openDelete(id){
  const row=libOf(S.docType).find(x=>x.id===id); if(!row) return;
  modal("Delete “"+row.name+"”?",
    "This draft was never published, so nothing was ever issued from it.",
    `<p class="note">The design and everything in it goes for good. Uploaded images stay
     in your school's asset store — they are shared, and other templates may use them.</p>
     <p class="note" style="margin-bottom:0">A template that HAS been published cannot be
     deleted: each published version is the record of what a certificate issued from it
     actually said. Those are archived instead.</p>`,
    `<button class="btn" data-close>Keep it</button><span class="spacer"></span>
     <button class="btn btn--primary" id="delGo" style="background:var(--warn);border-color:var(--warn)">Delete draft</button>`, true);
  const g=zq("#delGo");
  if(g) g.onclick=async ()=>{
    g.disabled=true;
    try{
      if(SRV.online) await srv.remove(id);
      S.lib[S.docType]=libOf(S.docType).filter(x=>x.id!==id);
      closeModal(); paintGallery();
      toast("Deleted “"+row.name+"”");
    }catch(e){ apiFail(e, "Deleting"); g.disabled=false; }
  };
}

function openActivate(id){
  const row=libOf(S.docType).find(r=>r.id===id), cur=activeTpl(S.docType);
  const t=TYPES.find(x=>x.id===S.docType);
  modal("Set active — "+row.name, "Exactly one template is active per document type",
    `<div class="gate">
      <div class="gate__row ${cur?"gate--warn":"gate--pass"}">
        <span class="gate__ic">${cur?"▲":"✓"}</span>
        <span><b>${cur?"Replaces "+esc(cur.name)+" (v"+cur.publishedVersion+")":"Nothing is active for "+esc(t.name)+" yet"}</b>
        <span>${cur?"That template stays published and can be reactivated at any time — activation is a pointer, not a deletion."
                   :"Until something is active, no print point can resolve this document type."}</span></span></div>
      <div class="gate__row gate--pass"><span class="gate__ic">✓</span>
        <span><b>Activating v${row.publishedVersion}</b>
        <span>The frozen snapshot, not the draft. Draft v${row.version} keeps editing separately.</span></span></div>
      <div class="gate__row gate--warn"><span class="gate__ic">⇥</span>
        <span><b>Takes effect everywhere at once</b>
        <span>The office print button, the Teacher app and a parent's download all call <span class="mono">active('${esc(S.docType)}')</span>. There is no per-surface rollout.</span></span></div>
     </div>
     <p class="note" style="margin-bottom:0">Certificates already issued are unaffected — each one records the template version that produced it, and that record never changes.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="actGo">Set active</button>`);
  zq("#actGo").onclick=async ()=>{
    /* THIS ONLY EVER UPDATED LOCAL STATE.
    
       It set S.active, repainted, and announced "is now the template every
       print point resolves" — without calling the server. Nothing was
       persisted, so the change survived until the next reload and then
       vanished, while the toast had already said the certificate was live.
       That is the phantom-success class in its most consequential form: the one
       action here that decides what a school legally issues, reporting done
       when nothing happened.
    
       The designer's publish flow went through activateOnServer(); this
       button, the one on the list, did not. */
    const b=zq("#actGo"); if(b) b.disabled=true;
    try{
      if(SRV.online){
        const out=await srv.activate(id);
        S.active[S.docType]=id;
        const r=libOf(S.docType).find(x=>x.id===id);
        if(r) r.activeVersion=out.activeVersion ?? r.publishedVersion;
      }else{
        S.active[S.docType]=id;
      }
      closeModal(); paintGallery();
      toast(row.name+" is now the template every print point resolves");
    }catch(e){
      apiFail(e, "Making it live");
      if(b) b.disabled=false;
    }
  };
}
zq("#mineGrid").addEventListener("click", e=>{
  /* The selector gates which buttons reach the branches below. A branch added
     without adding its attribute here is dead code that fails SILENTLY —
     clicking did nothing at all, no error, no dialog. */
  const b=e.target.closest("button[data-act],button[data-deact],button[data-open],button[data-del]");
  if(!b) return;
  if(b.dataset.open) return openTemplate(libOf(S.docType).find(r=>r.id===b.dataset.open));
  if(b.dataset.del) return openDelete(b.dataset.del);
  if(b.dataset.act) return openActivate(b.dataset.act);
  if(b.dataset.deact){
    const t=TYPES.find(x=>x.id===S.docType);
    modal("Deactivate?", "Nothing will resolve for "+esc(t.name),
      `<p style="margin-top:0;font-size:12.5px;line-height:1.6">With no active template, every print point for <b>${esc(t.name)}</b> fails closed — it refuses to render rather than falling back to some other template. That is the correct behaviour, and it is also a visible outage for the office.</p>`,
      `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
       <button class="btn btn--primary" id="deactGo" style="background:var(--warn);border-color:var(--warn)">Deactivate anyway</button>`, true);
    zq("#deactGo").onclick=async ()=>{
      /* Same defect as activation: this deleted the client's own copy of the
         pointer and announced the type deactivated, with no server call behind
         it. It came back on the next reload — after somebody had been told a
         school had stopped issuing a statutory document. */
      const g=zq("#deactGo"); if(g) g.disabled=true;
      try{
        if(SRV.online) await srv.deactivate(b.dataset.deact);
        delete S.active[S.docType];
        const r=libOf(S.docType).find(x=>x.id===b.dataset.deact);
        if(r) r.activeVersion=null;
        closeModal(); paintGallery();
        toast("Deactivated — this document type has no active template", true);
      }catch(e){
        apiFail(e, "Deactivating");
        if(g) g.disabled=false;
      }
    };
  }
});

/* ==========================================================================
   7 · IN-PLACE TEXT EDITING  (Canva: double-click to edit; Esc commits)
   Production uses Quill 2.0.3 Embed blots — raw contenteditable has known
   caret bugs around contenteditable=false. See REFERENCE_RESEARCH.md §4.1.
   ========================================================================== */
let editBefore=null;

function enterEdit(id, clientX, clientY){
  const o=obj(id);
  if(!o || o.type!=="text" || o.locked) return;
  if(!o.content.i18n[langOf(o)]) o.content.i18n[langOf(o)]={runs:[]};
  editBefore=snapshot();
  S.editing=id; S.sel=[id]; caretOffset=null;
  render();
  const n=nodeFor(id); if(!n) return;
  const inner=zq(".obj__in",n);
  inner.focus({preventScroll:true});
  if(clientX!=null && document.caretRangeFromPoint){
    const r=document.caretRangeFromPoint(clientX, clientY);
    if(r && inner.contains(r.startContainer)){ const s=getSelection(); s.removeAllRanges(); s.addRange(r); }
  }
  showCtxbar();
}
function commitEdit(){
  if(!S.editing) return;
  const o=obj(S.editing), n=nodeFor(S.editing);
  if(o && n){
    const inner=zq(".obj__in",n);
    inner.contentEditable="false";
    o.content.i18n[langOf(o)]={runs:parseRuns(inner)};
    if(!o.name || o.name===o.id) o.name=plainText(o).slice(0,26)||o.id;
  }
  const id=S.editing; S.editing=null; caretOffset=null;
  if(editBefore){ push("Edit text — "+id, editBefore, snapshot()); editBefore=null; }
  render();
}
function plainText(o){
  const d=o.content.i18n&&o.content.i18n[langOf(o)];
  if(!d) return "";
  return d.runs.map(r=> r.f!=null ? (FIELD[r.f]?FIELD[r.f].label:r.f) : r.t).join("").trim();
}
function insertField(key){
  const o=S.sel.length===1?obj(S.sel[0]):null;
  if(S.editing && o){
    const inner=zq(".obj__in",nodeFor(S.editing));
    const sel=getSelection();
    /* the picker modal steals focus, so put the caret back where it was
       before inserting — otherwise every field lands at the end */
    if(!sel.rangeCount || !inner.contains(sel.anchorNode)) restoreCaret();
    let rng = sel.rangeCount ? sel.getRangeAt(0) : null;
    if(!rng || !inner.contains(rng.commonAncestorContainer)){
      rng=document.createRange(); rng.selectNodeContents(inner); rng.collapse(false);
    }
    rng.deleteContents();
    const tmp=el("span"); tmp.innerHTML=chipHTML(key,false);
    const chip=tmp.firstChild;
    rng.insertNode(chip);
    /* always keep a text node after a chip so the caret has somewhere to live */
    const pad=document.createTextNode(" ");
    chip.parentNode.insertBefore(pad, chip.nextSibling);
    const nr=document.createRange(); nr.setStartAfter(pad); nr.collapse(true);
    sel.removeAllRanges(); sel.addRange(nr);
    inner.focus({preventScroll:true}); readCaret();
    toast("Inserted "+key+" — one atomic chip, not typed text");
    return;
  }
  if(o && o.type==="text"){
    const before=snapshot();
    const L=langOf(o);
    const d=o.content.i18n[L] || (o.content.i18n[L]={runs:[]});
    d.runs.push({f:key});
    push("Insert field", before, snapshot()); render();
    toast("Inserted "+key);
    return;
  }
  if(o && o.type==="table"){
    const before=snapshot();
    o.content.rows.push({key}); push("Add table row", before, snapshot()); render();
    toast("Added a row bound to "+key); return;
  }
  toast("Select a text object first, or double-click one to edit");
}
function exec(cmd){
  if(!S.editing) return;
  zq(".obj__in",nodeFor(S.editing)).focus();
  document.execCommand(cmd,false,null);
  paintCtxbar();
}

/* ── floating context toolbar (Canva) ──────────────────────────────────── */
function showCtxbar(){ paintCtxbar(); zq("#ctxbar").classList.add("is-on"); positionCtxbar(); }
function hideCtxbar(){ zq("#ctxbar").classList.remove("is-on"); }
function positionCtxbar(){
  const bar=zq("#ctxbar");
  if(!bar.classList.contains("is-on")) return;
  if(S.sel.length===0){ hideCtxbar(); return; }
  const rects=S.sel.map(nodeFor).filter(Boolean).map(n=>n.getBoundingClientRect());
  if(!rects.length){ hideCtxbar(); return; }
  const L=Math.min(...rects.map(r=>r.left)), R=Math.max(...rects.map(r=>r.right));
  const Tp=Math.min(...rects.map(r=>r.top)), B=Math.max(...rects.map(r=>r.bottom));
  const w=bar.offsetWidth||300, h=bar.offsetHeight||34;
  let x=(L+R)/2-w/2, y=Tp-h-10;
  if(y<62) y=Math.min(B+10, window.innerHeight-h-40);
  x=Math.max(60,Math.min(x,window.innerWidth-w-16));
  bar.style.left=x+"px"; bar.style.top=y+"px";
}
function paintCtxbar(){
  const bar=zq("#ctxbar");
  if(S.sel.length===0){ hideCtxbar(); return; }
  if(S.sel.length>1){
    bar.innerHTML=`<button data-align="left" title="Align left">⇤</button>
      <button data-align="centerX" title="Centre">⇔</button>
      <button data-align="right" title="Align right">⇥</button>
      <button data-align="distributeY" title="Distribute vertically">⇕</button>
      <span class="div"></span>
      <button data-act="dup" title="Duplicate — ⌘D">⧉</button>
      <button data-act="del" title="Delete">🗑</button>`;
    return;
  }
  const o=obj(S.sel[0]); if(!o){ hideCtxbar(); return; }
  const st=o.style||{};
  if(o.type==="text"||o.type==="table"){
    const isEd=S.editing===o.id;
    bar.innerHTML=`
      ${o.type==="text"?`<button data-act="${isEd?"done":"edit"}" title="${isEd?"Finish editing — Esc":"Edit text — Enter or double-click"}" class="${isEd?"is-on":""}">${isEd?"✓":"✎"}</button><span class="div"></span>`:""}
      <select data-p="style.fontFamily" title="Font">
        ${FONTS.map(f=>`<option ${st.fontFamily===f?"selected":""}>${f}</option>`).join("")}
      </select>
      <input type="number" step="0.5" min="4" data-p="style.sizePt" value="${st.sizePt||9}" title="Size (pt)">
      <span class="div"></span>
      ${isEd?`<button class="cbtn" data-exec="bold" title="Bold">B</button>
              <button class="cbtn" data-exec="italic" title="Italic" style="font-style:italic">I</button>
              <button class="cbtn" data-exec="underline" title="Underline" style="text-decoration:underline">U</button>
              <span class="div"></span>`:""}
      ${["left","center","right"].map(a=>`<button data-al="${a}" class="${(st.align||"left")===a?"is-on":""}" title="Align ${a}">${a==="left"?"⯇":a==="center"?"⯀":"⯈"}</button>`).join("")}
      <span class="div"></span>
      <button data-act="colour" title="Text colour"><span class="swatch" style="background:${esc(st.colour||"#14100D")}"></span></button>
      <span class="div"></span>
      <button class="cbtn cbtn--field" data-act="field" title="Insert a merge field at the caret">+ Field</button>`;
    return;
  }
  bar.innerHTML=`<button data-act="dup" title="Duplicate — ⌘D">⧉</button>
    <button data-act="lock" title="Lock position" class="${o.locked?"is-on":""}">🔒</button>
    <button data-act="fwd" title="Bring forward — ⌘]">⤒</button>
    <button data-act="back" title="Send backward — ⌘[">⤓</button>
    <span class="div"></span>
    <button data-act="del" title="Delete">🗑</button>`;
}
zq("#ctxbar").addEventListener("mousedown", e=>{ if(!e.target.closest("input,select")) e.preventDefault(); });
zq("#ctxbar").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  const o=S.sel.length===1?obj(S.sel[0]):null;
  if(b.dataset.exec) return exec(b.dataset.exec);
  if(b.dataset.align) return alignSel(b.dataset.align);
  if(b.dataset.al && o){ const before=snapshot(); o.style.align=b.dataset.al; push("Align text",before,snapshot()); render(); return; }
  const a=b.dataset.act;
  if(a==="edit" && o) return enterEdit(o.id);
  if(a==="done") return commitEdit();
  if(a==="field") return openFieldPicker();
  if(a==="dup") return duplicateSel();
  if(a==="del") return tryDelete();
  if(a==="lock" && o){ const before=snapshot(); o.locked=!o.locked; push("Lock",before,snapshot()); render(); return; }
  if(a==="fwd") return zOrder(1);
  if(a==="back") return zOrder(-1);
  if(a==="colour" && o){
    const cur=(o.style.colour||"#14100D");
    const i=el("input"); i.type="color"; i.value=cur.length===7?cur:"#14100D";
    i.style.cssText="position:fixed;left:-100px";
    document.body.appendChild(i);
    i.addEventListener("input",()=>{ o.style.colour=i.value; layoutPage(); paintCtxbar(); });
    i.addEventListener("change",()=>{ const before=snapshot(); push("Colour",before,snapshot()); i.remove(); render(); });
    i.click();
  }
});
zq("#ctxbar").addEventListener("change", e=>{
  const t=e.target, o=S.sel.length===1?obj(S.sel[0]):null;
  if(!t.dataset.p || !o) return;
  const before=snapshot();
  const k=t.dataset.p.slice(6);
  o.style[k] = t.type==="number" ? parseFloat(t.value) : t.value;
  push("Edit "+t.dataset.p, before, snapshot()); render();
});

function openFieldPicker(){
  if(S.editing) readCaret();
  const bound=boundKeys(), req=new Set(prof().requiredKeys);
  modal("Insert a merge field","Only fields this document type declares",
    `<div class="hintline" style="margin-bottom:10px">A field is inserted as one atomic chip. A caret cannot land inside it, and one backspace removes it whole — so a merge token can never be half-typed or misspelled.</div>
     <div id="pickList" style="max-height:46vh;overflow:auto"></div>`,
    `<button class="btn" data-close>Cancel</button>`, true);
  const L=zq("#pickList");
  contractFor().forEach(f=>{
    const b=el("button","field");
    b.innerHTML=`<span class="field__col"><span class="field__lbl">${esc(f.label)}</span>
      <span class="field__key">${esc(f.key)}</span></span>
      ${f.calc?'<span class="field__tag field__tag--calc">calc</span>':""}
      ${req.has(f.key)?'<span class="field__tag field__tag--req">req</span>':""}
      ${bound.has(f.key)?'<span class="field__tag">used</span>':""}`;
    b.title="Sample: "+f.sample;
    b.onclick=()=>{ closeModal(); insertField(f.key); };
    L.appendChild(b);
  });
}

/* ==========================================================================
   8 · LAYERS  (Figma's list — and the keyboard path to selection)
   ========================================================================== */
const TYPEICON={text:"T",table:"▦",image:"▣",shape:"━",qr:"▩",pageNumber:"#"};

/* ==========================================================================
   CONTENT PANE — the document view
   --------------------------------------------------------------------------
   A certificate is read top-to-bottom as prose and labelled fields, but the
   canvas only offers the shape view of it. Editing one word there means
   finding a 9.5pt target on a zoomable page, entering a mode, and leaving it
   again — and the likeliest misfire is DRAGGING the object instead of editing
   it, silently moving part of a statutory layout.

   This pane is the document view. Two modes:
     edit  every text object in reading order, always editable, no mode switch
     read  the whole certificate as continuous prose, for proofreading

   It edits CONTENT ONLY. Nothing here can move, resize, reorder or delete an
   object. See design/TEXT_EDITING_PROPOSAL.md.
   ========================================================================== */

/* Reading order: resolved top, then left. Not z-order, not creation order —
   reading order is the only order a clerk thinks in. */
function contentOrder(){
  const rank = {header:0, body:1, footer:2};
  return [...S.tpl.objects].sort((a,b)=>
      (rank[a.region||"body"] - rank[b.region||"body"])
   || ((a.yMm||0) - (b.yMm||0))
   || ((a.xMm||0) - (b.xMm||0)));
}

function contentPlain(o){
  const d = o.content && o.content.i18n && o.content.i18n[langOf(o)];
  if(!d || !d.runs) return "";
  return d.runs.map(r=> r.f!=null ? ("{"+(FIELD[r.f]?FIELD[r.f].label:r.f)+"}") : (r.t||"")).join("");
}

/* Harvest one editable row into the model.
   Returns true if the model actually changed. Deliberately does NOT push undo
   and does NOT repaint the pane — the input/blur split below owns both, so that
   a burst of keystrokes is one undo entry rather than one per character. */
function commitContentRow(ed){
  if(!ed || !ed.dataset) return false;
  const o=obj(ed.dataset.cid);
  if(!o || o.type!=="text") return false;
  const runs=parseRuns(ed);
  if(!o.content) o.content={};
  if(!o.content.i18n) o.content.i18n={};
  const cur=o.content.i18n[langOf(o)];
  if(cur && JSON.stringify(cur.runs)===JSON.stringify(runs)) return false;
  o.content.i18n[langOf(o)]={runs};
  return true;
}

/* The row the user is typing in right now, or null. */
function liveContentRow(){
  const wrap=zq("#contentList"), a=document.activeElement;
  return (wrap && a && a.isContentEditable && wrap.contains(a)) ? a : null;
}

/* P4.5 — capacity hint. ADVISORY ONLY: the real gate is P2.7, which measures
   the rendered block in mPDF. This is the cheap up-front signal so a clerk is
   not surprised at proof time, and it is deliberately phrased with "≈" because
   a character budget cannot know the font, the width or the line height.

   maxLen comes from the contract and is kept identical to the server's copy by
   DocContractParityTest — if the two drifted, this hint would confidently tell
   someone a wrong number. */
function capacityHint(o){
  if(o.type!=="text") return null;
  const d=o.content && o.content.i18n && o.content.i18n[langOf(o)];
  if(!d || !d.runs || !d.runs.length) return null;

  /* The budget belongs to the bound field, not the object. An object with no
     field is free text the compliance stack does not govern — no budget. */
  let budget=0, key=null;
  d.runs.forEach(r=>{
    if(r.f==null) return;
    const f=FIELD[r.f];
    if(f && f.maxLen && f.maxLen>budget){ budget=f.maxLen; key=r.f; }
  });
  if(!budget) return null;

  /* What the CURRENT sample actually RENDERS to.
     NOT contentPlain(): that substitutes the design-time placeholder "{School
     name}", so the count was the label's length and never moved when the p95
     toggle did — which defeats the one mode whose entire purpose is showing the
     worst case. Caught by E2E O5. Resolve through fieldValue(), the same
     function the canvas previews with. */
  if(S.data==="off") return {budget, used:null, key, over:false};
  let used=0;
  d.runs.forEach(r=>{
    if(r.f!=null){ const v=fieldValue(r.f); used += v==null ? 0 : String(v).length; }
    else used += (r.t||"").length;
  });
  return {budget, used, key, over: used>budget};
}

function paintContent(){
  const wrap=zq("#contentList"); if(!wrap) return;
  const hint=zq("#contentHint");

  /* Never rebuild underneath a live edit. innerHTML="" would destroy the
     focused node mid-keystroke and the edit would vanish into a detached
     element — the exact failure UX_SPEC §5A.4 records. Harvest what is on
     screen so the model stays true, then defer the repaint until the edit is
     released (see the blur handler). */
  const live=liveContentRow();
  if(live){ commitContentRow(live); wrap._deferred=true; return; }
  wrap._deferred=false;

  wrap.innerHTML="";

  if(S.cmode==="read"){
    hint.innerHTML="Proofreading view — the whole document as continuous text. <b>Read only.</b>";
    const box=el("div","");
    box.style.cssText="font-size:12px;line-height:1.65;white-space:pre-wrap;"
      +"padding:10px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--raised)";
    let last=null, out="";
    contentOrder().forEach(o=>{
      if(o.type!=="text") return;
      const reg=o.region||"body";
      if(reg!==last){ out += (out?"\n\n":"") + "— "+reg.toUpperCase()+" —\n"; last=reg; }
      const t=contentPlain(o).trim();
      if(t) out += t + "\n";
    });
    box.textContent = out.trim() || "This template has no text yet.";
    wrap.appendChild(box);
    return;
  }

  hint.innerHTML="Click any line and type. Fields come from the <b>Fields</b> pane. "
                +"Nothing here can move or resize an object.";

  let lastRegion=null;
  contentOrder().forEach(o=>{
    const reg=o.region||"body";
    if(reg!==lastRegion){
      wrap.appendChild(el("div","layer__grp",esc(reg==="body"?"Body":reg[0].toUpperCase()+reg.slice(1)+" region")));
      lastRegion=reg;
    }

    const row=el("div","");
    row.style.cssText="margin-bottom:9px";

    const lab=el("div","");
    lab.style.cssText="display:flex;align-items:center;gap:5px;margin-bottom:3px;"
      +"font-family:var(--font-m);font-size:9px;letter-spacing:.06em;color:var(--ink3)";
    lab.innerHTML=`<i style="font-style:normal">${TYPEICON[o.type]||"◻"}</i>`
      +`<span>${esc(o.name||o.id)}</span>`
      +(o.requiredKey?' <span style="color:var(--seal)" title="required by the active compliance stack">✦</span>':"")
      +(o.locked?' <span title="position locked">🔒</span>':"");
    row.appendChild(lab);

    if(o.type!=="text"){
      const na=el("div","", typeLabel(o.type).toLowerCase()+" — not text");
      na.style.cssText="font-size:11px;color:var(--ink4);padding:5px 7px;border:1px dashed var(--line);"
        +"border-radius:var(--r-xs);background:var(--sunk)";
      na.onclick=()=>{ S.sel=[o.id]; render(); };
      row.appendChild(na);
      wrap.appendChild(row); return;
    }

    const d=o.content && o.content.i18n && o.content.i18n[langOf(o)];
    const ed=el("div","");
    ed.contentEditable="true";
    ed.spellcheck=true;
    ed.dataset.cid=o.id;
    ed.style.cssText="font-size:12px;line-height:1.5;padding:6px 8px;border-radius:var(--r-xs);"
      +"border:1px solid "+(S.sel.includes(o.id)?"var(--clay)":"var(--line2)")+";"
      +"background:var(--panel);min-height:26px;outline:none";
    ed.innerHTML = (d && d.runs && d.runs.length) ? runsHTML(d.runs,false) : "";

    if(!(d && d.runs && d.runs.length)){
      ed.dataset.empty = langExplicit(o) ? "Not translated for this language" : "Empty";
    }

    /* Capture the undo baseline once per editing burst. Armed from focus AND
       from the first input: focus events are not dispatched at all while the
       window lacks OS focus, so focus alone is not a reliable arming point. */
    const arm=()=>{ if(ed._before==null) ed._before=snapshot(); };

    ed.addEventListener("focus", ()=>{
      if(S.sel.length!==1 || S.sel[0]!==o.id){ S.sel=[o.id]; paintLayers(); paintInspector(); paintCtxbar(); }
      arm();
    });

    /* Commit on every keystroke so the model is never behind the screen — an
       edit cannot be lost by switching window, closing the tab, or any other
       path where blur never arrives. Undo is NOT pushed here (that would be one
       entry per character) and the pane is NOT repainted (that would destroy
       this node); the canvas repaints so it stays a live preview. */
    ed.addEventListener("input", ()=>{
      arm();
      if(!commitContentRow(ed)) return;
      markDirty();
      layoutPage(); paintStatus();
    });

    /* Blur ends the burst: one undo entry for the whole thing, then the full
       repaint that was deferred while this node was live. */
    ed.addEventListener("blur", ()=>{
      const before=ed._before; ed._before=null;
      commitContentRow(ed);
      /* Compare against the baseline armed at the START of the burst, not
         against the model as it stood a moment ago: `input` has already
         committed every keystroke, so a blur-time diff is always empty and
         the undo entry would never be pushed. */
      const after=snapshot();
      const changed = before!=null && before!==after;
      if(changed) push("Edit text — "+(o.name||o.id), before, after);
      const wrap=zq("#contentList");
      if(changed || (wrap && wrap._deferred)) render();
    });
    /* Enter commits rather than inserting a paragraph: these are single fields
       in a form, not a word processor. Shift+Enter still breaks a line. */
    ed.addEventListener("keydown", e=>{
      if(e.key==="Enter" && !e.shiftKey){ e.preventDefault(); ed.blur(); }
      if(e.key==="Escape"){ e.preventDefault(); ed.blur(); }
    });

    row.appendChild(ed);
    const cap=capacityHint(o);
    if(cap){
      const c=el("div","");
      c.style.cssText="font-family:var(--font-m);font-size:9px;letter-spacing:.04em;margin-top:3px;"
        +"color:"+(cap.over?"var(--seal)":"var(--ink4)");
      c.textContent = "\u2248"+cap.budget+" chars fit \u00b7 "
        + (cap.used==null ? "no sample data" : "sample uses "+cap.used)
        + (cap.over ? "  \u2014 over budget, the proof gate will measure it" : "");
      c.title = "Advisory. The binding budget for "+cap.key+" is "+cap.budget
        +" characters; the real check is the proof-time overflow gate, which measures the rendered block.";
      row.appendChild(c);
    }

    wrap.appendChild(row);
  });

  if(!wrap.children.length){
    wrap.appendChild(el("div","hintline","This template has no objects yet."));
  }
}

function paintLayers(){
  const L=zq("#layerList"); L.innerHTML="";
  const groups=[["header","Header region"],["body","Body"],["footer","Footer region"]];
  groups.forEach(([g,label])=>{
    const items=S.tpl.objects.filter(o=>(o.region||"body")===g).sort((a,b)=>(b.z||0)-(a.z||0));
    if(!items.length) return;
    L.appendChild(el("div","layer__grp",esc(label)));
    items.forEach(o=>{
      const b=el("button","layer"+(S.sel.includes(o.id)?" is-sel":"")+(o.requiredKey?" is-req":""));
      b.innerHTML=`<i class="layer__ic">${TYPEICON[o.type]||"◻"}</i>
        <span class="layer__nm">${esc(o.name||o.id)}</span>
        ${o.lang?`<span class="layer__tag" style="color:var(--info)" title="language mode set on this object">${esc(o.lang.toUpperCase())}</span>`:""}
        ${o.anchorTo?'<span class="layer__tag" title="anchored">⇩</span>':""}
        ${o.requiredKey?'<span class="layer__tag" style="color:var(--seal)" title="required">✦</span>':""}
        <span class="layer__btn" data-lay="vis" title="Show / hide">${S.hidden[o.id]?"◌":"◉"}</span>
        <span class="layer__btn" data-lay="lock" title="Lock position">${o.locked?"🔒":"🔓"}</span>`;
      b.onclick=e=>{
        const t=e.target.closest("[data-lay]");
        if(t && t.dataset.lay==="vis"){ S.hidden[o.id]=!S.hidden[o.id]; render(); return; }
        if(t && t.dataset.lay==="lock"){ const before=snapshot(); o.locked=!o.locked; push("Lock",before,snapshot()); render(); return; }
        if(e.shiftKey){ S.sel.includes(o.id)?S.sel=S.sel.filter(s=>s!==o.id):S.sel.push(o.id); }
        else S.sel=[o.id];
        commitEdit(); render(); showCtxbar();
      };
      b.ondblclick=()=>{ if(o.type==="text") enterEdit(o.id); };
      L.appendChild(b);
    });
  });
}

/* ==========================================================================
   9 · INSPECTOR
   ========================================================================== */
function paintInspector(){
  const B=zq("#objBody"), badge=zq("#objBadge");
  if(S.sel.length===0){
    badge.innerHTML="";
    B.innerHTML=`<div class="empty-insp"><div class="empty-insp__ic">⌖</div>
      <p>Select an object on the page or in <b>Layers</b>.<br>
      <span class="kbd">shift</span>+click adds to the selection · drag on empty paper to marquee ·
      <span class="kbd">↑↓←→</span> nudges 1&nbsp;mm · <span class="kbd">alt</span> while hovering measures.</p></div>`;
    return;
  }
  if(S.sel.length>1){
    badge.innerHTML=`<span class="chip">${S.sel.length} selected</span>`;
    B.innerHTML=`${alignRow()}
      <div class="hintline">Align and distribute act on the whole selection.</div>
      <div class="row"><button class="btn btn--sm" data-act="dup">Duplicate</button>
      <button class="btn btn--sm" data-act="del" style="color:var(--seal);border-color:var(--seal-ring)">Delete</button></div>`;
    return;
  }
  const o=obj(S.sel[0]), st=o.style||{}, req=o.requiredKey;
  badge.innerHTML = req ? '<span class="chip chip--statutory"><span class="dot"></span>Required</span>'
                        : `<span class="chip">${esc(typeLabel(o.type))}</span>`;
  const lhBad = (o.type==="text"||o.type==="table") && (st.lineHeight==null||st.lineHeight==="");
  B.innerHTML = alignRow() + `
    <div class="row row--1"><div class="inp"><label>Name</label><input data-p="name" value="${esc(o.name||o.id)}"></div></div>
    <div class="row">
      <div class="inp"><label>X mm</label><input type="text" inputmode="decimal" data-num data-p="xMm" value="${mm(o.xMm)}"></div>
      <div class="inp"><label>Y mm</label><input type="text" inputmode="decimal" data-num data-p="yMm" value="${mm(resolvedY(o))}" ${o.anchorTo?"disabled title='Anchored — Y resolves at render'":""}></div>
    </div>
    <div class="row">
      <div class="inp"><label>W mm</label><input type="text" inputmode="decimal" data-num data-p="wMm" value="${mm(o.wMm)}"></div>
      <div class="inp"><label>H mm</label><input type="text" inputmode="decimal" data-num data-p="hMm" value="${mm(o.hMm)}" ${o.height==="auto"?"disabled title='Auto height — resolved from content'":""}></div>
    </div>

    <div class="subhead">Flow</div>
    <div class="row row--1"><div class="seg seg--fill">
      <button data-h="fixed" class="${o.height==="fixed"?"is-on":""}">Fixed height</button>
      <button data-h="auto"  class="${o.height==="auto"?"is-on":""}">Auto-grow</button></div></div>
    ${o.height==="auto"?`<div class="row">
      <div class="inp"><label>Max H mm</label>
        <input type="text" inputmode="decimal" data-num data-p="maxHMm" value="${o.maxHMm!=null?mm(o.maxHMm):""}" placeholder="none"></div>
      <div class="inp"><label>Resolved</label>
        <input value="${mm(S.measured[o.id]||o.hMm)}${S.clamped[o.id]?" ⚠":""}" disabled></div>
    </div>
    <div class="hintline">A ceiling on auto-grow. Without one, an over-long field pushes everything anchored below it off the sheet — including the signature block.</div>`:""}
    <div class="row row--1"><div class="seg seg--fill">
      <button data-flow="abs"  class="${o.anchorTo?"":"is-on"}">Absolute</button>
      <button data-flow="flow" class="${o.anchorTo?"is-on":""}">In flow (anchored)</button></div></div>
    <div class="row">
      <div class="inp"><label>Anchor to</label><select data-p="anchorTo">
        <option value="">— absolute —</option>
        ${bodyObjects().filter(x=>x.id!==o.id).map(x=>`<option value="${x.id}" ${o.anchorTo===x.id?"selected":""}>${esc(x.name||x.id)}</option>`).join("")}
      </select></div>
      <div class="inp"><label>Gap mm</label><input type="text" inputmode="decimal" data-num data-p="anchorGapMm" value="${o.anchorGapMm||0}" ${o.anchorTo?"":"disabled"}></div>
    </div>
    <div class="hintline">An anchored object holds a <b>gap below</b> its neighbour, never an absolute Y. Real data decides the final position — which is why the p95 toggle matters.<br>Every mm field takes a sum: <span class="mono">210/2</span>, <span class="mono">96.5+3</span>.</div>

    ${(o.type==="text"||o.type==="table")?`
    <div class="subhead">Type</div>
    <div class="row row--3">
      <div class="inp"><label>Size pt</label><input type="text" inputmode="decimal" data-num data-p="style.sizePt" value="${st.sizePt||9}"></div>
      <div class="inp ${lhBad?"inp--bad":""}"><label>Line ht</label><input type="text" inputmode="decimal" data-num data-p="style.lineHeight" value="${st.lineHeight!=null?st.lineHeight:""}"></div>
      <div class="inp"><label>Weight</label><select data-p="style.weight">
        ${[400,600,700].map(w=>`<option value="${w}" ${(st.weight||400)==w?"selected":""}>${w}</option>`).join("")}
      </select></div>
    </div>
    ${lhBad?`<div class="inp__err" style="margin:-4px 0 8px">Line height is mandatory. Without it mPDF and the browser disagree — Tamil measured 18.03&nbsp;mm against 9.53&nbsp;mm on one block. Publish will block.</div>`:""}
    <div class="row row--1"><div class="seg seg--fill">
      ${["left","center","right"].map(a=>`<button data-al="${a}" class="${(st.align||"left")===a?"is-on":""}">${a}</button>`).join("")}
    </div></div>`:""}

    ${o.type==="table"?`
    <div class="subhead">Rows — ${o.content.rows.length}</div>
    <div class="rowlist" id="rowList"></div>
    <button class="btn btn--sm" data-act="addrow">+ Add row</button>`:""}

    ${o.type==="text"?`<div class="row row--1" style="margin-top:10px">
      <button class="btn btn--sm" data-act="content">¶ Edit in Content <span class="btn__hint">no mode</span></button></div>`:""}

    ${o.type==="image"?`
    <div class="subhead">Asset</div>
    <div class="assetbox">
      <div class="assetbox__prev${o.asset?"":" assetbox__prev--empty"}">
        ${o.asset?`<img src="${o.asset.dataUrl}" alt="">`
                 :(o.bindKey?"resolved per document":"drop a file here, paste, or double-click")}
      </div>
      ${o.asset?`
        <div class="mono" style="font-size:10px;color:var(--ink3);word-break:break-all">${esc(o.asset.name)}
          · ${o.asset.wPx}×${o.asset.hPx}px · ${(o.asset.bytes/1024).toFixed(0)} KB</div>
        ${(()=>{ const d=assetDpi(o); if(!d) return "";
          const bad=d<MIN_DPI;
          return `<div class="dpi"><span class="dpi__dot" style="background:${bad?"var(--warn)":"var(--ok)"}"></span>
            <span style="color:${bad?"var(--warn)":"var(--ink3)"}">${d} dpi at ${mm(o.wMm)} mm${bad?" — soft in print":" — fine for print"}</span></div>`;})()}
        ${o.assetKind==="signature"&&!o.asset.hasAlpha?`<div class="inp__err" style="margin-top:5px">No transparency. A signature on a white block will print as a white block over the ruled line — export a PNG with an alpha channel.</div>`:""}
        <div class="row" style="margin-top:8px;margin-bottom:0">
          <button class="btn btn--sm" data-act="replaceAsset">Replace</button>
          <button class="btn btn--sm" data-act="clearAsset" style="color:var(--seal);border-color:var(--seal-ring)">Remove</button>
        </div>`
      :`<button class="btn btn--sm" data-act="replaceAsset" style="width:100%">Choose a file…</button>`}
      ${o.assetKind==="signature"?`<button class="btn btn--sm" data-act="drawSig" style="width:100%;margin-top:6px">✎ Draw a signature instead</button>`:""}
    </div>
    <div class="row row--1" style="margin-top:8px"><div class="inp"><label>Kind</label>
      <select data-p="assetKind">
        ${Object.entries(ASSET_KINDS).map(([k,v])=>`<option value="${k}" ${o.assetKind===k?"selected":""}>${esc(v.label)}</option>`).join("")}
      </select></div></div>
    <div class="hintline">${esc((ASSET_KINDS[o.assetKind]||{}).hint||"")}</div>
    <div class="row row--1"><div class="inp"><label>Or bind to a field (per document)</label>
      <select data-p="bindKey">
        <option value="" ${!o.bindKey?"selected":""}>— static asset, same on every document —</option>
        ${contractFor().filter(f=>f.type==="image").map(f=>`<option value="${f.key}" ${o.bindKey===f.key?"selected":""}>${esc(f.label)}</option>`).join("")}
      </select></div></div>
    <div class="hintline">A crest, signature or seal is <b>static</b> — uploaded once, identical on every certificate. A photograph or verification QR is <b>data</b> and resolves per document. The same object type does both, so say which.</div>
    ${o.assetKind==="signature"?`<div style="border:1px solid var(--seal-ring);background:var(--seal-dim);border-radius:var(--r-sm);padding:9px 10px;margin-top:9px">
      <b style="font-size:11.5px;color:var(--seal);display:block;margin-bottom:3px">A scanned signature is a picture, not a signature</b>
      <div style="font-size:10.5px;color:var(--ink2);line-height:1.5">Under the IT Act 2000 legal weight comes from a <b>digital signature</b> (s.3, a DSC from a licensed Certifying Authority) or a recognised <b>electronic signature</b> (s.3A, Second Schedule — Aadhaar eSign and the like), and s.5 makes those equivalent to a handwritten signature. An image pasted onto a PDF is neither. This is fine for a document whose authenticity is carried elsewhere — ours is the verification QR — but it must not be described to a school as "digitally signed".</div>
    </div>`:""}`:""}

    ${o.type==="text"?`
    <div class="subhead">Language mode</div>
    <div class="row row--1"><div class="inp"><select data-p="lang">
      <option value="" ${!o.lang?"selected":""}>Auto — inherit (${esc(LANGS[langOf(o)].native)})</option>
      ${Object.entries(LANGS).map(([k,v])=>`<option value="${k}" ${o.lang===k?"selected":""}>${esc(v.native)} — pinned on this object</option>`).join("")}
    </select></div></div>
    <div class="hintline">Auto walks up to the region, then the template default. Pinning one object — or a whole region — is how a <b>Hindi letterhead over an English body</b> works without a second template.</div>`:""}

    <div class="subhead">Visibility</div>
    <div class="row row--1"><div class="inp"><label>Show this object</label>
      <select data-p="showWhen">
        <option value="" ${!o.showWhen?"selected":""}>Always</option>
        ${contractFor().map(f=>`<option value="${f.key}" ${o.showWhen===f.key?"selected":""}>Only when “${esc(f.label)}” has a value</option>`).join("")}
      </select></div></div>
    ${o.showWhen?`<div class="hintline">Conditional. The compliance profile can drive this — CBSE's countersignature block, for example, applies only when the originating board is not CBSE.</div>`:""}

    <div class="subhead">Binding</div>
    ${req?`
      <div style="border:1px solid var(--seal-ring);background:var(--seal-dim);border-radius:var(--r-sm);padding:9px 10px">
        <div style="display:flex;gap:7px;align-items:center;margin-bottom:4px">
          <span class="lvl lvl--${(keyAuthority(req)||{}).evidence||"D"}">Level ${(keyAuthority(req)||{}).evidence||"—"}</span>
          <b style="font-size:11.5px">Statutory field</b></div>
        <div style="font-size:10.5px;color:var(--ink2);margin-bottom:3px">Required by <b>${esc((keyAuthority(req)||{}).label||"—")}</b></div>
        <div class="mono" style="font-size:10.5px;color:var(--ink2)">${esc(req)}</div>
        <div style="font-size:10.5px;color:var(--ink2);margin-top:5px;line-height:1.45">
          Move it, restyle it, translate it, change its font — all free. It cannot be deleted, and publish blocks while it is unbound.</div>
        <button class="btn btn--sm" style="margin-top:8px" data-cite="${esc(req)}">Why is this required?</button>
      </div>`:`<div class="hintline">No compliance binding. This object is yours entirely — delete it, move it, replace it.</div>`}

    <div class="subhead">Arrange</div>
    <div class="row row--1"><div class="seg seg--fill">
      <button data-lock="0" class="${!o.locked?"is-on":""}">Unlocked</button>
      <button data-lock="1" class="${o.locked?"is-on":""}">Position locked</button></div></div>
    <div class="row"><button class="btn btn--sm" data-act="fwd">Bring forward</button>
      <button class="btn btn--sm" data-act="back">Send backward</button></div>
    <div class="row"><button class="btn btn--sm" data-act="dup">Duplicate</button>
      <button class="btn btn--sm" data-act="del" style="color:var(--seal);border-color:var(--seal-ring)">Delete</button></div>`;

  if(o.type==="table"){
    const RL=zq("#rowList");
    o.content.rows.forEach((r,i)=>{
      const row=el("div","rowitem");
      row.innerHTML=`<select data-row="${i}">${contractFor().map(f=>`<option value="${f.key}" ${r.key===f.key?"selected":""}>${esc(f.label)}</option>`).join("")}</select>
        <button data-delrow="${i}" title="Remove row">✕</button>`;
      RL.appendChild(row);
    });
  }
}
function alignRow(){
  const one=S.sel.length<2;
  const t=n=>one?n+" (to page margins)":n;
  return `<div class="alignrow">
    <button data-align="left"    title="${t("Align left")} — ⌥A">⇤</button>
    <button data-align="centerX" title="${t("Align horizontal centres")} — ⌥H">⇔</button>
    <button data-align="right"   title="${t("Align right")} — ⌥D">⇥</button>
    <button data-align="top"     title="${t("Align top")} — ⌥W">⤒</button>
    <button data-align="middleY" title="${t("Align vertical centres")} — ⌥V">⇳</button>
    <button data-align="bottom"  title="${t("Align bottom")} — ⌥S">⤓</button>
    <button data-align="distributeX" title="Distribute horizontal spacing">⇹</button>
    <button data-align="distributeY" title="Distribute vertical spacing">⇵</button></div>`;
}
function paintPagePanel(){
  const p=S.tpl.page;
  zq("#pageBody").innerHTML=`
    <div class="row">
      <div class="inp"><label>Size</label><select data-page="size">
        ${Object.keys(PAPER).map(s=>`<option ${p.size===s?"selected":""}>${s}</option>`).join("")}</select></div>
      <div class="inp"><label>Orientation</label><select data-page="orientation">
        <option ${p.orientation==="portrait"?"selected":""}>portrait</option>
        <option ${p.orientation==="landscape"?"selected":""}>landscape</option></select></div>
    </div>
    <div class="row"><div class="inp"><label>Margin top mm</label><input type="text" inputmode="decimal" data-num data-page="t" value="${p.marginsMm.t}"></div>
      <div class="inp"><label>Bottom mm</label><input type="text" inputmode="decimal" data-num data-page="b" value="${p.marginsMm.b}"></div></div>
    <div class="row"><div class="inp"><label>Left mm</label><input type="text" inputmode="decimal" data-num data-page="l" value="${p.marginsMm.l}"></div>
      <div class="inp"><label>Right mm</label><input type="text" inputmode="decimal" data-num data-page="r" value="${p.marginsMm.r}"></div></div>
    <div class="hintline">The top margin is what reserves space for the header band. Chrome placed as a floating object reserves nothing and collides with row 1 — a real finding from the TC specimen, not a theory.</div>
    <div class="row row--1"><div class="inp"><label>Page mode</label>
      <select disabled><option>single — one page, overflow is an error</option></select></div></div>
    <div class="row row--1"><div class="inp"><label>Language fallback</label>
      <select disabled><option>block — a missing translation stops the render</option></select></div></div>
    <div class="hintline">Statutory documents use <b>block</b>. Falling back to English on a Hindi certificate would issue a document nobody asked for.</div>
    <div class="subhead">Language by region</div>
    ${["header","body","footer"].map(r=>`<div class="row row--1"><div class="inp"><label>${r}</label>
      <select data-region="${r}">
        <option value="" ${!(S.tpl.regionLang||{})[r]?"selected":""}>Auto — template default</option>
        ${Object.entries(LANGS).map(([k,v])=>`<option value="${k}" ${(S.tpl.regionLang||{})[r]===k?"selected":""}>${esc(v.native)}</option>`).join("")}
      </select></div></div>`).join("")}
    <div class="hintline">A region mode covers everything inside it that is still on Auto. An object can still pin its own.</div>`;
}

/* ==========================================================================
   10 · COMPLIANCE
   ========================================================================== */
function validate(){
  const p=prof(), bound=boundKeys(), blocking=[], warnings=[];
  /* An image with no source. The serializer REFUSES to render one, so without
     this the first symptom is a proof that fails after all the work is done —
     and the shipped Annexure-I starter carries a School crest object, so every
     new Transfer Certificate template hits it until a crest is uploaded. Found
     on the first live run. Kept identical to the server's check in
     Doc_templates::validate(). */
  S.tpl.objects.forEach(o=>{
    if(o.type==="image" && !(o.content && o.content.src))
      warnings.push({type:"nosrc", id:o.id, name:o.name||o.id});
  });
  p.requiredKeys.forEach(k=>{ if(!bound.has(k)) blocking.push({type:"unbound", key:k}); });
  S.tpl.objects.filter(o=>o.type==="text"||o.type==="table").forEach(o=>{
    if(o.style && (o.style.lineHeight==null||o.style.lineHeight==="")) blocking.push({type:"lineheight", id:o.id});
  });
  S.tpl.objects.forEach(o=>{
    if(o.height==="fixed" && S.measured[o.id]>o.hMm+0.4) warnings.push({type:"overflow", id:o.id});
    /* content that does not fit is content that will not print — on a
       statutory field, silent truncation is the worst possible outcome */
    if(S.clamped[o.id]>0.05) blocking.push({type:"clamped", id:o.id, over:S.clamped[o.id]});
  });
  const byRegion={};
  S.tpl.objects.forEach(o=>{
    if(o.type==="shape") return;
    const g=o.region||"body"; (byRegion[g]=byRegion[g]||[]).push(
      {id:o.id, x:o.xMm, y:resolvedY(o), w:o.wMm, h:S.measured[o.id]||o.hMm});
  });
  Object.values(byRegion).forEach(bs=>{
    for(let i=0;i<bs.length;i++) for(let j=i+1;j<bs.length;j++){
      const a=bs[i], b=bs[j];
      if(a.x<b.x+b.w-0.5 && a.x+a.w>b.x+0.5 && a.y<b.y+b.h-0.5 && a.y+a.h>b.y+0.5)
        warnings.push({type:"overlap", id:a.id, other:b.id});
    }
  });
  /* compliance that changes WHICH document you are issuing. If the routing
     condition holds at the current data, this template is the wrong instrument
     and no amount of correcting its fields makes it right. */
  stackActive().forEach(l=>(l.rule.routesTo||[]).forEach(r=>{
    let fired=false; try{ fired=!!r.test(); }catch(e){}
    if(fired) blocking.push({type:"wrongInstrument", route:r, authority:l.a});
  }));

  offContractKeys().forEach(k=>blocking.push({type:"offContract", key:k}));

  /* KER r.22 / TNER r.44 / CBSE r.8(vi) prescribe that a reissue is MARKED.
     TN even prescribes the colour. This is the one rendering feature a statute
     actually specifies, so it is enforced rather than suggested. */
  const dupReq = stackActive().map(l=>({a:l.a, d:l.rule.duplicateMark})).filter(x=>x.d && x.d.required);
  if(dupReq.length){
    const marks=S.tpl.objects.filter(o=>o.showWhen==="doc.isDuplicate");
    if(!marks.length){
      blocking.push({type:"noDuplicateMark", req:dupReq});
    }else{
      const wantsRed=dupReq.find(x=>x.d.colour);
      if(wantsRed){
        const ok=marks.some(o=>{
          const c=((o.style||{}).colour||"").toLowerCase();
          if(!/^#[0-9a-f]{6}$/.test(c)) return false;
          const r=parseInt(c.slice(1,3),16), g=parseInt(c.slice(3,5),16), b=parseInt(c.slice(5,7),16);
          return r>120 && r>g*1.6 && r>b*1.6;
        });
        if(!ok) warnings.push({type:"dupNotRed", authority:wantsRed.a, citation:wantsRed.d.citation});
      }
    }
  }

  /* signatures: presence is prescribed, and CBSE prescribes the ORDER */
  const wantSigs=prof().requiredSignatures||[];
  if(wantSigs.length){
    const have=S.tpl.objects.filter(o=>o.sigRole);
    const haveRoles=have.map(o=>o.sigRole);
    wantSigs.filter(r=>!haveRoles.includes(r)).forEach(r=>blocking.push({type:"noSignature", role:r}));
    const present=have.filter(o=>wantSigs.includes(o.sigRole))
      .sort((a,b)=>(resolvedY(a)-resolvedY(b)) || (a.xMm-b.xMm))
      .map(o=>o.sigRole);
    const wanted=wantSigs.filter(r=>haveRoles.includes(r));
    if(present.length===wanted.length && present.join(">")!==wanted.join(">"))
      warnings.push({type:"sigOrder", got:present, want:wanted});
  }

  S.tpl.objects.filter(o=>o.type==="image").forEach(o=>{
    if(o.bindKey) return;
    if(!o.asset){ warnings.push({type:"noAsset", id:o.id}); return; }
    const d=assetDpi(o);
    if(d && d<MIN_DPI) warnings.push({type:"lowDpi", id:o.id, dpi:d});
    if(o.assetKind==="signature" && !o.asset.hasAlpha) warnings.push({type:"noAlpha", id:o.id});
  });

  const cov=translationCoverage("hi");
  if(S.tpl.languages.includes("hi") && cov.done<cov.total) warnings.push({type:"untranslated", n:cov.total-cov.done});
  if(!S.proofed) blocking.push({type:"noproof"});
  return {blocking, warnings};
}
function paintCompliance(){
  const C=zq("#compBody"), v=validate(), bound=boundKeys();
  const st=resolveStack(), on=st.filter(l=>!l.off);
  const req=requiredKeysOf(), done=req.filter(k=>bound.has(k)).length;
  const sc=S.school;

  zq("#compBadge").innerHTML = !on.length ? '<span class="chip">Not checked</span>'
    : (v.blocking.length ? `<span class="chip chip--statutory"><span class="dot"></span>${v.blocking.length} blocking</span>`
                         : '<span class="chip chip--active"><span class="dot"></span>Clear</span>');

  const ring = req.length
    ? `<div class="comp__ring" style="background:conic-gradient(var(--ok) ${done/req.length*360}deg, var(--sunk) 0)">
         <span style="position:absolute;inset:3px;border-radius:50%;background:var(--raised)"></span>
         <b>${done}/${req.length}</b></div>`
    : `<div class="comp__ring" style="background:var(--sunk)">
         <span style="position:absolute;inset:3px;border-radius:50%;background:var(--raised)"></span>
         <b style="font-size:8px">—</b></div>`;

  C.innerHTML = `
    <div class="basis">
      ${ring}
      <div style="flex:1;min-width:0">
        <div class="basis__t">${esc(sc.board)} · ${esc(sc.state)}</div>
        <div class="basis__s">${esc(STAGES[sc.stage])}</div>
      </div>
      <button class="btn btn--sm" id="basisBtn">Change</button>
    </div>
    <div id="lyrs"></div>
    <div id="rulesList" class="rules"></div>`;

  const LY=zq("#lyrs");
  st.forEach(l=>{
    const w=el("div","lyr"+(l.off?" is-off":""));
    const keys=(l.rule.requiredKeys||[]).length;
    w.innerHTML=`
      <div class="lyr__head">
        <div class="lyr__t">
          <div class="lyr__n">${esc(l.a.label)}${l.rule.form?` <span class="mono" style="font-weight:400;color:var(--ink3)">· ${esc(l.rule.form)}</span>`:""}</div>
          <div class="lyr__a">${esc(l.a.authority)}</div>
          <div class="lyr__m">
            <span class="tier tier--${l.a.tier}">${l.a.tier==="board"?"Central board":l.a.tier}</span>
            <span class="lvl lvl--${l.a.evidence}">Level ${l.a.evidence}</span>
            <span class="mono" style="font-size:9.5px;color:var(--ink3)">verified ${l.a.verifiedOn}</span>
            ${keys?`<span class="mono" style="font-size:9.5px;color:var(--ink3)">${keys} required fields</span>`
                  :`<span class="mono" style="font-size:9.5px;color:var(--ink3)">constraints only</span>`}
          </div>
        </div>
        <button class="lyr__sw${l.off?"":" is-on"}" data-layer="${l.a.id}"
          title="${l.off?"Excluded — click to apply this authority":"Applied — click to exclude"}"></button>
      </div>`;
    if(!l.off){
      if(l.rule.fieldListVerified===false)
        w.appendChild(el("div","cons",'<span class="cons__ic">⚠</span><span>'+
          esc(l.rule.note || "The field list for this form has not been transcribed from the source. Nothing is enforced beyond the constraints below.")+'</span>'));
      (l.rule.constraints||[]).forEach(c=>{
        const isCourt=/⚖/.test(c);
        w.appendChild(el("div","cons",
          `<span class="cons__ic">${isCourt?"⚖":"§"}</span><span>${esc(c)}</span>`));
      });
      (l.rule.routesTo||[]).forEach(r=>{
        let fired=false; try{ fired=!!r.test(); }catch(e){}
        const other=TYPES.find(t=>t.id===r.toType);
        const d=el("div","route"+(fired?" is-fired":""));
        d.innerHTML=`<b>${fired?"⚠ At this data, the correct instrument is "+esc(r.label)
                              :"Sometimes this is the wrong instrument"}</b>
          <p>${esc(r.plain)}</p>
          <p class="mono">${esc(r.citation)} · fires when ${esc(r.testLabel)}</p>
          <button class="btn btn--sm" data-route="${esc(r.toType)}">Open ${esc(other?other.name:r.label)}</button>`;
        w.appendChild(d);
      });
    }
    LY.appendChild(w);
  });

  /* an unrepresented state is a finding in its own right, not an empty list */
  if(!stateHasAuthority()){
    const w=el("div","lyr");
    w.innerHTML=`<div class="lyr__head"><div class="lyr__t">
        <div class="lyr__n">${esc(sc.state)} — no verified authority</div>
        <div class="lyr__a">We hold no transcribed state rule for ${esc(sc.state)}, so nothing state-level is enforced.</div>
        <div class="lyr__m"><span class="tier tier--none">state</span>
          <span class="lvl lvl--D">no evidence</span></div>
      </div></div>`;
    w.appendChild(el("div","cons",'<span class="cons__ic">§</span><span>'+
      "Honesty is the feature. Inventing a plausible-looking requirement to fill this gap would assert wrong law confidently, which is worse than enforcing nothing. Each new verified state is pure additive value — and the state boards are roughly 98% of the market."+'</span>'));
    LY.appendChild(w);
  }

  const L=zq("#rulesList");
  v.blocking.filter(b=>b.type==="wrongInstrument").forEach(w=>{
    const b=el("button","rule rule--bad");
    b.innerHTML=`<span class="rule__ic">✕</span><span class="rule__main">
      <span class="rule__label">Wrong instrument for this pupil</span>
      <span class="rule__key">${esc(w.authority.label)} · ${esc(w.route.citation)} — issue ${esc(w.route.label)} instead</span>
      <span class="rule__meta"><span class="chip chip--statutory">Publish blocked</span></span></span>`;
    b.onclick=()=>routeTo(w.route.toType);
    L.appendChild(b);
  });
  v.blocking.filter(b=>b.type==="noDuplicateMark").forEach(w=>{
    const b=el("button","rule rule--bad");
    b.innerHTML=`<span class="rule__ic">✕</span><span class="rule__main">
      <span class="rule__label">This template cannot mark a duplicate</span>
      <span class="rule__key">${esc(w.req.map(x=>x.a.label+" "+x.d.citation).join(" · "))} — click to add the mark</span>
      <span class="rule__meta"><span class="chip chip--statutory">Publish blocked</span></span></span>`;
    b.onclick=addDuplicateMark;
    L.appendChild(b);
  });
  v.blocking.filter(b=>b.type==="noSignature").forEach(w=>{
    const b=el("button","rule rule--bad");
    b.innerHTML=`<span class="rule__ic">✕</span><span class="rule__main">
      <span class="rule__label">Signature block missing — ${esc(w.role.replace(/_/g," "))}</span>
      <span class="rule__key">prescribed by ${esc(prof().name)}</span>
      <span class="rule__meta"><span class="chip chip--statutory">Publish blocked</span></span></span>`;
    L.appendChild(b);
  });
  v.blocking.filter(b=>b.type==="offContract").forEach(w=>{
    const b=el("button","rule rule--bad");
    b.innerHTML=`<span class="rule__ic">✕</span><span class="rule__main">
      <span class="rule__label">Field not declared by this document type</span>
      <span class="rule__key">${esc(w.key)} — the bundle will not carry it, so the render fails closed</span>
      <span class="rule__meta"><span class="chip chip--statutory">Publish blocked</span></span></span>`;
    b.onclick=()=>{ const o=objectForKey(w.key); if(o){ S.sel=[o.id]; render(); showCtxbar(); } };
    L.appendChild(b);
  });
  v.blocking.filter(b=>b.type==="clamped").forEach(w=>{
    const o=obj(w.id), b=el("button","rule rule--bad");
    b.innerHTML=`<span class="rule__ic">✕</span><span class="rule__main">
      <span class="rule__label">Content cut off at max height</span>
      <span class="rule__key">${esc(o?(o.name||o.id):w.id)} — overshoots by ${mm(w.over)} mm</span>
      <span class="rule__meta"><span class="chip chip--statutory">Publish blocked</span></span></span>`;
    b.onclick=()=>{ S.sel=[w.id]; render(); showCtxbar(); };
    L.appendChild(b);
  });
  req.forEach(k=>{
    const ok=bound.has(k), f=FIELD[k]||{label:k}, au=keyAuthority(k);
    const b=el("button","rule "+(ok?"rule--ok":"rule--bad"));
    b.innerHTML=`<span class="rule__ic">${ok?"●":"○"}</span><span class="rule__main">
      <span class="rule__label">${esc(f.label)}</span>
      <span class="rule__key">${esc(k)}${au?" · "+esc(au.label):""}</span>
      ${ok?"":'<span class="rule__meta"><span class="chip chip--statutory">Unbound — publish blocked</span></span>'}</span>`;
    b.onclick=()=>{ const o=objectForKey(k);
      if(o){ S.sel=[o.id]; render(); showCtxbar(); toast("Bound in \u201c"+(o.name||o.id)+"\u201d"); }
      else openCite(k); };
    L.appendChild(b);
  });
  v.warnings.forEach(w=>{
    const b=el("button","rule rule--warn");
    const nm=id=>{ const o=obj(id); return o?(o.name||o.id):id; };
    const txt = w.type==="dupNotRed" ? ["Duplicate mark is not in red ink", w.authority.label+" "+w.citation+" prescribes red"]
              : w.type==="sigOrder" ? ["Signatures are out of the prescribed order",
                  "laid out "+w.got.map(r=>r.replace(/_/g," ")).join(" → ")+"; prescribed "+w.want.map(r=>r.replace(/_/g," ")).join(" → ")]
              : w.type==="noAsset" ? ["Image placeholder is empty", nm(w.id)+" — it will print as an empty box"]
              : w.type==="lowDpi" ? ["Image is low resolution for print", nm(w.id)+" — "+w.dpi+" dpi at this size, below "+MIN_DPI]
              : w.type==="noAlpha" ? ["Signature has no transparency", nm(w.id)+" — a white block will print over the ruled line"]
              : w.type==="overflow" ? ["Text overflows its fixed box", nm(w.id)+" — switch to auto-grow or widen it"]
              : w.type==="overlap"  ? ["Two objects collide at this data", nm(w.id)+" over "+nm(w.other)]
              : ["Untranslated strings in हिन्दी", w.n+" text objects have no Hindi content"];
    b.innerHTML=`<span class="rule__ic">▲</span><span class="rule__main">
      <span class="rule__label">${esc(txt[0])}</span><span class="rule__key">${esc(txt[1])}</span></span>`;
    b.onclick=()=>{ if(w.id){ S.sel=[w.id]; render(); showCtxbar(); } };
    L.appendChild(b);
  });

  const sigs=prof().requiredSignatures;
  if(sigs.length || prof().sealRequired){
    const f=el("div"); f.style.cssText="padding:10px 12px;border-top:1px solid var(--line)";
    f.innerHTML=`<div class="hintline" style="margin:0">Signatures: ${
      sigs.length?sigs.map(x=>`<b>${esc(x.replace(/_/g," "))}</b>`).join(" → "):"<b>none prescribed</b>"
    }. Seal: <b>${prof().sealRequired?"required":"not prescribed"}</b>.</div>`;
    L.appendChild(f);
  }

  zqa("[data-route]",C).forEach(b=>b.onclick=()=>routeTo(b.dataset.route));
  zq("#basisBtn").onclick=openBasis;
  zqa(".lyr__sw",LY).forEach(sw=>sw.onclick=()=>toggleLayer(sw.dataset.layer));
}

/* take the clerk to the document type the rule says they should be issuing */
function addDuplicateMark(){
  const before=snapshot(), D=pageDims(), m=S.tpl.page.marginsMm;
  const red = stackActive().map(l=>l.rule.duplicateMark).find(d=>d&&d.colour);
  const o={id:"obj_dup"+Math.random().toString(36).slice(2,5), name:"Duplicate mark",
    type:"text", xMm:D.w-m.r-65, yMm:m.t-4, wMm:65, hMm:8, z:9, height:"auto",
    showWhen:"doc.isDuplicate", isDuplicateMark:true,
    style:{sizePt:15, lineHeight:1.2, weight:700, align:"right",
           colour:red?red.colour:"#C0392B", track:".22em"},
    content:{i18n:{en:T({t:"DUPLICATE"}), hi:T({t:"द्वितीय प्रति"})}}};
  S.tpl.objects.push(o); S.sel=[o.id];
  push("Add duplicate mark", before, snapshot());
  S.issuance.duplicate=true; render(); showCtxbar();
  toast("Duplicate mark added — previewing as a duplicate so you can see it");
}

function routeTo(toType){
  const t=TYPES.find(x=>x.id===toType);
  if(!t) return toast("That document type is not enabled for this school");
  if(!typeEnabled(t)) return toast(t.name+" applies in "+t.requiresState+" — this school is in "+S.school.state, true);
  S.docType=toType; commitEdit(); go("gallery");
  toast("Switched to "+t.name+" — a different instrument, not a renamed one");
}

function toggleLayer(id){
  const a=AUTHORITIES.find(x=>x.id===id);
  if(!S.layerOff[id]){
    modal("Exclude an authority?", esc(a.label),
      `<p style="margin-top:0;font-size:12.5px;line-height:1.6">Excluding <b>${esc(a.label)}</b> stops its rules being enforced on this template. That is sometimes right — a school may have a written exemption, or the authority may not in fact reach this document — and sometimes it is a school editing its way out of a legal requirement.</p>
       <div class="row row--1"><div class="inp"><label>Reason (recorded)</label>
         <input id="ovrWhy" placeholder="e.g. school is CBSE-affiliated; state rule applies to aided schools only"></div></div>
       <p class="note" style="margin-bottom:0">The reason is stored with the template and shown on every rule it suppresses. Compliance profiles themselves remain editable by the platform super-admin only — a school can exclude a layer here, it cannot rewrite one.</p>`,
      `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
       <button class="btn btn--primary" id="ovrGo">Exclude ${esc(a.label)}</button>`, true);
    zq("#ovrGo").onclick=()=>{
      const why=(zq("#ovrWhy").value||"").trim();
      if(!why) return toast("A reason is required — an unexplained exclusion is an audit finding", true);
      S.layerOff[id]=true; S.overrideReason[id]=why; markDirty();
      closeModal(); render(); toast("Excluded "+a.label+" — reason recorded");
    };
    return;
  }
  S.layerOff[id]=false; delete S.overrideReason[id]; markDirty(); render();
  toast("Applied "+a.label+" again");
}

function openBasis(){
  const sc=S.school;
  modal("Compliance basis","Which authorities this school actually sits under",
    `<div class="row">
      <div class="inp"><label>Board</label><select id="bsBoard">
        ${BOARDS.map(b=>`<option ${sc.board===b?"selected":""}>${esc(b)}</option>`).join("")}</select></div>
      <div class="inp"><label>State</label><select id="bsState">
        ${STATES.map(x=>`<option ${sc.state===x?"selected":""}>${esc(x)}</option>`).join("")}</select></div>
    </div>
    <div class="row row--1"><div class="inp"><label>Classes taught</label><select id="bsStage">
      ${Object.entries(STAGES).map(([k,l])=>`<option value="${k}" ${sc.stage===k?"selected":""}>${esc(l)}</option>`).join("")}</select></div></div>
    <div class="subhead" style="margin-top:14px">Resolved stack</div>
    <div id="bsPreview"></div>
    <p class="note" style="margin-bottom:0">A school is never under one authority. Central board, state education rules and the RTE Act apply at the same time with different scopes, so the requirement set is their <b>union</b> and every rule carries the authority it came from. Where a state has no transcribed rule, that is stated rather than filled in.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="bsGo">Apply</button>`);
  const draw=()=>{
    const sc2={board:zq("#bsBoard").value, state:zq("#bsState").value, stage:zq("#bsStage").value};
    const st=resolveStack(S.docType, sc2);
    const P=zq("#bsPreview"); P.innerHTML="";
    st.forEach(l=>{
      const d=el("div","kv");
      d.innerHTML=`<span><span class="tier tier--${l.a.tier}">${l.a.tier==="board"?"Central board":l.a.tier}</span>
        &nbsp;${esc(l.a.label)}</span><b>Level ${l.a.evidence} · ${(l.rule.requiredKeys||[]).length} fields</b>`;
      P.appendChild(d);
    });
    if(!AUTHORITIES.some(a=>a.tier==="state"&&a.appliesWhen(sc2))){
      const d=el("div","kv");
      d.innerHTML=`<span><span class="tier tier--none">state</span>&nbsp;${esc(sc2.state)} — no verified authority</span><b>nothing enforced</b>`;
      P.appendChild(d);
    }
    if(!st.length) P.appendChild(el("div","note","No authority we hold reaches this document type for this school. The template is not compliance-checked."));
  };
  ["#bsBoard","#bsState","#bsStage"].forEach(id=>zq(id).onchange=draw);
  draw();
  zq("#bsGo").onclick=()=>{
    S.school={name:S.school.name, board:zq("#bsBoard").value, state:zq("#bsState").value, stage:zq("#bsStage").value};
    S.layerOff={}; S.overrideReason={}; markDirty();
    closeModal(); render();
    toast("Compliance basis: "+S.school.board+" · "+S.school.state);
  };
}

function paintBlocks(){
  const L=zq("#blockList"); if(!L) return;
  const IC={letterhead:"▤",signature:"✎",seal:"◎"};
  L.innerHTML="";
  BLOCKS.forEach(bl=>{
    const ref=S.blockRefs[bl.id];
    const pending = ref!=null && bl.version>ref && !S.blockIgnored[bl.id];
    const w=el("div");
    w.innerHTML=`<button class="blockitem"><span class="blockitem__ic">${IC[bl.type]}</span>
      <span><b>${esc(bl.name)}</b><span>${bl.objects} objects · used by ${bl.usedBy} · v${bl.version}${ref!=null?" · this template uses v"+ref:""}</span></span></button>
      ${pending?`<div class="upd">Update available — v${ref} → v${bl.version}
        <button data-review="${bl.id}">Review</button></div>`:""}`;
    L.appendChild(w);
  });
}
function paintFieldList(){
  const L=zq("#fieldList"); L.innerHTML="";
  const dt=(S.tpl&&S.tpl.docType)||S.docType, ty=TYPES.find(t=>t.id===dt);
  const cn=zq("#ctName"), cc=zq("#ctCount");
  if(cn) cn.textContent=ty?ty.name:"this document type";
  if(cc) cc.textContent=contractFor(dt).length+" fields";
  const bound=boundKeys(), req=new Set(prof().requiredKeys);
  contractFor().forEach(f=>{
    const b=el("button","field");
    b.innerHTML=`<span class="field__col"><span class="field__lbl">${esc(f.label)}</span>
      <span class="field__key">${esc(f.key)}</span></span>
      ${f.calc?'<span class="field__tag field__tag--calc">calc</span>':""}
      ${req.has(f.key)?'<span class="field__tag field__tag--req">req</span>':""}
      ${bound.has(f.key)?'<span class="field__tag">used</span>':""}`;
    b.title="Sample: "+f.sample;
    b.onclick=()=>insertField(f.key);
    L.appendChild(b);
  });
}
function paintStatus(){
  const v=validate();
  zq("#zoomVal").textContent=Math.round(S.zoom*100)+"%";
  if(S.editing) zq("#sbSel").innerHTML=`<b>Editing</b> ${esc(obj(S.editing).name||S.editing)} — Esc to finish`;
  else if(S.sel.length===1){
    const o=obj(S.sel[0]);
    zq("#sbSel").innerHTML=`<b>${esc(o.name||o.id)}</b> · ${mm(o.xMm)},${mm(resolvedY(o))} mm · ${mm(o.wMm)}×${mm(S.measured[o.id]||o.hMm)} mm${o.anchorTo?` · anchored to <b>${esc((obj(o.anchorTo)||{}).name||o.anchorTo)}</b>`:""}`;
  } else if(S.sel.length>1) zq("#sbSel").innerHTML=`<b>${S.sel.length}</b> objects selected`;
  else zq("#sbSel").textContent="No selection";
  const dt=zq("#dupToggle");
  if(dt){
    dt.innerHTML = S.issuance.duplicate ? '<b style="color:var(--seal)">DUPLICATE</b>' : "original";
    dt.title="Preview the document as an original or as a reissued duplicate";
  }
  /* The segmented control beside this already says which mode is on, so the
     sentence only earns its place when it is warning you. */
  zq("#sbData").innerHTML = S.data==="p95"
    ? '<span class="sb--warn">p95 — the length that breaks layouts</span>' : "";
  zq("#sbWarn").innerHTML = v.blocking.length
    ? `<span class="sb--warn">${v.blocking.length} blocking · ${v.warnings.length} warnings</span>`
    : '<span class="sb--ok">no blocking findings</span>';
  /* "lockVersion 19" is our word, not the reader's. It answers a question
     nobody asked and buries the one they did: is my work safe? The number is
     still there on hover for anyone debugging a conflict. */
  const save=zq("#sbSave");
  save.innerHTML = S.conflict
    ? '<span class="sb--warn">Not saving — someone else changed this template · reload</span>'
    : S.dirty
    ? '<span class="sb--warn">Unsaved changes</span>'
    : '<span class="sb--ok">All changes saved</span>';
  save.title = "lockVersion " + S.tpl.lockVersion;

  paintViewStrip();
}
function render(){
  paintCrumb(); paintTopActions(); layoutPage(); paintRulers();
  paintLayers(); paintFieldList(); paintBlocks(); paintContent(); paintInspector(); paintPagePanel();
  paintCompliance(); paintStatus(); paintCtxbar();
}

/* ==========================================================================
   11 · TOOLS, POINTER, PAN / ZOOM
   ========================================================================== */
function setTool(t){
  S.tool=t;
  zqa("#toolbar button").forEach(b=>b.classList.toggle("is-on", b.dataset.tool===t));
  const d=zq("#desk");
  d.classList.toggle("is-pan", t==="hand");
  d.classList.toggle("is-place", !["move","hand"].includes(t));
}
zq("#toolbar").addEventListener("click", e=>{
  const b=e.target.closest("button[data-tool]"); if(b){ commitEdit(); setTool(b.dataset.tool); }
});

let drag=null, spaceDown=false;

/* keep the stored caret current so a render can put it back */
["keyup","click","input"].forEach(ev=>zq("#page").addEventListener(ev, ()=>{ if(S.editing) readCaret(); }));

zq("#page").addEventListener("dblclick", e=>{
  const n=e.target.closest(".obj"); if(!n) return;
  const o=obj(n.dataset.id);
  if(o && o.type==="text") enterEdit(o.id, e.clientX, e.clientY);
  else if(o && o.type==="image" && !o.bindKey) pickFile(o.id);
});

zq("#page").addEventListener("mousedown", e=>{
  if(e.button!==0) return;
  const k=pxPerMm(), rect=zq("#page").getBoundingClientRect();
  const mx=(e.clientX-rect.left)/k, my=(e.clientY-rect.top)/k;

  if(spaceDown || S.tool==="hand") return;               /* pan handled on the desk */
  if(S.editing && e.target.closest(".obj") && e.target.closest(".obj").dataset.id===S.editing) return;
  commitEdit();

  if(!["move"].includes(S.tool)){                        /* placement tools */
    drag={mode:"place", type:S.tool, x0:mx, y0:my, node:null};
    e.preventDefault(); return;
  }
  const handle=e.target.closest(".h");
  if(handle && S.sel.length===1){
    const o=obj(S.sel[0]);
    drag={mode:"resize", dir:[...handle.classList].find(c=>["nw","ne","sw","se","n","s","w","e"].includes(c)),
          id:o.id, x0:e.clientX, y0:e.clientY, o0:Object.assign({},o), before:snapshot()};
    e.preventDefault(); return;
  }
  let node=e.target.closest(".obj");
  if(node){
    const oo=obj(node.dataset.id);
    /* locked and hidden objects fall through to the canvas, as in Figma —
       they stay reachable from Layers and the Select-layer menu */
    if(oo && (oo.locked || S.hidden[oo.id])) node=null;
  }
  if(node){
    const id=node.dataset.id, o=obj(id);
    /* click-through editing: while editing, clicking another text object
       edits it directly rather than making you double-click again */
    if(S.editing && S.editing!==id && o.type==="text"){ commitEdit(); enterEdit(id, e.clientX, e.clientY); e.preventDefault(); return; }
    if(e.altKey && !e.shiftKey){          /* alt-drag duplicates */
      if(!S.sel.includes(id)) S.sel=[id];
      duplicateSel();
      drag={mode:"move", ids:S.sel.slice(), x0:e.clientX, y0:e.clientY,
        start:Object.fromEntries(S.sel.map(i=>[i,{x:obj(i).xMm, y:resolvedY(obj(i))}])),
        before:snapshot(), moved:true};
      e.preventDefault(); return;
    }
    if(e.shiftKey){ S.sel.includes(id)?S.sel=S.sel.filter(s=>s!==id):S.sel.push(id); }
    else if(!S.sel.includes(id)) S.sel=[id];
    render(); showCtxbar();
    if(!o.locked) drag={mode:"move", ids:S.sel.slice(), x0:e.clientX, y0:e.clientY,
      start:Object.fromEntries(S.sel.map(i=>[i,{x:obj(i).xMm, y:resolvedY(obj(i))}])),
      before:snapshot(), moved:false};
    e.preventDefault(); return;
  }
  drag={mode:"marquee", x0:e.clientX-rect.left, y0:e.clientY-rect.top, node:null};
  S.sel=[]; hideCtxbar(); render(); e.preventDefault();
});

window.addEventListener("mousemove", e=>{
  if(!drag){ if(e.altKey && S.sel.length===1) measureTo(e); else clearMeas(); return; }
  const k=pxPerMm(), P=zq("#page"), rect=P.getBoundingClientRect();
  clearGuides();

  if(drag.mode==="move"){
    let dx=(e.clientX-drag.x0)/k, dy=(e.clientY-drag.y0)/k;
    if(Math.abs(dx)>.3||Math.abs(dy)>.3) drag.moved=true;
    const lead=obj(drag.ids[0]), s=drag.start[lead.id];
    const sn=snap(s.x+dx, s.y+dy, lead);
    dx+=sn.dx; dy+=sn.dy;
    const D=pageDims();
    drag.ids.forEach(id=>{
      const o=obj(id), st=drag.start[id];
      o.xMm=Math.max(0,Math.min(D.w-o.wMm, st.x+dx));
      if(!o.anchorTo) o.yMm=Math.max(0,Math.min(D.h-4, st.y+dy));
    });
    layoutPage(); paintStatus(); drawGuides(sn.guides);
  }
  if(drag.mode==="resize"){
    const o=obj(drag.id), d=drag.dir, o0=drag.o0;
    const dx=(e.clientX-drag.x0)/k, dy=(e.clientY-drag.y0)/k;
    if(d.includes("e")) o.wMm=Math.max(6,o0.wMm+dx);
    if(d.includes("s")&&o.height==="fixed") o.hMm=Math.max(3,o0.hMm+dy);
    if(d.includes("w")){ o.wMm=Math.max(6,o0.wMm-dx); o.xMm=o0.xMm+(o0.wMm-o.wMm); }
    if(d.includes("n")&&o.height==="fixed"&&!o.anchorTo){ o.hMm=Math.max(3,o0.hMm-dy); o.yMm=o0.yMm+(o0.hMm-o.hMm); }
    layoutPage(); paintStatus();
  }
  if(drag.mode==="marquee"||drag.mode==="place"){
    const x=e.clientX-rect.left, y=e.clientY-rect.top;
    const x0=drag.mode==="place"?drag.x0*k:drag.x0, y0=drag.mode==="place"?drag.y0*k:drag.y0;
    if(!drag.node){ drag.node=el("div","marquee"); P.appendChild(drag.node); }
    drag.node.style.left=Math.min(x,x0)+"px"; drag.node.style.top=Math.min(y,y0)+"px";
    drag.node.style.width=Math.abs(x-x0)+"px"; drag.node.style.height=Math.abs(y-y0)+"px";
    if(drag.mode==="place"){ drag.x1=x/k; drag.y1=y/k; }
    else {
      const a=Math.min(x,x0)/k, b=Math.max(x,x0)/k, c=Math.min(y,y0)/k, d2=Math.max(y,y0)/k;
      drag.hits=S.tpl.objects.filter(o=>{
        const oy=resolvedY(o)+regionTop(o)/k;
        return o.xMm<b && o.xMm+o.wMm>a && oy<d2 && oy+(S.measured[o.id]||o.hMm)>c;
      }).map(o=>o.id);
    }
  }
});

window.addEventListener("mouseup", ()=>{
  if(!drag) return;
  clearGuides();
  if(drag.mode==="move" && drag.moved) push("Move "+drag.ids.length+" object(s)", drag.before, snapshot());
  if(drag.mode==="resize") push("Resize", drag.before, snapshot());
  if(drag.mode==="marquee"){ if(drag.node) drag.node.remove(); if(drag.hits&&drag.hits.length) S.sel=drag.hits; }
  if(drag.mode==="place"){
    if(drag.node) drag.node.remove();
    const x=Math.min(drag.x0, drag.x1==null?drag.x0:drag.x1), y=Math.min(drag.y0, drag.y1==null?drag.y0:drag.y1);
    const w=Math.abs((drag.x1==null?drag.x0:drag.x1)-drag.x0), h=Math.abs((drag.y1==null?drag.y0:drag.y1)-drag.y0);
    const tap = w<3 && h<3;              /* Figma: click = auto, drag = fixed */
    const D=pageDims(), m=S.tpl.page.marginsMm;
    const no=addObject(drag.type, x, y,
      tap ? Math.max(20,(D.w-m.r)-x) : Math.max(w,20),
      tap ? 8 : Math.max(h,6));
    if(no && drag.type==="text") no.height = tap ? "auto" : "fixed";
    setTool("move");
  }
  const wasPlace = drag.mode==="place";
  drag=null; render();
  if(S.sel.length) showCtxbar();
  if(wasPlace && S.sel.length===1 && obj(S.sel[0]).type==="text") enterEdit(S.sel[0]);
});

/* snapping — threshold in px so it feels identical at every zoom, and every
   guide carries the millimetre it snapped to (Figma's measured guides) */
function snap(x,y,o){
  const k=pxPerMm(), TH=6/k, m=S.tpl.page.marginsMm, D=pageDims(), guides=[];
  let dx=0, dy=0;
  const others=S.tpl.objects.filter(t=>t.id!==o.id && (t.region||"body")===(o.region||"body"));
  const xT=[m.l, D.w-m.r-o.wMm, D.w/2-o.wMm/2]
    .concat(S.guides.v).concat(S.guides.v.map(g=>g-o.wMm))
    .concat(others.map(t=>t.xMm)).concat(others.map(t=>t.xMm+t.wMm-o.wMm));
  const yT=[m.t, D.h-m.b].concat(S.guides.h)
    .concat(others.map(t=>resolvedY(t))).concat(others.map(t=>resolvedY(t)+(S.measured[t.id]||t.hMm)));
  xT.forEach(t=>{ if(!dx && Math.abs(x-t)<TH){ dx=t-x; guides.push({axis:"x", at:t, lbl:mm(t)+" mm"}); } });
  yT.forEach(t=>{ if(!dy && Math.abs(y-t)<TH){ dy=t-y; guides.push({axis:"y", at:t, lbl:mm(t)+" mm"}); } });
  return {dx,dy,guides};
}
function drawGuides(gs){
  const k=pxPerMm(), P=zq("#page");
  gs.forEach(g=>{
    const n=el("div","guide"), l=el("div","guide__lbl",esc(g.lbl));
    if(g.axis==="x"){ n.style.cssText=`left:${g.at*k}px;top:0;width:1px;height:100%`; l.style.cssText=`left:${g.at*k+4}px;top:6px`; }
    else { n.style.cssText=`top:${g.at*k}px;left:0;height:1px;width:100%`; l.style.cssText=`top:${g.at*k+4}px;left:6px`; }
    P.appendChild(n); P.appendChild(l);
  });
}
const clearGuides=()=>zqa(".guide,.guide__lbl").forEach(g=>g.remove());
const clearMeas  =()=>zqa(".meas,.meas__lbl").forEach(g=>g.remove());

/* alt + hover = measure from the selection to whatever is under the cursor */
function measureTo(e){
  clearMeas();
  const n=e.target.closest(".obj"); if(!n) return;
  const b=obj(n.dataset.id), a=obj(S.sel[0]);
  if(!b||!a||a.id===b.id) return;
  const k=pxPerMm(), P=zq("#page");
  const box=o=>({x:o.xMm, y:resolvedY(o)+regionTop(o)/k, w:o.wMm, h:S.measured[o.id]||o.hMm});
  const A=box(a), B=box(b);
  const add=(css,cls)=>{ const d=el("div",cls); d.style.cssText=css; P.appendChild(d); };
  let gapY=0, gapX=0;
  if(B.y >= A.y+A.h) gapY=B.y-(A.y+A.h); else if(A.y >= B.y+B.h) gapY=A.y-(B.y+B.h);
  if(B.x >= A.x+A.w) gapX=B.x-(A.x+A.w); else if(A.x >= B.x+B.w) gapX=A.x-(B.x+B.w);
  const cx=Math.max(A.x,B.x)*k+6;
  if(gapY>0.05){
    const top=Math.min(A.y+A.h, B.y+B.h)*k, hgt=gapY*k;
    add(`left:${cx}px;top:${top}px;width:1px;height:${hgt}px`,"meas");
    const l=el("div","meas__lbl",mm(gapY)+" mm"); l.style.cssText=`left:${cx+5}px;top:${top+hgt/2-7}px`; P.appendChild(l);
  }
  if(gapX>0.05){
    const cy=Math.max(A.y,B.y)*k+6, lft=Math.min(A.x+A.w, B.x+B.w)*k;
    add(`top:${cy}px;left:${lft}px;height:1px;width:${gapX*k}px`,"meas");
    const l=el("div","meas__lbl",mm(gapX)+" mm"); l.style.cssText=`top:${cy+4}px;left:${lft+gapX*k/2-12}px`; P.appendChild(l);
  }
  if(gapX<=0.05 && gapY<=0.05){
    const l=el("div","meas__lbl","overlapping"); l.style.cssText=`left:${B.x*k}px;top:${B.y*k-14}px`; P.appendChild(l);
  }
}

/* desk pan + zoom */
const desk=zq("#desk");
let pan=null, gdrag=null;

/* drag a guide out of a ruler (Figma) */
["#rulerH","#rulerV"].forEach(sel=>zq(sel).addEventListener("mousedown", e=>{
  if(e.button!==0) return;
  const axis = sel==="#rulerH" ? "v" : "h";
  const k=pxPerMm(), r=zq("#page").getBoundingClientRect();
  const at = axis==="v" ? (e.clientX-r.left)/k : (e.clientY-r.top)/k;
  S.guides[axis].push(Math.max(0,mm(at)));
  gdrag={axis, idx:S.guides[axis].length-1, fresh:true};
  layoutPage(); e.preventDefault(); e.stopPropagation();
}));
/* grab an existing guide */
zq("#page").addEventListener("mousedown", e=>{
  const g=e.target.closest(".rguide"); if(!g) return;
  gdrag={axis:g.dataset.axis, idx:+g.dataset.idx};
  e.preventDefault(); e.stopPropagation();
}, true);

desk.addEventListener("mousedown", e=>{
  if(e.button===1 || spaceDown || S.tool==="hand"){
    pan={x:e.clientX, y:e.clientY, l:desk.scrollLeft, t:desk.scrollTop};
    desk.classList.add("is-panning"); e.preventDefault(); return;
  }
  /* the desk is canvas: clicking it clears the selection, exactly as
     clicking blank paper does. Without this, deselect looks broken —
     the desk is most of the screen.
     `defaultPrevented` first, and it is load-bearing: the page handler calls
     render(), which detaches the clicked node, so by the time the event
     reaches here `closest(".page")` on a detached node returns null and this
     would wipe a selection that was just made. */
  if(e.defaultPrevented || drag || gdrag) return;
  if(e.target.closest(".page")||e.target.closest(".ruler")||e.target.closest(".toolbar")) return;
  commitEdit();
  if(S.sel.length){ S.sel=[]; hideCtxbar(); render(); }
});
window.addEventListener("mousemove", e=>{
  if(gdrag){
    const k=pxPerMm(), r=zq("#page").getBoundingClientRect(), D=pageDims();
    const at = gdrag.axis==="v" ? (e.clientX-r.left)/k : (e.clientY-r.top)/k;
    S.guides[gdrag.axis][gdrag.idx]=mm(at);
    layoutPage();
    clearGuides();
    const lim = gdrag.axis==="v" ? D.w : D.h;
    const off = at<-4 || at>lim+4;
    const l=el("div","guide__lbl", (off?"release to remove — ":"")+mm(at)+" mm");
    l.style.cssText = gdrag.axis==="v"
      ? `left:${at*k+5}px;top:8px` : `top:${at*k+5}px;left:8px`;
    zq("#page").appendChild(l);
    return;
  }
  if(!pan) return;
  desk.scrollLeft=pan.l-(e.clientX-pan.x);
  desk.scrollTop =pan.t-(e.clientY-pan.y);
});
window.addEventListener("mouseup", ()=>{
  if(gdrag){
    const D=pageDims(), lim=gdrag.axis==="v"?D.w:D.h, v=S.guides[gdrag.axis][gdrag.idx];
    if(v<-4||v>lim+4){ S.guides[gdrag.axis].splice(gdrag.idx,1); toast("Guide removed"); }
    gdrag=null; clearGuides(); layoutPage();
  }
  pan=null; desk.classList.remove("is-panning");
});
desk.addEventListener("wheel", e=>{
  if(e.metaKey||e.ctrlKey){
    e.preventDefault();
    const before=S.zoom;
    S.zoom=Math.max(.25,Math.min(3, S.zoom*(e.deltaY<0?1.08:0.92)));
    if(Math.abs(before-S.zoom)>0.001){ layoutPage(); paintRulers(); paintStatus(); }
  }
},{passive:false});
desk.addEventListener("scroll", positionCtxbar);

function zoomFit(){
  const h=desk.clientHeight-110, w=desk.clientWidth-120, D=pageDims();
  S.zoom=Math.max(.25,Math.min(1.6, Math.min(h/(D.h*96/25.4), w/(D.w*96/25.4))));
  layoutPage(); paintRulers(); paintStatus();
}
zq("#zoomIn").onclick =()=>{ S.zoom=Math.min(3,S.zoom+.1); layoutPage(); paintRulers(); paintStatus(); };
zq("#zoomOut").onclick=()=>{ S.zoom=Math.max(.25,S.zoom-.1); layoutPage(); paintRulers(); paintStatus(); };
zq("#zoomFit").onclick=zoomFit;
function zoomToSelection(){
  if(!S.sel.length) return zoomFit();
  const k0=pxPerMm();
  const bs=S.sel.map(obj).filter(Boolean).map(o=>({
    x:o.xMm, y:resolvedY(o)+regionTop(o)/k0, w:o.wMm, h:S.measured[o.id]||o.hMm}));
  const w=Math.max(...bs.map(b=>b.x+b.w))-Math.min(...bs.map(b=>b.x));
  const h=Math.max(...bs.map(b=>b.y+b.h))-Math.min(...bs.map(b=>b.y));
  S.zoom=Math.max(.25,Math.min(3, Math.min((desk.clientWidth-160)/(w*96/25.4), (desk.clientHeight-160)/(h*96/25.4))));
  layoutPage(); paintRulers(); paintStatus();
  const k=pxPerMm(), pr=zq("#page").getBoundingClientRect(), dr=desk.getBoundingClientRect();
  const cx=(Math.min(...bs.map(b=>b.x))+w/2)*k, cy=(Math.min(...bs.map(b=>b.y))+h/2)*k;
  desk.scrollLeft += (pr.left-dr.left)+cx-desk.clientWidth/2;
  desk.scrollTop  += (pr.top-dr.top)+cy-desk.clientHeight/2;
}

/* ==========================================================================
   12 · OBJECT OPERATIONS
   ========================================================================== */
function addObject(type,x,y,w,h){
  const before=snapshot();
  const id="obj_"+Math.random().toString(36).slice(2,6);
  const o={id, name:type.charAt(0).toUpperCase()+type.slice(1), type,
    xMm:x!=null?mm(x):40, yMm:y!=null?mm(y):120, wMm:w!=null?mm(w):90, hMm:h!=null?mm(h):(type==="text"?8:20),
    z:9, height:type==="text"?"auto":"fixed",
    style:{sizePt:9, lineHeight:1.45, weight:400, align:"left", colour:"#14100D"}};
  if(type==="text") o.content={i18n:{en:{runs:[{t:"New text"}]}, hi:{runs:[]}}};
  else if(type==="table") o.content={rows:[{key:"student.fullName"},{key:"student.dob"}]};
  else if(type==="image") o.content={label:"image"};
  else if(type==="shape"){ o.content={shape:"line"}; o.hMm=Math.max(0.6, o.hMm>6?0.6:o.hMm); }
  else if(type==="qr") o.content={};
  else o.content={format:"Page {n}"};
  S.tpl.objects.push(o); S.sel=[id];
  push("Add "+type, before, snapshot()); render(); showCtxbar();
  return o;
}
function duplicateSel(){
  if(!S.sel.length) return;
  const before=snapshot(), ids=[];
  S.sel.map(obj).forEach(o=>{
    const c=JSON.parse(JSON.stringify(o));
    c.id="obj_"+Math.random().toString(36).slice(2,6);
    c.name=(o.name||o.id)+" copy"; c.xMm+=5; c.yMm=resolvedY(o)+5; c.anchorTo=null; c._y=null;
    c.requiredKey=null;    /* a duplicate is not a second statutory object */
    S.tpl.objects.push(c); ids.push(c.id);
  });
  S.sel=ids; push("Duplicate", before, snapshot()); render(); showCtxbar();
  toast("Duplicated — the copy carries no compliance binding");
}
function tryDelete(){
  const blocked=S.sel.map(obj).filter(o=>o&&o.requiredKey);
  if(blocked.length){ openCite(blocked[0].requiredKey, true); return; }
  const before=snapshot();
  S.tpl.objects.forEach(o=>{ if(S.sel.includes(o.anchorTo)) { o.anchorTo=null; o._y=null; } });
  S.tpl.objects=S.tpl.objects.filter(o=>!S.sel.includes(o.id));
  S.sel=[]; hideCtxbar(); push("Delete object", before, snapshot()); render(); toast("Object deleted");
}
function zOrder(dir){
  if(!S.sel.length) return;
  const before=snapshot();
  S.sel.map(obj).forEach(o=>o.z=(o.z||0)+dir);
  push("Z order", before, snapshot()); render();
}
/* Figma: one object aligns to its parent, several align to each other.
   Our "parent" is the printable area inside the page margins. */
function alignSel(mode){
  if(!S.sel.length) return toast("Select something first");
  const before=snapshot(), os=S.sel.map(obj), D=pageDims(), m=S.tpl.page.marginsMm;
  const H=o=>S.measured[o.id]||o.hMm;
  const single=os.length<2;
  if(mode.startsWith("distribute") && single) return toast("Distribute needs three or more objects");

  const L = single ? m.l          : Math.min(...os.map(o=>o.xMm));
  const R = single ? D.w-m.r      : Math.max(...os.map(o=>o.xMm+o.wMm));
  const Tp= single ? m.t          : Math.min(...os.map(o=>resolvedY(o)));
  const B = single ? D.h-m.b      : Math.max(...os.map(o=>resolvedY(o)+H(o)));
  const setY=(o,y)=>{ if(!o.anchorTo) o.yMm=y; };

  if(mode==="left")    os.forEach(o=>o.xMm=L);
  if(mode==="right")   os.forEach(o=>o.xMm=R-o.wMm);
  if(mode==="centerX") os.forEach(o=>o.xMm=(L+R)/2-o.wMm/2);
  if(mode==="pageX")   os.forEach(o=>o.xMm=(D.w-o.wMm)/2);
  if(mode==="top")     os.forEach(o=>setY(o,Tp));
  if(mode==="bottom")  os.forEach(o=>setY(o,B-H(o)));
  if(mode==="middleY") os.forEach(o=>setY(o,(Tp+B)/2-H(o)/2));
  if(mode==="distributeY"){
    const a=os.slice().sort((x,y)=>resolvedY(x)-resolvedY(y));
    const y0=resolvedY(a[0]), y1=resolvedY(a[a.length-1]), gap=(y1-y0)/(a.length-1);
    a.forEach((o,i)=>setY(o,y0+gap*i));
  }
  if(mode==="distributeX"){
    const a=os.slice().sort((x,y)=>x.xMm-y.xMm);
    const x0=a[0].xMm, x1=a[a.length-1].xMm, gap=(x1-x0)/(a.length-1);
    a.forEach((o,i)=>o.xMm=x0+gap*i);
  }
  push("Align — "+mode, before, snapshot()); render();
  toast(single ? "Aligned to the page margins" : "Aligned "+os.length+" objects");
}

/* ==========================================================================
   12b · ASSETS — drag and drop, paste, replace
   SVG is refused: it is XML that can carry <script>, event handlers, XXE and
   foreignObject, so accepting it into a school's asset store would be a stored
   XSS vector. Raster only, sniffed by content and not by file extension.
   ========================================================================== */
const OK_TYPES = ["image/png","image/jpeg","image/webp"];
function readAsset(file){
  return new Promise((res,rej)=>{
    if(file.type==="image/svg+xml" || /\.svg$/i.test(file.name))
      return rej("SVG is not accepted — it can carry script, and a certificate asset store is not the place to find out. Export a PNG.");
    if(!OK_TYPES.includes(file.type)) return rej("Only PNG, JPEG or WebP. That file is "+(file.type||"an unknown type")+".");
    if(file.size > 8*1024*1024) return rej("That file is "+(file.size/1048576).toFixed(1)+" MB. Keep assets under 8 MB.");
    const fr=new FileReader();
    fr.onload=()=>{
      const img=new Image();
      img.onload=()=>{
        /* alpha matters for a signature or a seal: a white box over a ruled
           line prints as a white box over a ruled line */
        let hasAlpha=false;
        try{
          const c=document.createElement("canvas"); c.width=Math.min(img.width,40); c.height=Math.min(img.height,40);
          const x=c.getContext("2d"); x.drawImage(img,0,0,c.width,c.height);
          const d=x.getImageData(0,0,c.width,c.height).data;
          for(let i=3;i<d.length;i+=4){ if(d[i]<250){ hasAlpha=true; break; } }
        }catch(e){}
        res({name:file.name, dataUrl:fr.result, wPx:img.width, hPx:img.height,
             bytes:file.size, mime:file.type, hasAlpha});
      };
      img.onerror=()=>rej("That file could not be decoded as an image.");
      img.src=fr.result;
    };
    fr.onerror=()=>rej("That file could not be read.");
    fr.readAsDataURL(file);
  });
}
function applyAsset(o, a, keepBox){
  o.asset=a; o.bindKey=null;
  /* content.src is what the SERVER reads. o.asset is preview-only. */
  if(a.src){ o.content=Object.assign({}, o.content, {src:a.src}); }
  if(!keepBox){
    const ratio=a.hPx/a.wPx;
    o.hMm=Math.max(4, Math.round(o.wMm*ratio*10)/10);
  }
  const d=assetDpi(o);
  toast(a.name+" placed"+(d?" · "+d+" dpi at this size":""), d && d<MIN_DPI);
}
async function dropFiles(files, atMm, targetId){
  const list=[...files].slice(0,4);
  for(let i=0;i<list.length;i++){
    let a;
    try{ a=await readAsset(list[i]); }
    catch(msg){ toast(String(msg), true); continue; }
    /* UPLOAD IT. The asset carries a base64 data: URL for instant on-screen
       preview, and that is ALL it is for — it must never be what gets saved:
         · the panel XSS-filters every POST, and CI neutralises "data:", which
           mangles the JSON so the save fails with the misleading
           "patch must be a JSON object";
         · Firestore documents cap at 1 MiB, and a couple of embedded crests
           would approach it;
         · Doc_serializer::guardSrc() rejects data: by design, so it could never
           render into a PDF anyway.
       upload_asset() stores the bytes once, under their own content hash, and
       hands back a school-relative path — which is what the renderer wants. */
    if(SRV.online){
      try{
        const up=await srv.uploadAsset(list[i]);
        a.src=up.src; a.wPx=up.width||a.wPx; a.hPx=up.height||a.hPx;
      }catch(e){
        apiFail(e, "Uploading "+list[i].name);
        toast("Not placed — the image could not be uploaded, so it was not added.");
        continue;                       // never place an image we cannot render
      }
    }
    const before=snapshot();
    if(targetId){                       /* Figma's drop-to-replace */
      const o=obj(targetId);
      applyAsset(o, a, true);
      push("Replace image", before, snapshot());
    }else{
      const o=addObject("image", (atMm?atMm.x:40)+i*4, (atMm?atMm.y:120)+i*4, 40, 40);
      o.assetKind = /sign/i.test(a.name) ? "signature" : /seal|stamp/i.test(a.name) ? "seal" : "crest";
      o.name = ASSET_KINDS[o.assetKind].label;
      applyAsset(o, a);
      push("Place image", before, snapshot());
    }
  }
  render(); if(S.sel.length) showCtxbar();
}
/* A principal without a scanner. Drawn strokes are alpha by construction, so
   this sidesteps the white-block-over-the-ruled-line problem entirely — the
   output is exactly the transparent PNG the asset check asks for. */
function openSignaturePad(targetId){
  modal("Draw a signature","Signed with a trackpad, mouse or finger",
    `<canvas class="sigpad" id="sigpad" width="1600" height="500" style="height:190px"></canvas>
     <div class="sigpad__row">
       <span class="sigpad__hint">Drawn strokes carry an alpha channel, so this prints over a ruled line correctly — which a scanned JPEG will not.</span>
       <button class="btn btn--sm" id="sigClear">Clear</button>
     </div>
     <p class="note" style="margin-bottom:0">This is still an <b>image</b> of a signature. It carries no status under the IT Act 2000 — s.3 means a DSC, s.3A means a Second-Schedule electronic signature such as Aadhaar eSign. Authenticity on this document is carried by the verification QR.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="sigUse">Use this signature</button>`);
  const c=zq("#sigpad"), x=c.getContext("2d");
  x.lineWidth=6; x.lineCap="round"; x.lineJoin="round"; x.strokeStyle="#16233a";
  let drawing=false, drew=false, last=null;
  const pt=e=>{ const r=c.getBoundingClientRect();
    return {x:(e.clientX-r.left)*(c.width/r.width), y:(e.clientY-r.top)*(c.height/r.height)}; };
  c.addEventListener("pointerdown", e=>{ drawing=true; drew=true; last=pt(e); c.setPointerCapture(e.pointerId); });
  c.addEventListener("pointermove", e=>{ if(!drawing) return;
    const p=pt(e); x.beginPath(); x.moveTo(last.x,last.y); x.lineTo(p.x,p.y); x.stroke(); last=p; });
  ["pointerup","pointerleave","pointercancel"].forEach(ev=>c.addEventListener(ev,()=>drawing=false));
  zq("#sigClear").onclick=()=>{ x.clearRect(0,0,c.width,c.height); drew=false; };
  zq("#sigUse").onclick=()=>{
    if(!drew) return toast("Nothing drawn yet", true);
    /* crop to the ink so the box matches the signature, not the pad */
    const d=x.getImageData(0,0,c.width,c.height).data;
    let x0=c.width,y0=c.height,x1=0,y1=0;
    for(let py=0;py<c.height;py++) for(let px=0;px<c.width;px++){
      if(d[(py*c.width+px)*4+3]>8){ if(px<x0)x0=px; if(px>x1)x1=px; if(py<y0)y0=py; if(py>y1)y1=py; }
    }
    const pad=12;
    x0=Math.max(0,x0-pad); y0=Math.max(0,y0-pad);
    x1=Math.min(c.width-1,x1+pad); y1=Math.min(c.height-1,y1+pad);
    const w=Math.max(1,x1-x0), h=Math.max(1,y1-y0);
    const o2=document.createElement("canvas"); o2.width=w; o2.height=h;
    o2.getContext("2d").drawImage(c,x0,y0,w,h,0,0,w,h);
    const url=o2.toDataURL("image/png");
    const asset={name:"drawn-signature.png", dataUrl:url, wPx:w, hPx:h,
                 bytes:Math.round(url.length*0.75), mime:"image/png", hasAlpha:true};
    const before=snapshot();
    if(targetId && obj(targetId)){ const o=obj(targetId); o.assetKind="signature"; applyAsset(o,asset); }
    else{
      const o=addObject("image", 30, 240, 45, 15);
      o.assetKind="signature"; o.name="Signature"; applyAsset(o,asset);
    }
    push("Draw signature", before, snapshot());
    closeModal(); render(); showCtxbar();
  };
}

function pickFile(targetId){
  const i=el("input"); i.type="file"; i.accept="image/png,image/jpeg,image/webp";
  i.style.cssText="position:fixed;left:-1000px";
  document.body.appendChild(i);
  i.onchange=()=>{ if(i.files&&i.files[0]) dropFiles(i.files,null,targetId); i.remove(); };
  i.click();
}
(function wireDnD(){
  const d=zq("#desk");
  let depth=0;
  const hasFiles=e=>e.dataTransfer && [...e.dataTransfer.types].includes("Files");
  d.addEventListener("dragenter", e=>{ if(!hasFiles(e))return; e.preventDefault(); depth++; d.classList.add("is-dropping"); });
  d.addEventListener("dragleave", e=>{ if(!hasFiles(e))return; depth=Math.max(0,depth-1); if(!depth) d.classList.remove("is-dropping"); });
  d.addEventListener("dragover", e=>{
    if(!hasFiles(e))return; e.preventDefault(); e.dataTransfer.dropEffect="copy";
    const n=e.target.closest(".obj");
    zqa(".obj.is-drop").forEach(x=>x.classList.remove("is-drop"));
    if(n && obj(n.dataset.id) && obj(n.dataset.id).type==="image") n.classList.add("is-drop");
  });
  d.addEventListener("drop", e=>{
    if(!hasFiles(e))return; e.preventDefault(); depth=0;
    d.classList.remove("is-dropping");
    zqa(".obj.is-drop").forEach(x=>x.classList.remove("is-drop"));
    const n=e.target.closest(".obj");
    const tgt = n && obj(n.dataset.id) && obj(n.dataset.id).type==="image" ? n.dataset.id : null;
    const k=pxPerMm(), r=zq("#page").getBoundingClientRect();
    dropFiles(e.dataTransfer.files, {x:mm((e.clientX-r.left)/k), y:mm((e.clientY-r.top)/k)}, tgt);
  });
  /* paste an image straight onto the page */
  window.addEventListener("paste", e=>{
    if(S.screen!=="designer" || S.editing) return;
    const it=[...(e.clipboardData&&e.clipboardData.items||[])].filter(x=>x.kind==="file");
    if(!it.length) return;
    e.preventDefault();
    const sel=S.sel.length===1&&obj(S.sel[0]).type==="image"?S.sel[0]:null;
    dropFiles(it.map(x=>x.getAsFile()).filter(Boolean), null, sel);
  });
})();

/* ==========================================================================
   13 · KEYBOARD
   ========================================================================== */
const TOOLKEY={v:"move",h:"hand",t:"text",b:"table",i:"image",l:"shape",q:"qr"};
window.addEventListener("keydown", e=>{
  if(S.screen!=="designer") return;
  const meta=e.metaKey||e.ctrlKey;
  const t=e.target;
  const typing = S.editing || (t && t.matches && t.matches("input,select,textarea,[contenteditable='true']"));

  if(e.code==="Space" && !typing){ spaceDown=true; zq("#desk").classList.add("is-pan"); }
  /* Escape is staged: never destroys work, always reaches a neutral state */
  if(e.key==="Escape"){
    if(zq("#scrim").classList.contains("is-on")) return closeModal();
    /* an open drawer is the frontmost thing on screen, so Esc dismisses it
       before it touches the selection underneath */
    if(zq(".rail").classList.contains("is-open") || zq(".insp").classList.contains("is-open"))
      return closeDrawers();
    if(S.editing){ commitEdit(); return; }          // 1 · commit, stay selected
    if(S.sel.length){ S.sel=[]; hideCtxbar(); render(); return; }   // 2 · deselect
    setTool("move"); return;                        // 3 · back to the move tool
  }
  if(typing){
    if(meta && ["b","i","u"].includes(e.key.toLowerCase()) && S.editing){
      e.preventDefault(); exec({b:"bold",i:"italic",u:"underline"}[e.key.toLowerCase()]);
    }
    return;
  }
  const selectable = ()=>S.tpl.objects.filter(o=>!o.locked && !S.hidden[o.id]);
  if(meta && e.key.toLowerCase()==="a"){
    e.preventDefault();
    const all=selectable().map(o=>o.id);
    S.sel = e.shiftKey ? all.filter(id=>!S.sel.includes(id)) : all;
    render(); if(S.sel.length) showCtxbar();
    toast(e.shiftKey?"Selection inverted — "+S.sel.length:"Selected all "+S.sel.length);
    return;
  }
  if(e.key==="Tab"){                       /* flat cycling — no nesting to descend */
    e.preventDefault();
    const list=selectable(); if(!list.length) return;
    const i=list.findIndex(o=>o.id===S.sel[0]);
    const n=list[((e.shiftKey? i-1 : i+1)+list.length)%list.length] || list[0];
    S.sel=[n.id]; render(); showCtxbar(); return;
  }
  if(e.altKey && !meta){                   /* Figma's align shortcuts */
    const A={KeyA:"left",KeyD:"right",KeyH:"centerX",KeyW:"top",KeyS:"bottom",KeyV:"middleY"}[e.code];
    if(A){ e.preventDefault(); alignSel(A); return; }
  }
  if(e.shiftKey && !meta && ["Digit0","Digit1","Digit2"].includes(e.code)){
    e.preventDefault();
    if(e.code==="Digit1") zoomFit();
    else if(e.code==="Digit2") zoomToSelection();
    else { S.zoom=1; layoutPage(); paintRulers(); paintStatus(); }
    return;
  }
  if(meta && (e.key==="="||e.key==="+")){ e.preventDefault(); zq("#zoomIn").click(); return; }
  if(meta && e.key==="-"){ e.preventDefault(); zq("#zoomOut").click(); return; }
  if(meta && e.key.toLowerCase()==="z"){ e.preventDefault(); e.shiftKey?redo():undo(); return; }
  if(meta && e.key.toLowerCase()==="y"){ e.preventDefault(); redo(); return; }
  if(meta && e.key.toLowerCase()==="d"){ e.preventDefault(); duplicateSel(); return; }
  if(meta && e.key.toLowerCase()==="c"){ S.clipboard=S.sel.map(obj).map(o=>JSON.parse(JSON.stringify(o))); toast("Copied "+S.clipboard.length); return; }
  if(meta && e.key.toLowerCase()==="v" && S.clipboard){
    const before=snapshot(), ids=[];
    S.clipboard.forEach(c=>{ const o=JSON.parse(JSON.stringify(c));
      o.id="obj_"+Math.random().toString(36).slice(2,6); o.xMm+=6; o.yMm=(o._y!=null?o._y:o.yMm)+6;
      o._y=null; o.anchorTo=null; o.requiredKey=null; S.tpl.objects.push(o); ids.push(o.id); });
    S.sel=ids; push("Paste", before, snapshot()); render(); return;
  }
  if(meta && e.key==="]"){ e.preventDefault(); zOrder(1); return; }
  if(meta && e.key==="["){ e.preventDefault(); zOrder(-1); return; }
  if(!meta && TOOLKEY[e.key.toLowerCase()]){ setTool(TOOLKEY[e.key.toLowerCase()]); return; }
  if(e.key==="Enter" && S.sel.length===1){
    const o=obj(S.sel[0]); if(o.type==="text"){ e.preventDefault(); enterEdit(o.id); } return;
  }
  if((e.key==="Delete"||e.key==="Backspace") && S.sel.length){ e.preventDefault(); tryDelete(); return; }
  const N={ArrowUp:[0,-1],ArrowDown:[0,1],ArrowLeft:[-1,0],ArrowRight:[1,0]}[e.key];
  if(N && S.sel.length){
    e.preventDefault();
    const before=snapshot(), step=e.shiftKey?10:1;
    S.sel.forEach(id=>{ const o=obj(id); if(o.locked) return;
      o.xMm=Math.max(0,o.xMm+N[0]*step); if(!o.anchorTo) o.yMm=Math.max(0,o.yMm+N[1]*step); });
    push("Nudge", before, snapshot()); render(); showCtxbar();
  }
});
window.addEventListener("keyup", e=>{
  if(e.code==="Space"){ spaceDown=false; if(S.tool!=="hand") zq("#desk").classList.remove("is-pan"); }
  if(e.key==="Alt") clearMeas();
});

/* ==========================================================================
   14 · CONTEXT MENU
   ========================================================================== */
const cm=zq("#ctxmenu");
zq("#page").addEventListener("contextmenu", e=>{
  e.preventDefault();
  const n=e.target.closest(".obj");
  if(n && !S.sel.includes(n.dataset.id)){ S.sel=[n.dataset.id]; render(); showCtxbar(); }
  const o=S.sel.length===1?obj(S.sel[0]):null;
  /* everything under the cursor, topmost first — the only way to reach an
     object that is underneath another, or one that is locked */
  const k=pxPerMm(), pr=zq("#page").getBoundingClientRect();
  const px=(e.clientX-pr.left)/k, py=(e.clientY-pr.top)/k;
  const under=S.tpl.objects.filter(x=>{
    const oy=resolvedY(x)+regionTop(x)/k;
    return px>=x.xMm && px<=x.xMm+x.wMm && py>=oy && py<=oy+(S.measured[x.id]||x.hMm);
  }).sort((a,b)=>(b.z||0)-(a.z||0));
  cm.innerHTML=`
    ${under.length>1?`<div class="layer__grp" style="margin:2px 0 3px 9px">Select layer</div>`+
      under.map(x=>`<button data-pick="${x.id}">${TYPEICON[x.type]||"◻"} ${esc(x.name||x.id)}${x.locked?" 🔒":""}${x.requiredKey?' <em style="color:var(--seal)">required</em>':""}</button>`).join("")+
      '<div class="div"></div>':""}
    ${o&&o.type==="text"?'<button data-cm="edit">✎ Edit text<em>Enter</em></button>':""}
    <button data-cm="dup" ${S.sel.length?"":"disabled"}>⧉ Duplicate<em>⌘D</em></button>
    <button data-cm="copy" ${S.sel.length?"":"disabled"}>Copy<em>⌘C</em></button>
    <div class="div"></div>
    <button data-cm="fwd" ${S.sel.length?"":"disabled"}>Bring forward<em>⌘]</em></button>
    <button data-cm="back" ${S.sel.length?"":"disabled"}>Send backward<em>⌘[</em></button>
    <button data-cm="lock" ${o?"":"disabled"}>${o&&o.locked?"Unlock position":"Lock position"}</button>
    <div class="div"></div>
    <button data-cm="anchor" ${o?"":"disabled"}>${o&&o.anchorTo?"Detach from anchor":"Anchor to object above"}</button>
    <div class="div"></div>
    <button data-cm="clearguides" ${(S.guides.v.length+S.guides.h.length)?"":"disabled"}>Remove all guides</button>
    <div class="div"></div>
    <button data-cm="del" class="danger" ${S.sel.length?"":"disabled"}>Delete<em>⌫</em></button>`;
  cm.classList.add("is-on");
  cm.style.left=Math.min(e.clientX, innerWidth-220)+"px";
  cm.style.top =Math.min(e.clientY, innerHeight-260)+"px";
});
window.addEventListener("mousedown", e=>{ if(!e.target.closest("#ctxmenu")) cm.classList.remove("is-on"); });
cm.addEventListener("click", e=>{
  const pick=e.target.closest("button[data-pick]");
  if(pick){ cm.classList.remove("is-on"); S.sel=[pick.dataset.pick]; render(); showCtxbar(); return; }
  const b=e.target.closest("button[data-cm]"); if(!b) return;
  cm.classList.remove("is-on");
  const o=S.sel.length===1?obj(S.sel[0]):null, a=b.dataset.cm;
  if(a==="edit"&&o) return enterEdit(o.id);
  if(a==="content"&&o) return openContentFor(o.id);
  if(a==="dup") return duplicateSel();
  if(a==="copy"){ S.clipboard=S.sel.map(obj).map(x=>JSON.parse(JSON.stringify(x))); return toast("Copied "+S.clipboard.length); }
  if(a==="fwd") return zOrder(1);
  if(a==="back") return zOrder(-1);
  if(a==="lock"&&o){ const before=snapshot(); o.locked=!o.locked; push("Lock",before,snapshot()); return render(); }
  if(a==="anchor"&&o){
    const before=snapshot();
    if(o.anchorTo){ o.anchorTo=null; o._y=null; toast("Detached — back to an absolute Y"); }
    else{
      const above=bodyObjects().filter(x=>x.id!==o.id && resolvedY(x)<resolvedY(o))
        .sort((p,q)=>resolvedY(q)-resolvedY(p))[0];
      if(!above) return toast("Nothing above to anchor to");
      o.anchorTo=above.id; o.anchorGapMm=Math.max(0, mm(resolvedY(o)-(resolvedY(above)+S.measured[above.id])));
      toast("Anchored to “"+(above.name||above.id)+"” with a "+o.anchorGapMm+" mm gap");
    }
    push("Anchor", before, snapshot()); return render();
  }
  if(a==="clearguides"){ S.guides={v:[],h:[]}; layoutPage(); return toast("Guides removed"); }
  if(a==="del") return tryDelete();
});

/* ==========================================================================
   15 · PANEL WIRING
   ========================================================================== */
/* ── narrow-viewport drawers ──────────────────────────────────────────────
   Below 1020px the rail, and below 760px the inspector, slide over the desk
   instead of being hidden outright. Media queries own the layout; JS only
   toggles `is-open`, so the two can never disagree about which mode is live. */
const railIsDrawer = ()=>matchMedia("(max-width:1020px)").matches;
const inspIsDrawer = ()=>matchMedia("(max-width:760px)").matches;

function drawerScrim(){
  let s=zq(".zxdt .drawer-scrim") || zq(".drawer-scrim");
  if(!s){ s=el("div","drawer-scrim"); zq(".dz").appendChild(s);
    s.addEventListener("click", ()=>closeDrawers()); }
  return s;
}
function syncScrim(){
  const open = zq(".rail").classList.contains("is-open") || zq(".insp").classList.contains("is-open");
  drawerScrim().classList.toggle("is-on", open);
}
function closeDrawers(){
  zq(".rail").classList.remove("is-open");
  zq(".insp").classList.remove("is-open");
  syncScrim();
}
function openRailDrawer(){
  if(!railIsDrawer()) return;
  zq(".insp").classList.remove("is-open");
  zq(".rail").classList.add("is-open");
  syncScrim();
}
/* Leaving drawer mode must not strand an `is-open` class on a panel that is
   once again a normal flex child — harmless visually, but it would leave the
   scrim up over a desk nobody can click. */
matchMedia("(max-width:1020px)").addEventListener("change", closeDrawers);
matchMedia("(max-width:760px)").addEventListener("change", closeDrawers);

zq("#tabstrip").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  const alreadyShowing = b.classList.contains("is-on") && zq(".rail").classList.contains("is-open");
  zqa("#tabstrip button").forEach(t=>t.classList.toggle("is-on", t===b));
  zqa(".rail__pane").forEach(p=>p.classList.toggle("is-on", p.dataset.pane===b.dataset.pane));
  zq("#railHead").textContent={layers:"Layers",insert:"Insert",fields:"Merge fields",blocks:"Reusable blocks",content:"Content"}[b.dataset.pane];
  /* in drawer mode the tabstrip is the only way back in, so it must open —
     and tapping the tab of the pane already showing closes it again */
  if(railIsDrawer()){ alreadyShowing ? closeDrawers() : openRailDrawer(); }
});

zq("#inspBtn").addEventListener("click", ()=>{
  const insp=zq(".insp");
  if(insp.classList.contains("is-open")) return closeDrawers();
  zq(".rail").classList.remove("is-open");
  insp.classList.add("is-open");
  syncScrim();
});
/* Content pane: Edit all / Read toggle. Read is proofreading — deliberately
   read-only, so a final check cannot become an accidental edit. */
/* Inspector's "Edit in Content" — the old ✎ here duplicated the context-toolbar
   button, sat far from the object, and was the least discoverable of four doors
   into the same mode. It now opens the mode-free route instead. */
function openContentFor(id){
  const tab=zq('#tabstrip button[data-pane="content"]'); if(tab) tab.click();
  S.cmode="edit";
  zqa("#contentMode button").forEach(t=>t.classList.toggle("is-on", t.dataset.cmode==="edit"));
  paintContent();
  const box=zq(`#contentList [data-cid="${id}"]`);
  if(box){ box.scrollIntoView({block:"center"}); box.focus(); }
  else toast("That object has no editable text");
}

zq("#contentMode").addEventListener("click", e=>{
  const b=e.target.closest("button[data-cmode]"); if(!b) return;
  S.cmode=b.dataset.cmode;
  zqa("#contentMode button").forEach(t=>t.classList.toggle("is-on", t===b));
  paintContent();
});

zq(".rail").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  if(b.dataset.add) addObject(b.dataset.add);
  if(b.dataset.review) return openBlockUpdate(b.dataset.review);
  if(b.id==="simEdit"){
    BLOCKS[0].version++; S.blockIgnored.BLK0001=false; render();
    toast("A new letterhead version was published — this template is now offered the update");
  }
  if(b.id==="gridBtn"){ S.grid=!S.grid; layoutPage(); toast("Grid "+(S.grid?"on":"off")); }
  if(b.id==="anchorBtn"){ S.anchors=!S.anchors; layoutPage(); toast("Anchor chains "+(S.anchors?"shown":"hidden")); }
});
zq(".insp").addEventListener("change", e=>{
  const t=e.target;
  if(t.dataset.p){
    const o=obj(S.sel[0]); if(!o) return;
    const before=snapshot(), p=t.dataset.p;
    let v = t.hasAttribute("data-num") ? evalMm(t.value, null) : t.value;
    if(t.hasAttribute("data-num") && v===null){ toast("That isn't a number or a sum I can work out", true); return render(); }
    if(p==="anchorTo"){ o.anchorTo=v||null; if(!v) o._y=null; }
    else if(p.startsWith("style.")){ o.style=o.style||{}; o.style[p.slice(6)] = v===""?null:v; }
    else o[p]=v;
    push("Edit "+p, before, snapshot()); render();
  }
  if(t.dataset.row!=null){
    const o=obj(S.sel[0]), before=snapshot();
    o.content.rows[+t.dataset.row].key=t.value;
    push("Change row field", before, snapshot()); render();
  }
  if(t.dataset.region){
    const before=snapshot();
    S.tpl.regionLang=S.tpl.regionLang||{};
    S.tpl.regionLang[t.dataset.region]=t.value||null;
    push("Region language", before, snapshot()); render();
    toast(t.dataset.region+" region: "+(t.value?LANGS[t.value].native:"auto"));
  }
  if(t.dataset.page){
    const before=snapshot(), k=t.dataset.page;
    if(k==="size"||k==="orientation") S.tpl.page[k]=t.value;
    else { const v=evalMm(t.value,null); if(v===null){ toast("That isn't a number I can work out", true); return render(); }
           S.tpl.page.marginsMm[k]=v; }
    push("Page setup", before, snapshot()); render();
  }
});
zq(".insp").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  const o=S.sel.length===1?obj(S.sel[0]):null;
  if(b.dataset.delrow!=null && o){ const before=snapshot(); o.content.rows.splice(+b.dataset.delrow,1);
    push("Remove row", before, snapshot()); return render(); }
  if(b.dataset.h && o){ const before=snapshot(); o.height=b.dataset.h; push("Height mode",before,snapshot()); return render(); }
  if(b.dataset.flow && o){
    const before=snapshot();
    if(b.dataset.flow==="abs"){ o.anchorTo=null; o._y=null; }
    else{
      const above=bodyObjects().filter(x=>x.id!==o.id && resolvedY(x)<resolvedY(o))
        .sort((p,q)=>resolvedY(q)-resolvedY(p))[0];
      if(!above){ toast("Nothing above to anchor to"); return; }
      o.anchorTo=above.id;
      o.anchorGapMm=Math.max(0, mm(resolvedY(o)-(resolvedY(above)+S.measured[above.id])));
    }
    push("Flow mode", before, snapshot()); return render();
  }
  if(b.dataset.al && o){ const before=snapshot(); o.style.align=b.dataset.al; push("Align text",before,snapshot()); return render(); }
  if(b.dataset.lock && o){ const before=snapshot(); o.locked=b.dataset.lock==="1"; push("Lock",before,snapshot()); return render(); }
  if(b.dataset.align) return alignSel(b.dataset.align);
  if(b.dataset.cite) return openCite(b.dataset.cite);
  const a=b.dataset.act;
  if(a==="replaceAsset"&&o) return pickFile(o.id);
  if(a==="drawSig"&&o) return openSignaturePad(o.id);
  if(a==="clearAsset"&&o){ const before=snapshot(); o.asset=null;
    push("Remove image", before, snapshot()); return render(); }
  if(a==="edit"&&o) return enterEdit(o.id);
  if(a==="addrow"&&o){ const before=snapshot(); o.content.rows.push({key:"student.fullName"});
    push("Add row", before, snapshot()); return render(); }
  if(a==="dup") return duplicateSel();
  if(a==="del") return tryDelete();
  if(a==="fwd") return zOrder(1);
  if(a==="back") return zOrder(-1);
});
/* Bound to BOTH containers: the language and sample-data buttons moved to the
   status bar, and a handler delegated only on #topActions would have silently
   stopped working the moment they left it. */
["#topActions","#viewStrip"].forEach(sel=>{ const n=zq(sel); if(n) n.addEventListener("click", onTopAction); });
function onTopAction(e){
  const b=e.target.closest("button"); if(!b) return;
  if(b.dataset.lang){ commitEdit(); S.lang=b.dataset.lang; render(); toast("Previewing "+LANGS[S.lang].native); }
  if(b.dataset.data){ S.data=b.dataset.data; render();
    if(b.dataset.data==="p95") toast("p95 sample data — this is the length that overflows in production"); }
  if(b.id==="undoBtn") undo();
  if(b.id==="redoBtn") redo();
  if(b.id==="proofBtn") openProof();
  if(b.id==="pubBtn") openPublish();
  if(b.id==="histBtn") openHistory();
}
zq("#crumb").addEventListener("click", e=>{
  const b=e.target.closest("button[data-go]"); if(b){ commitEdit(); go(b.dataset.go); }
});
zqa(".sect__head").forEach(h=>h.onclick=()=>zq("#"+h.dataset.sect).classList.toggle("is-open"));
zq("#keysBtn").onclick=openKeys;
zq("#dupToggle").onclick=()=>{
  S.issuance.duplicate=!S.issuance.duplicate; render();
  toast(S.issuance.duplicate ? "Previewing as a duplicate — the statutory mark must appear"
                             : "Previewing as an original");
};
/* THEME: one control per page, speaking one vocabulary.
   
   The panel's day/night switch writes data-theme="day"|"night", and this
   module's CSS already reads exactly that. This button wrote "dark"|"light" —
   a third vocabulary nothing on the page understands. Clicking it left the
   document in a theme neither the panel chrome nor the designer recognised, so
   appearance silently fell back to the OS preference and the panel's own
   switch stopped agreeing with the page. It also explains why toggling did
   nothing visible here.
   
   Inside the panel the button is REMOVED — the panel already has a theme
   switch, and two controls for one setting is how they drift apart. It stays
   for the standalone harness, where nothing else provides one, and there it
   now speaks day/night like everything else. */
(function(){
  const btn=zq("#themeBtn"); if(!btn) return;
  if(SRV.online){ btn.remove(); return; }
  btn.onclick=()=>{
    const r=document.documentElement;
    const cur=r.getAttribute("data-theme")
      || (matchMedia("(prefers-color-scheme:dark)").matches?"night":"day");
    r.setAttribute("data-theme", cur==="night"?"day":"night");
    if(S.screen==="designer") layoutPage();
  };
})();
window.addEventListener("resize", ()=>{ if(S.screen==="designer"){ layoutPage(); positionCtxbar(); } });

/* ==========================================================================
   16 · MODALS
   ========================================================================== */
function modal(title, sub, body, foot, small){
  zq("#mTitle").textContent=title; zq("#mSub").textContent=sub||"";
  zq("#mBody").innerHTML=body; zq("#mFoot").innerHTML=foot||"";
  zq("#modal").classList.toggle("modal--sm", !!small);
  zq("#scrim").classList.add("is-on");
}
const closeModal=()=>zq("#scrim").classList.remove("is-on");
zq("#scrim").addEventListener("click", e=>{ if(e.target.id==="scrim"||e.target.closest("[data-close]")) closeModal(); });

function openKeys(){
  const K=[["V / H","Move · Hand"],["T / B / I / L / Q","Text · Table · Image · Rule · QR"],
    ["click / drag with T","Auto-grow text · fixed box"],
    ["Double-click","Edit text in place"],["Enter","Edit the selected text object"],
    ["Esc","1 finish editing · 2 deselect · 3 back to Move"],
    ["⌘B / ⌘I / ⌘U","Bold · italic · underline while editing"],
    ["↑ ↓ ← →","Nudge 1 mm"],["shift + arrows","Nudge 10 mm"],
    ["⌘A / ⌘⇧A","Select all · invert selection"],["Tab / ⇧Tab","Cycle objects"],
    ["shift + click","Add to selection"],["drag on paper","Marquee select"],
    ["right-click","Select layer — pick from what's underneath"],
    ["⌘D","Duplicate"],["alt + drag","Duplicate as you drag"],["⌘C / ⌘V","Copy · paste"],
    ["⌘] / ⌘[","Bring forward · send backward"],["⌘Z / ⌘⇧Z","Undo · redo"],
    ["⌥A ⌥H ⌥D","Align left · centre · right"],["⌥W ⌥V ⌥S","Align top · middle · bottom"],
    ["⇧1 / ⇧2 / ⇧0","Zoom to fit · to selection · 100%"],["⌘+ / ⌘−","Zoom in · out"],
    ["space + drag","Pan the desk"],["⌘ + scroll","Zoom"],
    ["alt + hover","Measure to another object"],
    ["drag from a ruler","Pull out a guide · drag it back to remove"],
    ["⌫","Delete (refused on required objects)"]];
  modal("Keyboard","Figma's conventions where one already exists — see design/FIGMA_STUDY.md",
    `<div class="keys">${K.map(([k,d])=>`<div><span class="kbd">${esc(k)}</span><span>${esc(d)}</span></div>`).join("")}</div>`,
    '<button class="btn" data-close>Close</button>');
}
function openCite(key, refused){
  const au=keyAuthority(key), p=prof(), f=FIELD[key]||{label:key};
  const A = au || {label:p.name, authority:p.authority, evidence:p.evidence,
                   verifiedOn:p.verifiedOn, owner:p.owner, scopeNote:p.scope, tier:"board"};
  modal(refused?"This object cannot be deleted":"Why this field is required",
    refused?"It carries a compliance binding":"Compliance rule detail",
    `<div class="cite">
      ${refused?`<p style="margin-top:0"><b>${esc(f.label)}</b> is a required object under the profile resolved for your school.
        Every other freedom stands — move it, resize it, restyle it, change its font, translate it.
        Presence is the only thing enforced.</p>`:""}
      <dl>
        <dt>Field</dt><dd><b>${esc(f.label)}</b> <span class="mono" style="color:var(--ink3)">${esc(key)}</span></dd>
        <dt>Required by</dt><dd><span class="tier tier--${A.tier}">${A.tier==="board"?"Central board":A.tier}</span> <b>${esc(A.label)}</b></dd>
        <dt>Authority</dt><dd>${esc(A.authority||"—")}</dd>
        <dt>Evidence</dt><dd><span class="lvl lvl--${A.evidence}">Level ${A.evidence}</span>${A.evidence==="A"?" — read from the primary text, not a secondary reproduction":A.evidence==="B"?" — cited from the research corpus; the primary text was not read in this pass":""}</dd>
        <dt>Verified</dt><dd>${esc(A.verifiedOn||"—")}${A.owner?" by "+esc(A.owner):""}</dd>
        <dt>Scope</dt><dd>${esc(A.scopeNote||"—")}</dd>
        ${A.sourceRef?`<dt>Source</dt><dd class="mono" style="font-size:10px;word-break:break-all">${esc(A.sourceRef)}</dd>`:""}
      </dl>
      <p style="margin-bottom:0;color:var(--ink3);font-size:11px">
        Compliance validates the <b>template</b>, never the issuance. That a form carries a
        "fees paid up to" field grants no power to withhold the certificate — courts have repeatedly
        held a TC is not a tool to collect arrears, and at the elementary stage it cannot be withheld at all.</p>
    </div>`,
    `<button class="btn" data-close>Close</button>
     ${refused?'<span style="font-size:11px;color:var(--ink3);margin-left:auto">Unbind the field first if you truly need to remove it.</span>':""}`, true);
}
function openBlockUpdate(id){
  const bl=BLOCKS.find(b=>b.id===id), ref=S.blockRefs[id];
  const live = S.tpl.activeVersion!=null;
  modal("Letterhead update available", `v${ref} → v${bl.version} · ${esc(bl.name)}`,
    `<div class="cmp">
       <div class="cmp__col"><h5>In this template — v${ref}</h5><div class="cmp__paper" id="cmpA"></div></div>
       <div class="cmp__col"><h5>Published block — v${bl.version}</h5><div class="cmp__paper" id="cmpB"></div></div>
     </div>
     <div class="cmp__key"><span><b style="background:var(--clay)"></b>changed</span></div>
     <p class="note">${live
       ? `This template has <b>v${S.tpl.activeVersion} published and active</b>. Accepting does <b>not</b> alter it — the published snapshot is what a certificate issued last month was rendered from, and it never changes. Accepting creates <b>draft v${S.tpl.version+1}</b>, which goes through the usual publish gate including a fresh proof render.`
       : `This template is a draft, so accepting applies the change straight away.`}</p>
     <p class="note" style="margin-bottom:0">Ignoring is remembered. A school may deliberately keep an older letterhead on one certificate type.</p>`,
    `<button class="btn" id="blkIgnore">Ignore</button><span class="spacer"></span>
     <button class="btn" data-close>Decide later</button>
     <button class="btn btn--primary" id="blkAccept">Accept${live?" — creates draft v"+(S.tpl.version+1):""}</button>`);
  const head=S.tpl.objects.filter(o=>o.region==="header");
  schematic(head, zq("#cmpA"));
  schematic(head, zq("#cmpB"));
  [...zq("#cmpB").children].slice(1,3).forEach(i=>i.className="chg");
  zq("#blkIgnore").onclick=()=>{ S.blockIgnored[id]=true; closeModal(); render(); toast("Update ignored — the badge will stay quiet"); };
  zq("#blkAccept").onclick=()=>{
    S.blockRefs[id]=bl.version;
    if(live){ S.tpl.version++; S.proofed=null; toast("Accepted — now editing draft v"+S.tpl.version+". The published version is untouched."); }
    else toast("Accepted — applied to the draft");
    markDirty(); closeModal(); render();
  };
}

/* ── A4 · compare this draft with the published version ───────────────── */
function diffObjects(base, cur){
  const bi=Object.fromEntries(base.map(o=>[o.id,o])), ci=Object.fromEntries(cur.map(o=>[o.id,o]));
  const changed=[], added=[], removed=[];
  cur.forEach(o=>{
    const b=bi[o.id];
    if(!b){ added.push(o.id); return; }
    const same = b.xMm===o.xMm && b.yMm===o.yMm && b.wMm===o.wMm && b.hMm===o.hMm &&
      b.height===o.height && b.anchorTo===o.anchorTo && (b.maxHMm||null)===(o.maxHMm||null) &&
      JSON.stringify(b.style)===JSON.stringify(o.style) && JSON.stringify(b.content)===JSON.stringify(o.content);
    if(!same) changed.push(o.id);
  });
  base.forEach(o=>{ if(!ci[o.id]) removed.push(o.id); });
  return {changed, added, removed};
}
function openCompare(){
  const base=S.baseline||[], cur=S.tpl.objects;
  const d=diffObjects(base, cur);
  const paint=(host, objs, marks)=>{
    schematic(objs, host);
    [...host.children].forEach((i,idx)=>{
      const o=objs[idx]; if(!o) return;
      if(marks.changed.includes(o.id)) i.className="chg";
      else if(marks.added.includes(o.id)) i.className="add";
      else if(marks.removed.includes(o.id)) i.className="del";
    });
  };
  const list = [...d.changed.map(id=>["changed",id]), ...d.added.map(id=>["added",id]), ...d.removed.map(id=>["removed",id])];
  modal("Compare with the published version",
    `v${S.tpl.activeVersion||"—"} (active) vs v${S.tpl.version} (this draft)`,
    `<div class="cmp">
      <div class="cmp__col"><h5>v${S.tpl.activeVersion||"—"} · active</h5><div class="cmp__paper" id="cmpL"></div></div>
      <div class="cmp__col"><h5>v${S.tpl.version} · this draft</h5><div class="cmp__paper" id="cmpR"></div></div>
     </div>
     <div class="cmp__key">
       <span><b style="background:var(--clay)"></b>changed</span>
       <span><b style="background:var(--ok)"></b>added</span>
       <span><b style="background:var(--warn)"></b>removed</span>
     </div>
     ${list.length?`<div style="margin-top:12px">${list.map(([k,id])=>{
        const o=obj(id)||(base.find(x=>x.id===id)||{});
        return `<div class="kv"><span>${esc(o.name||id)}</span><b>${k}</b></div>`;}).join("")}</div>`
       : `<p class="note" style="margin-bottom:0">Nothing has changed since v${S.tpl.activeVersion}.</p>`}
     <p class="note" style="margin-bottom:0">"What changed since the version that is live?" is the question a Principal asks before approving. It is the moment of legal exposure in this module, so it should not require opening two windows and squinting.</p>`,
    `<button class="btn" data-close>Close</button>`);
  paint(zq("#cmpL"), base, {changed:d.changed, added:[], removed:d.removed});
  paint(zq("#cmpR"), cur,  {changed:d.changed, added:d.added, removed:[]});
}

function openProof(){
  modal("Proof render","Through the real mPDF pipeline — not a browser approximation",
   `<div class="langtabs">${S.tpl.languages.map(l=>{const c=translationCoverage(l);
      return `<span class="langtab ${l===S.lang?"is-on":""}">${LANGS[l].native} <span class="mono" style="font-size:9px;opacity:.75">${c.done}/${c.total}</span></span>`;}).join("")}</div>
    <div class="proof">
      <div class="proof__paper" id="proofPaper"></div>
      <div class="proof__side">
        <div id="proofLog" style="font-size:11.5px;color:var(--ink2);line-height:1.7"></div>
        <div class="bar"><i id="proofBar"></i></div>
        <div id="proofKv"></div>
      </div>
    </div>
    <p class="note" style="margin-bottom:0">Proof renders are explicit, never per keystroke — mPDF is heavy and the
    production box has an OOM history. The browser preview you edit against uses the <b>same serializer</b>,
    so a difference between them is a bug, not a style choice.</p>`,
   `<button class="btn" data-close>Close</button><span class="spacer"></span>
    <button class="btn btn--primary" id="proofRun">Render proof</button>`);
  schematic(S.tpl.objects, zq("#proofPaper"));
  zq("#proofRun").onclick=()=>{
    if(SRV.online) return proofOnServer();
    const log=zq("#proofLog"), bar=zq("#proofBar");
    const steps=["Serializing template → HTML (namespaced under .zx-tpl-"+S.tpl.templateId+")",
      "Resolving merge fields against sample data",
      "Registering fonts — lohitdeva, dejavusans · useOTL 0xFF",
      "mPDF render · "+S.tpl.page.size+" "+S.tpl.page.orientation,
      "Hashing PDF bytes"];
    log.innerHTML=""; bar.style.width="0";
    steps.forEach((s,i)=>setTimeout(()=>{
      log.insertAdjacentHTML("beforeend", `<div>· ${esc(s)}</div>`);
      bar.style.width=((i+1)/steps.length*100)+"%";
      if(i===steps.length-1){
        const hash="sha256:"+Math.random().toString(16).slice(2,10)+"a41f9c2e"+Math.random().toString(16).slice(2,6);
        S.proofed={hash};
        zq("#proofKv").innerHTML=`<div class="kv"><span>Result</span><b style="color:var(--ok)">rendered · 1 page</b></div>
          <div class="kv"><span>Peak memory</span><b>84 MB</b></div>
          <div class="kv"><span>Render time</span><b>1.9 s</b></div>
          <div class="kv"><span>Content hash</span><b>${hash}</b></div>`;
        paintCompliance(); paintStatus(); toast("Proof rendered — publish is now unlocked");
      }
    }, 380*(i+1)));
  };
}
/**
 * Render the proof ON THE SERVER — the real one.
 *
 * The draft is SAVED FIRST, deliberately. The server renders from the stored
 * template, not from anything posted with the request, and publish() later
 * verifies the proof still describes the stored design. Proofing unsaved edits
 * would produce a proof of a document that does not exist anywhere.
 *
 * The button is disabled while it runs. A proof is seconds of mPDF work, and a
 * second click would start a second render whose result arrives later and
 * overwrites the first — the caller would be looking at a hash for a render
 * they did not watch.
 */
async function proofOnServer(){
  const log=zq("#proofLog"), bar=zq("#proofBar"), btn=zq("#proofRun");
  const say=t=>{ if(log) log.insertAdjacentHTML("beforeend", `<div>· ${esc(t)}</div>`); };
  if(btn) btn.disabled=true;
  if(log) log.innerHTML=""; if(bar) bar.style.width="15%";

  try{
    if(S.dirty){
      say("Saving the draft — the server renders what is stored, not what is on screen");
      if(!await srvSaveDraft(true)){
        /* SAY WHY IT STOPPED.
           The proof must render what is STORED, so a refused save means there
           is nothing new to render — correct, but this used to reset the bar to
           zero and return in silence, so pressing Render Proof simply did
           nothing and there was no way to tell whether it was broken, slow, or
           refusing. */
        if(bar){ bar.style.width="0"; bar.classList.remove("is-working"); }
        say(S.conflict
          ? "STOPPED — this template was changed elsewhere, so your edits are not saved and "
            + "there is nothing new to render. Reload to pick up the current version."
          : "STOPPED — the draft could not be saved, and a proof can only render what is saved.");
        toast(S.conflict
          ? "Not proofed — reload to get the current version of this template"
          : "Not proofed — the draft could not be saved", true);
        return;
      }
    }
    const langs=(S.tpl.languages||["en"]).length;
    say("Rendering "+langs+" language(s) at p95 · mPDF");

    /* THE BAR MUST NOT FREEZE.
    
       It used to jump to 55% and sit there for the whole render — four seconds
       of a motionless bar and a disabled button, which is indistinguishable
       from a hang. The work happens in ONE server request, so the client cannot
       know real progress; pretending to would be worse. So show the honest
       thing: elapsed seconds, ticking, against what this template usually
       takes. A number that moves says "working"; a bar that doesn't says
       "broken". */
    const t0=Date.now();
    if(bar){ bar.style.width="15%"; bar.classList.add("is-working"); }
    const tick=setInterval(()=>{
      const s=Math.round((Date.now()-t0)/1000);
      if(bar) bar.style.width=Math.min(90, 15+s*8)+"%";
      const el=document.getElementById("proofElapsed");
      if(el) el.textContent=s+"s"+(s>20?" — still working; long or multi-language templates take longer":"");
    },1000);
    if(log) log.insertAdjacentHTML("beforeend",
      '<div>· elapsed <b id="proofElapsed">0s</b></div>');

    let out;
    try { out=await srv.proof(S.tpl.templateId); }
    finally { clearInterval(tick); if(bar) bar.classList.remove("is-working"); }
    const pr=out.proof||{};
    S.proofed={hash:pr.hash, pages:pr.pages, contentHash:pr.contentHash, paths:out.paths||{}};
    if(bar) bar.style.width="100%";
    say("Hashed "+pr.pages+" page(s)");

    const kv=zq("#proofKv");
    if(kv) kv.innerHTML=`<div class="kv"><span>Result</span><b style="color:var(--ok)">rendered · ${pr.pages} page(s)</b></div>
      <div class="kv"><span>Engine</span><b>mPDF ${esc(pr.mpdfVersion||"?")}</b></div>
      <div class="kv"><span>Content hash</span><b>${esc(pr.hash||"")}</b></div>`;
    paintCompliance(); paintStatus();
    toast("Proof rendered — publish is now unlocked");
  }catch(e){
    if(bar) bar.style.width="0";
    say("FAILED — nothing was recorded");
    /* S.proofed stays null on purpose: a failed proof must NOT unlock publish. */
    apiFail(e, "Proof");
  }finally{
    if(btn) btn.disabled=false;
  }
}

function openPublish(){
  const v=validate(), p=prof(), rows=[];
  const unbound=v.blocking.filter(b=>b.type==="unbound"), lh=v.blocking.filter(b=>b.type==="lineheight");
  rows.push(unbound.length?{c:"fail",t:`${unbound.length} required field${unbound.length>1?"s":""} unbound`,s:unbound.map(b=>b.key).join(", ")}
                          :{c:"pass",t:"Every required field is bound",s:`${p.requiredKeys.length} keys under ${p.name}`});
  rows.push(lh.length?{c:"fail",t:"An object has no line height",s:"mPDF and the browser will disagree — see "+lh[0].id}
                     :{c:"pass",t:"Every text object declares a line height",s:"Renderer agreement verified at 92/92 probes"});
  const nd=v.blocking.filter(b=>b.type==="noDuplicateMark");
  if(nd.length) rows.push({c:"fail", t:"No duplicate mark on this template",
    s:nd[0].req.map(x=>x.a.label+" "+x.d.citation).join("; ")+" — a reissue must be marked"});
  const ns=v.blocking.filter(b=>b.type==="noSignature");
  if(ns.length) rows.push({c:"fail", t:ns.length+" prescribed signature block"+(ns.length>1?"s":"")+" missing",
    s:ns.map(b=>b.role.replace(/_/g," ")).join(", ")});
  const oc=v.blocking.filter(b=>b.type==="offContract");
  if(oc.length) rows.push({c:"fail", t:oc.length+" field"+(oc.length>1?"s":"")+" not declared by this document type",
    s:oc.map(b=>b.key).join(", ")+" — contract mismatch is a hard error at render, never a blank"});
  const wi=v.blocking.filter(b=>b.type==="wrongInstrument");
  if(wi.length) rows.push({c:"fail", t:"This is the wrong instrument for this pupil",
    s:wi[0].authority.label+" "+wi[0].route.citation+" — "+wi[0].route.label+" must be issued instead"});
  const cl=v.blocking.filter(b=>b.type==="clamped");
  rows.push(cl.length?{c:"fail",t:"Content is being cut off at its max height",
      s:cl.map(b=>(obj(b.id)||{}).name+" overshoots by "+mm(b.over)+" mm at this data").join("; ")}
    :{c:"pass",t:"Nothing is truncated at the current data",s:"Check again in p95 before publishing"});
  rows.push(S.proofed?{c:"pass",t:"Proof render succeeded",s:S.proofed.hash}
                     :{c:"fail",t:"No proof render on this version",s:"Publish is gated on a PDF that actually rendered"});
  const cov=translationCoverage("hi");
  if(cov.done<cov.total) rows.push({c:"warn",t:`${cov.total-cov.done} strings untranslated in हिन्दी`,
    s:"languageFallback is block — a missing Hindi string stops the render, it does not silently fall back to English"});
  const iq=v.warnings.filter(w=>["lowDpi","noAsset","noAlpha"].includes(w.type));
  if(iq.length) rows.push({c:"warn", t:"Image quality", s:iq.map(w=>{
    const o=obj(w.id)||{}; return (o.name||w.id)+" — "+(w.type==="lowDpi"?w.dpi+" dpi":w.type==="noAsset"?"empty placeholder":"no transparency");
  }).join("; ")});
  const ov=v.warnings.filter(w=>w.type==="overflow");
  if(ov.length) rows.push({c:"warn",t:"Text overflows a fixed box at this data",s:ov.map(o=>o.id).join(", ")});
  const blocked=rows.some(r=>r.c==="fail");
  modal("Publish version "+S.tpl.version, blocked?"Blocked — resolve the red rows first":"This freezes an immutable snapshot",
    `<div class="gate">${rows.map(r=>`<div class="gate__row gate--${r.c}">
      <span class="gate__ic">${r.c==="pass"?"✓":r.c==="fail"?"✕":"▲"}</span>
      <span><b>${esc(r.t)}</b><span>${esc(r.s)}</span></span></div>`).join("")}</div>
     <p class="note">Publishing writes <span class="mono">documentTemplateVersions/${esc(S.tpl.schoolId)}_${esc(S.tpl.templateId)}_v${S.tpl.version}</span>
     — create-only, never updated or deleted, by anyone. It records the font manifest and the mPDF version too,
     so a re-render years from now is explainable rather than mysterious.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="pubGo" ${blocked?"disabled":""}>Publish v${S.tpl.version}</button>`);
  /* NOT "Publish and set active" — publishing freezes a snapshot and stops.
     Activation is a separate decision with its own blast radius (§9.2c), and
     the very next modal asks for it. A label promising activation here would
     be a lie about a legally consequential action. */
  const g=zq("#pubGo"); if(g) g.onclick=()=>{
    if(SRV.online) return publishOnServer();
    S.tpl.publishedVersion=S.tpl.version; S.tpl.version++; S.dirty=false;
    /* publishing freezes a snapshot. It does NOT make it the one that prints —
       that is activation, and it is a separate decision with its own blast radius. */
    let row=libOf(S.tpl.docType).find(r=>r.id===S.tpl.templateId);
    if(!row){ row={id:S.tpl.templateId, name:S.tpl.name, starter:"tc_cbse", edited:"just now"};
      (S.lib[S.tpl.docType]=S.lib[S.tpl.docType]||[]).push(row); }
    row.name=S.tpl.name; row.status="published";
    row.publishedVersion=S.tpl.publishedVersion; row.version=S.tpl.version; row.edited="just now";
    const already = S.active[S.tpl.docType]===S.tpl.templateId;
    S.tpl.activeVersion = already ? S.tpl.publishedVersion : null;
    closeModal(); render();
    if(already) return toast("Published v"+S.tpl.publishedVersion+" — it was already active, so it is live now");
    const cur=activeTpl(S.tpl.docType);
    modal("Published v"+S.tpl.publishedVersion, "Publishing freezes it. Activating is what makes it print.",
      `<div class="gate">
         <div class="gate__row gate--pass"><span class="gate__ic">✓</span>
           <span><b>v${S.tpl.publishedVersion} is frozen</b><span>Immutable, with its proof hash, font manifest and mPDF version recorded.</span></span></div>
         <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
           <span><b>${cur?"“"+esc(cur.name)+"” is still the active template":"Nothing is active for this document type"}</b>
           <span>${cur?"Nothing prints from your new version until you activate it.":"No print point can resolve this type until something is activated."}</span></span></div>
       </div>`,
      `<button class="btn" data-close>Leave it published only</button><span class="spacer"></span>
       <button class="btn btn--primary" id="pubAct">Set v${S.tpl.publishedVersion} active</button>`, true);
    zq("#pubAct").onclick=()=>{
      if(SRV.online) return activateOnServer();
      S.active[S.tpl.docType]=S.tpl.templateId;
      S.tpl.activeVersion=S.tpl.publishedVersion;
      closeModal(); render(); toast("Active — every print point now resolves v"+S.tpl.publishedVersion);
    };
  };
}
/**
 * Publish, on the server.
 *
 * The server refuses without a proof on record that still describes the stored
 * design, so the two failures worth catching here are "you changed something
 * after proofing" and "you never proofed" — both of which must say so plainly
 * rather than appear to succeed.
 */
async function publishOnServer(){
  const btn=zq("#pubGo"); if(btn) btn.disabled=true;
  try{
    if(S.dirty && !await srvSaveDraft(true)) return;

    const out=await srv.publish(S.tpl.templateId);
    S.tpl.publishedVersion=out.version;
    S.tpl.version=out.version+1;
    /* Adopt the new lockVersion. Publishing bumps it server-side, and holding
       the old one made the very next autosave conflict. */
    if(out.lockVersion!=null) S.tpl.lockVersion=out.lockVersion;
    S.dirty=false; S.proofed=null;   // the new draft has no proof of its own

    let row=libOf(S.tpl.docType).find(r=>r.id===S.tpl.templateId);
    if(!row){ row={id:S.tpl.templateId, name:S.tpl.name, starter:S.tpl.starterId||"tc_cbse"};
      (S.lib[S.tpl.docType]=S.lib[S.tpl.docType]||[]).push(row); }
    Object.assign(row,{name:S.tpl.name, status:"published",
      publishedVersion:out.version, version:S.tpl.version, edited:"just now"});

    const already = S.active[S.tpl.docType]===S.tpl.templateId;
    S.tpl.activeVersion = already ? out.version : S.tpl.activeVersion;
    closeModal(); render();

    if(already) return toast("Published v"+out.version+" — it was already active, so it is live now");
    offerActivation(out.version);
  }catch(e){
    apiFail(e, "Publish");
  }finally{ if(btn) btn.disabled=false; }
}

/** The activation offer, after a successful publish. */
function offerActivation(version){
  const cur=activeTpl(S.tpl.docType);
  modal("Published v"+version, "Publishing freezes it. Activating is what makes it print.",
    `<div class="gate">
       <div class="gate__row gate--pass"><span class="gate__ic">✓</span>
         <span><b>v${version} is frozen</b><span>Immutable, with its proof hash, font manifest and mPDF version recorded.</span></span></div>
       <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
         <span><b>${cur?"“"+esc(cur.name)+"” is still the active template":"Nothing is active for this document type"}</b>
         <span>${cur?"Nothing prints from your new version until you activate it.":"No print point can resolve this type until something is activated."}</span></span></div>
     </div>`,
    `<button class="btn" data-close>Leave it published only</button><span class="spacer"></span>
     <button class="btn btn--primary" id="pubAct">Set v${version} active</button>`, true);
  const a=zq("#pubAct");
  if(a) a.onclick=()=> SRV.online ? activateOnServer() : (
    S.active[S.tpl.docType]=S.tpl.templateId,
    S.tpl.activeVersion=version, closeModal(), render(),
    toast("Active — every print point now resolves v"+version));
}

/**
 * Activate, on the server.
 *
 * The consequential one: activeVersion is the pointer every print point
 * resolves, so this is what decides which certificate a school legally issues.
 * The server runs it in a transaction — two concurrent activates must not each
 * see no incumbent and both win.
 */
async function activateOnServer(){
  const btn=zq("#pubAct"); if(btn) btn.disabled=true;
  try{
    const out=await srv.activate(S.tpl.templateId);
    const v=out.activeVersion || S.tpl.publishedVersion;
    S.active[S.tpl.docType]=S.tpl.templateId;
    S.tpl.activeVersion=v;
    if(out.lockVersion!=null) S.tpl.lockVersion=out.lockVersion;
    closeModal(); render();
    toast("Active — every print point now resolves v"+v);
  }catch(e){
    apiFail(e, "Activate");
  }finally{ if(btn) btn.disabled=false; }
}

/**
 * Version history — the REAL versions.
 *
 * This panel used to render HARDCODED rows ("v2 · active · sha256:9c41…a2f1")
 * for every template, whatever had actually been published. That is worse than
 * showing nothing: it is fabricated audit information in the one place a person
 * goes to ask "which template produced this certificate?".
 */
async function openHistory(){
  if(!SRV.online) return openHistoryOffline();

  modal("Version history","Loading…",`<p class="note">Reading the frozen versions…</p>`,
        `<button class="btn" data-close>Close</button>`, true);
  let data;
  try { data = await srv.versions(S.tpl.templateId); }
  catch(e){ closeModal(); return apiFail(e, "Version history"); }

  const rows=(data.versions||[]).map(v=>{
    if(v.missing){
      /* Reported, never skipped — a missing snapshot is exactly what makes a
         version unreproducible, and hiding it hides the only symptom. */
      return `<li><span class="tl__v">v${v.version}</span><span class="tl__m">
        <b style="color:var(--bad)">Snapshot missing</b>
        <span>Nothing can be reproduced from this version.</span></span></li>`;
    }
    const canRoll = SRV.can.manage && !v.active;
    const hash=(v.proofPdfHash||"");
    const langs=v.pdfLangs||[];
    /* SHOW IT BEFORE ASKING ANYONE TO MAKE IT LIVE. History could only
       activate a version; there was no way to look at one first, which is the
       wrong way round for a document a school is legally answerable for.
       The frozen PDF is already on disk — this just opens it. */
    const view = langs.map(l=>
      `<a class="btn btn--sm" target="_blank" rel="noopener"
          href="${SRV.base}/version_pdf?templateId=${encodeURIComponent(S.tpl.templateId)}&version=${v.version}&lang=${encodeURIComponent(l)}"
       >View${langs.length>1?" ("+esc(l.toUpperCase())+")":" PDF"}</a>`).join("");
    return `<li><span class="tl__v">v${v.version}</span><span class="tl__m">
      <b>Published${v.active?" · in use":""}</b>
      <span>${esc(relTime(v.publishedAt))}${v.publishedBy?" by "+esc(v.publishedBy):""}
        · mPDF ${esc(v.mpdfVersion||"?")}
        · ${(v.fontManifest||[]).length} fonts
        · <span class="mono" title="${esc(hash)}">${esc(hash.slice(0,19))}…</span></span>
      <span class="tl__acts">${view}${
        canRoll?`<button class="btn btn--sm" data-roll="${v.version}">Make v${v.version} active</button>`:""}</span>
    </span></li>`;
  }).join("");

  modal("Version history","Every published version is frozen forever",
    `<ul class="tl">
      <li><span class="tl__v">v${data.draftVersion}</span><span class="tl__m"><b>Draft — you are here</b>
        <span title="lockVersion ${S.tpl.lockVersion}">${S.dirty?"Unsaved changes":"All changes saved"} · not published yet</span></span></li>
      ${rows||`<li><span class="tl__m"><b>Nothing published yet</b>
        <span>Publish this draft to freeze its first version.</span></span></li>`}
    </ul>
    <p class="note" style="margin-bottom:0">This is the answer to <b>"show me the exact template that produced this certificate"</b>,
    asked three years later by somebody who is not you.</p>`,
    `<button class="btn" data-close>Close</button>`, true);

  document.querySelectorAll("[data-roll]").forEach(b=>{
    b.onclick=()=>confirmRollback(parseInt(b.dataset.roll,10));
  });
}

/**
 * Rolling back is a real activation, so it gets a real confirmation.
 *
 * It is not undoing a mistake quietly — it changes which certificate the school
 * legally issues, exactly as activating forward does, and it is logged as a
 * rollback so the question "what happened that morning?" has an answer.
 */
function confirmRollback(version){
  modal("Make v"+version+" active again?",
    "This is what every print point will resolve.",
    `<p class="note">v${version} is a frozen, already-proofed version — nothing is re-rendered
     and nothing is re-checked. The version that is active now stays published and can be
     activated again later.</p>
     <p class="note" style="margin-bottom:0">This is recorded as a <b>rollback</b> in the audit log,
     not as an ordinary activation.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="rollGo">Make v${version} active</button>`, true);

  const g=zq("#rollGo");
  if(g) g.onclick=async ()=>{
    g.disabled=true;
    try{
      const out=await srv.activate(S.tpl.templateId, version);
      S.tpl.activeVersion=out.activeVersion;
      if(out.lockVersion!=null) S.tpl.lockVersion=out.lockVersion;
      S.active[S.tpl.docType]=S.tpl.templateId;
      closeModal(); render();
      toast("Rolled back — every print point now resolves v"+out.activeVersion);
    }catch(e){ apiFail(e, "Rollback"); }
    finally{ g.disabled=false; }
  };
}

function openHistoryOffline(){
  modal("Version history","Every published version is frozen forever",
    `<ul class="tl">
      <li><span class="tl__v">v${S.tpl.version}</span><span class="tl__m"><b>Draft — you are here</b>
        <span>lockVersion ${S.tpl.lockVersion}${S.dirty?" · unsaved changes":""}</span></span></li>
      <li><span class="tl__v">v2</span><span class="tl__m"><b>Published · active</b>
        <span>04 Aug 2026 by Principal · sha256:9c41…a2f1 · mPDF 8.3.1 · lohitdeva + dejavusans</span></span></li>
      <li><span class="tl__v">v1</span><span class="tl__m"><b>Published</b>
        <span>12 Jul 2026 by Principal · superseded by v2</span></span></li>
    </ul>
    <p class="note" style="margin-bottom:0">This is the answer to <b>"show me the exact template that produced this certificate"</b>,
    asked three years later by somebody who is not you.</p>`,
    `<button class="btn" data-close>Close</button>
     <button class="btn" id="cmpBtn">Compare with active</button><span class="spacer"></span>
     <button class="btn" id="conflictDemo">Simulate a concurrent edit</button>`, true);
  zq("#conflictDemo").onclick=openConflict;
  zq("#cmpBtn").onclick=openCompare;
}
function openConflict(){
  modal("This template changed while you were editing","Someone else saved first",
    `<p style="margin-top:0;font-size:12.5px;line-height:1.6"><b>Priya (Office)</b> saved
      <span class="mono">lockVersion 18</span> two minutes ago. Your copy is on <span class="mono">17</span>.
      Saving now would silently erase their work, so it has been stopped.</p>
     <div class="gate">
       <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
         <span><b>Their changes</b><span>Signature block moved · school address edited</span></span></div>
       <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
         <span><b>Your changes</b><span>3 objects moved · reason-for-leaving anchored</span></span></div>
     </div>
     ${S.tpl.activeVersion!=null?`<p class="note" style="margin-bottom:0">This template has a <b>published, active version</b>, so overwriting is not offered — one of these two changes may be the one a Principal already approved. Review both and keep yours as a new draft instead. Nothing is lost either way.</p>`:""}`,
    (S.tpl.activeVersion!=null
      ? `<button class="btn" data-close>Keep editing</button><span class="spacer"></span>
         <button class="btn" data-close>Reload theirs</button>
         <button class="btn btn--primary" id="cflReview">Review both, then save as a new draft</button>`
      : `<button class="btn" data-close>Keep editing</button><span class="spacer"></span>
         <button class="btn" id="cflReview">Review changes</button>
         <button class="btn" data-close>Save mine over theirs</button>`), true);
  const rv=zq("#cflReview"); if(rv) rv.onclick=()=>{ closeModal(); openCompare(); };
}

/* ==========================================================================
   17 · BOOT
   ========================================================================== */
S.school=Object.assign({}, SCHOOL_DEFAULT);
S.tpl=starterTC();

/* NEVER SHOW THE FIXTURES TO A REAL SCHOOL.
   
   These constants exist so the offline harness has something to drive. Online
   they were painted first and replaced a few seconds later once the real
   templates arrived — so the screen opened showing "TC — main letterhead ·
   ACTIVE" and "2 templates · edited 2 days ago" for a school that has neither,
   and then quietly changed under the reader.
   
   That is worse than a slow screen. A slow screen makes you wait; this one
   answered your question wrongly and corrected itself only if you were still
   looking. Every read here costs ~2s from a dev machine, so the window was
   wide enough to act on.
   
   Online we start EMPTY and say we are loading. */
if(SRV.online){
  S.lib={}; S.active={}; S.loading=true;
  if(BOOT.docType) S.docType=BOOT.docType;   // land on the right screen immediately
  if(BOOT.templateId){
    /* Deep-linking into the designer shows the shell while the real template
       loads — and the shell is a FIXTURE. Left as-is it announced someone
       else's template by name and state ("TC — main letterhead · In use · v2")
       for the seconds the fetch takes. Blank the identity until we know it. */
    S.tpl.name=""; S.tpl.publishedVersion=null; S.tpl.activeVersion=null; S.tpl.version=1;
  }
}else{
  S.lib=JSON.parse(JSON.stringify(LIB));
  S.active=Object.assign({}, ACTIVE);
}
/* Land on the screen the URL asked for, immediately.
   /design/{id} used to show the hub for the several seconds the fetch takes and
   then jump — so the answer to "where is my template?" was a screen full of
   other things, followed by a lurch. */
paintHub();
go(!SRV.online ? "hub"
   : BOOT.templateId ? "designer"
   : BOOT.docType    ? "gallery"
   : "hub");

/**
 * Replace the built-in fixtures with this school's real templates.
 *
 * Runs AFTER the first paint so the page is never blank while the network
 * answers, and the fixtures above are only ever a first frame — if the load
 * fails, the library is emptied rather than left showing invented templates
 * that a clerk could try to activate. Showing someone else's demo data as
 * their own is worse than showing nothing.
 */
/**
 * Repaint whatever screen is showing.
 *
 * render() only draws the DESIGNER, so hydrating while the gallery was open
 * left it on "Loading your templates…" forever — the data had arrived and
 * nothing redrew it. go() already knows the per-screen painter; this is the
 * same mapping without the navigation side effects.
 */
function repaintScreen(){
  if(S.screen==="hub")     return paintHub();
  if(S.screen==="gallery") return paintGallery();
  render();
}

async function hydrateFromServer(){
  if(!SRV.online) return;
  if(BOOT.schoolName) S.school=Object.assign({}, S.school, {name:BOOT.schoolName});
  try{
    const out=await srv.templates("");
    const lib={}, active={};
    /* Tolerate both shapes. The endpoint normalises now, but a client that
       silently mis-parses a list as a map shows an empty library — which looks
       exactly like a school that has no templates. */
    const raw = out.templates || {};
    const entries = Array.isArray(raw)
      ? raw.map(r => [r.id || "", r.data || r])
      : Object.entries(raw);
    entries.forEach(([docId,t])=>{
      if(!docId || !t) return;
      const type=t.docType||"transfer_certificate";
      (lib[type]=lib[type]||[]).push({
        id:docId, name:t.name||docId, starter:t.starterId||null,
        status:t.status||"draft", version:t.version||1,
        publishedVersion:t.publishedVersion||null,
        /* activeVersion was dropped here, so a card could not tell WHICH
           version is live — it fell back to the newest published one and
           announced "version 4 is what every print point resolves" while v3 was
           actually printing. The one number the card exists to report. */
        activeVersion:t.activeVersion!=null?t.activeVersion:null,
        edited:t.updatedAt||"",
        editedBy:t.updatedBy||t.createdBy||""
      });
      if(t.activeVersion!=null) active[type]=docId;
    });
    S.lib=lib; S.active=active; S.loading=false;
    repaintScreen();
  }catch(e){
    S.lib={}; S.active={}; S.loading=false;
    repaintScreen();
    apiFail(e, "Loading your templates");
  }

  /* DEEP LINKS.
     /doc_templates/gallery/{type} and /design/{id} both used to land on the
     hub, because BOOT.screen and BOOT.docType were never read — go("hub") ran
     unconditionally. So a bookmark, a browser Back, or a link someone pasted
     always dropped you at the top and you had to navigate in again, which is
     most of what "I cannot find what I saved" feels like. */
  /* /doc_templates/design/{id} opens that template directly. */
  if(BOOT.templateId){
    try{
      const r=await srv.template(BOOT.templateId);
      if(r&&r.template){ adoptTemplate(r.template, BOOT.templateId); }
    }catch(e){ apiFail(e, "Opening the template"); }
  }
}

/** Take a stored template document and make it the one on screen. */
function adoptTemplate(t, docId){
  S.tpl=Object.assign(starterTC(), t);
  /* CRITICAL: the stored document carries `templateId` as the SHORT entity id
     ("TPL0001"), and the assign above has just overwritten the full document id
     with it. Every endpoint takes the full one, so leaving this would make every
     save, proof, publish and activate fail with "no template 'TPL0001'" — which
     is precisely what the first live run did. */
  S.tpl.templateId = t._id || docId || S.tpl.templateId;
  S.docType=t.docType||S.docType;
  S.lang=t.defaultLanguage||"en";
  S.sel=[]; S.undo=[]; S.redo=[]; S.dirty=false; S.tool="move";
  /* A stored proof only counts while it still describes THIS design — the
     server checks the same thing at publish time, by content and not by flag. */
  S.proofed=(t.lastProof&&t.lastProof.hash)?{hash:t.lastProof.hash, contentHash:t.lastProof.contentHash}:null;
  S.baseline=JSON.parse(JSON.stringify(t.objects||[]));
  go("designer");
}

hydrateFromServer();
