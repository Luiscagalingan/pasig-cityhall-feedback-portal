@echo off
cd /d "%~dp0.."
C:\xampp\php\php.exe tests\svm_bridge_test.php
C:\xampp\php\php.exe tests\smoke_test.php
C:\xampp\php\php.exe tests\security_static_test.php
pause
