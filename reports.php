<?php
require 'config.php'; require 'layout.php'; require_login();
$v = $_GET['v'] ?? 'ledger';
if (!in_array($v, ['ledger','trial','financial'], true)) $v = 'ledger';
$balances = account_balances($pdo);

if ($v === 'trial') {
    $tb = []; $totD = $totC = 0;
    foreach ($balances as $b) {
        if (abs($b['balance']) < 0.005) continue;
        $debitCol = (($b['debit_normal'] && $b['balance'] >= 0) || (!$b['debit_normal'] && $b['balance'] < 0)) ? abs($b['balance']) : 0;
        $creditCol = $debitCol ? 0 : abs($b['balance']);
        $totD += $debitCol; $totC += $creditCol;
        $tb[] = ['code'=>$b['account_code'], 'name'=>$b['account_name'], 'debit'=>$debitCol, 'credit'=>$creditCol];
    }
    if (isset($_GET['export'])) {
        // Export laid out like the General Journal template: heading labels, column labels, rows, totals.
        $company = $_SESSION['je_company'] ?? '';
        $report  = $_SESSION['je_report']  ?? 'Chart of Accounts';
        $period  = $_SESSION['je_period']  ?? date('Y-m-t');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) $period = date('Y-m-t');
        $head = [
            $company !== '' ? $company : 'Company name',
            $report !== '' ? $report : 'Chart of Accounts',
            'End of Month: ' . date('d/m/Y', strtotime($period)),
        ];
        $title  = 'TRIAL BALANCE';
        $labels = ['Date', 'Account Titles and Explanation', 'Debit (₱)', 'Credit (₱)'];
        $status = abs($totD - $totC) < 0.005 ? 'Balanced — total debits equal total credits.' : 'Not balanced — debits and credits differ.';
        $dateTxt = date('d/m/y', strtotime($period));

        if (true) { // single export: aligned, Excel-readable file
            // Excel-readable table: centered headings, bordered cells, auto column widths, right-aligned amounts.
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="trial_balance_' . date('Ymd') . '.xls"');
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8">'
               . '<style>td,th{font-family:Calibri,Arial,sans-serif;font-size:11pt;vertical-align:middle}'
               . '.c{text-align:center;font-weight:bold;border:none}.th{text-align:center;font-weight:bold;background:#D9D9D9;border:.5pt solid #000}'
               . '.t{border:.5pt solid #000}.n{border:.5pt solid #000;text-align:right;mso-number-format:"\#\,\#\#0\.00"}'
               . '.b{font-weight:bold}</style></head><body><table style="border-collapse:collapse">'
               . '<col width="110"><col width="320"><col width="130"><col width="130">';
            foreach ($head as $hline) echo '<tr><td colspan="4" class="c">' . h($hline) . '</td></tr>';
            echo '<tr><td colspan="4" class="c">&nbsp;</td></tr><tr><td colspan="4" class="c">' . h($title) . '</td></tr>';
            echo '<tr>'; foreach ($labels as $l) echo '<th class="th">' . h($l) . '</th>'; echo '</tr>';
            $first = true;
            foreach ($tb as $r) {
                echo '<tr><td class="t">' . ($first ? h($dateTxt) : '') . '</td>'
                   . '<td class="t" style="padding-left:' . ($r['credit'] > 0 ? '28' : '6') . 'px">' . h($r['name']) . '</td>'
                   . '<td class="n">' . ($r['debit']  > 0 ? number_format($r['debit'], 2, '.', '')  : '') . '</td>'
                   . '<td class="n">' . ($r['credit'] > 0 ? number_format($r['credit'], 2, '.', '') : '') . '</td></tr>';
                $first = false;
            }
            echo '<tr><td class="t"></td><td class="t b" style="text-align:right">Total</td>'
               . '<td class="n b">' . number_format($totD, 2, '.', '') . '</td><td class="n b">' . number_format($totC, 2, '.', '') . '</td></tr>';
            echo '<tr><td colspan="4" class="c" style="font-weight:normal">' . h($status) . '</td></tr>';
            echo '</table></body></html>';
            audit($pdo, 'Exported trial balance'); exit;
        }

    }
    $company = $_SESSION['je_company'] ?? '';
    $period  = $_SESSION['je_period']  ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) $period = date('Y-m-t');
    layout_start('trial', 'Trial Balance', 'Summarized account balances, verifying that total debits equal total credits.'); ?>
<div style="text-align:center;margin-bottom:10px">
  <b><?=h($company !== '' ? $company : 'Company name')?></b><br>
  <b>Trial Balance</b><br>
  <span class="small muted">End of Month: <?=h(date('d/m/Y', strtotime($period)))?></span>
</div>
<?php table_open(['Date','Account Titles and Explanation','Debit (₱)','Credit (₱)'], [0,0,1,1]);
    $first = true;
    foreach ($tb as $r): ?>
  <tr>
    <td><?=$first ? '<b>' . h(date('d/m/y', strtotime($period))) . '</b>' : ''?></td>
    <td style="padding-left:<?=$r['credit'] > 0 ? '36' : '12'?>px"><?=h($r['name'])?></td>
    <td class="num"><?=$r['debit'] ? h(peso($r['debit'])) : ''?></td>
    <td class="num"><?=$r['credit'] ? h(peso($r['credit'])) : ''?></td>
  </tr>
