// migrate_rtdb_to_firestore.js — generic idempotent RTDB → Firestore walker.
//
// Copies subtrees from Realtime Database to Firestore collections using
// a configurable mapping. Idempotent: re-running with the same config
// is a no-op because targets use merge writes keyed on deterministic
// doc IDs. Each mapping produces ONE Firestore doc per RTDB leaf node.
//
// Firebase project + credentials come from the service account JSON
// already used everywhere else in the project.
//
// ──────────────────────────────────────────────────────────────────────
//  USAGE
// ──────────────────────────────────────────────────────────────────────
//   Dry-run (print planned writes only):
//     node scripts/migrate_rtdb_to_firestore.js --mapping=notifBadges --dry-run
//   Live migration:
//     node scripts/migrate_rtdb_to_firestore.js --mapping=notifBadges
//   Custom mapping from a JSON file:
//     node scripts/migrate_rtdb_to_firestore.js --config=./my-mapping.json
//
// ──────────────────────────────────────────────────────────────────────
//  MAPPING SHAPE
// ──────────────────────────────────────────────────────────────────────
//   {
//     name: 'notifBadges',
//     rtdbRoot: 'NotifBadge',            // RTDB subtree to walk
//     firestoreCollection: 'notifBadges', // target Firestore collection
//     // docIdTemplate uses ${key} for each path segment under rtdbRoot.
//     // "NotifBadge/{userId}" → docIdTemplate: '${userId}'
//     docIdTemplate: '${userId}',
//     // Leaf depth: how many path segments below rtdbRoot each doc is.
//     leafDepth: 1,
//     // Transform each leaf's value into the Firestore doc payload.
//     // Defaults to identity.
//     transform: (key, rtdbValue) => ({ userId: key, ...rtdbValue }),
//     // Optional source schoolId (some collections live per-school).
//     schoolId: '',
//   }
//
// Built-in mappings (invokable via --mapping=…) are listed at the bottom
// of this file and can be customised per environment.
//
// ──────────────────────────────────────────────────────────────────────

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({
  credential: admin.credential.cert(sa),
  databaseURL: process.env.RTDB_URL || 'https://graderadmin-default-rtdb.asia-southeast1.firebasedatabase.app',
});
const rtdb = admin.database();
const fs   = admin.firestore();

