<?php
require 'config.php'; require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT title,file_name,file_path FROM assignments WHERE assignment_id=?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a || empty($a['file_path'])) { http_response_code(404); exit('File not found.'); }
$path = __DIR__ . '/uploads/assignments/' . basename($a['file_path']);
if (!is_file($path)) { http_response_code(404); exit('File not found.'); }
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/msword';
$name = $a['file_name'] ?: ('task.' . $ext);
audit($pdo, 'Downloaded task document: ' . $a['title']);
header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
