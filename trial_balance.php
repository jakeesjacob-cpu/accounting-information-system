<?php
require 'config.php'; require 'layout.php'; require_login();
$role   = $_SESSION['user']['role'];
$userId = (int)$_SESSION['user']['user_id'];
$canReview = in_array($role, ['Admin', 'Faculty'], true);

// ---- helpers -------------------------------------------------------------
function tb_has_owner(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)$pdo->query("SHOW COLUMNS FROM chart_of_accounts LIKE 'created_by'")->fetch(); }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}
function tb_peso($n): string { return '₱' . number_format((float)$n, 2); }

// Heading (company name, title of report, end of month) the student typed in the Journal.
function tb_header(PDO $pdo, int $studentId): array {
    $out = ['company' => '', 'period_end' => null];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_report_headers (
            student_id INT NOT NULL PRIMARY KEY,
            company VARCHAR(150) NOT NULL DEFAULT '',
            report_title VARCHAR(150) NOT NULL DEFAULT '',
            period_end DATE NULL
        )");
        $q = $pdo->prepare('SELECT company, period_end FROM student_report_headers WHERE student_id=?');
        $q->execute([$studentId]);
        if ($r = $q->fetch()) { $out['company'] = (string)$r['company']; $out['period_end'] = $r['period_end']; }
    } catch (Throwable $e) { /* heading table unavailable */ }
    return $out;
}

// One student's trial balance: every account in THEIR chart of accounts, with the totals
// of THEIR posted (balanced / Valid) journal entries only. Net debit -> Debit column,
// net credit -> Credit column.
function tb_rows(PDO $pdo, int $studentId): array {
    $own = tb_has_owner($pdo);
    $sql = "SELECT a.account_id, a.account_code, a.account_name, " . ($own ? "a.created_by" : "NULL") . " AS created_by,
                   COALESCE(SUM(jl.debit_amount),0) AS d, COALESCE(SUM(jl.credit_amount),0) AS c
            FROM chart_of_accounts a
            LEFT JOIN (journal_lines jl JOIN journal_entries je
                       ON je.entry_id = jl.entry_id AND je.student_id = ? AND je.ai_validation_status = 'Valid')
                   ON jl.account_id = a.account_id
            " . ($own ? "WHERE a.created_by = ? OR a.created_by IS NULL" : "") . "
            GROUP BY a.account_id, a.account_code, a.account_name" . ($own ? ", a.created_by" : "") . "
            ORDER BY a.account_code";
    $q = $pdo->prepare($sql);
    $q->execute($own ? [$studentId, $studentId] : [$studentId]);
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $d = (float)$r['d']; $c = (float)$r['c'];
        // shared (no owner) accounts only appear if this student actually used them
        if ($r['created_by'] === null && $own && $d == 0 && $c == 0) continue;
        $net = $d - $c;
        $rows[] = ['name' => $r['account_name'], 'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0];
    }
    return $rows;
}

