@echo off
cd /d %~dp0
echo Starting Payroll Attendance app...
echo Access it at http://192.168.1.180:8010 from any computer on this network.
echo Press Ctrl+C to stop the server.
php artisan serve --host=0.0.0.0 --port=8010
