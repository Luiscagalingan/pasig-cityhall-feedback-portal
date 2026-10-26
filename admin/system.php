<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/bootstrap.php";
$user = require_login(["admin"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    if (($_POST["action"] ?? "") === "purge_audit") {
        $eligible = (int) db()
            ->query(
                "SELECT COUNT(*) FROM audit_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL " .
                    AUDIT_RETENTION_MONTHS .
                    " MONTH)",
            )
            ->fetchColumn();
        $stmt = db()->prepare(
            "DELETE FROM audit_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL " . AUDIT_RETENTION_MONTHS . " MONTH)",
        );
        $stmt->execute();
        audit(
            (int) $user["id"],
            "audit_logs_purged",
            "Purged " . $stmt->rowCount() . " audit record(s) older than six months",
        );
        set_flash("success", $stmt->rowCount() . " eligible audit record(s) permanently purged.");
        redirect("admin/system.php");
    } elseif (($_POST["action"] ?? "") === "apply_retention") {
        $pdo = db();
        $removed = [
            "feedback" => 0,
            "login_attempts" => 0,
            "notifications" => 0,
            "submission_logs" => 0,
            "previews" => 0,
        ];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "DELETE FROM feedback WHERE submitted_at<DATE_SUB(NOW(),INTERVAL " .
                    FEEDBACK_RETENTION_MONTHS .
                    " MONTH)",
            );
            $stmt->execute();
            $removed["feedback"] = $stmt->rowCount();
            $stmt = $pdo->prepare(
                "DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL " .
                    LOGIN_ATTEMPT_RETENTION_DAYS .
                    " DAY)",
            );
            $stmt->execute();
            $removed["login_attempts"] = $stmt->rowCount();
            $stmt = $pdo->prepare(
                "DELETE FROM notifications WHERE created_at<DATE_SUB(NOW(),INTERVAL " .
                    NOTIFICATION_RETENTION_DAYS .
                    " DAY)",
            );
            $stmt->execute();
            $removed["notifications"] = $stmt->rowCount();
            try {
                $stmt = $pdo->prepare(
                    "DELETE FROM public_submission_log WHERE submitted_at<DATE_SUB(NOW(),INTERVAL " .
                        LOGIN_ATTEMPT_RETENTION_DAYS .
                        " DAY)",
                );
                $stmt->execute();
                $removed["submission_logs"] = $stmt->rowCount();
            } catch (Throwable) {
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $previewDir = app_storage_path('import_previews');
        if (is_dir($previewDir)) {
            foreach (glob($previewDir . "/preview_*.json") ?: [] as $file) {
                if (
                    is_file($file) &&
                    filemtime($file) < time() - IMPORT_PREVIEW_RETENTION_HOURS * 3600 &&
                    @unlink($file)
                ) {
                    $removed["previews"]++;
                }
            }
        }
        audit((int) $user["id"], "retention_policy_applied", json_encode($removed));
        set_flash(
            "success",
            "Retention maintenance completed: " . array_sum($removed) . " expired record/file(s) removed.",
        );
        redirect("admin/system.php");
    }
}
$q = trim((string) ($_GET["q"] ?? ""));
$actionFilter = trim((string) ($_GET["action_filter"] ?? ""));
$roleFilter = trim((string) ($_GET["role_filter"] ?? ""));
$moduleFilter = trim((string) ($_GET["module_filter"] ?? ""));
$resultFilter = trim((string) ($_GET["result_filter"] ?? ""));
$userFilter = max(0, (int) ($_GET["user_filter"] ?? 0));
$officeFilter = max(0, (int) ($_GET["office_filter"] ?? 0));
$dateFrom = trim((string) ($_GET["date_from"] ?? ""));
$dateTo = trim((string) ($_GET["date_to"] ?? ""));
$validRoles = ["admin", "office_head", "office_staff", "public"];
$where = ["1=1"];
$params = [];
if ($q !== "") {
    $where[] =
        "(a.details LIKE ? OR a.ip_address LIKE ? OR COALESCE(a.username,u.username) LIKE ? OR u.full_name LIKE ? OR a.module LIKE ? OR a.action LIKE ? OR COALESCE(a.assigned_office_name,o.name) LIKE ?)";
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
}
if ($actionFilter !== "") {
    $where[] = "a.action=?";
    $params[] = $actionFilter;
}
if (in_array($roleFilter, $validRoles, true)) {
    if ($roleFilter === "public") {
        $where[] = "a.user_id IS NULL";
    } else {
        $where[] = "COALESCE(a.user_role,u.role)=?";
        $params[] = $roleFilter;
    }
}
if ($userFilter > 0) { $where[] = 'a.user_id=?'; $params[] = $userFilter; }
if ($officeFilter > 0) { $where[] = 'COALESCE(a.assigned_office_id,u.office_id)=?'; $params[] = $officeFilter; }
if ($moduleFilter !== '') { $where[] = 'a.module=?'; $params[] = $moduleFilter; }
if (in_array($resultFilter, ['success','failed'], true)) { $where[] = 'a.result=?'; $params[] = $resultFilter; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 'a.created_at>=?'; $params[] = $dateFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $where[] = 'a.created_at<DATE_ADD(?,INTERVAL 1 DAY)'; $params[] = $dateTo; }
$whereSql = implode(" AND ", $where);
$perPage = 30;
$auditJoins = " LEFT JOIN users u ON u.id=a.user_id LEFT JOIN offices o ON o.id=COALESCE(a.assigned_office_id,u.office_id) ";
$count = db()->prepare("SELECT COUNT(*) FROM audit_logs a" . $auditJoins . "WHERE " . $whereSql);
$count->execute($params);
$p = pagination((int) $count->fetchColumn(), $perPage);
$exportPdf = ($_GET["export"] ?? "") === "pdf";
$scope = (string) ($_GET["scope"] ?? "current");
$from = max(1, (int) ($_GET["page_from"] ?? $p["page"]));
$to = max($from, (int) ($_GET["page_to"] ?? $from));
$from = min($from, $p["pages"]);
$to = min($to, $p["pages"]);
$limit = $p["per_page"];
$offset = $p["offset"];
if ($exportPdf) {
    if ($scope === "all") {
        $limit = $p["total"];
        $offset = 0;
    } elseif ($scope === "custom") {
        $offset = ($from - 1) * $perPage;
        $limit = ($to - $from + 1) * $perPage;
    } else {
        $offset = ($from - 1) * $perPage;
    }
}
$stmt = db()->prepare(
     "SELECT a.*,COALESCE(NULLIF(u.full_name,'') ,a.username,'Public / System') actor_name,
      COALESCE(a.username,u.username) actor_username,COALESCE(a.user_role,u.role,'public') actor_role,
      COALESCE(a.assigned_office_name,o.name,'System') actor_office,COALESCE(o.code,'') office_code
      FROM audit_logs a{$auditJoins}WHERE {$whereSql} ORDER BY a.created_at DESC,a.id DESC LIMIT " .
         max(1, $limit) . " OFFSET " . $offset,
 );
$stmt->execute($params);
$logs = $stmt->fetchAll();
$eligiblePurge = (int) db()
    ->query(
        "SELECT COUNT(*) FROM audit_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL " .
            AUDIT_RETENTION_MONTHS .
            " MONTH)",
    )
    ->fetchColumn();
$modelFile = __DIR__ . "/../ml/models/svm_sentiment.joblib";
$metricsFile = __DIR__ . "/../ml/models/model_metrics.json";
$metrics = is_file($metricsFile) ? json_decode((string) file_get_contents($metricsFile), true) : null;
$health = predict_sentiment("Mabilis at maayos ang serbisyo.");
$metricSamples = (int) ($metrics["unique_samples"] ?? ($metrics["sample_count"] ?? 0));
$metricAccuracy = (float) ($metrics["final_test_accuracy"] ?? ($metrics["accuracy"] ?? 0));
if ($metrics) {
    $metrics["unique_samples"] = $metricSamples;
    $metrics["final_test_accuracy"] = $metricAccuracy;
}
$counts = [
    "active_offices" => (int) db()->query("SELECT COUNT(*) FROM offices WHERE status='active'")->fetchColumn(),
    "reviews" => (int) db()
        ->query("SELECT COUNT(*) FROM feedback WHERE review_status='needs_review' AND is_void=0")
        ->fetchColumn(),
    "training" => (int) db()->query("SELECT COUNT(*) FROM training_candidates")->fetchColumn(),
    "locked" => (int) db()->query("SELECT COUNT(*) FROM users WHERE locked_until>NOW()")->fetchColumn(),
];
$actions = db()->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$modules = db()->query("SELECT DISTINCT module FROM audit_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$users = db()->query("SELECT id,username,full_name FROM users ORDER BY username")->fetchAll();
$offices = db()->query("SELECT id,name,status FROM offices ORDER BY name")->fetchAll();
render_dashboard_start($exportPdf ? "Ulat ng Audit Log" : "System & Audit", "system");
page_header(
    "System Health & Audit",
    "Makikita rito ang mga aktibidad ng Admin, Office Head, Staff, at public users.",
    $exportPdf
        ? ""
        : '<button type="button" class="btn" data-modal-open="backup-modal">Create Encrypted Backup</button><button type="button" class="btn secondary" data-modal-open="retention-modal">Retention Policy</button>',
);
?>
<?php if (!$exportPdf): ?><div class="tabs"><a class="tab <?= $roleFilter === "" ? "active" : "" ?>" href="?<?= e(
    http_build_query(array_filter(["q" => $q, "action_filter" => $actionFilter])),
) ?>">Lahat</a><?php foreach (
    [
        "admin" => "Admin",
        "office_head" => "Office Head",
        "office_staff" => "Office Staff",
        "public" => "Public / System",
    ]
    as $roleKey => $roleLabel
): ?><a class="tab <?= $roleFilter === $roleKey ? "active" : "" ?>" href="?<?= e(
    http_build_query(array_filter(["q"=>$q,"action_filter"=>$actionFilter,"role_filter"=>$roleKey,"user_filter"=>$userFilter ?: null,"office_filter"=>$officeFilter ?: null,"date_from"=>$dateFrom,"date_to"=>$dateTo,"module_filter"=>$moduleFilter,"result_filter"=>$resultFilter])),
) ?>"><?= e($roleLabel) ?></a><?php endforeach; ?></div><?php endif; ?>
<?php if (!$exportPdf): ?>
<div class="app-modal" id="backup-modal" aria-hidden="true"><section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="backup-title"><button type="button" class="modal-close" data-modal-close>&times;</button><h2 id="backup-title">Create Encrypted Backup</h2><p class="muted">Encrypted with AES-256-GCM and never stored on the web server. Keep the passphrase separately; it cannot be recovered.</p><form method="post" action="<?= e(
    app_url("admin/backup.php"),
 ) ?>" target="_blank" class="modal-form" data-swal-confirm="Create and download an encrypted backup now? Keep the passphrase separate because it cannot be recovered." data-swal-title="Download encrypted backup?" data-swal-confirm-text="Yes, download backup"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><div class="form-group"><label>Backup Passphrase</label><input type="password" name="backup_passphrase" minlength="12" autocomplete="new-password" required></div><div class="form-group"><label>Confirm Passphrase</label><input type="password" name="backup_passphrase_confirm" minlength="12" autocomplete="new-password" required></div><div class="help">Recommended retention: <?= BACKUP_RETENTION_DAYS ?> days. Restore using tools/decrypt_backup.php.</div><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn">Download Encrypted Backup</button></div></form></section></div>
