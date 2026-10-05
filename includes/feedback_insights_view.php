<?php
declare(strict_types=1);

function render_feedback_insight_list(array $items, string $kind, bool $showOffice): void
{
    foreach ($items as $item) {
        $modalId = 'feedback-insight-' . (int)$item['id'];
        ?>
        <button type="button" class="notification-item insight-card <?= $kind === 'critical' ? 'unread' : '' ?>" data-modal-open="<?= e($modalId) ?>">
            <span class="notification-dot"></span><span><strong><?= e(date('M d, Y', strtotime($item['visit_date']))) ?></strong><span class="insight-comment"><?= e($item['comment']) ?></span><?php if ($showOffice): ?><small class="muted"><?= e($item['office_code']) ?> — <?= e($item['office_name']) ?></small><?php elseif ($item['assisted_by']): ?><small class="muted">Assisted by <?= e($item['assisted_by']) ?></small><?php endif; ?></span>
        </button>
        <div class="app-modal" id="<?= e($modalId) ?>" aria-hidden="true"><section class="app-modal-dialog insight-modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($modalId) ?>-title"><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button><h2 id="<?= e($modalId) ?>-title">Feedback #<?= (int)$item['id'] ?></h2><p class="muted"><?= e($item['office_code']) ?> — <?= e($item['office_name']) ?> · <?= e(date('M d, Y', strtotime($item['visit_date']))) ?></p><div class="insight-detail-grid"><div><small>Sentiment</small><strong><?= e(status_label($item['sentiment'])) ?> (<?= number_format((float)$item['sentiment_confidence'] * 100, 2) ?>%)</strong></div><div><small>Service</small><strong><?= e($item['service_received']) ?></strong></div><div><small>Client</small><strong><?= e($item['sex']) ?>, <?= (int)$item['age'] ?> · <?= e($item['client_type']) ?></strong></div><div><small>Assisted By</small><strong><?= e($item['assisted_by'] ?: 'Unassigned') ?></strong></div></div><div class="insight-ratings"><span>Timeliness <b><?= (int)$item['timeliness_rating'] ?>/4</b></span><span>Client Handling <b><?= (int)$item['client_handling_rating'] ?>/4</b></span><span>Quality <b><?= (int)$item['quality_rating'] ?>/4</b></span><span>Overall <b><?= (int)$item['overall_rating'] ?>/4</b></span></div><div class="formula-box"><strong>Client Comment</strong><p><?= e($item['comment']) ?></p></div><p class="muted">Weighted result: <strong><?= number_format((float)$item['final_score'], 2) ?>%</strong> · Source: <?= e(status_label($item['source'])) ?></p><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Close</button></div></section></div>
        <?php
    }
}
