'use strict';

const fs = require('fs');
const assert = require('assert');
const read = path => fs.readFileSync(path, 'utf8');
const email = read('includes/email_verification.php');
const auth = read('includes/auth.php');
const staff = read('admin/staff.php');
const heads = read('admin/heads.php');
const verify = read('verify-email.php');
const adminReports = read('admin/reports.php');
const officeReports = read('office/reports.php');
const dashboard = read('includes/dashboard.php');
const migration = read('database/migrations/2026_10_29_authorized_user_email_verification.sql');
const schemas = read('database/schema.sql') + read('database/railway_schema.sql');
const docker = read('Dockerfile');
const entrypoint = read('docker-runtime-entrypoint.sh');
const notifications = read('notifications.php');
const notificationRead = read('notification-read.php');
const functions = read('includes/functions.php');
const clientCounts = read('includes/client_counts.php');

const checks = [
  ['schema/migration verification columns and grandfathering default', () =>
    ['email_verification_required TINYINT(1) NOT NULL DEFAULT 0', 'email_verified_at DATETIME NULL',
      'verification_token_hash CHAR(64) NULL', 'verification_expires_at DATETIME NULL',
      'verification_sent_at DATETIME NULL', 'verification_send_count TINYINT UNSIGNED']
      .every(column => schemas.includes(column) && migration.includes(column.split(' ')[0]))],
  ['non-destructive migration shape', () =>
    /MODIFY status ENUM\('active','archived','pending'\)/.test(migration)
      && !/DELETE|UPDATE|DROP|TRUNCATE/i.test(migration)],
  ['admin-only fixed-role pending account creation', () =>
    staff.includes('require_login(["admin"])') && heads.includes('require_login(["admin"])')
      && staff.includes("'office_staff','pending'") && heads.includes("'office_head','pending'")],
  ['office-head creation serializes on the office row and rolls back failures', () =>
    heads.includes('SELECT id FROM offices WHERE id=? AND status=\'active\' FOR UPDATE')
      && heads.includes('SELECT id FROM users WHERE office_id=? AND role=\'office_head\' AND status IN (\'active\',\'pending\') FOR UPDATE')
      && heads.includes('$pdo->commit()') && heads.includes('if (db()->inTransaction()) db()->rollBack();')],
  ['domain allowlist required and normalized', () =>
    email.includes("getenv('PASIG_GOV_EMAIL_DOMAINS')") && email.includes('mb_strtolower')
      && staff.includes('if (!approved_government_email_domains())')
      && heads.includes('if (!approved_government_email_domains())')],
  ['cryptographic token generation and hash-only storage', () =>
    email.includes('bin2hex(random_bytes(32))') && email.includes("hash('sha256', $token)")
      && email.includes('verification_token_hash=?')],
  ['CSRF-protected, expiring, pending-only single-use verification', () =>
    verify.includes('verify_csrf()') && verify.includes('verification_expires_at>NOW()')
      && verify.includes("status='pending'") && verify.includes('verification_token_hash=NULL')
      && verify.includes("o.status='active'") && verify.includes('catch (Throwable)')],
  ['token excluded from request URL and carried in fragment/POST body', () =>
    email.includes("app_url('verify-email.php') . '#' ") && verify.includes("$_POST['token']")
      && !verify.includes("$_GET['token']")],
  ['atomic resend interval/count limit and count-window reset ordering', () => {
    const sql = email.match(/UPDATE users SET verification_send_count=([\s\S]*?) WHERE id=\?/);
    return Boolean(sql) && email.includes('INTERVAL 60 SECOND') && email.includes('verification_send_count<5')
      && email.includes('INTERVAL 24 HOUR') && sql[0].indexOf('verification_send_count=IF')
      < sql[0].indexOf('verification_sent_at=NOW()');
  }],
  ['unverified users blocked at login and session reload', () =>
    auth.includes("$user['email_verified_at'] === null")
      && auth.includes("$user['email_verified_at'] !== null")],
  ['report role gates retained', () =>
    adminReports.includes('require_login(["admin"])')
      && officeReports.includes("require_login(['office_head'])")
      && !officeReports.includes("require_login(['office_staff'])")],
  ['admin detail query office/date/archive/void filters', () =>
    adminReports.includes('f.office_id=?') && adminReports.includes('f.visit_date>=?')
      && adminReports.includes('f.visit_date<=?') && adminReports.includes("o.status='active'")
      && adminReports.includes('f.is_void=0')],
  ['aggregates receive archive/void filters including action metrics', () =>
    adminReports.includes('rating_distribution($officeId ?: null, $dateFrom ?: null, $dateTo ?: null, !$includeArchived, $includeVoid)')
      && adminReports.includes('client_output_rows($officeId ?: null, $dateFrom ?: null, $dateTo ?: null, "", !$includeArchived, $includeVoid)')
      && dashboard.includes("$actionWhere = $includeVoid ? ['1=1']")],
  ['office report pinned to authenticated office', () =>
    officeReports.includes("$officeId=(int)$user['office_id']")
      && officeReports.includes("$where=['f.office_id=?']") && officeReports.includes('foreach($rows as $r)')],
  ['announcements target active users dynamically across offices', () =>
    notifications.includes("role IN ('admin','office_head','office_staff') AND status='active'")
      && notifications.includes("role='office_staff' AND status='active'")
      && notifications.includes("['all','staff','office']")
      && notifications.includes("SELECT id FROM offices WHERE id=? AND status='active'")
      && notifications.includes("WHERE office_id=? AND role IN ('office_head','office_staff') AND status='active'")
      && notifications.includes('data-announcement-office')
      && notifications.includes('office_id') && !notifications.includes('office_id=1')],
  ['client-count requests notify and display only to the designated processing admin', () =>
    clientCounts.includes("WHERE username=? AND role='admin' AND status='active'")
      && clientCounts.includes("execute(['admin'])")
      && notifications.includes("LOWER(recipient.username)='admin'")
      && notifications.includes("LOWER(designated_admin.username)='admin'")
      && notificationRead.includes("LOWER(designated_admin.username)='admin'")
      && functions.includes("LOWER(recipient.username)='admin'")
      && !notifications.includes("username='uno'") && !notificationRead.includes("username='uno'")],
  ['client-count requests bind office and requester from active authenticated account', () =>
    clientCounts.includes('client_count_requester($pdo, $staffId)')
      && clientCounts.includes('(int)$staff[\'office_id\']')
      && schemas.includes('UNIQUE KEY uq_pending_staff_year')],
  ['Docker SMTP uses runtime credentials through passwordeval', () =>
    docker.includes('msmtp-mta') && docker.includes('ca-certificates')
      && entrypoint.includes('PASIG_SMTP_PASSWORD')
      && entrypoint.includes('passwordeval "printenv PASIG_SMTP_PASSWORD"')
      && !entrypoint.includes('password ' + '$' + '{PASIG_SMTP_PASSWORD}')],
  ['email transport fails closed without valid public origin', () =>
    email.includes("getenv('PASIG_PUBLIC_BASE_URL')")
      && email.includes('filter_var($publicBase, FILTER_VALIDATE_URL)')],
];

let failures = 0;
for (const [name, test] of checks) {
  try {
    assert(test(), name);
    console.log('PASS (source assertion): ' + name);
  } catch (error) {
    failures += 1;
    console.error('FAIL (source assertion): ' + name);
  }
}
console.log('Source assertions: ' + (checks.length - failures) + ' passed, ' + failures + ' failed.');
process.exitCode = failures ? 1 : 0;
