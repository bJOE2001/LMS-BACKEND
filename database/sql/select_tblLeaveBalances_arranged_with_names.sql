/*
tblLeaveBalances arranged query with denormalized name columns.
*/
SELECT TOP (1000)
    lb.id,
    lb.employee_id,
    lb.employee_name,
    lb.leave_type_id,
    lb.leave_type_name,
    lb.balance,
    lb.last_accrual_date,
    lb.[year],
    lb.created_at,
    lb.updated_at
FROM [LMS_DB].[dbo].[tblLeaveBalances] AS lb
ORDER BY lb.employee_name, lb.leave_type_name, lb.id;
