<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role']; $uid = (int)$_SESSION['user']['user_id'];
$isStaff = in_array($role, ['Admin','Faculty'], true);
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); require_role(['Admin','Faculty']);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_program') {
            $code = trim($_POST['code'] ?? ''); $name = trim($_POST['name'] ?? '');
            if ($code === '' || $name === '') flash('warn', 'Enter a program code and name.');
            else {
                $pdo->prepare("INSERT INTO courses(course_code,course_name,professor_id) VALUES(?,?,?)")->execute([$code, $name, $uid]);
                audit($pdo, 'Created program'); flash('ok', 'Program added.');
            }
        } elseif ($action === 'edit_program') {
            $id = (int)($_POST['course_id'] ?? 0);
            $code = trim($_POST['code'] ?? ''); $name = trim($_POST['name'] ?? '');
            if ($code === '' || $name === '') flash('warn', 'Enter a program code and name.');
            else {
                $pdo->prepare("UPDATE courses SET course_code=?, course_name=? WHERE course_id=?")->execute([$code, $name, $id]);
                audit($pdo, 'Edited program'); flash('ok', 'Program updated.');
            }
        } elseif ($action === 'edit_section') {
            $id = (int)($_POST['section_id'] ?? 0); $name = trim($_POST['section_name'] ?? '');
            $r = $pdo->prepare("SELECT course_id FROM sections WHERE section_id=?"); $r->execute([$id]); $courseId = $r->fetchColumn();
            if ($courseId === false) flash('warn', 'Section not found.');
            elseif ($name === '') flash('warn', 'Enter the section name.');
            else {
                $d = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id=? AND section_name=? AND section_id<>?"); $d->execute([$courseId, $name, $id]);
                if ($d->fetchColumn() > 0) flash('warn', 'A section with that name already exists in this program.');
                else {
                    $pdo->prepare("UPDATE sections SET section_name=? WHERE section_id=?")->execute([$name, $id]);
                    audit($pdo, 'Edited section'); flash('ok', 'Section updated.');
                }
            }
        } elseif ($action === 'edit_class') {
            // Same fields as "New class". Enlisted students stay with the section; a section or subject that is used only
            // by this class is renamed in place, otherwise an existing one is reused or a new one is created.
            $sid = (int)($_POST['schedule_id'] ?? 0); $courseId = (int)($_POST['course_id'] ?? 0);
            $subCode = trim($_POST['subject_code'] ?? ''); $subName = trim($_POST['subject_name'] ?? '');
            $secName = trim($_POST['section_name'] ?? '');
            $day = $_POST['day'] ?? ''; $start = $_POST['start'] ?? ''; $end = $_POST['end'] ?? '';
            $timeOk = preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end);
            $c = $pdo->prepare("SELECT cs.subject_id, cs.section_id, sec.course_id FROM class_schedules cs JOIN sections sec ON sec.section_id=cs.section_id WHERE cs.schedule_id=?");
            $c->execute([$sid]); $cur = $c->fetch();
            $prog = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE course_id=?"); $prog->execute([$courseId]);
            if (!$cur) flash('warn', 'Class not found.');
            elseif (!$prog->fetchColumn()) flash('warn', 'Choose a program.');
            elseif ($subCode === '' || $subName === '' || $secName === '') flash('warn', 'Enter the subject code, subject name and section.');
            elseif (!in_array($day, $days, true) || !$timeOk) flash('warn', 'Choose a day and a start and end time.');
            elseif ($end <= $start) flash('warn', 'End time must be after the start time.');
            else {
                $oldSub = (int)$cur['subject_id']; $oldSec = (int)$cur['section_id']; $sameProg = $courseId === (int)$cur['course_id'];
                $pdo->beginTransaction();
                try {
                    // subject
                    $q = $pdo->prepare("SELECT subject_id FROM subjects WHERE course_id=? AND subject_code=?"); $q->execute([$courseId, $subCode]);
                    $subId = $q->fetchColumn();
                    if ($subId !== false) {
                        $subId = (int)$subId;
                        if ($subId === $oldSub) $pdo->prepare("UPDATE subjects SET subject_name=? WHERE subject_id=?")->execute([$subName, $subId]);
                    } else {
                        $u = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE subject_id=? AND schedule_id<>?"); $u->execute([$oldSub, $sid]);
                        if ($sameProg && $u->fetchColumn() == 0) {
                            $pdo->prepare("UPDATE subjects SET subject_code=?, subject_name=? WHERE subject_id=?")->execute([$subCode, $subName, $oldSub]);
                            $subId = $oldSub;
                        } else {
                            $pdo->prepare("INSERT INTO subjects(course_id,subject_code,subject_name) VALUES(?,?,?)")->execute([$courseId, $subCode, $subName]);
                            $subId = (int)$pdo->lastInsertId();
                        }
                    }
                    // section
                    $q = $pdo->prepare("SELECT section_id FROM sections WHERE course_id=? AND section_name=?"); $q->execute([$courseId, $secName]);
                    $secId = $q->fetchColumn();
                    if ($secId !== false) $secId = (int)$secId;
                    else {
                        $u = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE section_id=? AND schedule_id<>?"); $u->execute([$oldSec, $sid]);
                        if ($sameProg && $u->fetchColumn() == 0) {
                            $pdo->prepare("UPDATE sections SET section_name=? WHERE section_id=?")->execute([$secName, $oldSec]);
                            $secId = $oldSec;
                        } else {
                            $pdo->prepare("INSERT INTO sections(course_id,section_name) VALUES(?,?)")->execute([$courseId, $secName]);
                            $secId = (int)$pdo->lastInsertId();
                        }
                    }
                    $clash = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE section_id=? AND day_of_week=? AND start_time < ? AND end_time > ? AND schedule_id<>?");
                    $clash->execute([$secId, $day, $end, $start, $sid]);
                    if ($clash->fetchColumn() > 0) {
                        $pdo->rollBack();
                        flash('warn', 'That section already has a class overlapping this time on ' . $day . '.');
                    } else {
                        $pdo->prepare("UPDATE class_schedules SET subject_id=?, section_id=?, day_of_week=?, start_time=?, end_time=? WHERE schedule_id=?")
                            ->execute([$subId, $secId, $day, $start, $end, $sid]);
                        if ($subId !== $oldSub)  // tidy up a subject or section that is no longer used
                            $pdo->prepare("DELETE FROM subjects WHERE subject_id=? AND NOT EXISTS (SELECT 1 FROM class_schedules WHERE subject_id=?)")->execute([$oldSub, $oldSub]);
                        if ($secId !== $oldSec)
                            $pdo->prepare("DELETE FROM sections WHERE section_id=? AND NOT EXISTS (SELECT 1 FROM class_schedules WHERE section_id=?)
                                AND NOT EXISTS (SELECT 1 FROM enrollments WHERE section_id=?)")->execute([$oldSec, $oldSec, $oldSec]);
                        $pdo->commit();
                        audit($pdo, 'Edited class (subject, section and schedule)'); flash('ok', 'Class updated.');
                    }
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
        } elseif ($action === 'delete_program') {
            $id = (int)($_POST['course_id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $secIds = "SELECT section_id FROM sections WHERE course_id=?";
                $pdo->prepare("DELETE FROM class_schedules WHERE section_id IN ($secIds)")->execute([$id]);
                $pdo->prepare("DELETE FROM enrollments WHERE section_id IN ($secIds)")->execute([$id]);
                $pdo->prepare("DELETE FROM student_groups WHERE section_id IN ($secIds)")->execute([$id]);
                $pdo->prepare("DELETE FROM sections WHERE course_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM subjects WHERE course_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM courses WHERE course_id=?")->execute([$id]);
                $pdo->commit();
                audit($pdo, 'Deleted program'); flash('ok', 'Program deleted.');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('warn', 'Cannot delete this program — it still has learning modules, assignments or grades linked to it.');
            }
        } elseif ($action === 'add_class') {
            // One data entry: subject + section + schedule. Existing subjects/sections are reused, new ones are created.
            $courseId = (int)($_POST['course_id'] ?? 0);
            $subCode = trim($_POST['subject_code'] ?? ''); $subName = trim($_POST['subject_name'] ?? '');
            $secName = trim($_POST['section_name'] ?? '');
            $day = $_POST['day'] ?? ''; $start = $_POST['start'] ?? ''; $end = $_POST['end'] ?? '';
            $timeOk = preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end);
            $prog = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE course_id=?"); $prog->execute([$courseId]);
            if (!$prog->fetchColumn()) flash('warn', 'Choose a program.');
            elseif ($subCode === '' || $subName === '' || $secName === '') flash('warn', 'Enter the subject code, subject name and section.');
            elseif (!in_array($day, $days, true) || !$timeOk) flash('warn', 'Choose a day and a start and end time.');
            elseif ($end <= $start) flash('warn', 'End time must be after the start time.');
            else {
                $pdo->beginTransaction();
                try {
                    $q = $pdo->prepare("SELECT subject_id FROM subjects WHERE course_id=? AND subject_code=?"); $q->execute([$courseId, $subCode]);
                    $subId = $q->fetchColumn();
                    if ($subId === false) {
                        $pdo->prepare("INSERT INTO subjects(course_id,subject_code,subject_name) VALUES(?,?,?)")->execute([$courseId, $subCode, $subName]);
                        $subId = (int)$pdo->lastInsertId();
                    }
                    $q = $pdo->prepare("SELECT section_id FROM sections WHERE course_id=? AND section_name=?"); $q->execute([$courseId, $secName]);
                    $secId = $q->fetchColumn();
                    if ($secId === false) {
                        $pdo->prepare("INSERT INTO sections(course_id,section_name) VALUES(?,?)")->execute([$courseId, $secName]);
                        $secId = (int)$pdo->lastInsertId();
                    }
                    $clash = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE section_id=? AND day_of_week=? AND start_time < ? AND end_time > ?");
                    $clash->execute([$secId, $day, $end, $start]);
                    if ($clash->fetchColumn() > 0) {
                        $pdo->rollBack();
                        flash('warn', 'That section already has a class overlapping this time on ' . $day . '.');
                    } else {
                        $pdo->prepare("INSERT INTO class_schedules(subject_id,section_id,day_of_week,start_time,end_time) VALUES(?,?,?,?,?)")
                            ->execute([$subId, $secId, $day, $start, $end]);
                        $pdo->commit();
                        audit($pdo, 'Added class (subject, section and schedule)'); flash('ok', 'Class added.');
                    }
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
        } elseif ($action === 'delete_schedule') {
            $id = (int)$_POST['schedule_id'];
            $r = $pdo->prepare("SELECT subject_id,section_id FROM class_schedules WHERE schedule_id=?"); $r->execute([$id]); $row = $r->fetch();
            $pdo->prepare("DELETE FROM class_schedules WHERE schedule_id=?")->execute([$id]);
            if ($row) { // tidy up a subject or section that is no longer used
                $pdo->prepare("DELETE FROM subjects WHERE subject_id=? AND NOT EXISTS (SELECT 1 FROM class_schedules WHERE subject_id=?)")
                    ->execute([$row['subject_id'], $row['subject_id']]);
                $pdo->prepare("DELETE FROM sections WHERE section_id=?
                    AND NOT EXISTS (SELECT 1 FROM class_schedules WHERE section_id=?)
                    AND NOT EXISTS (SELECT 1 FROM enrollments WHERE section_id=?)")
                    ->execute([$row['section_id'], $row['section_id'], $row['section_id']]);
            }
            audit($pdo, 'Removed class schedule'); flash('ok', 'Class removed.');
        } elseif ($action === 'delete_section') {
            $id = (int)$_POST['section_id'];
            $pdo->prepare("DELETE FROM class_schedules WHERE section_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM enrollments WHERE section_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM student_groups WHERE section_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM sections WHERE section_id=?")->execute([$id]);
            audit($pdo, 'Removed section'); flash('ok', 'Section removed.');
        }
    } catch (PDOException $e) {
        flash('warn', $e->getCode() === '23000' ? 'That code or name already exists.' : 'Database error. Did you run migration_faculty_features.sql?');
    }
    header('Location: programs.php'); exit;
}

$programs = $pdo->query("SELECT c.*, u.name professor FROM courses c LEFT JOIN users u ON u.user_id=c.professor_id ORDER BY c.course_code")->fetchAll();
$classes = $subjList = $secList = [];
$mySection = null;
try {
    $dayOrder = "FIELD(cs.day_of_week,'" . implode("','", $days) . "')";
    $where = ''; $params = [];
    if ($role === 'Student') {
        $m = $pdo->prepare("SELECT e.section_id, sec.section_name FROM enrollments e JOIN sections sec ON sec.section_id=e.section_id WHERE e.student_id=?");
        $m->execute([$uid]); $mySection = $m->fetch();
        if ($mySection) { $where = 'WHERE sec.section_id=?'; $params = [$mySection['section_id']]; }
    }
    $st = $pdo->prepare("SELECT sec.section_id, sec.section_name, c.course_id, c.course_code, cs.schedule_id, cs.day_of_week, cs.start_time, cs.end_time,
        sub.subject_code, sub.subject_name, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=sec.section_id) cnt
        FROM sections sec JOIN courses c ON c.course_id=sec.course_id
        LEFT JOIN class_schedules cs ON cs.section_id=sec.section_id LEFT JOIN subjects sub ON sub.subject_id=cs.subject_id
        $where ORDER BY c.course_code, sec.section_name, $dayOrder, cs.start_time");
    $st->execute($params); $classes = $st->fetchAll();
    if ($isStaff) {
        $subjList = $pdo->query("SELECT subject_code, MIN(subject_name) subject_name FROM subjects GROUP BY subject_code ORDER BY subject_code")->fetchAll();
        $secList  = $pdo->query("SELECT DISTINCT section_name FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    flash('warn', 'Setup error: ' . $e->getMessage());
}

function t12($t) { return date('g:i A', strtotime($t)); }

layout_start('programs', 'Program Management', 'Programs and their classes: subject, section and schedule (day and time) in one entry.');
?>
<section class="block"><h2>Programs <span class="tag"><?=count($programs)?></span></h2>
<?php if ($isStaff): ?><div style="margin-bottom:10px"><button class="btn brass" onclick="document.getElementById('progDlg').showModal()">+ New program</button></div><?php endif;
table_open($isStaff ? ['Code','Program','Faculty',''] : ['Code','Program','Faculty']);
foreach ($programs as $p): ?><tr><td><?=h($p['course_code'])?></td><td><?=h($p['course_name'])?></td><td><?=h($p['professor'] ?? '—')?></td>
  <?php if ($isStaff): ?><td>
    <button type="button" class="btn ghost small" data-id="<?=(int)$p['course_id']?>" data-code="<?=h($p['course_code'])?>" data-name="<?=h($p['course_name'])?>" onclick="editProgram(this)">Edit</button>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this program with all its subjects, sections, schedules and enlisted students? This cannot be undone.')">
      <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_program">
      <input type="hidden" name="course_id" value="<?=(int)$p['course_id']?>"><button class="btn ghost small">Delete</button></form></td><?php endif; ?></tr><?php endforeach;
table_close($isStaff ? 4 : 3, !$programs); ?></section>

<section class="block"><h2><?=$mySection ? 'My class schedule — ' . h($mySection['section_name']) : 'Classes'?> <span class="tag"><?=count($classes)?></span></h2>
<?php if ($isStaff): ?><div style="margin-bottom:10px"><button class="btn brass" onclick="document.getElementById('classDlg').showModal()">+ New class</button></div><?php endif;
table_open($isStaff ? ['Program','Section','Subject','Day','Time','Students',''] : ['Program','Section','Subject','Day','Time'], $isStaff ? [0,0,0,0,0,1,0] : []);
foreach ($classes as $r): ?>
  <tr><td><?=h($r['course_code'])?></td><td><?=h($r['section_name'])?></td>
  <?php if ($r['schedule_id']): ?>
    <td><?=h($r['subject_code'] . ' — ' . $r['subject_name'])?></td><td><?=h($r['day_of_week'])?></td><td><?=h(t12($r['start_time']) . ' – ' . t12($r['end_time']))?></td>
  <?php else: ?><td colspan="3" class="muted small">No schedule yet</td><?php endif; ?>
  <?php if ($isStaff): ?><td class="num"><?=(int)$r['cnt']?></td><td>
    <?php if ($r['schedule_id']): ?>
    <button type="button" class="btn ghost small" data-sid="<?=(int)$r['schedule_id']?>" data-course="<?=(int)$r['course_id']?>"
      data-subcode="<?=h($r['subject_code'])?>" data-subname="<?=h($r['subject_name'])?>" data-section="<?=h($r['section_name'])?>"
      data-day="<?=h($r['day_of_week'])?>" data-start="<?=h(substr($r['start_time'], 0, 5))?>" data-end="<?=h(substr($r['end_time'], 0, 5))?>" onclick="editClass(this)">Edit</button>
    <?php else: ?>
    <button type="button" class="btn ghost small" data-secid="<?=(int)$r['section_id']?>" data-section="<?=h($r['section_name'])?>" onclick="editSection(this)">Edit</button>
    <?php endif; ?>
    <form method="post" style="display:inline" onsubmit="return confirm('<?=$r['schedule_id'] ? 'Delete this class?' : 'Delete this section and its enlisted students?'?>')">
      <input type="hidden" name="csrf" value="<?=h(csrf())?>">
      <?php if ($r['schedule_id']): ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="schedule_id" value="<?=(int)$r['schedule_id']?>">
      <?php else: ?><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?=(int)$r['section_id']?>"><?php endif; ?>
      <button class="btn ghost small">Delete</button></form></td><?php endif; ?></tr>
<?php endforeach; table_close($isStaff ? 7 : 5, !$classes); ?></section>

<?php if ($isStaff): ?>
<dialog id="progDlg" class="modal"><h3>New program</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_program">
  <div><label class="field-label">Program code</label><input name="code" required placeholder="e.g. BSAIS"></div>
  <div><label class="field-label">Program name</label><input name="name" required></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Add program</button></div></form></dialog>

<dialog id="editProgDlg" class="modal"><h3>Edit program</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="edit_program"><input type="hidden" name="course_id" id="epId">
  <div><label class="field-label">Program code</label><input name="code" id="epCode" required></div>
  <div><label class="field-label">Program name</label><input name="name" id="epName" required></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save changes</button></div></form></dialog>

<dialog id="editSecDlg" class="modal"><h3>Edit section</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="edit_section"><input type="hidden" name="section_id" id="esId">
  <div><label class="field-label">Section name</label><input name="section_name" id="esName" required></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save changes</button></div></form></dialog>

<dialog id="editClassDlg" class="modal"><h3>Edit class</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="edit_class"><input type="hidden" name="schedule_id" id="ecId">
  <div><label class="field-label">Program</label><select name="course_id" id="ecCourse" required>
    <?php foreach ($programs as $p): ?><option value="<?=(int)$p['course_id']?>"><?=h($p['course_code'] . ' — ' . $p['course_name'])?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Subject code</label><input name="subject_code" id="ecSubCode" list="subCodes" required autocomplete="off"></div>
  <div><label class="field-label">Subject name</label><input name="subject_name" id="ecSubName" required></div>
  <div><label class="field-label">Section</label><input name="section_name" id="ecSection" list="secNames" required autocomplete="off"></div>
  <div><label class="field-label">Day</label><select name="day" id="ecDay"><?php foreach ($days as $d): ?><option><?=h($d)?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Start time</label><input type="time" name="start" id="ecStart" required></div>
  <div><label class="field-label">End time</label><input type="time" name="end" id="ecEnd" required></div>
  <p class="small muted" style="margin:0">Students already enlisted stay with their section. Changing a section's name here renames it if no other class uses it.</p>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save changes</button></div></form></dialog>
<script>
function editProgram(b){
  document.getElementById('epId').value = b.dataset.id; document.getElementById('epCode').value = b.dataset.code; document.getElementById('epName').value = b.dataset.name;
  document.getElementById('editProgDlg').showModal();
}
function editSection(b){
  document.getElementById('esId').value = b.dataset.secid; document.getElementById('esName').value = b.dataset.section;
  document.getElementById('editSecDlg').showModal();
}
function editClass(b){
  var d = b.dataset;
  document.getElementById('ecId').value = d.sid; document.getElementById('ecCourse').value = d.course;
  document.getElementById('ecSubCode').value = d.subcode; document.getElementById('ecSubName').value = d.subname;
  document.getElementById('ecSection').value = d.section; document.getElementById('ecDay').value = d.day;
  document.getElementById('ecStart').value = d.start; document.getElementById('ecEnd').value = d.end;
  document.getElementById('editClassDlg').showModal();
}
</script>

<dialog id="classDlg" class="modal"><h3>New class</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_class">
  <div><label class="field-label">Program</label><select name="course_id" required>
    <?php foreach ($programs as $p): ?><option value="<?=(int)$p['course_id']?>"><?=h($p['course_code'] . ' — ' . $p['course_name'])?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Subject code</label><input name="subject_code" id="subCode" list="subCodes" required placeholder="e.g. FABM1" autocomplete="off"></div>
  <div><label class="field-label">Subject name</label><input name="subject_name" id="subName" required placeholder="e.g. Fundamentals of Accountancy"></div>
  <div><label class="field-label">Section</label><input name="section_name" list="secNames" required placeholder="e.g. BSAIS 1-A" autocomplete="off"></div>
  <div><label class="field-label">Day</label><select name="day"><?php foreach ($days as $d): ?><option><?=h($d)?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Start time</label><input type="time" name="start" required></div>
  <div><label class="field-label">End time</label><input type="time" name="end" required></div>
  <datalist id="subCodes"><?php foreach ($subjList as $s): ?><option value="<?=h($s['subject_code'])?>"><?=h($s['subject_name'])?></option><?php endforeach; ?></datalist>
  <datalist id="secNames"><?php foreach ($secList as $n): ?><option value="<?=h($n)?>"></option><?php endforeach; ?></datalist>
  <p class="small muted" style="margin:0">Existing subjects and sections are reused; new ones are created automatically.</p>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Add class</button></div></form></dialog>
<script>
var subjNames = <?=json_encode(array_column($subjList, 'subject_name', 'subject_code'), JSON_UNESCAPED_UNICODE)?>;
document.getElementById('subCode').addEventListener('change', function(){
  var n = subjNames[this.value]; if (n && !document.getElementById('subName').value) document.getElementById('subName').value = n;
});
</script>
<?php endif; layout_end(); ?>