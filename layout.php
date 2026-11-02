<?php
// Shared shell (sidebar + page head) ported from Accounting_LMS_Prototype.html
function nav_config() {
    $all = ['Admin','Faculty','Student'];
    $noAdmin = ['Faculty','Student']; // Admin only sees Overview and Administration
    return [
        ['Overview', [
            ['dashboard',     'Dashboard',         '◆', 'dashboard.php',            $all],
            ['notifications', 'Notifications',     '✉', 'notifications.php',        $all],
        ]],
        ['Accounting (AIS)', [
            ['coa',     'Chart of Accounts', '▤', 'accounting.php?v=coa',     $noAdmin],
            ['journal', 'Journal Entries',   '✎', 'accounting.php?v=journal', $noAdmin],
            ['ledger',  'General Ledger',    '▥', 'reports.php?v=ledger',     $noAdmin],
            ['trial',   'Trial Balance',     '⚖', 'reports.php?v=trial',      $noAdmin],
            ['reports', 'Financial Reports', '▦', 'reports.php?v=financial',  $noAdmin],
        ]],
        ['Learning (LMS)', [
            ['programs',    'Program Management', '▧', 'programs.php',              $noAdmin],
            ['students',    'Student Management', '◍', 'students.php',              ['Faculty']],
            ['tasks',       'Task Management',    '▤', 'tasks.php',                 $noAdmin],
            ['grades',      'Grades',             '★', 'grades.php',                $noAdmin],
        ]],
        ['Administration', [
            ['users', 'User Management', '◍', 'users.php', ['Admin']],
            ['audit', 'Audit Trail',     '☰', 'audit.php', ['Admin']],
        ]],
    ];
}
 
// Number of notifications this user has not opened yet (never counts their own posts).
function unread_notifications($u) {
    global $pdo;
    try {
        $sql = "SELECT COUNT(*) FROM notifications n
                WHERE n.notification_id > (SELECT COALESCE(last_seen_notification,0) FROM users WHERE user_id=?)
                AND (n.created_by IS NULL OR n.created_by <> ?)
                AND (n.recipient_id IS NULL OR n.recipient_id = ?)"
             . ($u['role'] === 'Student' ? " AND n.audience <> 'All Faculty'" : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([(int)$u['user_id'], (int)$u['user_id'], (int)$u['user_id']]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }   // column/table missing: just show no badge
}

function layout_start($active, $title, $sub = '', $eyebrow = '') {
    $u = $_SESSION['user'];
    $unread = unread_notifications($u);
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#16233F">
<title><?=h($title)?> — Accounting Information System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="app.css?v=<?=@filemtime(__DIR__ . '/app.css')?>">
</head><body>
<div id="app">
  <div id="navOverlay"></div>
  <aside id="sidebar">
    <div class="brand">
      <div class="mark">Accounting Information System</div>
      <div class="sub">ACLC College Baliuag &middot; BSAIS / BSEN<br>Business &amp; Accounting Department</div>
    </div>
    <nav class="tabs">
<?php foreach (nav_config() as $g):
        $items = array_filter($g[1], function ($i) use ($u) { return in_array($u['role'], $i[4], true); });
        if (!$items) continue; ?>
      <div class="tab-group-label"><?=h($g[0])?></div>
<?php foreach ($items as $i): ?>
      <a class="navbtn<?=$i[0] === $active ? ' active' : ''?>" href="<?=h($i[3])?>"><span class="ico"><?=$i[2]?></span><?=h($i[1])?><?php if ($i[0] === 'notifications' && $unread > 0): ?><span class="nav-badge"><?=$unread > 99 ? '99+' : $unread?></span><?php endif; ?></a>
<?php endforeach; endforeach; ?>
    </nav>
    <div class="role-switch">
      <label>Signed in as</label>
      <div class="who-name"><?=h($u['name'])?></div>
      <div class="who-role"><?=h(role_label($u['role']))?></div>
      <a class="btn ghost small" href="profile.php">Profile</a>
      <a class="btn ghost small" href="logout.php">Logout</a>
    </div>
  </aside>
  <main id="main">
    <div class="mobile-topbar">
      <button id="menuBtn" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">☰<?php if ($unread > 0): ?><span class="nav-badge"><?=$unread > 99 ? '99+' : $unread?></span><?php endif; ?></button>
      <strong style="font-family:'Source Serif 4',serif;"><?=h($title)?></strong>
    </div>
    <header class="pagehead">
      <?php if ($eyebrow): ?><div class="eyebrow"><?=h($eyebrow)?></div><?php endif; ?>
      <h1><?=h($title)?></h1>
      <?php if ($sub): ?><p><?=h($sub)?></p><?php endif; ?>
    </header>
<?php
    if (!empty($_SESSION['flash'])) {
        [$type, $text] = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="banner ' . ($type === 'ok' ? 'ok' : 'warn') . '">' . h($text) . '</div>';
    }
}
 
function layout_end() { ?>
  </main>
</div>
<script>
(function(){
  var sb  = document.getElementById('sidebar');
  var ov  = document.getElementById('navOverlay');
  var btn = document.getElementById('menuBtn');

  function setOpen(open){
    sb.classList.toggle('open', open);
    ov.classList.toggle('show', open);
    document.body.classList.toggle('nav-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function(){ setOpen(!sb.classList.contains('open')); });
  ov.addEventListener('click', function(){ setOpen(false); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') setOpen(false); });
  sb.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', function(){ setOpen(false); }); });

  // On phones, tables turn into stacked cards. Each cell is labelled from its column heading.
  document.querySelectorAll('table').forEach(function(t){
    var heads = [].map.call(t.querySelectorAll('thead th'), function(th){ return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function(tr){
      var i = 0;
      [].forEach.call(tr.children, function(td){
        if (td.tagName !== 'TD') return;
        var span = td.colSpan || 1;
        if (span === 1 && heads[i]) td.setAttribute('data-label', heads[i]);
        i += span;
      });
    });
    t.classList.add('stackable');
  });
})();
</script>
</body></html>
<?php }
 
function stat_card($label, $value, $cls = '') {
    echo '<div class="card stat-card"><div class="label">' . h($label) . '</div><div class="value ' . h($cls) . '">' . h($value) . '</div></div>';
}
function pill($status) {
    $map = ['Valid'=>['ok','Balanced'], 'Invalid'=>['warn','Unbalanced'], 'Active'=>['ok','Active'],
            'Disabled'=>['warn','Disabled'], 'Pending'=>['neutral','Pending']];
    $m = $map[$status] ?? ['neutral', $status];
    return '<span class="pill ' . $m[0] . '">' . h($m[1]) . '</span>';
}
function table_open(array $headers, array $num = []) {
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $i => $hd) echo '<th class="' . (!empty($num[$i]) ? 'num' : '') . '">' . h($hd) . '</th>';
    echo '</tr></thead><tbody>';
}
function table_close($cols, $isEmpty = false) {
    if ($isEmpty) echo '<tr><td colspan="' . (int)$cols . '" class="empty">No records.</td></tr>';
    echo '</tbody></table></div>';
}
function journal_mini(array $entries) {
    table_open(['Date','Memo','Student','Amount','Status'], [0,0,0,1,0]);
    foreach ($entries as $e) {
        echo '<tr><td>' . h($e['entry_date']) . '</td><td>' . h($e['description']) . '</td><td>' . h($e['name']) . '</td><td class="num">'
           . h(peso($e['total_debit'])) . '</td><td>' . pill($e['ai_validation_status']) . '</td></tr>';
    }
    table_close(5, !$entries);
}