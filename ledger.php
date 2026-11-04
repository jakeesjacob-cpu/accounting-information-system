<?php
require 'config.php'; require 'layout.php'; require_login();
$role   = $_SESSION['user']['role'];
$userId = (int)$_SESSION['user']['user_id'];
$canFilter = in_array($role, ['Admin','Faculty'], true);

// ---------- helpers ----------
// Debit-nature accounts (Asset, Expense) grow on the debit side; all others on the credit side.
function lg_normal($type) { return in_array($type, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit'; }
function lg_money($n) { return '₱' . number_format((float)$n, 2); }
// A running balance is shown as an amount plus DR or CR, e.g. ₱107,000.00 DR
function lg_bal($amount, $side) { return lg_money(abs($amount)) . ' ' . ($side === 'Debit' ? 'DR' : 'CR'); }
function lg_title($d) { $p = explode(' ‖ ', (string)$d, 2); return $p[0]; }

// ---------- filters (faculty / admin) ----------
$fSec = (int)($_GET['sec'] ?? 0); $fGrp = (int)($_GET['grp'] ?? 0); $fStu = (int)($_GET['stu'] ?? 0);
$fSections = $fGroups = $fStudents = [];
$rows = []; $loadError = '';

try {
    $w = ["j.ai_validation_status='Valid'"]; $p = [];   // only balanced entries are posted to the ledger
    if ($role === 'Student') { $w[] = 'j.student_id=?'; $p[] = $userId; }
    elseif ($fStu) { $w[] = 'j.student_id=?'; $p[] = $fStu; }
    elseif ($fGrp) { $w[] = 'e.group_id=?';   $p[] = $fGrp; }
    elseif ($fSec) { $w[] = 'e.section_id=?'; $p[] = $fSec; }

    $st = $pdo->prepare("SELECT j.student_id, u.name, sc.section_name, g.group_name,
            a.account_id, a.account_code, a.account_name, a.account_type,
            j.entry_id, j.entry_date, j.description, l.debit_amount, l.credit_amount
        FROM journal_lines l
        JOIN journal_entries j ON j.entry_id = l.entry_id
        JOIN users u ON u.user_id = j.student_id
        JOIN chart_of_accounts a ON a.account_id = l.account_id
        LEFT JOIN enrollments e ON e.student_id = j.student_id
        LEFT JOIN sections sc ON sc.section_id = e.section_id
        LEFT JOIN student_groups g ON g.group_id = e.group_id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY u.name, j.student_id, a.account_code, a.account_name, j.entry_date, j.entry_id");
    $st->execute($p); $rows = $st->fetchAll();

    if ($canFilter) {
        $fSections = $pdo->query("SELECT s.section_id, s.section_name, c.course_code FROM sections s JOIN courses c ON c.course_id=s.course_id ORDER BY c.course_code, s.section_name")->fetchAll();
        $q = $pdo->prepare("SELECT g.group_id, g.group_name, s.section_name FROM student_groups g JOIN sections s ON s.section_id=g.section_id WHERE (?=0 OR g.section_id=?) ORDER BY s.section_name, g.group_name");
        $q->execute([$fSec, $fSec]); $fGroups = $q->fetchAll();
        $q = $pdo->prepare("SELECT u.user_id, u.name FROM users u JOIN enrollments e ON e.student_id=u.user_id
            WHERE u.role='Student' AND (?=0 OR e.section_id=?) AND (?=0 OR e.group_id=?) ORDER BY u.name");
        $q->execute([$fSec, $fSec, $fGrp, $fGrp]); $fStudents = $q->fetchAll();
    }
} catch (PDOException $e) {
    $loadError = 'The ledger could not be loaded: ' . $e->getMessage();
}

// Heading (company name, title of report, end of month) saved by each student in the journal.
$headers = [];
try {
    $ids = array_values(array_unique(array_map('intval', array_column($rows, 'student_id'))));
    if ($ids) {
        $hq = $pdo->prepare('SELECT * FROM student_report_headers WHERE student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $hq->execute($ids);
        foreach ($hq->fetchAll() as $hr) $headers[(int)$hr['student_id']] = $hr;
    }
} catch (PDOException $e) { $headers = []; }

// Group: student -> account -> lines
$byStudent = [];
foreach ($rows as $r) {
    $sid = (int)$r['student_id']; $aid = (int)$r['account_id'];
    if (!isset($byStudent[$sid])) $byStudent[$sid] = ['info' => $r, 'accounts' => []];
    if (!isset($byStudent[$sid]['accounts'][$aid])) $byStudent[$sid]['accounts'][$aid] = ['info' => $r, 'lines' => []];
    $byStudent[$sid]['accounts'][$aid]['lines'][] = $r;
}

layout_start('ledger', 'General Ledger', 'Every posted journal entry, sorted into its account with a running balance.');
if ($loadError !== '') echo '<div class="banner warn">' . h($loadError) . '</div>';
?>

<?php if ($canFilter): ?>
<form method="get" style="margin-bottom:12px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div style="min-width:170px"><label class="field-label">Section</label>
      <select name="sec" onchange="this.form.grp.value=0;this.form.stu.value=0;this.form.submit()">
        <option value="0">All sections</option>
        <?php foreach ($fSections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$fSec === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Group</label>
      <select name="grp" onchange="this.form.stu.value=0;this.form.submit()">
        <option value="0">All groups</option>
        <?php foreach ($fGroups as $g): ?><option value="<?=(int)$g['group_id']?>"<?=$fGrp === (int)$g['group_id'] ? ' selected' : ''?>><?=h($g['section_name'] . ' — ' . $g['group_name'])?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Student</label>
      <select name="stu" onchange="this.form.submit()">
        <option value="0">All students</option>
        <?php foreach ($fStudents as $s): ?><option value="<?=(int)$s['user_id']?>"<?=$fStu === (int)$s['user_id'] ? ' selected' : ''?>><?=h($s['name'])?></option><?php endforeach; ?>
      </select></div>
    <?php if ($fSec || $fGrp || $fStu): ?><a class="btn ghost small" href="ledger.php">Clear filter</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<section class="block">
  <div style="text-align:center;margin-bottom:10px">
    <h2 style="margin-top:8px">GENERAL LEDGER</h2>
  </div>
<?php if (!$byStudent): ?>
  <div class="empty">No posted journal entries yet. Only entries where debit equals credit appear in the ledger.</div>
<?php else:
  foreach ($byStudent as $sid => $stu):
    $f  = $stu['info'];
    $hd = $headers[$sid] ?? null;
    $hCompany = $hd['company'] ?? '';
    $hReport  = $hd['report_title'] ?? '';
    $hPeriod  = $hd['period_end'] ?? ''; ?>
  <div style="text-align:left;margin:18px 0 6px;line-height:1.6">
    <div><b>Company name:</b> <?=h($hCompany !== '' ? $hCompany : '—')?></div>
    <div><b>Title of report:</b> <?=h($hReport !== '' ? $hReport : '—')?></div>
    <div><b>End of Month:</b> <?=h($hPeriod ? date('d/m/Y', strtotime($hPeriod)) : '—')?></div>
    <div><b>Name:</b> <?=h($f['name'])?></div>
    <div><b>Section:</b> <?=h(!empty($f['section_name']) ? $f['section_name'] : 'Not enlisted')?><?php if (!empty($f['group_name'])): ?> &nbsp;·&nbsp; <b>Group:</b> <?=h($f['group_name'])?><?php endif; ?></div>
  </div>
<?php
  table_open(['Account','Debit','Credit','Balance'], [0,1,1,1]);
  $grandD = 0.0; $grandC = 0.0; $n = 0;
  foreach ($stu['accounts'] as $acc):
    $n++;
    $ai = $acc['info']; $side = lg_normal($ai['account_type']);
    $sumD = 0.0; $sumC = 0.0; $bal = 0.0; ?>
    <tr><td colspan="4"><b><?=$n?>. <?=h($ai['account_name'])?></b></td></tr>
<?php foreach ($acc['lines'] as $l):
      $d = (float)$l['debit_amount']; $c = (float)$l['credit_amount'];
      $sumD += $d; $sumC += $c;
      $bal += ($side === 'Debit') ? ($d - $c) : ($c - $d);
      $balSide = $bal >= 0 ? $side : ($side === 'Debit' ? 'Credit' : 'Debit'); ?>
    <tr>
      <td style="padding-left:12px"><?=h(lg_title($l['description']))?></td>
      <td class="num"><?=$d > 0 ? h(lg_money($d)) : ''?></td>
      <td class="num"><?=$c > 0 ? h(lg_money($c)) : ''?></td>
      <td class="num"><?=h(lg_bal($bal, $balSide))?></td>
    </tr>
<?php endforeach;
    $grandD += $sumD; $grandC += $sumC;
    $endSide = $bal >= 0 ? $side : ($side === 'Debit' ? 'Credit' : 'Debit'); ?>
    <tr style="font-weight:700">
      <td style="text-align:right">Total</td>
      <td class="num"><?=h(lg_money($sumD))?></td>
      <td class="num"><?=h(lg_money($sumC))?></td>
      <td class="num"><?=h(lg_bal($bal, $endSide))?></td>
    </tr>
<?php endforeach; ?>
    <tr style="font-weight:700">
      <td style="text-align:right">TOTALS</td>
      <td class="num"><?=h(lg_money($grandD))?></td>
      <td class="num"><?=h(lg_money($grandC))?></td>
      <td></td>
    </tr>
<?php table_close(4);
  endforeach;
endif; ?>
</section>

<?php layout_end(); ?>
