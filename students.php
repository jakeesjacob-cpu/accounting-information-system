<?php
require 'config.php'; require 'layout.php'; require_role(['Admin','Faculty']);
$secFilter = (int)($_GET['sec'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $back = (int)($_POST['sec'] ?? 0);
    try {
        if ($action === 'enlist') {
            $secId = (int)($_POST['section_id'] ?? 0);
            $ids = array_unique(array_map('intval', (array)($_POST['student_ids'] ?? [])));
            $exists = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE section_id=?"); $exists->execute([$secId]);
            if (!$exists->fetchColumn()) flash('warn', 'Choose a section.');
            elseif (!$ids) flash('warn', 'Select at least one student to enlist.');
            else {
                // Works with or without a UNIQUE key on enrollments.student_id:
                // new student -> INSERT, already in another section -> move (group is cleared), same section -> skip.
                $isStudent = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id=? AND role='Student'");
                $cur = $pdo->prepare("SELECT enrollment_id, section_id FROM enrollments WHERE student_id=? LIMIT 1");
                // Older databases have enrollments.course_id (foreign key to courses). If it exists, fill it from the section's program.
                $hasCourseCol = (bool)$pdo->query("SHOW COLUMNS FROM enrollments LIKE 'course_id'")->fetch();
                $secCourse = $pdo->prepare("SELECT course_id FROM sections WHERE section_id=?"); $secCourse->execute([$secId]);
                $courseId = (int)$secCourse->fetchColumn();
                if ($hasCourseCol) {
                    $ins = $pdo->prepare("INSERT INTO enrollments(student_id,section_id,course_id) VALUES(?,?,$courseId)");
                    $mv  = $pdo->prepare("UPDATE enrollments SET section_id=?, course_id=$courseId, group_id=NULL WHERE enrollment_id=?");
                } else {
                    $ins = $pdo->prepare("INSERT INTO enrollments(student_id,section_id) VALUES(?,?)");
                    $mv  = $pdo->prepare("UPDATE enrollments SET section_id=?, group_id=NULL WHERE enrollment_id=?");
                }
                $n = 0; $moved = 0;
                foreach ($ids as $sid) {
                    if ($sid <= 0) continue;
                    $isStudent->execute([$sid]);
                    if (!$isStudent->fetchColumn()) continue;
                    $cur->execute([$sid]); $row = $cur->fetch(); $cur->closeCursor();
                    if (!$row) { $ins->execute([$sid, $secId]); $n++; }
                    elseif ((int)$row['section_id'] !== $secId) { $mv->execute([$secId, $row['enrollment_id']]); $n++; $moved++; }
                }
                audit($pdo, "Enlisted $n student(s) to a section");
                if ($n === 0) flash('warn', 'The selected students are already in that section.');
                else flash('ok', "$n student(s) enlisted" . ($moved ? " ($moved moved from another section)." : '.'));
                $back = $secId;
            }
        } elseif ($action === 'unenlist') {
            $pdo->prepare("DELETE FROM enrollments WHERE enrollment_id=?")->execute([(int)($_POST['enrollment_id'] ?? 0)]);
            audit($pdo, 'Removed a student from a section'); flash('ok', 'Student removed from the section.');
        } elseif ($action === 'unenlist_many') {
            $ids = array_filter(array_map('intval', (array)($_POST['enrollment_ids'] ?? [])));
            if (!$ids) flash('warn', 'Tick at least one student to remove.');
            else {
                $in = implode(',', $ids);
                $pdo->exec("DELETE FROM enrollments WHERE enrollment_id IN ($in)");
                audit($pdo, 'Removed ' . count($ids) . ' student(s) from sections'); flash('ok', count($ids) . ' student(s) removed from their section.');
            }
        } elseif ($action === 'add_group') {
            $secId = (int)($_POST['section_id'] ?? 0); $name = trim($_POST['group_name'] ?? '');
            if ($secId <= 0 || $name === '') flash('warn', 'Choose a section and enter a group name.');
            else {
                $pdo->prepare("INSERT INTO student_groups(section_id,group_name) VALUES(?,?)")->execute([$secId, $name]);
                audit($pdo, 'Created student group'); flash('ok', 'Group created.');
                $back = $secId;
            }
        } elseif ($action === 'assign_group') {
            $eid = (int)($_POST['enrollment_id'] ?? 0); $gid = (int)($_POST['group_id'] ?? 0);
            $e = $pdo->prepare("SELECT section_id FROM enrollments WHERE enrollment_id=?"); $e->execute([$eid]); $esec = $e->fetchColumn();
            if ($esec === false) flash('warn', 'Student not found.');
            elseif ($gid === 0) {
                $pdo->prepare("UPDATE enrollments SET group_id=NULL WHERE enrollment_id=?")->execute([$eid]);
                audit($pdo, 'Removed student from group'); flash('ok', 'Group cleared.');
            } else {
                $g = $pdo->prepare("SELECT section_id FROM student_groups WHERE group_id=?"); $g->execute([$gid]);
                if ($g->fetchColumn() != $esec) flash('warn', 'That group belongs to a different section.');
                else {
                    $pdo->prepare("UPDATE enrollments SET group_id=? WHERE enrollment_id=?")->execute([$gid, $eid]);
                    audit($pdo, 'Assigned student to group'); flash('ok', 'Group updated.');
                }
            }
        } elseif ($action === 'delete_group') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $pdo->prepare("UPDATE enrollments SET group_id=NULL WHERE group_id=?")->execute([$gid]);
            $pdo->prepare("DELETE FROM student_groups WHERE group_id=?")->execute([$gid]);
            audit($pdo, 'Deleted student group'); flash('ok', 'Group deleted.');
        }
    } catch (PDOException $e) {
        flash('warn', ($e->getCode() === '23000' && $action === 'add_group') ? 'That group name already exists in this section.' : 'Database error: ' . $e->getMessage());
    }
    header('Location: students.php' . ($back ? '?sec=' . $back : '')); exit;
}

