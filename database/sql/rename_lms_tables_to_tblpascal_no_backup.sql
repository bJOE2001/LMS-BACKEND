/*
  Execute table rename without backup (use only when backup is handled externally).
*/

USE [LMS_DB];
GO

SET NOCOUNT ON;

DECLARE @Map TABLE (
    Seq int IDENTITY(1,1) PRIMARY KEY,
    OldName sysname NOT NULL,
    NewName sysname NOT NULL
);

INSERT INTO @Map (OldName, NewName)
VALUES
(N'departments', N'tblDepartments'),
(N'hr_accounts', N'tblHRAccounts'),
(N'department_admins', N'tblDepartmentAdmins'),
(N'employees', N'tblEmployees'),
(N'employee_accounts', N'tblEmployeeAccounts'),
(N'leave_types', N'tblLeaveTypes'),
(N'leave_balances', N'tblLeaveBalances'),
(N'admin_leave_balances', N'tblAdminLeaveBalances'),
(N'leave_applications', N'tblLeaveApplications'),
(N'leave_application_logs', N'tblLeaveApplicationLogs'),
(N'notifications', N'tblNotifications'),
(N'leave_balance_import_logs', N'tblLeaveBalanceImportLogs');

IF EXISTS (
    SELECT 1
    FROM @Map m
    WHERE OBJECT_ID(CONCAT(N'dbo.', m.OldName), N'U') IS NOT NULL
      AND OBJECT_ID(CONCAT(N'dbo.', m.NewName), N'U') IS NOT NULL
)
BEGIN
    SELECT m.OldName, m.NewName
    FROM @Map m
    WHERE OBJECT_ID(CONCAT(N'dbo.', m.OldName), N'U') IS NOT NULL
      AND OBJECT_ID(CONCAT(N'dbo.', m.NewName), N'U') IS NOT NULL;

    THROW 50001, 'Preflight failed: both old and new table names exist.', 1;
END;

SELECT
    o.name AS module_name,
    o.type_desc,
    LEFT(m.definition, 300) AS definition_snippet
FROM sys.sql_modules m
JOIN sys.objects o ON o.object_id = m.object_id
WHERE EXISTS (
    SELECT 1
    FROM @Map x
    WHERE m.definition LIKE CONCAT(N'%', x.OldName, N'%')
)
ORDER BY o.type_desc, o.name;

CREATE TABLE #CountsBefore (TableName sysname NOT NULL, Cnt bigint NOT NULL);
CREATE TABLE #CountsAfter  (TableName sysname NOT NULL, Cnt bigint NOT NULL);

DECLARE @sql nvarchar(max) = N'';
SELECT @sql = @sql + N'
IF OBJECT_ID(N''dbo.' + m.OldName + N''', N''U'') IS NOT NULL
    INSERT INTO #CountsBefore(TableName, Cnt)
    SELECT N''' + m.OldName + N''', COUNT_BIG(*) FROM dbo.' + QUOTENAME(m.OldName) + N';'
FROM @Map m;
EXEC sp_executesql @sql;

DECLARE @OldName sysname, @NewName sysname, @OldObj nvarchar(300), @NewObj nvarchar(300);
DECLARE rename_cursor CURSOR FAST_FORWARD FOR
    SELECT OldName, NewName FROM @Map ORDER BY Seq;

OPEN rename_cursor;
FETCH NEXT FROM rename_cursor INTO @OldName, @NewName;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @OldObj = CONCAT(N'dbo.', @OldName);
    SET @NewObj = CONCAT(N'dbo.', @NewName);

    IF OBJECT_ID(@OldObj, N'U') IS NOT NULL AND OBJECT_ID(@NewObj, N'U') IS NULL
    BEGIN
        PRINT CONCAT(N'Renaming ', @OldObj, N' -> ', @NewObj);
        EXEC sp_rename @objname = @OldObj, @newname = @NewName, @objtype = N'OBJECT';
    END
    ELSE IF OBJECT_ID(@OldObj, N'U') IS NULL AND OBJECT_ID(@NewObj, N'U') IS NOT NULL
    BEGIN
        PRINT CONCAT(N'Skipping (already renamed): ', @NewObj);
    END
    ELSE IF OBJECT_ID(@OldObj, N'U') IS NULL AND OBJECT_ID(@NewObj, N'U') IS NULL
    BEGIN
        THROW 50002, 'Rename failed: neither source nor target table exists for a mapping row.', 1;
    END
    ELSE
    BEGIN
        THROW 50003, 'Rename failed: both source and target exist unexpectedly.', 1;
    END;

    FETCH NEXT FROM rename_cursor INTO @OldName, @NewName;
END;

CLOSE rename_cursor;
DEALLOCATE rename_cursor;

SET @sql = N'';
SELECT @sql = @sql + N'
IF OBJECT_ID(N''dbo.' + m.NewName + N''', N''U'') IS NOT NULL
    INSERT INTO #CountsAfter(TableName, Cnt)
    SELECT N''' + m.NewName + N''', COUNT_BIG(*) FROM dbo.' + QUOTENAME(m.NewName) + N';'
FROM @Map m;
EXEC sp_executesql @sql;

SELECT
    m.OldName,
    m.NewName,
    b.Cnt AS BeforeCount,
    a.Cnt AS AfterCount,
    CASE WHEN b.Cnt = a.Cnt THEN 'OK' ELSE 'MISMATCH' END AS CountCheck
FROM @Map m
LEFT JOIN #CountsBefore b ON b.TableName = m.OldName
LEFT JOIN #CountsAfter a ON a.TableName = m.NewName
ORDER BY m.Seq;

SELECT
    fk.name AS FKName,
    OBJECT_SCHEMA_NAME(fk.parent_object_id) + N'.' + OBJECT_NAME(fk.parent_object_id) AS ParentTable,
    OBJECT_SCHEMA_NAME(fk.referenced_object_id) + N'.' + OBJECT_NAME(fk.referenced_object_id) AS ReferencedTable,
    fk.is_disabled,
    fk.is_not_trusted
FROM sys.foreign_keys fk
WHERE OBJECT_NAME(fk.parent_object_id) LIKE N'tbl%'
   OR OBJECT_NAME(fk.referenced_object_id) LIKE N'tbl%'
ORDER BY ParentTable, FKName;

SELECT
    t.name AS RenamedTable,
    p.rows AS ApproxRows
FROM sys.tables t
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
WHERE t.name IN (
N'tblDepartments', N'tblHRAccounts', N'tblDepartmentAdmins', N'tblEmployees',
N'tblEmployeeAccounts', N'tblLeaveTypes', N'tblLeaveBalances', N'tblAdminLeaveBalances',
N'tblLeaveApplications', N'tblLeaveApplicationLogs', N'tblNotifications', N'tblLeaveBalanceImportLogs'
)
ORDER BY t.name;

DROP TABLE #CountsBefore;
DROP TABLE #CountsAfter;
GO
