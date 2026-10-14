<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role']; $uid = (int)$_SESSION['user']['user_id'];
$isStaff = in_array($role, ['Admin','Faculty'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'module') {
        require_role(['Admin','Faculty']);
        $pdo->prepare("INSERT INTO learning_modules(course_id,title,content_type,content_url) VALUES(?,?,?,?)")
            ->execute([(int)$_POST['course_id'], trim($_POST['title']), $_POST['type'], trim($_POST['url'] ?? '')]);
        audit($pdo, 'Created learning module'); flash('ok', 'Learning module published.');
    } elseif ($action === 'delete_module') {
        require_role(['Admin','Faculty']);
        $pdo->prepare("DELETE FROM learning_modules WHERE module_id=?")->execute([(int)$_POST['module_id']]);
        audit($pdo, 'Removed a learning module'); flash('ok', 'Module removed.');
    } elseif ($action === 'assignment') {
        require_role(['Admin','Faculty']);
        $f = $_FILES['doc'] ?? null;
        $title = trim($_POST['title'] ?? '');
        $err = null;
        if ($title === '') $err = 'Enter a title for the task.';
        elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) $err = 'Choose a Word document to upload (max 10 MB).';
        else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['doc','docx'], true)) $err = 'Only Word documents (.doc or .docx) are allowed.';
            elseif ($f['size'] > 10 * 1024 * 1024) $err = 'The file is larger than 10 MB.';
            else {
                $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
                $okMime = ['application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                           'application/zip','application/CDFV2','application/x-ole-storage','application/vnd.ms-office','application/octet-stream'];
                if ($mime !== '' && !in_array($mime, $okMime, true)) $err = 'That file does not look like a Word document.';
                elseif ($ext === 'docx' && class_exists('ZipArchive')) {
                    $z = new ZipArchive;
                    if ($z->open($f['tmp_name']) !== true || $z->locateName('word/document.xml') === false) $err = 'That file is not a valid .docx document.';
                    $z->close();
                }
            }
        }
        if ($err) flash('warn', $err);
        else {
            $dir = __DIR__ . '/uploads/assignments';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                file_put_contents(__DIR__ . '/uploads/.htaccess',
                    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) flash('warn', 'Could not save the file. Check the uploads folder permissions.');
            else {
                $orig = substr(preg_replace('/[^\w.\- ()]/u', '_', basename($f['name'])), 0, 200);
                $pdo->prepare("INSERT INTO assignments(course_id,title,instructions,due_date,file_name,file_path) VALUES(?,?,?,?,?,?)")
                    ->execute([(int)$_POST['course_id'], $title, '', ($_POST['due_date'] ?? '') ?: null, $orig, $stored]);
                audit($pdo, 'Uploaded task document'); flash('ok', 'Task uploaded.');
            }
        }
    } elseif ($action === 'delete_assignment') {
        require_role(['Admin','Faculty']);
        $id = (int)($_POST['assignment_id'] ?? 0);
        $f = $pdo->prepare("SELECT file_path FROM assignments WHERE assignment_id=?"); $f->execute([$id]); $file = $f->fetchColumn();
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM task_submissions WHERE assignment_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM assignments WHERE assignment_id=?")->execute([$id]);
            $pdo->commit();
            if ($file) @unlink(__DIR__ . '/uploads/assignments/' . basename($file));
            audit($pdo, 'Deleted a task'); flash('ok', 'Task deleted.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('warn', 'Cannot delete this task — grades are linked to it.');
        }
    } elseif ($action === 'submit') {
        require_role(['Student']);
        $pdo->prepare("INSERT INTO task_submissions(assignment_id,student_id,topic,description) VALUES(?,?,?,?)")
            ->execute([(int)$_POST['assignment_id'], $uid, trim($_POST['topic']), trim($_POST['description'])]);
        audit($pdo, 'Submitted assignment'); flash('ok', 'Task submitted.');
    }
    header('Location: tasks.php'); exit;
}

