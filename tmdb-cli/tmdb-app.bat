@echo off
rem Lets you run "tmdb-app ..." on Windows instead of "php tmdb-app.php ...".
rem %~dp0 is the folder this file lives in; %* passes every argument along.
php "%~dp0tmdb-app.php" %*
