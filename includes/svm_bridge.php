<?php
declare(strict_types=1);

// This directory is denied by storage/.htaccess. Never send diagnostics to HTTP.
function svm_log_failure(string $stage, array $details): void
{
    $entry = json_encode(['time' => gmdate('c'), 'stage' => $stage] + $details,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!@error_log($entry . PHP_EOL, 3, __DIR__ . '/../storage/svm_bridge.log')) {
        // No paths or user input in the server-log fallback.
        @error_log('SVM bridge failure; protected diagnostic log unavailable.');
    }
}

function svm_valid_prediction($data): bool
{
    return is_array($data)
        && in_array($data['label'] ?? null, ['positive', 'neutral', 'negative'], true)
        && isset($data['confidence'])
        && (is_int($data['confidence']) || is_float($data['confidence']))
        && is_finite((float)$data['confidence'])
        && $data['confidence'] >= 0 && $data['confidence'] <= 1;
}

function svm_run_python(string $python, string $script, string $input, float $timeout = 30.0): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'reason' => 'proc_open unavailable'];
    }
    if (!is_file($script)) {
        return ['ok' => false, 'reason' => 'Prediction script missing'];
    }
    // File-backed streams avoid stdout/stderr pipe deadlocks on Windows.
    // tmpfile creates temporary files which are removed when closed.
    $streams = [];
    $process = null;
    try {
        for ($i = 0; $i < 3; $i++) {
            $streams[$i] = @tmpfile();
            if ($streams[$i] === false) {
                throw new RuntimeException('Cannot allocate process streams');
            }
        }
        if (fwrite($streams[0], $input) !== strlen($input)) {
            throw new RuntimeException('Cannot write process input');
        }
        rewind($streams[0]);
        // Array arguments bypass cmd.exe/shell parsing, including paths with spaces.
        $process = @proc_open([$python, '-B', '-X', 'utf8', $script], $streams, $pipes,
            dirname($script), null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Python process');
        }
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Python prediction timed out');
            }
            usleep(20000);
        } while (true);
        $exit = $status['exitcode'];
        proc_close($process);
        $process = null;
        rewind($streams[1]);
        rewind($streams[2]);
        $output = stream_get_contents($streams[1]);
        $stderr = stream_get_contents($streams[2], 4096);
        $data = json_decode((string)$output, true);
        return ['ok' => $exit === 0 && json_last_error() === JSON_ERROR_NONE,
            'reason' => $exit !== 0 ? 'Python exited unsuccessfully' : 'Invalid JSON output',
            'exit_code' => $exit, 'stderr' => $stderr, 'data' => $data];
    } catch (Throwable $error) {
        return ['ok' => false, 'reason' => $error->getMessage()];
    } finally {
        if (is_resource($process)) proc_close($process);
        foreach ($streams as $stream) if (is_resource($stream)) fclose($stream);
    }
}

function svm_resolve_python(array $candidates): ?string
{
    foreach ($candidates as $python) {
        // Absolute/relative paths must exist; bare command names resolve via PATH.
        if ((str_contains($python, '/') || str_contains($python, '\\')) && !is_file($python)) {
            svm_log_failure('candidate', ['python' => $python, 'reason' => 'Executable missing']);
            continue;
        }
        $result = svm_run_python($python, SVM_PREDICT_SCRIPT, 'Mabilis at maayos ang serbisyo.');
        if ($result['ok'] && svm_valid_prediction($result['data'] ?? null)) return $python;
        if ($result['ok']) $result['reason'] = 'Invalid prediction response';
        unset($result['data']);
        svm_log_failure('candidate', ['python' => $python] + $result);
    }
    return null;
}

function svm_python_runtime(): ?string
{
    static $checked = false;
    static $python = null;
    if (!$checked) {
        $python = svm_resolve_python(SVM_PYTHON_CANDIDATES);
        $checked = true;
    }
    return $python;
}

function svm_predict_json(string $script, string $input, ?int $batchCount = null): ?array
{
    $python = svm_python_runtime();
    if ($python === null) return null;
    $result = svm_run_python($python, $script, $input);
    $data = $result['data'] ?? null;
    $valid = $result['ok'] && ($batchCount === null ? svm_valid_prediction($data)
        : is_array($data) && array_keys($data) === range(0, $batchCount - 1)
            && count($data) === $batchCount && count(array_filter($data, 'svm_valid_prediction')) === $batchCount);
    if ($valid) return $data;
    if ($result['ok']) $result['reason'] = 'Invalid prediction response';
    unset($result['data']);
    svm_log_failure('prediction', ['python' => $python, 'script' => $script] + $result);
    return null;
}
