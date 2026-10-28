<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/bootstrap.php";
$admin = require_login(["admin"]);
$offices = db()->query("SELECT * FROM offices ORDER BY status='active' DESC,name")->fetchAll();
$selected = request_int("office_id");
$requestedViewStatus = (string) ($_GET["status"] ?? "active");
$viewStatus = in_array($requestedViewStatus, ["active", "archived", "pending"], true) ? $requestedViewStatus : "active";
$domainPattern = implode('|', array_map(static fn(string $domain): string => preg_quote($domain, '/'), approved_government_email_domains()));
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $action = (string) ($_POST["action"] ?? "");
    $returnOffice = post_int("return_office");
    $requestedReturnStatus = $_POST["return_status"] ?? ($_GET["status"] ?? "active");
    $returnStatus = in_array($requestedReturnStatus, ["active", "archived", "pending"], true)
        ? (string) $requestedReturnStatus
        : "active";
    try {
        if ($action === "create") {
            $officeId = post_int("office_id");
            $name = trim((string) ($_POST["full_name"] ?? ""));
            $username = trim((string) ($_POST["username"] ?? ""));
            $email = normalized_government_email((string) ($_POST["email"] ?? ""));
            $password = (string) ($_POST["password"] ?? "");
            if (!approved_government_email_domains()) {
                throw new RuntimeException("Account invitations are unavailable until PASIG_GOV_EMAIL_DOMAINS is configured with the approved government email domain.");
            }
            if (
                !$officeId ||
                !is_approved_government_email($email) ||
                $name === "" ||
                $username === "" ||
                !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) ||
                strlen($password) < 8 ||
                !preg_match("/[A-Za-z]/", $password) ||
                !preg_match("/\d/", $password)
            ) {
                throw new RuntimeException(
                    "Complete all fields with a valid email at an approved Pasig City Government domain. Password must be at least 8 characters with a letter and a number.",
                );
            }
            $officeCheck = db()->prepare("SELECT COUNT(*) FROM offices WHERE id=? AND status='active'");
            $officeCheck->execute([$officeId]);
            if (!(int)$officeCheck->fetchColumn()) throw new RuntimeException("Select an active office.");
            $stmt = db()->prepare(
                "INSERT INTO users(office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password,email_verification_required) VALUES(?,?,?,?,?,'office_staff','pending',?,1,1)",
            );
            $stmt->execute([
                $officeId,
                $name,
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                (int) $admin["id"],
            ]);
            $userId = (int)db()->lastInsertId();
            $sent = issue_verification_email($userId);
            audit((int) $admin["id"], "admin_staff_create", "Created pending staff account for office #{$officeId}; verification_email_transport=" . ($sent ? "accepted" : "failed"));
            set_flash($sent ? "success" : "warning", $sent ? "Pending staff account created. Verification message accepted by the mail transport; the account cannot sign in until verified." : "Pending staff account created, but the verification email could not be sent. Configure mail delivery and use Resend Verification.");
        } elseif ($action === "edit") {
            $id = post_int("user_id");
            $officeId = post_int("office_id");
            $name = trim((string) ($_POST["full_name"] ?? ""));
            $username = trim((string) ($_POST["username"] ?? ""));
            $email = normalized_government_email((string) ($_POST["email"] ?? ""));
            $password = (string) ($_POST["password"] ?? "");
            $currentStmt = db()->prepare("SELECT email,status FROM users WHERE id=? AND role='office_staff'");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) throw new RuntimeException("Staff account not found.");
            if ($current["status"] === "pending" && normalized_government_email((string)$current["email"]) !== $email) {
                throw new RuntimeException("A pending account's email cannot be changed because its invitation is tied to that address. Archive it and create a new invitation.");
            }
            $sql =
                $password !== ""
                    ? "UPDATE users SET office_id=?,full_name=?,username=?,email=?,password_hash=?,must_change_password=1 WHERE id=? AND role='office_staff'"
                    : "UPDATE users SET office_id=?,full_name=?,username=?,email=? WHERE id=? AND role='office_staff'";
            $params =
                $password !== ""
                    ? [$officeId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $id]
                    : [$officeId, $name, $username, $email, $id];
            db()->prepare($sql)->execute($params);
            audit(
                (int) $admin["id"],
                "admin_staff_edit",
                "Updated staff #{$id}; office={$officeId}; password_reset=" . ($password !== "" ? "yes" : "no"),
            );
            set_flash("success", "Staff account updated.");
        } elseif ($action === "status") {
            $id = post_int("user_id");
            $status = (string) ($_POST["status"] ?? "");
            if (!in_array($status, ["active", "archived"], true)) {
                throw new RuntimeException("Invalid status.");
            }
            if ($status === "active") {
                $pending = db()->prepare("SELECT email_verification_required,email_verified_at FROM users WHERE id=? AND role='office_staff'");
                $pending->execute([$id]);
                $account = $pending->fetch();
                if (!$account) throw new RuntimeException("Staff account not found.");
                if ((int)$account["email_verification_required"] && $account["email_verified_at"] === null) throw new RuntimeException("This account must complete email verification before activation.");
            }
            db()
                ->prepare("UPDATE users SET status=? WHERE id=? AND role='office_staff'")
                ->execute([$status, $id]);
            audit((int) $admin["id"], "admin_staff_status", "Staff #{$id} -> {$status}");
            set_flash("success", "Staff account status updated.");
        } elseif ($action === "resend_verification") {
            $id = post_int("user_id");
            $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='office_staff' AND status='pending' AND email_verification_required=1 AND email_verified_at IS NULL");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) throw new RuntimeException("This account is not awaiting email verification.");
            $sent = issue_verification_email($id);
            set_flash($sent ? "success" : "warning", $sent ? "Verification message accepted by the mail transport." : "Email was not sent. Check mail configuration and the resend limit, then try again later.");
        } elseif ($action === "delete") {
            $id = post_int("user_id");
            db()
                ->prepare("UPDATE users SET status='archived' WHERE id=? AND role='office_staff'")
                ->execute([$id]);
            audit((int) $admin["id"], "admin_staff_status", "Staff #{$id} -> archived (legacy delete request)");
            set_flash("success", "Staff account archived.");
        }
    } catch (Throwable $e) {
        audit((int) $admin["id"], "staff_management_failed", "Staff account management action failed", "failed");
        set_flash("error", "Unable to complete action: " . $e->getMessage());
    }
    $returnQuery = ["status" => $returnStatus];
    if ($returnOffice) {
        $returnQuery["office_id"] = $returnOffice;
    }
    redirect("admin/staff.php?" . http_build_query($returnQuery));
}
$staffCounts = ["active" => 0, "archived" => 0, "pending" => 0];
foreach (
    db()->query("SELECT status,COUNT(*) total FROM users WHERE role='office_staff' GROUP BY status")->fetchAll()
    as $row
) {
    $staffCounts[$row["status"]] = (int) $row["total"];
}
$where = ["u.role='office_staff'", "u.status=?"];
$params = [$viewStatus];
if ($selected) {
    $where[] = "u.office_id=?";
    $params[] = $selected;
}
$stmt = db()->prepare(
    "SELECT u.*,o.name office_name,o.code office_code FROM users u JOIN offices o ON o.id=u.office_id WHERE " .
        implode(" AND ", $where) .
        " ORDER BY o.name,u.full_name",
);
$stmt->execute($params);
$staff = $stmt->fetchAll();
render_dashboard_start("Manage Staff", "staff");
page_header(
    "Manage Staff by Office",
    "Filter staff accounts by office, then create or manage accounts.",
    '<button type="button" class="btn" data-modal-open="add-staff-modal">Add Staff</button>',
);
?>
<div class="app-modal" id="add-staff-modal" aria-hidden="true"><section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-staff-title"><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button><h2 id="add-staff-title">Add Office Staff</h2><p class="muted">The account will be restricted to feedback and dashboards from its assigned office. Email verification is required before sign in.</p><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="create"><input type="hidden" name="return_office" value="<?= $selected ?>"><input type="hidden" name="return_status" value="pending"><div class="form-group"><label>Assigned Office</label><select name="office_id" required><option value="">Select office</option><?php foreach (
    $offices
    as $o
): ?><option value="<?= (int) $o["id"] ?>" <?= $selected === (int) $o["id"] ? "selected" : "" ?> <?= $o["status"] ===
 "archived"
     ? "disabled"
     : "" ?>><?= e($o["name"]) ?> (<?= e($o["code"]) ?>)<?= $o["status"] === "archived"
    ? " — Archived"
    : "" ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Full Name</label><input name="full_name" required></div><div class="form-row"><div class="form-group"><label>Username</label><input name="username" required></div><div class="form-group"><label>Email</label><input type="email" name="email" required <?= $domainPattern ? 'pattern="^[A-Za-z0-9._%+-]+@(' . e($domainPattern) . ')$"' : 'disabled' ?>><div class="help">Use an approved Pasig City Government email address<?= $domainPattern ? ': ' . e(implode(', ', approved_government_email_domains())) : '. Domain configuration is required.' ?>.</div></div></div><div class="form-group"><label>Temporary Password</label><input type="password" name="password" minlength="8" required><div class="help">At least 8 characters with a letter and number.</div></div><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn">Create Staff</button></div></form></section></div>