$courses = $pdo->query("SELECT c.*, u.name professor FROM courses c LEFT JOIN users u ON u.user_id=c.professor_id ORDER BY c.course_id")->fetchAll();
$modules = $pdo->query("SELECT m.*, c.course_code FROM learning_modules m JOIN courses c ON c.course_id=m.course_id ORDER BY m.module_id DESC")->fetchAll();
$assignments = $pdo->query("SELECT a.*, c.course_code FROM assignments a JOIN courses c ON c.course_id=a.course_id ORDER BY a.assignment_id DESC")->fetchAll();

$mine = []; $subs = [];
if ($role === 'Student') {
    $stmt = $pdo->prepare("SELECT * FROM task_submissions WHERE student_id=?"); $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $s) $mine[$s['assignment_id']] = $s;
} else {
    foreach ($pdo->query("SELECT s.*, u.name FROM task_submissions s JOIN users u ON u.user_id=s.student_id ORDER BY s.submission_id DESC")->fetchAll() as $s) $subs[$s['assignment_id']][] = $s;
}

function course_select($courses) {
    echo '<select name="course_id">';
    foreach ($courses as $c) echo '<option value="' . (int)$c['course_id'] . '">' . h($c['course_code'] . ' — ' . $c['course_name']) . '</option>';
    echo '</select>';
}
function del_btn($action, $field, $id, $msg) {
    echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'' . h($msg) . '\')">'
       . '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">'
       . '<input type="hidden" name="' . h($field) . '" value="' . (int)$id . '"><button class="btn ghost small">Delete</button></form>';
}
layout_start('tasks', 'Task Management', 'Assignments (Word documents) and learning materials in one place.');
$total = count($assignments) + count($modules);
?>
<div class="flex-between" style="margin-bottom:12px">
  <span>
    <button type="button" class="btn ghost small chip" data-f="all" onclick="filterTasks('all')">All <span class="tag"><?=$total?></span></button>
    <button type="button" class="btn ghost small chip" data-f="assignment" onclick="filterTasks('assignment')">Assignments <span class="tag"><?=count($assignments)?></span></button>
    <button type="button" class="btn ghost small chip" data-f="module" onclick="filterTasks('module')">Learning materials <span class="tag"><?=count($modules)?></span></button>
  </span>
  <?php if ($isStaff): ?><button class="btn brass" onclick="document.getElementById('taskDlg').showModal()">+ New task</button><?php endif; ?>
</div>
<?php if (!$total): ?><div class="empty">No tasks posted yet.</div><?php endif; ?>

<div class="card-stack" id="taskList">
<?php foreach ($assignments as $a): ?>
  <div class="card task-item" data-kind="assignment">
    <div class="flex-between"><h3 style="font-size:1rem"><?=h($a['title'])?> <span class="small muted">· <?=h($a['course_code'])?></span></h3>
      <span><span class="pill neutral">Assignment</span> <span class="small muted">Due <?=h($a['due_date'] ?? '—')?></span></span></div>
    <?php if (!empty($a['file_path'])): ?>
      <p class="small" style="margin:6px 0"><a class="btn ghost small" href="download.php?id=<?=(int)$a['assignment_id']?>">⬇ Download task (Word)</a> <span class="muted"><?=h($a['file_name'] ?? '')?></span></p>
    <?php elseif (!empty($a['instructions'])): ?><p class="small" style="margin:6px 0"><?=nl2br(h($a['instructions']))?></p><?php endif; ?>
    <?php if ($role === 'Student'):
        if (isset($mine[$a['assignment_id']])): $s = $mine[$a['assignment_id']]; ?>
          <div class="banner ok" style="margin:0">✓ Submitted “<?=h($s['topic'])?>” on <?=h(substr($s['submitted_at'], 0, 10))?></div>
        <?php else: ?><button class="btn brass" onclick="openSubmit(<?=(int)$a['assignment_id']?>)">Submit task</button><?php endif;
    else:
        $list = $subs[$a['assignment_id']] ?? [];
        if ($list) { table_open(['Student','Topic','Description','Submitted']);
            foreach ($list as $s): ?><tr><td><?=h($s['name'])?></td><td><?=h($s['topic'])?></td><td><?=h($s['description'])?></td><td class="small"><?=h(substr($s['submitted_at'], 0, 10))?></td></tr><?php endforeach;
            table_close(4); }
        else echo '<div class="empty">No submissions yet.</div>';
        del_btn('delete_assignment', 'assignment_id', $a['assignment_id'], 'Delete this task and its submissions?');
    endif; ?>
  </div>
