@echo off
cd /d C:\Users\sanan\prime-healers-os

echo ================================
echo Prime Healers OS - Push to GitHub UAT
echo ================================

git branch --show-current
git status

echo.
set /p msg="Enter commit message: "

git add .

git diff --cached --quiet
if %errorlevel%==0 (
    echo.
    echo No staged changes found. Nothing to commit.
    echo If you expected changes, they may not be saved.
    pause
    exit /b 1
)

git commit -m "%msg%"
if errorlevel 1 (
    echo Commit failed.
    pause
    exit /b 1
)

git push origin uat
if errorlevel 1 (
    echo Push failed.
    pause
    exit /b 1
)

echo.
echo Latest local commit:
git log --oneline -1

echo.
echo GitHub UAT updated successfully.
pause
