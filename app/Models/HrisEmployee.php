<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HrisEmployee model — reads from the vwActive view on the HRIS server (pmis2003).
 *
 * This is a READ-ONLY model. No inserts, updates, or deletes should
 * ever be performed against the HRIS database from this application.
 */
class HrisEmployee extends Model
{
    /**
     * The database connection used by the model.
     */
    protected $connection = 'hr_sqlsrv';

    /**
     * The view associated with the model.
     */
    protected $table = 'vwActive';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'PMISNO';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Columns to select by default for performance.
     * Override at the query level with ->select() as needed.
     *
     * Actual vwActive columns:
     *   ControlNo, PMISNO, Surname, Firstname, Sex, Office, Status,
     *   ToDate, MIddlename, BirthDate, Pics, Grades, Steps, Designation,
     *   Name1, Name2, Name3, Name4, DesigCode, Charges, RateDay, TelNo,
     *   RateMon, Divisions, Sections, FromDate, Address, Renew
     */
    public const LISTING_COLUMNS = [
        'PMISNO',
        'Surname',
        'Firstname',
        'MIddlename',
        'Office',
        'Designation',
        'Status',
    ];
}