const BUILTIN_MAPPINGS = {
  // Each user's notification badge counts. Moves NotifBadge/{userId}
  // nodes to notifBadges/{userId} docs with schoolId tagging.
  notifBadges: {
    name: 'notifBadges',
    rtdbRoot: 'NotifBadge',
    firestoreCollection: 'notifBadges',
    docIdTemplate: '${userId}',
    leafDepth: 1,
    transform: (key, value) => ({
      userId: key,
      attendance:  Number(value?.attendance  || 0),
      homework:    Number(value?.homework    || 0),
      fees:        Number(value?.fees        || 0),
      circular:    Number(value?.circular    || 0),
      chat:        Number(value?.chat        || 0),
      exam:        Number(value?.exam        || 0),
      transport:   Number(value?.transport   || 0),
      general:     Number(value?.general     || 0),
      updatedAt:   value?.updatedAt || new Date().toISOString(),
    }),
  },

  // Per-user online presence. Presence/{userId} → presence/{userId}.
  presence: {
    name: 'presence',
    rtdbRoot: 'Presence',
    firestoreCollection: 'presence',
    docIdTemplate: '${userId}',
    leafDepth: 1,
    transform: (key, value) => ({
      userId:   key,
      online:   Boolean(value?.online),
      lastSeen: value?.lastSeen || new Date().toISOString(),
    }),
  },

  // Student flags (red-flag system). StudentFlags/{schoolCode}/{studentId}/{flagId}
  studentFlags: {
    name: 'studentFlags',
    rtdbRoot: 'StudentFlags',
    firestoreCollection: 'studentFlags',
    docIdTemplate: '${schoolCode}_${studentId}_${flagId}',
    leafDepth: 3,
    transform: (keys, value) => ({
      schoolId:  String(keys.schoolCode || ''),
      studentId: String(keys.studentId  || ''),
      flagId:    String(keys.flagId     || ''),
      ...value,
      updatedAt: value?.updatedAt || new Date().toISOString(),
    }),
  },

  // FCM device tokens. Users/Devices/{userId}/{deviceId}/ → userDevices/{userId_deviceId}
  userDevices: {
    name: 'userDevices',
    rtdbRoot: 'Users/Devices',
    firestoreCollection: 'userDevices',
    docIdTemplate: '${userId}_${deviceId}',
    leafDepth: 2,
    transform: (keys, value) => ({
      userId:    String(keys.userId   || ''),
      deviceId:  String(keys.deviceId || ''),
      fcmToken:  String(value?.fcmToken || ''),
      platform:  String(value?.platform || 'android'),
      appId:     String(value?.appId || ''),
      updatedAt: value?.updatedAt || new Date().toISOString(),
    }),
  },

  // OTP sessions. System/PasswordResets/{key} → otp_sessions/{key}
  // Doc id is the sanitised OTP key (adminId or STUDENT_ prefixed hash).
  otpSessions: {
    name: 'otpSessions',
    rtdbRoot: 'System/PasswordResets',
    firestoreCollection: 'otp_sessions',
    docIdTemplate: '${otpKey}',
    leafDepth: 1,
    transform: (key, value) => ({
      otpKey:           String(key || '').replace(/[^A-Za-z0-9_\-]/g, '_'),
      otpHash:          value?.otp_hash ?? value?.otpHash ?? null,
      email:            String(value?.email || ''),
      expiresAt:        Number(value?.expires_at ?? value?.expiresAt ?? 0),
      attempts:         Number(value?.attempts ?? 0),
      resetToken:       value?.reset_token ?? value?.resetToken ?? null,
      resetTokenExpiry: Number(value?.reset_token_expiry ?? value?.resetTokenExpiry ?? 0),
      createdAt:        value?.created_at ?? value?.createdAt ?? new Date().toISOString(),
      updatedAt:        new Date().toISOString(),
    }),
  },

  // ── Hostel module — Phase 5 ───────────────────────────────────────
  //   Schools/{sch}/Operations/Hostel/Buildings/{id} → hostelBuildings
  //   Schools/{sch}/Operations/Hostel/Rooms/{id}     → hostelRooms
  //   Schools/{sch}/Operations/Hostel/Allocations/{sid} → hostelAllocations
  //
  // These mappings require --rtdbRoot override at the CLI because the
  // RTDB path embeds the schoolId. Use:
  //   node migrate_rtdb_to_firestore.js --mapping=hostelBuildings \
  //        --rtdbRootOverride=Schools/SCH_D94FE8F7AD/Operations/Hostel/Buildings \
  //        --schoolId=SCH_D94FE8F7AD
  hostelBuildings: {
    name: 'hostelBuildings',
    rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Buildings', // placeholder
    firestoreCollection: 'hostelBuildings',
    docIdTemplate: '${schoolId}_${buildingId}',
    leafDepth: 1,
    transform: (keys, value) => ({
      schoolId:   process.env.MIG_SCHOOL_ID || String(keys.schoolId || ''),
      buildingId: String(Object.values(keys)[0] || ''),
      name:       String(value?.name || ''),
      type:       String(value?.type || 'mixed'),
      wardenId:   String(value?.warden_id   || value?.wardenId   || ''),
      wardenName: String(value?.warden_name || value?.wardenName || ''),
      floors:     Number(value?.floors || 1),
      address:    String(value?.address || ''),
      status:     String(value?.status || 'Active'),
      createdAt:  value?.created_at || value?.createdAt || new Date().toISOString(),
      updatedAt:  new Date().toISOString(),
    }),
  },

  hostelRooms: {
    name: 'hostelRooms',
    rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Rooms',
    firestoreCollection: 'hostelRooms',
    docIdTemplate: '${schoolId}_${roomId}',
    leafDepth: 1,
    transform: (keys, value) => ({
      schoolId:    process.env.MIG_SCHOOL_ID || '',
      roomId:      String(Object.values(keys)[0] || ''),
      building_id: String(value?.building_id || ''),
      floor:       Number(value?.floor || 0),
      room_no:     String(value?.room_no || ''),
      type:        String(value?.type || 'double'),
      beds:        Number(value?.beds || 0),
      occupied:    Number(value?.occupied || 0),
      monthly_fee: Number(value?.monthly_fee || 0),
      facilities:  String(value?.facilities || ''),
      status:      String(value?.status || 'Active'),
      createdAt:   value?.created_at || new Date().toISOString(),
      updatedAt:   new Date().toISOString(),
    }),
  },

  // ── Inventory — Phase 5 ──────────────────────────────────────────
  //   Schools/{sch}/Operations/Inventory/Items/{ITM…}          → inventory
  //   Schools/{sch}/Operations/Inventory/Categories/{ICAT…}    → inventoryCategories
  //   Schools/{sch}/Operations/Inventory/Vendors/{VND…}        → vendors
  //   Schools/{sch}/Operations/Inventory/Purchases/{PO…}       → purchaseOrders
  //   Schools/{sch}/Operations/Inventory/Issues/{ISI…}         → inventoryIssues
  // Pass --schoolId=SCH_… (per-school path).
  inventoryItems: {
    name: 'inventoryItems',
    rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Items',
    firestoreCollection: 'inventory',
    docIdTemplate: '${schoolId}_${itemId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      return {
        schoolId:      process.env.MIG_SCHOOL_ID || '',
        itemId:        String(Object.values(keys)[0] || ''),
        name:          String(value?.name || ''),
        category_id:   String(value?.category_id || ''),
        unit:          String(value?.unit || 'Pcs'),
        min_stock:     Number(value?.min_stock || 0),
        current_stock: Number(value?.current_stock || 0),
        location:      String(value?.location || ''),
        description:   String(value?.description || ''),
        status:        String(value?.status || 'Active'),
        createdAt:     value?.created_at || value?.createdAt || new Date().toISOString(),
        updatedAt:     new Date().toISOString(),
      };
    },
  },

  inventoryCategories: {
    name: 'inventoryCategories',
    rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Categories',
    firestoreCollection: 'inventoryCategories',
    docIdTemplate: '${schoolId}_${categoryId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      return {
        schoolId:    process.env.MIG_SCHOOL_ID || '',
        categoryId:  String(Object.values(keys)[0] || ''),
        name:        String(value?.name || ''),
        description: String(value?.description || ''),
        status:      String(value?.status || 'Active'),
        createdAt:   value?.created_at || new Date().toISOString(),
        updatedAt:   new Date().toISOString(),
      };
    },
  },

  vendors: {
    name: 'vendors',
    rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Vendors',
    firestoreCollection: 'vendors',
    docIdTemplate: '${schoolId}_${vendorId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      return {
        schoolId:  process.env.MIG_SCHOOL_ID || '',
        vendorId:  String(Object.values(keys)[0] || ''),
        name:      String(value?.name || ''),
        contact:   String(value?.contact || ''),
        email:     String(value?.email || ''),
        address:   String(value?.address || ''),
        gst:       String(value?.gst || ''),
        status:    String(value?.status || 'Active'),
        createdAt: value?.created_at || new Date().toISOString(),
        updatedAt: new Date().toISOString(),
      };
    },
  },

  purchaseOrders: {
    name: 'purchaseOrders',
    rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Purchases',
    firestoreCollection: 'purchaseOrders',
    docIdTemplate: '${schoolId}_${purchaseId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      return {
        schoolId:     process.env.MIG_SCHOOL_ID || '',
        purchaseId:   String(Object.values(keys)[0] || ''),
        item_id:      String(value?.item_id || ''),
        item_name:    String(value?.item_name || ''),
        vendor_id:    String(value?.vendor_id || ''),
        vendor_name:  String(value?.vendor_name || ''),
        qty:          Number(value?.qty || 0),
        unit_price:   Number(value?.unit_price || 0),
        total:        Number(value?.total || 0),
        date:         String(value?.date || ''),
        invoice_no:   String(value?.invoice_no || ''),
        payment_mode: String(value?.payment_mode || 'Cash'),
        journal_id:   String(value?.journal_id || ''),
        notes:        String(value?.notes || ''),
        status:       String(value?.status || 'Completed'),
        created_by:   String(value?.created_by || ''),
        createdAt:    value?.created_at || new Date().toISOString(),
        updatedAt:    new Date().toISOString(),
      };
    },
  },

  inventoryIssues: {
    name: 'inventoryIssues',
    rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Issues',
    firestoreCollection: 'inventoryIssues',
    docIdTemplate: '${schoolId}_${issueId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      return {
        schoolId:    process.env.MIG_SCHOOL_ID || '',
        issueId:     String(Object.values(keys)[0] || ''),
        item_id:     String(value?.item_id || ''),
        item_name:   String(value?.item_name || ''),
        issued_to:   String(value?.issued_to || ''),
        issued_by:   String(value?.issued_by || ''),
        qty:         Number(value?.qty || 0),
        purpose:     String(value?.purpose || ''),
        date:        String(value?.date || ''),
        return_date: String(value?.return_date || ''),
        return_qty:  Number(value?.return_qty || 0),
        status:      String(value?.status || 'Issued'),
        createdAt:   value?.created_at || new Date().toISOString(),
        updatedAt:   new Date().toISOString(),
      };
    },
  },

  // ── NoticeAnnouncement — Phase 5 ─────────────────────────────────
  //   Schools/{sch}/{session}/All Notices/{noticeId} → notices
  //   Pass --schoolId + --session to scope the read.
  //     node migrate_rtdb_to_firestore.js --mapping=notices \
  //          --schoolId=SCH_D94FE8F7AD --session=2026-27
  notices: {
    name: 'notices',
    rtdbRoot: 'Schools/__schoolId__/__session__/All Notices',
    firestoreCollection: 'notices',
    docIdTemplate: '${schoolId}_${noticeId}',
    leafDepth: 1,
    transform: (keys, value) => {
      // Ignore the 'Count' pseudo-child — it's a counter, not a notice.
      const id = String(Object.values(keys)[0] || '');
      if (id === 'Count' || typeof value !== 'object' || value === null) return null;
      const ts = value?.Timestamp ?? value?.Time_Stamp ?? value?.timestamp ?? null;
      return {
        schoolId:    process.env.MIG_SCHOOL_ID    || '',
        session:     process.env.MIG_SESSION_YEAR || '',
        noticeId:    id,
        title:       String(value?.Title       || value?.title       || ''),
        description: String(value?.Description || value?.description || ''),
        fromId:      String(value?.['From Id']  || value?.fromId || ''),
        fromType:    String(value?.['From Type']|| value?.fromType || 'Admin'),
        priority:    String(value?.Priority    || value?.priority || 'Normal'),
        category:    String(value?.Category    || value?.category || 'General'),
        toId:        (value?.['To Id'] && typeof value['To Id'] === 'object')
                       ? value['To Id'] : (value?.toId || {}),
        timestamp:   ts ? (typeof ts === 'number' ? new Date(ts).toISOString() : String(ts)) : '',
        timestampMs: typeof ts === 'number' ? ts : 0,
        createdAt:   ts ? (typeof ts === 'number' ? new Date(ts).toISOString() : String(ts)) : new Date().toISOString(),
      };
    },
  },

  hostelAllocations: {
    name: 'hostelAllocations',
    rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Allocations',
    firestoreCollection: 'hostelAllocations',
    docIdTemplate: '${schoolId}_${studentId}',
    leafDepth: 1,
    transform: (keys, value) => ({
      schoolId:      process.env.MIG_SCHOOL_ID || '',
      studentId:     String(Object.values(keys)[0] || ''),
      room_id:       String(value?.room_id || ''),
      room_no:       String(value?.room_no || ''),
      building_id:   String(value?.building_id || ''),
      building_name: String(value?.building_name || ''),
      bed_no:        Number(value?.bed_no || 1),
      student_name:  String(value?.student_name || ''),
      student_class: String(value?.student_class || ''),
      monthly_fee:   Number(value?.monthly_fee || 0),
      check_in:      String(value?.check_in || ''),
      check_out:     String(value?.check_out || ''),
      status:        String(value?.status || 'Active'),
      createdAt:     value?.created_at || new Date().toISOString(),
      updatedAt:     new Date().toISOString(),
    }),
  },

  // ── Transport — Phase 7 ──────────────────────────────────────────
  //   Schools/{sch}/Operations/Transport/Vehicles/{VH…}     → vehicles
  //   Schools/{sch}/Operations/Transport/Routes/{RT…}       → routes
  //   Schools/{sch}/Operations/Transport/Stops/{STP…}       → transportStops
  //   Schools/{sch}/Operations/Transport/Assignments/{sid}  → studentRoutes
  //   Pass --schoolId to scope each read.
  //     node migrate_rtdb_to_firestore.js --mapping=vehicles \
  //          --schoolId=SCH_D94FE8F7AD
  vehicles: {
    name: 'vehicles',
    rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Vehicles',
    firestoreCollection: 'vehicles',
    docIdTemplate: '${schoolId}_${vehicleId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      return {
        schoolId:         process.env.MIG_SCHOOL_ID || '',
        vehicleId:        id,
        number:           String(value?.number || ''),
        type:             String(value?.type || 'Bus'),
        capacity:         Number(value?.capacity || 0),
        driver_name:      String(value?.driver_name || ''),
        driver_phone:     String(value?.driver_phone || ''),
        staff_id:         String(value?.staff_id || ''),
        insurance_no:     String(value?.insurance_no || ''),
        insurance_expiry: String(value?.insurance_expiry || ''),
        fitness_expiry:   String(value?.fitness_expiry || ''),
        gps_enabled:      Boolean(value?.gps_enabled),
        status:           String(value?.status || 'Active'),
        createdAt:        value?.created_at || new Date().toISOString(),
        updatedAt:        new Date().toISOString(),
      };
    },
  },

  routes: {
    name: 'routes',
    rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Routes',
    firestoreCollection: 'routes',
    docIdTemplate: '${schoolId}_${routeId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      return {
        schoolId:    process.env.MIG_SCHOOL_ID || '',
        routeId:     id,
        name:        String(value?.name || ''),
        vehicle_id:  String(value?.vehicle_id || ''),
        start_point: String(value?.start_point || ''),
        end_point:   String(value?.end_point || ''),
        distance_km: Number(value?.distance_km || 0),
        monthly_fee: Number(value?.monthly_fee || 0),
        status:      String(value?.status || 'Active'),
        createdAt:   value?.created_at || new Date().toISOString(),
        updatedAt:   new Date().toISOString(),
      };
    },
  },

  transportStops: {
    name: 'transportStops',
    rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Stops',
    firestoreCollection: 'transportStops',
    docIdTemplate: '${schoolId}_${stopId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      return {
        schoolId:    process.env.MIG_SCHOOL_ID || '',
        stopId:      id,
        route_id:    String(value?.route_id || ''),
        name:        String(value?.name || ''),
        pickup_time: String(value?.pickup_time || ''),
        drop_time:   String(value?.drop_time || ''),
        order:       Number(value?.order || 0),
        status:      String(value?.status || 'Active'),
        createdAt:   value?.created_at || new Date().toISOString(),
        updatedAt:   new Date().toISOString(),
      };
    },
  },

  studentRoutes: {
    name: 'studentRoutes',
    rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Assignments',
    firestoreCollection: 'studentRoutes',
    docIdTemplate: '${schoolId}_${studentId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      return {
        schoolId:      process.env.MIG_SCHOOL_ID || '',
        studentId:     id,
        route_id:      String(value?.route_id || ''),
        route_name:    String(value?.route_name || ''),
        stop_id:       String(value?.stop_id || ''),
        stop_name:     String(value?.stop_name || ''),
        type:          String(value?.type || 'both'),
        student_name:  String(value?.student_name || ''),
        student_class: String(value?.student_class || ''),
        monthly_fee:   Number(value?.monthly_fee || 0),
        assigned_date: String(value?.assigned_date || ''),
        assigned_by:   String(value?.assigned_by || ''),
        status:        String(value?.status || 'Active'),
        createdAt:     value?.created_at || new Date().toISOString(),
        updatedAt:     new Date().toISOString(),
      };
    },
  },

  // ── Events — Phase 8 ─────────────────────────────────────────────
  //   Schools/{sch}/Events/List/{EVT…}                     → events
  //   Schools/{sch}/Events/Participants/{EVT…}/{pid}       → eventParticipants
  //     node migrate_rtdb_to_firestore.js --mapping=events \
  //          --schoolId=SCH_D94FE8F7AD
  events: {
    name: 'events',
    rtdbRoot: 'Schools/__schoolId__/Events/List',
    firestoreCollection: 'events',
    docIdTemplate: '${schoolId}_${eventId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      if (id === 'Counter' || id === '') return null;
      const start = String(value?.start_date || '');
      const end   = String(value?.end_date   || start);
      return {
        schoolId:         process.env.MIG_SCHOOL_ID || '',
        eventId:          id,
        title:            String(value?.title || ''),
        description:      String(value?.description || ''),
        category:         String(value?.category || 'event'),
        location:         String(value?.location || ''),
        start_date:       start,
        end_date:         end,
        startDate:        start,
        endDate:          end,
        organizer:        String(value?.organizer || ''),
        max_participants: Number(value?.max_participants || 0),
        status:           String(value?.status || 'scheduled'),
        mediaUrls:        Array.isArray(value?.mediaUrls) ? value.mediaUrls : [],
        created_at:       value?.created_at || new Date().toISOString(),
        createdAt:        value?.created_at || new Date().toISOString(),
        created_by:       String(value?.created_by || ''),
        createdBy:        String(value?.created_by || ''),
        created_by_name:  String(value?.created_by_name || ''),
        updated_at:       new Date().toISOString(),
      };
    },
  },

  eventParticipants: {
    name: 'eventParticipants',
    rtdbRoot: 'Schools/__schoolId__/Events/Participants',
    firestoreCollection: 'eventParticipants',
    docIdTemplate: '${schoolId}_${eventId}_${participantId}',
    leafDepth: 2,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const evtId = String(keys.eventId || '');
      const pid   = String(keys.participantId || '');
      if (evtId === '' || pid === '') return null;
      return {
        schoolId:          process.env.MIG_SCHOOL_ID || '',
        eventId:           evtId,
        participantId:     pid,
        participant_id:    pid,
        participant_type:  String(value?.participant_type || 'student'),
        name:              String(value?.name || ''),
        class:             String(value?.class || ''),
        section:           String(value?.section || ''),
        status:            String(value?.status || 'registered'),
        registered_by:     String(value?.registered_by || ''),
        registration_date: String(value?.registration_date || ''),
        updated_at:        new Date().toISOString(),
      };
    },
  },

  // ── Library — Phase 9 ────────────────────────────────────────────
  //   Schools/{sch}/Operations/Library/Books/{BK…}       → libraryBooks
  //   Schools/{sch}/Operations/Library/Categories/{CAT…} → bookCategories
  //   Schools/{sch}/Operations/Library/Issues/{ISS…}     → libraryIssues
  //   Schools/{sch}/Operations/Library/Fines/{FN…}       → libraryFines
  //     node migrate_rtdb_to_firestore.js --mapping=libraryBooks \
  //          --schoolId=SCH_D94FE8F7AD
  libraryBooks: {
    name: 'libraryBooks',
    rtdbRoot: 'Schools/__schoolId__/Operations/Library/Books',
    firestoreCollection: 'libraryBooks',
    docIdTemplate: '${schoolId}_${bookId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      if (id === '') return null;
      const copies = Number(value?.copies || 0);
      const avail  = Number(value?.available || 0);
      const title  = String(value?.title || '');
      const author = String(value?.author || '');
      return {
        schoolId:        process.env.MIG_SCHOOL_ID || '',
        bookId:          id,
        title:           title,
        searchTitle:     title.toLowerCase(),
        author:          author,
        authors:         author ? [author] : [],
        isbn:            String(value?.isbn || ''),
        category:        String(value?.category_id || ''),
        category_id:     String(value?.category_id || ''),
        publisher:       String(value?.publisher || ''),
        edition:         String(value?.edition || ''),
        copies:          copies,
        available:       avail,
        totalCopies:     copies,
        availableCopies: avail,
        location:        String(value?.shelf_location || ''),
        shelf_location:  String(value?.shelf_location || ''),
        description:     String(value?.description || ''),
        status:          String(value?.status || 'Active'),
        created_at:      value?.created_at || new Date().toISOString(),
        createdAt:       value?.created_at || new Date().toISOString(),
        updated_at:      new Date().toISOString(),
        updatedAt:       new Date().toISOString(),
      };
    },
  },

  bookCategories: {
    name: 'bookCategories',
    rtdbRoot: 'Schools/__schoolId__/Operations/Library/Categories',
    firestoreCollection: 'bookCategories',
    docIdTemplate: '${schoolId}_${categoryId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      if (id === '') return null;
      return {
        schoolId:    process.env.MIG_SCHOOL_ID || '',
        categoryId:  id,
        name:        String(value?.name || ''),
        description: String(value?.description || ''),
        status:      String(value?.status || 'Active'),
        created_at:  value?.created_at || new Date().toISOString(),
        updated_at:  new Date().toISOString(),
      };
    },
  },

  libraryIssues: {
    name: 'libraryIssues',
    rtdbRoot: 'Schools/__schoolId__/Operations/Library/Issues',
    firestoreCollection: 'libraryIssues',
    docIdTemplate: '${schoolId}_${issueId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      if (id === '') return null;
      const status = String(value?.status || 'Issued').toLowerCase();
      return {
        schoolId:     process.env.MIG_SCHOOL_ID || '',
        issueId:      id,
        book_id:      String(value?.book_id || ''),
        bookId:       String(value?.book_id || ''),
        book_title:   String(value?.book_title || ''),
        bookTitle:    String(value?.book_title || ''),
        student_id:   String(value?.student_id || ''),
        borrowerId:   String(value?.student_id || ''),
        student_name: String(value?.student_name || ''),
        borrowerName: String(value?.student_name || ''),
        borrowerType: 'student',
        issue_date:   String(value?.issue_date || ''),
        issueDate:    String(value?.issue_date || ''),
        due_date:     String(value?.due_date || ''),
        dueDate:      String(value?.due_date || ''),
        return_date:  String(value?.return_date || ''),
        returnDate:   String(value?.return_date || ''),
        renewals:     Number(value?.renewals || 0),
        maxRenewals:  Number(value?.maxRenewals || 2),
        fine_amount:  Number(value?.fine_amount || 0),
        fine:         Number(value?.fine_amount || 0),
        late_days:    Number(value?.late_days || 0),
        status:       status,
        issued_by:    String(value?.issued_by || ''),
        issuedBy:     String(value?.issued_by || ''),
        returned_by:  String(value?.returned_by || ''),
        returnedTo:   String(value?.returned_by || ''),
        created_at:   value?.created_at || new Date().toISOString(),
        createdAt:    value?.created_at || new Date().toISOString(),
        updated_at:   new Date().toISOString(),
      };
    },
  },

  libraryFines: {
    name: 'libraryFines',
    rtdbRoot: 'Schools/__schoolId__/Operations/Library/Fines',
    firestoreCollection: 'libraryFines',
    docIdTemplate: '${schoolId}_${fineId}',
    leafDepth: 1,
    transform: (keys, value) => {
      if (!value || typeof value !== 'object') return null;
      const id = String(Object.values(keys)[0] || '');
      if (id === '') return null;
      const status = String(value?.status || 'Pending').toLowerCase();
      const amount = Number(value?.amount || 0);
      return {
        schoolId:     process.env.MIG_SCHOOL_ID || '',
        fineId:       id,
        issue_id:     String(value?.issue_id || ''),
        issueId:      String(value?.issue_id || ''),
        bookId:       String(value?.book_id || ''),
        book_title:   String(value?.book_title || ''),
        bookTitle:    String(value?.book_title || ''),
        student_id:   String(value?.student_id || ''),
        borrowerId:   String(value?.student_id || ''),
        student_name: String(value?.student_name || ''),
        borrowerName: String(value?.student_name || ''),
        late_days:    Number(value?.late_days || 0),
        amount:       amount,
        fineAmount:   amount,
        reason:       'overdue',
        paid:         Boolean(value?.paid),
        journal_id:   String(value?.journal_id || ''),
        payment_mode: String(value?.payment_mode || ''),
        status:       status,
        paid_at:      String(value?.paid_at || ''),
        paidAt:       String(value?.paid_at || ''),
        paid_by:      String(value?.paid_by || ''),
        created_at:   value?.created_at || new Date().toISOString(),
        createdAt:    value?.created_at || new Date().toISOString(),
        updated_at:   new Date().toISOString(),
      };
    },
  },
};

