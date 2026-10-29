<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/bootstrap.php";
$admin = require_login(["admin"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $action = (string) ($_POST["action"] ?? "");
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
            $password = 'password123!';
            if (!approved_government_email_domains()) throw new RuntimeException("Account invitations are unavailable until PASIG_GOV_EMAIL_DOMAINS is configured with the approved government email domain.");
            if (
                !$officeId ||
                !is_approved_government_email($email) ||
                $name === "" ||
                $username === "" ||
                !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) ||
                mb_strlen($name) > 160 ||
                mb_strlen($username) > 80
            ) {
                throw new RuntimeException(
                    "Complete all fields with a valid email at an approved Pasig City Government domain and a valid username.",
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1 FOR UPDATE');
            $duplicate->execute([$username, $email]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('That username or email address is already in use.');
            $officeCheck = $pdo->prepare("SELECT id FROM offices WHERE id=? AND status='active' FOR UPDATE");
            $officeCheck->execute([$officeId]);
            if (!$officeCheck->fetchColumn()) throw new RuntimeException("Select an active office.");
            $check = $pdo->prepare(
                "SELECT id FROM users WHERE office_id=? AND role='office_head' AND status IN ('active','pending') FOR UPDATE",
            );
            $check->execute([$officeId]);
            if ($check->fetchColumn()) {
                throw new RuntimeException(
                    "This office already has an active or pending Office Head. Archive that account before creating a replacement.",
                );
            }
            if (!consume_head_email_code($pdo, (int)$admin['id'], $email, (string)($_POST['challenge_ref'] ?? ''))) {
                throw new RuntimeException('Verify this email with the six-digit code before creating the Office Head account.');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO users(office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password,email_verification_required) VALUES(?,?,?,?,?,'office_head','pending',?,1,1)",
            );
            $stmt->execute([
                $officeId,
                $name,
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                (int) $admin["id"],
            ]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->commit();
            $sent = issue_verification_email($userId);
            audit((int) $admin["id"], "head_create", "Created pending head account for office #{$officeId}; verification_email_transport=" . ($sent ? "accepted" : "failed"));
            $deliveryError = trim((string)($GLOBALS['verification_email_delivery_error'] ?? ''));
            set_flash($sent ? "success" : "warning", $sent ? "Pending Office Head account created. Verification message accepted by the mail transport; the account remains unavailable until verified." : "Pending Office Head account created, but the verification email could not be sent." . ($deliveryError !== '' ? ' ' . $deliveryError : ' Check mail settings and use Resend Verification.'));
        } elseif ($action === "edit") {
            $id = post_int("user_id");
            $officeId = post_int("office_id");
            $name = trim((string) ($_POST["full_name"] ?? ""));
            $username = trim((string) ($_POST["username"] ?? ""));
            $email = normalized_government_email((string) ($_POST["email"] ?? ""));
            $password = (string) ($_POST["password"] ?? "");
            $currentStmt = db()->prepare("SELECT status,email FROM users WHERE id=? AND role='office_head'");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                throw new RuntimeException("Office Head account not found.");
            }
            if (normalized_government_email((string)$current["email"]) !== $email
                && !is_approved_government_email($email)) {
                throw new RuntimeException("A changed Office Head email must use a valid address at an approved government or organization domain.");
            }
            if ($current["status"] === "pending" && normalized_government_email((string)$current["email"]) !== $email) {
                throw new RuntimeException("A pending account's email cannot be changed because its invitation is tied to that address. Archive it and create a new invitation.");
            }
            if ($current["status"] === "active") {
                $check = db()->prepare(
                    "SELECT COUNT(*) FROM users WHERE office_id=? AND role='office_head' AND status='active' AND id<>?",
                );
                $check->execute([$officeId, $id]);
                if ((int) $check->fetchColumn() > 0) {
                    throw new RuntimeException("The selected office already has another active Office Head.");
                }
            }
            if ($password !== "") {
                $stmt = db()->prepare(
                    "UPDATE users SET office_id=?,full_name=?,username=?,email=?,password_hash=?,must_change_password=1 WHERE id=? AND role='office_head'",
                );
                $stmt->execute([$officeId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = db()->prepare(
                    "UPDATE users SET office_id=?,full_name=?,username=?,email=? WHERE id=? AND role='office_head'",
                );
                $stmt->execute([$officeId, $name, $username, $email, $id]);
            }
            audit(
                (int) $admin["id"],
                "head_edit",
                "Updated Office Head #{$id}; office={$officeId}; password_reset=" . ($password !== "" ? "yes" : "no"),
            );
            set_flash("success", "Office Head account updated.");
        } elseif ($action === "status") {
            $id = post_int("user_id");
            $status = (string) ($_POST["status"] ?? "");
            if (!in_array($status, ["active", "archived"], true)) {
                throw new RuntimeException("Invalid account status.");
            }
            if ($status === "active") {
                $officeStmt = db()->prepare("SELECT office_id,email_verification_required,email_verified_at FROM users WHERE id=? AND role='office_head'");
                $officeStmt->execute([$id]);
                $target = $officeStmt->fetch();
                if (!$target) throw new RuntimeException("Office Head account not found.");
                if ((int)$target["email_verification_required"] && $target["email_verified_at"] === null) throw new RuntimeException("This account must complete email verification before activation.");
                $targetOffice = (int)$target["office_id"];
                $check = db()->prepare(
                    "SELECT COUNT(*) FROM users WHERE office_id=? AND role='office_head' AND status='active' AND id<>?",
                );
                $check->execute([$targetOffice, $id]);
                if ((int) $check->fetchColumn() > 0) {
                    throw new RuntimeException("This office already has another active Office Head.");
                }
            }
            $stmt = db()->prepare("UPDATE users SET status=? WHERE id=? AND role='office_head'");
            $stmt->execute([$status, $id]);
            audit((int) $admin["id"], "head_status", "Office Head #{$id} -> {$status}");
            set_flash("success", "Office Head status updated.");
        } elseif ($action === "delete") {
            $id = post_int("user_id");
            $stmt = db()->prepare("UPDATE users SET status='archived' WHERE id=? AND role='office_head'");
            $stmt->execute([$id]);
            audit((int) $admin["id"], "head_status", "Office Head #{$id} -> archived (legacy delete request)");
            set_flash("success", "Office Head account archived.");
        } elseif ($action === "resend_verification") {
            $id = post_int("user_id");
            $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='office_head' AND status='pending' AND email_verification_required=1 AND email_verified_at IS NULL");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) throw new RuntimeException("This account is not awaiting email verification.");
            $sent = issue_verification_email($id);
            $deliveryError = trim((string)($GLOBALS['verification_email_delivery_error'] ?? ''));
            set_flash($sent ? "success" : "warning", $sent ? "Verification message accepted by the mail transport." : "Email was not sent." . ($deliveryError !== '' ? ' ' . $deliveryError : ' Check mail settings and the resend limit, then retry later.'));
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        audit((int) $admin["id"], "head_management_failed", "Office Head management action failed", "failed");
        set_flash("error", "Unable to complete action: " . $e->getMessage());
    }
    redirect("admin/heads.php?status=" . urlencode($returnStatus));
}
$offices = active_offices();
$requestedViewStatus = (string) ($_GET["status"] ?? "active");
$viewStatus = in_array($requestedViewStatus, ["active", "archived", "pending"], true) ? $requestedViewStatus : "active";
$domainPattern = implode('|', array_map(static fn(string $domain): string => preg_quote($domain, '/'), approved_government_email_domains()));
$headCounts = ["active" => 0, "archived" => 0, "pending" => 0];
foreach (
    db()->query("SELECT status,COUNT(*) total FROM users WHERE role='office_head' GROUP BY status")->fetchAll()
    as $row
) {
    $headCounts[$row["status"]] = (int) $row["total"];
}
$headStmt = db()->prepare(
            "SELECT u.*,o.name office_name,o.code office_code FROM users u LEFT JOIN offices o ON o.id=u.office_id WHERE u.role='office_head' AND u.status=? ORDER BY o.name,u.full_name",
);
$headStmt->execute([$viewStatus]);
$heads = $headStmt->fetchAll();
render_dashboard_start("Manage Office Heads", "heads");
page_header(
    "Manage Office Heads",
    "Create, edit, archive, or reactivate Office Head accounts.",
    '<button type="button" class="btn" data-modal-open="add-head-modal">Add Office Head</button>',
);
?>
<div class="app-modal" id="add-head-modal" aria-hidden="true"><section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-head-title"><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button><h2 id="add-head-title">Add Office Head</h2><p class="muted">Assign the account to an active office. Only one active Office Head is allowed per office.</p><form method="post" class="modal-form" autocomplete="off" data-head-create-form><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="create"><input type="hidden" name="return_status" value="pending"><div class="form-group"><label>Assigned Office</label><select name="office_id" required><option value="">Select office</option><?php foreach (
    $offices
    as $o
): ?><option value="<?= (int) $o["id"] ?>"><?= e($o["name"]) ?> (<?= e(
     $o["code"],
 ) ?>)</option><?php endforeach; ?></select></div><div class="form-group"><label>Full Name</label><input name="full_name" required></div><div class="form-row"><div class="form-group"><label>Username</label><input name="username" autocomplete="new-username" data-lpignore="true" data-1p-ignore="true" required></div><div class="form-group"><label>Email</label><input type="email" name="email" required <?= $domainPattern ? 'pattern="^[A-Za-z0-9._%+-]+@(' . e($domainPattern) . ')$"' : 'disabled' ?>><div class="help">Use an approved government or official organization email address<?= $domainPattern ? ': ' . e(implode(', ', approved_government_email_domains())) : '. Domain configuration is required.' ?>.</div></div></div><input type="hidden" name="challenge_ref" value=""><div class="form-group"><button type="button" class="btn secondary" data-head-send-code>Send 6-Digit Verification Code</button><p class="help" data-head-code-status aria-live="polite"></p></div><div class="form-row" data-head-code-panel hidden><div class="form-group"><label for="head-email-code">Email Verification Code</label><input id="head-email-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" data-head-code><div class="help">Enter the six-digit code sent to the email address above. It expires in 10 minutes.</div></div><div class="form-group" style="align-self:end"><button type="button" class="btn secondary" data-head-verify-code>Verify Code</button></div></div><div class="form-group"><label>Temporary Password</label><input type="text" value="password123!" readonly><div class="help">This temporary password will be emailed with the verification link and must be changed after first sign-in.</div></div><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn" data-head-create disabled>Create Head Account</button></div></form></section></div>
<div class="tabs"><a class="tab <?= $viewStatus === "active"
    ? "active"
    : "" ?>" href="?status=active">Active Heads <b><?= $headCounts["active"] ?></b></a><a class="tab <?= $viewStatus ===
"archived"
    ? "active"
    : "" ?>" href="?status=archived">Archived Heads <b><?= $headCounts["archived"] ?></b></a><a class="tab <?= $viewStatus === "pending" ? "active" : "" ?>" href="?status=pending">Pending Verification <b><?= $headCounts["pending"] ?></b></a></div>
<section class="card"><div class="card-head"><div><h2>Office Head Directory</h2><p><?= count(
    $heads,
) ?> registered account(s)</p></div></div><div class="table-wrap"><table><thead><tr><th>Office Head</th><th>Office</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php
 foreach ($heads as $h): ?><tr><td><strong><?= e($h["full_name"]) ?></strong><br><span class="muted"><?= e(
    $h["username"],
) ?> · <?= e($h["email"]) ?></span></td><td><?= e($h["office_name"] ?? "Unassigned") ?><br><span class="muted"><?= e(
    $h["office_code"] ?? "",
) ?></span></td><td><?= badge(status_label($h["status"]), $h["status"]) ?></td><td><?= e(
    date("M d, Y", strtotime($h["created_at"])),
) ?></td><td class="actions-cell"><?php if ($h["status"] === "pending"): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resend_verification"><input type="hidden" name="user_id" value="<?= (int)$h["id"] ?>"><input type="hidden" name="return_status" value="<?= e($viewStatus) ?>"><button class="btn secondary small">Resend Verification</button></form><?php endif; ?><details><summary class="btn secondary small">Edit</summary><form method="post" style="min-width:300px;padding-top:10px"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?= (int) $h[
    "id"
] ?>"><div class="form-group"><select name="office_id" required><?php foreach (
    $offices
    as $o
): ?><option value="<?= (int) $o["id"] ?>" <?= (int) $o["id"] === (int) $h["office_id"] ? "selected" : "" ?>><?= e(
    $o["name"],
) ?></option><?php endforeach; ?></select></div><div class="form-group"><input name="full_name" value="<?= e(
    $h["full_name"],
) ?>" required></div><div class="form-group"><input name="username" value="<?= e(
    $h["username"],
) ?>" required></div><div class="form-group"><input type="email" name="email" value="<?= e(
    $h["email"],
) ?>" required></div><div class="form-group"><input type="password" name="password" placeholder="New password (optional)"></div><button class="btn small">Save Changes</button></form></details><form method="post"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int) $h[
    "id"
] ?>"><input type="hidden" name="status" value="<?= $h["status"] === "archived"
    ? "active"
    : "archived" ?>"><button class="btn <?= $h["status"] === "archived" ? "success" : "danger" ?> small"><?= $h[
     "status"
 ] === "archived"
     ? "Reactivate"
     : "Archive" ?></button></form><form method="post" data-confirm-delete="Permanently delete this Office Head account?"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $h[
    "id"
] ?>"><button class="btn danger small">Delete</button></form></td></tr><?php endforeach;
 if (!$heads): ?><tr><td colspan="5" class="empty-state">No Office Head accounts.</td></tr><?php endif;
 ?></tbody></table></div></section>
