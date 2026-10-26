<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;

final class Timetable
{
    /** @var array<int, array{0: string, 1: string}> */
    public const PERIOD_TIMES = [
        1 => ['08:00:00', '08:40:00'],
        2 => ['08:40:00', '09:20:00'],
        3 => ['09:20:00', '10:00:00'],
        4 => ['10:20:00', '11:00:00'],
        5 => ['11:00:00', '11:40:00'],
        6 => ['12:20:00', '13:00:00'],
    ];

    public static function forClass(int $classId, int $termId): array
    {
        $rows = Database::fetchAll(
            'SELECT ts.uuid, ts.day_of_week, ts.period_no, ts.starts_at, ts.ends_at, ts.room,
                    sub.uuid AS subject_uuid, sub.name AS subject_name,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
             FROM timetable_slots ts
             INNER JOIN subjects sub ON sub.id = ts.subject_id
             LEFT JOIN users u ON u.id = ts.teacher_id
             WHERE ts.class_id = ? AND ts.term_id = ?
             ORDER BY ts.day_of_week ASC, ts.period_no ASC',
            [$classId, $termId]
        );

        $days = [
            'Monday' => array_fill(0, 6, null),
            'Tuesday' => array_fill(0, 6, null),
            'Wednesday' => array_fill(0, 6, null),
            'Thursday' => array_fill(0, 6, null),
            'Friday' => array_fill(0, 6, null),
        ];

        $periods = self::periods();
        $slots = [];

        foreach ($rows as $row) {
            $day = Academics::dayName((int) $row['day_of_week']);
            $period = (int) $row['period_no'];
            if (!isset($days[$day]) || $period < 1 || $period > 6) {
                continue;
            }

            $slot = [
                'id' => $row['uuid'],
                'day' => $day,
                'period' => $period,
                'subject' => [
                    'id' => $row['subject_uuid'],
                    'name' => $row['subject_name'],
                ],
                'teacher' => !empty($row['teacher_first_name'])
                    ? trim($row['teacher_first_name'] . ' ' . $row['teacher_last_name'])
                    : 'Class teacher',
                'room' => $row['room'],
                'starts_at' => substr((string) $row['starts_at'], 0, 5),
                'ends_at' => substr((string) $row['ends_at'], 0, 5),
            ];

            $days[$day][$period - 1] = $slot;
            $slots[] = $slot;
        }

        return [
            'periods' => $periods,
            'days' => $days,
            'slots' => $slots,
        ];
    }

