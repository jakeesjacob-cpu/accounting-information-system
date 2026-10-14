<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role']; $uid = (int)$_SESSION['user']['user_id'];
$isStaff = in_array($role, ['Admin','Faculty'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); require_role(['Admin','Faculty']);
    $score = (float)($_POST['score'] ?? -1); $max = (float)($_POST['max_score'] ?? 0);
    if ($score < 0 || $max <= 0 || $score > $max) flash('warn', 'Enter a score between 0 and the maximum.');
    else {
        $pdo->prepare("INSERT INTO grades(student_id,course_id,assignment_id,score,max_score,date_recorded) VALUES(?,?,?,?,?,CURDATE())")
            ->execute([(int)$_POST['student_id'], (int)$_POST['course_id'], ($_POST['assignment_id'] ?? '') !== '' ? (int)$_POST['assignment_id'] : null, $score, $max]);
        audit($pdo, 'Recorded a grade'); flash('ok', 'Grade recorded.');
    }
    header('Location: grades.php'); exit;
}
$sql = "SELECT g.*, u.name, COALESCE(a.title, s.title, 'Recorded grade') label, c.course_code
        FROM grades g JOIN users u ON u.user_id=g.student_id JOIN courses c ON c.course_id=g.course_id
        LEFT JOIN assignments a ON a.assignment_id=g.assignment_id LEFT JOIN assessments s ON s.assessment_id=g.assessment_id"
        . ($isStaff ? "" : " WHERE g.student_id=?") . " ORDER BY g.grade_id DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($isStaff ? [] : [$uid]); $grades = $stmt->fetchAll();
if ($isStaff) {
    $students = $pdo->query("SELECT user_id,name FROM users WHERE role='Student' ORDER BY name")->fetchAll();
    $courses = $pdo->query("SELECT course_id,course_code FROM courses ORDER BY course_code")->fetchAll();
    $assignments = $pdo->query("SELECT a.assignment_id,a.title,c.course_code FROM assignments a JOIN courses c ON c.course_id=a.course_id ORDER BY a.assignment_id DESC")->fetchAll();
}
layout_start('grades', 'Grades', 'Consolidated assessment and assignment results.');
if ($isStaff): ?><div style="margin-bottom:12px"><button class="btn brass" onclick="document.getElementById('gradeDlg').showModal()">+ Record grade</button></div><?php endif;
table_open($isStaff ? ['Student','Course','Item','Score','Percent'] : ['Course','Item','Score','Percent'], $isStaff ? [0,0,0,1,1] : [0,0,1,1]);
foreach ($grades as $g): $max = (float)$g['max_score']; ?>
  <tr><?php if ($isStaff): ?><td><?=h($g['name'])?></td><?php endif; ?><td><?=h($g['course_code'])?></td><td><?=h($g['label'])?></td><td class="num"><?=h(($g['score'] + 0) . ' / ' . ($max + 0))?></td><td class="num"><?=$max > 0 ? round($g['score'] / $max * 100) . '%' : '—'?></td></tr>
<?php endforeach; table_close($isStaff ? 5 : 4, !$grades);
if ($isStaff): ?>
<dialog id="gradeDlg" class="modal"><h3>Record grade</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <div><label class="field-label">Student</label><select name="student_id"><?php foreach ($students as $s): ?><option value="<?=(int)$s['user_id']?>"><?=h($s['name'])?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Course</label><select name="course_id"><?php foreach ($courses as $c): ?><option value="<?=(int)$c['course_id']?>"><?=h($c['course_code'])?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Assignment (optional)</label><select name="assignment_id"><option value="">— none —</option><?php foreach ($assignments as $a): ?><option value="<?=(int)$a['assignment_id']?>"><?=h($a['course_code'] . ' - ' . $a['title'])?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Score</label><input type="number" name="score" step="0.01" min="0" required></div>
  <div><label class="field-label">Maximum score</label><input type="number" name="max_score" step="0.01" min="1" value="100" required></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save</button></div>
</form></dialog>
<?php endif; layout_end(); ?>
