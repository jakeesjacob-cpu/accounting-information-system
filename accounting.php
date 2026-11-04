<?php
require 'config.php'; require 'layout.php'; require_login();
$role    = $_SESSION['user']['role'];
$userId  = (int)$_SESSION['user']['user_id'];
$canEdit = in_array($role, ['Admin','Faculty'], true);
$canAdd  = in_array($role, ['Admin','Student'], true);   // Faculty no longer add/edit/delete accounts: they check them
$canJournal = in_array($role, ['Admin','Student'], true); // Faculty cannot add/delete entries...
$canReview  = in_array($role, ['Admin','Faculty'], true); // ...but can check (review) student work
$v = (($_POST['v'] ?? $_GET['v'] ?? 'coa') === 'journal') ? 'journal' : 'coa';
 
// Normal balance side for each account type ("Debit/Credit Nature" column).
// True when chart_of_accounts has the created_by (owner) column.
function coa_has_owner(PDO $pdo) {
    static $has = null;
    if ($has === null) { try { $has = (bool)$pdo->query("SHOW COLUMNS FROM chart_of_accounts LIKE 'created_by'")->fetch(); } catch (Throwable $e) { $has = false; } }
    return $has;
}
// Per-student heading (company name, title of report, end of month) shown above each student's General Journal.
function ensure_report_headers(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_report_headers (
        student_id INT NOT NULL PRIMARY KEY,
        company VARCHAR(150) NOT NULL DEFAULT '',
        report_title VARCHAR(150) NOT NULL DEFAULT '',
        period_end DATE NULL
    )");
}

