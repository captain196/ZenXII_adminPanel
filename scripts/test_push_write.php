<?php
// One-shot diagnostic: invoke the exact Firestore write path that _enqueue_push_request uses
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

$saPath = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$client = new FirestoreRestClient($saPath, 'graderadmin', '(default)');

$docId = 'SCH_D94FE8F7AD_notice_created_TEST' . time();
$payload = [
    'schoolId' => 'SCH_D94FE8F7AD',
    'mark'     => 'NOTICE_CREATED',
    'source'   => 'notice_created',
    'status'   => 'pending',
    'markedBy' => 'cli-test',
    'createdAt'=> date('c'),
];

echo "Writing pushRequests/{$docId}...\n";
$ok = $client->setDocument('pushRequests', $docId, $payload, false);
echo "setDocument returned: " . var_export($ok, true) . "\n";
echo "Now reading it back...\n";
$got = $client->getDocument('pushRequests', $docId);
echo "getDocument returned: " . var_export($got, true) . "\n";
