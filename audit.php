<?php
require 'config.php'; require 'layout.php'; require_role(['Admin']);
$logs = $pdo->query("SELECT l.*, u.name FROM audit_logs l JOIN users u ON u.user_id=l.user_id ORDER BY l.log_id DESC LIMIT 200")->fetchAll();
layout_start('audit', 'Audit Trail', 'Significant user actions and changes to accounting records, logged with a timestamp.');
table_open(['Timestamp','User','Action']);
foreach ($logs as $l): ?>
  <tr><td class="small"><?=h(date('M d, Y h:i A', strtotime($l['timestamp'])))?></td><td><?=h($l['name'])?></td><td><?=h($l['action'])?></td></tr>
<?php endforeach; table_close(3, !$logs); layout_end(); ?>
