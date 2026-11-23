```mermaid
erDiagram
    users ||--o{ access_cards : "assigned_by"
    devices ||--o{ access_cards : "device_id"
    employees ||--o{ access_cards : "employee_id"
    tenants ||--o{ access_cards : "tenant_id"
    tenants ||--o{ access_levels : "tenant_id"
    devices ||--o{ attendance_logs : "device_id"
    employees ||--o{ attendance_logs : "employee_id"
    tenants ||--o{ attendance_logs : "tenant_id"
    attendance_sync_runs ||--o{ attendance_raw_logs : "attendance_sync_run_id"
    devices ||--o{ attendance_raw_logs : "device_id"
    tenants ||--o{ attendance_raw_logs : "tenant_id"
    device_commands ||--o{ attendance_sync_runs : "device_command_id"
    devices ||--o{ attendance_sync_runs : "device_id"
    tenants ||--o{ attendance_sync_runs : "tenant_id"
    employees ||--o{ attendance_waivers : "employee_id"
    tenants ||--o{ attendance_waivers : "tenant_id"
    tenants ||--o{ audit_logs : "tenant_id"
    users ||--o{ audit_logs : "user_id"
    devices ||--o{ biometric_templates : "device_id"
    employees ||--o{ biometric_templates : "employee_id"
    tenants ||--o{ biometric_templates : "tenant_id"
    employees ||--o{ departments : "manager_id"
    tenants ||--o{ departments : "tenant_id"
    devices ||--o{ device_commands : "device_id"
    users ||--o{ device_commands : "requested_by"
    tenants ||--o{ device_commands : "tenant_id"
    device_groups ||--o{ device_group_members : "device_group_id"
    devices ||--o{ device_group_members : "device_id"
    tenants ||--o{ device_groups : "tenant_id"
    users ||--o{ device_roster_entries : "claimed_by"
    employees ||--o{ device_roster_entries : "claimed_employee_id"
    devices ||--o{ device_roster_entries : "device_id"
    tenants ||--o{ device_roster_entries : "tenant_id"
    devices ||--o{ device_status_logs : "device_id"
    tenants ||--o{ device_status_logs : "tenant_id"
    users ||--o{ devices : "adopted_by"
    tenants ||--o{ devices : "tenant_id"
    access_levels ||--o{ employee_access_levels : "access_level_id"
    employees ||--o{ employee_access_levels : "employee_id"
    devices ||--o{ employee_device_maps : "device_id"
    employees ||--o{ employee_device_maps : "employee_id"
    tenants ||--o{ employee_device_maps : "tenant_id"
    departments ||--o{ employees : "department_id"
    multi_card_groups ||--o{ employees : "multi_card_group_id"
    people ||--o{ employees : "person_id"
    tenants ||--o{ employees : "tenant_id"
    device_commands ||--o{ enrollment_sessions : "device_command_id"
    devices ||--o{ enrollment_sessions : "device_id"
    employees ||--o{ enrollment_sessions : "employee_id"
    users ||--o{ enrollment_sessions : "requested_by"
    tenants ||--o{ enrollment_sessions : "tenant_id"
    tenants ||--o{ holidays : "tenant_id"
    permissions ||--o{ model_has_permissions : "permission_id"
    roles ||--o{ model_has_roles : "role_id"
    tenants ||--o{ multi_card_groups : "tenant_id"
    tenants ||--o{ notifications : "tenant_id"
    users ||--o{ notifications : "user_id"
    tenants ||--o{ report_exports : "tenant_id"
    users ||..o{ report_exports : "user_id (no constraint)"
    permissions ||--o{ role_has_permissions : "permission_id"
    roles ||--o{ role_has_permissions : "role_id"
    tenants ||--o{ scheduled_jobs : "tenant_id"
    tenants ||--o{ settings : "tenant_id"
    tenants ||--o{ users : "tenant_id"
    employees ||..o{ users : "employee_id (no constraint)"
    tenants ||--o{ webhook_deliveries : "tenant_id"
    webhook_endpoints ||--o{ webhook_deliveries : "webhook_endpoint_id"
    tenants ||--o{ webhook_endpoints : "tenant_id"
    access_cards {
        bigint id PK
        bigint tenant_id FK
        bigint employee_id FK
        bigint device_id FK
        bigint card_number
        nvarchar status
        datetime assigned_at
        datetime revoked_at
        nvarchar revoke_reason
        bit is_suspect
        bigint assigned_by FK
        datetime created_at
        datetime updated_at
    }
    access_levels {
        bigint id PK
        bigint tenant_id FK
        nvarchar name
        nvarchar time_zone
        datetime created_at
        datetime updated_at
    }
    attendance_logs {
        bigint id PK
        bigint device_id FK
        nvarchar device_user_id
        bigint employee_id FK
        datetime punched_at
        tinyint status
        tinyint verify_method
        datetime created_at
        datetime updated_at
        bigint tenant_id FK
    }
    attendance_raw_logs {
        bigint id PK
        bigint tenant_id FK
        bigint device_id FK
        bigint attendance_sync_run_id FK
        nvarchar raw
        nvarchar device_user_id
        datetime punched_at
        tinyint verify_method
        tinyint status
        bit decoded
        bit imported
        nvarchar skip_reason
        datetime created_at
        datetime updated_at
    }
    attendance_sync_runs {
        bigint id PK
        bigint tenant_id FK
        bigint device_id FK
        bigint device_command_id FK
        nvarchar mode
        nvarchar status
        datetime started_at
        datetime finished_at
        int duration_ms
        int records_read
        int records_imported
        int records_duplicate
        int records_undecodable
        int records_unmatched
        datetime watermark_before
        datetime watermark_after
        nvarchar message
        nvarchar error
        datetime created_at
        datetime updated_at
        nvarchar progress_phase
        int progress_read
        int progress_total
        nvarchar progress_unit
        smallint progress_attempt
        datetime progress_updated_at
        date range_from
        date range_to
        int records_out_of_range
    }
    attendance_waivers {
        bigint id PK
        bigint employee_id FK
        date date
        nvarchar reason
        datetime created_at
        datetime updated_at
        bigint tenant_id FK
    }
    audit_logs {
        bigint id PK
        bigint tenant_id FK
        bigint user_id FK
        nvarchar action
        nvarchar auditable_type
        bigint auditable_id
        nvarchar changes
        nvarchar ip_address
        nvarchar user_agent
        datetime created_at
        datetime updated_at
    }
    biometric_templates {
        bigint id PK
        bigint tenant_id FK
        bigint employee_id FK
        bigint device_id FK
        tinyint finger_slot
        int template_size
        nvarchar template_format
        datetime enrolled_at
        datetime verified_at
        datetime created_at
        datetime updated_at
        nvarchar template
        nchar template_sha1
    }
    departments {
        bigint id PK
        bigint tenant_id FK
        nvarchar name
        nvarchar code
        bigint manager_id FK
        datetime created_at
        datetime updated_at
        datetime deleted_at
        bigint parent_id
    }
    device_commands {
        bigint id PK
        bigint tenant_id FK
        bigint device_id FK
        nvarchar command
        nvarchar payload
        nvarchar status
        tinyint attempts
        tinyint max_attempts
        datetime available_at
        datetime started_at
        datetime finished_at
        nvarchar message
        nvarchar error
        nvarchar raw_response
        bigint requested_by FK
        datetime created_at
        datetime updated_at
    }
    device_group_members {
        bigint id PK
        bigint device_group_id FK
        bigint device_id FK
        datetime created_at
        datetime updated_at
    }
    device_groups {
        bigint id PK
        bigint tenant_id FK
        nvarchar name
        nvarchar description
        datetime created_at
        datetime updated_at
        bit shared_pin_namespace
        nvarchar pin_namespace_evidence
    }
    device_roster_entries {
        bigint id PK
        bigint tenant_id FK
        bigint device_id FK
        int device_uid
        nvarchar device_user_id
        nvarchar device_name
        tinyint device_privilege
        bigint card_number
        nvarchar fingerprint_slots
        nvarchar claim_status
        bigint claimed_employee_id FK
        bigint claimed_by FK
        datetime claimed_at
        nvarchar ignore_reason
        datetime first_seen_at
        datetime last_seen_at
        datetime created_at
        datetime updated_at
    }
    device_status_logs {
        bigint id PK
        bigint tenant_id FK
        bigint device_id FK
        nvarchar status
        nvarchar previous_status
        int latency_ms
        nvarchar reason
        datetime observed_at
        datetime created_at
        datetime updated_at
    }
    devices {
        bigint id PK
        nvarchar name
        nvarchar ip_address
        int port
        nvarchar comm_key
        nvarchar location
        bit is_active
        datetime last_synced_at
        nvarchar last_sync_status
        nvarchar last_sync_error
        datetime created_at
        datetime updated_at
        nvarchar protocol
        nvarchar web_username
        nvarchar web_password
        bigint tenant_id FK
        nvarchar model
        nvarchar firmware_version
        nvarchar platform
        nvarchar serial_number
        nvarchar mac_address
        int user_capacity
        int log_capacity
        int fingerprint_capacity
        datetime info_read_at
        nvarchar timezone
        datetime last_seen_at
        nvarchar health_status
        int consecutive_failures
        int last_latency_ms
        nvarchar capabilities_cache
        datetime capabilities_probed_at
        datetime attendance_watermark
        bigint attendance_imported_total
        nvarchar adoption_status
        datetime roster_read_at
        datetime adopted_at
        bigint adopted_by FK
    }
    employee_access_levels {
        bigint id PK
        bigint employee_id FK
        bigint access_level_id FK
        datetime created_at
        datetime updated_at
    }
    employee_device_maps {
        bigint id PK
        bigint tenant_id FK
        bigint employee_id FK
        bigint device_id FK
        int device_uid
        nvarchar device_user_id
        nvarchar device_name
        tinyint device_privilege
        bigint card_number
        nvarchar fingerprint_slots
        datetime synced_at
        nvarchar reconciliation_status
        nvarchar reconciliation_detail
        datetime created_at
        datetime updated_at
        datetime valid_from
        datetime valid_to
        nvarchar closed_reason
        nvarchar template_digests
        datetime templates_read_at
    }
    employees {
        bigint id PK
        nvarchar name
        nvarchar employee_code
        nvarchar email
        nvarchar department
        bit is_active
        datetime created_at
        datetime updated_at
        nvarchar photo_path
        bigint card_number
        tinyint fingerprint_count
        datetime enrollment_synced_at
        bigint tenant_id FK
        bigint department_id FK
        nvarchar fingerprint_slots
        bigint person_id FK
        nvarchar last_name
        nvarchar gender
        nvarchar mobile_phone
        date hired_date
        date birthday
        nvarchar device_password
        nvarchar privilege
        nvarchar job_title
        nvarchar office
        nvarchar education
        nvarchar country
        nvarchar city
        nvarchar postal_code
        nvarchar home_phone
        nvarchar political_status
        nvarchar race
        nvarchar home_address
        nvarchar work_address
        bigint multi_card_group_id FK
        bit valid_period_enabled
        date access_valid_from
        date access_valid_to
        nvarchar external_id
        nvarchar employment_type
        date resigned_at
    }
    enrollment_sessions {
        bigint id PK
        bigint tenant_id FK
        bigint employee_id FK
        bigint device_id FK
        bigint device_command_id FK
        nvarchar type
        tinyint finger_slot
        nvarchar status
        datetime requested_at
        datetime sent_at
        datetime completed_at
        int timeout_seconds
        bigint captured_card_number
        smallint device_error_code
        nvarchar events
        nvarchar message
        bigint requested_by FK
        datetime created_at
        datetime updated_at
    }
    holidays {
        bigint id PK
        date date
        nvarchar name
        datetime created_at
        datetime updated_at
        bigint tenant_id FK
    }
    model_has_permissions {
        bigint permission_id FK
        nvarchar model_type
        bigint model_id
        bigint team_id
    }
    model_has_roles {
        bigint role_id FK
        nvarchar model_type
        bigint model_id
        bigint team_id
    }
    multi_card_groups {
        bigint id PK
        bigint tenant_id FK
        nvarchar name
        datetime created_at
        datetime updated_at
    }
    notifications {
        bigint id PK
        bigint tenant_id FK
        bigint user_id FK
        nvarchar type
        nvarchar level
        nvarchar title
        nvarchar body
        nvarchar action_url
        nvarchar data
        datetime read_at
        datetime created_at
        datetime updated_at
    }
    people {
        bigint id PK
        nvarchar hrms_person_id
        nvarchar full_name
        nvarchar status
        datetime created_at
        datetime updated_at
    }
    permissions {
        bigint id PK
        nvarchar name
        nvarchar guard_name
        datetime created_at
        datetime updated_at
    }
    personal_access_tokens {
        bigint id PK
        nvarchar tokenable_type
        bigint tokenable_id
        nvarchar name
        nvarchar token
        nvarchar abilities
        datetime last_used_at
        datetime expires_at
        datetime created_at
        datetime updated_at
    }
    report_exports {
        bigint id PK
        bigint tenant_id FK
        bigint user_id
        nvarchar kind
        nvarchar params
        nvarchar status
        int total
        int done
        nvarchar manifest
        nvarchar file_path
        nvarchar filename
        bigint file_size
        nvarchar message
        nvarchar error
        datetime finished_at
        datetime created_at
        datetime updated_at
    }
    role_has_permissions {
        bigint permission_id FK
        bigint role_id FK
    }
    roles {
        bigint id PK
        bigint team_id
        nvarchar name
        nvarchar guard_name
        datetime created_at
        datetime updated_at
    }
    scheduled_jobs {
        bigint id PK
        bigint tenant_id FK
        nvarchar name
        nvarchar action
        nvarchar schedule
        nvarchar device_ids
        bit is_enabled
        datetime last_run_at
        nvarchar last_result
        bigint created_by
        datetime created_at
        datetime updated_at
    }
    settings {
        bigint id PK
        bigint tenant_id FK
        nvarchar key
        nvarchar value
        datetime created_at
        datetime updated_at
    }
    tenants {
        bigint id PK
        nvarchar name
        nvarchar slug
        nvarchar timezone
        nvarchar logo_path
        nvarchar primary_color
        bit is_active
        datetime trial_ends_at
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    users {
        bigint id PK
        nvarchar name
        nvarchar email
        datetime email_verified_at
        nvarchar password
        nvarchar remember_token
        datetime created_at
        datetime updated_at
        bigint tenant_id FK
        bit is_super_admin
        bigint employee_id
        bit is_active
    }
    webhook_deliveries {
        bigint id PK
        bigint tenant_id FK
        bigint webhook_endpoint_id FK
        nvarchar event_id
        nvarchar event
        nvarchar payload
        nvarchar status
        smallint attempts
        smallint response_status
        nvarchar response_body
        nvarchar error
        datetime next_attempt_at
        datetime delivered_at
        datetime created_at
        datetime updated_at
    }
    webhook_endpoints {
        bigint id PK
        bigint tenant_id FK
        nvarchar url
        nvarchar description
        nvarchar secret
        nvarchar events
        bit is_active
        bigint punch_cursor
        int consecutive_failures
        datetime last_success_at
        datetime last_failure_at
        datetime disabled_at
        datetime created_at
        datetime updated_at
    }
```
