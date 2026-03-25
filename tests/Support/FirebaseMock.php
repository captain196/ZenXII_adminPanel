<?php
namespace Tests\Support;

/**
 * FirebaseMock — in-memory Firebase double.
 *
 * Stores data in a nested PHP array so unit tests never touch the real database.
 * Matches the public API of the production Firebase library exactly.
 *
 * Features
 *  • get / set / update / delete / push / exists / shallow_get
 *  • Call recording: every call is stored in $calls[] for assertion
 *  • Seed helper: seed($path, $data) populates test fixtures
 *  • Failure injection: failNext($op) makes the next matching call return null/false
 */
class FirebaseMock
{
    /** Nested store: path segments → value */
    private array $store = [];

    /** Every call recorded as ['op'=>string, 'path'=>string, 'data'=>mixed] */
    public array $calls = [];

    /** Ops that should simulate failure on next call */
    private array $failQueue = [];

    // ── Seeding ────────────────────────────────────────────────────────────────

    public function seed(string $path, mixed $data): void
    {
        $this->_set_nested($this->store, explode('/', $path), $data);
    }

    public function reset(): void
    {
        $this->store    = [];
        $this->calls    = [];
        $this->failQueue = [];
    }

    // ── Failure injection ──────────────────────────────────────────────────────

    /** Make the next call to $op (e.g. 'get') simulate a Firebase error. */
    public function failNext(string $op): void
    {
        $this->failQueue[] = strtolower($op);
    }

    // ── Firebase API surface ───────────────────────────────────────────────────

    public function get(string $path): mixed
    {
        $this->_record('get', $path);
        if ($this->_shouldFail('get')) return null;
        return $this->_get_nested($this->store, explode('/', $path));
    }

    public function set(string $path, mixed $data): bool
    {
        $this->_record('set', $path, $data);
        if ($this->_shouldFail('set')) return false;
        $this->_set_nested($this->store, explode('/', $path), $data);
        return true;
    }

    public function update(string $path, array $data): bool
    {
        $this->_record('update', $path, $data);
        if ($this->_shouldFail('update')) return false;
        $existing = $this->_get_nested($this->store, explode('/', $path));
        $merged   = is_array($existing) ? array_merge($existing, $data) : $data;
        $this->_set_nested($this->store, explode('/', $path), $merged);
        return true;
    }

    public function delete(string $path): bool
    {
        $this->_record('delete', $path);
        if ($this->_shouldFail('delete')) return false;
        $this->_delete_nested($this->store, explode('/', $path));
        return true;
    }

    public function push(string $path, mixed $data): ?string
    {
        $this->_record('push', $path, $data);
        if ($this->_shouldFail('push')) return null;
        $key = 'mock_' . substr(md5(uniqid('', true)), 0, 8);
        $this->_set_nested($this->store, array_merge(explode('/', $path), [$key]), $data);
        return $key;
    }

    public function exists(string $path): bool
    {
        $this->_record('exists', $path);
        return $this->_get_nested($this->store, explode('/', $path)) !== null;
    }

    public function shallow_get(string $path): array
    {
        $this->_record('shallow_get', $path);
        $val = $this->_get_nested($this->store, explode('/', $path));
        return is_array($val) ? array_keys($val) : [];
    }

    public function generateKey(string $path): ?string
    {
        return 'key_' . substr(md5(uniqid('', true)), 0, 8);
    }

    // ── Assertion helpers ──────────────────────────────────────────────────────

    /** Assert that an op was called with a path matching the given substring. */
    public function assertCalled(string $op, string $pathContains, ?\PHPUnit\Framework\TestCase $test = null): bool
    {
        foreach ($this->calls as $call) {
            if ($call['op'] === $op && strpos($call['path'], $pathContains) !== false) {
                return true;
            }
        }
        if ($test) {
            $test->fail("Expected Firebase::{$op}() to be called with path containing '{$pathContains}'.\nActual calls: " . json_encode(array_column($this->calls, 'path')));
        }
        return false;
    }

    /** Count calls matching op + optional path substring. */
    public function countCalls(string $op, string $pathContains = ''): int
    {
        $n = 0;
        foreach ($this->calls as $c) {
            if ($c['op'] === $op && ($pathContains === '' || strpos($c['path'], $pathContains) !== false)) {
                $n++;
            }
        }
        return $n;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function _record(string $op, string $path, mixed $data = null): void
    {
        $this->calls[] = ['op' => $op, 'path' => $path, 'data' => $data];
    }

    private function _shouldFail(string $op): bool
    {
        $idx = array_search($op, $this->failQueue, true);
        if ($idx !== false) {
            array_splice($this->failQueue, $idx, 1);
            return true;
        }
        return false;
    }

    private function _get_nested(array $store, array $segments): mixed
    {
        $node = $store;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) return null;
            $node = $node[$seg];
        }
        return $node;
    }

    private function _set_nested(array &$store, array $segments, mixed $value): void
    {
        $node = &$store;
        foreach ($segments as $seg) {
            if (!isset($node[$seg]) || !is_array($node[$seg])) {
                $node[$seg] = [];
            }
            $node = &$node[$seg];
        }
        $node = $value;
    }

    private function _delete_nested(array &$store, array $segments): void
    {
        $node = &$store;
        $last = array_pop($segments);
        foreach ($segments as $seg) {
            if (!isset($node[$seg])) return;
            $node = &$node[$seg];
        }
        unset($node[$last]);
    }
}
