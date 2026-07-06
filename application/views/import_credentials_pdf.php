<?php
/**
 * Import credentials handout — printable PDF table of the users created by a
 * bulk import, so the school can hand each person their login.
 *
 * Rendered by Pdf_generator (Dompdf). Dompdf has limited flexbox/grid, so the
 * layout is deliberately table-based. Kept self-contained (all CSS inline).
 *
 * Expected vars:
 *   $rows        array of ['name'=>, 'phone'=>, 'userId'=>, 'password'=>]
 *   $entityLabel 'Student' | 'Staff'
 *   $title       heading, e.g. "Student Login Credentials"
 *   $schoolName  school display name
 *   $sessionYear session string
 *   $generatedAt formatted date/time
 */
$rows        = isset($rows) && is_array($rows) ? $rows : [];
$entityLabel = $entityLabel ?? 'User';
$title       = $title ?? ($entityLabel . ' Login Credentials');
$schoolName  = $schoolName ?? '';
$sessionYear = $sessionYear ?? '';
$generatedAt = $generatedAt ?? date('d-m-Y H:i');
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<style>
  .cred-doc { font-family: sans-serif; color: #1e293b; }
  .cred-hdr { border-bottom: 3px solid #2563eb; padding-bottom: 10px; margin-bottom: 14px; }
  .cred-school { font-size: 20px; font-weight: 800; color: #1e3a8a; margin: 0 0 2px; }
  .cred-title { font-size: 13px; font-weight: 700; color: #2563eb; margin: 0; letter-spacing: .3px; }
  .cred-meta { margin-top: 6px; font-size: 10px; color: #64748b; }
  .cred-meta span { margin-right: 18px; }
  .cred-note {
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
    font-size: 9.5px; padding: 7px 10px; border-radius: 6px; margin-bottom: 12px;
  }
  table.cred-tbl { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  table.cred-tbl thead { display: table-header-group; }   /* repeat header on each page */
  table.cred-tbl th {
    background: #2563eb; color: #fff; text-align: left; font-weight: 700;
    padding: 7px 9px; border: 1px solid #1d4ed8; font-size: 10px;
    text-transform: uppercase; letter-spacing: .4px;
  }
  table.cred-tbl td { padding: 6px 9px; border: 1px solid #e2e8f0; vertical-align: top; }
  table.cred-tbl tr { page-break-inside: avoid; }
  table.cred-tbl tbody tr:nth-child(even) td { background: #f8fafc; }
  .col-idx  { width: 34px; text-align: center; color: #64748b; }
  .col-uid  { font-family: monospace; font-weight: 700; color: #1e3a8a; white-space: nowrap; }
  .col-pwd  { font-family: monospace; font-weight: 700; color: #b91c1c; white-space: nowrap; }
  .col-phone { white-space: nowrap; }
  .cell-blank { color: #cbd5e1; }
  .cred-foot { margin-top: 16px; font-size: 8.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
</style>

<div class="cred-doc">
  <div class="cred-hdr">
    <?php if ($schoolName !== ''): ?><p class="cred-school"><?= $e($schoolName) ?></p><?php endif; ?>
    <p class="cred-title"><?= $e($title) ?></p>
    <div class="cred-meta">
      <span><b>Total:</b> <?= count($rows) ?> <?= $e(strtolower($entityLabel)) ?><?= count($rows) === 1 ? '' : 's' ?></span>
      <?php if ($sessionYear !== ''): ?><span><b>Session:</b> <?= $e($sessionYear) ?></span><?php endif; ?>
      <span><b>Generated:</b> <?= $e($generatedAt) ?></span>
    </div>
  </div>

  <div class="cred-note">
    <b>Confidential.</b> This sheet contains login IDs and default passwords. Hand each
    <?= $e(strtolower($entityLabel)) ?> only their own row and ask them to change the password after first login.
  </div>

  <table class="cred-tbl">
    <thead>
      <tr>
        <th class="col-idx">#</th>
        <th>Name</th>
        <th>Mobile Number</th>
        <th>User ID</th>
        <th>Default Password</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:18px;">No users to list.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r):
          $name  = trim((string) ($r['name'] ?? ''));
          $phone = trim((string) ($r['phone'] ?? ''));
          $uid   = trim((string) ($r['userId'] ?? ''));
          $pwd   = trim((string) ($r['password'] ?? ''));
        ?>
        <tr>
          <td class="col-idx"><?= $i + 1 ?></td>
          <td><?= $name !== '' ? $e($name) : '<span class="cell-blank">—</span>' ?></td>
          <td class="col-phone"><?= $phone !== '' ? $e($phone) : '<span class="cell-blank"></span>' ?></td>
          <td class="col-uid"><?= $e($uid) ?></td>
          <td class="col-pwd"><?= $pwd !== '' ? $e($pwd) : '<span class="cell-blank">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="cred-foot">
    ZenXii · <?= $e($schoolName) ?> · Generated <?= $e($generatedAt) ?> — keep this document secure.
  </div>
</div>