// Chart of accounts: every student builds their own accounts. A student can change only their own;
// Admin can change any; Faculty cannot change accounts - they review (check) them instead.
function coa_can_manage(PDO $pdo, $id, $role, $uid) {
    if ($role === 'Admin') return true;
    if ($role !== 'Student' || $uid <= 0 || !coa_has_owner($pdo)) return false;
    $q = $pdo->prepare("SELECT created_by FROM chart_of_accounts WHERE account_id=?"); $q->execute([$id]);
    return (int)$q->fetchColumn() === $uid;
}
function coa_row_manageable($a) {
    global $role, $userId;
    return $role === 'Admin' || ($role === 'Student' && $userId > 0 && (int)($a['created_by'] ?? 0) === $userId);
}
// Sends a private notification to one student. Returns '' on success or the database error text.
function notify_student(PDO $pdo, $studentId, $message, $creatorId) {
    try {
        $pdo->prepare("INSERT INTO notifications(audience,message,created_by,recipient_id) VALUES('All Students',?,?,?)")
            ->execute([$message, $creatorId, (int)$studentId]);
        return '';
    } catch (PDOException $e) { return $e->getMessage(); }
}
// True when a journal entry already has a score recorded in Grades.
function entry_is_graded(PDO $pdo, $id) {
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE entry_id=?"); $q->execute([(int)$id]); return (int)$q->fetchColumn() > 0; }
    catch (Throwable $e) { return false; }   // grading migration not run yet
}
// Dropdown data for the faculty's Section / Group / Student filter.
function review_filter_lists(PDO $pdo, $fSec, $fGrp) {
    $secs = $pdo->query("SELECT s.section_id, s.section_name, c.course_code FROM sections s JOIN courses c ON c.course_id=s.course_id ORDER BY c.course_code, s.section_name")->fetchAll();
    $q = $pdo->prepare("SELECT g.group_id, g.group_name, s.section_name FROM student_groups g JOIN sections s ON s.section_id=g.section_id WHERE (?=0 OR g.section_id=?) ORDER BY s.section_name, g.group_name");
    $q->execute([$fSec, $fSec]); $grps = $q->fetchAll();
    $q = $pdo->prepare("SELECT u.user_id, u.name FROM users u JOIN enrollments e ON e.student_id=u.user_id WHERE u.role='Student' AND (?=0 OR e.section_id=?) AND (?=0 OR e.group_id=?) ORDER BY u.name");
    $q->execute([$fSec, $fSec, $fGrp, $fGrp]); $stus = $q->fetchAll();
    return [$secs, $grps, $stus];
}
function normal_balance($type) {
    return in_array($type, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit';
}
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_account') {
            require_role(['Admin','Student']);
            if (coa_has_owner($pdo)) {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts(account_code,account_name,account_type,description,created_by) VALUES(?,?,?,?,?)");
                $stmt->execute([trim($_POST['code']), trim($_POST['name']), $_POST['type'], trim($_POST['description'] ?? ''), $userId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts(account_code,account_name,account_type,description) VALUES(?,?,?,?)");
                $stmt->execute([trim($_POST['code']), trim($_POST['name']), $_POST['type'], trim($_POST['description'] ?? '')]);
            }
            audit($pdo, 'Added chart of account');
            flash('ok', 'Account added.');
        } elseif ($action === 'review_account') {
            require_role(['Admin','Faculty']);
            $id = (int)($_POST['account_id'] ?? 0);
            $status = $_POST['review_status'] ?? ''; $comment = trim($_POST['review_comment'] ?? '');
            $q = $pdo->prepare("SELECT account_code, account_name, created_by FROM chart_of_accounts WHERE account_id=?"); $q->execute([$id]); $acc = $q->fetch();
            if (!$acc) flash('warn', 'Account not found.');
            elseif (empty($acc['created_by'])) flash('warn', 'Shared starter accounts do not belong to a student, so they are not reviewed.');
            elseif (!in_array($status, ['Approved','Needs Revision','Pending'], true)) flash('warn', 'Choose a review result.');
            elseif ($status === 'Needs Revision' && $comment === '') flash('warn', 'Add a comment so the student knows what to fix.');
            else {
                $pdo->prepare("UPDATE chart_of_accounts SET review_status=?, review_comment=?, reviewed_by=?, reviewed_at=? WHERE account_id=?")
                    ->execute([$status, $comment !== '' ? $comment : null, $userId, $status === 'Pending' ? null : date('Y-m-d H:i:s'), $id]);
                audit($pdo, "Reviewed chart of account #$id: $status");
                if ($status === 'Pending') flash('ok', 'Review cleared.');
                else {
                    $msg = 'Your chart of accounts entry "' . $acc['account_code'] . ' - ' . $acc['account_name'] . '" was '
                         . ($status === 'Approved' ? 'APPROVED' : 'marked NEEDS REVISION') . ' by ' . $_SESSION['user']['name'] . '.'
                         . ($comment !== '' ? "\nComment: " . $comment : '');
                    $err = notify_student($pdo, (int)$acc['created_by'], $msg, $userId);
                    if ($err === '') flash('ok', 'Review saved and sent to the student.');
                    else flash('warn', 'Review saved, but the student could not be notified: ' . $err);
                }
            }
        } elseif ($action === 'grade_coa') {
            require_role(['Admin','Faculty']);
            // One score per student for their whole chart of accounts. Re-grading updates it; a blank score removes it.
            $sid = (int)($_POST['student_id'] ?? 0);
            $scoreRaw = trim((string)($_POST['score'] ?? '')); $score = (float)$scoreRaw; $maxScore = (float)($_POST['max_score'] ?? 0);
            $sq = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id=? AND role='Student'"); $sq->execute([$sid]);
            if (!$sq->fetchColumn()) flash('warn', 'Student not found.');
            elseif ($scoreRaw !== '' && (!is_numeric($scoreRaw) || $maxScore <= 0 || $score < 0 || $score > $maxScore)) flash('warn', 'Enter a score between 0 and the maximum score.');
            else {
                try {
                    if ($scoreRaw === '') {
                        $pdo->prepare("DELETE FROM grades WHERE coa_student_id=?")->execute([$sid]);
                        audit($pdo, "Cleared chart of accounts score for student #$sid");
                        flash('ok', 'Chart of accounts score cleared.');
                    } else {
                        $cq = $pdo->prepare("SELECT sc.course_id FROM enrollments e JOIN sections sc ON sc.section_id=e.section_id WHERE e.student_id=? LIMIT 1");
                        $cq->execute([$sid]); $courseId = $cq->fetchColumn();
                        if ($courseId === false) flash('warn', 'The student is not enrolled in a section, so the score could not be recorded.');
                        else {
                            $pdo->prepare("INSERT INTO grades(student_id,course_id,coa_student_id,score,max_score,date_recorded) VALUES(?,?,?,?,?,CURDATE())
                                ON DUPLICATE KEY UPDATE course_id=VALUES(course_id), score=VALUES(score), max_score=VALUES(max_score), date_recorded=CURDATE()")
                                ->execute([$sid, (int)$courseId, $sid, $score, $maxScore]);
                            $gn = ($score + 0) . ' / ' . ($maxScore + 0);
                            audit($pdo, "Graded chart of accounts for student #$sid: $gn");
                            $msg = 'Your chart of accounts was graded by ' . $_SESSION['user']['name'] . '. Score: ' . $gn . '.';
                            $err = notify_student($pdo, $sid, $msg, $userId);
                            if ($err === '') flash('ok', 'Score ' . $gn . ' recorded in Grades and sent to the student.');
                            else flash('warn', 'Score ' . $gn . ' recorded in Grades, but the student could not be notified: ' . $err);
                        }
                    }
                } catch (PDOException $ge) { flash('warn', 'The score could not be saved. Run migration_coa_grading.sql first.'); }
            }
        } elseif ($action === 'edit_account') {
            require_role(['Admin','Student']);
            $id = (int)($_POST['account_id'] ?? 0);
            $code = trim($_POST['code'] ?? ''); $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? ''; $desc = trim($_POST['description'] ?? '');
            if (!coa_can_manage($pdo, $id, $role, $userId)) {
                flash('warn', 'You are not allowed to change accounts.');
            } elseif ($code === '' || $name === '' || !in_array($type, ['Asset','Liability','Equity','Revenue','Expense'], true)) {
                flash('warn', 'Enter an account code, name and a valid type.');
            } else {
                $cur = $pdo->prepare("SELECT account_type FROM chart_of_accounts WHERE account_id=?"); $cur->execute([$id]);
                $oldType = $cur->fetchColumn();
                $used = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id=?"); $used->execute([$id]);
                if ($oldType === false) flash('warn', 'Account not found.');
                elseif ($oldType !== $type && $used->fetchColumn() > 0) flash('warn', 'Cannot change the type — the account is already used in journal entries.');
                else {
                    $pdo->prepare("UPDATE chart_of_accounts SET account_code=?, account_name=?, account_type=?, description=? WHERE account_id=?")
                        ->execute([$code, $name, $type, $desc, $id]);
                    try { $pdo->prepare("UPDATE chart_of_accounts SET review_status='Pending', review_comment=NULL, reviewed_by=NULL, reviewed_at=NULL WHERE account_id=?")->execute([$id]); }
                    catch (PDOException $e2) { /* review columns not added yet */ }
                    if ($role === 'Student') { // a changed chart of accounts must be graded again
                        try { $pdo->prepare("DELETE FROM grades WHERE coa_student_id=?")->execute([$userId]); } catch (PDOException $e5) { /* coa grading column not added yet */ }
                    }
                    audit($pdo, 'Edited chart of account'); flash('ok', 'Account updated.');
                }
            }
        } elseif ($action === 'delete_account') {
            require_role(['Admin','Student']);
            $id = (int)($_POST['account_id'] ?? 0);
            $used = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id=?");
            $used->execute([$id]);
            if (!coa_can_manage($pdo, $id, $role, $userId)) {
                flash('warn', 'You are not allowed to remove accounts.');
            } elseif ($used->fetchColumn() > 0) {
                flash('warn', 'Cannot remove — account is used in journal entries.');
            } else {
                $pdo->prepare("DELETE FROM chart_of_accounts WHERE account_id=?")->execute([$id]);
                audit($pdo, 'Removed a chart-of-accounts entry');
                flash('ok', 'Account removed.');
            }
        } elseif ($action === 'delete_entry') {
            require_role(['Admin','Student']); // Faculty is view-only
            // Students may delete only their own entries; Admin/Faculty may delete any.
            $id = (int)($_POST['entry_id'] ?? 0);
            $own = $pdo->prepare("SELECT student_id FROM journal_entries WHERE entry_id=?");
            $own->execute([$id]);
            $owner = $own->fetchColumn();
            if ($owner === false) {
                flash('warn', 'Journal entry not found.');
            } elseif ($role === 'Student' && (int)$owner !== $userId) {
                flash('warn', 'You can only delete your own journal entries.');
            } elseif ($role === 'Student' && entry_is_graded($pdo, $id)) {
                flash('warn', 'This entry has already been graded and can no longer be deleted.');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM journal_lines WHERE entry_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM journal_entries WHERE entry_id=?")->execute([$id]);
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                try { $pdo->prepare("DELETE FROM grades WHERE entry_id=?")->execute([$id]); } catch (PDOException $e3) { /* grading columns not added yet */ }
                audit($pdo, 'Deleted a journal entry');
                reset_reports_if_empty($pdo); // nothing left -> reset Ledger / Trial Balance / Financial Reports
                flash('ok', 'Journal entry deleted.');
            }
        } elseif ($action === 'edit_entry') {
            require_role(['Admin','Student']); // Faculty reviews entries but does not change them
            $id = (int)($_POST['entry_id'] ?? 0);
            $own = $pdo->prepare("SELECT student_id FROM journal_entries WHERE entry_id=?"); $own->execute([$id]); $owner = $own->fetchColumn();
            $title = trim($_POST['title'] ?? ''); $expl = trim($_POST['explanation'] ?? '');
            $dateRaw = trim($_POST['entry_date'] ?? ''); $dt = DateTime::createFromFormat('Y-m-d', $dateRaw);
            if ($owner === false) flash('warn', 'Journal entry not found.');
            elseif ($role === 'Student' && (int)$owner !== $userId) flash('warn', 'You can only edit your own journal entries.');
            elseif ($title === '') flash('warn', 'Enter an entry title.');
            elseif (!$dt || $dt->format('Y-m-d') !== $dateRaw) flash('warn', 'Please enter a valid date as DD/MM/YYYY.');
            else {
                $lines = []; $bad = '';
                foreach (($_POST['account'] ?? []) as $i => $raw) {
                    $dv = (float)str_replace(',', '', trim((string)($_POST['debit'][$i] ?? '')));
                    $cv = (float)str_replace(',', '', trim((string)($_POST['credit'][$i] ?? '')));
                    if (trim((string)$raw) === '' && $dv == 0 && $cv == 0) continue;
                    $aid = find_account($pdo, $raw, (int)$owner);
                    if ($aid === null) { $bad = 'Account not found: ' . trim((string)$raw) . '. Use a code or title from the Chart of Accounts.'; break; }
                    $lines[] = [$aid, $dv, $cv];
                }
                if ($bad !== '') flash('warn', $bad);
                elseif (count($lines) < 2) flash('warn', 'A journal entry needs at least two lines.');
                else {
                    $valid = journal_is_balanced(array_column($lines, 1), array_column($lines, 2));
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE journal_entries SET entry_date=?, description=?, ai_validation_status=? WHERE entry_id=?")
                            ->execute([$dt->format('Y-m-d'), $title . ($expl !== '' ? ' ‖ ' . $expl : ''), $valid ? 'Valid' : 'Invalid', $id]);
                        $pdo->prepare("DELETE FROM journal_lines WHERE entry_id=?")->execute([$id]);
                        $ins = $pdo->prepare("INSERT INTO journal_lines(entry_id,account_id,debit_amount,credit_amount) VALUES(?,?,?,?)");
                        foreach ($lines as $l) $ins->execute([$id, $l[0], $l[1], $l[2]]);
                        $pdo->commit();
                    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                    try { // an edited entry goes back to "not yet reviewed" so faculty check it again
                        $pdo->prepare("UPDATE journal_entries SET review_status='Pending', review_comment=NULL, reviewed_by=NULL, reviewed_at=NULL WHERE entry_id=?")->execute([$id]);
                    } catch (PDOException $e2) { /* review columns not added yet */ }
                    try { $pdo->prepare("DELETE FROM grades WHERE entry_id=?")->execute([$id]); } catch (PDOException $e3) { /* grading columns not added yet */ }
                    // The heading (company name, title of report, end of month) belongs to the student's journal.
                    $hCo = trim($_POST['company'] ?? ''); $hRp = trim($_POST['report_title'] ?? '');
                    $hPd = DateTime::createFromFormat('Y-m-d', trim($_POST['period'] ?? ''));
                    $hPe = $hPd ? $hPd->format('Y-m-t') : date('Y-m-t');
                    try {
                        ensure_report_headers($pdo);
                        $pdo->prepare('INSERT INTO student_report_headers (student_id, company, report_title, period_end) VALUES (?,?,?,?)
                                       ON DUPLICATE KEY UPDATE company=VALUES(company), report_title=VALUES(report_title), period_end=VALUES(period_end)')
                            ->execute([(int)$owner, $hCo, $hRp, $hPe]);
                    } catch (PDOException $e4) { /* heading table unavailable */ }
                    if ((int)$owner === (int)$userId) { $_SESSION['je_company'] = $hCo; $_SESSION['je_report'] = $hRp; $_SESSION['je_period'] = $hPe; }
                    audit($pdo, 'Edited a journal entry: ' . ($valid ? 'Valid' : 'Invalid'));
                    if ($valid) flash('ok', 'Journal entry updated and validated: debit equals credit.');
                    else flash('warn', 'Entry updated but saved as Unbalanced: debit and credit totals must be equal.');
                }
            }
        } elseif ($action === 'review_entry') {
            require_role(['Admin','Faculty']);
            $id      = (int)($_POST['entry_id'] ?? 0);
            $status  = $_POST['review_status'] ?? '';
            $comment = trim($_POST['review_comment'] ?? '');
            $chk = $pdo->prepare("SELECT student_id, entry_date, description FROM journal_entries WHERE entry_id=?"); $chk->execute([$id]);
            $ent = $chk->fetch();
            $scoreRaw = trim((string)($_POST['score'] ?? '')); $maxRaw = trim((string)($_POST['max_score'] ?? ''));
            $hasScore = $scoreRaw !== '' && $status !== 'Pending';
            $score = (float)$scoreRaw; $maxScore = (float)$maxRaw;
            if (!$ent) flash('warn', 'Journal entry not found.');
            elseif ($hasScore && (!is_numeric($scoreRaw) || $maxScore <= 0 || $score < 0 || $score > $maxScore)) flash('warn', 'Enter a score between 0 and the maximum score.');
            elseif (!in_array($status, ['Approved','Needs Revision','Pending'], true)) flash('warn', 'Choose a review result.');
            elseif ($status === 'Needs Revision' && $comment === '') flash('warn', 'Add a comment so the student knows what to fix.');
            else {
                $pdo->prepare("UPDATE journal_entries SET review_status=?, review_comment=?, reviewed_by=?, reviewed_at=? WHERE entry_id=?")
                    ->execute([$status, $comment !== '' ? $comment : null, $userId, $status === 'Pending' ? null : date('Y-m-d H:i:s'), $id]);
                audit($pdo, "Reviewed journal entry #$id: $status");
                // Score -> Grades. One grade per entry: re-reviewing updates it, blank score or "not yet reviewed" removes it.
                $gradeNote = ''; $gradeWarn = '';
                try {
                    if (!$hasScore) {
                        $pdo->prepare("DELETE FROM grades WHERE entry_id=?")->execute([$id]);
                    } else {
                        $cq = $pdo->prepare("SELECT sc.course_id FROM enrollments e JOIN sections sc ON sc.section_id=e.section_id WHERE e.student_id=? LIMIT 1");
                        $cq->execute([(int)$ent['student_id']]); $courseId = $cq->fetchColumn();
                        if ($courseId === false) $gradeWarn = 'The student is not enrolled in a section, so the score could not be recorded.';
                        else {
                            $pdo->prepare("INSERT INTO grades(student_id,course_id,entry_id,score,max_score,date_recorded) VALUES(?,?,?,?,?,CURDATE())
                                ON DUPLICATE KEY UPDATE course_id=VALUES(course_id), score=VALUES(score), max_score=VALUES(max_score), date_recorded=CURDATE()")
                                ->execute([(int)$ent['student_id'], (int)$courseId, $id, $score, $maxScore]);
                            $gradeNote = ($score + 0) . ' / ' . ($maxScore + 0);
                            audit($pdo, "Graded journal entry #$id: $gradeNote");
                        }
                    }
                } catch (PDOException $ge) { $gradeWarn = 'The score could not be saved. Run migration_journal_grading.sql first.'; }
                $sent = false; $notifErr = '';
                if ($status !== 'Pending') {   // tell the student the result (shows on their Notifications page)
                    try {
                        $etitle = explode(' ‖ ', (string)$ent['description'], 2)[0];
                        $msg = 'Your journal entry "' . $etitle . '" dated ' . date('d/m/Y', strtotime($ent['entry_date']))
                             . ($status === 'Approved' ? ' was APPROVED' : ' NEEDS REVISION') . ' by ' . $_SESSION['user']['name'] . '.'
                             . ($gradeNote !== '' ? ' Score: ' . $gradeNote . '.' : '')
                             . ($comment !== '' ? "\nComment: " . $comment : '');
                        $pdo->prepare("INSERT INTO notifications(audience,message,created_by,recipient_id) VALUES('All Students',?,?,?)")
                            ->execute([$msg, $userId, (int)$ent['student_id']]);
                        $sent = true;
                    } catch (PDOException $ne) { $notifErr = $ne->getMessage(); /* the review itself is still saved */ }
                }
                $okAll = ($sent || $status === 'Pending') && $gradeWarn === '';
                $m = $status === 'Pending' ? 'Review cleared.' : ($sent ? 'Review saved and sent to the student.' : 'Review saved, but the student could not be notified: ' . $notifErr);
                if ($gradeNote !== '') $m .= ' Score ' . $gradeNote . ' recorded in Grades.';
                if ($gradeWarn !== '') $m .= ' ' . $gradeWarn;
                flash($okAll ? 'ok' : 'warn', $m);
            }
        } elseif ($action === 'journal') {
            require_role(['Admin','Student']); // Faculty is view-only
            // Header textboxes (kept per student for the General Journal heading)
            $_SESSION['je_company'] = trim($_POST['company'] ?? '');
            $_SESSION['je_report']  = trim($_POST['report_title'] ?? '');
            $pd = DateTime::createFromFormat('Y-m-d', trim($_POST['period'] ?? ''));
            $_SESSION['je_period']  = $pd ? $pd->format('Y-m-t') : date('Y-m-t');
            // Also keep the heading in the database so faculty see it on every student's entries.
            try {
                ensure_report_headers($pdo);
                $pdo->prepare('INSERT INTO student_report_headers (student_id, company, report_title, period_end) VALUES (?,?,?,?)
                               ON DUPLICATE KEY UPDATE company=VALUES(company), report_title=VALUES(report_title), period_end=VALUES(period_end)')
                    ->execute([$userId, $_SESSION['je_company'], $_SESSION['je_report'], $_SESSION['je_period']]);
            } catch (PDOException $e) { /* heading still works from the session */ }
            $posted = 0; $bad = 0; $err = '';
            foreach (($_POST['title'] ?? []) as $i => $title) {
                $accs = $_POST['account_id'][$i] ?? [];
                // Amounts arrive formatted like 1,234.50 - strip the commas before saving.
                $deb  = array_map(fn($v) => str_replace(',', '', trim((string)$v)), $_POST['debit'][$i]  ?? []);
                $cre  = array_map(fn($v) => str_replace(',', '', trim((string)$v)), $_POST['credit'][$i] ?? []);
                if (!array_filter($accs, fn($x) => trim($x) !== '')) continue; // skip empty blocks
                $expl = trim($_POST['explanation'][$i] ?? '');
                $desc = trim($title) . ($expl !== '' ? ' ‖ ' . $expl : '');
                $dt = DateTime::createFromFormat('Y-m-d', trim($_POST['entry_date'][$i] ?? ''));
                if (!$dt || $dt->format('Y-m-d') !== trim($_POST['entry_date'][$i])) {
                    $bad++; $err = 'Please enter a valid date as DD/MM/YYYY.'; continue;
                }
                $r = save_journal_entry($pdo, $userId, $dt->format('Y-m-d'), $desc, $accs, $deb, $cre);
                if ($r['ok']) {
                    $posted++;
                    if (!$r['valid']) { $bad++; $err = $r['message']; }
                    audit($pdo, 'Recorded journal entry: ' . ($r['valid'] ? 'Valid' : 'Invalid'));
                } else { $bad++; $err = $r['message']; }
            }
            if ($posted === 0 && $bad === 0) flash('warn', 'Nothing to post — add at least one account line.');
            elseif ($bad === 0) flash('ok', $posted . ' journal ' . ($posted === 1 ? 'entry' : 'entries') . ' posted.');
            else flash('warn', $bad . ' of ' . max($posted, $bad) . ' entries need attention. ' . $err);
        }
    } catch (PDOException $e) {
        flash('warn', $e->getCode() === '23000' ? 'That account code or name is already taken. For every student to have their own copy of each account, run the latest migration_journal_review.sql.'
            : (in_array($action, ['review_entry','review_account'], true) ? 'Could not save the review. Run migration_journal_review.sql first.' : 'Database error: ' . $e->getMessage()));
    }
    $qs = '';   // keep the faculty's section / group / student filter after saving
    foreach (['sec','grp','stu'] as $k) if ((int)($_POST[$k] ?? 0) > 0) $qs .= '&' . $k . '=' . (int)$_POST[$k];
    header('Location: accounting.php?v=' . $v . $qs); exit;
}
 
// Accounts the Journal Entries page may use: a student's own accounts plus shared starter accounts; staff see all.
$accounts = [];
try {
    if ($role === 'Student' && coa_has_owner($pdo)) {
        $q = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE created_by=? OR created_by IS NULL ORDER BY account_code"); $q->execute([$userId]); $accounts = $q->fetchAll();
    } else $accounts = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code")->fetchAll();
} catch (PDOException $e) { $accounts = []; }

if ($v === 'coa') {
    $fSec = (int)($_GET['sec'] ?? 0); $fGrp = (int)($_GET['grp'] ?? 0); $fStu = (int)($_GET['stu'] ?? 0);
    $fSections = $fGroups = $fStudents = []; $coaRows = []; $me = ['section_name' => '', 'group_name' => ''];
    try {
        if ($role === 'Student') {
            $q = $pdo->prepare("SELECT sc.section_name, g.group_name FROM enrollments e JOIN sections sc ON sc.section_id=e.section_id LEFT JOIN student_groups g ON g.group_id=e.group_id WHERE e.student_id=? LIMIT 1");
            $q->execute([$userId]); $me = $q->fetch() ?: $me;
            if (coa_has_owner($pdo)) {
                $q = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE created_by=? OR created_by IS NULL ORDER BY (created_by IS NULL), account_code"); $q->execute([$userId]); $coaRows = $q->fetchAll();
            } else $coaRows = $accounts;
        } else {
            [$fSections, $fGroups, $fStudents] = review_filter_lists($pdo, $fSec, $fGrp);
            $w = []; $pr = [];
            if ($fStu)     { $w[] = 'a.created_by=?'; $pr[] = $fStu; }
            elseif ($fGrp) { $w[] = 'e.group_id=?';   $pr[] = $fGrp; }
            elseif ($fSec) { $w[] = 'e.section_id=?'; $pr[] = $fSec; }
            $q = $pdo->prepare("SELECT a.*, u.name owner_name, sc.section_name, g.group_name
                FROM chart_of_accounts a LEFT JOIN users u ON u.user_id=a.created_by
                LEFT JOIN enrollments e ON e.student_id=a.created_by LEFT JOIN sections sc ON sc.section_id=e.section_id
                LEFT JOIN student_groups g ON g.group_id=e.group_id"
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY (a.created_by IS NULL), u.name, a.account_code");
            $q->execute($pr); $coaRows = $q->fetchAll();
        }
    } catch (PDOException $e) {
        flash('warn', 'Some filters are unavailable (' . $e->getMessage() . '). Run migration_journal_review.sql.');
        $coaRows = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code")->fetchAll();
    }
    $coaGroups = []; $pendingAcc = 0;
    foreach ($coaRows as $a) {
        $k = (int)($a['created_by'] ?? 0);
        if (!isset($coaGroups[$k])) {
            $coaGroups[$k] = $role === 'Student' && $k === $userId
                ? ['name' => $_SESSION['user']['name'], 'section' => $me['section_name'] ?? '', 'group' => $me['group_name'] ?? '', 'rows' => []]
                : ['name' => $a['owner_name'] ?? '', 'section' => $a['section_name'] ?? '', 'group' => $a['group_name'] ?? '', 'rows' => []];
        }
        $coaGroups[$k]['rows'][] = $a;
        if ($k && (empty($a['review_status']) || $a['review_status'] === 'Pending')) $pendingAcc++;
    }
    $coaGrades = [];   // student_id => score row for the whole chart of accounts
    try {
        $gIds = array_values(array_filter(array_keys($coaGroups)));
        if ($gIds) {
            $gq = $pdo->prepare('SELECT coa_student_id, score, max_score FROM grades WHERE coa_student_id IN (' . implode(',', array_fill(0, count($gIds), '?')) . ')');
            $gq->execute($gIds);
            foreach ($gq->fetchAll() as $gr) $coaGrades[(int)$gr['coa_student_id']] = $gr;
        }
    } catch (PDOException $e) { /* coa grading column not added yet */ }
    $subtitle = $role === 'Faculty' ? "Check each student's chart of accounts, one student at a time."
              : ($role === 'Student' ? 'Add the accounts you will use in your journal entries. Your faculty will check them.' : 'Every student keeps their own chart of accounts.');
    layout_start('coa', 'Chart of Accounts', $subtitle);
?>

<?php if ($canReview): ?>
<form method="get" style="margin-bottom:12px">
  <input type="hidden" name="v" value="coa">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div style="min-width:170px"><label class="field-label">Section</label>
      <select name="sec" onchange="this.form.grp.value=0;this.form.stu.value=0;this.form.submit()">
        <option value="0">All sections</option>
        <?php foreach ($fSections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$fSec === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Group</label>
      <select name="grp" onchange="this.form.stu.value=0;this.form.submit()">
        <option value="0">All groups</option>
        <?php foreach ($fGroups as $g): ?><option value="<?=(int)$g['group_id']?>"<?=$fGrp === (int)$g['group_id'] ? ' selected' : ''?>><?=h($g['section_name'] . ' — ' . $g['group_name'])?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Student</label>
      <select name="stu" onchange="this.form.submit()">
        <option value="0">All students</option>
        <?php foreach ($fStudents as $s): ?><option value="<?=(int)$s['user_id']?>"<?=$fStu === (int)$s['user_id'] ? ' selected' : ''?>><?=h($s['name'])?></option><?php endforeach; ?>
      </select></div>
    <?php if ($fSec || $fGrp || $fStu): ?><a class="btn ghost small" href="accounting.php?v=coa">Clear filter</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="flex-between" style="margin-bottom:12px">
    <span class="muted small">
        <?=count($coaRows)?> accounts<?php if ($canReview): ?> · <?=$pendingAcc?> not yet reviewed<?php endif; ?> · as of <?=h(date('F d, Y'))?>
    </span>
    <?php if ($canAdd): ?>
        <button class="btn brass" onclick="document.getElementById('accDlg').showModal()">+ New account</button>
    <?php endif; ?>
</div>

<?php if (!$coaGroups): ?><div class="empty"><?=$role === 'Student' ? 'You have not added any accounts yet. Use “+ New account” to build your chart of accounts.' : 'No accounts to show.'?></div><?php endif; ?>

<?php foreach ($coaGroups as $k => $grp): ?>
<section class="block">
  <div style="text-align:left;margin:6px 0 6px;line-height:1.6">
    <?php if ($k): ?>
      <div><b>Name:</b> <?=h($grp['name'])?></div>
      <div><b>Section:</b> <?=h($grp['section'] !== '' ? $grp['section'] : 'Not enlisted')?><?php if ($grp['group'] !== ''): ?> &nbsp;·&nbsp; <b>Group:</b> <?=h($grp['group'])?><?php endif; ?></div>
      <?php $cg = $coaGrades[$k] ?? null; ?>
      <div><b>Score:</b> <?=$cg ? trim(grade_pill($cg['score'], $cg['max_score'])) : '<span class="small muted">Not graded yet</span>'?>
        <?php if ($canReview): ?>
        <button type="button" class="btn brass small" style="margin-left:8px"
                data-student-id="<?=(int)$k?>" data-student="<?=h($grp['name'])?>"
                data-score="<?=$cg ? h($cg['score'] + 0) : ''?>" data-max="<?=$cg ? h($cg['max_score'] + 0) : 100?>"
                onclick="openCoaGrade(this)">Grade chart of accounts</button>
        <?php endif; ?></div>
    <?php else: ?>
      <div><b>Starter accounts</b> <span class="small muted">— shared, not reviewed</span></div>
    <?php endif; ?>
  </div>
  <?php table_open(['Code','Account Name','Type','Debit/Credit Nature','Description','Review','Action'], [0,0,0,0,0,0,0]); ?>
  <?php foreach ($grp['rows'] as $a): ?>
    <tr>
      <td class="num"><?=h($a['account_code'])?></td>
      <td><?=h($a['account_name'])?></td>
      <td><?=h($a['account_type'])?></td>
      <td><?=h(normal_balance($a['account_type']))?></td>
      <td><?=h($a['description'] ?? '')?></td>
      <td><?=$k ? review_pill($a['review_status'] ?? '') : '<span class="small muted">—</span>'?></td>
      <td style="white-space:nowrap">
        <?php if ($canReview && $k): ?>
          <button type="button" class="btn brass small"
                  data-id="<?=(int)$a['account_id']?>" data-status="<?=h($a['review_status'] ?? 'Pending')?>" data-comment="<?=h($a['review_comment'] ?? '')?>"
                  data-label="<?=h($a['account_code'] . ' - ' . $a['account_name'])?>" data-student="<?=h($grp['name'])?>"
                  onclick="openAcctReview(this)">Review</button>
        <?php endif; ?>
        <?php if (coa_row_manageable($a)): ?>
          <button type="button" class="btn ghost small"
                  data-id="<?=(int)$a['account_id']?>" data-code="<?=h($a['account_code'])?>" data-name="<?=h($a['account_name'])?>"
                  data-type="<?=h($a['account_type'])?>" data-desc="<?=h($a['description'] ?? '')?>"
                  onclick="editAccount(this)">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Remove this account?')">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_account">
            <input type="hidden" name="v" value="coa"><input type="hidden" name="account_id" value="<?=(int)$a['account_id']?>">
            <button class="btn ghost small">Remove</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php if (!empty($a['review_comment'])): ?>
    <tr><td></td><td colspan="6"><b>Faculty feedback:</b> <?=nl2br(h($a['review_comment']))?></td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php table_close(7); ?>
</section>
<?php endforeach; ?>

<?php if ($canReview): ?>
<dialog id="acctReviewDlg" class="modal"><h3>Review account</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="review_account"><input type="hidden" name="v" value="coa">
  <input type="hidden" name="account_id" id="raId">
  <input type="hidden" name="sec" value="<?=$fSec?>"><input type="hidden" name="grp" value="<?=$fGrp?>"><input type="hidden" name="stu" value="<?=$fStu?>">
  <p class="small muted" style="margin:0">Student: <b id="raStudent"></b><br>Account: <b id="raLabel"></b></p>
  <div><label class="field-label">Result</label>
    <select name="review_status" id="raStatus">
      <option value="Approved">Approved — account is correct</option>
      <option value="Needs Revision">Needs revision — send back to student</option>
      <option value="Pending">Not yet reviewed (clear review)</option>
    </select></div>
  <div><label class="field-label">Comment (required when sending back)</label><textarea name="review_comment" id="raComment" placeholder="e.g. Wrong account type."></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save review</button></div>
</form></dialog>
<script>
function openAcctReview(b){
  document.getElementById('raId').value = b.dataset.id;
  document.getElementById('raStudent').textContent = b.dataset.student;
  document.getElementById('raLabel').textContent = b.dataset.label;
  document.getElementById('raStatus').value = b.dataset.status || 'Pending';
  document.getElementById('raComment').value = b.dataset.comment || '';
  document.getElementById('acctReviewDlg').showModal();
}
</script>
<dialog id="coaGradeDlg" class="modal"><h3>Grade chart of accounts</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="grade_coa"><input type="hidden" name="v" value="coa">
  <input type="hidden" name="student_id" id="cgId">
  <input type="hidden" name="sec" value="<?=$fSec?>"><input type="hidden" name="grp" value="<?=$fGrp?>"><input type="hidden" name="stu" value="<?=$fStu?>">
  <p class="small muted" style="margin:0">Student: <b id="cgStudent"></b></p>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div><label class="field-label">Score</label><input type="number" name="score" id="cgScore" step="0.01" min="0" placeholder="e.g. 18"></div>
    <div><label class="field-label">Out of</label><input type="number" name="max_score" id="cgMax" step="0.01" min="1" value="100"></div>
  </div>
  <p class="small muted" style="margin:0">One score for the student's whole chart of accounts. It is saved to their Grades. Leave the score blank to remove it.</p>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save score</button></div>
</form></dialog>
<script>
function openCoaGrade(b){
  document.getElementById('cgId').value = b.dataset.studentId;
  document.getElementById('cgStudent').textContent = b.dataset.student;
  document.getElementById('cgScore').value = b.dataset.score || '';
  document.getElementById('cgMax').value = b.dataset.max || 100;
  document.getElementById('coaGradeDlg').showModal();
}
</script>
<?php endif; ?>

<?php if ($canAdd): ?>

<dialog id="editAccDlg" class="modal">
    <h3>Edit Account</h3>
    <form method="post" class="form-grid cols-1">
        <input type="hidden" name="csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="edit_account">
        <input type="hidden" name="v" value="coa">
        <input type="hidden" name="account_id" id="eaId">
        <div><label class="field-label">Account Code</label><input name="code" id="eaCode" required></div>
        <div><label class="field-label">Account Name</label><input name="name" id="eaName" required></div>
        <div><label class="field-label">Type</label>
            <select name="type" id="eaType">
                <option>Asset</option><option>Liability</option><option>Equity</option><option>Revenue</option><option>Expense</option>
            </select></div>
        <div><label class="field-label">Description</label><input name="description" id="eaDesc" placeholder="Optional"></div>
        <div class="actions">
            <button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button>
            <button class="btn brass">Save changes</button>
        </div>
    </form>
</dialog>
<script>
function editAccount(b){
  document.getElementById('eaId').value   = b.dataset.id;
  document.getElementById('eaCode').value = b.dataset.code;
  document.getElementById('eaName').value = b.dataset.name;
  document.getElementById('eaType').value = b.dataset.type;
  document.getElementById('eaDesc').value = b.dataset.desc;
  document.getElementById('editAccDlg').showModal();
}
</script>

<dialog id="accDlg" class="modal">

    <h3>New Account</h3>

    <form method="post" class="form-grid cols-1">

        <input type="hidden"
               name="csrf"
               value="<?=h(csrf())?>">

        <input type="hidden"
               name="action"
               value="add_account">

        <input type="hidden"
               name="v"
               value="coa">

        <div>
            <label class="field-label">Account Code</label>
            <input
                name="code"
                required
                placeholder="e.g. 102">
        </div>

        <div>
            <label class="field-label">Account Name</label>
            <input
                name="name"
                required
                placeholder="e.g. Supplies">
        </div>

        <div>
            <label class="field-label">Type</label>

            <select name="type">
                <option>Asset</option>
                <option>Liability</option>
                <option>Equity</option>
                <option>Revenue</option>
                <option>Expense</option>
            </select>
        </div>

        <div>
            <label class="field-label">Description</label>

            <input
                name="description"
                placeholder="Optional">
        </div>

        <div class="actions">

            <button
                type="button"
                class="btn ghost"
                onclick="this.closest('dialog').close()">
                Cancel
            </button>

            <button class="btn brass">
                Add Account
            </button>

        </div>

    </form>

</dialog>

<?php endif;

    layout_end();
    exit;
}
 
// ---------- Journal Entries ----------
$fSec = (int)($_GET['sec'] ?? 0); $fGrp = (int)($_GET['grp'] ?? 0); $fStu = (int)($_GET['stu'] ?? 0);
$fSections = $fGroups = $fStudents = [];
{
    // Admin / Faculty: all entries, optionally narrowed to one student, one group or one section.
    try {
        $w = []; $p = [];
        if ($role === 'Student') { $w[] = 'j.student_id=?'; $p[] = $userId; }
        elseif ($fStu)  { $w[] = 'j.student_id=?'; $p[] = $fStu; }
        elseif ($fGrp)  { $w[] = 'e.group_id=?';   $p[] = $fGrp; }
        elseif ($fSec)  { $w[] = 'e.section_id=?'; $p[] = $fSec; }
        $st = $pdo->prepare("SELECT j.*, u.name, sc.section_name, g.group_name, gr.score AS grade_score, gr.max_score AS grade_max,
                (SELECT COALESCE(SUM(l.debit_amount),0) FROM journal_lines l WHERE l.entry_id=j.entry_id) AS total_debit
            FROM journal_entries j JOIN users u ON u.user_id=j.student_id
            LEFT JOIN enrollments e ON e.student_id=j.student_id
            LEFT JOIN sections sc ON sc.section_id=e.section_id
            LEFT JOIN student_groups g ON g.group_id=e.group_id
            LEFT JOIN grades gr ON gr.entry_id=j.entry_id"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY j.entry_id DESC LIMIT 200");
        $st->execute($p); $entries = $st->fetchAll();

        $fSections = $pdo->query("SELECT s.section_id, s.section_name, c.course_code FROM sections s JOIN courses c ON c.course_id=s.course_id ORDER BY c.course_code, s.section_name")->fetchAll();
        $q = $pdo->prepare("SELECT g.group_id, g.group_name, s.section_name FROM student_groups g JOIN sections s ON s.section_id=g.section_id WHERE (?=0 OR g.section_id=?) ORDER BY s.section_name, g.group_name");
        $q->execute([$fSec, $fSec]); $fGroups = $q->fetchAll();
        $q = $pdo->prepare("SELECT u.user_id, u.name FROM users u JOIN enrollments e ON e.student_id=u.user_id
            WHERE u.role='Student' AND (?=0 OR e.section_id=?) AND (?=0 OR e.group_id=?) ORDER BY u.name");
        $q->execute([$fSec, $fSec, $fGrp, $fGrp]); $fStudents = $q->fetchAll();
    } catch (PDOException $e) {
        flash('warn', 'Filters unavailable (' . $e->getMessage() . '). Showing all entries.');
        $entries = recent_journal_entries($pdo, 50, $role === 'Student' ? $userId : null);
    }
}
$entries = array_reverse($entries);   // oldest first: the 1st entry on top, the newest at the bottom
$pendingCount = 0;
foreach ($entries as $e0) if (empty($e0['review_status']) || $e0['review_status'] === 'Pending') $pendingCount++;
function review_pill($s) {
    $s = $s ?: 'Pending';
    $cls = $s === 'Approved' ? 'ok' : ($s === 'Needs Revision' ? 'warn' : 'neutral');
    return '<span class="pill ' . $cls . '">' . h($s === 'Pending' ? 'Not yet reviewed' : $s) . '</span>';
}
function grade_pill($sc, $mx) {
    if ($sc === null || $mx === null) return '';
    return ' <span class="pill ok">Score ' . h(($sc + 0) . ' / ' . ($mx + 0)) . '</span>';
}
$lines   = journal_lines_for($pdo, array_column($entries, 'entry_id'));
// No entries left (e.g. all deleted): reset the saved heading so the page starts fresh.
reset_reports_if_empty($pdo);
$headers = [];   // student_id => [company, report_title, period_end]
try {
    ensure_report_headers($pdo);
    $ids = array_values(array_unique(array_map('intval', array_column($entries, 'student_id'))));
    if ($ids) {
        $hq = $pdo->prepare('SELECT * FROM student_report_headers WHERE student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $hq->execute($ids);
        foreach ($hq->fetchAll() as $hr) $headers[(int)$hr['student_id']] = $hr;
    }
} catch (PDOException $e) { $headers = []; }
// A student who logs in again gets their saved heading back in the form.
if ($role === 'Student' && $entries && !isset($_SESSION['je_company']) && isset($headers[$userId])) {
    $_SESSION['je_company'] = $headers[$userId]['company'];
    $_SESSION['je_report']  = $headers[$userId]['report_title'];
    if (!empty($headers[$userId]['period_end'])) $_SESSION['je_period'] = $headers[$userId]['period_end'];
}
$company = $_SESSION['je_company'] ?? '';
$report  = $_SESSION['je_report']  ?? 'Chart of Accounts';
$period  = $_SESSION['je_period']  ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) $period = date('Y-m-t'); // ignore old text values
layout_start('journal', 'Journal Entries',
    'Enter the company details, then record each transaction from the business scenario. The validation engine checks that every entry balances before it posts.',
    'AI-assisted validation');

// Split stored description into entry title + explanation.
function je_split($d) { $p = explode(' ‖ ', (string)$d, 2); return [$p[0], $p[1] ?? '']; }
// Display dates as DD/MM/YYYY.
function je_date_label($d) { return date('d/m/Y', strtotime($d)); }

// DD/MM/YYYY date field. The browser's own <input type="date"> always shows the
// device's locale format (MM/DD/YYYY here), so we show a text box in DD/MM/YYYY and
// keep the real value (YYYY-MM-DD) in a hidden input that the server reads.
function dmy_input($name, $iso, $eom = false) {
    $shown = date('d/m/Y', strtotime($iso)); ?>
    <span class="dmy"<?=$eom ? ' data-eom="1"' : ''?>>
      <input type="text" class="dmy-text" value="<?=h($shown)?>" placeholder="DD/MM/YYYY" inputmode="numeric" maxlength="10" autocomplete="off" required>
      <input type="hidden" class="dmy-iso" name="<?=h($name)?>" value="<?=h($iso)?>">
      <button type="button" class="dmy-btn" aria-label="Open calendar" title="Pick from calendar">&#128197;</button>
      <input type="date" class="dmy-native" value="<?=h($iso)?>" tabindex="-1" aria-hidden="true">
    </span>
<?php }

function je_line($i) { ?>
    <div class="je-line">
      <div><label class="field-label">Account title</label>
        <input type="text" name="account_id[<?=$i?>][]" list="acctList" placeholder="e.g. Cash" autocomplete="off"></div>
      <div><label class="field-label">Debit (₱)</label><input type="text" class="amt" inputmode="decimal" autocomplete="off" placeholder="0.00" name="debit[<?=$i?>][]" oninput="updateTotals(this)"></div>
      <div><label class="field-label">Credit (₱)</label><input type="text" class="amt" inputmode="decimal" autocomplete="off" placeholder="0.00" name="credit[<?=$i?>][]" oninput="updateTotals(this)"></div>
      <div><button type="button" class="btn ghost small" onclick="removeLine(this)">Remove</button></div>
    </div>
<?php }

function je_block($i) { ?>
  <div class="card je-block" style="margin-top:12px">
    <div class="form-grid" style="display:grid">
      <div><label class="field-label">Date</label>
        <?php dmy_input("entry_date[$i]", date('Y-m-d')); ?></div>
      <div><label class="field-label">Entry title</label>
        <input name="title[<?=$i?>]" placeholder="e.g. Starting capital" required></div>
    </div>
    <div class="je-lines" style="margin-top:12px"><?php je_line($i); je_line($i); ?></div>
    <button type="button" class="btn ghost small" style="margin-top:8px" onclick="addLine(this)">+ Add line</button>
    <div style="margin-top:12px"><label class="field-label">Explanation</label>
      <input name="explanation[<?=$i?>]" placeholder="e.g. To record the initial cash investment."></div>
    <div class="je-totals"></div>
    <div class="je-banner"></div>
    <div style="margin-top:8px"><button type="button" class="btn ghost small" onclick="removeBlock(this)">Remove this entry</button></div>
  </div>
<?php } ?>

<?php if ($canJournal): ?>
<datalist id="acctList">
<?php foreach ($accounts as $a): ?><option value="<?=h($a['account_name'])?>"><?=h($a['account_code'])?></option><?php endforeach; ?>
</datalist>

<section class="block"><h2>New journal entries</h2>
<div class="card">
<form method="post" id="jeForm">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="journal"><input type="hidden" name="v" value="journal">
  <div class="form-grid" style="display:grid">
    <div><label class="field-label">Company name</label>
      <input name="company" value="<?=h($company)?>" required placeholder="e.g. ABC School Supplies"></div>
    <div><label class="field-label">Title of report</label>
      <input name="report_title" value="<?=h($report)?>" required placeholder="e.g. Chart of Accounts"></div>
    <div><label class="field-label">Period</label>
      <?php dmy_input('period', $period, true); ?>
      <span class="small muted">End of month: <b class="eom-label"><?=h(date('d/m/Y', strtotime($period)))?></b> (DD/MM/YYYY)</span></div>
  </div>
  <p class="small muted" style="margin-top:8px">Account titles come from the business scenario task. Pick from the chart of accounts or type your own; debit lines first, then indented credit lines.</p>
  <div id="jeBlocks"><?php je_block(0); ?></div>
  <button type="button" class="btn ghost small" style="margin-top:12px" onclick="addBlock()">+ Add another entry</button>
  <div style="margin-top:12px"><button type="submit" class="btn brass">Validate &amp; post entries</button></div>
</form></div></section>

<template id="blockTpl"><?php je_block('__i__'); ?></template>
<?php else: ?>
<p class="small muted" style="margin-bottom:12px">Faculty can check student journal entries (filter by section, group or student, then use Review to approve or send back with comments) but cannot add or delete entries.</p>
<?php endif; ?>

<?php if ($canReview): ?>
<form method="get" style="margin-bottom:12px">
  <input type="hidden" name="v" value="journal">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div style="min-width:170px"><label class="field-label">Section</label>
      <select name="sec" onchange="this.form.grp.value=0;this.form.stu.value=0;this.form.submit()">
        <option value="0">All sections</option>
        <?php foreach ($fSections as $s): ?><option value="<?=(int)$s['section_id']?>"<?=$fSec === (int)$s['section_id'] ? ' selected' : ''?>><?=h($s['section_name'] . ' (' . $s['course_code'] . ')')?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Group</label>
      <select name="grp" onchange="this.form.stu.value=0;this.form.submit()">
        <option value="0">All groups</option>
        <?php foreach ($fGroups as $g): ?><option value="<?=(int)$g['group_id']?>"<?=$fGrp === (int)$g['group_id'] ? ' selected' : ''?>><?=h($g['section_name'] . ' — ' . $g['group_name'])?></option><?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label class="field-label">Student</label>
      <select name="stu" onchange="this.form.submit()">
        <option value="0">All students</option>
        <?php foreach ($fStudents as $s): ?><option value="<?=(int)$s['user_id']?>"<?=$fStu === (int)$s['user_id'] ? ' selected' : ''?>><?=h($s['name'])?></option><?php endforeach; ?>
      </select></div>
    <?php if ($fSec || $fGrp || $fStu): ?><a class="btn ghost small" href="accounting.php?v=journal">Clear filter</a><?php endif; ?>
  </div>
  <p class="small muted" style="margin:8px 0 0"><?=count($entries)?> entr<?=count($entries) === 1 ? 'y' : 'ies'?> shown · <?=$pendingCount?> not yet reviewed</p>
</form>
<?php endif; ?>

<section class="block">
  <div style="text-align:center;margin-bottom:10px">
    <h2 style="margin-top:8px">GENERAL JOURNAL</h2>
  </div>
<?php if (!$entries): ?><div class="empty">No journal entries yet.</div><?php else:
  $byStudent = [];
  foreach ($entries as $e) $byStudent[$e['student_id']][] = $e;   // one block per student
  foreach ($byStudent as $stuEntries):
    $f = $stuEntries[0];
    $hd = $headers[(int)$f['student_id']] ?? null;
    $hCompany = $hd['company'] ?? ((int)$f['student_id'] === (int)$userId ? $company : '');
    $hReport  = $hd['report_title'] ?? ((int)$f['student_id'] === (int)$userId ? $report : '');
    $hPeriod  = !empty($hd['period_end']) ? $hd['period_end'] : ((int)$f['student_id'] === (int)$userId ? $period : ''); ?>
  <div style="text-align:left;margin:18px 0 6px;line-height:1.6">
    <div><b>Company name:</b> <?=h($hCompany !== '' ? $hCompany : '—')?></div>
    <div><b>Title of report:</b> <?=h($hReport !== '' ? $hReport : '—')?></div>
    <div><b>End of Month:</b> <?=h($hPeriod !== '' ? date('d/m/Y', strtotime($hPeriod)) : '—')?></div>
    <div><b>Name:</b> <?=h($f['name'])?></div>
    <div><b>Section:</b> <?=h(!empty($f['section_name']) ? $f['section_name'] : 'Not enlisted')?><?php if (!empty($f['group_name'])): ?> &nbsp;·&nbsp; <b>Group:</b> <?=h($f['group_name'])?><?php endif; ?></div>
  </div>
<?php table_open(['Date','Account Titles and Explanation','Debit (₱)','Credit (₱)'], [0,0,1,1]);
  foreach ($stuEntries as $e):
    [$title, $expl] = je_split($e['description']);
    $ls = $lines[$e['entry_id']] ?? []; ?>
    <tr>
      <td><b><?=h(je_date_label($e['entry_date']))?></b></td>
      <td><b><?=h($title)?></b></td>
      <td colspan="2" style="text-align:right">
        <?=pill($e['ai_validation_status'])?> <?=review_pill($e['review_status'] ?? '')?><?=grade_pill($e['grade_score'] ?? null, $e['grade_max'] ?? null)?>
        <?php if ($canReview): ?>
        <button type="button" class="btn brass small"
          data-id="<?=(int)$e['entry_id']?>" data-status="<?=h($e['review_status'] ?? 'Pending')?>" data-comment="<?=h($e['review_comment'] ?? '')?>"
          data-student="<?=h($e['name'])?>" data-score="<?=isset($e['grade_score']) ? h($e['grade_score'] + 0) : ''?>" data-max="<?=h(isset($e['grade_max']) ? $e['grade_max'] + 0 : 100)?>" onclick="openReview(this)">Review</button>
        <?php endif; ?>
        <?php if ($canJournal): ?>
        <button type="button" class="btn ghost small" onclick="editEntry(this)" data-entry="<?=h(json_encode([
            'id' => (int)$e['entry_id'], 'date' => $e['entry_date'], 'title' => $title, 'expl' => $expl,
            'company' => $hCompany, 'report' => $hReport, 'period' => ($hPeriod !== '' ? $hPeriod : date('Y-m-t')),
            'lines' => array_map(function ($l) { return ['a' => $l['account_name'], 'd' => (float)$l['debit_amount'], 'c' => (float)$l['credit_amount']]; }, $ls)
          ], JSON_UNESCAPED_UNICODE))?>">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this journal entry? This cannot be undone.')">
          <input type="hidden" name="csrf" value="<?=h(csrf())?>">
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="v" value="journal">
          <input type="hidden" name="entry_id" value="<?=(int)$e['entry_id']?>">
          <button class="btn ghost small">Delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php foreach ($ls as $l): $isCredit = $l['credit_amount'] > 0 && !($l['debit_amount'] > 0); ?>
    <tr>
      <td></td>
      <td style="padding-left:<?=$isCredit ? '36' : '12'?>px"><?=h($l['account_name'])?></td>
      <td class="num"><?=$l['debit_amount'] > 0 ? h(peso($l['debit_amount'])) : ''?></td>
      <td class="num"><?=$l['credit_amount'] > 0 ? h(peso($l['credit_amount'])) : ''?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($expl !== ''): ?>
    <tr><td></td><td colspan="3" style="padding-left:12px"><i><?=h($expl)?></i></td></tr>
    <?php endif; ?>
    <?php if (!empty($e['review_comment'])): ?>
    <tr><td></td><td colspan="3" style="padding-left:12px"><b>Faculty feedback:</b> <?=nl2br(h($e['review_comment']))?></td></tr>
    <?php endif;
  endforeach;
  table_close(4);
  endforeach;
endif; ?>
</section>

<?php if ($canJournal): ?>
<dialog id="editEntryDlg" class="modal" style="max-width:760px">
  <h3>Edit journal entry</h3>
  <form method="post" class="form-grid cols-1">
    <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="edit_entry"><input type="hidden" name="v" value="journal">
    <input type="hidden" name="entry_id" id="eeId">
    <div><label class="field-label">Company name</label><input name="company" id="eeCompany" required placeholder="e.g. ABC School Supplies"></div>
    <div><label class="field-label">Title of report</label><input name="report_title" id="eeReport" required placeholder="e.g. Chart of Accounts"></div>
    <div id="eePeriodWrap"><label class="field-label">End of month</label><?php dmy_input('period', date('Y-m-t'), true); ?></div>
    <div id="eeDateWrap"><label class="field-label">Date</label><?php dmy_input('entry_date', date('Y-m-d')); ?></div>
    <div><label class="field-label">Entry title</label><input name="title" id="eeTitle" required></div>
    <div class="je-block">
      <div class="je-lines" id="eeLines"></div>
      <button type="button" class="btn ghost small" style="margin-top:8px" onclick="addEditLine(null)">+ Add line</button>
      <div class="je-totals"></div>
      <div class="je-banner"></div>
    </div>
    <div><label class="field-label">Explanation</label><input name="explanation" id="eeExpl"></div>
    <p class="small muted" style="margin:0">Saving re-checks that debits equal credits. An edited entry goes back to "not yet reviewed" for faculty and its score (if any) is cleared so it can be graded again.</p>
    <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save changes</button></div>
  </form>
</dialog>
<script>
function addEditLine(l){
  var r = document.createElement('div'); r.className = 'je-line';
  r.innerHTML = '<div><label class="field-label">Account title</label><input type="text" name="account[]" list="acctList" autocomplete="off"></div>'
    + '<div><label class="field-label">Debit (₱)</label><input type="text" class="amt" inputmode="decimal" autocomplete="off" placeholder="0.00" name="debit[]" oninput="updateTotals(this)"></div>'
    + '<div><label class="field-label">Credit (₱)</label><input type="text" class="amt" inputmode="decimal" autocomplete="off" placeholder="0.00" name="credit[]" oninput="updateTotals(this)"></div>'
    + '<div><button type="button" class="btn ghost small" onclick="removeEditLine(this)">Remove</button></div>';
  var f = r.querySelectorAll('input');
  if (l) { f[0].value = l.a; f[1].value = l.d > 0 ? fmtAmt(l.d) : ''; f[2].value = l.c > 0 ? fmtAmt(l.c) : ''; }
  document.getElementById('eeLines').appendChild(r);
}
function removeEditLine(btn){
  var w = document.getElementById('eeLines');
  if (w.children.length > 2) { btn.closest('.je-line').remove(); updateTotals(w.querySelector('input')); }
}
function editEntry(btn){
  var d = JSON.parse(btn.dataset.entry), w = document.getElementById('eeLines');
  document.getElementById('eeId').value = d.id;
  document.getElementById('eeTitle').value = d.title;
  document.getElementById('eeExpl').value = d.expl;
  document.getElementById('eeCompany').value = d.company || '';
  document.getElementById('eeReport').value = d.report || '';
  dmySet(document.querySelector('#eePeriodWrap .dmy'), d.period);
  dmySet(document.querySelector('#eeDateWrap .dmy'), d.date);
  w.innerHTML = '';
  d.lines.forEach(addEditLine);
  while (w.children.length < 2) addEditLine(null);
  updateTotals(w.querySelector('input'));
  document.getElementById('editEntryDlg').showModal();
}
</script>
<?php endif; ?>

<?php if ($canReview): ?>
<dialog id="reviewDlg" class="modal"><h3>Review journal entry</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="review_entry"><input type="hidden" name="v" value="journal">
  <input type="hidden" name="entry_id" id="rvId">
  <input type="hidden" name="sec" value="<?=$fSec?>"><input type="hidden" name="grp" value="<?=$fGrp?>"><input type="hidden" name="stu" value="<?=$fStu?>">
  <p class="small muted" style="margin:0">Student: <b id="rvStudent"></b></p>
  <div><label class="field-label">Result</label>
    <select name="review_status" id="rvStatus">
      <option value="Approved">Approved — entry is correct</option>
      <option value="Needs Revision">Needs revision — send back to student</option>
      <option value="Pending">Not yet reviewed (clear review)</option>
    </select></div>
  <div><label class="field-label">Comment (required when sending back)</label><textarea name="review_comment" id="rvComment" placeholder="e.g. Check the account used for the credit line."></textarea></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div><label class="field-label">Score (optional)</label><input type="number" name="score" id="rvScore" step="0.01" min="0" placeholder="e.g. 18"></div>
    <div><label class="field-label">Out of</label><input type="number" name="max_score" id="rvMax" step="0.01" min="1" value="100"></div>
  </div>
  <p class="small muted" style="margin:0">A score is saved to the student's Grades. Leave it blank to record no grade.</p>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save review</button></div>
</form></dialog>
<script>
function openReview(btn){
  document.getElementById('rvId').value = btn.dataset.id;
  document.getElementById('rvStudent').textContent = btn.dataset.student;
  document.getElementById('rvStatus').value = btn.dataset.status || 'Pending';
  document.getElementById('rvComment').value = btn.dataset.comment || '';
  document.getElementById('rvScore').value = btn.dataset.score || '';
  document.getElementById('rvMax').value = btn.dataset.max || 100;
  document.getElementById('reviewDlg').showModal();
}
</script>
<?php endif; ?>

<style>
.dmy{position:relative;display:block}
.dmy .dmy-text{width:100%;padding-right:40px;box-sizing:border-box}
.dmy .dmy-btn{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;font-size:16px;line-height:1;padding:4px;color:inherit}
.dmy .dmy-native{position:absolute;right:0;bottom:0;width:1px;height:1px;opacity:0;pointer-events:none;border:0;padding:0}
</style>

<script>
var blockIdx = 1;
function peso(n){ return '₱' + n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function sumIn(block, key){ var t=0; block.querySelectorAll('input[name^="'+key+'["]').forEach(function(i){ t+=parseFloat(String(i.value).replace(/,/g,''))||0; }); return t; }
function updateTotals(el){
  var blk = el.closest('.je-block');
  var d=sumIn(blk,'debit'), c=sumIn(blk,'credit'), diff=Math.round((d-c)*100)/100;
  blk.querySelector('.je-totals').innerHTML='<span>Total debits <b class="num">'+peso(d)+'</b></span> <span>Total credits <b class="num">'+peso(c)+'</b></span>';
  var b=blk.querySelector('.je-banner');
  if((d||c) && diff!==0) b.innerHTML='<div class="banner warn" style="margin-top:10px">⚠ Not balanced — debits and credits differ by '+peso(Math.abs(diff))+'. The entry will be saved as Unbalanced.</div>';
  else if(d && c && diff===0) b.innerHTML='<div class="banner ok" style="margin-top:10px">✓ Balanced — debits equal credits.</div>';
  else b.innerHTML='';
}
function pad2(n){ return ('0'+n).slice(-2); }
// 'DD/MM/YYYY' -> 'YYYY-MM-DD', or null if it is not a real date
function dmyParse(t){
  var m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(t); if(!m) return null;
  var d=+m[1], mo=+m[2], y=+m[3], x=new Date(y, mo-1, d);
  if(x.getFullYear()!==y || x.getMonth()!==mo-1 || x.getDate()!==d) return null;
  return m[3]+'-'+m[2]+'-'+m[1];
}
// Put an ISO date into a .dmy field (text box, hidden value, native picker, label)
function dmySet(box, iso){
  var p=iso.split('-'), y=+p[0], m=+p[1], d=+p[2];
  if(box.getAttribute('data-eom')){ d=new Date(y, m, 0).getDate(); }   // snap to end of month
  iso = y+'-'+pad2(m)+'-'+pad2(d);
  var t=box.querySelector('.dmy-text');
  t.value = pad2(d)+'/'+pad2(m)+'/'+y;
  t.setCustomValidity('');
  box.querySelector('.dmy-iso').value = iso;
  box.querySelector('.dmy-native').value = iso;
  var lbl = box.parentNode.querySelector('.eom-label');
  if(lbl) lbl.textContent = t.value;
}
// Typing: auto-insert the slashes, accept only digits
document.addEventListener('input', function(e){
  var t=e.target; if(!t.classList || !t.classList.contains('dmy-text')) return;
  var box=t.closest('.dmy'), digits=t.value.replace(/\D/g,'').slice(0,8), out=digits;
  if(digits.length>4) out=digits.slice(0,2)+'/'+digits.slice(2,4)+'/'+digits.slice(4);
  else if(digits.length>2) out=digits.slice(0,2)+'/'+digits.slice(2);
  t.value = out;
  var iso = dmyParse(out);
  if(iso){ dmySet(box, iso); }
  else { box.querySelector('.dmy-iso').value=''; t.setCustomValidity('Enter a valid date as DD/MM/YYYY'); }
});
// Leaving the box with a half-typed date: flag it
document.addEventListener('focusout', function(e){
  var t=e.target; if(!t.classList || !t.classList.contains('dmy-text')) return;
  if(!dmyParse(t.value)) t.setCustomValidity('Enter a valid date as DD/MM/YYYY');
});
// Calendar button opens the native picker; picking a day fills the field
document.addEventListener('click', function(e){
  var b=e.target.closest ? e.target.closest('.dmy-btn') : null; if(!b) return;
  var n=b.closest('.dmy').querySelector('.dmy-native');
  if(n.showPicker){ try{ n.showPicker(); return; }catch(x){} }
  n.focus(); n.click();
});
document.addEventListener('change', function(e){
  var n=e.target; if(!n.classList || !n.classList.contains('dmy-native') || !n.value) return;
  dmySet(n.closest('.dmy'), n.value);
});
// Amount boxes: while typing keep digits and one dot; on leaving show 1,234.50
function fmtAmt(v){
  var n=parseFloat(String(v).replace(/,/g,''));
  return isNaN(n) ? '' : n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}
document.addEventListener('input', function(e){
  var t=e.target; if(!t.classList || !t.classList.contains('amt')) return;
  var v=t.value.replace(/[^\d.]/g,''), i=v.indexOf('.');
  if(i>-1) v=v.slice(0,i+1)+v.slice(i+1).replace(/\./g,'').slice(0,2);
  t.value=v;
});
document.addEventListener('focusout', function(e){
  var t=e.target; if(!t.classList || !t.classList.contains('amt')) return;
  t.value=fmtAmt(t.value); updateTotals(t);
});
document.addEventListener('focusin', function(e){
  var t=e.target; if(!t.classList || !t.classList.contains('amt')) return;
  t.value=t.value.replace(/,/g,'');
});
function addLine(btn){
  var w=btn.closest('.je-block').querySelector('.je-lines'), r=w.lastElementChild.cloneNode(true);
  r.querySelectorAll('input').forEach(function(i){ i.value=''; });
  w.appendChild(r); updateTotals(btn);
}
function removeLine(btn){
  var w=btn.closest('.je-lines');
  if(w.children.length>2){ var blk=btn.closest('.je-block'); btn.closest('.je-line').remove(); updateTotals(blk.querySelector('input')); }
}
function addBlock(){
  var html=document.getElementById('blockTpl').innerHTML.replace(/__i__/g, blockIdx++);
  document.getElementById('jeBlocks').insertAdjacentHTML('beforeend', html);
}
function removeBlock(btn){
  var w=document.getElementById('jeBlocks');
  if(w.children.length>1) btn.closest('.je-block').remove();
}
</script>
<?php layout_end(); ?>