$sections = $candidates = $enr = $groups = [];
try {
    $sections = $pdo->query("SELECT s.*, c.course_code FROM sections s JOIN courses c ON c.course_id=s.course_id ORDER BY c.course_code, s.section_name")->fetchAll();
    // Every active student, not-yet-enlisted first; enlisted ones show their current section (and can be moved).
    $candidates = $pdo->query("SELECT u.user_id, u.name, sec.section_name, e.enrollment_id
        FROM users u LEFT JOIN enrollments e ON e.student_id=u.user_id LEFT JOIN sections sec ON sec.section_id=e.section_id
        WHERE u.role='Student' AND COALESCE(u.status,'Active')='Active'
        ORDER BY (e.enrollment_id IS NOT NULL), u.name")->fetchAll();
    $st = $pdo->prepare("SELECT e.*, u.name, u.email, sec.section_name, c.course_code, g.group_name
        FROM enrollments e JOIN users u ON u.user_id=e.student_id JOIN sections sec ON sec.section_id=e.section_id
        JOIN courses c ON c.course_id=sec.course_id LEFT JOIN student_groups g ON g.group_id=e.group_id
        WHERE (? = 0 OR e.section_id = ?) ORDER BY c.course_code, sec.section_name, g.group_name, u.name");
    $st->execute([$secFilter, $secFilter]); $enr = $st->fetchAll();
    $gs = $pdo->prepare("SELECT g.*, sec.section_name, (SELECT COUNT(*) FROM enrollments e WHERE e.group_id=g.group_id) cnt
        FROM student_groups g JOIN sections sec ON sec.section_id=g.section_id
        WHERE (? = 0 OR g.section_id = ?) ORDER BY sec.section_name, g.group_name");
    $gs->execute([$secFilter, $secFilter]); $groups = $gs->fetchAll();
} catch (PDOException $e) {
   flash('warn', 'Setup error: ' . $e->getMessage());
}
$groupsBySec = [];
foreach ($groups as $g) $groupsBySec[$g['section_id']][] = $g;

layout_start('students', 'Student Management', 'Enlist students into sections and organize them into groups.');
?>
<div class="flex-between" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <label class="field-label" style="margin:0">Section</label>
    <select name="sec" onchange="this.form.submit()" style="max-width:260px">
      <option value="0">All sections</option>
      <?php foreach ($sections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$secFilter === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?>
    </select>
  </form>
  <span>
    <button class="btn brass" onclick="document.getElementById('enlistDlg').showModal()">+ Enlist students</button>
    <button class="btn brass" onclick="document.getElementById('groupDlg').showModal()">+ New group</button>
  </span>
</div>
<?php if (!$sections): ?><div class="banner warn">No sections yet. Create sections in Program Management first.</div><?php endif; ?>

<section class="block"><h2>Groups <span class="tag"><?=count($groups)?></span></h2>
<?php if (!$groups): ?><div class="empty">No groups yet.</div><?php endif; ?>
<div class="card-stack">
<?php foreach ($groups as $g): ?>
  <div class="card"><div class="flex-between"><div><b><?=h($g['group_name'])?></b> <span class="small muted">· <?=h($g['section_name'])?> · <?=(int)$g['cnt']?> member(s)</span></div>
    <form method="post" onsubmit="return confirm('Delete this group? Members stay enlisted but become ungrouped.')">
      <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_group">
      <input type="hidden" name="group_id" value="<?=(int)$g['group_id']?>"><input type="hidden" name="sec" value="<?=$secFilter?>">
      <button class="btn ghost small">Delete group</button></form></div></div>
<?php endforeach; ?>
</div></section>

<section class="block"><h2>Enlisted students <span class="tag"><?=count($enr)?></span></h2>
<form id="bulkForm" method="post" style="margin-bottom:10px" onsubmit="return confirm('Remove the ticked students from their sections?')">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="unenlist_many"><input type="hidden" name="sec" value="<?=$secFilter?>">
  <button type="button" class="btn ghost small" onclick="document.querySelectorAll('.enrBox').forEach(function(b){ b.checked = true; })">Select all</button>
  <button type="button" class="btn ghost small" onclick="document.querySelectorAll('.enrBox').forEach(function(b){ b.checked = false; })">Clear</button>
  <button class="btn ghost small">Remove selected</button>
</form>
<?php table_open(['','Student','Email','Section','Group','Action']);
foreach ($enr as $e): ?>
  <tr><td style="width:36px"><input type="checkbox" class="enrBox" form="bulkForm" name="enrollment_ids[]" value="<?=(int)$e['enrollment_id']?>" style="width:auto"></td><td><?=h($e['name'])?></td><td class="small"><?=h($e['email'])?></td><td><?=h($e['section_name'])?> <span class="small muted"><?=h($e['course_code'])?></span></td>
  <td><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="assign_group">
    <input type="hidden" name="enrollment_id" value="<?=(int)$e['enrollment_id']?>"><input type="hidden" name="sec" value="<?=$secFilter?>">
    <select name="group_id" onchange="this.form.submit()">
      <option value="0">— no group —</option>
      <?php foreach ($groupsBySec[$e['section_id']] ?? [] as $g): ?><option value="<?=(int)$g['group_id']?>"<?=(int)$e['group_id'] === (int)$g['group_id'] ? ' selected' : ''?>><?=h($g['group_name'])?></option><?php endforeach; ?>
    </select></form></td>
  <td><form method="post" onsubmit="return confirm('Remove this student from the section?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="unenlist">
    <input type="hidden" name="enrollment_id" value="<?=(int)$e['enrollment_id']?>"><input type="hidden" name="sec" value="<?=$secFilter?>"><button class="btn ghost small">Remove</button></form></td></tr>
<?php endforeach; table_close(6, !$enr); ?></section>

<dialog id="enlistDlg" class="modal"><h3>Enlist students</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="enlist">
  <div><label class="field-label">Section</label><select name="section_id" required>
    <?php foreach ($sections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$secFilter === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?></select></div>
  <?php if (!$sections): ?><div class="banner warn">No sections yet. Add a class (with a section) in Program Management first.</div><?php endif; ?>
  <div><label class="field-label">Students</label>
    <input type="search" id="enlistSearch" placeholder="Search students..." autocomplete="off" style="margin-bottom:6px">
    <div style="margin-bottom:6px"><button type="button" class="btn ghost small" onclick="enlistPick(true)">Select shown</button> <button type="button" class="btn ghost small" onclick="enlistPick(false)">Clear</button></div>
    <div style="max-height:220px;overflow:auto;border:1px solid var(--border);border-radius:6px;padding:8px">
    <?php foreach ($candidates as $u): ?><label class="enlistRow" data-name="<?=h(strtolower($u['name']))?>" style="display:block;padding:3px 0"><input type="checkbox" name="student_ids[]" value="<?=(int)$u['user_id']?>" style="width:auto"> <?=h($u['name'])?><?php if ($u['enrollment_id']): ?> <span class="muted small">— now in <?=h($u['section_name'])?> (will be moved)</span><?php endif; ?></label><?php endforeach; ?>
    <?php if (!$candidates): ?><span class="muted small">No active student accounts. Add students in User Management first.</span><?php endif; ?></div></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Enlist</button></div></form></dialog>

<dialog id="groupDlg" class="modal"><h3>New group</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_group">
  <div><label class="field-label">Section</label><select name="section_id" required>
    <?php foreach ($sections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$secFilter === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Group name</label><input name="group_name" required placeholder="e.g. Group 1"></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Create group</button></div></form></dialog>
<script>
document.getElementById('enlistSearch').addEventListener('input', function(){
  var q = this.value.toLowerCase();
  document.querySelectorAll('.enlistRow').forEach(function(r){ r.style.display = r.dataset.name.indexOf(q) > -1 ? 'block' : 'none'; });
});
function enlistPick(on){
  document.querySelectorAll('.enlistRow').forEach(function(r){ if (r.style.display !== 'none') r.querySelector('input').checked = on; });
}
</script>
<?php layout_end(); ?>