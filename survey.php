<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$office = fetch_office_by_code((string)($_GET['office'] ?? $_POST['office_code'] ?? ''));
if (!$office) { http_response_code(404); exit('The selected office survey is unavailable.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submissionLimit = survey_submission_limit();
    if (!$submissionLimit['allowed']) {
        if ((int)$submissionLimit['hourly_count'] >= SURVEY_SUBMISSION_HOURLY_LIMIT) {
            $errors[] = 'Naabot na ang maximum na tatlong submission sa loob ng isang oras. Subukan muli mamaya.';
        } else {
            $errors[] = 'Maghintay muna ng ' . max(1, (int)$submissionLimit['wait_seconds']) . ' segundo bago muling magsumite.';
        }
    }
    $visitDate = (string)($_POST['visit_date'] ?? '');
    $sex = trim((string)($_POST['sex'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $clientType = trim((string)($_POST['client_type'] ?? ''));
    $service = trim((string)($_POST['service_received'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    $ratings = [$_POST['timeliness'] ?? null, $_POST['client_handling'] ?? null, $_POST['quality'] ?? null, $_POST['overall'] ?? null];
    if (!$visitDate || strtotime($visitDate) === false || $visitDate > date('Y-m-d')) $errors[] = 'Enter a valid service date that is not in the future.';
    if (!in_array($sex, ['Female','Male','Prefer not to say'], true)) $errors[] = 'Select a valid sex option.';
    if ($age < 18) $errors[] = 'Hindi maaaring sumagot ang edad 17 pababa. Ang survey na ito ay para lamang sa edad 18 pataas.';
    elseif ($age > 120) $errors[] = 'Maglagay ng wastong edad mula 18 hanggang 120.';
    if (!in_array($clientType, ['Pasigueño','Non-Pasigueño','City Government Employee'], true)) $errors[] = 'Select a valid client classification.';
    if ($service === '') $errors[] = 'Enter the transaction or service received.';
    if (array_filter($ratings, fn($r) => !valid_rating($r))) $errors[] = 'Answer all four service ratings.';
    if (!isset($_POST['consent'])) $errors[] = 'Confirm that you agree to the privacy notice and feedback processing.';

    if (!$errors) {
        $fingerprint = feedback_fingerprint((int)$office['id'], ['visit_date'=>$visitDate,'sex'=>$sex,'age'=>$age,'client_type'=>$clientType,'service'=>$service,'ratings'=>$ratings,'comment'=>$comment]);
        $duplicate = db()->prepare('SELECT id FROM feedback WHERE office_id=? AND record_fingerprint=? AND is_void=0 LIMIT 1');
        $duplicate->execute([(int)$office['id'], $fingerprint]);
        if ($duplicate->fetchColumn()) {
            $errors[] = 'Naisumite na ang kaparehong feedback. Hindi na ito muling isinave upang maiwasan ang duplicate response.';
        }
    }

    if (!$errors) {
        $prediction = feedback_sentiment_prediction($ratings, $comment);
        $scores = compute_feedback_scores($ratings, $prediction['label']);
        $reviewStatus = prediction_review_status($prediction);
        $stmt = db()->prepare("INSERT INTO feedback
            (office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,
             sentiment,original_sentiment,sentiment_confidence,sentiment_source,review_status,model_version,average_rating,rating_percent,comment_score,final_score,
             source,record_fingerprint,consent_version,submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'public_survey',?,?,NOW())");
        $stmt->execute([(int)$office['id'],$visitDate,$sex,$age,$clientType,$service,(int)$ratings[0],(int)$ratings[1],(int)$ratings[2],(int)$ratings[3],$comment,
            $prediction['label'],$prediction['label'],$prediction['confidence'],$prediction['source'],$reviewStatus,model_version(),$scores['average_rating'],$scores['rating_percent'],$scores['comment_score'],$scores['final_score'],$fingerprint,PRIVACY_NOTICE_VERSION]);
        $feedbackId = (int)db()->lastInsertId();
        record_public_submission((int)$office['id'], $feedbackId);
        create_action_if_needed($feedbackId, (int)$office['id'], $prediction['label'], $scores['final_score'], $comment);
        if ($reviewStatus === 'needs_review') {
            notify_office_heads((int)$office['id'], 'review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' has low confidence or used the fallback classifier.', 'review.php');
            notify_admins('review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' requires human review.', 'review.php');
        }
        audit(null, 'public_feedback_submit', 'Office ' . $office['code'] . ', feedback #' . $feedbackId . ', source=' . $prediction['source']);
        set_flash('success', 'Thank you. Your feedback was submitted successfully.');
        redirect('');
    }
}
render_public_start('Feedback Survey');
?>
<nav class="public-nav"><a class="public-brand" href="<?= e(app_url()) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>City Government of Pasig</strong><small>Public Feedback Survey</small></div></a><div class="public-actions"><a class="btn secondary public-back" href="<?= e(app_url()) ?>"><span aria-hidden="true">←</span> Back to Office Selection</a></div></nav>
<div class="survey-shell"><div class="survey-banner"><span class="eyebrow"><?= e($office['code']) ?> PUBLIC FEEDBACK FORM</span><h1>YOUR FEEDBACK MATTERS TO US!</h1><p class="muted survey-intro">Mahalaga po ang inyong tapat na feedback upang mapabuti ang kalidad ng serbisyong aming inihahatid. Ang survey na ito ay magtatagal lamang ng kulang 3 minuto. Ang inyong mga sagot ay gagamitin nang maayos upang makatulong sa serbisyo ng pamahalaang lungsod.</p><p class="muted survey-intro"><em>We are committed to providing you with the best possible service, and your honest feedback is crucial to achieving that goal. Please take three minutes to complete this survey. Your responses will be kept confidential and used only to identify areas for service improvement.</em></p><div class="survey-progress" aria-label="Survey sections"><span>1. Tungkol sa pagbisita</span><span>2. Marka sa serbisyo</span><span>3. Komento o mungkahi</span></div></div>
<form method="post" class="survey-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="office_code" value="<?= e($office['code']) ?>">
<?php if ($errors): ?><div class="error-box"><strong>Please correct the following:</strong><br><?= implode('<br>', array_map('e',$errors)) ?></div><?php endif; ?>
<h2 class="section-title">1. Tungkol sa inyong pagbisita / About your visit</h2><div class="form-row three"><div class="form-group"><label>Petsa ng serbisyo / Date of service <span class="required">*</span></label><input type="date" name="visit_date" value="<?= e($_POST['visit_date'] ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div><div class="form-group"><label>Kasarian / Sex <span class="required">*</span></label><select name="sex" required><option value="">Pumili / Select</option><?php foreach(['Female','Male','Prefer not to say'] as $v): ?><option <?= (($_POST['sex'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Edad / Age (18 pataas) <span class="required">*</span></label><input type="number" min="18" max="120" name="age" value="<?= e($_POST['age'] ?? '') ?>" inputmode="numeric" required><div class="help">Ang survey ay para lamang sa adult respondent na 18–120 taong gulang.</div></div></div>
<div class="form-row"><div class="form-group"><label>Uri ng kliyente / Client type <span class="required">*</span></label><select name="client_type" required><option value="">Pumili / Select</option><?php foreach(['Pasigueño','Non-Pasigueño','City Government Employee'] as $v): ?><option <?= (($_POST['client_type'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Serbisyo o transaksyong natanggap / Service received <span class="required">*</span></label><input name="service_received" value="<?= e($_POST['service_received'] ?? '') ?>" placeholder="Halimbawa: Pagkuha ng permit" required></div></div>
<h2 class="section-title">2. Marka sa serbisyo / Rate the service</h2><p class="muted">Piliin ang sagot na pinakamalapit sa inyong karanasan.</p><div class="rating-guide" aria-label="Rating guide"><span><b>1</b>Lubos na hindi sang-ayon</span><span><b>2</b>Hindi sang-ayon</span><span><b>3</b>Sang-ayon</span><span><b>4</b>Lubos na sang-ayon</span></div><div class="rating-grid">
<?php $items=['timeliness'=>'Makatwiran ang tagal ng paghihintay.','client_handling'=>'Magalang at maayos akong tinulungan ng kawani.','quality'=>'Tama, kumpleto, at maayos ang serbisyong natanggap ko.','overall'=>'Nasiyahan ako sa kabuuang serbisyo.']; foreach($items as $name=>$label): ?><div class="rating-item"><strong class="rating-question"><?= e($label) ?> <span class="required">*</span></strong><div class="rating-options"><?php for($i=1;$i<=4;$i++): ?><label><input type="radio" name="<?= e($name) ?>" value="<?= $i ?>" <?= ((int)($_POST[$name] ?? 0)===$i)?'checked':'' ?> required><span><?= $i ?> · <?= ['','Hindi talaga','Hindi','Oo','Oo, lubos'][$i] ?></span></label><?php endfor; ?></div></div><?php endforeach; ?></div>
<h2 class="section-title">3. Komento o mungkahi / Comment or suggestion</h2><div class="form-group"><label>Ano ang masasabi ninyo sa serbisyong natanggap? <span class="muted">(Opsyonal)</span></label><textarea name="comment" maxlength="3000" placeholder="Halimbawa: Maayos ang serbisyo, ngunit sana ay mas maikli ang oras ng paghihintay. Maaari sa Filipino, English, o Taglish."><?= e($_POST['comment'] ?? '') ?></textarea><div class="help">Opsyonal ang comment. Kapag iniwan itong blangko, ang sentiment ay awtomatikong ibabatay sa inyong apat na service ratings. Huwag maglagay ng pangalan, numero ng telepono, o sensitibong personal na impormasyon.</div></div>
<div class="privacy-notice"><strong>Paunawa sa Privacy / Privacy Notice (Version <?= e(PRIVACY_NOTICE_VERSION) ?>)</strong><p>Gagamitin lamang ang inyong sagot para sukatin at mapabuti ang serbisyo ng lungsod. Huwag ilagay ang pangalan, numero ng telepono, medical diagnosis, o ibang sensitibong impormasyon. Mga awtorisadong kawani lamang ang maaaring makakita ng impormasyong sakop ng kanilang opisina at tungkulin. Ang feedback ay pananatilihin nang hanggang <?= FEEDBACK_RETENTION_MONTHS ?> buwan at pagkatapos ay aalisin ayon sa retention policy. Privacy contact: <?= e(PRIVACY_CONTACT) ?>. <a href="<?= e(app_url('privacy.php')) ?>"><strong>Basahin ang buong privacy policy.</strong></a></p></div>
<label class="checkbox-row"><input type="checkbox" name="consent" value="1" required><span>Nabasa ko ang privacy notice at kusang-loob kong ipinapasa ang feedback na ito para makatulong sa pagpapabuti ng serbisyo.</span></label><button class="btn survey-submit" type="submit">Ipasa ang Feedback / Submit</button></form></div>
<script>
document.querySelectorAll('.rating-item .rating-question').forEach((el, index) => {
  const labels = [
    'BILIS NG SERBISYO (TIMELINESS)',
    'PAKIKITUNGO SA KLIYENTE (CLIENT HANDLING)',
    'KALIDAD NG SERBISYO (QUALITY OF SERVICE)',
    'OVERALL SATISFACTION'
  ];
  const descriptions = [
    'Ang oras ng pagproseso ng inyong application/request ay akma o mas mabilis kaysa sa inaasahan.',
    'Magalang at propesyonal ang pakikitungo ng kawani na nagbigay ng serbisyo.',
    'Ang serbisyo o dokumentong natanggap ko ay tumpak, kumpleto, at walang kamalian.',
    'Lubos akong nasiyahan sa serbisyong aking natanggap.'
  ];
  const required = el.querySelector('.required');
  el.textContent = labels[index] + ' ';
  el.append(required);
  const small = document.createElement('small');
  small.textContent = descriptions[index];
  el.append(small);
});
document.querySelectorAll('.rating-options span').forEach((el, index) => {
  el.textContent = ['Strongly Disagree', 'Disagree', 'Agree', 'Strongly Agree'][index % 4];
});
const sections = document.querySelectorAll('.section-title');
if (sections[1]) sections[1].textContent = 'Lagyan ng tsek (✓) ang hanay na pinakaangkop sa inyong sagot / Please put a check mark (✓) on the column that best corresponds to your answer.';
if (sections[2]) sections[2].textContent = 'Mga komento o suhestiyon / Comments or suggestions';
const commentLabel = document.querySelector('textarea[name="comment"]')?.closest('.form-group')?.querySelector('label');
if (commentLabel) commentLabel.firstChild.textContent = 'Mga komento o suhestiyon kung paano pa mapapabuti ang aming mga serbisyo: ';
</script>
<?php render_public_end(); ?>
