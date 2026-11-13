<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Office model — reads from the libOffice table on the Bioattendance server (BIOASD).
 *
 * This is a READ-ONLY model. No inserts, updates, or deletes should
 * ever be performed against the Bioattendance database from this application.
 */
class Office extends Model
{
    /**
     * The database connection used by the model.
     */
    protected $connection = 'bio_sqlsrv';

    /**
     * The table associated with the model.
     */
    protected $table = 'libOffice';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'Office';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Minimal column set for office listings.
     */
    public const OFFICE_COLUMNS = [
        'Office',
    ];
}