function parseArgs(argv) {
  const opts = {};
  for (const a of argv.slice(2)) {
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq === -1) opts[a.slice(2)] = true;
    else opts[a.slice(2, eq)] = a.slice(eq + 1);
  }
  return opts;
}

function resolveMapping(opts) {
  if (opts.config) return require(path.resolve(opts.config));
  const name = (opts.mapping || '').trim();
  if (!name || !BUILTIN_MAPPINGS[name]) {
    console.error(`--mapping required. One of: ${Object.keys(BUILTIN_MAPPINGS).join(', ')}`);
    process.exit(2);
  }
  const mapping = Object.assign({}, BUILTIN_MAPPINGS[name]);

  // Per-school mappings use __schoolId__ and/or __session__ placeholders
  // in rtdbRoot. Resolve from CLI opts or a single --rtdbRootOverride
  // that hard-codes the full path.
  if (opts.rtdbRootOverride) {
    mapping.rtdbRoot = String(opts.rtdbRootOverride);
  } else {
    if (opts.schoolId) mapping.rtdbRoot = mapping.rtdbRoot.replace('__schoolId__', String(opts.schoolId));
    if (opts.session)  mapping.rtdbRoot = mapping.rtdbRoot.replace('__session__',  String(opts.session));
  }
  // Surface schoolId/session to transforms so they can tag docs consistently.
  if (opts.schoolId) process.env.MIG_SCHOOL_ID    = String(opts.schoolId);
  if (opts.session)  process.env.MIG_SESSION_YEAR = String(opts.session);
  return mapping;
}

