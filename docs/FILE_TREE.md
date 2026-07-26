# File Tree — new SaaS architecture

Files added/changed by the multi-tenant, API-first rebuild (Phases 1–9).
The ZKTeco service layer and the legacy Inertia screens are reused as-is.

## Backend

```
app/
  Support/
    ApiResponse.php               # { success, message, data, meta, errors } envelope
    Tenancy.php                   # server-side acting-tenant context
  Models/
    Tenant.php  Department.php  Setting.php  Notification.php  AuditLog.php
    User.php (Sanctum+roles+tenant)  Employee/Device/AttendanceLog/Holiday/AttendanceWaiver (+tenant)
    Concerns/BelongsToTenant.php   Scopes/TenantScope.php
  Http/
    Controllers/
      Controller.php              # AuthorizesRequests/ValidatesRequests
      Api/
        Auth/AuthController.php
        EmployeeController.php  DepartmentController.php  DeviceController.php
        AttendanceController.php  WaiverController.php  HolidayController.php
        NotificationController.php  DashboardController.php  ReportController.php
        AuditLogController.php
    Middleware/ResolveTenant.php
    Requests/Api/{Auth,Employee,Department,Device,Holiday}/*.php   # Form Requests
    Resources/*Resource.php       # API Resources
  Policies/{Employee,Department,Device,Holiday}Policy.php
  Services/
    Realtime/{TopicBuilder,MqttPublisher}.php
    Notifications/NotificationService.php
    Attendance/{AttendanceSummaryService,DashboardService}.php
    Zkteco/*                      # reused, hardware-verified
  Observers/AuditObserver.php
  Events/DeviceSyncFailed.php     Listeners/NotifyOperatorsOfSyncFailure.php
  Providers/AppServiceProvider.php  # Gate::before super-admin + audit observers

bootstrap/app.php                 # api routes, middleware aliases/priority, JSON exception envelope
routes/api.php  routes/web.php    # api endpoints; web ships SPA shell at /app
config/permission.php (teams=on)  config/services.php (mqtt.ws_url)

database/
  migrations/2026_07_26_08*       # tenants, tenant_id, departments, settings, notifications, audit_logs
  seeders/{DatabaseSeeder,RolesAndPermissionsSeeder}.php

tests/
  Feature/Api/ApiFlowTest.php     # auth, tenant isolation, permissions, workflow
  TestCase.php                    # resets Tenancy per test
```

## Frontend (React SPA)

```
resources/views/spa.blade.php     # app shell (injects config, loads bundle)
resources/js/spa/
  main.jsx  App.jsx               # bootstrap + router (basename /app)
  shared/
    api.js                        # fetch client (token + envelope + 401)
    auth.jsx                      # AuthProvider / useAuth (perm gating)
    useDeviceClass.js             # mobile|desktop switch
    useMqtt.js                    # MQTT-over-WebSocket (fail-safe)
    useUnreadCount.js             # live notification badge
    ui.jsx                        # reusable Card/Button/StatTile/Badge/…
  layouts/
    AppLayout.jsx                 # picks chrome by device class
    DesktopLayout.jsx  MobileLayout.jsx  nav.jsx   # SEPARATE presentation
  pages/
    Login.jsx
    dashboard/{Dashboard,DashboardDesktop,DashboardMobile}.jsx
    employees/{Employees,EmployeesDesktop,EmployeesMobile,EmployeeFormModal}.jsx
    notifications/Notifications.jsx
```
