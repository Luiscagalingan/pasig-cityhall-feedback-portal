<?php
declare(strict_types=1);
// CLI only: does not connect to the database or change stored sentiment labels.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/sentiment.php';

$comments = [
    'Mabilis ang serbisyo at mabait ang staff',
    'Sobrang tagal ng proseso at hindi maayos ang serbisyo',
    'Okay naman ang service pero medyo matagal',
];
$failed = false;
function bridge_check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL '), $name, PHP_EOL;
    if (!$ok) $failed = true;
}
$python = svm_python_runtime();
bridge_check('Python dependencies and production model', $python !== null);
if ($python === null) exit(1);
$singles = [];
foreach ($comments as $comment) {
    $result = predict_sentiment($comment);
    echo json_encode(['comment' => $comment] + $result, JSON_UNESCAPED_UNICODE), PHP_EOL;
    bridge_check('PHP prediction JSON (label, confidence, source=svm)',
        svm_valid_prediction($result) && $result['source'] === 'svm');
    $singles[] = $result;
}
$batch = predict_sentiments_batch($comments);
bridge_check('Batch predictions match single predictions', $batch === $singles);
$health = predict_sentiment('Mabilis at maayos ang serbisyo.');
echo 'System Health probe: ', $health['source'] === 'svm' ? 'SVM Model' : 'Fallback', PHP_EOL;
bridge_check('System Health uses live SVM source', $health['source'] === 'svm');
bridge_check('Reject invalid label/confidence',
    !svm_valid_prediction(['label' => 'unknown', 'confidence' => 0.5])
    && !svm_valid_prediction(['label' => 'neutral'])
    && !svm_valid_prediction(['label' => 'neutral', 'confidence' => 2])
    && !svm_valid_prediction(['label' => 'neutral', 'confidence' => '0.5']));
bridge_check('Invalid executable rejected',
    svm_resolve_python([__DIR__ . '/missing python/python.exe']) === null);

// Exercise a script path with spaces while loading the real production model.
$testDir = sys_get_temp_dir() . '/pasig svm test ' . bin2hex(random_bytes(6));
$script = $testDir . '/prediction with spaces.py';
try {
    if (!mkdir($testDir, 0700)) throw new RuntimeException('Cannot create test directory');
    $mlDir = json_encode(realpath(__DIR__ . '/../ml'), JSON_UNESCAPED_SLASHES);
    $predictPath = json_encode(realpath(SVM_PREDICT_SCRIPT), JSON_UNESCAPED_SLASHES);
    file_put_contents($script, "import sys, runpy\nsys.path.insert(0, $mlDir)\nrunpy.run_path($predictPath, run_name='__main__')\n");
    $result = svm_run_python($python, $script, $comments[2]);
    bridge_check('Script path with spaces loads production model', $result['ok']
        && svm_valid_prediction($result['data'] ?? null)
        && $result['data']['label'] === $singles[2]['label']
        && $result['data']['confidence'] === $singles[2]['confidence']);
} finally {
    if (is_file($script)) unlink($script);
    if (is_dir($testDir)) rmdir($testDir);
}
exit($failed ? 1 : 0);
