@echo off
REM Re-establish the phone → laptop USB tunnel for parent-app testing.
REM Run this once after plugging the USB cable in, or any time payments
REM start failing with "Failed to connect". No IP juggling required.

set ADB=C:\Users\91865\AppData\Local\Android\Sdk\platform-tools\adb.exe

echo Checking connected devices...
"%ADB%" devices

echo.
echo Setting up tunnel: phone:8080 -> laptop:80
"%ADB%" reverse tcp:8080 tcp:80

echo.
echo Active reverse tunnels:
"%ADB%" reverse --list

echo.
echo Done. Parent app can now reach http://localhost:8080/Grader/school/
pause
