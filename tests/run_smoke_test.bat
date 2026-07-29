@echo off
cd /d "%~dp0.."
"C:\Users\PC\AppData\Local\Programs\Python\Python312\python.exe" --version
C:\xampp\php\php.exe tests\smoke_test.php
pause
