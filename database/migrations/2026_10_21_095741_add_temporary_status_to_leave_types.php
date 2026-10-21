<?php

use App\Models\LeaveType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $leaveTypes = LeaveType::all();

        foreach ($leaveTypes as $type) {
            $allowed = $type->allowed_status;
            if (is_array($allowed) && ! in_array(LeaveType::EMPLOYMENT_STATUS_TEMPORARY, $allowed, true)) {
                $allowed[] = LeaveType::EMPLOYMENT_STATUS_TEMPORARY;
                $type->allowed_status = $allowed;
                $type->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $leaveTypes = LeaveType::all();

        foreach ($leaveTypes as $type) {
            $allowed = $type->allowed_status;
            if (is_array($allowed)) {
                $type->allowed_status = array_values(array_filter(
                    $allowed,
                    fn ($status) => $status !== LeaveType::EMPLOYMENT_STATUS_TEMPORARY
                ));
                $type->save();
            }
        }
    }
};
