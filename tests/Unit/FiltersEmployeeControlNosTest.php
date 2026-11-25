<?php

namespace Tests\Unit;

use App\Traits\FiltersEmployeeControlNos;
use Illuminate\Database\SqlServerConnection;
use PHPUnit\Framework\TestCase;

class FiltersEmployeeControlNosTest extends TestCase
{
    public function test_empty_control_numbers_produces_false_predicate(): void
    {
        $helper = $this->makeHelper();
        $query = $this->makeSqlServerConnection()->table('tblAttendanceLogs');

        $helper->apply($query, ['', '   ', null]);

        $this->assertSame('select * from [tblAttendanceLogs] where 1 = 0', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_small_control_number_list_uses_standard_where_in(): void
    {
        $helper = $this->makeHelper();
        $query = $this->makeSqlServerConnection()->table('tblAttendanceLogs');

        $values = array_map(static fn (int $i): string => (string) $i, range(1, 10));
        $helper->apply($query, $values);

        $this->assertStringContainsString('[employee_control_no] in (', $query->toSql());
        $this->assertStringNotContainsString('OPENJSON', $query->toSql());
        $this->assertCount(10, $query->getBindings());
    }

    public function test_sqlsrv_large_control_number_list_uses_single_openjson_binding(): void
    {
        $helper = $this->makeHelper();
        $query = $this->makeSqlServerConnection()->table('tblAttendanceLogs');

        $values = array_map(static fn (int $i): string => str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 2500));
        $helper->apply($query, $values);

        $this->assertSame(
            'select * from [tblAttendanceLogs] where [employee_control_no] IN (SELECT [value] FROM OPENJSON(?))',
            $query->toSql()
        );

        $bindings = $query->getBindings();
        $this->assertCount(1, $bindings);
        $decoded = json_decode((string) $bindings[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2500, $decoded);
        $this->assertSame('000001', $decoded[0]);
        $this->assertSame('002500', $decoded[2499]);
    }

    public function test_sqlsrv_over_5000_control_numbers_chunks_openjson_bindings_and_wraps_qualified_columns(): void
    {
        $helper = $this->makeHelper();
        $query = $this->makeSqlServerConnection()->table('tblAttendanceLogs');

        $values = array_map(static fn (int $i): string => (string) $i, range(1, 6000));
        $helper->apply($query, $values, 'tblAttendanceLogs.employee_control_no');

        $this->assertSame(
            'select * from [tblAttendanceLogs] where ([tblAttendanceLogs].[employee_control_no] IN (SELECT [value] FROM OPENJSON(?)) or [tblAttendanceLogs].[employee_control_no] IN (SELECT [value] FROM OPENJSON(?)))',
            $query->toSql()
        );

        $bindings = $query->getBindings();
        $this->assertCount(2, $bindings);
        $this->assertCount(5000, json_decode((string) $bindings[0], true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(1000, json_decode((string) $bindings[1], true, 512, JSON_THROW_ON_ERROR));
    }

    private function makeSqlServerConnection(): SqlServerConnection
    {
        return new SqlServerConnection(static fn (): null => null, 'TEST_DB', '', ['driver' => 'sqlsrv']);
    }

    private function makeHelper(): object
    {
        return new class
        {
            use FiltersEmployeeControlNos;

            /**
             * @param  array<int, mixed>  $controlNos
             */
            public function apply(object $query, array $controlNos, string $column = 'employee_control_no'): object
            {
                return $this->whereInEmployeeControlNos($query, $controlNos, $column);
            }
        };
    }
}
