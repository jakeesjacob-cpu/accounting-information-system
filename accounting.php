<?php
require 'config.php'; require 'layout.php'; require_login();
$role    = $_SESSION['user']['role'];
$userId  = (int)$_SESSION['user']['user_id'];
$canEdit = in_array($role, ['Admin','Faculty'], true);
$canAdd  = in_array($role, ['Admin','Faculty','Student'], true);
$v = (($_POST['v'] ?? $_GET['v'] ?? 'coa') === 'journal') ? 'journal' : 'coa';
 
// Normal balance side for each account type ("Debit/Credit Nature" column).
function normal_balance($type) {
    return in_array($type, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit';
}
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_account') {
            require_role(['Admin','Faculty','Student']);
            $stmt = $pdo->prepare("INSERT INTO chart_of_accounts(account_code,account_name,account_type,description) VALUES(?,?,?,?)");
            $stmt->execute([trim($_POST['code']), trim($_POST['name']), $_POST['type'], trim($_POST['description'] ?? '')]);
            audit($pdo, 'Added chart of account');
            flash('ok', 'Account added.');
        } elseif ($action === 'delete_account') {
            require_role(['Admin','Faculty']);
            $id = (int)($_POST['account_id'] ?? 0);
            $used = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id=?");
            $used->execute([$id]);
            if ($used->fetchColumn() > 0) {
                flash('warn', 'Cannot remove — account is used in journal entries.');
            } else {
                $pdo->prepare("DELETE FROM chart_of_accounts WHERE account_id=?")->execute([$id]);
                audit($pdo, 'Removed a chart-of-accounts entry');
                flash('ok', 'Account removed.');
            }
        } elseif ($action === 'delete_entry') {
            // Students may delete only their own entries; Admin/Faculty may delete any.
            $id = (int)($_POST['entry_id'] ?? 0);
            $own = $pdo->prepare("SELECT student_id FROM journal_entries WHERE entry_id=?");
            $own->execute([$id]);
            $owner = $own->fetchColumn();
            if ($owner === false) {
                flash('warn', 'Journal entry not found.');
            } elseif ($role === 'Student' && (int)$owner !== $userId) {
                flash('warn', 'You can only delete your own journal entries.');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM journal_lines WHERE entry_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM journal_entries WHERE entry_id=?")->execute([$id]);
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                audit($pdo, 'Deleted a journal entry');
                flash('ok', 'Journal entry deleted.');
            }
        } elseif ($action === 'journal') {
            // Header textboxes (kept per student for the General Journal heading)
            $_SESSION['je_company'] = trim($_POST['company'] ?? '');
            $_SESSION['je_report']  = trim($_POST['report_title'] ?? '');
            $pd = DateTime::createFromFormat('Y-m-d', trim($_POST['period'] ?? ''));
            $_SESSION['je_period']  = $pd ? $pd->format('Y-m-t') : date('Y-m-t');
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
        flash('warn', $e->getCode() === '23000' ? 'That account code already exists.' : 'Database error.');
    }
    header('Location: accounting.php?v=' . $v); exit;
}
 
$accounts = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code")->fetchAll();
 
if ($v === 'coa') {
    layout_start('coa', 'Chart of Accounts', 'All accounts in one organized table.');
?>

<div class="flex-between" style="margin-bottom:12px">
    <span class="muted small">
        <?=count($accounts)?> accounts · as of <?=h(date('F d, Y'))?>
    </span>

    <?php if ($canAdd): ?>
        <button class="btn brass" onclick="document.getElementById('accDlg').showModal()">
            + New account
        </button>
    <?php endif; ?>
</div>

<section class="block">

    <?php table_open(
        ['Code','Account Name','Type','Debit/Credit Nature','Description','Action'],
        [0,0,0,0,0,0]
    ); ?>

    <?php foreach ($accounts as $a): ?>

        <tr>
            <td class="num">
                <?=h($a['account_code'])?>
            </td>

            <td>
                <?=h($a['account_name'])?>
            </td>

            <td>
                <?=h($a['account_type'])?>
            </td>

            <td>
                <?=h(normal_balance($a['account_type']))?>
            </td>

            <td>
                <?=h($a['description'] ?? '')?>
            </td>

            <td style="width:110px">
                <?php if ($canEdit): ?>

                    <form method="post"
                          onsubmit="return confirm('Remove this account?')">

                        <input type="hidden"
                               name="csrf"
                               value="<?=h(csrf())?>">

                        <input type="hidden"
                               name="action"
                               value="delete_account">

                        <input type="hidden"
                               name="v"
                               value="coa">

                        <input type="hidden"
                               name="account_id"
                               value="<?=(int)$a['account_id']?>">

                        <button class="btn ghost small">
                            Remove
                        </button>

                    </form>

                <?php endif; ?>
            </td>
        </tr>

    <?php endforeach; ?>

    <?php table_close(6); ?>

</section>

<?php if ($canAdd): ?>

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
$entries = recent_journal_entries($pdo, 50, $role === 'Student' ? $userId : null);
$lines   = journal_lines_for($pdo, array_column($entries, 'entry_id'));
// No entries left (e.g. all deleted): reset the saved heading so the page starts fresh.
if (!$entries) unset($_SESSION['je_company'], $_SESSION['je_report'], $_SESSION['je_period']);
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

<section class="block">
  <div style="text-align:center;margin-bottom:10px">
    <b><?=h($company !== '' ? $company : 'Company name')?></b><br>
    <b><?=h($report !== '' ? $report : 'Chart of Accounts')?></b><br>
    <span class="small muted">End of Month: <?=h(date('d/m/Y', strtotime($period)))?></span><br>
    <h2 style="margin-top:8px">GENERAL JOURNAL <span class="tag"><?=count($entries)?> shown</span></h2>
  </div>
<?php if (!$entries): ?><div class="empty">No journal entries yet.</div><?php else:
  table_open(['Date','Account Titles and Explanation','Debit (₱)','Credit (₱)'], [0,0,1,1]);
  foreach ($entries as $e):
    [$title, $expl] = je_split($e['description']);
    $ls = $lines[$e['entry_id']] ?? []; ?>
    <tr>
      <td><b><?=h(je_date_label($e['entry_date']))?></b></td>
      <td><b><?=h($title)?></b> <span class="small muted">— <?=h($e['name'])?></span></td>
      <td colspan="2" style="text-align:right">
        <?=pill($e['ai_validation_status'])?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this journal entry? This cannot be undone.')">
          <input type="hidden" name="csrf" value="<?=h(csrf())?>">
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="v" value="journal">
          <input type="hidden" name="entry_id" value="<?=(int)$e['entry_id']?>">
          <button class="btn ghost small">Delete</button>
        </form>
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
    <?php endif;
  endforeach;
  table_close(4);
endif; ?>
</section>

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