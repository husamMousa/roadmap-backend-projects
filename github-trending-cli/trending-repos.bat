@echo off
rem Lets you run "trending-repos ..." on Windows instead of "php trending-repos.php ...".
rem %~dp0 is the folder this file lives in; %* passes every argument along.
php "%~dp0trending-repos.php" %*
