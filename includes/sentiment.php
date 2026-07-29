<?php
declare(strict_types=1);

function predict_sentiment(string $comment): array
{
    $comment = trim($comment);
    if ($comment === '') {
        return ['label' => 'neutral', 'confidence' => 0.0, 'source' => 'empty'];
    }

    if (is_file(SVM_PREDICT_SCRIPT) && function_exists('proc_open')) {
        $command = escapeshellcmd(PYTHON_BIN) . ' ' . escapeshellarg(SVM_PREDICT_SCRIPT);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, dirname(SVM_PREDICT_SCRIPT));
        if (is_resource($process)) {
            fwrite($pipes[0], $comment);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $data = json_decode((string)$output, true);
            if ($code === 0 && is_array($data) && in_array($data['label'] ?? '', ['positive','neutral','negative'], true)) {
                return [
                    'label' => $data['label'],
                    'confidence' => round((float)($data['confidence'] ?? 0), 4),
                    'source' => 'svm',
                ];
            }
            error_log('SVM prediction failed: ' . $error);
        }
    }

    // Operational fallback only, so survey submission still works when Python is not installed.
    // Install/train the included SVM model to make source='svm'.
    $text = mb_strtolower($comment);
    $positive = ['mabilis','maayos','maganda','mabait','helpful','excellent','good','great','salamat','accommodating','satisfied','malinis','oks','goods','solid','angas','ambait','ambilis','smooth','responsive','approachable','walang hassle','no hassle'];
    $negative = ['mabagal','matagal','pangit','masungit','rude','poor','bad','hindi malinaw','kulang','problema','delay','disappointed','ambagal','badtrip','waley','dedma','hassle','nakakainis','magulo','pabalik balik'];
    $score = 0;
    foreach ($positive as $word) if (str_contains($text, $word)) $score++;
    foreach ($negative as $word) if (str_contains($text, $word)) $score--;
    $label = $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral');
    return ['label' => $label, 'confidence' => min(0.75, 0.45 + abs($score) * 0.08), 'source' => 'fallback'];
}

function predict_sentiments_batch(array $comments): array
{
    if (!$comments) return [];
    $script = __DIR__ . '/../ml/predict_batch.py';
    if (is_file($script) && function_exists('proc_open')) {
        $command = escapeshellcmd(PYTHON_BIN) . ' ' . escapeshellarg($script);
        $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $process = @proc_open($command, $descriptors, $pipes, dirname($script));
        if (is_resource($process)) {
            fwrite($pipes[0], json_encode(array_values($comments), JSON_UNESCAPED_UNICODE));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process);
            $data = json_decode((string)$output, true);
            if ($code === 0 && is_array($data) && count($data) === count($comments)) {
                return array_map(static fn($item) => [
                    'label' => in_array($item['label'] ?? '', ['positive','neutral','negative'], true) ? $item['label'] : 'neutral',
                    'confidence' => round((float)($item['confidence'] ?? 0), 4),
                    'source' => 'svm',
                ], $data);
            }
            error_log('Batch SVM prediction failed: ' . $error);
        }
    }
    return array_map('predict_sentiment', $comments);
}
