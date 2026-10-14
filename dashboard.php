<?php
require 'config.php'; require 'layout.php'; require_login();
$role  = $_SESSION['user']['role'];
$scope = $role === 'Student' ? (int)$_SESSION['user']['user_id'] : null;   // students see only their own entries/grades

$stmt = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(ai_validation_status='Valid'),0) ok FROM journal_entries" . ($scope ? " WHERE student_id=?" : ""));
$stmt->execute($scope ? [$scope] : []);
$cnt = $stmt->fetch();
$total = (int)$cnt['total']; $balanced = (int)$cnt['ok']; $unbalanced = $total - $balanced;
$totalAssets = sum_by_type(account_balances($pdo), 'Asset');
$recent = recent_journal_entries($pdo, 5, $scope);

if ($role !== 'Admin') {
    $stmt = $pdo->prepare("SELECT g.*, COALESCE(a.title, s.title, 'Recorded grade') label
        FROM grades g LEFT JOIN assignments a ON a.assignment_id=g.assignment_id LEFT JOIN assessments s ON s.assessment_id=g.assessment_id"
        . ($scope ? " WHERE g.student_id=?" : "") . " ORDER BY g.grade_id DESC LIMIT 10");
    $stmt->execute($scope ? [$scope] : []);
    $grades = $stmt->fetchAll();
    $pcts = [];
    foreach ($grades as $g) if ((float)$g['max_score'] > 0) $pcts[] = $g['score'] / $g['max_score'] * 100;
    $avg = $pcts ? array_sum($pcts) / count($pcts) : null;
} else {
    $roleCounts = $pdo->query("SELECT role, COUNT(*) FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
}

layout_start('dashboard', 'Dashboard',
    'Signed in as ' . $_SESSION['user']['name'] . ' (' . role_label($role) . '). Overview of the Accounting Information System and Learning Management System modules.');
?>
<div class="grid cols-4">
<?php stat_card('Journal entries', $total); stat_card('Balanced entries', $balanced, 'ok');
      stat_card('Unbalanced entries', $unbalanced, $unbalanced ? 'warn' : ''); stat_card('Total assets on record', peso($totalAssets)); ?>
</div>

<section class="block" style="margin-top:30px"><h2>Recent journal activity</h2>
<?php if ($recent) journal_mini($recent); else echo '<div class="empty">No journal entries recorded yet.</div>'; ?>
</section>

<?php if ($role !== 'Admin'): ?>
<section class="block"><h2>Coursework snapshot <?php if ($avg !== null): ?><span class="tag">avg score <?=round($avg)?>%</span><?php endif; ?></h2>
<?php if ($grades): table_open(['Item','Score','Percent'], [0,1,1]);
      foreach ($grades as $g): $max = (float)$g['max_score']; ?>
  <tr><td><?=h($g['label'])?></td><td class="num"><?=h(($g['score'] + 0) . ' / ' . ($max + 0))?></td><td class="num"><?=$max > 0 ? round($g['score'] / $max * 100) . '%' : '—'?></td></tr>
<?php endforeach; table_close(3); else: ?><div class="empty">No grade records yet.</div><?php endif; ?>
</section>
<?php else: ?>
<section class="block"><h2>System users</h2>
<div class="grid cols-3">
<?php foreach (['Admin'=>'Administrators','Faculty'=>'Faculty','Student'=>'Students'] as $r => $label) stat_card($label, (int)($roleCounts[$r] ?? 0)); ?>
</div></section>
<?php endif; ?>
<?php layout_end(); ?>
