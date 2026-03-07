/*
  LMS_DB table rename script: snake_case -> tblPascalCase
  Keeps framework tables unchanged: migrations, personal_access_tokens
*/

USE [master];
GO

DECLARE @db sysname = N'LMS_DB';
DECLARE @backupDir nvarchar(4000) = CONVERT(nvarchar(4000), SERVERPROPERTY('InstanceDefaultBackupPath'));
IF @backupDir IS NULL OR LEN(@backupDir) = 0
    SET @backupDir = N'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\';
IF RIGHT(@backupDir, 1) <> N'\\'
    SET @backupDir += N'\\';

DECLARE @ts varchar(32) = REPLACE(REPLACE(REPLACE(CONVERT(varchar(19), GETDATE(), 120), '-', ''), ':', ''), ' ', '_');
DECLARE @backupFile nvarchar(4000) = @backupDir + @db + N'_before_tblpascal_' + @ts + N'.bak';

PRINT N'Backing up LMS_DB to: ' + @backupFile;
BEGIN TRY
    BACKUP DATABASE [LMS_DB]
      TO DISK = @backupFile
      WITH COPY_ONLY, INIT, COMPRESSION, CHECKSUM, STATS = 10;
    PRINT N'Backup completed.';
END TRY
BEGIN CATCH
    DECLARE @backupErr nvarchar(4000) = ERROR_MESSAGE();
    RAISERROR(N'Backup failed. Aborting rename. Error: %s', 16, 1, @backupErr);
    RETURN;
END CATCH;
GO

:r .\backend\database\sql\rename_lms_tables_to_tblpascal_no_backup.sql
