<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves every stored timestamp by a fixed offset.
 *
 * Needed when the application's timezone changes after data already exists.
 * The columns are `timestamp without time zone`, so each row holds whatever
 * local time was in force when it was written - change the zone and the old
 * rows silently start reading wrong by exactly the difference.
 *
 * Dry run by default, and it prints what it will touch before touching
 * anything. Rewriting timestamps is not reversible by inspection - once the
 * numbers move there is nothing in the row saying they used to be different
 * - so this asks to be run twice on purpose (CLAUDE.md 35.20).
 *
 * Reversing is the same command with a negated offset.
 */
class ShiftStoredTimestampsCommand extends Command
{
    protected $signature = 'documents:shift-timestamps
                            {--hours= : Offset in hours, e.g. 8 or -8}
                            {--apply : Actually write the change. Without this nothing is modified}';

    protected $description = 'Shift every stored timestamp after an application timezone change';

    /**
     * Table => the datetime columns it owns.
     *
     * Listed explicitly rather than discovered from the schema, so a column
     * that must not move - or a new table nobody thought about - cannot be
     * swept along silently.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'documents' => ['source_last_modified_at', 'last_analyzed_at', 'missing_since', 'created_at', 'updated_at', 'deleted_at'],
        'document_versions' => ['source_last_modified_at', 'detected_at', 'analyzed_at', 'created_at', 'updated_at'],
        'document_analyses' => ['started_at', 'completed_at', 'created_at', 'updated_at'],
        'document_sections' => ['created_at', 'updated_at'],
        'document_issues' => ['created_at', 'updated_at'],
        'language_results' => ['created_at', 'updated_at'],
        'document_sources' => ['last_scan_at', 'last_successful_scan_at', 'created_at', 'updated_at', 'deleted_at'],
        'scan_logs' => ['started_at', 'completed_at', 'created_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'settings' => ['created_at', 'updated_at'],
        'users' => ['last_login_at', 'email_verified_at', 'created_at', 'updated_at'],
    ];

    public function handle(AuditLogger $auditLogger): int
    {
        $hours = $this->option('hours');

        if ($hours === null || ! is_numeric($hours)) {
            $this->error('Specify the offset, for example: --hours=8');

            return self::FAILURE;
        }

        $hours = (float) $hours;

        if ($hours === 0.0) {
            $this->error('An offset of zero would do nothing.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->line('');
        $this->line(sprintf(
            '  Shifting stored timestamps by %+g hour(s)  [%s]',
            $hours,
            $apply ? 'APPLYING' : 'dry run - nothing will be written',
        ));
        $this->line(sprintf('  Application timezone is now %s', config('app.timezone')));
        $this->line('');

        $rows = [];
        $touched = 0;

        foreach (self::COLUMNS as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $affected = $this->countAffected($table, $columns);

            if ($affected === 0) {
                continue;
            }

            $sample = $this->sample($table, $columns, $hours);
            $rows[] = [$table, $affected, $sample];
            $touched += $affected;

            if ($apply) {
                $this->shift($table, $columns, $hours);
            }
        }

        if ($rows === []) {
            $this->info('  Nothing to shift.');

            return self::SUCCESS;
        }

        $this->table(['Table', 'Rows', 'Example (before → after)'], $rows);
        $this->line('');

        if (! $apply) {
            $this->warn(sprintf('  Dry run. %d row(s) would change.', $touched));
            $this->line(sprintf(
                '  Run it for real with:  php artisan documents:shift-timestamps --hours=%g --apply',
                $hours,
            ));

            return self::SUCCESS;
        }

        // Recorded so the trail shows why every timestamp before this point
        // moved. Without it the shift is invisible after the fact.
        $auditLogger->log(
            'system.timestamps_shifted',
            newValues: [
                'hours' => $hours,
                'rows' => $touched,
                'timezone' => config('app.timezone'),
            ],
        );

        $this->info(sprintf('  Done. %d row(s) shifted.', $touched));
        $this->line(sprintf(
            '  To reverse:  php artisan documents:shift-timestamps --hours=%g --apply',
            -$hours,
        ));

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    /** @param array<int, string> $columns */
    private function countAffected(string $table, array $columns): int
    {
        $query = DB::table($table);
        $present = $this->presentColumns($table, $columns);

        if ($present === []) {
            return 0;
        }

        $query->where(function ($q) use ($present) {
            foreach ($present as $column) {
                $q->orWhereNotNull($column);
            }
        });

        return $query->count();
    }

    /** @param array<int, string> $columns */
    private function sample(string $table, array $columns, float $hours): string
    {
        foreach ($this->presentColumns($table, $columns) as $column) {
            $value = DB::table($table)->whereNotNull($column)->value($column);

            if ($value !== null) {
                $before = Carbon::parse($value);

                return sprintf(
                    '%s: %s → %s',
                    $column,
                    $before->format('Y-m-d H:i'),
                    $before->copy()->addMinutes((int) round($hours * 60))->format('Y-m-d H:i'),
                );
            }
        }

        return '—';
    }

    /** @param array<int, string> $columns */
    private function shift(string $table, array $columns, float $hours): void
    {
        $minutes = (int) round($hours * 60);

        foreach ($this->presentColumns($table, $columns) as $column) {
            // Applied in SQL so a large table does not have to be read into
            // PHP row by row, and so the whole column moves atomically.
            DB::statement(sprintf(
                'UPDATE %s SET %s = %s + (? * interval \'1 minute\') WHERE %s IS NOT NULL',
                $table,
                $column,
                $column,
                $column,
            ), [$minutes]);
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function presentColumns(string $table, array $columns): array
    {
        $schema = DB::getSchemaBuilder();

        return array_values(array_filter(
            $columns,
            static fn (string $column) => $schema->hasColumn($table, $column),
        ));
    }
}
