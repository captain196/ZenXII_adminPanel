// Shared blog post data — used by both blog.html (list) and blog-post.html (detail).
const POSTS = {
  "multi-school-rollout": {
    title: "Rolling out one platform across a school federation",
    category: "Operations",
    readMin: 6,
    author: "Yugant Verma",
    date: "2026-05-12",
    cover: "https://images.unsplash.com/photo-1492541737557-dfd592c6e42d",
    excerpt: "What we learned helping a 7-campus group consolidate onto a single ZenXii tenant — and the surprises along the way.",
    body: [
      { type: "p", text: "Consolidating seven independently-run campuses onto a single platform is less a software problem and more a change-management problem. The software part is solved when the schema is right and the data migrates cleanly. The hard part is everything around that — staff habits, parents who remember the old phone number, principals who run their school differently from the principal next door." },
      { type: "h2", text: "Start with the office, not the classroom" },
      { type: "p", text: "Every successful rollout we've watched starts with the admin panel. Once the school office trusts the platform — once the fee desk reconciles to the rupee and the attendance report matches the register — every other adoption decision gets easier. Teachers and parents follow the office's confidence." },
      { type: "h2", text: "One tenant, three apps, one truth" },
      { type: "p", text: "The biggest unlock for a federation isn't \"all campuses on the same product.\" It's \"all campuses on the same data model.\" The superadmin layer becomes the place where group-level reporting actually works — because there is no longer a campus-by-campus reconciliation step." },
      { type: "h2", text: "Where it got hard" },
      { type: "p", text: "Two surprises: (1) parent phone numbers were duplicated across siblings in different campuses, which broke the sibling-discovery flow until we added a federation-aware merge; (2) one campus had custom report-card templates baked into a print job nobody owned — we recreated it as a config, not a one-off." },
      { type: "h2", text: "What we'd do differently" },
      { type: "p", text: "Communicate the cut-over date the way you'd communicate a board exam date. The technical migration takes a weekend. The mental migration takes the term." }
    ]
  },
  "parent-comms-that-land": {
    title: "Parent communication that actually lands",
    category: "Communication",
    readMin: 4,
    author: "Yugant Verma",
    date: "2026-04-28",
    cover: "https://images.unsplash.com/photo-1542626991-cbc4e32524cc",
    excerpt: "Most school messages get half-read. A few small structural choices change that — without changing the message itself.",
    body: [
      { type: "p", text: "Schools send a lot of messages — and parents read very few of them. That's not a content problem. It's a structure problem." },
      { type: "h2", text: "One channel beats four" },
      { type: "p", text: "A school running on WhatsApp groups, SMS, paper diary slips and email is sending the same message four times in four formats. Parents stop reading any of them. Pick one canonical channel and use the rest for nudges." },
      { type: "h2", text: "Make the subject do the work" },
      { type: "p", text: "\"PTM\" is not a subject. \"PTM on 14 May, 11:00 AM, Class 5A\" is. The subject of a school message should make the message redundant for parents who only have time to glance." },
      { type: "h2", text: "Acknowledge, don't ask" },
      { type: "p", text: "If a message needs a reply, ask the smallest possible reply: a tap. Reply rates collapse the moment you ask parents to write a sentence." }
    ]
  },
  "hierarchical-password-reset": {
    title: "Why ZenXii doesn't do self-service OTP password reset",
    category: "Security",
    readMin: 5,
    author: "Yugant Verma",
    date: "2026-04-15",
    cover: "https://images.unsplash.com/photo-1680992046626-418f7e910589",
    excerpt: "Hierarchical reset keeps recovery in the school's trusted chain — and removes a whole class of phishing risk.",
    body: [
      { type: "p", text: "When we sat down to design password recovery for ZenXii, the obvious answer was an OTP to the parent's registered phone or email. We didn't ship that. Here's why." },
      { type: "h2", text: "The OTP threat model is bad for schools" },
      { type: "p", text: "OTP-based recovery only works if you trust the destination (phone/email). In a school context, both are often shared or out-of-date. The teacher's number ends up registered against the parent. The grandparent's number is on file. Self-service OTP, in this environment, is functionally an open door." },
      { type: "h2", text: "Hierarchical reset, instead" },
      { type: "p", text: "In ZenXii, a higher-privileged user resets a lower-privileged one. The principal resets a teacher. The teacher resets a student. The super admin resets the principal. Recovery stays inside the school's trusted chain — the same chain that decides who can mark attendance, change a grade, or sign a transfer certificate." },
      { type: "h2", text: "What this costs" },
      { type: "p", text: "It costs convenience. A parent can't reset their own password at 11pm. They have to message the school office the next morning. That is the price of removing the phishing vector — and almost every school we've talked to is happy to pay it." }
    ]
  }
};
