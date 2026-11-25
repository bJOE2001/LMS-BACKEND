<?php

namespace App\Traits;

trait FiltersEmployeeControlNos
{
    private const EMPLOYEE_CONTROL_NO_BINDING_LIMIT = 500;

    private const EMPLOYEE_CONTROL_NO_SQLSRV_JSON_CHUNK_SIZE = 5000;

    private function whereInEmployeeControlNos($query, array $controlNos, string $column = 'employee_control_no')
    {
        $controlNos = $this->normalizeEmployeeControlNoFilterValues($controlNos);

        if ($controlNos === []) {
            return $query->whereRaw('1 = 0');
        }

        if (count($controlNos) <= self::EMPLOYEE_CONTROL_NO_BINDING_LIMIT) {
            return $query->whereIn($column, $controlNos);
        }

        $connection = method_exists($query, 'toBase')
            ? $query->toBase()->getConnection()
            : $query->getConnection();

        if ($connection->getDriverName() === 'sqlsrv') {
            $wrappedColumn = $connection->getQueryGrammar()->wrap($column);
            $chunks = array_chunk($controlNos, self::EMPLOYEE_CONTROL_NO_SQLSRV_JSON_CHUNK_SIZE);

            if (count($chunks) === 1) {
                return $query->whereRaw(
                    "{$wrappedColumn} IN (SELECT [value] FROM OPENJSON(?))",
                    [json_encode($chunks[0], JSON_THROW_ON_ERROR)]
                );
            }

            return $query->where(function ($nestedQuery) use ($wrappedColumn, $chunks): void {
                foreach ($chunks as $index => $chunk) {
                    $binding = json_encode($chunk, JSON_THROW_ON_ERROR);
                    if ($index === 0) {
                        $nestedQuery->whereRaw("{$wrappedColumn} IN (SELECT [value] FROM OPENJSON(?))", [$binding]);

                        continue;
                    }

                    $nestedQuery->orWhereRaw("{$wrappedColumn} IN (SELECT [value] FROM OPENJSON(?))", [$binding]);
                }
            });
        }

        // Fallback chunking for non-sqlsrv drivers (e.g. SQLite in tests).
        return $query->where(function ($nestedQuery) use ($column, $controlNos): void {
            foreach (array_chunk($controlNos, self::EMPLOYEE_CONTROL_NO_BINDING_LIMIT) as $index => $chunk) {
                if ($index === 0) {
                    $nestedQuery->whereIn($column, $chunk);

                    continue;
                }

                $nestedQuery->orWhereIn($column, $chunk);
            }
        });
    }

    /**
     * @param  array<int, mixed>  $controlNos
     * @return array<int, string>
     */
    private function normalizeEmployeeControlNoFilterValues(array $controlNos): array
    {
        $values = [];

        foreach ($controlNos as $controlNo) {
            $value = trim((string) ($controlNo ?? ''));
            if ($value === '' || array_key_exists($value, $values)) {
                continue;
            }

            $values[$value] = $value;
        }

        return array_values($values);
    }
}