function resolveDocId(template, keys) {
  return template.replace(/\$\{([A-Za-z0-9_]+)\}/g, (_, k) => String(keys[k] ?? ''));
}

/**
 * Walk an RTDB subtree to the configured leaf depth, yielding
 * { keys, value } for each leaf. Depth-1 leaves: `NotifBadge/{userId}`
 * → yields { userId, value } for every userId child.
 */
async function *walkTree(rootRef, rootName, leafDepth) {
  const snap = await rootRef.once('value');
  if (!snap.exists()) return;
  function *descend(node, depth, keys) {
    if (depth === 0) {
      yield { keys, value: node };
      return;
    }
    if (!node || typeof node !== 'object') return;
    for (const k of Object.keys(node)) {
      // Convention: the top-level key name from the template (first seen
      // at each depth) — we don't actually know the semantic names up
      // front, so walkTree passes positional placeholders by depth:
      //   depth 1: { segment1: <value> }
      //   depth 2: { segment1, segment2 }
      //   etc.
      const newKeys = { ...keys, [`segment${Object.keys(keys).length + 1}`]: k };
      yield *descend(node[k], depth - 1, newKeys);
    }
  }
  yield *descend(snap.val(), leafDepth, {});
}

/**
 * Translate positional segment keys (segment1, segment2, …) into the
 * named keys the mapping's docIdTemplate + transform expect. The
 * mapping declares names in its docIdTemplate; we extract them.
 */
