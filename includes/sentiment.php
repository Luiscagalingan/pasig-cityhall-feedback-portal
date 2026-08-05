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
    $text = preg_replace('/[^\pL\pN\s]+/u', ' ', mb_strtolower($comment)) ?? mb_strtolower($comment);
    $positive = [
        'mabilis','maayos','maganda','mabait','magalang','matulungin','malinis','malinaw','kumpleto','tama',
        'mahusay','maasikaso','maaliwalas','organisado','propesyonal','madali','convenient','helpful','excellent','good',
        'great','satisfied','satisfactory','responsive','approachable','accommodating','efficient','effective','accurate','reliable',
        'friendly','courteous','professional','prompt','smooth','organized','clean','clear','complete','quick',
        'fast','okay','ok','oks','goods','solid','ayos','galing','astig','panalo',
        'salamat','thank you','thanks','ambait','ambilis','napakabilis','napakabait','napakaayos','sulit','commendable'
    ];
    $negative = [
        'mabagal','matagal','pangit','masungit','magulo','marumi','malabo','kulang','mali','sirang',
        'mahirap','nakakainis','nakakadismaya','nakakagalit','nakakapagod','problema','reklamo','abala','pila','delay',
        'delayed','rude','poor','bad','terrible','awful','disappointed','dissatisfied','unhelpful','unprofessional',
        'unresponsive','inaccurate','incomplete','confusing','dirty','slow','late','hassle','inefficient','ignored',
        'dedma','suplado','suplada','badtrip','waley','palpak','sablay','ampaw','bulok','worst',
        'ambagal','napakabagal','napakatagal','pabalik balik','paulit ulit','walang sistema','walang kwenta','sayang oras','sungit','taray'
    ];
    $positivePhrases = [
        'walang hassle','no hassle','maayos ang serbisyo','mabilis ang serbisyo','maganda ang serbisyo','nasiyahan ako',
        'very satisfied','highly satisfied','well organized','very helpful','thank you po','mahusay na serbisyo',
        'magalang ang staff','mabait ang staff','madaling proseso','maikling pila','mabilis na proseso'
    ];
    $negativePhrases = [
        'hindi mabilis','hindi maayos','hindi maganda','hindi mabait','hindi magalang','hindi malinaw','hindi kumpleto',
        'hindi helpful','not helpful','not satisfied','not good','not clear','not organized','not responsive',
        'sobrang bagal','sobrang tagal','mahaba ang pila','matagal ang pila','poor service','bad service',
        'walang tumulong','walang sumasagot','sayang ang oras','pabalik balik','paulit ulit'
    ];
    $score = 0;
    foreach ($positivePhrases as $phrase) if (str_contains($text, $phrase)) $score += 2;
    foreach ($negativePhrases as $phrase) if (str_contains($text, $phrase)) $score -= 2;
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
