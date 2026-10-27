<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Artisan command to install or update the real-time event triggers on zkbiotime.
 * Supports leading-zero tolerance (e.g. device "22936" matching HRIS "022936").
 */
class InstallZkBioTriggersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bio:triggers';

    /**
     * Aliases for easy recall.
     *
     * @var list<string>
     */
    protected $aliases = [
        'bio:setup',
        'zkbio:install-triggers',
        'dtr:triggers',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install real-time SQL triggers on ZKBio Time with leading-zero normalization';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Configuring real-time SQL Server triggers on [zkbiotime] database...');

        try {
            // 1. Real-time fingerprint registration trigger on iclock_biodata
            $sqlBiodataTrigger = "
            CREATE OR ALTER TRIGGER trg_zkbio_biodata_sync_to_lms
            ON dbo.iclock_biodata
            AFTER INSERT, UPDATE
            AS
            BEGIN
                SET NOCOUNT ON;

                IF NOT EXISTS (SELECT 1 FROM inserted WHERE bio_type = 1 AND valid = 1)
                    RETURN;

                -- 1. Update existing enrollments in BIO_DB that are PENDING_ENROLLMENT or need device info
                -- Uses both exact string matching and numeric matching to tolerate leading zero differences (e.g. 22936 vs 022936)
                UPDATE e
                SET 
                    e.status = 'REGISTERED',
                    e.enrollment_device_sn = COALESCE(e.enrollment_device_sn, i.sn),
                    e.enrolled_at = COALESCE(e.enrolled_at, i.change_time, GETDATE()),
                    e.notes = 'Enrolled on ' + COALESCE(i.sn, 'terminal') + ' and synchronized via real-time trigger',
                    e.updated_at = GETDATE()
                FROM BIO_DB.dbo.tblBiometricEnrollments e
                JOIN zkbiotime.dbo.personnel_employee p 
                    ON e.employee_control_no = p.emp_code
                    OR (ISNUMERIC(e.employee_control_no) = 1 AND ISNUMERIC(p.emp_code) = 1 AND TRY_CAST(e.employee_control_no AS bigint) = TRY_CAST(p.emp_code AS bigint))
                JOIN inserted i ON i.employee_id = p.id
                WHERE i.bio_type = 1 AND i.valid = 1
                  AND (e.status <> 'REGISTERED' OR e.enrollment_device_sn IS NULL);

                -- 2. Insert new enrollment record for employees who do not exist yet in tblBiometricEnrollments
                -- Standardizes unpadded numbers to canonical 6-digit HRIS format (e.g. 22936 -> 022936)
                INSERT INTO BIO_DB.dbo.tblBiometricEnrollments (
                    employee_control_no,
                    employee_name,
                    status,
                    enrollment_device_sn,
                    enrolled_at,
                    enrolled_by_user_id,
                    synced_devices,
                    notes,
                    created_at,
                    updated_at
                )
                SELECT 
                    CASE 
                        WHEN ISNUMERIC(p.emp_code) = 1 AND LEN(LTRIM(RTRIM(p.emp_code))) <= 6 
                            THEN RIGHT('000000' + LTRIM(RTRIM(p.emp_code)), 6) 
                        ELSE LTRIM(RTRIM(p.emp_code)) 
                    END,
                    COALESCE(p.first_name, 'EMP ' + p.emp_code),
                    'REGISTERED',
                    i.sn,
                    COALESCE(i.change_time, GETDATE()),
                    NULL,
                    '[]',
                    'Auto-registered from physical biometric scan via real-time trigger',
                    GETDATE(),
                    GETDATE()
                FROM inserted i
                JOIN zkbiotime.dbo.personnel_employee p ON i.employee_id = p.id
                WHERE i.bio_type = 1 AND i.valid = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM BIO_DB.dbo.tblBiometricEnrollments e
                      WHERE e.employee_control_no = p.emp_code
                         OR (ISNUMERIC(e.employee_control_no) = 1 AND ISNUMERIC(p.emp_code) = 1 AND TRY_CAST(e.employee_control_no AS bigint) = TRY_CAST(p.emp_code AS bigint))
                  );

                -- 3. Log real-time registration event in tblBiometricTransferLogs
                INSERT INTO BIO_DB.dbo.tblBiometricTransferLogs (
                    employee_control_no,
                    employee_name,
                    action_type,
                    source_department_id,
                    source_office_name,
                    source_device_sn,
                    source_area_id,
                    target_department_id,
                    target_office_name,
                    target_device_sn,
                    target_area_id,
                    status,
                    remarks,
                    performed_by_user_id,
                    performed_by_name,
                    created_at,
                    updated_at
                )
                SELECT 
                    CASE 
                        WHEN ISNUMERIC(p.emp_code) = 1 AND LEN(LTRIM(RTRIM(p.emp_code))) <= 6 
                            THEN RIGHT('000000' + LTRIM(RTRIM(p.emp_code)), 6) 
                        ELSE LTRIM(RTRIM(p.emp_code)) 
                    END,
                    COALESCE(p.first_name, 'EMP ' + p.emp_code),
                    'REGISTRATION',
                    18, -- CHRMO
                    'CHRMO',
                    NULL,
                    3,  -- Area 3 (CHRMO)
                    18, -- CHRMO
                    'CHRMO',
                    i.sn,
                    3,
                    'SUCCESS',
                    'Real-time fingerprint enrollment captured on ' + COALESCE(i.sn, 'terminal'),
                    NULL,
                    'ZKBio Time Biometric Sensor Trigger',
                    GETDATE(),
                    GETDATE()
                FROM inserted i
                JOIN zkbiotime.dbo.personnel_employee p ON i.employee_id = p.id
                WHERE i.bio_type = 1 AND i.valid = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM BIO_DB.dbo.tblBiometricTransferLogs l
                      WHERE (l.employee_control_no = p.emp_code OR (ISNUMERIC(l.employee_control_no) = 1 AND ISNUMERIC(p.emp_code) = 1 AND TRY_CAST(l.employee_control_no AS bigint) = TRY_CAST(p.emp_code AS bigint)))
                        AND l.action_type = 'REGISTRATION'
                        AND l.target_device_sn = i.sn
                        AND DATEDIFF(minute, l.created_at, GETDATE()) < 5
                  );
            END;
            ";

            DB::connection('zkbio')->statement($sqlBiodataTrigger);
            $this->line('  <fg=green>+</> Trigger installed: <fg=yellow>trg_zkbio_biodata_sync_to_lms</> on zkbiotime.dbo.iclock_biodata');

            // 2. Real-time punch transaction trigger on iclock_transaction with leading-zero tolerance
            $sqlPunchTrigger = "
            CREATE OR ALTER TRIGGER trg_zkbio_sync_to_lms
            ON dbo.iclock_transaction
            AFTER INSERT
            AS
            BEGIN
                SET NOCOUNT ON;

                -- 1. Insert punch into tblAttendanceLogs (stores raw device pin and canonical padded control no)
                INSERT INTO BIO_DB.dbo.tblAttendanceLogs (
                    device_serial_number,
                    biometric_pin,
                    employee_control_no,
                    punch_time,
                    punch_state,
                    verify_type,
                    work_code,
                    sync_source,
                    raw_payload,
                    created_at,
                    updated_at
                )
                SELECT 
                    i.terminal_sn,
                    i.emp_code,
                    CASE 
                        WHEN ISNUMERIC(i.emp_code) = 1 AND LEN(LTRIM(RTRIM(i.emp_code))) <= 6 
                            THEN RIGHT('000000' + LTRIM(RTRIM(i.emp_code)), 6) 
                        ELSE LTRIM(RTRIM(i.emp_code)) 
                    END,
                    i.punch_time,
                    CASE WHEN ISNUMERIC(i.punch_state) = 1 THEN CAST(i.punch_state AS smallint) ELSE 0 END,
                    CASE WHEN ISNUMERIC(i.verify_type) = 1 THEN CAST(i.verify_type AS smallint) ELSE 15 END,
                    i.work_code,
                    'ZKBIO_TRIGGER',
                    CONCAT('ID=', i.id, ';SN=', i.terminal_sn),
                    GETDATE(),
                    GETDATE()
                FROM inserted i
                WHERE NOT EXISTS (
                    SELECT 1 FROM BIO_DB.dbo.tblAttendanceLogs r
                    WHERE r.biometric_pin = i.emp_code AND r.punch_time = i.punch_time
                );

                -- 2. AUTO-ENROLL NEW EMPLOYEES: If employee punches on a device and has no enrollment record in LMS,
                -- automatically create their enrollment as REGISTERED using canonical 6-digit control number
                INSERT INTO BIO_DB.dbo.tblBiometricEnrollments (
                    employee_control_no,
                    employee_name,
                    status,
                    enrollment_device_sn,
                    enrolled_at,
                    enrolled_by_user_id,
                    synced_devices,
                    notes,
                    created_at,
                    updated_at
                )
                SELECT 
                    CASE 
                        WHEN ISNUMERIC(i.emp_code) = 1 AND LEN(LTRIM(RTRIM(i.emp_code))) <= 6 
                            THEN RIGHT('000000' + LTRIM(RTRIM(i.emp_code)), 6) 
                        ELSE LTRIM(RTRIM(i.emp_code)) 
                    END,
                    COALESCE(p.first_name, 'EMP ' + i.emp_code),
                    'REGISTERED',
                    i.terminal_sn,
                    i.punch_time,
                    NULL,
                    CONCAT('[\"', i.terminal_sn, '\"]'),
                    CONCAT('Auto-enrolled from physical device punch on terminal ', i.terminal_sn),
                    GETDATE(),
                    GETDATE()
                FROM (
                    SELECT DISTINCT emp_code, terminal_sn, punch_time
                    FROM inserted
                    WHERE emp_code IS NOT NULL AND LTRIM(RTRIM(emp_code)) <> ''
                ) i
                LEFT JOIN zkbiotime.dbo.personnel_employee p 
                    ON p.emp_code = i.emp_code
                    OR (ISNUMERIC(p.emp_code) = 1 AND ISNUMERIC(i.emp_code) = 1 AND TRY_CAST(p.emp_code AS bigint) = TRY_CAST(i.emp_code AS bigint))
                WHERE NOT EXISTS (
                    SELECT 1 FROM BIO_DB.dbo.tblBiometricEnrollments e
                    WHERE e.employee_control_no = i.emp_code
                       OR (ISNUMERIC(e.employee_control_no) = 1 AND ISNUMERIC(i.emp_code) = 1 AND TRY_CAST(e.employee_control_no AS bigint) = TRY_CAST(i.emp_code AS bigint))
                );

                -- 3. AUTO-UPGRADE EXISTING ENROLLMENTS: Ensure status is REGISTERED and device is synced
                UPDATE e
                SET 
                    e.status = 'REGISTERED',
                    e.enrollment_device_sn = COALESCE(e.enrollment_device_sn, i.terminal_sn),
                    e.enrolled_at = COALESCE(e.enrolled_at, i.punch_time),
                    e.synced_devices = CASE 
                        WHEN e.synced_devices IS NULL OR e.synced_devices = '[]' OR e.synced_devices = '' 
                            THEN CONCAT('[\"', i.terminal_sn, '\"]')
                        WHEN CHARINDEX(i.terminal_sn, e.synced_devices) = 0 
                            THEN REPLACE(e.synced_devices, ']', CONCAT(',\"', i.terminal_sn, '\"]'))
                        ELSE e.synced_devices 
                    END,
                    e.notes = CONCAT('Auto-registered from physical punch on ', i.terminal_sn),
                    e.updated_at = GETDATE()
                FROM BIO_DB.dbo.tblBiometricEnrollments e
                JOIN inserted i 
                    ON e.employee_control_no = i.emp_code
                    OR (ISNUMERIC(e.employee_control_no) = 1 AND ISNUMERIC(i.emp_code) = 1 AND TRY_CAST(e.employee_control_no AS bigint) = TRY_CAST(i.emp_code AS bigint))
                WHERE e.status <> 'REGISTERED' OR e.synced_devices IS NULL OR e.synced_devices = '[]';

                -- 4. Log in tblBiometricTransferLogs as AUTO_ENROLL
                INSERT INTO BIO_DB.dbo.tblBiometricTransferLogs (
                    employee_control_no,
                    employee_name,
                    action_type,
                    target_device_sn,
                    status,
                    remarks,
                    performed_by_name,
                    created_at,
                    updated_at
                )
                SELECT 
                    CASE 
                        WHEN ISNUMERIC(i.emp_code) = 1 AND LEN(LTRIM(RTRIM(i.emp_code))) <= 6 
                            THEN RIGHT('000000' + LTRIM(RTRIM(i.emp_code)), 6) 
                        ELSE LTRIM(RTRIM(i.emp_code)) 
                    END,
                    COALESCE(p.first_name, 'EMP ' + i.emp_code),
                    'ASSIGN',
                    i.terminal_sn,
                    'SUCCESS',
                    CONCAT('Auto-enrolled from physical device punch on terminal ', i.terminal_sn),
                    'Physical Terminal Punch Trigger',
                    GETDATE(),
                    GETDATE()
                FROM (
                    SELECT DISTINCT emp_code, terminal_sn
                    FROM inserted
                    WHERE emp_code IS NOT NULL AND LTRIM(RTRIM(emp_code)) <> ''
                ) i
                LEFT JOIN zkbiotime.dbo.personnel_employee p 
                    ON p.emp_code = i.emp_code
                    OR (ISNUMERIC(p.emp_code) = 1 AND ISNUMERIC(i.emp_code) = 1 AND TRY_CAST(p.emp_code AS bigint) = TRY_CAST(i.emp_code AS bigint))
                WHERE NOT EXISTS (
                    SELECT 1 FROM BIO_DB.dbo.tblBiometricTransferLogs l
                    WHERE (l.employee_control_no = i.emp_code OR (ISNUMERIC(l.employee_control_no) = 1 AND ISNUMERIC(i.emp_code) = 1 AND TRY_CAST(l.employee_control_no AS bigint) = TRY_CAST(i.emp_code AS bigint)))
                );
            END;
            ";

            DB::connection('zkbio')->statement($sqlPunchTrigger);
            $this->line('  <fg=green>+</> Trigger installed: <fg=yellow>trg_zkbio_sync_to_lms</> on zkbiotime.dbo.iclock_transaction');

            $this->info('Real-time triggers are fully operational. Leading-zero tolerance enabled!');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to install triggers: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
