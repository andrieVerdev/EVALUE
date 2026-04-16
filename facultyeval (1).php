<?php
// ══════════════════════════════════════════════════════════════
//  FacultyEval — Faculty Evaluation System
//  Single self-contained PHP file with SQLite backend
//
//  REQUIREMENTS : PHP 7.4+, PDO_SQLite (enabled on most hosts)
//  USAGE        : Upload this one file. DB is auto-created.
//  DEFAULT LOGIN: admin / 1234
// ══════════════════════════════════════════════════════════════
session_name('FESV4_SESS');
session_start();

// ─── Database ────────────────────────────────────────────────
$dbPath = __DIR__ . '/facultyeval.db';
try { $pdo = new PDO('sqlite:' . $dbPath); }
catch (Exception $e) { die('<b>Error:</b> Cannot create SQLite database. Check folder write permissions.'); }

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT UNIQUE NOT NULL,
    password   TEXT NOT NULL,
    role       TEXT NOT NULL DEFAULT 'student',
    name       TEXT NOT NULL DEFAULT '',
    section    TEXT NOT NULL DEFAULT '',
    subject    TEXT NOT NULL DEFAULT '',
    anon_id    TEXT NOT NULL DEFAULT '',
    faculty_id INTEGER DEFAULT NULL
  );
  CREATE TABLE IF NOT EXISTS faculty (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    name               TEXT NOT NULL,
    subject            TEXT NOT NULL DEFAULT '',
    section            TEXT NOT NULL DEFAULT '',
    teacher_account_id INTEGER DEFAULT NULL
  );
  CREATE TABLE IF NOT EXISTS evaluations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    anon_id    TEXT NOT NULL,
    faculty_id INTEGER NOT NULL,
    ratings    TEXT NOT NULL DEFAULT '{}',
    overall    REAL NOT NULL DEFAULT 0,
    comment    TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  );
  CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
  );
");

// Seed
if (!$pdo->query("SELECT COUNT(*) FROM accounts WHERE username='admin'")->fetchColumn())
    $pdo->exec("INSERT INTO accounts (username,password,role,name) VALUES ('admin','1234','admin','Administrator')");
if (!$pdo->query("SELECT COUNT(*) FROM settings WHERE key='period'")->fetchColumn())
    $pdo->exec("INSERT INTO settings (key,value) VALUES ('period','1')");

// ─── Helpers ─────────────────────────────────────────────────
function jsonOut($d) { header('Content-Type: application/json'); echo json_encode($d); exit; }
function err($m)     { jsonOut(['error' => $m]); }
function ok($x=[])   { jsonOut(array_merge(['ok'=>true], $x)); }
function uid()       { return $_SESSION['uid'] ?? null; }
function urole()     { return $_SESSION['role'] ?? null; }
function guard()     { if (!uid()) err('Not authenticated'); }
function guardAdmin(){ guard(); if (urole()!=='admin') err('Forbidden'); }

function fmtAcc($r, $pw=false) {
    return [
        'id'        => (int)$r['id'],
        'username'  => $r['username'],
        'password'  => $pw ? $r['password'] : '',
        'role'      => $r['role'],
        'name'      => $r['name'],
        'section'   => $r['section'],
        'subject'   => $r['subject'],
        'anonId'    => $r['anon_id'],
        'facultyId' => $r['faculty_id'] !== null ? (int)$r['faculty_id'] : null,
    ];
}
function fmtFac($r) {
    return [
        'id'               => (int)$r['id'],
        'name'             => $r['name'],
        'subject'          => $r['subject'],
        'section'          => $r['section'],
        'teacherAccountId' => $r['teacher_account_id'] !== null ? (int)$r['teacher_account_id'] : null,
    ];
}
function fmtEval($r) {
    return [
        'id'        => (int)$r['id'],
        'anonId'    => $r['anon_id'],
        'facultyId' => (int)$r['faculty_id'],
        'ratings'   => json_decode($r['ratings'], true) ?: [],
        'overall'   => (float)$r['overall'],
        'comment'   => $r['comment'],
        'date'      => $r['created_at'],
    ];
}
function periodOpen() {
    global $pdo;
    return $pdo->query("SELECT value FROM settings WHERE key='period'")->fetchColumn() !== '0';
}
function genAnonId() {
    $L='ABCDEFGHJKLMNPQRSTUVWXYZ'; $r='';
    for ($i=0;$i<3;$i++) $r.=$L[random_int(0,strlen($L)-1)];
    $r.='-';
    for ($i=0;$i<4;$i++) $r.=random_int(0,9);
    return $r;
}
function fetchUser($id) {
    global $pdo;
    $s=$pdo->prepare("SELECT * FROM accounts WHERE id=?");
    $s->execute([$id]); return $s->fetch();
}

// ─── PHP-LEVEL SESSION CHECK for boot data ───────────────────
// PHP injects the current user directly into the HTML so JavaScript
// knows the auth state instantly — no extra API round-trip needed.
$bootUser = null;
if (uid()) {
    $u = fetchUser(uid());
    if ($u) $bootUser = fmtAcc($u, true);
    else     { session_destroy(); session_start(); }
}