    public static function teacherDay(int $teacherId, string $date, int $termId): array
    {
        $day = (int) date('N', strtotime($date));
        if ($day < 1 || $day > 5) {
            return [];
        }

        $rows = Database::fetchAll(
            'SELECT ts.uuid, ts.period_no, ts.starts_at, ts.ends_at, ts.room,
                    c.uuid AS class_uuid, c.name AS class_name,
                    sub.uuid AS subject_uuid, sub.name AS subject_name
             FROM timetable_slots ts
             INNER JOIN classes c ON c.id = ts.class_id
             INNER JOIN subjects sub ON sub.id = ts.subject_id
             WHERE ts.teacher_id = ? AND ts.term_id = ? AND ts.day_of_week = ?
             ORDER BY ts.period_no ASC',
            [$teacherId, $termId, $day]
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['uuid'],
            'period' => (int) $row['period_no'],
            'time' => self::timeLabel($row['starts_at'], $row['ends_at']),
            'starts_at' => substr((string) $row['starts_at'], 0, 5),
            'ends_at' => substr((string) $row['ends_at'], 0, 5),
            'class' => ['id' => $row['class_uuid'], 'name' => $row['class_name']],
            'subject' => ['id' => $row['subject_uuid'], 'name' => $row['subject_name']],
            'room' => $row['room'],
        ], $rows);
    }

    public static function upcomingForTeacher(int $teacherId, int $termId, int $limit = 4): array
    {
        $remaining = [];
        $today = date('Y-m-d');
        $now = date('H:i:s');

        for ($offset = 0; $offset <= 7 && count($remaining) < $limit; $offset++) {
            $date = date('Y-m-d', strtotime("+{$offset} days", strtotime($today)));
            $lessons = self::teacherDay($teacherId, $date, $termId);

            foreach ($lessons as $lesson) {
                if ($offset === 0 && $lesson['starts_at'] <= substr($now, 0, 5)) {
                    continue;
                }

                if ($offset >= 5) {
                    continue;
                }

                $lesson['date'] = $date;
                $lesson['date_label'] = $offset === 0 ? 'Today' : date('D, j M', strtotime($date));
                $remaining[] = $lesson;

                if (count($remaining) >= $limit) {
                    break;
                }
            }
        }

        return $remaining;
    }

    public static function studentUpNext(int $classId, int $termId, int $limit = 3): array
    {
        $today = date('Y-m-d');
        $now = date('H:i:s');
        $result = [];

        for ($offset = 0; $offset <= 7 && count($result) < $limit; $offset++) {
            $date = date('Y-m-d', strtotime("+{$offset} days", strtotime($today)));
            $day = (int) date('N', strtotime($date));
            if ($day < 1 || $day > 5) {
                continue;
            }

            $rows = Database::fetchAll(
                'SELECT ts.uuid, ts.period_no, ts.starts_at, ts.ends_at, ts.room,
                        sub.name AS subject_name, sub.uuid AS subject_uuid,
                        u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
                 FROM timetable_slots ts
                 INNER JOIN subjects sub ON sub.id = ts.subject_id
                 LEFT JOIN users u ON u.id = ts.teacher_id
                 WHERE ts.class_id = ? AND ts.term_id = ? AND ts.day_of_week = ?
                 ORDER BY ts.period_no ASC',
                [$classId, $termId, $day]
            );

            foreach ($rows as $row) {
                if ($offset === 0 && substr((string) $row['starts_at'], 0, 5) <= substr($now, 0, 5)) {
                    continue;
                }

                $result[] = [
                    'id' => $row['uuid'],
                    'subject' => $row['subject_name'],
                    'teacher' => !empty($row['teacher_first_name'])
                        ? trim($row['teacher_first_name'] . ' ' . $row['teacher_last_name'])
                        : 'Class teacher',
                    'room' => $row['room'],
                    'time' => self::timeLabel($row['starts_at'], $row['ends_at']),
                    'period' => (int) $row['period_no'],
                    'date' => $date,
                    'date_label' => $offset === 0 ? 'Today' : date('D, j M', strtotime($date)),
                ];

                if (count($result) >= $limit) {
                    break 2;
                }
            }
        }

        return $result;
    }

    public static function createSlot(
        int $classId,
        int $subjectId,
        ?int $teacherId,
        int $termId,
        int $day,
        int $period,
        string $room
    ): array {
        if ($day < 1 || $day > 5) {
            throw HttpException::validation('Choose a school day from Monday to Friday.', [
                'day_of_week' => 'Day must be between 1 and 5.',
            ]);
        }
        if (!isset(self::PERIOD_TIMES[$period])) {
            throw HttpException::validation('Choose a valid timetable period.', [
                'period_no' => 'Period must be between 1 and 6.',
            ]);
        }

        [$starts, $ends] = self::PERIOD_TIMES[$period];

        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO timetable_slots
                (uuid, term_id, class_id, subject_id, teacher_id, day_of_week, period_no, starts_at, ends_at, room)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $termId, $classId, $subjectId, $teacherId, $day, $period, $starts, $ends, $room]
        );

        return ['id' => $uuid];
    }

    public static function deleteSlot(string $uuid): void
    {
        $deleted = Database::execute('DELETE FROM timetable_slots WHERE uuid = ?', [$uuid]);
        if ($deleted === 0) {
            throw HttpException::notFound('That timetable slot was not found.');
        }
    }

    /** @return list<array{period: int, label: string, time: string}> */
    public static function periods(): array
    {
        $periods = [];
        foreach (self::PERIOD_TIMES as $number => [$start, $end]) {
            $periods[] = [
                'period' => $number,
                'label' => 'Period ' . $number,
                'time' => self::timeLabel($start, $end),
            ];
        }

        return $periods;
    }

    public static function timeLabel(string $start, string $end): string
    {
        return self::clock($start) . ' – ' . self::clock($end);
    }

    private static function clock(string $time): string
    {
        return ltrim(date('g:i', strtotime($time)), '0') . (strtotime($time) !== false && (int) substr($time, 0, 2) >= 12 ? 'pm' : 'am');
    }

    public static function parseDate(mixed $value): string
    {
        return Validator::date($value, 'date') ?? date('Y-m-d');
    }
}
