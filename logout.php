<?php
require 'config.php';
if (!empty($_SESSION['user'])) audit($pdo, 'User logged out');
session_destroy();
header('Location: index.php');
exit;
