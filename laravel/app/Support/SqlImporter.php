<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes the legacy Meshline WiFi MySQL dumps.
 *
 * The dumps are written for the `mysql` CLI: they contain DELIMITER directives,
 * stored procedures/triggers, line and block comments and a mix of MySQL-only
 * and MariaDB-only idempotency clauses. Laravel's PDO connection does not know
 * about DELIMITER, so statements are split here and sent one at a time.
 */
final class SqlImporter
{
    /** Driver-level error codes that mean "already applied" rather than a real failure. */
    private const DUPLICATE_CODES = [1050, 1060, 1061, 1062, 1068, 1304, 1306];

    /** True when the active connection can actually run the MySQL dumps. */
    public static function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Import one dump file. Returns the number of statements executed. */
    public static function import(string $path): int
    {
        if (! is_file($path)) {
            throw new RuntimeException("SQL dump not found: {$path}");
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read SQL dump: {$path}");
        }

        return static::run($sql, basename($path));
    }

    /** Import several dumps in the order given. */
    public static function importMany(array $paths): int
    {
        $executed = 0;
        foreach ($paths as $path) {
            $executed += static::import((string) $path);
        }

        return $executed;
    }

    /** Split and execute raw SQL, tolerating already-applied DDL. */
    public static function run(string $sql, string $source = 'sql'): int
    {
        $executed = 0;

        foreach (static::split($sql) as $statement) {
            $statement = static::normalize($statement);
            if ($statement === '') {
                continue;
            }

            try {
                DB::unprepared($statement);
                $executed++;
            } catch (Throwable $e) {
                if (static::isDuplicateError($e)) {
                    Log::notice('[SqlImporter] Skipped already-applied statement in '.$source.': '.static::snippet($statement));
                    continue;
                }

                throw new RuntimeException(
                    'SQL import failed in '.$source.': '.$e->getMessage().
                    ' (statement: '.static::snippet($statement).')',
                    0,
                    $e
                );
            }
        }

        return $executed;
    }

    /**
     * Split a dump on the active delimiter (initially `;`), ignoring semicolons
     * inside strings, identifiers, comments and DELIMITER-defined blocks.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $delimiter = ';';
        $quote = null;
        $comment = null;
        $atLineStart = true;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // -- and # comments run to the end of the line.
            if ($comment === 'line') {
                if ($char === "\n") {
                    $comment = null;
                    $buffer .= "\n";
                    $atLineStart = true;
                }

                continue;
            }

            if ($comment === 'block') {
                if ($char === '*' && $next === '/') {
                    $comment = null;
                    $buffer .= ' ';
                    $i++;
                } else {
                    if ($char === "\n") {
                        $atLineStart = true;
                    }
                    $buffer .= $char;
                }

                continue;
            }

            // Quotes (including backslash and doubled-quote escapes).
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }

                continue;
            }

            // DELIMITER is a client directive, not SQL. It may change at any
            // line (e.g. $$ for a procedure, // for a trigger definition).
            if ($atLineStart && ($char === ' ' || $char === "\t" || $char === "\r")) {
                continue;
            }
            if ($atLineStart && preg_match('/\GDELIMITER[ \t]+([^\s]+)[ \t]*(?:\r?\n|$)/i', $sql, $matches, 0, $i)) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                    $buffer = '';
                }
                $delimiter = $matches[1];
                $i += strlen($matches[0]) - 1;
                $atLineStart = true;

                continue;
            }

            $atLineStart = ($char === "\n");

            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $comment = 'line';
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = 'block';
                $buffer .= ' ';
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                    $buffer = '';
                }
                $i += strlen($delimiter) - 1;

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    /**
     * Make MariaDB-only idempotency clauses acceptable to MySQL 8. Duplicate
     * errors are still handled by the caller, so stripping IF NOT EXISTS keeps
     * both dialects safe to re-run.
     */
    private static function normalize(string $statement): string
    {
        $statement = preg_replace_callback(
            '/\bADD\s+((?:COLUMN|INDEX|KEY|UNIQUE(?:\s+(?:INDEX|KEY))?)\s+)?IF\s+NOT\s+EXISTS\s+/i',
            static fn (array $m): string => 'ADD '.($m[1] ?? ''),
            $statement
        ) ?? $statement;

        $statement = preg_replace(
            '/\bCREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+/i',
            'CREATE $1INDEX ',
            $statement
        ) ?? $statement;

        return trim(preg_replace(
            '/\b(CREATE\s+(?:OR\s+REPLACE\s+)?(?:PROCEDURE|FUNCTION|TRIGGER))\s+IF\s+NOT\s+EXISTS\s+/i',
            '$1 ',
            $statement
        ) ?? $statement);
    }

    private static function isDuplicateError(Throwable $e): bool
    {
        $driverCode = null;
        if ($e instanceof QueryException && isset($e->errorInfo[1])) {
            $driverCode = (int) $e->errorInfo[1];
        }

        if ($driverCode !== null && in_array($driverCode, self::DUPLICATE_CODES, true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate column')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'duplicate table')
            || str_contains($message, 'already exists');
    }

    private static function snippet(string $statement): string
    {
        return substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 160);
    }
}
