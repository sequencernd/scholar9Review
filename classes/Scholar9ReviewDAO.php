<?php

namespace APP\plugins\generic\scholar9Review;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Scholar9ReviewDAO
{
    private const TABLE = 'scholar9_reviews';

    // ── Schema install (idempotent) ─────────────────────────────────────────

    public static function install(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                if (!Schema::hasColumn(self::TABLE, 'reviewer_id')) {
                    $table->string('reviewer_id', 255)->nullable()->after('score');
                }
                if (!Schema::hasColumn(self::TABLE, 'reviewers_cache')) {
                    $table->longText('reviewers_cache')->nullable()->after('reviewer_email');
                }
                if (!Schema::hasColumn(self::TABLE, 'full_review')) {
                    $table->boolean('full_review')->default(false)->after('open_review');
                }
            });
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_id')->index();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('review_round_id')->default(0);
            $table->string('s9_paper_id', 255)->nullable()->index();
            $table->string('s9_review_url', 500)->nullable();
            $table->string('status', 60)->default('pending')->index();
            $table->string('recommendation', 60)->nullable();
            $table->decimal('score', 3, 1)->nullable();
            $table->string('reviewer_id', 255)->nullable();
            $table->string('reviewer_name', 255)->nullable();
            $table->string('reviewer_email', 255)->nullable();
            $table->longText('reviewers_cache')->nullable();
            $table->longText('reviewer_comment')->nullable();
            $table->longText('ai_summary')->nullable();
            $table->longText('messages_cache')->nullable();
            $table->longText('ratings_cache')->nullable();
            $table->boolean('open_review')->default(false);
            $table->boolean('full_review')->default(false);
            $table->string('webhook_secret', 255)->nullable();
            $table->string('s9_webhook_id', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['journal_id', 'submission_id', 'review_round_id'], 'uq_submission_round');
        });
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    public function getBySubmission(int $journalId, int $submissionId, int $reviewRoundId = 0): ?array
    {
        $sql = 'SELECT * FROM ' . self::TABLE
             . ' WHERE journal_id = ? AND submission_id = ? AND review_round_id = ? LIMIT 1';

        $rows = DB::select($sql, [$journalId, $submissionId, $reviewRoundId]);

        return isset($rows[0]) ? $this->decodeJson((array) $rows[0]) : null;
    }

    public function getByS9PaperId(string $s9PaperId, ?int $journalId = null): ?array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE s9_paper_id = ?';
        $bindings = [$s9PaperId];
        if ($journalId !== null) {
            $sql .= ' AND journal_id = ?';
            $bindings[] = $journalId;
        }
        $rows = DB::select($sql . ' LIMIT 1', $bindings);

        return isset($rows[0]) ? $this->decodeJson((array) $rows[0]) : null;
    }

    public function getAllBySubmission(int $journalId, int $submissionId): array
    {
        $sql  = 'SELECT * FROM ' . self::TABLE
              . ' WHERE journal_id = ? AND submission_id = ? ORDER BY review_round_id ASC';
        $rows = DB::select($sql, [$journalId, $submissionId]);

        return array_map(fn($row) => $this->decodeJson((array) $row), $rows);
    }

    // ── Mutations ───────────────────────────────────────────────────────────

    public function insert(array $data): int
    {
        $now = now()->toDateTimeString();

        DB::insert(
            'INSERT INTO ' . self::TABLE
            . ' (journal_id, submission_id, review_round_id, s9_paper_id, s9_review_url,'
            . '  status, webhook_secret, s9_webhook_id, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['journal_id'],
                $data['submission_id'],
                $data['review_round_id']  ?? 0,
                $data['s9_paper_id']      ?? null,
                $data['s9_review_url']    ?? null,
                $data['status']           ?? 'pending',
                $data['webhook_secret']   ?? null,
                $data['s9_webhook_id']    ?? null,
                $now,
                $now,
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = [
            's9_paper_id', 's9_review_url', 'status', 'recommendation', 'score',
            'reviewer_id', 'reviewer_name', 'reviewer_email', 'reviewers_cache',
            'reviewer_comment', 'ai_summary',
            'messages_cache', 'ratings_cache', 'open_review', 'full_review',
            'webhook_secret', 's9_webhook_id', 'last_synced_at',
        ];

        $sets   = [];
        $values = [];

        foreach ($allowed as $col) {
            if (!\array_key_exists($col, $data)) {
                continue;
            }
            $sets[]   = "{$col} = ?";
            $values[] = \in_array($col, ['reviewers_cache', 'messages_cache', 'ratings_cache'], true) && \is_array($data[$col])
                ? json_encode($data[$col])
                : $data[$col];
        }

        if (empty($sets)) {
            return;
        }

        $sets[]   = 'updated_at = ?';
        $values[] = now()->toDateTimeString();
        $values[] = $id;

        DB::update('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
    }

    public function delete(int $id): void
    {
        DB::delete('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function decodeJson(array $row): array
    {
        foreach (['reviewers_cache', 'messages_cache', 'ratings_cache'] as $col) {
            if (!empty($row[$col]) && \is_string($row[$col])) {
                $row[$col] = json_decode($row[$col], true) ?? [];
            }
        }
        return $row;
    }
}