<div class="app-modal" id="retention-modal" aria-hidden="true"><section class="app-modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="retention-title"><button type="button" class="modal-close" data-modal-close>&times;</button><h2 id="retention-title">Apply Retention Policy</h2><p>This removes expired data according to the configured institutional schedule.</p><div class="formula-box">Feedback: <?= FEEDBACK_RETENTION_MONTHS ?> months<br>Audit logs: <?= AUDIT_RETENTION_MONTHS ?> months (separate action)<br>Login attempts: <?= LOGIN_ATTEMPT_RETENTION_DAYS ?> days<br>Notifications: <?= NOTIFICATION_RETENTION_DAYS ?> days<br>Import previews: <?= IMPORT_PREVIEW_RETENTION_HOURS ?> hours<br>Privacy contact: <?= e(
     PRIVACY_CONTACT,
 ) ?></div><form method="post" data-swal-confirm="Remove all records that are older than the configured retention periods? This operation cannot be undone." data-swal-title="Apply retention policy?" data-swal-confirm-text="Yes, remove expired data" data-swal-destructive><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="apply_retention"><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn danger">Remove Expired Data</button></div></form></section></div>
<?php endif; ?>
<?php if (
    !$exportPdf
): ?><div class="app-modal" id="audit-export-modal" aria-hidden="true"><section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="audit-export-title"><button type="button" class="modal-close" data-modal-close>&times;</button><h2 id="audit-export-title">Export Audit Log PDF</h2><p class="muted">Active search and action filters will be preserved.</p><form method="get" target="_blank" class="modal-form"><input type="hidden" name="export" value="pdf"><?php
foreach (["q"=>$q,"action_filter"=>$actionFilter,"role_filter"=>$roleFilter,"user_filter"=>$userFilter,"office_filter"=>$officeFilter,"date_from"=>$dateFrom,"date_to"=>$dateTo,"module_filter"=>$moduleFilter,"result_filter"=>$resultFilter] as $filterName=>$filterValue): if ($filterValue!=="" && $filterValue!==0): ?><input type="hidden" name="<?= e($filterName) ?>" value="<?= e((string)$filterValue) ?>"><?php endif; endforeach;
?><div class="scope-options"><label class="scope-option"><input type="radio" name="scope" value="current" checked><span><strong>Current page</strong><br><small>Page <?= $p[
    "page"
] ?> only.</small></span></label><label class="scope-option"><input type="radio" name="scope" value="custom"><span><strong>Custom page range</strong><br><small>Choose the first and last page below.</small></span></label><label class="scope-option"><input type="radio" name="scope" value="all"><span><strong>All filtered pages</strong><br><small>All <?= $p[
     "total"
 ] ?> matching events.</small></span></label></div><div class="page-range"><label>From page<input type="number" name="page_from" min="1" max="<?= $p[
     "pages"
 ] ?>" value="<?= $p["page"] ?>"></label><label>To page<input type="number" name="page_to" min="1" max="<?= $p[
    "pages"
] ?>" value="<?= $p[
    "page"
] ?>"></label></div><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn">Open PDF Preview</button></div></form></section></div>
<div class="app-modal" id="audit-purge-modal" aria-hidden="true"><section class="app-modal-dialog" role="alertdialog" aria-modal="true"><button type="button" class="modal-close" data-modal-close>&times;</button><h2>Purge Old Audit Logs</h2><p>Only logs older than six months are eligible. This will permanently delete <strong><?= $eligiblePurge ?></strong> record(s); recent logs remain protected.</p><form method="post" data-swal-confirm="Permanently delete the eligible audit logs? Recent logs will remain protected." data-swal-title="Purge old audit logs?" data-swal-confirm-text="Yes, purge logs" data-swal-destructive><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="purge_audit"><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn danger" <?= $eligiblePurge ===
0
    ? "disabled"
    : "" ?>>Purge Eligible Logs</button></div></form></section></div><?php endif; ?>
