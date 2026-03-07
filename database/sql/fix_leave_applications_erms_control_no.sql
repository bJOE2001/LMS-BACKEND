DECLARE @fkName NVARCHAR(255);
DECLARE @sql NVARCHAR(MAX);
SELECT @fkName = fk.name
FROM sys.foreign_keys fk
INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
INNER JOIN sys.columns c ON c.object_id = fkc.parent_object_id AND c.column_id = fkc.parent_column_id
WHERE OBJECT_NAME(fk.parent_object_id) = 'tblLeaveApplications'
  AND c.name IN ('employee_id','erms_control_no');
IF @fkName IS NOT NULL
BEGIN
    SET @sql = N'ALTER TABLE tblLeaveApplications DROP CONSTRAINT ' + QUOTENAME(@fkName);
    EXEC sp_executesql @sql;
END;

IF EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('tblLeaveApplications')
      AND name = 'tblleaveapplications_employee_id_status_index'
)
BEGIN
    DROP INDEX tblleaveapplications_employee_id_status_index ON tblLeaveApplications;
END;

IF COL_LENGTH('tblLeaveApplications','employee_id') IS NOT NULL
   AND COL_LENGTH('tblLeaveApplications','erms_control_no') IS NULL
BEGIN
    EXEC sp_rename 'tblLeaveApplications.employee_id', 'erms_control_no', 'COLUMN';
END;
GO

IF COL_LENGTH('tblLeaveApplications','erms_control_no') IS NOT NULL
BEGIN
    EXEC sp_executesql N'UPDATE tblLeaveApplications SET erms_control_no = TRY_CONVERT(INT, erms_control_no) WHERE erms_control_no IS NOT NULL;';
    EXEC sp_executesql N'ALTER TABLE tblLeaveApplications ALTER COLUMN erms_control_no INT NULL;';
END;
GO

IF COL_LENGTH('tblLeaveApplications','erms_control_no') IS NOT NULL
   AND NOT EXISTS (
      SELECT 1 FROM sys.indexes
      WHERE object_id = OBJECT_ID('tblLeaveApplications')
        AND name = 'IX_tblLeaveApplications_erms_control_no_status'
   )
BEGIN
    CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
    ON tblLeaveApplications (erms_control_no, status);
END;
GO
