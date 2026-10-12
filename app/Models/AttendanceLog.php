<?php

namespace App\Models;

/**
 * Model representing punch records stored in tblAttendanceLogs.
 */
class AttendanceLog extends AttendanceRawLog
{
    protected $table = 'tblAttendanceLogs';
}
