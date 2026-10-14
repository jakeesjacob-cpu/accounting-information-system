<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role']; $uid = (int)$_SESSION['user']['user_id'];
$isStaff = in_array($role, ['Admin','Faculty'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'course') {
        require_role(['Admin','Faculty']);
        $pdo->prepare("INSERT INTO courses(course_code,course_name,professor_id) VALUES(?,?,?)")->execute([trim($_POST['code']), trim($_POST['name']), $uid]);
        audit($pdo, 'Created course'); flash('ok', 'Course created.');
    } elseif ($action === 'module') {
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
    } elseif ($action === 'submit') {
        require_role(['Student']);
        $pdo->prepare("INSERT INTO task_submissions(assignment_id,student_id,topic,description) VALUES(?,?,?,?)")
            ->execute([(int)$_POST['assignment_id'], $uid, trim($_POST['topic']), trim($_POST['description'])]);
        audit($pdo, 'Submitted assignment'); flash('ok', 'Task submitted.');
    }
    header('Location: learning.php'); exit;
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
layout_start('learning', 'Modules & Assignments', 'Lessons, readings and videos, plus tasks uploaded by faculty as Word documents.');
?>


<section class="block"><h2>Learning Modules <span class="tag"><?=count($modules)?></span></h2>
<?php if ($isStaff): ?><div style="margin-bottom:10px"><button class="btn brass" onclick="document.getElementById('modDlg').showModal()">+ New module</button></div><?php endif; ?>
<?php if (!$modules): ?><div class="empty">No learning modules posted yet.</div><?php endif; ?>
<div class="card-stack">
<?php foreach ($modules as $m): ?>
  <div class="card"><div class="flex-between"><h3 style="font-size:1rem"><?=h($m['title'])?> <span class="small muted">· <?=h($m['course_code'])?></span></h3><span class="pill neutral"><?=h($m['content_type'])?></span></div>
  <?php if (!empty($m['content_url'])): ?><p class="small" style="margin:6px 0 0"><a href="<?=h($m['content_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($m['content_url'])?></a></p><?php endif; ?>
  <?php if ($isStaff): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Remove this module?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_module"><input type="hidden" name="module_id" value="<?=(int)$m['module_id']?>"><button class="btn ghost small">Remove</button></form><?php endif; ?></div>
<?php endforeach; ?>
</div></section>

<section class="block" id="assignments"><h2>Assignments <span class="tag"><?=count($assignments)?></span></h2>
<?php if ($isStaff): ?><div style="margin-bottom:10px"><button class="btn brass" onclick="document.getElementById('asDlg').showModal()">+ Upload task</button></div><?php endif; ?>
<?php if (!$assignments): ?><div class="empty">No assignments posted yet.</div><?php endif; ?>
<div class="card-stack">
<?php foreach ($assignments as $a): ?>
  <div class="card"><div class="flex-between"><h3 style="font-size:1rem"><?=h($a['title'])?> <span class="small muted">· <?=h($a['course_code'])?></span></h3><span class="small muted">Due <?=h($a['due_date'] ?? '—')?></span></div>
  <?php if (!empty($a['file_path'])): ?>
    <p class="small" style="margin:6px 0"><a class="btn ghost small" href="download.php?id=<?=(int)$a['assignment_id']?>">⬇ Download task (Word)</a> <span class="muted"><?=h($a['file_name'] ?? '')?></span></p>
  <?php else: ?><p class="small" style="margin:6px 0"><?=nl2br(h($a['instructions'] ?? ''))?></p><?php endif; ?>
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
  endif; ?></div>
<?php endforeach; ?>
</div></section>

<?php if ($isStaff): ?>
<dialog id="modDlg" class="modal"><h3>New learning module</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="module">
  <div><label class="field-label">Course</label><?php course_select($courses); ?></div>
  <div><label class="field-label">Title</label><input name="title" required></div>
  <div><label class="field-label">Type</label><select name="type"><option>Reading</option><option>Video</option><option>Slides</option><option>Material</option></select></div>
  <div><label class="field-label">Content URL</label><input name="url" placeholder="https://..."></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Publish module</button></div></form></dialog>
<dialog id="asDlg" class="modal"><h3>Upload task (Word document)</h3><form method="post" enctype="multipart/form-data" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="assignment">
  <div><label class="field-label">Course</label><?php course_select($courses); ?></div>
  <div><label class="field-label">Title</label><input name="title" required></div>
  <div><label class="field-label">Word document (.doc or .docx, max 10 MB)</label><input type="file" name="doc" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></div>
  <div><label class="field-label">Due date (optional)</label><input type="datetime-local" name="due_date"></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Upload task</button></div></form></dialog>
<?php else: ?>
<dialog id="subDlg" class="modal"><h3>Submit task</h3><form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="assignment_id" id="subAssignment">
  <div><label class="field-label">Topic</label><input name="topic" required></div>
  <div><label class="field-label">Your response</label><textarea name="description" required></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Submit</button></div></form></dialog>
<script>function openSubmit(id){ document.getElementById('subAssignment').value=id; document.getElementById('subDlg').showModal(); }</script>
<?php endif; layout_end(); ?>