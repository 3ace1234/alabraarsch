<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Validator;

/**
 * Shared academic helpers: current term, term formatting, grading and ranking.
 */
final class Academics
{
    private static ?array $currentTerm = null;

    public static function currentTerm(): array
    {
        if (self::$currentTerm !== null) {
            return self::$currentTerm;
        }

        $term = Database::fetch(
            'SELECT t.*, s.name AS session_name, s.uuid AS session_uuid
             FROM terms t
             INNER JOIN academic_sessions s ON s.id = t.session_id
             WHERE t.is_current = 1
             ORDER BY t.id DESC
             LIMIT 1'
        );

        if ($term === null) {
            throw HttpException::server('No current academic term has been configured.');
        }

        self::$currentTerm = $term;

        return $term;
    }

    public static function termByUuid(?string $uuid): array
    {
        if ($uuid === null || $uuid === '') {
            return self::currentTerm();
        }

        $term = Database::fetch(
            'SELECT t.*, s.name AS session_name, s.uuid AS session_uuid
             FROM terms t
             INNER JOIN academic_sessions s ON s.id = t.session_id
             WHERE t.uuid = ?
             LIMIT 1',
            [$uuid]
        );

        if ($term === null) {
            throw HttpException::notFound('That academic term was not found.');
        }

        return $term;
    }

    public static function latestPublishedTerm(?int $studentId = null): ?array
    {
        $sql = 'SELECT t.*, s.name AS session_name, s.uuid AS session_uuid
                FROM terms t
                INNER JOIN academic_sessions s ON s.id = t.session_id
                WHERE t.results_published_at IS NOT NULL';
        $params = [];

        if ($studentId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM results r WHERE r.term_id = t.id AND r.student_id = ?)';
            $params[] = $studentId;
        }

        $sql .= ' ORDER BY s.starts_on DESC, t.sequence DESC LIMIT 1';

        return Database::fetch($sql, $params);
    }

    public static function presentTerm(array $term): array
    {
        return [
            'id' => $term['uuid'],
            'name' => $term['name'],
            'sequence' => (int) $term['sequence'],
            'session' => [
                'id' => $term['session_uuid'],
                'name' => $term['session_name'],
            ],
            'label' => trim($term['name'] . ', ' . $term['session_name']),
            'starts_on' => $term['starts_on'],
            'ends_on' => $term['ends_on'],
            'is_current' => (bool) $term['is_current'],
            'results_published_at' => $term['results_published_at'],
        ];
    }

    public static function classByUuid(string $uuid): array
    {
        $class = Database::fetch(
            'SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
             FROM classes c
             LEFT JOIN users u ON u.id = c.form_teacher_id
             WHERE c.uuid = ?
             LIMIT 1',
            [$uuid]
        );

        if ($class === null) {
            throw HttpException::notFound('That class was not found.');
        }

        return $class;
    }

    public static function subjectByUuid(string $uuid): array
    {
        $subject = Database::fetch(
            'SELECT * FROM subjects WHERE uuid = ? LIMIT 1',
            [$uuid]
        );

        if ($subject === null) {
            throw HttpException::notFound('That subject was not found.');
        }

        return $subject;
    }

    /** @return list<array> */
    public static function allTerms(): array
    {
        return Database::fetchAll(
            'SELECT t.*, s.name AS session_name, s.uuid AS session_uuid, s.starts_on AS session_starts_on
             FROM terms t
             INNER JOIN academic_sessions s ON s.id = t.session_id
             ORDER BY s.starts_on DESC, t.sequence DESC'
        );
    }

    public static function presentClass(array $class): array
    {
        return [
            'id' => $class['uuid'],
            'name' => $class['name'],
            'level' => $class['level'],
            'level_label' => self::levelLabel($class['level']),
            'form_teacher' => !empty($class['teacher_first_name'])
                ? trim($class['teacher_first_name'] . ' ' . $class['teacher_last_name'])
                : null,
        ];
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'nursery' => 'Nursery',
            'primary' => 'Primary',
            'junior_secondary' => 'Junior Secondary',
            'senior_secondary' => 'Senior Secondary',
            default => ucfirst(str_replace('_', ' ', $level)),
        };
    }

    public static function grade(float $total): array
    {
        $total = round($total, 2);

        return match (true) {
            $total >= 85 => ['grade' => 'A', 'remark' => 'Excellent'],
            $total >= 75 => ['grade' => 'A', 'remark' => 'Very good'],
            $total >= 70 => ['grade' => 'A', 'remark' => 'Good'],
            $total >= 60 => ['grade' => 'B', 'remark' => 'Credit'],
            $total >= 50 => ['grade' => 'C', 'remark' => 'Pass'],
            $total >= 45 => ['grade' => 'D', 'remark' => 'Fair'],
            $total >= 40 => ['grade' => 'E', 'remark' => 'Weak'],
            default => ['grade' => 'F', 'remark' => 'Needs improvement'],
        };
    }

    public static function averageForStudent(int $studentId, int $termId): ?float
    {
        $average = Database::fetchValue(
            'SELECT AVG(total) FROM results WHERE student_id = ? AND term_id = ?',
            [$studentId, $termId]
        );

        return $average !== null ? round((float) $average, 1) : null;
    }

    public static function classSize(int $classId): int
    {
        return (int) Database::fetchValue(
            "SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'",
            [$classId]
        );
    }

    /**
     * Rank by the mean total across all recorded subjects. Ties share a rank.
     *
     * @return array{position: int|null, class_size: int}
     */
    public static function positionForStudent(int $studentId, int $termId, int $classId): array
    {
        $rows = Database::fetchAll(
            'SELECT r.student_id, AVG(r.total) AS average
             FROM results r
             INNER JOIN students s ON s.id = r.student_id
             WHERE r.term_id = ? AND s.class_id = ? AND s.status <> \'withdrawn\'
             GROUP BY r.student_id
             ORDER BY average DESC',
            [$termId, $classId]
        );

        $position = null;
        $studentAverage = null;

        foreach ($rows as $row) {
            if ((int) $row['student_id'] === $studentId) {
                $studentAverage = (float) $row['average'];
                break;
            }
        }

        if ($studentAverage === null) {
            return ['position' => null, 'class_size' => self::classSize($classId)];
        }

        $ahead = 0;
        foreach ($rows as $row) {
            if ((float) $row['average'] > $studentAverage) {
                $ahead++;
            }
        }
        $position = $ahead + 1;

        return ['position' => $position, 'class_size' => self::classSize($classId)];
    }

    public static function currentSessionId(): int
    {
        return (int) self::currentTerm()['session_id'];
    }

    public static function validTermUuid(mixed $value): ?string
    {
        return Validator::uuid($value, 'term_id', false);
    }

    public static function dayName(int $day): string
    {
        return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day] ?? 'Unknown';
    }

    public static function formatDate(?string $date, string $format = 'j M Y'): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp !== false ? date($format, $timestamp) : null;
    }
}