<?php endforeach; ?>
<?php foreach ($modules as $m): ?>
  <div class="card task-item" data-kind="module">
    <div class="flex-between"><h3 style="font-size:1rem"><?=h($m['title'])?> <span class="small muted">· <?=h($m['course_code'])?></span></h3><span class="pill neutral"><?=h($m['content_type'])?></span></div>
    <?php if (!empty($m['content_url'])): ?><p class="small" style="margin:6px 0 0"><a href="<?=h($m['content_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($m['content_url'])?></a></p><?php endif; ?>
    <?php if ($isStaff) del_btn('delete_module', 'module_id', $m['module_id'], 'Delete this learning material?'); ?>
  </div>
<?php endforeach; ?>
</div>

<?php if ($isStaff): ?>
<dialog id="taskDlg" class="modal"><h3>New task</h3><form method="post" enctype="multipart/form-data" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <div><label class="field-label">Task type</label>
    <select name="action" id="kind" onchange="toggleKind()">
      <option value="assignment">Assignment (Word document)</option>
      <option value="module">Learning material (link)</option>
    </select></div>
  <div><label class="field-label">Course</label><?php course_select($courses); ?></div>
  <div><label class="field-label">Title</label><input name="title" required></div>
  <div class="k-assignment"><label class="field-label">Word document (.doc or .docx, max 10 MB)</label>
    <input type="file" name="doc" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></div>
  <div class="k-assignment"><label class="field-label">Due date (optional)</label><input type="datetime-local" name="due_date"></div>
  <div class="k-module" style="display:none"><label class="field-label">Material type</label>
    <select name="type" disabled><option>Reading</option><option>Video</option><option>Slides</option><option>Material</option></select></div>
  <div class="k-module" style="display:none"><label class="field-label">Content URL</label><input name="url" placeholder="https://..." disabled></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Post task</button></div></form></dialog>
<script>
function toggleKind(){
  var k = document.getElementById('kind').value;
  document.querySelectorAll('.k-assignment').forEach(function(d){
    d.style.display = k === 'assignment' ? '' : 'none';
    d.querySelectorAll('input').forEach(function(i){ i.disabled = k !== 'assignment'; });
  });
  document.querySelectorAll('.k-module').forEach(function(d){
    d.style.display = k === 'module' ? '' : 'none';
    d.querySelectorAll('input,select').forEach(function(i){ i.disabled = k !== 'module'; });
  });
}
</script>
<?php else: ?>
<dialog id="subDlg" class="modal"><h3>Submit task</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="assignment_id" id="subAssignment">
  <div><label class="field-label">Topic</label><input name="topic" required></div>
  <div><label class="field-label">Your response</label><textarea name="description" required></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Submit</button></div></form></dialog>
<script>function openSubmit(id){ document.getElementById('subAssignment').value=id; document.getElementById('subDlg').showModal(); }</script>
<?php endif; ?>
<script>
function filterTasks(f){
  document.querySelectorAll('.task-item').forEach(function(c){ c.style.display = (f === 'all' || c.dataset.kind === f) ? '' : 'none'; });
  document.querySelectorAll('.chip').forEach(function(b){ b.style.fontWeight = b.dataset.f === f ? '700' : ''; });
}
filterTasks('all');
</script>
<?php layout_end(); ?>