<div class="stats-grid"><div class="stat-card"><small>Database</small><strong>Connected</strong><span>pasig_feedback_portal</span></div><div class="stat-card"><small>SVM Bridge</small><strong><?= $health[
    "source"
] === "svm"
    ? "SVM Model"
    : "Fallback" ?></strong><span><?= e($health["label"]) ?> · <?= number_format(
     (float) $health["confidence"] * 100,
     1,
 ) ?>%</span></div><div class="stat-card"><small>Active Offices</small><strong><?= $counts[
    "active_offices"
] ?></strong></div><div class="stat-card"><small>Review Queue</small><strong><?= $counts[
    "reviews"
] ?></strong></div><div class="stat-card"><small>Locked Accounts</small><strong><?= $counts[
    "locked"
] ?></strong></div></div>
<?php if (
    !$exportPdf
): ?><div class="content-grid"><section class="card"><div class="card-head"><div><h2>SVM Model Information</h2><p>Active runtime and development metrics</p></div></div><div class="formula-box"><strong>Python:</strong> <?= svm_python_runtime() !==
null
    ? "Validated Python runtime"
    : "Unavailable (see protected bridge log)" ?><br><strong>Model:</strong> <?= is_file($modelFile)
    ? "Ready"
    : "Missing" ?><br><strong>Live source:</strong> <?= $health["source"] === "svm"
    ? "SVM Model"
    : "Fallback" ?><br><strong>Model version:</strong> <?= e(model_version()) ?></div><?php if (
    $metrics
): ?><p><strong>Samples:</strong> <?= (int) $metrics[
    "unique_samples"
] ?> · <strong>Accuracy:</strong> <?= number_format(
     (float) $metrics["final_test_accuracy"] * 100,
     2,
 ) ?>%</p><?php endif; ?></section><section class="card"><div class="card-head"><div><h2>Security & Governance</h2></div></div><div class="bar-list"><div>✓ Five-attempt account lockout</div><div>✓ CSRF, prepared SQL, escaping, and protected audit logs</div><div>✓ Audit retention: purge restricted to records older than six months</div><div>✓ <?= $counts[
    "training"
] ?> approved retraining candidate(s)</div></div></section></div><?php endif; ?>
<section class="card"><div class="card-head"><div><h2><?= $exportPdf
    ? "Audit Log Export"
    : "Recent Audit Log" ?></h2><p><?= $exportPdf ? count($logs) : $p["total"] ?> event(s) shown</p></div><?php if (
     !$exportPdf
 ): ?><div class="actions-cell"><button type="button" class="btn secondary small" data-modal-open="audit-export-modal">Export PDF</button><button type="button" class="btn danger small" data-modal-open="audit-purge-modal" <?= $eligiblePurge ===