<div class="tabs"><a class="tab <?= $viewStatus === "active" ? "active" : "" ?>" href="<?= e(
    app_url("admin/staff.php?status=active" . ($selected ? "&office_id=" . $selected : "")),
) ?>">Active Staff <b><?= $staffCounts["active"] ?></b></a><a class="tab <?= $viewStatus === "archived"
    ? "active"
    : "" ?>" href="<?= e(
    app_url("admin/staff.php?status=archived" . ($selected ? "&office_id=" . $selected : "")),
) ?>">Archived Staff <b><?= $staffCounts["archived"] ?></b></a><a class="tab <?= $viewStatus === "pending" ? "active" : "" ?>" href="<?= e(app_url("admin/staff.php?status=pending" . ($selected ? "&office_id=" . $selected : ""))) ?>">Pending Verification <b><?= $staffCounts["pending"] ?></b></a></div>
<div class="tabs"><a class="tab <?= !$selected ? "active" : "" ?>" href="<?= e(
    app_url("admin/staff.php?status=" . $viewStatus),
) ?>">All Offices</a><?php foreach ($offices as $o): ?><a class="tab <?= $selected === (int) $o["id"]
    ? "active"
    : "" ?>" href="<?= e(app_url("admin/staff.php?status=" . $viewStatus . "&office_id=" . $o["id"])) ?>"><?=
