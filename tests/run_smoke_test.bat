@echo off
setlocal
cd /d "%~dp0.."
set "PHP_BIN=C:\xampp\php\php.exe"

for %%T in (
    tests\security_static_test.php
    tests\scoring_test.php
    tests\output_periods_test.php
    tests\dataset_import_test.php
    tests\survey_rate_limit_test.php
    tests\svm_bridge_test.php
    tests\smoke_test.php
) do (
    echo.
    echo Running %%T
    "%PHP_BIN%" "%%T" || exit /b 1
)

echo.
echo All PHP verification suites passed.
exit /b 0
