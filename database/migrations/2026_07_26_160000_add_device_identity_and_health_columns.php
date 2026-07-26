<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the EXISTING devices table rather than creating a parallel
 * attendance_devices one. `devices` already carries tenancy, protocol fields, a
 * policy and working CRUD; duplicating it would split the source of truth.
 *
 * Adds three groups: identity read off the hardware, health/heartbeat state, and
 * the sync watermark that makes incremental import possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // --- Identity, read from the device itself (CMD_VERSION / CMD_DEVICE).
            // Previously fetched by getInfo() and thrown away.
            $table->string('model')->nullable()->after('protocol');           // e.g. F18/ID
            $table->string('firmware_version')->nullable()->after('model');   // e.g. Ver 6.60 Apr 26 2016
            $table->string('platform')->nullable()->after('firmware_version'); // e.g. ZMM210_TFT
            $table->string('serial_number')->nullable()->after('platform');
            $table->string('mac_address')->nullable()->after('serial_number');
            $table->unsignedInteger('user_capacity')->nullable()->after('mac_address');
            $table->unsignedInteger('log_capacity')->nullable()->after('user_capacity');
            $table->unsignedInteger('fingerprint_capacity')->nullable()->after('log_capacity');
            $table->timestamp('info_read_at')->nullable()->after('fingerprint_capacity');

            // Per-device override of the wall-clock zone it stamps records with.
            // Null falls back to config('attendance.device_timezone').
            $table->string('timezone')->nullable()->after('info_read_at');

            // --- Health / heartbeat.
            // last_synced_at already exists and means "last successful sync"; this is
            // the broader "last time the device answered anything at all".
            $table->timestamp('last_seen_at')->nullable()->after('timezone');
            $table->string('health_status')->default('unknown')->after('last_seen_at'); // online|offline|degraded|unknown
            $table->unsignedInteger('consecutive_failures')->default(0)->after('health_status');
            $table->unsignedInteger('last_latency_ms')->nullable()->after('consecutive_failures');

            // --- Capability flags, probed read-only and cached so list views and the
            // UI can hide unsupported actions without touching the network.
            $table->json('capabilities_cache')->nullable()->after('last_latency_ms');
            $table->timestamp('capabilities_probed_at')->nullable()->after('capabilities_cache');

            // --- Incremental attendance sync watermark.
            // The protocol cannot be asked for "punches since X" — ATTLOG_RRQ always
            // returns the whole table — so this is a CLIENT-side cutoff that saves the
            // database work and dedupe scan, not the transfer. See AttendanceService.
            $table->timestamp('attendance_watermark')->nullable()->after('capabilities_probed_at');
            $table->unsignedBigInteger('attendance_imported_total')->default(0)->after('attendance_watermark');

            $table->index(['tenant_id', 'health_status']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'health_status']);
            $table->dropColumn([
                'model', 'firmware_version', 'platform', 'serial_number', 'mac_address',
                'user_capacity', 'log_capacity', 'fingerprint_capacity', 'info_read_at',
                'timezone', 'last_seen_at', 'health_status', 'consecutive_failures',
                'last_latency_ms', 'capabilities_cache', 'capabilities_probed_at',
                'attendance_watermark', 'attendance_imported_total',
            ]);
        });
    }
};
