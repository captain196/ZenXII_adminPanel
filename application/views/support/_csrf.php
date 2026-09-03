<?php
/**
 * CSRF handshake for every Support view.
 *
 * `SD.postJSON()` sends `SD.csrfName: SD.csrfHash`. Those default to
 * `'csrf_token'` and an EMPTY STRING in assets/js/support_desk.js, so a view
 * that does not emit the real values ships an empty token on every POST and
 * CodeIgniter rejects it with a 403.
 *
 * That is exactly what happened: only thread.php emitted them. The queue and
 * My Tickets views did not, so any POST from either was refused — and the
 * helper reports a 403 as "That action was refused. Your access may have
 * changed — reload and try again.", which points a staff member and their
 * admin straight at RBAC for what is actually a missing token.
 *
 * It stayed invisible because the only POST on the queue is bulk force close,
 * which currently has no UI control wired to it. The day one is added, it would
 * have failed 100% of the time with a message about permissions.
 *
 * Included by every Support view so a fourth one cannot forget.
 */
?>
<script>
  SD.csrfName = '<?= $this->security->get_csrf_token_name() ?>';
  SD.csrfHash = '<?= $this->security->get_csrf_hash() ?>';
</script>