<script>(()=>{
const form=document.querySelector('[data-head-create-form]');if(!form)return;
const email=form.elements.email,ref=form.elements.challenge_ref,code=form.querySelector('[data-head-code]'),panel=form.querySelector('[data-head-code-panel]'),send=form.querySelector('[data-head-send-code]'),verify=form.querySelector('[data-head-verify-code]'),create=form.querySelector('[data-head-create]'),status=form.querySelector('[data-head-code-status]');
let expiresAt=0,resendAt=0,verified=false,sending=false,message='',ticker=null;
const clock=seconds=>{const n=Math.max(0,Math.ceil(seconds));return `${Math.floor(n/60)}:${String(n%60).padStart(2,'0')}`};
const render=()=>{const now=Date.now();if(expiresAt&&now>=expiresAt){expiresAt=0;verified=false;create.disabled=true;message='Verification code expired. Request a new code.'}if(resendAt&&now>=resendAt)resendAt=0;send.disabled=sending||Boolean(resendAt&&now<resendAt);const extras=[];if(expiresAt){message=message||'Verification code sent successfully.';extras.push(`Expires in ${clock((expiresAt-now)/1000)}.`)}if(resendAt&&now<resendAt)extras.push(`Resend available in ${clock((resendAt-now)/1000)}.`);status.textContent=message+(extras.length?' '+extras.join(' '):'');if(!expiresAt&&!resendAt&&ticker){clearInterval(ticker);ticker=null}};
const startClock=()=>{if(!ticker)ticker=setInterval(render,1000);render()};
const request=async(action)=>{const data=new FormData(form);data.set('action',action);const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),20000);try{const response=await fetch('<?= e(app_url('admin/head-email-code.php')) ?>',{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},signal:controller.signal});const raw=await response.text();let result=null;if((response.headers.get('content-type')||'').includes('application/json')){try{result=JSON.parse(raw)}catch{}}if(result&&typeof result.message==='string'){if(!response.ok||result.ok!==true){const failure=new Error(result.message);failure.requestId=result.request_id;failure.retryAfterSeconds=result.retry_after_seconds;failure.reason=result.reason;throw failure}return result}if(response.status===419&&raw)throw new Error(raw.slice(0,300));if(response.redirected||response.status===401||response.status===403)throw new Error('Your admin session expired or lacks access. Refresh the page and sign in again.');throw new Error('The server returned HTTP '+response.status+' without a readable verification message. Refresh the page and try again.')}finally{clearTimeout(timer)}};
send.addEventListener('click',async()=>{if(!form.reportValidity())return;sending=true;message='Sending verification code…';render();try{const result=await request('send_email_code');ref.value=result.challenge;code.value='';panel.hidden=false;verified=false;create.disabled=true;message=result.message;expiresAt=Date.now()+(Number(result.expires_in_seconds)||600)*1000;resendAt=Date.now()+(Number(result.resend_in_seconds)||60)*1000}catch(error){message=error.name==='AbortError'?'The request timed out. Check the server and try again.':error.message+(error.requestId?' (Reference: '+error.requestId+')':'');if(Number(error.retryAfterSeconds)>0)resendAt=Date.now()+Number(error.retryAfterSeconds)*1000;if(error.reason==='code_expired')expiresAt=Date.now();}finally{sending=false;startClock()}});
verify.addEventListener('click',async()=>{if(!ref.value){message='Request a verification code first.';render();return}verify.disabled=true;message='Checking code…';render();try{const result=await request('verify_email_code');verified=true;create.disabled=false;message=result.message}catch(error){message=error.message+(error.requestId?' (Reference: '+error.requestId+')':'');if(error.reason==='code_expired')expiresAt=Date.now();verified=false;create.disabled=true}finally{verify.disabled=false;startClock()}});
email.addEventListener('input',()=>{ref.value='';expiresAt=0;resendAt=0;verified=false;create.disabled=true;message='Email changed. Request and verify a new code.';render()});
})();</script>
<?php render_dashboard_end(); ?>