function extractNamedKeys(template, positional) {
  const names = [...template.matchAll(/\$\{([A-Za-z0-9_]+)\}/g)].map(m => m[1]);
  const out = {};
  names.forEach((name, i) => { out[name] = positional[`segment${i + 1}`] ?? ''; });
  return out;
}

(async () => {
  const opts = parseArgs(process.argv);
  const dryRun = !!opts['dry-run'];
  const mapping = resolveMapping(opts);

  console.log(`[${mapping.name}] migrating ${mapping.rtdbRoot} → ${mapping.firestoreCollection} (dryRun=${dryRun})`);

  let scanned = 0, written = 0, skipped = 0;
  let batch = fs.batch();
  let batchOps = 0;

  const rootRef = rtdb.ref(mapping.rtdbRoot);
  for await (const { keys: positional, value } of walkTree(rootRef, mapping.rtdbRoot, mapping.leafDepth || 1)) {
    scanned++;
    const named = extractNamedKeys(mapping.docIdTemplate, positional);
    const payload = (mapping.transform)
      ? mapping.transform(mapping.leafDepth > 1 ? named : Object.values(named)[0] || '', value)
      : (typeof value === 'object' && value ? value : { value });

    // Transforms may return null to mean "skip this leaf" (e.g. the
    // Count pseudo-child under All Notices isn't a real record).
    if (payload === null || payload === undefined) { skipped++; continue; }

    // If the transform supplied its own noticeId/buildingId etc, prefer
    // those over whatever the positional walker extracted.
    const mergedForId = { ...named, ...payload };
    const docId = resolveDocId(mapping.docIdTemplate, mergedForId);
    if (!docId || docId.endsWith('_') || docId.startsWith('_')) { skipped++; continue; }

    console.log(`  ${mapping.firestoreCollection}/${docId}  ←  ${mapping.rtdbRoot}/${Object.values(positional).join('/')}`);

    if (!dryRun) {
      batch.set(fs.collection(mapping.firestoreCollection).doc(docId), {
        ...payload,
        _migratedFrom: `rtdb://${mapping.rtdbRoot}/${Object.values(positional).join('/')}`,
        _migratedAt:   new Date().toISOString(),
      }, { merge: true });
      batchOps++;
      written++;
      if (batchOps >= 400) { await batch.commit(); batch = fs.batch(); batchOps = 0; }
    }
  }
  if (!dryRun && batchOps > 0) await batch.commit();

  console.log(`\n── ${mapping.name} ─────────────────────────────`);
  console.log(`scanned:   ${scanned}`);
  console.log(`written:   ${written}${dryRun ? ' (dry-run — no writes)' : ''}`);
  console.log(`skipped:   ${skipped}`);
  console.log(`──────────────────────────────────────────────`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