<?php $first = false; endforeach; ?>
  <tr>
    <td></td><td style="text-align:right"><b>Total</b></td>
    <td class="num"><b><?=h(peso($totD))?></b></td><td class="num"><b><?=h(peso($totC))?></b></td>
  </tr>
<?php table_close(4, !$tb); ?>
<div class="je-totals" style="margin-top:14px">
  <?=pill(abs($totD - $totC) < 0.005 ? 'Valid' : 'Invalid')?>
  <a class="btn ghost small" href="reports.php?v=trial&export=1" style="margin-left:auto">Export</a>
</div>
<?php layout_end(); exit; }

if ($v === 'financial') {
    $revenue = sum_by_type($balances, 'Revenue'); $expense = sum_by_type($balances, 'Expense');
    $net = $revenue - $expense;
    $assets = sum_by_type($balances, 'Asset'); $liab = sum_by_type($balances, 'Liability');
    $equity = sum_by_type($balances, 'Equity') + $net;
    layout_start('reports', 'Financial Reports', 'Income statement and balance sheet, generated from posted (balanced) journal entries.'); ?>
<section class="block"><h2>Income Statement</h2><div class="card">
  <div class="flex-between"><span>Total revenue</span><b class="num"><?=h(peso($revenue))?></b></div>
  <div class="flex-between"><span>Total expenses</span><b class="num"><?=h(peso($expense))?></b></div>
  <hr class="rule"><div class="flex-between"><b>Net income</b><b class="num" style="color:var(<?=$net >= 0 ? '--green-ok' : '--rust'?>)"><?=h(peso($net))?></b></div>
</div></section>
<section class="block"><h2>Balance Sheet</h2><div class="grid cols-2">
  <div class="card"><h3 style="font-size:.95rem">Assets</h3>
    <?php foreach ($balances as $b) if ($b['account_type'] === 'Asset'): ?><div class="flex-between small"><span><?=h($b['account_name'])?></span><span class="num"><?=h(peso($b['balance']))?></span></div><?php endif; ?>
    <hr class="rule"><div class="flex-between"><b>Total assets</b><b class="num"><?=h(peso($assets))?></b></div></div>
  <div class="card"><h3 style="font-size:.95rem">Liabilities &amp; Equity</h3>
    <?php foreach ($balances as $b) if ($b['account_type'] === 'Liability'): ?><div class="flex-between small"><span><?=h($b['account_name'])?></span><span class="num"><?=h(peso($b['balance']))?></span></div><?php endif; ?>
    <div class="flex-between small"><span>Owner's equity (incl. net income)</span><span class="num"><?=h(peso($equity))?></span></div>
    <hr class="rule"><div class="flex-between"><b>Total liabilities &amp; equity</b><b class="num"><?=h(peso($liab + $equity))?></b></div></div>
</div></section>
<?php layout_end(); exit; }

// ---- General ledger ----
$rows = $pdo->query("SELECT a.account_id, j.entry_date, j.description, l.debit_amount, l.credit_amount
    FROM journal_lines l JOIN journal_entries j ON j.entry_id=l.entry_id JOIN chart_of_accounts a ON a.account_id=l.account_id
    WHERE j.ai_validation_status='Valid' ORDER BY j.entry_date, j.entry_id, l.line_id")->fetchAll();
$byAcc = [];
foreach ($rows as $r) if ($r['debit_amount'] > 0 || $r['credit_amount'] > 0) $byAcc[$r['account_id']][] = $r;
layout_start('ledger', 'General Ledger', 'Balanced journal entries posted automatically to each account.');
$any = false;
foreach ($balances as $a):
    if (empty($byAcc[$a['account_id']])) continue; $any = true; $running = 0; ?>
<section class="block"><h2><?=h($a['account_code'] . ' · ' . $a['account_name'])?> <span class="tag"><?=h($a['account_type'])?></span></h2>
<?php table_open(['Date','Memo','Debit','Credit','Balance'], [0,0,1,1,1]);
      foreach ($byAcc[$a['account_id']] as $l):
          $running += $a['debit_normal'] ? $l['debit_amount'] - $l['credit_amount'] : $l['credit_amount'] - $l['debit_amount']; ?>
  <tr><td><?=h($l['entry_date'])?></td><td><?=h($l['description'])?></td><td class="num"><?=$l['debit_amount'] > 0 ? h(peso($l['debit_amount'])) : ''?></td><td class="num"><?=$l['credit_amount'] > 0 ? h(peso($l['credit_amount'])) : ''?></td><td class="num"><?=h(peso($running))?></td></tr>
<?php endforeach; table_close(5); ?></section>
<?php endforeach;
if (!$any) echo '<div class="empty">No balanced journal entries have been posted yet.</div>';
layout_end();