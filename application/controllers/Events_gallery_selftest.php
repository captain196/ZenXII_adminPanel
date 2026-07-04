<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Events_gallery_selftest — CLI integration harness that exercises the REAL
 * admin runtime code paths (Entity_firestore_sync gallery dual-write) against
 * the live graderadmin Firestore under the SCH_TEST tenant, then reads back the
 * EXACT queries the Teacher/Parent apps run, asserting the whole Events +
 * Gallery + event<->gallery contract end-to-end. Self-cleans.
 *
 * Run:  php index.php events_gallery_selftest run
 *       php index.php events_gallery_selftest cleanup   # just remove test docs
 *
 * SAFE: all writes are under schoolId = SCH_TEST with SELFTEST-prefixed ids;
 * every run deletes prior test docs first and again at the end.
 */
class Events_gallery_selftest extends CI_Controller
{
    const SID   = 'SCH_TEST';
    const EVT   = 'EVT_SELFTEST';
    const IMG   = 'IMG_SELFTEST1';
    const VID   = 'VID_SELFTEST1';
    const STU1  = 'STU_SELFTEST_A';
    const STU2  = 'STU_SELFTEST_Z';

    private $pass = 0, $fail = 0;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI only.', 403); exit(1); }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('entity_firestore_sync', null, 'entity_sync');
    }

    // ---- helpers -----------------------------------------------------------
    private function out($s){ fwrite(STDOUT, $s."\n"); }
    private function ok($cond, $label){
        if ($cond) { $this->pass++; $this->out("  [PASS] $label"); }
        else       { $this->fail++; $this->out("  [FAIL] $label"); }
        return (bool)$cond;
    }
    /** Normalize a firestoreQuery row (['id'=>,'data'=>] or flat) to its data map. */
    private function rowData($row){ return isset($row['data']) && is_array($row['data']) ? $row['data'] : (is_array($row) ? $row : []); }
    private function rowId($row){ return $row['id'] ?? null; }

    public function run()
    {
        $this->out(str_repeat('=',72));
        $this->out("EVENTS + GALLERY SELF-TEST  (tenant ".self::SID.", live graderadmin)");
        $this->out(str_repeat('=',72));

        $this->cleanupDocs();               // clean slate
        $this->entity_sync->init($this->firebase, self::SID, '2026-2027', 'selftest');

        $this->phase1_events();
        $this->phase2_link();
        $this->phase3_video();
        $this->phase4_participants_index();
        $this->phase5_cascade();

        $this->out(str_repeat('-',72));
        $this->cleanupDocs();               // final cleanup
        $this->out(str_repeat('=',72));
        $this->out(sprintf("RESULT:  %d passed,  %d failed", $this->pass, $this->fail));
        $this->out(str_repeat('=',72));
    }

    public function cleanup(){ $this->cleanupDocs(); $this->out("cleanup done."); }

    // ==== persistent device-test seeding (real school, real image URL) ======
    const SEED_EVT = 'EVT_DEVICETEST';
    const SEED_IMG = 'IMG_DEVICETEST';

    /** Find a real https Firebase Storage image URL already owned by $sid. */
    private function findRealImageUrl(string $sid): string
    {
        // 1) existing gallery media, 2) stories, 3) school logo.
        foreach ([['galleryMedia', 'url'], ['stories', 'mediaUrl'], ['stories', 'url']] as $probe) {
            $rows = $this->firebase->firestoreQuery($probe[0], [['schoolId','==',$sid]], null, 'ASC', 20);
            foreach ((array)$rows as $r){
                $d = $this->rowData($r);
                $u = (string)($d[$probe[1]] ?? '');
                if (strpos($u, 'firebasestorage.googleapis.com') !== false) return $u;
            }
        }
        $school = $this->firebase->firestoreGet('schools', $sid);
        foreach (['logo','logoUrl','schoolLogo'] as $k){
            $u = (string)($school[$k] ?? '');
            if (strpos($u, 'firebasestorage.googleapis.com') !== false) return $u;
        }
        return '';
    }

    public function seed($sid = 'SCH_B56BB9A401')
    {
        $url = $this->findRealImageUrl($sid);
        if ($url === '') { $this->out("No real Firebase Storage image URL found for {$sid}; app would show a broken tile. Aborting seed."); return; }
        $this->entity_sync->init($this->firebase, $sid, '2026-2027', 'devicetest');
        $now = date('c');
        $this->firebase->firestoreSet('events', "{$sid}_".self::SEED_EVT, [
            'schoolId'=>$sid, 'title'=>'DEMO Event — event↔gallery test', 'description'=>'Temporary test event (safe to delete).',
            'category'=>'general', 'location'=>'Test', 'organizer'=>'QA Harness',
            'start_date'=>'2026-08-20','startDate'=>'2026-08-20','end_date'=>'2026-08-20','endDate'=>'2026-08-20',
            'status'=>'scheduled','mediaUrls'=>[$url],'created_by'=>'devicetest','createdBy'=>'devicetest','created_at'=>$now,'createdAt'=>$now,
        ], false);
        $this->entity_sync->syncGalleryAlbum(self::SEED_EVT, [
            'title'=>'DEMO Event — event↔gallery test','category'=>'event','source'=>'event','eventId'=>self::SEED_EVT,
            'createdBy'=>'devicetest','createdAt'=>$now,'isArchived'=>false,'coverImage'=>$url,
        ]);
        $this->entity_sync->syncGalleryMedia(self::SEED_EVT, self::SEED_IMG, [
            'url'=>$url,'type'=>'image','caption'=>'device test image','isArchived'=>false,'uploadedBy'=>'devicetest','uploadedAt'=>$now,
        ]);
        $this->firebase->firestoreSet('galleryAlbums', "{$sid}_".self::SEED_EVT, ['mediaCount'=>1], true);
        $this->out("SEEDED for {$sid}: event '".self::SEED_EVT."' + event-album + 1 image.");
        $this->out("image URL: {$url}");
    }

    /** Resolve a school id from a name substring (case-insensitive). */
    private function resolveSchool(string $needle)
    {
        $rows = $this->firebase->firestoreQuery('schools', []);
        $hits = [];
        foreach ((array)$rows as $r){
            $id = $this->rowId($r); $d = $this->rowData($r);
            $name = (string)($d['name'] ?? $d['schoolName'] ?? $d['school_name'] ?? '');
            if ($id && stripos($name, $needle) !== false && strpos((string)$id, '_profile') === false){
                $hits[] = ['id'=>$id, 'name'=>$name];
            }
        }
        return $hits;
    }

    /** Read-only: list a school's events, flag duplicate groups. */
    public function events_audit($needle = 'Vikrant')
    {
        $hits = $this->resolveSchool($needle);
        if (empty($hits)) { $this->out("No school matching '{$needle}'."); return; }
        foreach ($hits as $h){
            $sid = $h['id'];
            $this->out("\nSchool: {$h['name']}  ({$sid})");
            $rows = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
            $events = [];
            foreach ((array)$rows as $r){
                $d = $this->rowData($r);
                $events[] = [
                    'docId'   => $this->rowId($r),
                    'title'   => (string)($d['title'] ?? ''),
                    'start'   => (string)($d['startDate'] ?? $d['start_date'] ?? ''),
                    'created' => (string)($d['createdAt'] ?? $d['created_at'] ?? ''),
                ];
            }
            $this->out("  total events: ".count($events));
            // Group by title|start to find dupes.
            $groups = [];
            foreach ($events as $e){ $groups[$e['title'].'|'.$e['start']][] = $e; }
            foreach ($groups as $key => $g){
                if (count($g) > 1){
                    $this->out("  ** DUPLICATE x".count($g)." — \"".$g[0]['title']."\" (".$g[0]['start'].")");
                    // sort by created asc; keep first
                    usort($g, function($a,$b){ return strcmp($a['created'],$b['created']); });
                    foreach ($g as $i => $e){
                        $tag = $i===0 ? 'KEEP ' : 'DUP  ';
                        $this->out("      [{$tag}] {$e['docId']}   created={$e['created']}");
                    }
                }
            }
        }
    }

    /** Delete duplicate events (keep earliest createdAt per title|startDate). DRY-RUN unless 'commit'. */
    public function events_dedupe($needle = 'Vikrant', $commit = '')
    {
        $go = ($commit === 'commit');
        $hits = $this->resolveSchool($needle);
        if (empty($hits)) { $this->out("No school matching '{$needle}'."); return; }
        $this->out("MODE: ".($go?'COMMIT (deleting)':'DRY-RUN'));
        foreach ($hits as $h){
            $sid = $h['id'];
            $rows = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
            $events = [];
            foreach ((array)$rows as $r){
                $d = $this->rowData($r);
                $events[] = ['docId'=>$this->rowId($r),'title'=>(string)($d['title']??''),'start'=>(string)($d['startDate']??$d['start_date']??''),'created'=>(string)($d['createdAt']??$d['created_at']??'')];
            }
            $groups = [];
            foreach ($events as $e){ $groups[$e['title'].'|'.$e['start']][] = $e; }
            $removed = 0;
            foreach ($groups as $g){
                if (count($g) <= 1) continue;
                usort($g, function($a,$b){ return strcmp($a['created'],$b['created']); });
                for ($i=1; $i<count($g); $i++){
                    $docId = $g[$i]['docId'];
                    // also clean this dup's participants + gallery album/media (same cascade)
                    if ($go){
                        $this->firebase->firestoreDelete('events', $docId);
                        // dup event id = docId without "{sid}_" prefix
                        $evtId = preg_replace('/^'.preg_quote($sid.'_','/').'/', '', $docId);
                        $this->firebase->firestoreDelete('galleryAlbums', "{$sid}_{$evtId}");
                        $parts = $this->firebase->firestoreQuery('eventParticipants', [['schoolId','==',$sid],['eventId','==',$evtId]]);
                        foreach ((array)$parts as $p){ if($this->rowId($p)) $this->firebase->firestoreDelete('eventParticipants', $this->rowId($p)); }
                        // cascade linked notices (source=event, eventRef==evtId)
                        $nts = $this->firebase->firestoreQuery('notices', [['schoolId','==',$sid],['eventRef','==',$evtId]]);
                        foreach ((array)$nts as $n){ if($this->rowId($n)) $this->firebase->firestoreDelete('notices', $this->rowId($n)); }
                        $this->out("  deleted dup {$docId} (+ notices)");
                    } else {
                        $this->out("  would delete dup {$docId}  (\"{$g[$i]['title']}\")");
                    }
                    $removed++;
                }
            }
            $this->out("  {$h['name']}: ".($go?"deleted":"would delete")." {$removed} duplicate event(s).");
        }
    }

    public function probe_events($sid = 'SCH_B56BB9A401')
    {
        $rows = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]], 'startDate', 'DESC');
        $this->out("row count: ".count((array)$rows));
        $first = ((array)$rows)[0] ?? null;
        $this->out("first row TOP-LEVEL keys: ".json_encode(array_keys((array)$first)));
        if (isset($first['data'])) $this->out("first row DATA keys: ".json_encode(array_keys((array)$first['data'])));
        $this->out("title at top: ".json_encode($first['title'] ?? '(none)'));
        $this->out("title under data: ".json_encode($first['data']['title'] ?? '(none)'));
    }

    public function probe_getevents($sid = 'SCH_B56BB9A401')
    {
        // Simulate the FIXED get_events transform: unwrap data + raw id.
        $rows = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]], 'startDate', 'DESC');
        $this->out("Events after fix (id | title | category | status | start):");
        foreach ((array)$rows as $doc){
            $e = $this->rowData($doc);                                  // == _row unwrap
            $full = (string)($this->rowId($doc) ?? '');
            $raw  = (strpos($full, $sid.'_')===0) ? substr($full, strlen($sid)+1) : $full;
            $this->out(sprintf("  %s | %s | %s | %s | %s",
                $raw, ($e['title']??'(blank)'), ($e['category']??'?'), ($e['status']??'?'),
                ($e['start_date']??$e['startDate']??'?')));
        }
    }

    public function notices_audit($sid = 'SCH_B56BB9A401')
    {
        $rows = $this->firebase->firestoreQuery('notices', [['schoolId','==',$sid]]);
        $this->out("notices for {$sid}: ".count((array)$rows));
        // current live event ids (raw)
        $ev = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
        $liveEvt = [];
        foreach((array)$ev as $r){ $full=(string)($this->rowId($r)??''); $liveEvt[] = (strpos($full,$sid.'_')===0)?substr($full,strlen($sid)+1):$full; }
        $this->out("live events (raw ids): ".json_encode($liveEvt));
        $this->out(str_repeat('-',60));
        foreach ((array)$rows as $r){
            $d = $this->rowData($r); $docId = (string)($this->rowId($r)??'');
            $src = (string)($d['source'] ?? '');
            $ref = (string)($d['eventRef'] ?? $d['eventId'] ?? '');
            $title = (string)($d['title'] ?? $d['heading'] ?? '');
            $orphan = ($src==='event' && $ref!=='' && !in_array($ref,$liveEvt,true)) ? '  <== ORPHAN (event deleted)' : '';
            $this->out(sprintf("  %s | src=%s | ref=%s | \"%s\"%s", $docId, $src, $ref, mb_substr($title,0,40), $orphan));
        }
    }

    /** Delete notices whose source=event and eventRef points to a now-deleted event. */
    public function notices_dedupe($sid = 'SCH_B56BB9A401', $commit = '')
    {
        $go = ($commit === 'commit');
        $ev = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
        $liveEvt = [];
        foreach((array)$ev as $r){ $full=(string)($this->rowId($r)??''); $liveEvt[] = (strpos($full,$sid.'_')===0)?substr($full,strlen($sid)+1):$full; }
        $rows = $this->firebase->firestoreQuery('notices', [['schoolId','==',$sid]]);
        $this->out("MODE: ".($go?'COMMIT':'DRY-RUN'));
        $n=0;
        foreach ((array)$rows as $r){
            $d = $this->rowData($r); $docId = (string)($this->rowId($r)??'');
            $src = (string)($d['source'] ?? '');
            $ref = (string)($d['eventRef'] ?? $d['eventId'] ?? '');
            if ($src==='event' && $ref!=='' && !in_array($ref,$liveEvt,true)){
                if ($go){ $this->firebase->firestoreDelete('notices', $docId); $this->out("  deleted orphan notice {$docId} (ref={$ref})"); }
                else { $this->out("  would delete orphan notice {$docId} (ref={$ref}, \"".mb_substr((string)($d['title']??''),0,40)."\")"); }
                $n++;
            }
        }
        $this->out("  ".($go?'deleted':'would delete')." {$n} orphan event-notice(s).");
    }

    public function mq_audit($sid = 'SCH_B56BB9A401')
    {
        $ev = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
        $liveEvt = [];
        foreach((array)$ev as $r){ $full=(string)($this->rowId($r)??''); $liveEvt[] = (strpos($full,$sid.'_')===0)?substr($full,strlen($sid)+1):$full; }
        foreach (['messageQueue','pushRequests'] as $col){
            $rows = $this->firebase->firestoreQuery($col, [['schoolId','==',$sid]]);
            $this->out("\n{$col} for {$sid}: ".count((array)$rows));
            foreach ((array)$rows as $r){
                $d = $this->rowData($r); $docId=(string)($this->rowId($r)??'');
                $type=(string)($d['type']??''); $ref=(string)($d['eventRef']??$d['eventId']??$d['refId']??'');
                $title=(string)($d['title']??$d['heading']??$d['body']??'');
                $status=(string)($d['status']??$d['state']??'');
                $orphan = ($ref!=='' && strpos($ref,'EVT')===0 && !in_array($ref,$liveEvt,true)) ? '  <== ORPHAN' : '';
                $this->out(sprintf("  %s | type=%s | status=%s | ref=%s | \"%s\"%s", $docId, $type, $status, $ref, mb_substr($title,0,35), $orphan));
            }
        }
    }

    public function gallery_audit($sid = 'SCH_B56BB9A401')
    {
        $this->out("== events ==");
        foreach ((array)$this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]) as $r){
            $d=$this->rowData($r); $full=(string)($this->rowId($r)??'');
            $raw=(strpos($full,$sid.'_')===0)?substr($full,strlen($sid)+1):$full;
            $this->out("  docId={$full}  raw={$raw}  title=\"".($d['title']??'')."\"");
        }
        $this->out("== galleryAlbums ==");
        foreach ((array)$this->firebase->firestoreQuery('galleryAlbums', [['schoolId','==',$sid]]) as $r){
            $d=$this->rowData($r);
            $this->out(sprintf("  docId=%s  albumId=%s  eventId=%s  source=%s  count=%s  title=\"%s\"",
                $this->rowId($r), ($d['albumId']??'?'), ($d['eventId']??'?'), ($d['source']??'?'), ($d['mediaCount']??'?'), ($d['title']??'')));
        }
        $this->out("== galleryMedia ==");
        foreach ((array)$this->firebase->firestoreQuery('galleryMedia', [['schoolId','==',$sid]]) as $r){
            $d=$this->rowData($r);
            $this->out(sprintf("  docId=%s  albumId=%s  type=%s  dur=%s", $this->rowId($r), ($d['albumId']??'?'), ($d['type']??'?'), json_encode($d['duration']??null)));
        }
    }

    public function verifyseed($sid = 'SCH_B56BB9A401')
    {
        $this->out("Verifying seeded data is app-visible for {$sid}:");
        $ev = $this->firebase->firestoreQuery('events', [['schoolId','==',$sid]]);
        $seen=false; foreach((array)$ev as $r){ if($this->rowId($r)==="{$sid}_".self::SEED_EVT){$seen=true;} }
        $this->ok($seen, "Events list query returns DEMO event");
        $al = $this->firebase->firestoreQuery('galleryAlbums', [['schoolId','==',$sid],['eventId','==',self::SEED_EVT],['isArchived','==',false]]);
        $this->ok(count((array)$al)>=1, "getEventAlbum(EVT_DEVICETEST) resolves the album (=> View Photos shows)");
        $md = $this->firebase->firestoreQuery('galleryMedia', [['schoolId','==',$sid],['albumId','==',self::SEED_EVT],['isArchived','==',false]]);
        $this->ok(count((array)$md)>=1, "album has 1 media (image renders)");
        $this->out(sprintf("  (%d pass, %d fail)", $this->pass, $this->fail));
    }

    public function unseed($sid = 'SCH_B56BB9A401')
    {
        foreach ([
            ['events', "{$sid}_".self::SEED_EVT],
            ['galleryAlbums', "{$sid}_".self::SEED_EVT],
            ['galleryMedia', self::SEED_EVT.'_'.self::SEED_IMG],
        ] as $d){ try { $this->firebase->firestoreDelete($d[0], $d[1]); } catch (\Throwable $e){} }
        $this->out("UNSEEDED {$sid}.");
    }

    // ---- PHASE 1: Events contract (admin write -> app read shape) ----------
    private function phase1_events()
    {
        $this->out("\nPHASE 1 — Events contract");
        $now = date('c');
        // Mirror what Events::create_event writes (dual snake+camel, organizer, mediaUrls=[]).
        $ok = $this->firebase->firestoreSet('events', self::SID.'_'.self::EVT, [
            'schoolId'    => self::SID,
            'title'       => 'Selftest Sports Day',
            'description' => 'Harness event',
            'category'    => 'sports',
            'location'    => 'Main Ground',
            'organizer'   => 'Coach Kumar',
            'start_date'  => '2026-08-15', 'startDate' => '2026-08-15',
            'end_date'    => '2026-08-15', 'endDate'   => '2026-08-15',
            'status'      => 'scheduled',
            'max_participants' => 100,
            'mediaUrls'   => [],
            'created_by'  => 'selftest', 'createdBy' => 'selftest',
            'created_at'  => $now, 'createdAt' => $now,
        ], false);
        $this->ok($ok, "write events/".self::SID.'_'.self::EVT);

        // Read the way the apps do: whereEqualTo(schoolId).
        $rows = $this->firebase->firestoreQuery('events', [['schoolId','==',self::SID]]);
        $mine = null;
        foreach ((array)$rows as $r){ if ($this->rowId($r)===self::SID.'_'.self::EVT){ $mine=$this->rowData($r); break; } }
        $this->ok($mine !== null, "app query events(schoolId==) returns the event");
        if ($mine){
            $this->ok(($mine['organizer'] ?? '')==='Coach Kumar', "organizer populated (was always blank pre-fix)");
            $this->ok(!empty($mine['startDate']), "camelCase startDate present (apps orderBy/read this)");
            $this->ok(($mine['status'] ?? '')==='scheduled', "status readable");
            $this->ok(is_array($mine['mediaUrls'] ?? null), "mediaUrls is an array");
        }
    }

    // ---- PHASE 2: event<->gallery link via REAL runtime sync ---------------
    private function phase2_link()
    {
        $this->out("\nPHASE 2 — Event<->Gallery link (real syncGalleryAlbum/Media)");
        // Exactly what Schools::uploadMedia now does for an event upload.
        $a = $this->entity_sync->syncGalleryAlbum(self::EVT, [
            'title'    => 'Selftest Sports Day',
            'category' => 'event',
            'source'   => 'event',
            'eventId'  => self::EVT,
            'createdBy'=> 'selftest',
            'createdAt'=> date('c'),
            'isArchived' => false,
        ]);
        $this->ok($a, "syncGalleryAlbum(source=event, eventId=".self::EVT.")");

        $m = $this->entity_sync->syncGalleryMedia(self::EVT, self::IMG, [
            'url'  => 'https://firebasestorage.googleapis.com/v0/b/graderadmin.appspot.com/o/selftest_img1.jpg?alt=media',
            'type' => 'image',
            'caption' => 'harness image',
            'isArchived' => false,
            'uploadedBy' => 'selftest',
            'uploadedAt' => date('c'),
        ]);
        $this->ok($m, "syncGalleryMedia(image)");
        $this->entity_sync->bumpGalleryAlbumCount(self::EVT, 1);

        // ***THE headline query***: apps' getEventAlbum(eventId).
        $albums = $this->firebase->firestoreQuery('galleryAlbums', [
            ['schoolId','==',self::SID], ['eventId','==',self::EVT], ['isArchived','==',false],
        ]);
        $album = null;
        foreach ((array)$albums as $r){ $d=$this->rowData($r); if (($d['eventId'] ?? '')===self::EVT){ $album=$d; break; } }
        $this->ok($album !== null, "app getEventAlbum(eventId) resolves the album (event->View Photos)");
        if ($album){
            $this->ok(($album['source'] ?? '')==='event', "album.source == 'event'");
            $this->ok(($album['schoolId'] ?? '')===self::SID, "album.schoolId == SCH_TEST (correct key, not schoolCode)");
            $this->ok((int)($album['mediaCount'] ?? 0) >= 1, "album.mediaCount incremented");
        }

        // Apps' album-media query.
        $media = $this->firebase->firestoreQuery('galleryMedia', [
            ['schoolId','==',self::SID], ['albumId','==',self::EVT], ['isArchived','==',false],
        ]);
        $img = null;
        foreach ((array)$media as $r){ $d=$this->rowData($r); if (($d['type'] ?? '')==='image'){ $img=$d; break; } }
        $this->ok($img !== null, "app album-media query returns the image");
        if ($img) $this->ok(strpos($img['url'] ?? '', 'firebasestorage.googleapis.com') !== false, "media.url is a Firebase Storage URL");
    }

    // ---- PHASE 3: video metadata (was dropped by app model pre-fix) --------
    private function phase3_video()
    {
        $this->out("\nPHASE 3 — Gallery video metadata");
        $v = $this->entity_sync->syncGalleryMedia(self::EVT, self::VID, [
            'url'       => 'https://firebasestorage.googleapis.com/v0/b/graderadmin.appspot.com/o/selftest_vid1.mp4?alt=media',
            'type'      => 'video',
            'thumbnail' => 'https://firebasestorage.googleapis.com/v0/b/graderadmin.appspot.com/o/selftest_vid1_thumb.jpg?alt=media',
            'duration'  => '12',
            'isArchived'=> false,
            'uploadedBy'=> 'selftest',
            'uploadedAt'=> date('c'),
        ]);
        $this->ok($v, "syncGalleryMedia(video + thumbnail + duration)");
        $this->entity_sync->bumpGalleryAlbumCount(self::EVT, 1);

        $doc = $this->firebase->firestoreGet('galleryMedia', self::EVT.'_'.self::VID);
        $this->ok(is_array($doc), "video media doc exists");
        if (is_array($doc)){
            $this->ok(($doc['type'] ?? '')==='video', "type=='video' (apps route to player)");
            $this->ok(!empty($doc['thumbnail']), "thumbnail persisted (apps render poster, not blank)");
            $this->ok(($doc['duration'] ?? '')!=='', "duration persisted");
        }
    }

    // ---- PHASE 4: eventParticipants composite index (deployed) -------------
    private function phase4_participants_index()
    {
        $this->out("\nPHASE 4 — eventParticipants index (schoolId,eventId,name)");
        $this->firebase->firestoreSet('eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU2,
            ['schoolId'=>self::SID,'eventId'=>self::EVT,'name'=>'Zed Zulu','studentId'=>self::STU2], false);
        $this->firebase->firestoreSet('eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU1,
            ['schoolId'=>self::SID,'eventId'=>self::EVT,'name'=>'Aaron Alpha','studentId'=>self::STU1], false);

        // The exact get_participants() query: 2 equality filters + orderBy(name).
        // If the composite index is NOT live this throws FAILED_PRECONDITION.
        $threw = false; $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('eventParticipants',
                [['schoolId','==',self::SID],['eventId','==',self::EVT]], 'name', 'ASC');
        } catch (\Throwable $e){ $threw = true; $this->out("    (query threw: ".$e->getMessage().")"); }
        $this->ok(!$threw, "Participation query runs WITHOUT FAILED_PRECONDITION (index live)");
        $this->ok(count((array)$rows) >= 2, "returns the 2 participants");
        if (count((array)$rows) >= 2){
            $first = $this->rowData($rows[0]);
            $this->ok(($first['name'] ?? '')==='Aaron Alpha', "orderBy(name) ASC honored (Aaron before Zed)");
        }
    }

    // ---- PHASE 5: cascade delete (replicates delete_event Firestore steps) --
    private function phase5_cascade()
    {
        $this->out("\nPHASE 5 — Event delete cascade");
        // Replicate the Firestore-side cascade delete_event performs.
        $this->firebase->firestoreDelete('eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU1);
        $this->firebase->firestoreDelete('eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU2);
        $this->entity_sync->syncDeleteGalleryMedia(self::EVT, self::IMG);
        $this->entity_sync->syncDeleteGalleryMedia(self::EVT, self::VID);
        $this->firebase->firestoreDelete('galleryAlbums', self::SID.'_'.self::EVT);
        $this->firebase->firestoreDelete('events', self::SID.'_'.self::EVT);

        $ev = $this->firebase->firestoreQuery('events', [['schoolId','==',self::SID]]);
        $stillEvt = false; foreach((array)$ev as $r){ if($this->rowId($r)===self::SID.'_'.self::EVT){ $stillEvt=true; } }
        $this->ok(!$stillEvt, "event doc removed");

        $al = $this->firebase->firestoreQuery('galleryAlbums', [['schoolId','==',self::SID],['eventId','==',self::EVT],['isArchived','==',false]]);
        $this->ok(count((array)$al)===0, "event album removed (no dangling album in apps)");

        $md = $this->firebase->firestoreQuery('galleryMedia', [['schoolId','==',self::SID],['albumId','==',self::EVT],['isArchived','==',false]]);
        $this->ok(count((array)$md)===0, "album media removed (no orphaned media)");

        $pt = $this->firebase->firestoreQuery('eventParticipants', [['schoolId','==',self::SID],['eventId','==',self::EVT]]);
        $this->ok(count((array)$pt)===0, "participants removed");
    }

    // ---- teardown ----------------------------------------------------------
    private function cleanupDocs()
    {
        foreach ([
            ['events', self::SID.'_'.self::EVT],
            ['galleryAlbums', self::SID.'_'.self::EVT],
            ['galleryMedia', self::EVT.'_'.self::IMG],
            ['galleryMedia', self::EVT.'_'.self::VID],
            ['eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU1],
            ['eventParticipants', self::SID.'_'.self::EVT.'_'.self::STU2],
        ] as $d){
            try { $this->firebase->firestoreDelete($d[0], $d[1]); } catch (\Throwable $e) {}
        }
    }
}