e($o["code"]) .
($o["status"] === "archived" ? " · Archived" : "")
?></a><?php endforeach; ?></div>
<section class="card"><div class="card-head"><div><h2><?= $selected
    ? "Selected Office"
    : "All Office" ?> Staff</h2><p><?= count(
     $staff,
 ) ?> account(s)</p></div></div><div class="table-wrap"><table><thead><tr><th>Staff</th><th>Office</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php
 foreach ($staff as $s): ?><tr><td><strong><?= e($s["full_name"]) ?></strong><br><span class="muted"><?= e(
    $s["username"],
) ?> · <?= e($s["email"]) ?></span></td><td><?= e($s["office_name"]) ?><br><span class="muted"><?= e(
    $s["office_code"],
) ?></span></td><td><?= badge(status_label($s["status"]), $s["status"]) ?></td><td><?= e(
    date("M d, Y", strtotime($s["created_at"])),
) ?></td><td class="actions-cell"><?php if ($s["status"] === "pending"): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resend_verification"><input type="hidden" name="user_id" value="<?= (int)$s["id"] ?>"><input type="hidden" name="return_office" value="<?= $selected ?>"><input type="hidden" name="return_status" value="<?= e($viewStatus) ?>"><button class="btn secondary small">Resend Verification</button></form><?php endif; ?><details><summary class="btn secondary small">Edit</summary><form method="post" style="min-width:300px;padding-top:10px"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?= (int) $s[
    "id"
] ?>"><input type="hidden" name="return_office" value="<?= $selected ?>"><input type="hidden" name="return_status" value="pending"><div class="form-group"><select name="office_id"><?php foreach (
    $offices
    as $o
): ?><option value="<?= (int) $o["id"] ?>" <?= (int) $o["id"] === (int) $s["office_id"] ? "selected" : "" ?>><?= e(
    $o["name"],
) ?></option><?php endforeach; ?></select></div><div class="form-group"><input name="full_name" value="<?= e(
    $s["full_name"],
) ?>" required></div><div class="form-group"><input name="username" value="<?= e(
    $s["username"],
) ?>" required></div><div class="form-group"><input type="email" name="email" value="<?= e(
    $s["email"],
) ?>" required></div><div class="form-group"><input type="password" name="password" placeholder="New password (optional)"></div><button class="btn small">Save</button></form></details><form method="post"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int) $s[
    "id"
] ?>"><input type="hidden" name="return_office" value="<?= $selected ?>"><input type="hidden" name="status" value="<?= $s[
    "status"
] === "archived"
    ? "active"
    : "archived" ?>"><button class="btn <?= $s["status"] === "archived" ? "success" : "danger" ?> small"><?= $s[
     "status"
 ] === "archived"
     ? "Reactivate"
     : "Archive" ?></button></form><form method="post" data-confirm-delete="Permanently delete this staff account?"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $s[
    "id"
] ?>"><input type="hidden" name="return_office" value="<?= $selected ?>"><button class="btn danger small">Delete</button></form></td></tr><?php endforeach;
 if (!$staff): ?><tr><td colspan="5" class="empty-state">No staff found in this view.</td></tr><?php endif;
 ?></tbody></table></div></section>
<?php render_dashboard_end(); ?>