// ---- who is shown --------------------------------------------------------
$fSec = (int)($_GET['sec'] ?? 0); $fGrp = (int)($_GET['grp'] ?? 0); $fStu = (int)($_GET['stu'] ?? 0);
$students = [];
if ($role === 'Student') {
    $students[] = ['user_id' => $userId, 'name' => $_SESSION['user']['name'] ?? ''];
} else {
    $w = ["u.role='Student'"]; $p = [];
    if ($fSec) { $w[] = 'e.section_id=?'; $p[] = $fSec; }
    if ($fGrp) { $w[] = 'e.group_id=?';   $p[] = $fGrp; }
    if ($fStu) { $w[] = 'u.user_id=?';    $p[] = $fStu; }
    $q = $pdo->prepare("SELECT DISTINCT u.user_id, u.name FROM users u
                        LEFT JOIN enrollments e ON e.student_id=u.user_id
                        WHERE " . implode(' AND ', $w) . "
                          AND EXISTS (SELECT 1 FROM journal_entries je WHERE je.student_id=u.user_id)
                        ORDER BY u.name");
    $q->execute($p);
    $students = $q->fetchAll();
}

layout_start('trial', 'Trial Balance',
    $role === 'Student'
        ? 'Your trial balance is built from the journal entries you posted (balanced entries only).'
        : 'Each student\'s trial balance, built from the journal entries that student posted.');

if ($canReview) {
    $secs = $pdo->query("SELECT s.section_id, s.section_name, c.course_code FROM sections s JOIN courses c ON c.course_id=s.course_id ORDER BY c.course_code, s.section_name")->fetchAll();
    $q = $pdo->prepare("SELECT g.group_id, g.group_name, s.section_name FROM student_groups g JOIN sections s ON s.section_id=g.section_id WHERE (?=0 OR g.section_id=?) ORDER BY s.section_name, g.group_name");
    $q->execute([$fSec, $fSec]); $grps = $q->fetchAll();
    $q = $pdo->prepare("SELECT DISTINCT u.user_id, u.name FROM users u JOIN enrollments e ON e.student_id=u.user_id WHERE u.role='Student' AND (?=0 OR e.section_id=?) AND (?=0 OR e.group_id=?) ORDER BY u.name");
    $q->execute([$fSec, $fSec, $fGrp, $fGrp]); $stus = $q->fetchAll();
?>
<form method="get" style="margin-bottom:12px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div style="min-width:170px"><label class="field-label">Section</label>
      <select name="sec" onchange="this.form.grp.value=0;this.form.stu.value=0;this.form.submit()">
        <option value="0">All sections</option>
        <?php foreach ($secs as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$fSec === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Group</label>
      <select name="grp" onchange="this.form.stu.value=0;this.form.submit()">
        <option value="0">All groups</option>
        <?php foreach ($grps as $g): ?><option value="<?=(int)$g['group_id']?>"<?=$fGrp === (int)$g['group_id'] ? ' selected' : ''?>><?=h($g['section_name'] . ' — ' . $g['group_name'])?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Student</label>
      <select name="stu" onchange="this.form.submit()">
        <option value="0">All students</option>
        <?php foreach ($stus as $s): ?><option value="<?=(int)$s['user_id']?>"<?=$fStu === (int)$s['user_id'] ? ' selected' : ''?>><?=h($s['name'])?></option><?php endforeach; ?>
      </select></div>
  </div>
</form>
<?php } ?>

<style>
.tbx{width:100%;max-width:560px;border-collapse:collapse;background:#fff;color:#000;font-family:'Times New Roman',Times,serif;font-size:15px;margin:0 0 6px}
.tbx td,.tbx th{border:1px solid #000;padding:3px 6px}
.tbx .hd{text-align:center;font-weight:bold;font-size:16px}
.tbx .co,.tbx .pd{color:#e00000}
.tbx .cols th{text-align:left;font-weight:bold}
.tbx .nm{color:#e00000}
.tbx .am{text-align:right;white-space:nowrap}
.tbx tfoot td{font-weight:bold}
.tb-who{margin:18px 0 6px;font-weight:600}
</style>

<?php if (!$students): ?>
  <div class="card"><p class="small muted">No posted journal entries yet, so there is no trial balance to show.</p></div>
<?php endif; ?>

<?php foreach ($students as $stu):
    $sid  = (int)$stu['user_id'];
    $hd   = tb_header($pdo, $sid);
    $rows = tb_rows($pdo, $sid);
    $td = 0; $tc = 0; foreach ($rows as $r) { $td += $r['debit']; $tc += $r['credit']; }
    $period = $hd['period_end'] ? date('d/m/Y', strtotime($hd['period_end'])) : '';
?>
  <?php if ($canReview): ?><div class="tb-who"><?=h($stu['name'])?></div><?php endif; ?>
  <div style="overflow-x:auto">
  <table class="tbx">
    <thead>
    <tr><td class="hd co" colspan="3"><?=h($hd['company'] !== '' ? $hd['company'] : 'Company name')?></td></tr>
    <tr><td class="hd" colspan="3">Trial Balance</td></tr>
    <tr><td class="hd pd" colspan="3">End of Month<?=$period !== '' ? ': ' . h($period) : ''?></td></tr>
    <tr class="cols"><th style="width:44%">Account</th><th>Debit (₱)</th><th>Credit (₱)</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="3" style="text-align:center">No accounts yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nm"><?=h($r['name'])?></td>
        <td class="am"><?=h(tb_peso($r['debit']))?></td>
        <td class="am"><?=h(tb_peso($r['credit']))?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td>Totals</td><td class="am"><?=h(tb_peso($td))?></td><td class="am"><?=h(tb_peso($tc))?></td></tr>
    </tfoot>
  </table>
  </div>
  <?php if (round($td - $tc, 2) != 0): ?>
    <div class="banner warn" style="max-width:560px">⚠ Debits and credits differ by <?=h(tb_peso(abs($td - $tc)))?>.</div>
  <?php endif; ?>
<?php endforeach; ?>

<?php layout_end(); ?>