<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Service_exception — typed errors thrown by service-layer methods so the
 * controller can map them to consistent JSON responses.
 *
 *   throw new Service_exception('Topic not found', 'not_found');
 *
 * Types (controller doesn't strictly branch on these today, but they're
 * available for future error-shape standardisation):
 *
 *   'validation'  — input failed pre-condition checks (missing field, bad format)
 *   'auth'        — caller not authorised for this resource
 *   'conflict'    — version mismatch (Phase 2 optimistic concurrency)
 *   'not_found'   — target doc / topic / event doesn't exist
 *   'internal'    — Firestore failure, batch fail, etc. (default)
 */
class Service_exception extends \RuntimeException
{
    /** @var string */
    public $errorType;

    public function __construct(string $message, string $errorType = 'internal', \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
    }
}
