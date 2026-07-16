<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use PDO;

class PostgresConnection extends BasePostgresConnection
{
    /**
     * Bind values to their parameters in the given statement.
     *
     * @param  \PDOStatement  $statement
     * @param  array  $bindings
     */
    public function bindValues($statement, $bindings): void
    {
        // Get the SQL query to analyze context
        $sql = $statement->queryString ?? '';

        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;

            // Handle boolean columns - convert integer 1/0 to proper booleans
            // Only do this when we detect a boolean context in the SQL
            if ($value === 1 || $value === '1') {
                // Check if this is likely a boolean comparison
                if ($this->isLikelyBooleanContext($sql, $key, '1')) {
                    $statement->bindValue($param, true, PDO::PARAM_BOOL);

                    continue;
                }
            } elseif ($value === 0 || $value === '0') {
                // For integer 0, keep it as integer unless it's clearly a boolean context
                // Check if this is a comparison with a boolean column
                if ($this->isLikelyBooleanContext($sql, $key, '0')) {
                    $statement->bindValue($param, false, PDO::PARAM_BOOL);

                    continue;
                }
            } elseif ($value === true) {
                $statement->bindValue($param, true, PDO::PARAM_BOOL);

                continue;
            } elseif ($value === false) {
                $statement->bindValue($param, false, PDO::PARAM_BOOL);

                continue;
            } elseif (is_int($value)) {
                $statement->bindValue($param, $value, PDO::PARAM_INT);

                continue;
            } elseif (is_null($value)) {
                $statement->bindValue($param, null, PDO::PARAM_NULL);

                continue;
            }

            $statement->bindValue($param, $value);
        }
    }

    /**
     * Check if a binding position is likely a boolean context
     */
    private function isLikelyBooleanContext(string $sql, $key, string $value): bool
    {
        // Look for patterns like: column = ?, is_active, is_, has_, etc.
        $sqlLower = strtolower($sql);

        // Count placeholders before this position to find which ? this is
        if (is_int($key)) {
            $placeholderNum = $key;
            $count = 0;
            $pos = 0;
            while (($pos = strpos($sql, '?', $pos)) !== false) {
                if ($count === $placeholderNum) {
                    // Check context around this placeholder
                    $start = max(0, $pos - 50);
                    $context = strtolower(substr($sql, $start, 60));

                    // Check for boolean column names
                    if (preg_match('/(is_active|is_[a-z_]+|has_[a-z_]+|enabled|disabled|status)\s*[=<>]/', $context)) {
                        return true;
                    }
                    break;
                }
                $pos++;
                $count++;
            }
        }

        return false;
    }
}
