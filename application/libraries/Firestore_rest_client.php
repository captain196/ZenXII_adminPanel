<?php
/**
 * Firestore REST API client — no gRPC extension required.
 * Uses Google OAuth2 service account JWT -> access token flow + Firestore v1 REST API.
 */

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

class FirestoreRestClient
{
    private string $projectId;
    private string $databaseId;
    private array  $serviceAccount;
    private string $accessToken = '';
    private int    $tokenExpiry = 0;

    public function __construct(string $serviceAccountPath, string $projectId, string $databaseId)
    {
        $json = file_get_contents($serviceAccountPath);
        if ($json === false) throw new \RuntimeException("Cannot read service account file: $serviceAccountPath");
        $this->serviceAccount = json_decode($json, true);
        if (!$this->serviceAccount) throw new \RuntimeException("Invalid service account JSON");
        $this->projectId  = $projectId;
        $this->databaseId = $databaseId;
    }

    private function baseUrl(): string
    {
        return "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->databaseId}/documents";
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== '' && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }
        $now = time();
        $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64url_encode(json_encode([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signInput = "$header.$payload";
        $pk = openssl_pkey_get_private($this->serviceAccount['private_key']);
        if (!$pk) throw new \RuntimeException('Invalid private key in service account');
        openssl_sign($signInput, $signature, $pk, OPENSSL_ALGO_SHA256);
        $jwt = $signInput . '.' . base64url_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new \RuntimeException("OAuth token request failed ($code): $resp");
        $data = json_decode($resp, true);
        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = $now + ($data['expires_in'] ?? 3600);
        return $this->accessToken;
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $token = $this->getAccessToken();
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($method === 'GET') {
            // default
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => json_decode($resp, true) ?? []];
    }

    private function encode($value): array
    {
        if ($value === null)         return ['nullValue' => null];
        if (is_bool($value))         return ['booleanValue' => $value];
        if (is_int($value))          return ['integerValue' => (string)$value];
        if (is_float($value))        return ['doubleValue' => $value];
        if (is_string($value))       return ['stringValue' => $value];
        if (is_array($value)) {
            if (empty($value))       return ['arrayValue' => ['values' => []]];
            if (array_is_list($value)) {
                return ['arrayValue' => ['values' => array_map([$this, 'encode'], $value)]];
            }
            $fields = [];
            foreach ($value as $k => $v) $fields[$k] = $this->encode($v);
            return ['mapValue' => ['fields' => $fields]];
        }
        return ['stringValue' => (string)$value];
    }

    private function decode(array $val)
    {
        if (array_key_exists('nullValue', $val))     return null;
        if (isset($val['booleanValue']))             return $val['booleanValue'];
        if (isset($val['integerValue']))             return (int)$val['integerValue'];
        if (isset($val['doubleValue']))              return (float)$val['doubleValue'];
        if (isset($val['stringValue']))              return $val['stringValue'];
        if (isset($val['timestampValue']))           return $val['timestampValue'];
        if (isset($val['arrayValue'])) {
            return array_map([$this, 'decode'], $val['arrayValue']['values'] ?? []);
        }
        if (isset($val['mapValue'])) {
            $result = [];
            foreach (($val['mapValue']['fields'] ?? []) as $k => $v) $result[$k] = $this->decode($v);
            return $result;
        }
        if (isset($val['geoPointValue']))            return $val['geoPointValue'];
        if (isset($val['referenceValue']))           return $val['referenceValue'];
        if (isset($val['bytesValue']))               return $val['bytesValue'];
        return null;
    }

    private function decodeDocument(array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $result = [];
        foreach ($fields as $k => $v) $result[$k] = $this->decode($v);
        return $result;
    }

    private function docIdFromName(string $name): string
    {
        $parts = explode('/', $name);
        return end($parts);
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        $url = $this->baseUrl() . "/$collection/$docId";
        $r = $this->request('GET', $url);
        if ($r['code'] === 404) return null;
        if ($r['code'] !== 200) {
            if (function_exists('log_message')) log_message('error', "FirestoreREST::get $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
            return null;
        }
        return $this->decodeDocument($r['body']);
    }

    public function setDocument(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        $fields = [];
        foreach ($data as $k => $v) $fields[$k] = $this->encode($v);

        if ($merge) {
            $masks = array_keys($data);
            $maskParams = implode('&', array_map(fn($m) => 'updateMask.fieldPaths=' . urlencode($m), $masks));
            $url = $this->baseUrl() . "/$collection/$docId?$maskParams";
            $r = $this->request('PATCH', $url, ['fields' => $fields]);
        } else {
            $url = $this->baseUrl() . "/$collection?documentId=" . urlencode($docId);
            $r = $this->request('POST', $url, ['fields' => $fields]);
            if ($r['code'] === 409) {
                $url = $this->baseUrl() . "/$collection/$docId";
                $r = $this->request('PATCH', $url, ['fields' => $fields]);
            }
        }
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::set $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    public function updateDocument(string $collection, string $docId, array $data): bool
    {
        $fields = [];
        foreach ($data as $k => $v) $fields[$k] = $this->encode($v);
        $masks = array_keys($data);
        $maskParams = implode('&', array_map(fn($m) => 'updateMask.fieldPaths=' . urlencode($m), $masks));
        $url = $this->baseUrl() . "/$collection/$docId?$maskParams";
        $r = $this->request('PATCH', $url, ['fields' => $fields]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::update $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    public function deleteDocument(string $collection, string $docId): bool
    {
        $url = $this->baseUrl() . "/$collection/$docId";
        $r = $this->request('DELETE', $url);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::delete $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    public function query(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        $opMap = ['=' => 'EQUAL', '==' => 'EQUAL', '<' => 'LESS_THAN', '<=' => 'LESS_THAN_OR_EQUAL',
                  '>' => 'GREATER_THAN', '>=' => 'GREATER_THAN_OR_EQUAL', '!=' => 'NOT_EQUAL',
                  'in' => 'IN', 'not-in' => 'NOT_IN', 'array-contains' => 'ARRAY_CONTAINS',
                  'array-contains-any' => 'ARRAY_CONTAINS_ANY'];

        $structuredQuery = [
            'from' => [['collectionId' => $collection]],
        ];

        if (!empty($conditions)) {
            $filters = [];
            foreach ($conditions as [$field, $op, $value]) {
                $firestoreOp = $opMap[$op] ?? 'EQUAL';
                $filters[] = [
                    'fieldFilter' => [
                        'field'  => ['fieldPath' => $field],
                        'op'     => $firestoreOp,
                        'value'  => $this->encode($value),
                    ]
                ];
            }
            if (count($filters) === 1) {
                $structuredQuery['where'] = $filters[0];
            } else {
                $structuredQuery['where'] = [
                    'compositeFilter' => ['op' => 'AND', 'filters' => $filters]
                ];
            }
        }

        if ($orderBy !== null) {
            $structuredQuery['orderBy'] = [[
                'field'     => ['fieldPath' => $orderBy],
                'direction' => strtoupper($direction) === 'DESC' ? 'DESCENDING' : 'ASCENDING',
            ]];
        }

        if ($limit !== null) {
            $structuredQuery['limit'] = $limit;
        }

        $url = $this->baseUrl() . ':runQuery';
        $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);

        // If query fails with index error and we have orderBy, retry without orderBy (client-side sort)
        if ($r['code'] !== 200 && $orderBy !== null) {
            unset($structuredQuery['orderBy']);
            $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);
        }

        if ($r['code'] !== 200) {
            if (function_exists('log_message')) log_message('error', "FirestoreREST::query $collection HTTP {$r['code']}: " . json_encode($r['body']));
            return [];
        }

        $results = [];
        $docs = $r['body'];
        if (!is_array($docs)) return [];
        foreach ($docs as $item) {
            if (isset($item['document'])) {
                $docName = $item['document']['name'];
                $docId   = $this->docIdFromName($docName);
                $data    = $this->decodeDocument($item['document']);
                $results[] = ['id' => $docId, 'data' => $data];
            }
        }
        // Client-side sort if orderBy was requested but couldn't be done server-side
        if ($orderBy !== null && !empty($results)) {
            usort($results, function ($a, $b) use ($orderBy, $direction) {
                $va = $a['data'][$orderBy] ?? '';
                $vb = $b['data'][$orderBy] ?? '';
                $cmp = $va <=> $vb;
                return strtoupper($direction) === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit !== null && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }
}