0
    ? "disabled"
    : "" ?>>Purge Old Logs (<?= $eligiblePurge ?>)</button></div><?php endif; ?></div><?php if (
    !$exportPdf
): ?><form class="filters compact audit-filters" method="get">
<label><span>From</span><input type="date" name="date_from" value="<?= e($dateFrom) ?>"></label><label><span>To</span><input type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
<input name="q" value="<?= e($q) ?>" placeholder="Search user, activity, module, IP">
<select name="user_filter"><option value="0">All users</option><?php foreach ($users as $auditUser): ?><option value="<?= (int)$auditUser['id'] ?>" <?= $userFilter===(int)$auditUser['id']?'selected':'' ?>><?= e($auditUser['full_name'].' (@'.$auditUser['username'].')') ?></option><?php endforeach; ?></select>
<select name="role_filter"><option value="">All roles</option><?php foreach ($validRoles as $role): ?><option value="<?= e($role) ?>" <?= $roleFilter===$role?'selected':'' ?>><?= e(status_label($role)) ?></option><?php endforeach; ?></select>
<select name="office_filter"><option value="0">All offices</option><?php foreach ($offices as $office): ?><option value="<?= (int)$office['id'] ?>" <?= $officeFilter===(int)$office['id']?'selected':'' ?>><?= e($office['name'].($office['status']==='archived'?' (Archived)':'')) ?></option><?php endforeach; ?></select>
<select name="action_filter"><option value="">All activities</option><?php foreach ($actions as $activity): ?><option value="<?= e($activity) ?>" <?= $actionFilter===$activity?'selected':'' ?>><?= e(status_label($activity)) ?></option><?php endforeach; ?></select>
<select name="module_filter"><option value="">All modules</option><?php foreach ($modules as $module): ?><option value="<?= e($module) ?>" <?= $moduleFilter===$module?'selected':'' ?>><?= e($module) ?></option><?php endforeach; ?></select>
<select name="result_filter"><option value="">All results</option><option value="success" <?= $resultFilter==='success'?'selected':'' ?>>Success</option><option value="failed" <?= $resultFilter==='failed'?'selected':'' ?>>Failed</option></select><button class="btn">Apply Filters</button></form><?php endif; ?><div class="table-wrap"><table><thead><tr><th>Date &amp; Time</th><th>User</th><th>Role</th><th>Office</th><th>Activity</th><th>Module</th><th>Description</th><th>Result</th><th>IP Address</th></tr></thead><tbody><?php foreach ($logs as $l): ?><tr><td><?= e(date("M d, Y h:i A",strtotime($l['created_at']))) ?></td><td><?= e($l['actor_name']) ?><?php if (!empty($l['actor_username'])): ?><br><small class="muted"><?php if ($l['user_id']): ?>#<?= (int)$l['user_id'] ?> · <?php endif; ?>@<?= e($l['actor_username']) ?></small><?php endif; ?></td><td><?= e(status_label($l['actor_role'])) ?></td><td><?= e($l['actor_office']) ?></td><td><?= e(status_label($l['action'])) ?></td><td><?= e($l['module']) ?></td><td><?= e($l['details']) ?></td><td><?= badge(ucfirst($l['result']),$l['result']==='failed'?'negative':'positive') ?></td><td><?= e($l['ip_address']) ?></td></tr><?php endforeach; ?><?php if (!$logs): ?><tr><td colspan="9" class="empty-state">No audit records match the filters.</td></tr><?php endif; ?></tbody></table></div><?php if (!$exportPdf): ?>
    <?= pagination_links($p) ?>
<?php endif; ?></section>
<?php if (
    $exportPdf
): ?><script>history.replaceState({},document.title,location.pathname);window.addEventListener('load',()=>setTimeout(()=>window.print(),400));</script><?php endif; ?>
<?php render_dashboard_end(); ?>
