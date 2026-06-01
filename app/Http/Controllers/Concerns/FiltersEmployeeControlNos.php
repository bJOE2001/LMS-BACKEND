<?php

namespace App\Http\Controllers\Concerns;

trait FiltersEmployeeControlNos
{
    private const EMPLOYEE_CONTROL_NO_BINDING_LIMIT = 1000;

    private const EMPLOYEE_CONTROL_NO_LITERAL_CHUNK_SIZE = 1000;

    private function whereInEmployeeControlNos($query, array $controlNos, string $column = 'employee_control_no')
    {
        $controlNos = $this->normalizeEmployeeControlNoFilterValues($controlNos);

        if ($controlNos === []) {
            return $query->whereRaw('1 = 0');
        }

        if (count($controlNos) <= self::EMPLOYEE_CONTROL_NO_BINDING_LIMIT) {
            return $query->whereIn($column, $controlNos);
        }

        // SQL Server rejects statements with more than 2100 bound parameters.
        return $query->where(function ($nestedQuery) use ($column, $controlNos): void {
            foreach (array_chunk($controlNos, self::EMPLOYEE_CONTROL_NO_LITERAL_CHUNK_SIZE) as $index => $chunk) {
                $condition = $this->rawEmployeeControlNoInCondition($column, $chunk);

                if ($index === 0) {
                    $nestedQuery->whereRaw($condition);

                    continue;
                }

                $nestedQuery->orWhereRaw($condition);
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

    /**
     * @param  array<int, string>  $controlNos
     */
    private function rawEmployeeControlNoInCondition(string $column, array $controlNos): string
    {
        $quotedValues = implode(', ', array_map(
            fn (string $value): string => $this->quoteSqlStringLiteral($value),
            $controlNos
        ));

        return "{$column} IN ({$quotedValues})";
    }

    private function quoteSqlStringLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