// ─── API Handler ─────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    switch ($action) {

    // ── AUTH ──────────────────────────────────────────────────
    case 'login': {
        $uname = trim($body['username'] ?? '');
        $pass  = $body['password'] ?? '';
        if (!$uname || !$pass) err('Please enter username and password');
        $s=$pdo->prepare("SELECT * FROM accounts WHERE username=?");
        $s->execute([$uname]); $acc=$s->fetch();
        if (!$acc || $acc['password'] !== $pass) err('Incorrect username or password');
        // Regenerate session ID on login for security
        session_regenerate_id(true);
        $_SESSION['uid']  = (int)$acc['id'];
        $_SESSION['role'] = $acc['role'];
        ok(['user' => fmtAcc($acc, true)]);
    }

    case 'logout': {
        session_unset(); session_destroy();
        ok();
    }

    // ── DATA ──────────────────────────────────────────────────
    case 'get_data': {
        guard();
        $isAdmin = (urole()==='admin');
        $accounts=[]; foreach($pdo->query("SELECT * FROM accounts ORDER BY id")->fetchAll() as $r) $accounts[]=fmtAcc($r,$isAdmin);
        $faculty=[];  foreach($pdo->query("SELECT * FROM faculty ORDER BY id")->fetchAll() as $r)   $faculty[]=fmtFac($r);
        $evals=[];    foreach($pdo->query("SELECT * FROM evaluations ORDER BY id")->fetchAll() as $r) $evals[]=fmtEval($r);
        jsonOut(['accounts'=>$accounts,'faculty'=>$faculty,'evals'=>$evals,'period'=>periodOpen()]);
    }

    // ── PERIOD ────────────────────────────────────────────────
    case 'toggle_period': {
        guardAdmin();
        $cur=$pdo->query("SELECT value FROM settings WHERE key='period'")->fetchColumn();
        $new=($cur==='0')?'1':'0';
        $pdo->prepare("UPDATE settings SET value=? WHERE key='period'")->execute([$new]);
        ok(['period'=>$new==='1']);
    }

    // ── ACCOUNTS ─────────────────────────────────────────────
    case 'save_account': {
        guardAdmin();
        $id      = isset($body['id']) ? (int)$body['id'] : null;
        $uname   = trim($body['username'] ?? '');
        $pass    = trim($body['password'] ?? '');
        $name    = trim($body['name']     ?? '');
        $role    = $body['role'] ?? 'student';
        $section = trim($body['section']  ?? '');
        $subject = trim($body['subject']  ?? '');
        if (!$uname||!$pass||!$name||!$section||($role==='teacher'&&!$subject)) err('Please fill in all required fields');

        // Duplicate username
        $dup=$pdo->prepare("SELECT COUNT(*) FROM accounts WHERE username=? AND id!=?");
        $dup->execute([$uname, $id??0]);
        if ($dup->fetchColumn()>0) err('Username is already taken');

        if ($id) {
            $old=fetchUser($id);
            $pdo->prepare("UPDATE accounts SET username=?,password=?,name=?,section=?,subject=? WHERE id=?")->execute([$uname,$pass,$name,$section,$subject,$id]);
            if ($old && $old['role']==='teacher' && $old['faculty_id'])
                $pdo->prepare("UPDATE faculty SET name=?,subject=?,section=? WHERE id=?")->execute([$name,$subject,$section,$old['faculty_id']]);
        } else {
            $anonId=($role==='student')?genAnonId():'';
            $facId=null;
            if ($role==='teacher') {
                $pdo->prepare("INSERT INTO faculty (name,subject,section) VALUES (?,?,?)")->execute([$name,$subject,$section]);
                $facId=(int)$pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO accounts (username,password,role,name,section,subject,anon_id,faculty_id) VALUES (?,?,?,?,?,?,?,?)")->execute([$uname,$pass,$role,$name,$section,$subject,$anonId,$facId]);
            $newId=(int)$pdo->lastInsertId();
            if ($facId) $pdo->prepare("UPDATE faculty SET teacher_account_id=? WHERE id=?")->execute([$newId,$facId]);
        }
        ok();
    }

    case 'delete_account': {
        guardAdmin();
        $id=(int)($body['id']??0);
        $acc=fetchUser($id);
        if ($acc && $acc['role']==='teacher' && $acc['faculty_id']) {
            $pdo->prepare("DELETE FROM evaluations WHERE faculty_id=?")->execute([$acc['faculty_id']]);
            $pdo->prepare("DELETE FROM faculty WHERE id=?")->execute([$acc['faculty_id']]);
        }
        $pdo->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);
        ok();
    }

    // ── FACULTY ───────────────────────────────────────────────
    case 'save_faculty': {
        guardAdmin();
        $id      = isset($body['id']) ? (int)$body['id'] : null;
        $name    = trim($body['name']    ?? '');
        $subject = trim($body['subject'] ?? '');
        $section = trim($body['section'] ?? '');
        if (!$name||!$subject||!$section) err('Please fill in all fields');
        if ($id)
            $pdo->prepare("UPDATE faculty SET name=?,subject=?,section=? WHERE id=?")->execute([$name,$subject,$section,$id]);
        else
            $pdo->prepare("INSERT INTO faculty (name,subject,section) VALUES (?,?,?)")->execute([$name,$subject,$section]);
        ok();
    }

    case 'delete_faculty': {
        guardAdmin();
        $id=(int)($body['id']??0);
        $pdo->prepare("DELETE FROM evaluations WHERE faculty_id=?")->execute([$id]);
        $pdo->prepare("UPDATE accounts SET faculty_id=NULL WHERE faculty_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM faculty WHERE id=?")->execute([$id]);
        ok();
    }

    case 'reset_faculty_evals': {
        guardAdmin();
        $pdo->prepare("DELETE FROM evaluations WHERE faculty_id=?")->execute([(int)($body['faculty_id']??0)]);
        ok();
    }

    // ── EVALUATIONS ───────────────────────────────────────────
    case 'submit_eval': {
        guard();
        if (urole()!=='student') err('Only students can submit evaluations');
        if (!periodOpen()) err('The evaluation period is currently closed');
        $facId   = (int)($body['faculty_id']??0);
        $anonId  = $body['anon_id'] ?? '';
        $ratings = $body['ratings'] ?? [];
        $overall = (float)($body['overall']??0);
        $comment = trim($body['comment']??'');
        // Verify anonId matches session
        $s=$pdo->prepare("SELECT anon_id FROM accounts WHERE id=?"); $s->execute([uid()]); $row=$s->fetch();
        if (!$row||$row['anon_id']!==$anonId) err('Invalid anonymous ID');
        // Duplicate
        $dup=$pdo->prepare("SELECT COUNT(*) FROM evaluations WHERE anon_id=? AND faculty_id=?");
        $dup->execute([$anonId,$facId]);
        if ($dup->fetchColumn()>0) err('You have already evaluated this faculty member');
        $pdo->prepare("INSERT INTO evaluations (anon_id,faculty_id,ratings,overall,comment) VALUES (?,?,?,?,?)")
            ->execute([$anonId,$facId,json_encode($ratings),$overall,$comment]);
        ok();
    }

    // ── PROFILE ───────────────────────────────────────────────
    case 'change_password': {
        guard();
        $cur = $body['current_password'] ?? '';
        $new = trim($body['new_password'] ?? '');
        if (strlen($new)<4) err('New password must be at least 4 characters');
        $s=$pdo->prepare("SELECT password FROM accounts WHERE id=?"); $s->execute([uid()]); $row=$s->fetch();
        if (!$row||$row['password']!==$cur) err('Current password is incorrect');
        $pdo->prepare("UPDATE accounts SET password=? WHERE id=?")->execute([$new,uid()]);
        ok();
    }

    default: err('Unknown action');
    }
    exit;
}
// ─── END API. Serve HTML below. ──────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>FacultyEval — Faculty Evaluation System</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#4F6BDE;--blue2:#3A5BBE;--blue3:#2A4BAE;
  --blue-l:#EEF2FF;--blue-s:#D6E0FF;
  --text:#1E2A4A;--t2:#5A6A8A;--t3:#9AAAC0;
  --bd:#DDE5F5;--bd2:#C8D5F0;
  --red:#E54B6A;--red-l:#FFF0F3;
  --green:#1CA670;--green-l:#EDFAF4;
  --gold:#F59E0B;--gold-l:#FFFBEB;
  --purple:#7C6FEF;
  --sh:0 2px 12px rgba(79,107,222,.10),0 1px 3px rgba(0,0,0,.06);
  --sh-md:0 4px 24px rgba(79,107,222,.14),0 2px 6px rgba(0,0,0,.06);
  --sh-lg:0 8px 40px rgba(79,107,222,.18),0 4px 12px rgba(0,0,0,.08);
}
html,body{min-height:100%;font-family:'Inter',sans-serif;font-size:14px;line-height:1.6;color:var(--text);background:#EEF2FF}
body{background-image:radial-gradient(ellipse 70% 50% at 20% 0%,rgba(79,107,222,.12) 0%,transparent 60%),radial-gradient(ellipse 50% 40% at 80% 100%,rgba(120,90,250,.08) 0%,transparent 50%);background-attachment:fixed;min-height:100vh}
.view{display:none!important}.view.active{display:block!important}
#view-login{min-height:100vh;display:none!important;align-items:center;justify-content:center}
#view-login.active{display:flex!important}
#navbar{position:sticky;top:0;z-index:100;background:rgba(255,255,255,.96);backdrop-filter:blur(18px);border-bottom:1px solid var(--bd);box-shadow:0 1px 4px rgba(79,107,222,.08);display:none}
#navbar.show{display:block}
.nav-inner{max-width:1100px;margin:0 auto;padding:0 20px;height:58px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.nav-brand{display:flex;align-items:center;gap:10px;cursor:pointer;flex-shrink:0}
.nav-logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--blue),var(--blue3));display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 2px 8px rgba(79,107,222,.35)}
.nav-title{font-family:'Poppins',sans-serif;font-size:15px;font-weight:700;color:var(--blue)}
.nav-period{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:.04em}
.period-open{background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0}
.period-closed{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA}
.nav-right{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.nav-role{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;border:1px solid var(--bd2)}
.nav-role.student{background:var(--blue-l);color:var(--blue)}
.nav-role.teacher{background:var(--green-l);color:var(--green)}
.nav-role.admin{background:#FFF7ED;color:#D97706}
.nav-user{font-size:12px;font-weight:600;color:var(--t2);padding:3px 10px;background:#F8FAFF;border-radius:20px;border:1px solid var(--bd)}
.nav-anon{font-size:10px;font-weight:700;color:var(--blue);padding:2px 9px;background:var(--blue-s);border-radius:20px;letter-spacing:.04em;display:none}
.btn-logout{font-size:11px;font-weight:600;color:var(--red);background:var(--red-l);border:1px solid rgba(229,75,106,.2);padding:5px 12px;border-radius:8px;cursor:pointer;transition:.2s;font-family:'Inter',sans-serif}
.btn-logout:hover{background:rgba(229,75,106,.15)}
.container{max-width:1100px;margin:0 auto;padding:24px 18px}
.card{background:#fff;border-radius:18px;border:1px solid var(--bd);box-shadow:var(--sh);overflow:hidden}
.card-top{height:4px;background:linear-gradient(90deg,var(--blue),var(--purple))}
.btn{font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;padding:10px 22px;border-radius:11px;cursor:pointer;border:none;transition:.2s;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.btn-primary{background:linear-gradient(135deg,var(--blue),var(--blue3));color:#fff;box-shadow:0 4px 14px rgba(79,107,222,.28)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(79,107,222,.38)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none}
.btn-ghost{background:var(--blue-l);border:1px solid var(--bd2);color:var(--t2);font-family:'Poppins',sans-serif}
.btn-ghost:hover{background:var(--blue-s)}
.btn-success{background:linear-gradient(135deg,var(--green),#14855a);color:#fff;box-shadow:0 4px 12px rgba(28,166,112,.22)}
.btn-success:hover:not(:disabled){transform:translateY(-1px)}
.btn-success:disabled{opacity:.6;cursor:not-allowed;transform:none!important}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:8px}
.btn-block{width:100%}
.btn-del{background:var(--red-l);border:1px solid rgba(229,75,106,.2);color:var(--red);font-size:11px;font-weight:600;padding:4px 11px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;transition:.2s}
.btn-del:hover{background:rgba(229,75,106,.15)}
.btn-edit{background:var(--blue-l);border:1px solid var(--bd2);color:var(--t2);font-size:11px;font-weight:600;padding:4px 11px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;transition:.2s}
.btn-edit:hover{background:var(--blue-s)}
.btn-reset{background:var(--gold-l);border:1px solid rgba(245,158,11,.25);color:#92400E;font-size:10px;font-weight:600;padding:4px 10px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;transition:.2s}
.btn-reset:hover{background:rgba(245,158,11,.15)}
.form-group{margin-bottom:14px}
.label{font-size:12px;font-weight:600;color:var(--t2);margin-bottom:5px;display:block}
.inp{width:100%;background:#fff;border:1.5px solid var(--bd2);border-radius:11px;padding:10px 13px;color:var(--text);font-size:13px;font-family:'Inter',sans-serif;transition:.2s;outline:none;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.inp:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(79,107,222,.13)}
.inp::placeholder{color:var(--t3)}
textarea.inp{resize:vertical;min-height:72px;line-height:1.6}
.inp-row{display:grid;grid-template-columns:1fr 1fr;gap:11px}
.pass-wrap{position:relative}
.pass-eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:600;color:var(--t3);cursor:pointer;user-select:none}
.alert{padding:9px 13px;border-radius:9px;font-size:12px;font-weight:500;margin:7px 0;display:none}
.alert.show{display:block}
.alert-error{background:var(--red-l);border:1px solid rgba(229,75,106,.25);color:var(--red)}
.alert-success{background:var(--green-l);border:1px solid rgba(28,166,112,.25);color:var(--green)}
.alert-info{background:var(--blue-l);border:1px solid var(--bd2);color:var(--blue2);display:block}
.alert-warn{background:var(--gold-l);border:1px solid rgba(245,158,11,.22);color:#92400E;display:block}
.alert-closed{background:#FEF2F2;border:1px solid #FECACA;border-radius:14px;padding:22px;text-align:center}
.badge{font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:3px;white-space:nowrap}
.badge-blue{background:var(--blue-s);color:var(--blue)}
.badge-green{background:var(--green-l);color:var(--green)}
.badge-red{background:var(--red-l);color:var(--red)}
.badge-gray{background:#F0F4FA;border:1px solid var(--bd);color:var(--t3)}
.badge-gold{background:#FEF3C7;color:#92400E}
.page-header{margin-bottom:18px}
.eyebrow{font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--blue);margin-bottom:4px;display:flex;align-items:center;gap:7px}
.eyebrow::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--blue);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.page-title{font-family:'Poppins',sans-serif;font-size:22px;font-weight:800;color:var(--text);letter-spacing:-.02em}
.page-title span{color:var(--blue)}
.page-sub{font-size:13px;color:var(--t2);margin-top:3px}
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:11px;margin-bottom:18px}
@media(min-width:560px){.stat-grid{grid-template-columns:repeat(4,1fr)}}
.stat-card{background:#fff;border:1px solid var(--bd);border-radius:14px;padding:14px;box-shadow:var(--sh)}
.stat-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;margin-bottom:8px}
.stat-value{font-family:'Poppins',sans-serif;font-size:24px;font-weight:800;color:var(--blue)}
.stat-label{font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.05em}
.stat-sub{font-size:11px;color:var(--t2);margin-top:1px}
.table-wrap{overflow-x:auto;border-radius:13px;border:1px solid var(--bd)}
table{width:100%;border-collapse:collapse;background:#fff}
thead{background:#F8FAFF}
th{padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--bd);white-space:nowrap}
td{padding:11px 14px;font-size:12px;color:var(--text);border-bottom:1px solid var(--bd)}
tr:last-child td{border-bottom:none}
tr:hover td{background:#F8FAFF}
.td-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
.fac-grid{display:grid;grid-template-columns:1fr;gap:13px}
@media(min-width:560px){.fac-grid{grid-template-columns:repeat(2,1fr)}}
@media(min-width:820px){.fac-grid{grid-template-columns:repeat(3,1fr)}}
.fac-card{background:#fff;border:1.5px solid var(--bd);border-radius:16px;padding:18px;transition:all .22s;display:flex;flex-direction:column;gap:10px;box-shadow:var(--sh)}
.fac-card:hover:not(.done){border-color:var(--blue);box-shadow:var(--sh-md);transform:translateY(-3px)}
.fac-card.done{background:#F9FBFF;border-color:var(--blue-s)}
.fac-avatar{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--purple));display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff;font-weight:700;font-family:'Poppins',sans-serif;flex-shrink:0}
.fac-name{font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;color:var(--text)}
.fac-meta{font-size:11px;color:var(--t2);display:flex;flex-direction:column;gap:3px}
.star-row{display:flex;gap:3px;margin:3px 0}
.star{font-size:26px;cursor:pointer;transition:transform .1s;color:#DDE5F5;user-select:none;line-height:1}
.star.active,.star:hover{color:var(--gold);transform:scale(1.1)}
.star-label{font-size:11px;color:var(--t2);margin-top:2px;min-height:16px;font-style:italic}
.star-disp{color:var(--gold)}
.eval-q{background:#F8FAFF;border:1px solid var(--bd);border-radius:12px;padding:13px;margin-bottom:10px}
.q-text{font-size:13px;font-weight:600;color:var(--text);margin-bottom:7px;display:flex;align-items:flex-start;gap:7px}
.q-num{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:50%;background:var(--blue);color:#fff;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px}
.login-wrap{width:100%;max-width:420px;padding:20px}
.login-card{background:#fff;border-radius:22px;box-shadow:var(--sh-lg);border:1px solid var(--bd);overflow:hidden}
.login-header{background:linear-gradient(135deg,var(--blue) 0%,var(--purple) 100%);padding:28px 24px 22px;text-align:center}
.login-logo{width:62px;height:62px;border-radius:16px;margin:0 auto 12px;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:28px;animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.login-title{font-family:'Poppins',sans-serif;font-size:24px;font-weight:800;color:#fff}
.login-sub{font-size:11px;color:rgba(255,255,255,.7);margin-top:3px;letter-spacing:.05em;text-transform:uppercase}
.login-body{padding:22px}
.login-footer{background:#F8FAFF;border-top:1px solid var(--bd);padding:10px 22px;display:flex;align-items:center;justify-content:center;gap:7px}
.dot{width:6px;height:6px;border-radius:50%;display:inline-block}
.dot-green{background:var(--green);animation:blink 1.5s infinite}
.footer-text{font-size:11px;color:var(--t3)}
.nav-tabs{display:flex;gap:3px;margin-bottom:18px;background:#fff;padding:4px;border-radius:12px;border:1px solid var(--bd);width:fit-content;flex-wrap:wrap}
.nav-tab{font-family:'Poppins',sans-serif;font-size:11px;font-weight:600;padding:6px 14px;border-radius:8px;cursor:pointer;border:none;background:none;color:var(--t2);transition:.2s;white-space:nowrap}
.nav-tab.active{background:linear-gradient(135deg,var(--blue),var(--blue3));color:#fff;box-shadow:0 2px 8px rgba(79,107,222,.26)}
.nav-tab:hover:not(.active){background:var(--blue-l);color:var(--text)}
.modal-overlay{position:fixed;inset:0;background:rgba(20,30,60,.46);backdrop-filter:blur(5px);z-index:200;display:none;align-items:center;justify-content:center;padding:20px}
.modal-overlay.show{display:flex}
.modal{background:#fff;border-radius:19px;padding:24px;width:100%;max-width:500px;box-shadow:var(--sh-lg);animation:fadein .2s ease;max-height:92vh;overflow-y:auto}
@keyframes fadein{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.modal-title{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;color:var(--text);margin-bottom:16px}
.modal-actions{display:flex;gap:9px;margin-top:16px;justify-content:flex-end}
.progress-wrap{background:#F0F4FA;border-radius:50px;height:7px;overflow:hidden;margin:2px 0}
.progress-bar{height:100%;border-radius:50px;background:linear-gradient(90deg,var(--blue),var(--purple));transition:width .4s ease}
.progress-bar.green{background:linear-gradient(90deg,var(--green),#14855a)}
.progress-bar.gold{background:linear-gradient(90deg,var(--gold),#D97706)}
.empty{text-align:center;padding:36px 20px;color:var(--t3)}
.empty-icon{font-size:40px;margin-bottom:9px}
.empty-text{font-size:13px;font-weight:500}
.back-btn{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--t2);cursor:pointer;padding:6px 13px;border-radius:9px;background:var(--blue-l);border:1px solid var(--bd2);margin-bottom:16px;transition:.2s;user-select:none}
.back-btn:hover{background:var(--blue-s);color:var(--text)}
.sec-tag{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:#EEF2FF;color:var(--blue)}
.done-overlay{display:flex;align-items:center;gap:7px;background:var(--green-l);border:1px solid rgba(28,166,112,.25);border-radius:9px;padding:8px 12px;font-size:11px;font-weight:600;color:var(--green)}
.rbar-row{margin-bottom:8px}
.rbar-lbl{display:flex;justify-content:space-between;font-size:10px;color:var(--t2);margin-bottom:2px}
.avg-score{font-family:'Poppins',sans-serif;font-size:22px;font-weight:800;color:var(--blue);line-height:1}
.period-banner{background:linear-gradient(135deg,var(--blue),var(--purple));color:#fff;padding:11px 17px;border-radius:13px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.period-banner.closed-banner{background:linear-gradient(135deg,#DC2626,#9B1C1C)}
.period-info{font-size:13px;font-weight:600}
.period-info small{display:block;font-size:11px;font-weight:400;opacity:.8;margin-top:1px}
.ring-wrap{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--bd);border-radius:13px;padding:13px 16px;margin-bottom:16px;box-shadow:var(--sh)}
.ring-text{font-family:'Poppins',sans-serif;font-size:20px;font-weight:800;color:var(--blue)}
.ring-sub{font-size:11px;color:var(--t2)}
.chart-card{background:#fff;border:1px solid var(--bd);border-radius:14px;padding:18px;box-shadow:var(--sh);margin-bottom:18px}
.chart-title{font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin-bottom:13px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.status-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-bottom:16px}
@media(max-width:540px){.status-grid{grid-template-columns:1fr}}
.status-card{background:#fff;border:1px solid var(--bd);border-radius:14px;padding:14px;box-shadow:var(--sh)}
.status-card-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2);margin-bottom:9px}
.fac-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:9px;margin-bottom:6px;background:#F8FAFF;border:1px solid var(--bd)}
.fac-avt-sm{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--blue),var(--purple));display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;font-weight:700;flex-shrink:0}
.fac-avt-sm.grey{background:linear-gradient(135deg,#9AAAC0,#C8D5F0)}
.profile-card{background:#fff;border:1px solid var(--bd);border-radius:16px;padding:22px;box-shadow:var(--sh);margin-bottom:16px}
.profile-card-title{font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px}
.profile-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd)}
.profile-row:last-child{border-bottom:none;padding-bottom:0}
.profile-key{font-size:11px;font-weight:600;color:var(--t2);min-width:110px}
.profile-val{font-size:12px;color:var(--text);font-weight:500}
.filter-bar{background:#fff;border:1px solid var(--bd);border-radius:12px;padding:12px 15px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;box-shadow:var(--sh)}
.filter-bar label{font-size:11px;font-weight:600;color:var(--t2);white-space:nowrap}
.filter-bar select,.filter-bar input[type=date]{font-size:12px;font-family:'Inter',sans-serif;border:1.5px solid var(--bd2);border-radius:8px;padding:5px 10px;color:var(--text);background:#fff;cursor:pointer;outline:none}
.section-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#F8FAFF;border:1px solid var(--bd);border-radius:9px;margin-bottom:7px}
.section-label{font-size:12px;font-weight:700;min-width:90px}
.section-pct{font-size:11px;font-weight:700;color:var(--blue);min-width:34px;text-align:right}
.refresh-indicator{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--t3)}
.refresh-dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:blink 1.5s infinite}
.export-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:9px;margin-bottom:14px}
.dist-row{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.dist-star{font-size:12px;min-width:22px;color:var(--gold);text-align:right}
.dist-bar-wrap{flex:1;background:#F0F4FA;border-radius:50px;height:10px;overflow:hidden}
.dist-bar{height:100%;border-radius:50px;background:linear-gradient(90deg,var(--gold),#F97316);transition:width .5s ease}
.dist-count{font-size:11px;color:var(--t2);min-width:26px;text-align:right}
</style>
</head>
<body>

<!-- NAVBAR -->
<div id="navbar">
  <div class="nav-inner">
    <div class="nav-brand" onclick="goHome()">
      <div class="nav-logo">📋</div>
      <span class="nav-title">FacultyEval</span>
      <span class="nav-period" id="nav-period-badge"></span>
    </div>
    <div class="nav-right">
      <span class="nav-role" id="nav-role-badge"></span>
      <span class="nav-user" id="nav-username"></span>
      <span class="nav-anon" id="nav-anon-id"></span>
      <div class="refresh-indicator" id="nav-refresh-ind" style="display:none"><span class="refresh-dot"></span><span>Live</span></div>
      <button class="btn-logout" onclick="logout()">Sign Out</button>
    </div>
  </div>
</div>

<!-- LOGIN -->
<div id="view-login" class="view active">
  <div class="login-wrap">
    <div class="login-card">
      <div class="card-top"></div>
      <div class="login-header">
        <div class="login-logo">📋</div>
        <div class="login-title">FacultyEval</div>
        <div class="login-sub">Faculty Evaluation System</div>
      </div>
      <div class="login-body">
        <div class="form-group">
          <label class="label">Username</label>
          <input class="inp" id="login-user" type="text" placeholder="Enter your username" autocomplete="username" onkeydown="if(event.key==='Enter')doLogin()"/>
        </div>
        <div class="form-group">
          <label class="label">Password</label>
          <div class="pass-wrap">
            <input class="inp" id="login-pass" type="password" placeholder="Enter your password" autocomplete="current-password" onkeydown="if(event.key==='Enter')doLogin()"/>
            <span class="pass-eye" onclick="togglePass('login-pass',this)">👁 Show</span>
          </div>
        </div>
        <div class="alert alert-error" id="login-err" style="display:none">Incorrect username or password.</div>
        <button class="btn btn-primary btn-block" style="margin-top:5px" id="login-btn" onclick="doLogin()">Log In</button>
        <div style="font-size:11px;color:var(--t3);text-align:center;margin-top:9px">Default admin: <strong>admin</strong> / <strong>1234</strong></div>
      </div>
      <div class="login-footer">
        <span class="dot dot-green"></span>
        <span class="footer-text">PHP + SQLite · Server-side Authentication</span>
      </div>
    </div>
  </div>
</div>

<!-- STUDENT VIEW -->
<div id="view-student" class="view">
  <div class="container">
    <div id="stu-closed-banner" style="display:none">
      <div class="alert-closed">
        <div style="font-size:34px;margin-bottom:8px">🔒</div>
        <div style="font-size:15px;font-weight:700;color:#B91C1C">Evaluation Period is Closed</div>
        <div style="font-size:12px;color:#DC2626;margin-top:5px">Please wait for the admin to open the evaluation period.</div>
      </div>
    </div>
    <div id="stu-open-content">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px">
        <div>
          <div class="eyebrow">Student Portal</div>
          <h1 class="page-title">Hello, <span id="stu-display-name">Student</span>!</h1>
          <p class="page-sub" id="stu-section-display"></p>
        </div>
        <div class="refresh-indicator"><span class="refresh-dot"></span><span>Auto-refresh on</span></div>
      </div>
      <div style="background:linear-gradient(135deg,#EEF2FF,#E8EDFF);border:1px solid var(--bd2);border-radius:12px;padding:10px 15px;display:flex;align-items:center;gap:11px;margin-bottom:16px">
        <span style="font-size:20px">🔒</span>
        <div>
          <div style="font-size:10px;color:var(--t2);font-weight:500">Your Anonymous Evaluator ID — responses are private</div>
          <div id="stu-anon-id" style="font-family:'Poppins',sans-serif;font-size:15px;font-weight:800;color:var(--blue);letter-spacing:.1em">—</div>
        </div>
      </div>
      <div class="nav-tabs">
        <button class="nav-tab active" id="stu-tab-eval"    onclick="stuTab('eval')">📚 Evaluate</button>
        <button class="nav-tab"        id="stu-tab-status"  onclick="stuTab('status')">📋 My Status</button>
        <button class="nav-tab"        id="stu-tab-profile" onclick="stuTab('profile')">👤 My Profile</button>
      </div>
      <div id="stu-panel-eval">
        <div id="student-home">
          <div class="ring-wrap">
            <svg width="50" height="50" viewBox="0 0 50 50" style="flex-shrink:0">
              <circle cx="25" cy="25" r="20" fill="none" stroke="#EEF2FF" stroke-width="5"/>
              <circle id="stu-ring" cx="25" cy="25" r="20" fill="none" stroke="#4F6BDE" stroke-width="5" stroke-dasharray="125.7" stroke-dashoffset="125.7" stroke-linecap="round" transform="rotate(-90 25 25)" style="transition:stroke-dashoffset .5s ease"/>
            </svg>
            <div>
              <div class="ring-text"><span id="stu-done-count">0</span><span style="font-size:14px;font-weight:600;color:var(--t2)"> / <span id="stu-total-count">0</span></span></div>
              <div class="ring-sub">Teachers evaluated in your section</div>
            </div>
          </div>
          <div class="alert alert-info" id="stu-info-msg" style="margin-bottom:14px">📚 Loading your teachers...</div>
          <div id="fac-cards-wrap" class="fac-grid"></div>
        </div>
        <div id="student-eval" style="display:none">
          <div class="back-btn" onclick="showStudentHome()">← Back to Faculty List</div>
          <div class="page-header">
            <div class="eyebrow">Evaluation Form</div>
            <h1 class="page-title">Evaluating <span id="eval-fac-name">Faculty</span></h1>
            <p class="page-sub" id="eval-fac-meta"></p>
          </div>
          <div class="card">
            <div class="card-top"></div>
            <div style="padding:20px">
              <div style="font-size:12px;color:var(--t2);margin-bottom:13px;padding:9px 12px;background:#F8FAFF;border-radius:9px;border:1px solid var(--bd)">⭐ Rate each item from <strong>1 (Poor)</strong> to <strong>5 (Excellent)</strong>. All responses are anonymous.</div>
              <div id="eval-questions-wrap"></div>
              <div class="form-group" style="margin-top:6px">
                <label class="label">Additional Comments <span style="font-weight:400;color:var(--t3)">(Optional)</span></label>
                <textarea class="inp" id="eval-comment" rows="3" placeholder="Share any additional feedback..."></textarea>
              </div>
              <div class="alert alert-error" id="eval-err" style="display:none">Please rate all questions before submitting.</div>
              <div class="alert alert-success" id="eval-success" style="display:none">✅ Evaluation submitted successfully! Thank you.</div>
              <button class="btn btn-success btn-block" id="eval-submit-btn" onclick="submitEval()" style="margin-top:12px">Submit Evaluation</button>
            </div>
          </div>
        </div>
      </div>
      <div id="stu-panel-status" style="display:none">
        <div class="status-grid">
          <div class="status-card">
            <div class="status-card-title">✅ Evaluated</div>
            <div id="stu-evaluated-list"></div>
          </div>
          <div class="status-card">
            <div class="status-card-title">⏳ Not Yet Evaluated</div>
            <div id="stu-pending-list"></div>
          </div>
        </div>
      </div>
      <div id="stu-panel-profile" style="display:none">
        <div class="profile-card">
          <div class="profile-card-title">👤 My Account</div>
          <div class="profile-row"><span class="profile-key">Full Name</span><span class="profile-val" id="prof-stu-name">—</span></div>
          <div class="profile-row"><span class="profile-key">Username</span><span class="profile-val"><code id="prof-stu-user" style="background:#F0F4FA;padding:2px 7px;border-radius:5px">—</code></span></div>
          <div class="profile-row"><span class="profile-key">Section</span><span class="profile-val"><span class="sec-tag">🏫 <span id="prof-stu-sec">—</span></span></span></div>
          <div class="profile-row"><span class="profile-key">Anonymous ID</span><span class="profile-val"><span style="font-family:'Poppins',sans-serif;font-weight:700;color:var(--blue)" id="prof-stu-anon">—</span></span></div>
          <div class="profile-row"><span class="profile-key">Submissions</span><span class="profile-val"><span class="badge badge-green" id="prof-stu-evcount">0</span></span></div>
        </div>
        <div class="profile-card">
          <div class="profile-card-title">🔑 Change Password</div>
          <div class="form-group"><label class="label">Current Password</label><div class="pass-wrap"><input class="inp" id="stu-pw-cur" type="password"/><span class="pass-eye" onclick="togglePass('stu-pw-cur',this)">👁</span></div></div>
          <div class="form-group"><label class="label">New Password</label><div class="pass-wrap"><input class="inp" id="stu-pw-new" type="password" placeholder="At least 4 characters"/><span class="pass-eye" onclick="togglePass('stu-pw-new',this)">👁</span></div></div>
          <div class="form-group"><label class="label">Confirm New Password</label><div class="pass-wrap"><input class="inp" id="stu-pw-con" type="password"/><span class="pass-eye" onclick="togglePass('stu-pw-con',this)">👁</span></div></div>
          <div class="alert alert-error" id="stu-pw-err" style="display:none"></div>
          <div class="alert alert-success" id="stu-pw-ok" style="display:none">✅ Password updated!</div>
          <button class="btn btn-primary" onclick="changePwd('student')">Update Password</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- TEACHER VIEW -->
<div id="view-teacher" class="view">
  <div class="container">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px">
      <div>
        <div class="eyebrow">Teacher Panel</div>
        <h1 class="page-title">Welcome, <span id="tch-display-name">Teacher</span></h1>
        <p class="page-sub" id="tch-sub-display"></p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <div class="refresh-indicator"><span class="refresh-dot"></span><span>Auto-refresh on</span></div>
        <button class="btn btn-ghost btn-sm" onclick="exportTeacherResults()">📤 Export PDF</button>
      </div>
    </div>
    <div class="nav-tabs">
      <button class="nav-tab active" id="tch-tab-results" onclick="tchTab('results')">📊 Results</button>
      <button class="nav-tab"        id="tch-tab-graph"   onclick="tchTab('graph')">📈 Graph</button>
      <button class="nav-tab"        id="tch-tab-profile" onclick="tchTab('profile')">👤 Profile</button>
    </div>
    <div id="tch-panel-results">
      <div class="filter-bar">
        <label>📅 Filter:</label>
        <select id="tch-filter-date" onchange="applyTeacherFilter()">
          <option value="all">All Time</option>
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="custom">Custom Range</option>
        </select>
        <div id="tch-custom-dates" style="display:none;gap:6px;align-items:center">
          <input type="date" id="tch-date-from" onchange="applyTeacherFilter()"/>
          <span style="font-size:11px;color:var(--t3)">to</span>
          <input type="date" id="tch-date-to" onchange="applyTeacherFilter()"/>
        </div>
        <span style="font-size:11px;color:var(--t3)" id="tch-filter-count"></span>
      </div>
      <div id="tch-eval-summary"></div>
    </div>
    <div id="tch-panel-graph" style="display:none">
      <div class="chart-card">
        <div class="chart-title">⭐ Rating Distribution</div>
        <div id="tch-dist-bars"></div>
        <div style="position:relative;height:220px;margin-top:10px"><canvas id="tch-dist-chart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">📊 Average Score Per Question</div>
        <div style="position:relative;height:240px"><canvas id="tch-q-chart"></canvas></div>
      </div>
    </div>
    <div id="tch-panel-profile" style="display:none">
      <div class="profile-card">
        <div class="profile-card-title">👤 My Account</div>
        <div class="profile-row"><span class="profile-key">Full Name</span><span class="profile-val" id="prof-tch-name">—</span></div>
        <div class="profile-row"><span class="profile-key">Username</span><span class="profile-val"><code id="prof-tch-user" style="background:#F0F4FA;padding:2px 7px;border-radius:5px">—</code></span></div>
        <div class="profile-row"><span class="profile-key">Subject</span><span class="profile-val" id="prof-tch-subject">—</span></div>
        <div class="profile-row"><span class="profile-key">Section</span><span class="profile-val"><span class="sec-tag">🏫 <span id="prof-tch-sec">—</span></span></span></div>
        <div class="profile-row"><span class="profile-key">Evaluations Received</span><span class="profile-val"><span class="badge badge-blue" id="prof-tch-evcount">0</span></span></div>
        <div class="profile-row"><span class="profile-key">Overall Rating</span><span class="profile-val"><span id="prof-tch-rating">—</span></span></div>
      </div>
      <div class="profile-card">
        <div class="profile-card-title">🔑 Change Password</div>
        <div class="form-group"><label class="label">Current Password</label><div class="pass-wrap"><input class="inp" id="tch-pw-cur" type="password"/><span class="pass-eye" onclick="togglePass('tch-pw-cur',this)">👁</span></div></div>
        <div class="form-group"><label class="label">New Password</label><div class="pass-wrap"><input class="inp" id="tch-pw-new" type="password" placeholder="At least 4 characters"/><span class="pass-eye" onclick="togglePass('tch-pw-new',this)">👁</span></div></div>
        <div class="form-group"><label class="label">Confirm New Password</label><div class="pass-wrap"><input class="inp" id="tch-pw-con" type="password"/><span class="pass-eye" onclick="togglePass('tch-pw-con',this)">👁</span></div></div>
        <div class="alert alert-error" id="tch-pw-err" style="display:none"></div>
        <div class="alert alert-success" id="tch-pw-ok" style="display:none">✅ Password updated!</div>
        <button class="btn btn-primary" onclick="changePwd('teacher')">Update Password</button>
      </div>
    </div>
  </div>
</div>

<!-- ADMIN VIEW -->
<div id="view-admin" class="view">
  <div class="container">
    <div class="page-header">
      <div class="eyebrow">Admin Panel</div>
      <h1 class="page-title">Dashboard <span>Overview</span></h1>
      <p class="page-sub">Manage accounts, faculty, and evaluation results.</p>
    </div>
    <div class="period-banner" id="admin-period-banner">
      <div class="period-info">
        <span id="period-banner-label">Evaluation Period: OPEN</span>
        <small id="period-banner-sub">Students can currently submit evaluations.</small>
      </div>
      <button id="period-toggle-btn" onclick="togglePeriod()" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);font-family:'Poppins',sans-serif;font-weight:600;font-size:11px;padding:6px 14px;border-radius:8px;cursor:pointer">Close Period</button>
    </div>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#EEF2FF">🎓</div><div class="stat-value" id="stat-students">0</div><div class="stat-label">Students</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#F0FDF4">👨‍🏫</div><div class="stat-value" id="stat-faculty">0</div><div class="stat-label">Faculty</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#FFF7ED">📝</div><div class="stat-value" id="stat-evals">0</div><div class="stat-label">Evaluations</div><div class="stat-sub" id="stat-evals-sub"></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#F5F3FF">📊</div><div class="stat-value" id="stat-completion">0%</div><div class="stat-label">Faculty Evaluated</div></div>
    </div>
    <div class="nav-tabs">
      <button class="nav-tab active" id="tab-accounts" onclick="switchTab('accounts')">👤 Accounts</button>
      <button class="nav-tab"        id="tab-faculty"  onclick="switchTab('faculty')">👨‍🏫 Faculty</button>
      <button class="nav-tab"        id="tab-status"   onclick="switchTab('status')">📊 Eval Status</button>
      <button class="nav-tab"        id="tab-results"  onclick="switchTab('results')">📈 Results</button>
    </div>
    <div id="panel-accounts">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:13px;flex-wrap:wrap;gap:8px">
        <span style="font-size:12px;font-weight:600;color:var(--t2)">All User Accounts</span>
        <div style="display:flex;gap:7px">
          <button class="btn btn-primary btn-sm" onclick="openAccountModal('student')">+ Student</button>
          <button class="btn btn-primary btn-sm" onclick="openAccountModal('teacher')">+ Teacher</button>
        </div>
      </div>
      <div class="table-wrap">
        <table><thead><tr><th>#</th><th>Username</th><th>Name</th><th>Role</th><th>Section/Subject</th><th>Activity</th><th>Actions</th></tr></thead><tbody id="accounts-tbody"></tbody></table>
      </div>
    </div>
    <div id="panel-faculty" style="display:none">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:13px;flex-wrap:wrap;gap:8px">
        <span style="font-size:12px;font-weight:600;color:var(--t2)">Faculty List</span>
        <button class="btn btn-primary btn-sm" onclick="openFacultyModal()">+ Add Faculty</button>
      </div>
      <div class="alert alert-info" style="margin-bottom:13px">ℹ️ Teacher accounts auto-create a faculty entry.</div>
      <div class="table-wrap">
        <table><thead><tr><th>#</th><th>Name</th><th>Subject</th><th>Section</th><th>Evaluations</th><th>Avg</th><th>Actions</th></tr></thead><tbody id="faculty-tbody"></tbody></table>
      </div>
    </div>
    <div id="panel-status" style="display:none">
      <div class="chart-card">
        <div class="chart-title"><span>📊 Average Rating by Faculty</span><span style="font-size:10px;color:var(--t3);font-weight:400">green=excellent, blue=good, gold=fair, red=poor</span></div>
        <div id="chart-no-data" style="display:none" class="empty"><div class="empty-icon">📊</div><div class="empty-text">No evaluations yet.</div></div>
        <div style="position:relative;height:260px" id="adm-chart-wrap"><canvas id="chart-ratings"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">🏫 Completion by Section</div>
        <div id="section-completion-list"></div>
      </div>
      <div class="status-grid">
        <div class="status-card"><div class="status-card-title">✅ Evaluated Faculty</div><div id="evaluated-list"></div></div>
        <div class="status-card"><div class="status-card-title">⏳ Not Yet Evaluated</div><div id="pending-list"></div></div>
      </div>
    </div>
    <div id="panel-results" style="display:none">
      <div class="export-bar">
        <span style="font-size:12px;font-weight:600;color:var(--t2)">Detailed Evaluation Results</span>
        <button class="btn btn-ghost btn-sm" onclick="printResults()">🖨️ Print Report</button>
      </div>
      <div id="results-wrap"></div>
    </div>
  </div>
</div>

<!-- MODAL: Account -->
<div class="modal-overlay" id="modal-account">
  <div class="modal">
    <div class="modal-title" id="account-modal-title">Add Account</div>
    <div id="account-role-display" style="margin-bottom:12px"></div>
    <div class="inp-row">
      <div class="form-group"><label class="label">Username</label><input class="inp" id="mod-acc-user" type="text" autocomplete="off"/></div>
      <div class="form-group"><label class="label">Password</label><div class="pass-wrap"><input class="inp" id="mod-acc-pass" type="password" autocomplete="new-password"/><span class="pass-eye" onclick="togglePass('mod-acc-pass',this)">👁</span></div></div>
    </div>
    <div class="form-group"><label class="label">Full Name</label><input class="inp" id="mod-acc-name" type="text"/></div>
    <div id="mod-stu-fields"><div class="form-group"><label class="label">Section</label><input class="inp" id="mod-acc-section" type="text" placeholder="e.g. Section A"/></div></div>
    <div id="mod-tch-fields" style="display:none">
      <div class="form-group"><label class="label">Subject</label><input class="inp" id="mod-acc-subject" type="text" placeholder="e.g. Mathematics"/></div>
      <div class="form-group"><label class="label">Assigned Section</label><input class="inp" id="mod-acc-tecsec" type="text" placeholder="e.g. Section A"/></div>
    </div>
    <div class="alert alert-error" id="modal-acc-err" style="display:none">Please fill in all required fields.</div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-account')">Cancel</button>
      <button class="btn btn-primary" id="modal-acc-save" onclick="saveAccount()">Save Account</button>
    </div>
  </div>
</div>
<!-- MODAL: Faculty -->
<div class="modal-overlay" id="modal-faculty">
  <div class="modal">
    <div class="modal-title" id="faculty-modal-title">Add Faculty</div>
    <div class="form-group"><label class="label">Full Name</label><input class="inp" id="mod-fac-name" type="text"/></div>
    <div class="form-group"><label class="label">Subject</label><input class="inp" id="mod-fac-subject" type="text"/></div>
    <div class="form-group"><label class="label">Assigned Section</label><input class="inp" id="mod-fac-section" type="text"/></div>
    <div class="alert alert-error" id="modal-fac-err" style="display:none">Please fill in all fields.</div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-faculty')">Cancel</button>
      <button class="btn btn-primary" id="modal-fac-save" onclick="saveFaculty()">Save Faculty</button>
    </div>
  </div>
</div>
<!-- MODAL: Confirm -->
<div class="modal-overlay" id="modal-confirm">
  <div class="modal" style="max-width:420px">
    <div class="modal-title" id="confirm-title">Confirm Action</div>
    <p style="color:var(--t2);font-size:13px;line-height:1.7" id="confirm-msg">Are you sure?</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-confirm')">Cancel</button>
      <button class="btn" id="confirm-ok-btn" onclick="confirmAction()">Confirm</button>
    </div>
  </div>
</div>

<script>
'use strict';

// ══════════════════════════════════════════════
//  PHP SESSION INJECTION
//  PHP injects the logged-in user directly into
//  the page — no extra API call on load needed.
// ══════════════════════════════════════════════
const __PHP_BOOT__ = <?php echo json_encode($bootUser); ?>;

// ══════════════════════════════════════════════
//  API
// ══════════════════════════════════════════════
async function api(action, body={}) {
  const r = await fetch('?api=1', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action, ...body})
  });
  if (!r.ok) return {error:'Server error '+r.status};
  return r.json();
}

// In-memory data
let _D = {accounts:[],faculty:[],evals:[],period:true};
async function loadData() {
  const d = await api('get_data');
  if (d.error) { if(d.error==='Not authenticated') forceLogout(); return; }
  _D.accounts = d.accounts||[];
  _D.faculty  = d.faculty||[];
  _D.evals    = d.evals||[];
  _D.period   = d.period!==false;
}
const getAccounts = ()=>_D.accounts;
const getFaculty  = ()=>_D.faculty;
const getEvals    = ()=>_D.evals;
const getPeriod   = ()=>_D.period;

// ══════════════════════════════════════════════
//  STATE
// ══════════════════════════════════════════════
let ME=null, qRatings={}, currentFacId=null, confirmCallback=null;
let editTgt={type:null,id:null,role:null};
let ratingChart=null,tchDistChart=null,tchQChart=null,refreshTimer=null;
let _stuTab='eval',_tchTab='results';
const stuCurrentTab=()=>_stuTab;
const tchCurrentTab=()=>_tchTab;

const QUESTIONS=[
  'The teacher explains lessons clearly and effectively.',
  'The teacher is well-prepared and organized for every class.',
  'The teacher treats students with respect and fairness.',
  'The teacher encourages active student participation.',
  "Overall, I am satisfied with this teacher's performance.",
];
const RLABELS=['','Poor','Fair','Good','Very Good','Excellent'];
const Q_SHORT=['Clarity','Preparedness','Respect & Fairness','Participation','Overall Satisfaction'];

// ══════════════════════════════════════════════
//  UTILS
// ══════════════════════════════════════════════
function ini(n){return(n||'').trim().split(/\s+/).map(w=>w[0]||'').slice(0,2).join('').toUpperCase()||'?';}
function stars(v,n=5){let s='';for(let i=1;i<=n;i++)s+=`<span style="color:${i<=v?'#F59E0B':'#DDE5F5'}">★</span>`;return s;}
function $(id){return document.getElementById(id);}
function show(id,on=true){const el=$(id);if(el)el.style.display=on?'block':'none';}
function showView(id){document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));$(id).classList.add('active');window.scrollTo(0,0);}
function openModal(id){$(id).classList.add('show');}
function closeModal(id){$(id).classList.remove('show');}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function sm(a,b){a=(a||'').toLowerCase().replace(/[\s\-_]+/g,'');b=(b||'').toLowerCase().replace(/[\s\-_]+/g,'');return a&&b&&(a===b||a.includes(b)||b.includes(a));}
function pct(v,m=5){return Math.round((parseFloat(v||0)/m)*100);}
function togglePass(id,btn){const i=$(id);if(!i)return;if(i.type==='password'){i.type='text';btn.textContent='🙈 Hide';}else{i.type='password';btn.textContent='👁 Show';}}
function barColor(avg){const n=parseFloat(avg);if(n>=4.5)return'rgba(28,166,112,.82)';if(n>=3.5)return'rgba(79,107,222,.82)';if(n>=2.5)return'rgba(245,158,11,.82)';return'rgba(229,75,106,.82)';}
function fmtDate(iso){if(!iso)return'—';return new Date(iso).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function fmtShortDate(iso){if(!iso)return'—';return new Date(iso).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});}

// ══════════════════════════════════════════════
//  AUTO-REFRESH
// ══════════════════════════════════════════════
function startRefresh(){
  clearInterval(refreshTimer);
  refreshTimer=setInterval(async()=>{
    if(!ME)return;
    await loadData();
    if(ME.role==='student'){const t=stuCurrentTab();if(t==='eval')renderFacCards();else if(t==='status')renderStuStatus();}
    else if(ME.role==='teacher'){const t=tchCurrentTab();if(t==='results')renderTeacherResults();else if(t==='graph')renderTeacherGraphs();updateTeacherProfile();}
    else if(ME.role==='admin'){renderAdminStats();}
  },30000);
}
function stopRefresh(){clearInterval(refreshTimer);refreshTimer=null;}
document.addEventListener('visibilitychange',async()=>{
  if(!document.hidden&&ME){
    await loadData();
    if(ME.role==='student'){const t=stuCurrentTab();if(t==='eval')renderFacCards();else if(t==='status')renderStuStatus();}
    else if(ME.role==='teacher'){if(tchCurrentTab()==='results')renderTeacherResults();}
    else if(ME.role==='admin')renderAdminStats();
  }
});

// ══════════════════════════════════════════════
//  PERIOD UI
// ══════════════════════════════════════════════
function updatePeriodUI(){
  const open=getPeriod();
  const nb=$('nav-period-badge');
  if(nb){nb.textContent=open?'● OPEN':'● CLOSED';nb.className='nav-period '+(open?'period-open':'period-closed');}
  const banner=$('admin-period-banner');
  if(banner){
    banner.className='period-banner'+(open?'':' closed-banner');
    $('period-banner-label').textContent='Evaluation Period: '+(open?'OPEN':'CLOSED');
    $('period-banner-sub').textContent=open?'Students can currently submit evaluations.':'Students cannot submit evaluations right now.';
    $('period-toggle-btn').textContent=open?'Close Period':'Open Period';
  }
}
function togglePeriod(){
  const open=getPeriod();
  confirmAction2(open?'Close Evaluation Period':'Open Evaluation Period',
    open?'Students will no longer be able to submit evaluations. Continue?':'Students will be able to submit evaluations again. Continue?',
    open?{bg:'var(--red)',color:'#fff',text:'Close Period'}:{bg:'var(--green)',color:'#fff',text:'Open Period'},
    async()=>{await api('toggle_period');await loadData();updatePeriodUI();renderAdminStats();});
}
function confirmAction2(title,msg,btnStyle,cb){
  $('confirm-title').textContent=title;$('confirm-msg').textContent=msg;
  const btn=$('confirm-ok-btn');
  btn.style.cssText=`background:${btnStyle.bg};color:${btnStyle.color};padding:10px 22px;font-size:13px;border-radius:10px;font-family:'Poppins',sans-serif;font-weight:600;cursor:pointer;border:none;`;
  btn.textContent=btnStyle.text;confirmCallback=cb;openModal('modal-confirm');
}
function confirmAction(){if(confirmCallback){const cb=confirmCallback;confirmCallback=null;Promise.resolve(cb());}closeModal('modal-confirm');}

// ══════════════════════════════════════════════
//  LOGIN / LOGOUT
// ══════════════════════════════════════════════
function forceLogout(){ME=null;stopRefresh();showView('view-login');}

async function doLogin(){
  const user=$('login-user').value.trim(),pass=$('login-pass').value;
  if(!user||!pass){show('login-err');return;}
  const btn=$('login-btn');btn.disabled=true;btn.textContent='Logging in...';
  show('login-err',false);
  const result=await api('login',{username:user,password:pass});
  btn.disabled=false;btn.textContent='Log In';
  if(result.error){$('login-err').textContent=result.error;show('login-err');return;}
  ME={...result.user};
  await loadData();
  enterApp();
}

function enterApp(){
  $('nav-username').textContent=ME.name||ME.username;
  $('nav-role-badge').textContent=ME.role==='admin'?'🛡️ Admin':ME.role==='teacher'?'👨‍🏫 Teacher':'🎓 Student';
  $('nav-role-badge').className='nav-role '+ME.role;
  updatePeriodUI();
  $('navbar').classList.add('show');
  $('nav-refresh-ind').style.display='';
  startRefresh();
  if(ME.role==='student'){
    $('nav-anon-id').textContent='ID: '+ME.anonId;$('nav-anon-id').style.display='';
    $('stu-display-name').textContent=ME.name;$('stu-section-display').textContent='📍 '+ME.section;$('stu-anon-id').textContent=ME.anonId;
    const open=getPeriod();$('stu-closed-banner').style.display=open?'none':'block';$('stu-open-content').style.display=open?'':'none';
    if(open)stuTab('eval');showView('view-student');
  } else if(ME.role==='teacher'){
    $('nav-anon-id').style.display='none';$('tch-display-name').textContent=ME.name;
    $('tch-sub-display').textContent=(ME.subject?'📚 '+ME.subject:'')+(ME.section?' · 🏫 '+ME.section:'');
    tchTab('results');showView('view-teacher');
  } else {
    $('nav-anon-id').style.display='none';showView('view-admin');renderAdminStats();switchTab('accounts');
  }
}

async function logout(){
  await api('logout');
  ME=null;qRatings={};currentFacId=null;stopRefresh();
  $('navbar').classList.remove('show');$('nav-anon-id').style.display='none';$('nav-refresh-ind').style.display='none';
  $('login-user').value='';$('login-pass').value='';show('login-err',false);
  [tchDistChart,tchQChart,ratingChart].forEach(c=>{try{c&&c.destroy();}catch(e){}});
  tchDistChart=tchQChart=ratingChart=null;showView('view-login');
}
function goHome(){if(!ME)return;if(ME.role==='student')stuTab('eval');else if(ME.role==='admin'){renderAdminStats();switchTab('accounts');}}

// ══════════════════════════════════════════════
//  STUDENT
// ══════════════════════════════════════════════
function stuTab(tab){
  _stuTab=tab;
  ['eval','status','profile'].forEach(t=>{$('stu-tab-'+t).classList.toggle('active',t===tab);$('stu-panel-'+t).style.display=t===tab?'':'none';});
  if(tab==='eval')showStudentHome();else if(tab==='status')renderStuStatus();else if(tab==='profile')renderStuProfile();
}
function renderFacCards(){
  const allFac=getFaculty(),evs=getEvals();
  const visible=allFac.filter(f=>sm(f.section,ME.section));
  const doneCount=visible.filter(f=>evs.some(e=>e.anonId===ME.anonId&&e.facultyId===f.id)).length;
  const total=visible.length;
  $('stu-done-count').textContent=doneCount;$('stu-total-count').textContent=total;
  const ring=$('stu-ring');
  if(ring){const c=125.7;ring.style.strokeDashoffset=total?c-(doneCount/total)*c:c;ring.style.stroke=doneCount===total&&total>0?'#1CA670':'#4F6BDE';}
  const msg=$('stu-info-msg'),wrap=$('fac-cards-wrap');
  if(!allFac.length){msg.textContent='⚠️ No faculty added yet. Ask your admin.';msg.className='alert alert-warn';wrap.innerHTML='<div class="empty"><div class="empty-icon">📭</div><div class="empty-text">No faculty found.</div></div>';return;}
  if(!visible.length){msg.textContent=`⚠️ No faculty assigned to your section "${ME.section}" yet.`;msg.className='alert alert-warn';wrap.innerHTML='<div class="empty"><div class="empty-icon">📭</div><div class="empty-text">No faculty for your section.</div></div>';return;}
  if(doneCount===total&&total>0){msg.textContent=`🎉 You have evaluated all ${total} teacher(s). Thank you!`;msg.className='alert alert-success';}
  else{msg.textContent=`📚 ${total-doneCount} of ${total} teacher(s) still need evaluation.`;msg.className='alert alert-info';}
  wrap.innerHTML=visible.map(f=>{
    const done=evs.some(e=>e.anonId===ME.anonId&&e.facultyId===f.id);
    const ev=evs.find(e=>e.anonId===ME.anonId&&e.facultyId===f.id);
    const fEvs=evs.filter(e=>e.facultyId===f.id);
    const avg=fEvs.length?(fEvs.reduce((s,e)=>s+e.overall,0)/fEvs.length).toFixed(1):null;
    return `<div class="fac-card ${done?'done':''}">
      <div style="display:flex;align-items:flex-start;gap:12px">
        <div class="fac-avatar">${ini(f.name)}</div>
        <div style="flex:1;min-width:0"><div class="fac-name">${esc(f.name)}</div><div class="fac-meta"><span>📚 ${esc(f.subject)}</span><span>🏫 ${esc(f.section)}</span></div></div>
        ${done?`<span class="badge badge-green">✅ Done</span>`:`<span class="badge badge-gold">⏳ Pending</span>`}
      </div>
      ${avg?`<div style="font-size:11px;color:var(--t2)">Class avg: <span class="star-disp">${stars(Math.round(avg))}</span> <strong>${avg}/5</strong></div>`:''}
      ${done?`<div class="done-overlay">✅ Submitted ${ev?fmtShortDate(ev.date):''}  — thank you!</div>`:`<button class="btn btn-primary btn-sm btn-block" onclick="startEval(${f.id})">📝 Evaluate Now</button>`}
    </div>`;
  }).join('');
}
function startEval(facId){
  const fac=getFaculty().find(f=>f.id===facId);if(!fac)return;
  if(!getPeriod()){alert('The evaluation period is currently closed.');return;}
  if(getEvals().some(e=>e.anonId===ME.anonId&&e.facultyId===facId)){alert('You already evaluated '+fac.name+'.');return;}
  currentFacId=facId;qRatings={};
  $('eval-fac-name').textContent=fac.name;$('eval-fac-meta').textContent=fac.subject+' · '+fac.section;
  show('eval-err',false);show('eval-success',false);$('eval-comment').value='';
  const btn=$('eval-submit-btn');btn.disabled=false;btn.textContent='Submit Evaluation';
  renderQuestions();$('student-home').style.display='none';$('student-eval').style.display='';window.scrollTo(0,0);
}
function showStudentHome(){$('student-home').style.display='';$('student-eval').style.display='none';renderFacCards();window.scrollTo(0,0);}
function renderQuestions(){
  $('eval-questions-wrap').innerHTML=QUESTIONS.map((q,i)=>`
    <div class="eval-q">
      <div class="q-text"><span class="q-num">${i+1}</span>${q}</div>
      <div class="star-row">${[1,2,3,4,5].map(v=>`<span class="star" id="star-${i}-${v}" onclick="setRating(${i},${v})">★</span>`).join('')}</div>
      <div class="star-label" id="slabel-${i}">Click a star to rate</div>
    </div>`).join('');
}
function setRating(qi,val){
  qRatings[qi]=val;
  for(let v=1;v<=5;v++){const s=$(`star-${qi}-${v}`);if(s)s.classList.toggle('active',v<=val);}
  const l=$(`slabel-${qi}`);if(l)l.textContent=RLABELS[val];
}
async function submitEval(){
  if(QUESTIONS.some((_,i)=>!qRatings[i])){show('eval-err');return;}
  if(!getPeriod()){alert('Evaluation period is closed.');return;}
  show('eval-err',false);
  const comment=$('eval-comment').value.trim();
  const overall=parseFloat((Object.values(qRatings).reduce((a,b)=>a+b,0)/QUESTIONS.length).toFixed(2));
  const btn=$('eval-submit-btn');btn.disabled=true;btn.textContent='Submitting...';
  const result=await api('submit_eval',{faculty_id:currentFacId,anon_id:ME.anonId,ratings:{...qRatings},overall,comment});
  if(result.error){btn.disabled=false;btn.textContent='Submit Evaluation';alert(result.error);return;}
  await loadData();show('eval-success');btn.textContent='✅ Submitted';
  setTimeout(()=>showStudentHome(),2200);
}
function renderStuStatus(){
  const allFac=getFaculty(),evs=getEvals();
  const visible=allFac.filter(f=>sm(f.section,ME.section));
  const evaluated=visible.filter(f=>evs.some(e=>e.anonId===ME.anonId&&e.facultyId===f.id));
  const pending=visible.filter(f=>!evs.some(e=>e.anonId===ME.anonId&&e.facultyId===f.id));
  $('stu-evaluated-list').innerHTML=evaluated.length?evaluated.map(f=>{const ev=evs.find(e=>e.anonId===ME.anonId&&e.facultyId===f.id);return`<div class="fac-item"><div class="fac-avt-sm">${ini(f.name)}</div><div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:700">${esc(f.name)}</div><div style="font-size:10px;color:var(--t2)">${esc(f.subject)} · ${fmtShortDate(ev?.date)}</div><div style="font-size:11px;color:var(--gold)">${stars(Math.round(ev?.overall||0))} <span style="color:var(--t2)">${ev?.overall?.toFixed(1)||'—'}/5</span></div></div><span class="badge badge-green">✅ Done</span></div>`;}).join(''):`<div class="empty" style="padding:14px"><div class="empty-text">None yet</div></div>`;
  $('stu-pending-list').innerHTML=pending.length?pending.map(f=>`<div class="fac-item"><div class="fac-avt-sm grey">${ini(f.name)}</div><div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:700">${esc(f.name)}</div><div style="font-size:10px;color:var(--t2)">${esc(f.subject)}</div></div><button class="btn btn-primary btn-sm" onclick="stuTab('eval')" style="font-size:10px;padding:4px 10px">Evaluate</button></div>`).join(''):`<div class="empty" style="padding:14px"><div class="empty-text">All done! 🎉</div></div>`;
}
function renderStuProfile(){
  const evCount=getEvals().filter(e=>e.anonId===ME.anonId).length;
  $('prof-stu-name').textContent=ME.name;$('prof-stu-user').textContent=ME.username;$('prof-stu-sec').textContent=ME.section;$('prof-stu-anon').textContent=ME.anonId;$('prof-stu-evcount').textContent=evCount+' submitted';
  show('stu-pw-err',false);show('stu-pw-ok',false);$('stu-pw-cur').value='';$('stu-pw-new').value='';$('stu-pw-con').value='';
}

// ══════════════════════════════════════════════
//  CHANGE PASSWORD
// ══════════════════════════════════════════════
async function changePwd(role){
  const p=role==='student'?'stu':'tch';
  const cur=$(p+'-pw-cur').value,nw=$(p+'-pw-new').value.trim(),con=$(p+'-pw-con').value.trim();
  show(p+'-pw-err',false);show(p+'-pw-ok',false);
  if(!cur||!nw||!con){$(p+'-pw-err').textContent='Please fill in all fields.';show(p+'-pw-err');return;}
  if(nw.length<4){$(p+'-pw-err').textContent='New password must be at least 4 characters.';show(p+'-pw-err');return;}
  if(nw!==con){$(p+'-pw-err').textContent='New passwords do not match.';show(p+'-pw-err');return;}
  const result=await api('change_password',{current_password:cur,new_password:nw});
  if(result.error){$(p+'-pw-err').textContent=result.error;show(p+'-pw-err');return;}
  ME={...ME,password:nw};show(p+'-pw-ok');
  $(p+'-pw-cur').value='';$(p+'-pw-new').value='';$(p+'-pw-con').value='';
  setTimeout(()=>show(p+'-pw-ok',false),3000);
}

// ══════════════════════════════════════════════
//  TEACHER
// ══════════════════════════════════════════════
function tchTab(tab){
  _tchTab=tab;
  ['results','graph','profile'].forEach(t=>{$('tch-tab-'+t).classList.toggle('active',t===tab);$('tch-panel-'+t).style.display=t===tab?'':'none';});
  if(tab==='results')renderTeacherResults();else if(tab==='graph')renderTeacherGraphs();else if(tab==='profile'){renderTeacherProfile();updateTeacherProfile();}
}
function getTeacherEvs(filtered){
  if(!ME.facultyId)return[];
  let evs=getEvals().filter(e=>e.facultyId===ME.facultyId);
  return filtered?filterEvsByDate(evs):evs;
}
function filterEvsByDate(evs){
  const sel=$('tch-filter-date');if(!sel||sel.value==='all')return evs;
  const now=new Date(),v=sel.value,start=new Date(now);
  if(v==='today'){start.setHours(0,0,0,0);return evs.filter(e=>new Date(e.date)>=start);}
  if(v==='week'){start.setDate(now.getDate()-now.getDay());start.setHours(0,0,0,0);return evs.filter(e=>new Date(e.date)>=start);}
  if(v==='month'){start.setDate(1);start.setHours(0,0,0,0);return evs.filter(e=>new Date(e.date)>=start);}
  if(v==='custom'){const from=$('tch-date-from')?.value,to=$('tch-date-to')?.value;if(!from&&!to)return evs;return evs.filter(e=>{const d=new Date(e.date);return(!from||d>=new Date(from))&&(!to||d<=new Date(to+'T23:59:59'));});}
  return evs;
}
function applyTeacherFilter(){const v=$('tch-filter-date').value;const cd=$('tch-custom-dates');if(cd)cd.style.display=v==='custom'?'flex':'none';renderTeacherResults();}
function renderTeacherResults(){
  const wrap=$('tch-eval-summary');if(!wrap)return;
  if(!ME.facultyId){wrap.innerHTML=`<div class="alert alert-warn">⚠️ No faculty record linked to your account. Contact admin.</div>`;return;}
  const fac=getFaculty().find(f=>f.id===ME.facultyId);
  if(!fac){wrap.innerHTML=`<div class="alert alert-warn">Faculty record not found. Contact admin.</div>`;return;}
  const allEvs=getTeacherEvs(false),evs=getTeacherEvs(true);
  const fc=$('tch-filter-count');if(fc)fc.textContent=`(${evs.length} of ${allEvs.length} evaluations)`;
  if(!allEvs.length){wrap.innerHTML=`<div style="text-align:center;padding:28px"><div class="fac-avatar" style="margin:0 auto 12px;width:52px;height:52px;font-size:20px">${ini(fac.name)}</div><div style="font-size:15px;font-weight:700">${esc(fac.name)}</div><div style="font-size:11px;color:var(--t2);margin-top:3px">📚 ${esc(fac.subject)} · 🏫 ${esc(fac.section)}</div><div class="alert alert-info" style="margin-top:16px;text-align:left">No evaluations submitted yet.</div></div>`;return;}
  if(!evs.length){wrap.innerHTML=`<div class="alert alert-warn">No evaluations match the current filter.</div>`;return;}
  const avg=(evs.reduce((s,e)=>s+e.overall,0)/evs.length).toFixed(2);const p=pct(avg);const barCls=parseFloat(avg)>=4?'green':parseFloat(avg)>=3?'':'gold';
  wrap.innerHTML=`
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px"><div class="fac-avatar" style="width:52px;height:52px;font-size:20px">${ini(fac.name)}</div><div><div style="font-size:15px;font-weight:700">${esc(fac.name)}</div><div style="font-size:11px;color:var(--t2)">📚 ${esc(fac.subject)} · 🏫 ${esc(fac.section)}</div></div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px">
      <div class="stat-card"><div class="stat-icon" style="background:#EEF2FF">⭐</div><div class="stat-value">${avg}</div><div class="stat-label">Overall Rating</div><div class="stat-sub">out of 5.0</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#F0FDF4">🎓</div><div class="stat-value">${evs.length}</div><div class="stat-label">Students</div><div class="stat-sub">evaluated you</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#FFF7ED">💬</div><div class="stat-value">${evs.filter(e=>e.comment).length}</div><div class="stat-label">Comments</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#F5F3FF">📊</div><div class="stat-value">${p}%</div><div class="stat-label">Score</div></div>
    </div>
    <div style="background:#F8FAFF;border:1px solid var(--bd);border-radius:13px;padding:14px;margin-bottom:16px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px"><div class="avg-score">${avg} / 5.0</div><div class="star-disp" style="font-size:20px">${stars(Math.round(avg))}</div><div style="font-size:11px;color:var(--t2);margin-left:4px">${evs.length} evaluation${evs.length!==1?'s':''}</div></div><div class="progress-wrap"><div class="progress-bar ${barCls}" style="width:${p}%"></div></div></div>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t2);margin-bottom:9px">Average Score Per Category</div>
    ${QUESTIONS.map((q,i)=>{const qa=(evs.reduce((s,e)=>s+(e.ratings[i]||0),0)/evs.length).toFixed(1);const qp=pct(qa);const qcls=parseFloat(qa)>=4?'green':parseFloat(qa)>=3?'':'gold';return`<div class="rbar-row"><div class="rbar-lbl"><span><strong>Q${i+1}</strong> — ${Q_SHORT[i]}</span><span><strong>${qa}</strong> / 5.0</span></div><div class="progress-wrap"><div class="progress-bar ${qcls}" style="width:${qp}%"></div></div><div style="font-size:10px;color:var(--t3);margin-top:1px;margin-bottom:6px">${q}</div></div>`;}).join('')}
    ${evs.filter(e=>e.comment).length?`<div style="margin-top:14px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t2);margin-bottom:8px">💬 Anonymous Comments (${evs.filter(e=>e.comment).length})</div>${evs.filter(e=>e.comment).map((e,i)=>`<div style="display:flex;gap:9px;align-items:flex-start;margin-bottom:7px"><div style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:var(--blue-l);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--blue)">${i+1}</div><div style="font-size:11px;color:var(--t2);padding:7px 11px;background:#F8FAFF;border-radius:9px;border-left:3px solid var(--blue);flex:1;font-style:italic">"${esc(e.comment)}"<div style="font-size:9px;color:var(--t3);margin-top:3px;font-style:normal">${fmtDate(e.date)}</div></div></div>`).join('')}</div>`:''}`;
}
function renderTeacherGraphs(){
  const evs=getTeacherEvs(false);
  try{if(tchDistChart){tchDistChart.destroy();tchDistChart=null;}}catch(e){}
  try{if(tchQChart){tchQChart.destroy();tchQChart=null;}}catch(e){}
  if(!evs.length){$('tch-dist-bars').innerHTML='';$('tch-panel-graph').innerHTML=`<div class="empty"><div class="empty-icon">📈</div><div class="empty-text">No evaluations yet.</div></div>`;return;}
  const dist={1:0,2:0,3:0,4:0,5:0};evs.forEach(e=>dist[Math.round(e.overall)]=(dist[Math.round(e.overall)]||0)+1);
  const tot=evs.length;
  $('tch-dist-bars').innerHTML=[5,4,3,2,1].map(v=>{const c=dist[v]||0;const p=tot?Math.round(c/tot*100):0;return`<div class="dist-row"><div class="dist-star">${v}★</div><div class="dist-bar-wrap"><div class="dist-bar" style="width:${p}%"></div></div><div class="dist-count">${c}</div><div style="font-size:10px;color:var(--t3);min-width:32px">${p}%</div></div>`;}).join('');
  const canvas=$('tch-dist-chart');
  if(canvas&&typeof Chart!=='undefined')tchDistChart=new Chart(canvas,{type:'doughnut',data:{labels:['5★ Excellent','4★ Very Good','3★ Good','2★ Fair','1★ Poor'],datasets:[{data:[dist[5],dist[4],dist[3],dist[2],dist[1]],backgroundColor:['rgba(28,166,112,.85)','rgba(79,107,222,.85)','rgba(245,158,11,.85)','rgba(249,115,22,.85)','rgba(229,75,106,.85)'],borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{size:11},padding:12}},tooltip:{callbacks:{label:ctx=>`${ctx.label}: ${ctx.raw} (${tot?Math.round(ctx.raw/tot*100):0}%)`}}}}});
  const qAvgs=QUESTIONS.map((_,i)=>(evs.reduce((s,e)=>s+(e.ratings[i]||0),0)/evs.length).toFixed(2));
  const qCanvas=$('tch-q-chart');
  if(qCanvas&&typeof Chart!=='undefined')tchQChart=new Chart(qCanvas,{type:'bar',data:{labels:Q_SHORT,datasets:[{label:'Avg Score',data:qAvgs,backgroundColor:qAvgs.map(v=>barColor(v)),borderColor:qAvgs.map(v=>barColor(v).replace('.82','.96')),borderWidth:2,borderRadius:8,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{min:0,max:5,ticks:{stepSize:1,callback:v=>v+'.0',font:{size:10}},grid:{color:'rgba(0,0,0,.04)'}},x:{grid:{display:false},ticks:{font:{size:10}}}}}});
}
function renderTeacherProfile(){
  $('prof-tch-name').textContent=ME.name;$('prof-tch-user').textContent=ME.username;$('prof-tch-subject').textContent=ME.subject||'—';$('prof-tch-sec').textContent=ME.section||'—';
  show('tch-pw-err',false);show('tch-pw-ok',false);$('tch-pw-cur').value='';$('tch-pw-new').value='';$('tch-pw-con').value='';
}
function updateTeacherProfile(){
  if(!$('prof-tch-evcount'))return;
  const evs=getTeacherEvs(false);$('prof-tch-evcount').textContent=evs.length+' received';
  const avg=evs.length?(evs.reduce((s,e)=>s+e.overall,0)/evs.length).toFixed(1):null;
  $('prof-tch-rating').innerHTML=avg?`${stars(Math.round(avg))} <strong style="color:var(--blue)">${avg}/5</strong>`:'No ratings yet';
}
function exportTeacherResults(){
  if(!ME.facultyId){alert('No faculty record linked to your account.');return;}
  const fac=getFaculty().find(f=>f.id===ME.facultyId);if(!fac){alert('Faculty record not found.');return;}
  const evs=getTeacherEvs(false);if(!evs.length){alert('No evaluations to export yet.');return;}
  const avg=(evs.reduce((s,e)=>s+e.overall,0)/evs.length).toFixed(2);
  let html=`<html><head><title>FacultyEval — ${fac.name}</title><style>body{font-family:Arial,sans-serif;padding:30px;color:#1E2A4A;font-size:13px;max-width:800px;margin:0 auto}h1{font-size:20px;border-bottom:3px solid #4F6BDE;padding-bottom:8px}h2{font-size:14px;color:#4F6BDE;margin:18px 0 7px;text-transform:uppercase}.score-box{background:#EEF2FF;border-radius:10px;padding:14px;margin-bottom:16px;display:flex;gap:18px;flex-wrap:wrap}.score-item{text-align:center}.score-val{font-size:28px;font-weight:800;color:#4F6BDE}.score-lbl{font-size:11px;color:#5A6A8A}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border:1px solid #DDE5F5;padding:8px 11px;font-size:12px}th{background:#EEF2FF;font-weight:700}.comment{background:#F8FAFF;border-left:3px solid #4F6BDE;padding:6px 10px;margin-bottom:6px;font-style:italic;font-size:11px;border-radius:3px}@media print{body{padding:10px}}</style></head><body>
    <h1>📋 Faculty Evaluation Report</h1><p style="font-size:11px;color:#5A6A8A"><strong>${fac.name}</strong> · ${fac.subject} · Section: ${fac.section}<br>Generated: ${new Date().toLocaleString()} · Total Evaluations: ${evs.length}</p>
    <div class="score-box"><div class="score-item"><div class="score-val">${avg}</div><div class="score-lbl">Overall Rating (/5)</div></div><div class="score-item"><div class="score-val">${evs.length}</div><div class="score-lbl">Evaluations</div></div><div class="score-item"><div class="score-val">${pct(avg)}%</div><div class="score-lbl">Score</div></div></div>
    <h2>Average Score Per Category</h2><table><thead><tr><th>#</th><th>Category</th><th>Question</th><th>Average</th></tr></thead><tbody>${QUESTIONS.map((q,i)=>{const qa=(evs.reduce((s,e)=>s+(e.ratings[i]||0),0)/evs.length).toFixed(2);return`<tr><td>${i+1}</td><td>${Q_SHORT[i]}</td><td>${q}</td><td><strong>${qa} / 5.0</strong></td></tr>`;}).join('')}</tbody></table>
    ${evs.filter(e=>e.comment).length?`<h2>Anonymous Comments</h2>${evs.filter(e=>e.comment).map((e,i)=>`<div class="comment">${i+1}. "${e.comment}" <span style="font-size:10px;color:#9AAAC0">(${fmtShortDate(e.date)})</span></div>`).join('')}`:''}
  </body></html>`;
  const w=window.open('','_blank');if(w){w.document.write(html);w.document.close();w.focus();setTimeout(()=>w.print(),600);}
}

// ══════════════════════════════════════════════
//  ADMIN
// ══════════════════════════════════════════════
function renderAdminStats(){
  const acc=getAccounts(),fac=getFaculty(),evs=getEvals();
  const facWithEvs=fac.filter(f=>evs.some(e=>e.facultyId===f.id)).length;
  $('stat-students').textContent=acc.filter(a=>a.role==='student').length;
  $('stat-faculty').textContent=fac.length;$('stat-evals').textContent=evs.length;
  $('stat-evals-sub').textContent=fac.length?`across ${facWithEvs} faculty`:'';
  $('stat-completion').textContent=fac.length?Math.round((facWithEvs/fac.length)*100)+'%':'0%';
  updatePeriodUI();
}
function switchTab(tab){
  ['accounts','faculty','status','results'].forEach(t=>{$('tab-'+t).classList.toggle('active',t===tab);$('panel-'+t).style.display=t===tab?'':'none';});
  if(tab==='accounts')renderAccountsTable();if(tab==='faculty')renderFacultyTable();if(tab==='status')renderEvalStatus();if(tab==='results')renderResults();
}
function renderAccountsTable(){
  const accounts=getAccounts().filter(a=>a.role!=='admin'),evs=getEvals();const tbody=$('accounts-tbody');
  if(!accounts.length){tbody.innerHTML=`<tr><td colspan="7"><div class="empty"><div class="empty-icon">📭</div><div class="empty-text">No accounts. Add students and teachers above.</div></div></td></tr>`;renderAdminStats();return;}
  tbody.innerHTML=accounts.map((a,i)=>{
    const badge=a.role==='teacher'?`<span class="badge badge-green">👨‍🏫 Teacher</span>`:`<span class="badge badge-blue">🎓 Student</span>`;
    const info=a.role==='student'?esc(a.section):(esc(a.subject)+(a.section?` · ${esc(a.section)}`:''));
    let act='—';
    if(a.role==='student'){const c=evs.filter(e=>e.anonId===a.anonId).length;act=`<span class="badge ${c?'badge-green':'badge-gray'}">${c} submitted</span>`;}
    else if(a.role==='teacher'&&a.facultyId){const c=evs.filter(e=>e.facultyId===a.facultyId).length;act=`<span class="badge ${c?'badge-green':'badge-gray'}">${c} received</span>`;}
    return `<tr><td style="color:var(--t3);font-size:10px">${i+1}</td><td><code style="font-size:11px;background:#F0F4FA;padding:2px 6px;border-radius:5px">${esc(a.username)}</code></td><td><strong>${esc(a.name)}</strong></td><td>${badge}</td><td><span class="sec-tag">🏫 ${info}</span></td><td>${act}</td><td><div class="td-actions"><button class="btn-edit" onclick="editAccount(${a.id})">Edit</button><button class="btn-del" onclick="deleteRecord('account',${a.id})">Delete</button></div></td></tr>`;
  }).join('');renderAdminStats();
}
function openAccountModal(role,id=null){
  editTgt={type:'account',id,role};const isTch=role==='teacher',isEdit=!!id;
  $('account-modal-title').textContent=(isEdit?'Edit ':'Add ')+(isTch?'Teacher':'Student')+' Account';
  $('account-role-display').innerHTML=`<span class="badge ${isTch?'badge-green':'badge-blue'}">${isTch?'👨‍🏫 Teacher':'🎓 Student'}</span>`;
  $('mod-stu-fields').style.display=isTch?'none':'';$('mod-tch-fields').style.display=isTch?'':'none';
  show('modal-acc-err',false);
  if(isEdit){
    const a=getAccounts().find(x=>x.id===id);if(!a){closeModal('modal-account');return;}
    editTgt.role=a.role;const isTch2=a.role==='teacher';
    $('account-role-display').innerHTML=`<span class="badge ${isTch2?'badge-green':'badge-blue'}">${isTch2?'👨‍🏫 Teacher':'🎓 Student'}</span>`;
    $('mod-stu-fields').style.display=isTch2?'none':'';$('mod-tch-fields').style.display=isTch2?'':'none';
    $('mod-acc-user').value=a.username;$('mod-acc-pass').value=a.password;$('mod-acc-name').value=a.name;
    $('mod-acc-section').value=a.role==='student'?a.section:'';$('mod-acc-subject').value=a.subject||'';$('mod-acc-tecsec').value=a.role==='teacher'?a.section:'';
  } else {$('mod-acc-user').value='';$('mod-acc-pass').value='';$('mod-acc-name').value='';$('mod-acc-section').value='';$('mod-acc-subject').value='';$('mod-acc-tecsec').value='';}
  openModal('modal-account');setTimeout(()=>$('mod-acc-user').focus(),150);
}
function editAccount(id){const a=getAccounts().find(x=>x.id===id);if(a)openAccountModal(a.role,id);}
async function saveAccount(){
  const username=$('mod-acc-user').value.trim(),password=$('mod-acc-pass').value.trim(),name=$('mod-acc-name').value.trim();
  const role=editTgt.role,isTch=role==='teacher';
  const section=isTch?$('mod-acc-tecsec').value.trim():$('mod-acc-section').value.trim();
  const subject=isTch?$('mod-acc-subject').value.trim():'';
  if(!username||!password||!name||!section||(isTch&&!subject)){show('modal-acc-err');return;}
  show('modal-acc-err',false);const btn=$('modal-acc-save');btn.disabled=true;
  const result=await api('save_account',{id:editTgt.id||null,username,password,name,role,section,subject});
  btn.disabled=false;
  if(result.error){$('modal-acc-err').textContent=result.error;show('modal-acc-err');return;}
  await loadData();closeModal('modal-account');renderAccountsTable();
}
function renderFacultyTable(){
  const fac=getFaculty(),evs=getEvals();const tbody=$('faculty-tbody');
  if(!fac.length){tbody.innerHTML=`<tr><td colspan="7"><div class="empty"><div class="empty-icon">📭</div><div class="empty-text">No faculty yet.</div></div></td></tr>`;renderAdminStats();return;}
  tbody.innerHTML=fac.map((f,i)=>{
    const fe=evs.filter(e=>e.facultyId===f.id);const avg=fe.length?(fe.reduce((s,e)=>s+e.overall,0)/fe.length).toFixed(1):'—';
    return `<tr><td style="color:var(--t3);font-size:10px">${i+1}</td><td><div style="display:flex;align-items:center;gap:8px"><div class="fac-avatar" style="width:28px;height:28px;border-radius:7px;font-size:11px">${ini(f.name)}</div><strong>${esc(f.name)}</strong></div></td><td>${esc(f.subject)}</td><td><span class="sec-tag">🏫 ${esc(f.section)}</span></td><td>${fe.length?`<span class="badge badge-green">${fe.length} eval${fe.length!==1?'s':''}</span>`:`<span class="badge badge-gray">None</span>`}</td><td>${avg!=='—'?`<span class="star-disp">${stars(Math.round(avg))}</span> <strong>${avg}</strong>`:'—'}</td><td><div class="td-actions"><button class="btn-edit" onclick="editFaculty(${f.id})">Edit</button>${fe.length?`<button class="btn-reset" onclick="resetFacultyEvals(${f.id})">Reset</button>`:''}<button class="btn-del" onclick="deleteRecord('faculty',${f.id})">Delete</button></div></td></tr>`;
  }).join('');renderAdminStats();
}
function openFacultyModal(id=null){
  editTgt={type:'faculty',id};$('faculty-modal-title').textContent=id?'Edit Faculty':'Add Faculty';$('modal-fac-save').textContent=id?'Save Changes':'Save Faculty';show('modal-fac-err',false);
  if(id){const f=getFaculty().find(x=>x.id===id);if(!f){closeModal('modal-faculty');return;}$('mod-fac-name').value=f.name;$('mod-fac-subject').value=f.subject;$('mod-fac-section').value=f.section;}
  else{$('mod-fac-name').value='';$('mod-fac-subject').value='';$('mod-fac-section').value='';}
  openModal('modal-faculty');setTimeout(()=>$('mod-fac-name').focus(),150);
}
function editFaculty(id){openFacultyModal(id);}
async function saveFaculty(){
  const name=$('mod-fac-name').value.trim(),subject=$('mod-fac-subject').value.trim(),section=$('mod-fac-section').value.trim();
  if(!name||!subject||!section){show('modal-fac-err');return;}show('modal-fac-err',false);
  const btn=$('modal-fac-save');btn.disabled=true;
  const result=await api('save_faculty',{id:editTgt.id||null,name,subject,section});
  btn.disabled=false;if(result.error){show('modal-fac-err');return;}
  await loadData();closeModal('modal-faculty');renderFacultyTable();
}
function resetFacultyEvals(facId){
  const f=getFaculty().find(x=>x.id===facId);
  confirmAction2('Reset Evaluations',`Delete all ${getEvals().filter(e=>e.facultyId===facId).length} evaluation(s) for "${f?f.name:'this faculty'}"? Students can re-evaluate.`,{bg:'var(--gold)',color:'#fff',text:'Reset Evaluations'},
    async()=>{await api('reset_faculty_evals',{faculty_id:facId});await loadData();renderFacultyTable();renderAdminStats();});
}
function renderEvalStatus(){
  const fac=getFaculty(),evs=getEvals(),acc=getAccounts();buildAdminChart(fac,evs);
  const students=acc.filter(a=>a.role==='student');
  const sections=[...new Set([...fac.map(f=>f.section),...students.map(s=>s.section)])].filter(Boolean);
  $('section-completion-list').innerHTML=sections.length?sections.map(sec=>{
    const secFac=fac.filter(f=>sm(f.section,sec)),secStu=students.filter(s=>sm(s.section,sec));
    const total=secFac.length*secStu.length;
    const done=evs.filter(e=>{const f=fac.find(x=>x.id===e.facultyId);const s=students.find(x=>x.anonId===e.anonId);return f&&s&&sm(f.section,sec)&&sm(s.section,sec);}).length;
    const p=total?Math.round(done/total*100):0;const cls=p>=80?'green':p>=50?'':'gold';
    return`<div class="section-row"><div class="section-label">🏫 ${esc(sec)}</div><div style="flex:1"><div class="progress-wrap"><div class="progress-bar ${cls}" style="width:${p}%"></div></div></div><div class="section-pct">${p}%</div><div style="font-size:10px;color:var(--t3);min-width:70px;text-align:right">${done}/${total}</div></div>`;
  }).join(''):`<div class="empty" style="padding:14px"><div class="empty-text">No sections yet.</div></div>`;
  const evaluated=fac.filter(f=>evs.some(e=>e.facultyId===f.id));const pending=fac.filter(f=>!evs.some(e=>e.facultyId===f.id));
  $('evaluated-list').innerHTML=evaluated.length?evaluated.map(f=>{const fe=evs.filter(e=>e.facultyId===f.id);const avg=(fe.reduce((s,e)=>s+e.overall,0)/fe.length).toFixed(1);return`<div class="fac-item"><div class="fac-avt-sm">${ini(f.name)}</div><div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:700">${esc(f.name)}</div><div style="font-size:10px;color:var(--t2)">${fe.length} eval${fe.length!==1?'s':''} · ⭐ ${avg}/5</div></div><span class="badge badge-green">Done</span></div>`;}).join(''):`<div class="empty" style="padding:12px"><div class="empty-text">None yet</div></div>`;
  $('pending-list').innerHTML=pending.length?pending.map(f=>`<div class="fac-item"><div class="fac-avt-sm grey">${ini(f.name)}</div><div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:700">${esc(f.name)}</div><div style="font-size:10px;color:var(--t2)">0 evaluations</div></div><span class="badge badge-gold">Pending</span></div>`).join(''):`<div class="empty" style="padding:12px"><div class="empty-text">All evaluated ✅</div></div>`;
}
function buildAdminChart(fac,evs){
  const canvas=$('chart-ratings');const wrap=$('adm-chart-wrap');const nd=$('chart-no-data');if(!canvas)return;
  if(ratingChart){try{ratingChart.destroy();}catch(e){}ratingChart=null;}
  const withEvs=fac.filter(f=>evs.some(e=>e.facultyId===f.id));
  if(!withEvs.length){nd.style.display='block';wrap.style.display='none';return;}
  nd.style.display='none';wrap.style.display='';
  const labels=withEvs.map(f=>f.name.replace(/^(Prof\.|Dr\.|Mr\.|Ms\.|Mrs\.)\s*/i,'').trim().split(' ').slice(0,2).join(' '));
  const data=withEvs.map(f=>{const fe=evs.filter(e=>e.facultyId===f.id);return fe.length?(fe.reduce((s,e)=>s+e.overall,0)/fe.length).toFixed(2):0;});
  const colors=data.map(v=>barColor(v));
  if(typeof Chart==='undefined'){wrap.style.display='none';return;}
  ratingChart=new Chart(canvas,{type:'bar',data:{labels,datasets:[{label:'Avg Rating',data,backgroundColor:colors,borderColor:colors.map(c=>c.replace('.82','.96')),borderWidth:2,borderRadius:9,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`Rating: ${ctx.raw} / 5.0`}}},scales:{y:{min:0,max:5,ticks:{stepSize:1,callback:v=>v+'.0',font:{size:11}},grid:{color:'rgba(0,0,0,.04)'}},x:{grid:{display:false},ticks:{font:{size:11}}}}}});
}
function renderResults(){
  const fac=getFaculty(),evs=getEvals();const wrap=$('results-wrap');
  if(!fac.length){wrap.innerHTML=`<div class="empty"><div class="empty-icon">📊</div><div class="empty-text">No faculty added yet.</div></div>`;return;}
  wrap.innerHTML=fac.map(f=>{
    const fe=evs.filter(e=>e.facultyId===f.id);const avg=fe.length?(fe.reduce((s,e)=>s+e.overall,0)/fe.length).toFixed(2):null;
    const p=avg?pct(avg):0;const barCls=avg?parseFloat(avg)>=4?'green':parseFloat(avg)>=3?'':'gold':'';
    const badge=!avg?`<span class="badge badge-gray">No Evaluations</span>`:parseFloat(avg)>=4.5?`<span class="badge badge-green">⭐ Excellent</span>`:parseFloat(avg)>=3.5?`<span class="badge badge-blue">👍 Good</span>`:parseFloat(avg)>=2.5?`<span class="badge badge-gold">📊 Fair</span>`:`<span class="badge badge-red">⚠️ Poor</span>`;
    return `<div class="card" style="padding:18px;margin-bottom:13px"><div class="card-top"></div><div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px"><div class="fac-avatar">${ini(f.name)}</div><div style="flex:1;min-width:110px"><div class="fac-name">${esc(f.name)}</div><div class="fac-meta"><span>📚 ${esc(f.subject)}</span><span>🏫 ${esc(f.section)}</span></div></div><div style="text-align:right">${badge}<div style="font-size:10px;color:var(--t3);margin-top:2px">${fe.length} submission${fe.length!==1?'s':''}</div></div></div>
      ${avg?`<div style="margin-top:13px"><div style="display:flex;align-items:center;gap:9px;margin-bottom:4px"><div class="avg-score">${avg} / 5.0</div><div class="star-disp" style="font-size:17px">${stars(Math.round(avg))}</div></div><div class="progress-wrap"><div class="progress-bar ${barCls}" style="width:${p}%"></div></div><div style="font-size:10px;color:var(--t3);margin-top:2px;margin-bottom:11px">Overall Average</div>${QUESTIONS.map((q,i)=>{const qa=(fe.reduce((s,e)=>s+(e.ratings[i]||0),0)/fe.length).toFixed(1);const qp=pct(qa);const qcls=parseFloat(qa)>=4?'green':parseFloat(qa)>=3?'':'gold';return`<div class="rbar-row"><div class="rbar-lbl"><span style="font-size:10px">${Q_SHORT[i]}</span><span style="font-size:10px"><strong>${qa}</strong>/5</span></div><div class="progress-wrap"><div class="progress-bar ${qcls}" style="width:${qp}%"></div></div></div>`;}).join('')}${fe.filter(e=>e.comment).length?`<div style="margin-top:11px"><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t2);margin-bottom:6px">Comments (${fe.filter(e=>e.comment).length})</div>${fe.filter(e=>e.comment).map(e=>`<div style="font-size:11px;color:var(--t2);padding:6px 10px;background:#F8FAFF;border-radius:7px;border-left:3px solid var(--blue);margin-bottom:5px;font-style:italic">"${esc(e.comment)}"</div>`).join('')}</div>`:''}</div>`:''}
    </div>`;
  }).join('');
}
function printResults(){
  const fac=getFaculty(),evs=getEvals();
  let html=`<html><head><title>FacultyEval Report</title><style>body{font-family:Arial,sans-serif;padding:28px;color:#1E2A4A;font-size:12px}h1{font-size:19px;border-bottom:3px solid #4F6BDE;padding-bottom:7px}h2{font-size:13px;color:#4F6BDE;margin:18px 0 6px;text-transform:uppercase}table{width:100%;border-collapse:collapse;margin-bottom:12px}th,td{border:1px solid #DDE5F5;padding:7px 10px;text-align:left;font-size:11px}th{background:#EEF2FF;font-weight:700}.meta{font-size:10px;color:#5A6A8A;margin-bottom:18px}.comment{background:#F8FAFF;border-left:3px solid #4F6BDE;padding:5px 9px;margin:3px 0;font-style:italic;font-size:11px}@media print{body{padding:0}}</style></head><body><h1>📋 FacultyEval — Admin Results Report</h1><div class="meta">Generated: ${new Date().toLocaleString()} · Total evaluations: ${evs.length}</div>`;
  fac.forEach(f=>{
    const fe=evs.filter(e=>e.facultyId===f.id);const avg=fe.length?(fe.reduce((s,e)=>s+e.overall,0)/fe.length).toFixed(2):'N/A';
    html+=`<h2>${f.name} — ${f.subject} (${f.section})</h2><table><tr><th>Evaluations</th><th>Overall Avg</th></tr><tr><td>${fe.length}</td><td>${avg} / 5.0</td></tr></table>`;
    if(fe.length){html+=`<table><tr><th>Category</th><th>Avg</th></tr>`;QUESTIONS.forEach((q,i)=>{const qa=(fe.reduce((s,e)=>s+(e.ratings[i]||0),0)/fe.length).toFixed(2);html+=`<tr><td>${Q_SHORT[i]}</td><td>${qa}/5</td></tr>`;});html+='</table>';
    const cmts=fe.filter(e=>e.comment);if(cmts.length)html+=`<div>Comments: ${cmts.map(e=>`<div class="comment">"${e.comment}"</div>`).join('')}</div>`;}
  });
  html+='</body></html>';const w=window.open('','_blank');if(w){w.document.write(html);w.document.close();w.focus();setTimeout(()=>w.print(),600);}
}
function deleteRecord(type,id){
  let name='this record';
  if(type==='account'){const a=getAccounts().find(x=>x.id===id);if(a)name=`"${a.name}" (@${a.username})`;}
  if(type==='faculty'){const f=getFaculty().find(x=>x.id===id);if(f)name=`"${f.name}"`;}
  confirmAction2('Delete Record',`Delete ${name}? This cannot be undone.`,{bg:'var(--red)',color:'#fff',text:'Delete'},
    async()=>{
      if(type==='account')await api('delete_account',{id});
      else if(type==='faculty')await api('delete_faculty',{id});
      await loadData();
      if(type==='account')renderAccountsTable();else if(type==='faculty')renderFacultyTable();
      renderAdminStats();
    });
}

// ══════════════════════════════════════════════
//  BOOT  — PHP injects session, no API call needed
// ══════════════════════════════════════════════
(async () => {
  if (__PHP_BOOT__) {
    // User already has a valid PHP session — enter app directly
    ME = __PHP_BOOT__;
    await loadData();
    enterApp();
  }
  // Otherwise login page is already shown (default active view)
})();
</script>
</body>
</html>
