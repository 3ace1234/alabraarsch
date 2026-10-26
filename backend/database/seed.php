<?php

declare(strict_types=1);

use AlFalaah\Core\Uuid;
use AlFalaah\Services\Academics;
use AlFalaah\Services\Timetable;

/**
 * Seeds a coherent demo school. It is intentionally data-rich so every
 * dashboard, result, attendance, fee and approval endpoint can be exercised.
 *
 * @return array<string, int|string>
 */
return static function (PDO $pdo, string $demoPassword): array {
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $termStart = '2026-09-07';
    $termEnd = '2026-12-11';
    $previousTermStart = '2026-04-20';
    $previousTermEnd = '2026-07-17';
    $passwordHash = password_hash($demoPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    try {
        $insert = static function (string $sql, array $params = []) use ($pdo): int {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return (int) $pdo->lastInsertId();
        };

        $makeUser = static function (
            string $email,
            string $role,
            string $first,
            string $last,
            ?string $phone = null
        ) use ($insert, $passwordHash, $now): int {
            return $insert(
                'INSERT INTO users
                    (uuid, email, password_hash, role, first_name, last_name, phone, status, password_changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    strtolower($email),
                    $passwordHash,
                    $role,
                    $first,
                    $last,
                    $phone,
                    'active',
                    $now,
                ]
            );
        };

        /* ---------------------------------------------------------------
           Staff and parent accounts
           --------------------------------------------------------------- */
        $teacherDefs = [
            'grace' => ['g.adeyemi@alfalaah.edu.ng', 'Grace', 'Adeyemi', '+234 803 100 2201'],
            'sulaiman' => ['s.bello@alfalaah.edu.ng', 'Sulaiman', 'Bello', '+234 803 100 2202'],
            'tunde' => ['t.ojo@alfalaah.edu.ng', 'Tunde', 'Ojo', '+234 803 100 2203'],
            'ibrahim' => ['i.musa@alfalaah.edu.ng', 'Ibrahim', 'Musa', '+234 803 100 2204'],
            'abdulrahman' => ['a.yusuf@alfalaah.edu.ng', 'Abdulrahman', 'Yusuf', '+234 803 100 2205'],
            'funke' => ['f.balogun@alfalaah.edu.ng', 'Funke', 'Balogun', '+234 803 100 2206'],
            'samuel' => ['s.eze@alfalaah.edu.ng', 'Samuel', 'Eze', '+234 803 100 2207'],
            'ngozi' => ['n.okeke@alfalaah.edu.ng', 'Ngozi', 'Okeke', '+234 803 100 2208'],
            'adewale' => ['a.ogunleye@alfalaah.edu.ng', 'Adewale', 'Ogunleye', '+234 803 100 2209'],
            'kunle' => ['k.adebayo@alfalaah.edu.ng', 'Kunle', 'Adebayo', '+234 803 100 2210'],
            'bola' => ['b.ajayi@alfalaah.edu.ng', 'Bola', 'Ajayi', '+234 803 100 2211'],
            'danjuma' => ['d.danjuma@alfalaah.edu.ng', 'Danjuma', 'Musa', '+234 803 100 2212'],
            'halima' => ['h.sadiq@alfalaah.edu.ng', 'Halima', 'Sadiq', '+234 803 100 2213'],
            'amaka' => ['a.nwosu@alfalaah.edu.ng', 'Amaka', 'Nwosu', '+234 803 100 2214'],
            'fatima' => ['f.lawal@alfalaah.edu.ng', 'Fatima', 'Lawal', '+234 803 100 2215'],
        ];

        $teacherIds = [];
        foreach ($teacherDefs as $key => [$email, $first, $last, $phone]) {
            $teacherIds[$key] = $makeUser($email, 'teacher', $first, $last, $phone);
        }

        $adminId = $makeUser('h.sanni@alfalaah.edu.ng', 'admin', 'Hauwa', 'Sanni', '+234 803 100 1100');
        $parentId = $makeUser('ibrahim.abdullahi@example.com', 'parent', 'Ibrahim', 'Abdullahi', '+234 803 000 0000');
        $aishaUserId = $makeUser(
            'aisha.abdullahi@student.alfalaah.edu.ng',
            'student',
            'Aisha',
            'Abdullahi',
            '+234 803 000 0001'
        );
        $yusufUserId = $makeUser(
            'yusuf.abdullahi@student.alfalaah.edu.ng',
            'student',
            'Yusuf',
            'Abdullahi',
            '+234 803 000 0000'
        );

        /* ---------------------------------------------------------------
           Academic calendar
           --------------------------------------------------------------- */
        $previousSessionId = $insert(
            'INSERT INTO academic_sessions (uuid, name, starts_on, ends_on, is_current)
             VALUES (?, ?, ?, ?, 0)',
            [Uuid::v4(), '2025/2026', '2025-09-08', '2026-07-17']
        );
        $currentSessionId = $insert(
            'INSERT INTO academic_sessions (uuid, name, starts_on, ends_on, is_current)
             VALUES (?, ?, ?, ?, 1)',
            [Uuid::v4(), '2026/2027', $termStart, '2027-07-16']
        );

        $previousTermId = $insert(
            'INSERT INTO terms
                (uuid, session_id, name, sequence, starts_on, ends_on, is_current, results_published_at)
             VALUES (?, ?, ?, 3, ?, ?, 0, ?)',
            [Uuid::v4(), $previousSessionId, 'Third Term', $previousTermStart, $previousTermEnd, '2026-07-18 10:00:00']
        );

        $currentTermId = $insert(
            'INSERT INTO terms
                (uuid, session_id, name, sequence, starts_on, ends_on, is_current)
             VALUES (?, ?, ?, 1, ?, ?, 1)',
            [Uuid::v4(), $currentSessionId, 'First Term', $termStart, $termEnd]
        );
        $insert(
            'INSERT INTO terms
                (uuid, session_id, name, sequence, starts_on, ends_on, is_current)
             VALUES (?, ?, ?, 2, ?, ?, 0)',
            [Uuid::v4(), $currentSessionId, 'Second Term', '2027-01-11', '2027-03-26']
        );
        $insert(
            'INSERT INTO terms
                (uuid, session_id, name, sequence, starts_on, ends_on, is_current)
             VALUES (?, ?, ?, 3, ?, ?, 0)',
            [Uuid::v4(), $currentSessionId, 'Third Term', '2027-04-19', '2027-07-16']
        );

        /* ---------------------------------------------------------------
           Classes
           --------------------------------------------------------------- */
        $classDefs = [
            'Nursery 2' => ['nursery', 'fatima'],
            'Primary 5A' => ['primary', 'grace'],
            'JSS 1A' => ['junior_secondary', 'tunde'],
            'JSS 2B' => ['junior_secondary', 'sulaiman'],
            'JSS 3A' => ['junior_secondary', 'funke'],
            'SSS 1B' => ['senior_secondary', 'ibrahim'],
            'SSS 2A' => ['senior_secondary', 'halima'],
        ];

        $classIds = [];
        $classNames = [];
        foreach ($classDefs as $name => [$level, $formTeacherKey]) {
            $classIds[$name] = $insert(
                'INSERT INTO classes (uuid, name, level, form_teacher_id) VALUES (?, ?, ?, ?)',
                [Uuid::v4(), $name, $level, $teacherIds[$formTeacherKey]]
            );
            $classNames[$name] = $name;
        }

        /* ---------------------------------------------------------------
           Subjects and class-subject teaching assignments
           --------------------------------------------------------------- */
        $subjectDefs = [
            'ENG' => ['English Studies', 'grace'],
            'MTH' => ['Mathematics', 'sulaiman'],
            'BSC' => ['Basic Science', 'tunde'],
            'ISL' => ['Islamic Studies', 'ibrahim'],
            'ARB' => ['Arabic', 'abdulrahman'],
            'SOS' => ['Social Studies', 'funke'],
            'CMP' => ['Computer Studies', 'samuel'],
            'BUS' => ['Business Studies', 'ngozi'],
            'YOR' => ['Yoruba', 'adewale'],
            'BTE' => ['Basic Technology', 'kunle'],
            'AGR' => ['Agricultural Science', 'bola'],
            'PHE' => ['P.H.E.', 'danjuma'],
            'CIV' => ['Civic Education', 'halima'],
            'CRA' => ['Creative Arts', 'amaka'],
            'FMT' => ['Further Mathematics', 'sulaiman'],
            'BIO' => ['Biology', 'bola'],
            'CHE' => ['Chemistry', 'halima'],
            'PHY' => ['Physics', 'tunde'],
        ];

        $subjectIds = [];
        $subjectNames = [];
        foreach ($subjectDefs as $code => [$name, $teacherKey]) {
            $subjectIds[$code] = $insert(
                'INSERT INTO subjects (uuid, name, code) VALUES (?, ?, ?)',
                [Uuid::v4(), $name, $code]
            );
            $subjectNames[$code] = $name;
        }

        $classSubjectCodes = [
            'Nursery 2' => ['ENG', 'MTH', 'BSC', 'ISL', 'ARB', 'SOS', 'CRA'],
            'Primary 5A' => ['ENG', 'MTH', 'BSC', 'ISL', 'ARB', 'SOS', 'CMP', 'AGR'],
            'JSS 1A' => ['ENG', 'MTH', 'BSC', 'ISL', 'ARB', 'SOS', 'CMP', 'BUS', 'YOR'],
            'JSS 2B' => ['ENG', 'MTH', 'BSC', 'ISL', 'ARB', 'SOS', 'CMP', 'BUS', 'YOR'],
            'JSS 3A' => ['ENG', 'MTH', 'BSC', 'ISL', 'ARB', 'SOS', 'CMP', 'BUS', 'YOR'],
            'SSS 1B' => ['ENG', 'MTH', 'BIO', 'CHE', 'PHY', 'FMT', 'ISL', 'CIV', 'CMP'],
            'SSS 2A' => ['ENG', 'MTH', 'BIO', 'CHE', 'PHY', 'FMT', 'ISL', 'CIV', 'CMP'],
        ];

        $classSubjectRows = [];
        foreach ($classSubjectCodes as $className => $codes) {
            $classSubjectRows[$className] = [];
            foreach ($codes as $code) {
                $teacherKey = $subjectDefs[$code][1];
                $insert(
                    'INSERT INTO class_subjects (class_id, subject_id, teacher_id, session_id)
                     VALUES (?, ?, ?, ?)',
                    [$classIds[$className], $subjectIds[$code], $teacherIds[$teacherKey], $currentSessionId]
                );
                $classSubjectRows[$className][] = [
                    'code' => $code,
                    'subject_id' => $subjectIds[$code],
                    'teacher_id' => $teacherIds[$teacherKey],
                ];
            }
        }

        /* ---------------------------------------------------------------
           Students
           --------------------------------------------------------------- */
        $houses = ['Al-Farooq House', 'Al-Firdaus House', 'Al-Aqsa House', 'Al-Noor House'];
        $students = [];
        $admissionCounter = 200;

        $addStudent = static function (
            string $className,
            string $first,
            string $last,
            string $admissionNo,
            string $gender,
            string $dob,
            ?int $userId = null,
            ?string $house = null
        ) use ($insert, $classIds, $houses): int {
            $id = $insert(
                'INSERT INTO students
                    (uuid, user_id, admission_no, first_name, last_name, date_of_birth, gender,
                     class_id, house, status, admitted_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    $userId,
                    $admissionNo,
                    $first,
                    $last,
                    $dob,
                    $gender,
                    $classIds[$className],
                    $house ?? $houses[($userId ?? 0) % count($houses)],
                    'active',
                    $className === 'Nursery 2' ? '2026-09-07' : '2024-09-09',
                ]
            );

            return $id;
        };

        $aishaId = $addStudent('JSS 2B', 'Aisha', 'Abdullahi', 'AF/2024/0187', 'female', '2012-03-14', $aishaUserId, 'Al-Farooq House');
        $yusufId = $addStudent('Primary 5A', 'Yusuf', 'Abdullahi', 'AF/2025/0321', 'male', '2015-08-22', $yusufUserId, 'Al-Farooq House');

        $studentDefs = [
            'JSS 2B' => [
                ['Musa', 'Danjuma', 'male', '2012-01-11'],
                ['Khadija', 'Bello', 'female', '2012-05-19'],
                ['Abdulrahman', 'Sani', 'male', '2011-11-02'],
                ['Zainab', 'Lawal', 'female', '2012-07-07'],
                ['Ismaila', 'Garba', 'male', '2012-02-25'],
                ['Maryam', 'Umar', 'female', '2011-09-30'],
                ['Sadiq', 'Abubakar', 'male', '2012-06-16'],
                ['Hauwa', 'Idris', 'female', '2012-04-08'],
                ['Bilal', 'Shehu', 'male', '2012-10-21'],
            ],
            'Primary 5A' => [
                ['Fatima', 'Bello', 'female', '2015-02-17'],
                ['Ibrahim', 'Sani', 'male', '2015-06-09'],
                ['Amina', 'Musa', 'female', '2015-01-28'],
                ['Abdulkarim', 'Ojo', 'male', '2015-09-12'],
                ['Hafsah', 'Umar', 'female', '2015-03-05'],
                ['David', 'Eze', 'male', '2015-07-23'],
                ['Halima', 'Lawal', 'female', '2015-11-14'],
            ],
            'JSS 1A' => [
                ['Nabila', 'Yusuf', 'female', '2013-02-10'],
                ['Umar', 'Faruk', 'male', '2013-05-21'],
                ['Aisha', 'Sule', 'female', '2013-08-03'],
                ['Ibrahim', 'Danjuma', 'male', '2013-01-17'],
                ['Zainab', 'Musa', 'female', '2013-09-26'],
                ['Kabir', 'Ojo', 'male', '2013-12-08'],
            ],
            'JSS 3A' => [
                ['Maryam', 'Bello', 'female', '2011-02-12'],
                ['Usman', 'Garba', 'male', '2011-04-30'],
                ['Fatima', 'Sani', 'female', '2011-07-19'],
                ['Abdullahi', 'Lawal', 'male', '2011-10-05'],
                ['Sumayya', 'Umar', 'female', '2011-12-22'],
                ['Hassan', 'Idris', 'male', '2011-06-15'],
            ],
            'SSS 1B' => [
                ['Khadija', 'Ojo', 'female', '2009-03-09'],
                ['Ahmad', 'Musa', 'male', '2009-06-18'],
                ['Blessing', 'Eze', 'female', '2009-08-27'],
                ['Faisal', 'Bello', 'male', '2009-11-04'],
                ['Rahma', 'Sani', 'female', '2009-01-23'],
                ['Chinedu', 'Okeke', 'male', '2009-05-31'],
            ],
            'SSS 2A' => [
                ['Aisha', 'Bello', 'female', '2008-02-14'],
                ['Daniel', 'Adeyemi', 'male', '2008-05-06'],
                ['Hafsat', 'Umar', 'female', '2008-09-01'],
                ['Samuel', 'Ojo', 'male', '2008-11-19'],
                ['Zainab', 'Garba', 'female', '2008-07-25'],
            ],
            'Nursery 2' => [
                ['Amina', 'Sani', 'female', '2021-01-16'],
                ['Musa', 'Bello', 'male', '2021-03-22'],
                ['Fatima', 'Ojo', 'female', '2021-06-11'],
                ['Ibrahim', 'Lawal', 'male', '2021-09-08'],
                ['Maryam', 'Eze', 'female', '2021-11-27'],
            ],
        ];

        foreach ($studentDefs as $className => $defs) {
            foreach ($defs as [$first, $last, $gender, $dob]) {
                $admissionCounter++;
                $admissionNo = sprintf(
                    'AF/%s/%04d',
                    $className === 'Primary 5A' ? '2025' : ($className === 'Nursery 2' ? '2026' : '2024'),
                    $admissionCounter
                );
                $students[] = [
                    'id' => $addStudent($className, $first, $last, $admissionNo, $gender, $dob),
                    'class' => $className,
                    'name' => "{$first} {$last}",
                ];
            }
        }

        $aisha = ['id' => $aishaId, 'class' => 'JSS 2B', 'name' => 'Aisha Abdullahi'];
        $yusuf = ['id' => $yusufId, 'class' => 'Primary 5A', 'name' => 'Yusuf Abdullahi'];

        $studentsByClass = [];
        foreach (array_merge([$aisha, $yusuf], $students) as $student) {
            $studentsByClass[$student['class']][] = $student;
        }

        // Keep Aisha and Yusuf first for deterministic peer score ordering.
        foreach ($studentsByClass as $className => &$list) {
            usort($list, static function (array $left, array $right) use ($aishaId, $yusufId): int {
                $rank = static function (array $student) use ($aishaId, $yusufId): int {
                    if ((int) $student['id'] === $aishaId) {
                        return 0;
                    }
                    if ((int) $student['id'] === $yusufId) {
                        return 0;
                    }
                    return 1;
                };
                return $rank($left) <=> $rank($right);
            });
        }
        unset($list);

        $insert(
            'INSERT INTO student_guardians (student_id, user_id, relationship, is_primary) VALUES (?, ?, ?, 1)',
            [$aishaId, $parentId, 'father']
        );
        $insert(
            'INSERT INTO student_guardians (student_id, user_id, relationship, is_primary) VALUES (?, ?, ?, 1)',
            [$yusufId, $parentId, 'father']
        );

        /* ---------------------------------------------------------------
           Timetable (current term)
           --------------------------------------------------------------- */
        $periods = Timetable::PERIOD_TIMES;
        $classIndex = 0;
        foreach ($classSubjectCodes as $className => $codes) {
            $mathKey = array_search('MTH', $codes, true);
            $others = array_values(array_filter($codes, static fn (string $code): bool => $code !== 'MTH'));

            for ($day = 1; $day <= 5; $day++) {
                for ($period = 1; $period <= 6; $period++) {
                    $mathPosition = (($day - 1) + $classIndex) % 6;
                    if ($period - 1 === $mathPosition) {
                        $code = 'MTH';
                    } else {
                        $offset = ($day * 2 + $period + $classIndex) % max(1, count($others));
                        $code = $others[$offset];
                    }

                    [$starts, $ends] = $periods[$period];
                    $teacherKey = $subjectDefs[$code][1];
                    $room = match (true) {
                        in_array($code, ['CMP', 'BTE'], true) => 'ICT Lab',
                        in_array($code, ['BSC', 'BIO', 'CHE', 'PHY', 'AGR'], true) => 'Science Lab',
                        in_array($code, ['ISL', 'ARB'], true) => 'Assembly Hall',
                        $code === 'PHE' => 'Sports Field',
                        $code === 'CRA' => 'Art Studio',
                        default => $className,
                    };

                    $insert(
                        'INSERT INTO timetable_slots
                            (uuid, term_id, class_id, subject_id, teacher_id, day_of_week, period_no, starts_at, ends_at, room)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            Uuid::v4(),
                            $currentTermId,
                            $classIds[$className],
                            $subjectIds[$code],
                            $teacherIds[$teacherKey],
                            $day,
                            $period,
                            $starts,
                            $ends,
                            $room,
                        ]
                    );
                }
            }
            $classIndex++;
        }

        /* ---------------------------------------------------------------
           Previous-term results (published)
           --------------------------------------------------------------- */
        $jssPeerTargets = [92, 88, 83, 81, 79, 77, 75, 73, 71];
        $primaryPeerTargets = [90, 86, 83, 81.5, 78, 75, 72];

        $aishaTotals = [
            'ENG' => 88, 'MTH' => 94, 'BSC' => 82, 'ISL' => 89, 'ARB' => 86,
            'SOS' => 80, 'CMP' => 90, 'BUS' => 71, 'YOR' => 82,
        ];
        $yusufTotals = [
            'ENG' => 78, 'MTH' => 85, 'BSC' => 81, 'ISL' => 88,
            'ARB' => 76, 'SOS' => 74, 'CMP' => 83, 'AGR' => 79,
        ];

        $insertResult = static function (
            int $studentId,
            int $termId,
            int $subjectId,
            float $total,
            ?int $sheetId = null
        ) use ($insert): void {
            $total = max(0, min(100, round($total, 2)));
            $ca = round($total * 0.4, 2);
            $exam = round($total - $ca, 2);
            $graded = Academics::grade($total);

            $insert(
                'INSERT INTO results
                    (student_id, term_id, subject_id, result_sheet_id, ca_score, exam_score, total, grade, remark)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $studentId,
                    $termId,
                    $subjectId,
                    $sheetId,
                    $ca,
                    $exam,
                    $total,
                    $graded['grade'],
                    $graded['remark'],
                ]
            );
        };

        $resultCount = 0;
        foreach ($classSubjectCodes as $className => $codes) {
            $list = $studentsByClass[$className] ?? [];
            $count = count($codes);
            $peerIndex = 0;

            foreach ($list as $index => $student) {
                foreach ($codes as $subjectIndex => $code) {
                    if ((int) $student['id'] === $aishaId) {
                        $total = (float) ($aishaTotals[$code] ?? 80);
                    } elseif ((int) $student['id'] === $yusufId) {
                        $total = (float) ($yusufTotals[$code] ?? 80);
                    } elseif ($className === 'JSS 2B') {
                        $target = $jssPeerTargets[$peerIndex] ?? 72;
                        $variation = $subjectIndex % 2 === 0 ? 2 : -2;
                        if ($count % 2 === 1 && $subjectIndex === $count - 1) {
                            $variation = 0;
                        }
                        $total = (float) ($target + $variation);
                    } elseif ($className === 'Primary 5A') {
                        $target = $primaryPeerTargets[$peerIndex] ?? 72;
                        $variation = $subjectIndex % 2 === 0 ? 2 : -2;
                        if ($count % 2 === 1 && $subjectIndex === $count - 1) {
                            $variation = 0;
                        }
                        $total = (float) ($target + $variation);
                    } else {
                        $base = 68 + (($index * 5 + $subjectIndex * 3) % 17);
                        $variation = $subjectIndex % 2 === 0 ? 1 : -1;
                        if ($count % 2 === 1 && $subjectIndex === $count - 1) {
                            $variation = 0;
                        }
                        $total = (float) ($base + $variation);
                    }

                    $insertResult((int) $student['id'], $previousTermId, $subjectIds[$code], $total);
                    $resultCount++;
                }

                if ((int) $student['id'] !== $aishaId && (int) $student['id'] !== $yusufId) {
                    $peerIndex++;
                }
            }
        }

        /* ---------------------------------------------------------------
           Current-term result sheets and approval queue
           --------------------------------------------------------------- */
        $makeSheet = static function (
            string $className,
            string $subjectCode,
            string $teacherKey,
            string $status,
            ?string $submittedAt,
            ?string $approvedAt
        ) use ($insert, $classIds, $subjectIds, $teacherIds, $currentTermId, $studentsByClass, $insertResult, $adminId): int {
            $teacherId = $teacherIds[$teacherKey];
            $sheetId = $insert(
                'INSERT INTO result_sheets
                    (uuid, class_id, subject_id, term_id, teacher_id, status, submitted_at, approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    $classIds[$className],
                    $subjectIds[$subjectCode],
                    $currentTermId,
                    $teacherId,
                    $status,
                    $submittedAt,
                    $status === 'approved' ? $adminId : null,
                    $approvedAt,
                ]
            );

            if ($status !== 'draft') {
                foreach ($studentsByClass[$className] ?? [] as $index => $student) {
                    $total = 72 + (($index * 4) % 16);
                    $insertResult((int) $student['id'], $currentTermId, $subjectIds[$subjectCode], (float) $total, $sheetId);
                }
            }

            return $sheetId;
        };

        $makeSheet('JSS 2B', 'MTH', 'sulaiman', 'submitted', '2026-10-23 16:10:00', null);
        $makeSheet('Primary 5A', 'ENG', 'grace', 'submitted', '2026-10-24 09:30:00', null);
        $makeSheet('SSS 2A', 'BIO', 'bola', 'submitted', '2026-10-22 14:05:00', null);
        $makeSheet('JSS 3A', 'ISL', 'ibrahim', 'approved', '2026-10-20 12:40:00', '2026-10-21 09:15:00');
        $makeSheet('JSS 1A', 'MTH', 'sulaiman', 'draft', null, null);

        /* ---------------------------------------------------------------
           Attendance (current term, last eight weeks)
           --------------------------------------------------------------- */
        $attendanceStart = date('Y-m-d', strtotime('-56 days', strtotime($today)));
        if ($attendanceStart < $termStart) {
            $attendanceStart = $termStart;
        }
        if ($attendanceStart > $termEnd) {
            $attendanceStart = $termEnd;
        }
        $attendanceEnd = min($today, $termEnd);

        $recordStatement = $pdo->prepare(
            'INSERT INTO attendance_records
                (student_id, term_id, date, status, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)'
        );
        $registerStatement = $pdo->prepare(
            'INSERT INTO attendance_registers (uuid, class_id, term_id, date, marked_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE marked_by = VALUES(marked_by), marked_at = NOW()'
        );

        $attendanceRecords = 0;
        $attendanceRegisters = 0;
        $cursor = strtotime($attendanceStart);
        $endTimestamp = strtotime($attendanceEnd);

        while ($cursor <= $endTimestamp) {
            $date = date('Y-m-d', $cursor);
            $day = (int) date('N', $cursor);
            if ($day <= 5) {
                foreach ($studentsByClass as $className => $list) {
                    // Leave two classes unmarked today so the teacher dashboard
                    // has a realistic pending-register queue.
                    if ($date === $today && in_array($className, ['JSS 1A', 'JSS 3A'], true)) {
                        continue;
                    }

                    $registerStatement->execute([
                        Uuid::v4(),
                        $classIds[$className],
                        $currentTermId,
                        $date,
                        $teacherIds[$classDefs[$className][1]],
                    ]);
                    $attendanceRegisters++;

                    foreach ($list as $student) {
                        $studentId = (int) $student['id'];
                        $bucket = crc32($date . '|' . $studentId) % 100;

                        if ($studentId === $aishaId) {
                            $status = $bucket < 93 ? 'present' : ($bucket < 96 ? 'late' : ($bucket < 99 ? 'absent' : 'excused'));
                        } elseif ($studentId === $yusufId) {
                            $status = $bucket < 87 ? 'present' : ($bucket < 92 ? 'late' : ($bucket < 97 ? 'absent' : 'excused'));
                        } else {
                            $status = $bucket < 89 ? 'present' : ($bucket < 94 ? 'late' : ($bucket < 98 ? 'absent' : 'excused'));
                        }

                        $timeSeed = crc32($date . '|time|' . $studentId);
                        $note = match ($status) {
                            'present' => 'Signed in at ' . sprintf('%d:%02d', 7 + (int) ($timeSeed % 2), 45 + (int) ($timeSeed % 15)),
                            'late' => 'Arrived at ' . sprintf('8:%02d', 5 + (int) ($timeSeed % 20)),
                            'excused' => 'Authorised absence — guardian note received',
                            default => null,
                        };

                        $recordStatement->execute([
                            $studentId,
                            $currentTermId,
                            $date,
                            $status,
                            $note,
                            $teacherIds[$classDefs[$className][1]],
                        ]);
                        $attendanceRecords++;
                    }
                }
            }

            $cursor = strtotime('+1 day', $cursor);
        }

        /* ---------------------------------------------------------------
           Fees
           --------------------------------------------------------------- */
        $feeAmounts = [
            'Nursery 2' => [80000, 20000, 20000],
            'Primary 5A' => [95000, 25000, 25000],
            'JSS 1A' => [120000, 35000, 30000],
            'JSS 2B' => [120000, 35000, 30000],
            'JSS 3A' => [120000, 35000, 30000],
            'SSS 1B' => [140000, 40000, 35000],
            'SSS 2A' => [140000, 40000, 35000],
        ];
        $feeItems = ['Tuition (First Term)', 'Books & learning materials', 'Transport (First Term)'];
        $feeInvoiceCount = 0;
        $paymentCount = 0;

        $makePayment = static function (
            int $invoiceId,
            int $studentId,
            float $amount,
            string $method,
            string $paidOn,
            ?string $reference = null,
            ?string $note = null
        ) use ($insert, $currentTermId, $adminId, &$paymentCount): void {
            $reference ??= 'PSK-SEED-' . $studentId . '-' . $invoiceId;
            $insert(
                'INSERT INTO fee_payments
                    (uuid, invoice_id, student_id, term_id, reference, amount, method, paid_on, received_by, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Uuid::v4(), $invoiceId, $studentId, $currentTermId, $reference, round($amount, 2), $method, $paidOn, $adminId, $note]
            );
            $paymentCount++;
        };

        foreach ($studentsByClass as $className => $list) {
            $amounts = $feeAmounts[$className];
            foreach ($list as $index => $student) {
                $studentId = (int) $student['id'];
                $invoiceIds = [];

                foreach ($amounts as $itemIndex => $amount) {
                    $feeInvoiceCount++;
                    $reference = match (true) {
                        $studentId === $aishaId => 'INV-26-0' . (412 + $itemIndex),
                        $studentId === $yusufId => 'INV-26-0' . (528 + $itemIndex),
                        default => sprintf('INV-26-%04d', 1000 + $feeInvoiceCount),
                    };

                    $invoiceIds[] = $insert(
                        'INSERT INTO fee_invoices
                            (uuid, student_id, term_id, reference, description, amount, status, issued_on, due_on)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            Uuid::v4(),
                            $studentId,
                            $currentTermId,
                            $reference,
                            $feeItems[$itemIndex],
                            $amount,
                            'unpaid',
                            '2026-09-02',
                            '2026-09-30',
                        ]
                    );
                }

                if ($studentId === $aishaId) {
                    foreach ($invoiceIds as $i => $invoiceId) {
                        $makePayment($invoiceId, $studentId, (float) $amounts[$i], $i === 2 ? 'Card payment' : 'Bank transfer', $i === 1 ? '2026-09-18' : '2026-09-04');
                    }
                    $pdo->prepare("UPDATE fee_invoices SET status = 'paid' WHERE student_id = ? AND term_id = ?")
                        ->execute([$studentId, $currentTermId]);
                    continue;
                }

                if ($studentId === $yusufId) {
                    $makePayment($invoiceIds[0], $studentId, 95000, 'Bank transfer', '2026-09-05', 'PSK-8847120');
                    $makePayment($invoiceIds[1], $studentId, 25000, 'POS at school office', '2026-09-17', 'PSK-8887742');
                    $pdo->prepare("UPDATE fee_invoices SET status = 'paid' WHERE id IN (?, ?)")
                        ->execute([$invoiceIds[0], $invoiceIds[1]]);
                    continue;
                }

                $mode = $studentId % 4;
                if ($mode === 0) {
                    foreach ($invoiceIds as $i => $invoiceId) {
                        $makePayment($invoiceId, $studentId, (float) $amounts[$i], $i === 1 ? 'POS at school office' : 'Bank transfer', '2026-09-15');
                    }
                    $pdo->prepare("UPDATE fee_invoices SET status = 'paid' WHERE student_id = ? AND term_id = ?")
                        ->execute([$studentId, $currentTermId]);
                } elseif ($mode === 1) {
                    $makePayment($invoiceIds[0], $studentId, (float) $amounts[0], 'Bank transfer', '2026-09-20');
                    $makePayment($invoiceIds[1], $studentId, 10000, 'Cash', '2026-09-24');
                    $pdo->prepare("UPDATE fee_invoices SET status = 'paid' WHERE id = ?")->execute([$invoiceIds[0]]);
                    $pdo->prepare("UPDATE fee_invoices SET status = 'partial' WHERE id = ?")->execute([$invoiceIds[1]]);
                } elseif ($mode === 2) {
                    $makePayment($invoiceIds[0], $studentId, (float) $amounts[0], 'Bank transfer', '2026-10-02');
                    $pdo->prepare("UPDATE fee_invoices SET status = 'paid' WHERE id = ?")->execute([$invoiceIds[0]]);
                }
            }
        }

        /* ---------------------------------------------------------------
           Announcements
           --------------------------------------------------------------- */
        $announcementDefs = [
            [
                'title' => 'First Term mid-term break',
                'body' => 'The school closes on Friday, 30 October and reopens on Monday, 9 November 2026, in shaa Allah. Please plan pick-up accordingly.',
                'category' => 'Events',
                'audiences' => ['student', 'parent', 'teacher', 'admin'],
                'pinned' => 1,
                'published_at' => '2026-10-24 08:30:00',
            ],
            [
                'title' => 'Parent–Teacher meeting',
                'body' => 'Our termly Parent–Teacher meeting holds on Saturday, 7 November, from 9:00am to 1:00pm in the main hall. Reports and subject feedback will be shared.',
                'category' => 'Academics',
                'audiences' => ['parent', 'teacher', 'admin'],
                'pinned' => 0,
                'published_at' => '2026-10-21 14:00:00',
            ],
            [
                'title' => 'Third Term results are now published',
                'body' => 'Parents and students can now view the Third Term 2025/2026 results in the portal. Report cards are available for download.',
                'category' => 'Academics',
                'audiences' => ['student', 'parent'],
                'pinned' => 0,
                'published_at' => '2026-10-20 09:00:00',
            ],
            [
                'title' => 'Result entry deadline for teaching staff',
                'body' => 'All First Term continuous assessment scores must be submitted through the portal by 5:00pm on Wednesday, 28 October.',
                'category' => 'Staff',
                'audiences' => ['teacher', 'admin'],
                'pinned' => 0,
                'published_at' => '2026-10-19 11:15:00',
            ],
            [
                'title' => 'Flu vaccination consent forms',
                'body' => 'The school nurse will visit on Tuesday, 3 November. Please return the consent form sent home with your child by Friday, 30 October.',
                'category' => 'Welfare',
                'audiences' => ['parent', 'teacher'],
                'pinned' => 0,
                'published_at' => '2026-10-18 16:20:00',
            ],
            [
                'title' => 'Admissions for 2027/2028 are open',
                'body' => 'Applications for Nursery, Primary and Secondary places are now open. Share the admissions page with families who may be interested.',
                'category' => 'Admissions',
                'audiences' => ['admin', 'teacher'],
                'pinned' => 0,
                'published_at' => '2026-10-16 10:00:00',
            ],
        ];

        $announcementIds = [];
        foreach ($announcementDefs as $definition) {
            $announcementUuid = Uuid::v4();
            $announcementId = $insert(
                'INSERT INTO announcements
                    (uuid, title, body, category, is_pinned, status, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $announcementUuid,
                    $definition['title'],
                    $definition['body'],
                    $definition['category'],
                    $definition['pinned'],
                    'published',
                    $definition['published_at'],
                    $adminId,
                ]
            );
            foreach ($definition['audiences'] as $audience) {
                $insert(
                    'INSERT INTO announcement_audiences (announcement_id, audience) VALUES (?, ?)',
                    [$announcementId, $audience]
                );
            }
            $announcementIds[] = ['id' => $announcementId, 'uuid' => $announcementUuid, 'audiences' => $definition['audiences']];
        }

        /* ---------------------------------------------------------------
           Notifications for the demo accounts
           --------------------------------------------------------------- */
        $notify = static function (
            int $userId,
            string $title,
            string $body,
            string $type,
            string $createdAt,
            bool $isRead = false,
            ?string $relatedUuid = null
        ) use ($insert): void {
            $insert(
                'INSERT INTO notifications
                    (uuid, user_id, title, body, type, related_type, related_uuid, is_read, read_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    $userId,
                    $title,
                    $body,
                    $type,
                    'announcement',
                    $relatedUuid,
                    $isRead ? 1 : 0,
                    $isRead ? $createdAt : null,
                    $createdAt,
                ]
            );
        };

        $notify($aishaUserId, 'Mid-term break', 'School closes on 30 October and reopens on 9 November 2026.', 'announcement', '2026-10-24 09:00:00', false, $announcementIds[0]['uuid']);
        $notify($aishaUserId, 'Third Term results published', 'Your Third Term 2025/2026 result is ready to view.', 'result', '2026-10-20 09:30:00', true, $announcementIds[2]['uuid']);
        $notify($aishaUserId, 'Mathematics assignment due', 'Exercise 4B is due tomorrow. Submit it before assembly.', 'assignment', date('Y-m-d H:i:s', strtotime('-5 hours')), false);
        $notify($aishaUserId, 'Parent–Teacher meeting', 'The termly Parent–Teacher meeting holds on 7 November.', 'announcement', '2026-10-21 14:30:00', true, $announcementIds[1]['uuid']);

        $notify($parentId, 'Fee balance for Yusuf', 'A balance of ₦25,000 remains on Yusuf’s First Term transport invoice.', 'fee', date('Y-m-d H:i:s', strtotime('-2 hours')), false);
        $notify($parentId, 'Mid-term break', 'School closes on 30 October and reopens on 9 November 2026.', 'announcement', '2026-10-24 09:00:00', false, $announcementIds[0]['uuid']);
        $notify($parentId, 'Parent–Teacher meeting', 'Join us on Saturday, 7 November from 9:00am in the main hall.', 'announcement', '2026-10-21 14:30:00', false, $announcementIds[1]['uuid']);
        $notify($parentId, 'Third Term results published', 'Aisha and Yusuf’s Third Term results are now available.', 'result', '2026-10-20 09:30:00', true, $announcementIds[2]['uuid']);

        $notify($teacherIds['sulaiman'], 'Result entry deadline', 'Submit all First Term continuous assessment scores by 28 October.', 'announcement', '2026-10-19 11:30:00', false, $announcementIds[3]['uuid']);
        $notify($teacherIds['sulaiman'], 'Two registers pending', 'JSS 1A and JSS 3A attendance registers are still open for today.', 'attendance', date('Y-m-d H:i:s', strtotime('-1 hour')), false);
        $notify($teacherIds['sulaiman'], 'Mid-term break', 'School closes on 30 October and reopens on 9 November 2026.', 'announcement', '2026-10-24 09:00:00', true, $announcementIds[0]['uuid']);
        $notify($teacherIds['sulaiman'], 'Parent–Teacher meeting', 'The termly meeting holds on 7 November from 9:00am.', 'announcement', '2026-10-21 14:30:00', true, $announcementIds[1]['uuid']);

        $notify($adminId, 'Three result sheets awaiting approval', 'JSS 2B Mathematics, Primary 5A English and SSS 2A Biology are ready for review.', 'result', date('Y-m-d H:i:s', strtotime('-30 minutes')), false);
        $notify($adminId, 'Mid-term break', 'School closes on 30 October and reopens on 9 November 2026.', 'announcement', '2026-10-24 09:00:00', false, $announcementIds[0]['uuid']);
        $notify($adminId, 'New admissions enquiry', 'Six new applications were received this week.', 'admissions', date('Y-m-d H:i:s', strtotime('-3 hours')), false);
        $notify($adminId, 'Parent–Teacher meeting', 'The termly meeting holds on 7 November from 9:00am.', 'announcement', '2026-10-21 14:30:00', true, $announcementIds[1]['uuid']);

        /* ---------------------------------------------------------------
           Admissions
           --------------------------------------------------------------- */
        $applications = [
            ['Habeeb Olawale', 'Rashid Olawale', 'rashid.olawale@example.com', '+234 806 100 3301', 'primary', 'new'],
            ['Sumayya Bello', 'Aminu Bello', 'aminu.bello@example.com', '+234 806 100 3302', 'junior_secondary', 'new'],
            ['Chisom Okafor', 'Nkem Okafor', 'nkem.okafor@example.com', '+234 806 100 3303', 'nursery', 'new'],
            ['Abdulmalik Sani', 'Hafsat Sani', 'hafsat.sani@example.com', '+234 806 100 3304', 'senior_secondary', 'new'],
            ['Zara Yakubu', 'Musa Yakubu', 'musa.yakubu@example.com', '+234 806 100 3305', 'primary', 'new'],
            ['Yusuf Ibrahim', 'Ruqayya Ibrahim', 'ruqayya.ibrahim@example.com', '+234 806 100 3306', 'primary', 'assessment'],
            ['Hauwa Adamu', 'Sule Adamu', 'sule.adamu@example.com', '+234 806 100 3307', 'junior_secondary', 'assessment'],
            ['David Ekong', 'Grace Ekong', 'grace.ekong@example.com', '+234 806 100 3308', 'senior_secondary', 'offer'],
            ['Amina Lawal', 'Khalid Lawal', 'khalid.lawal@example.com', '+234 806 100 3309', 'primary', 'offer'],
            ['Ibrahim Ojo', 'Funmi Ojo', 'funmi.ojo@example.com', '+234 806 100 3310', 'nursery', 'enrolled'],
            ['Maryam Sule', 'Yakubu Sule', 'yakubu.sule@example.com', '+234 806 100 3311', 'junior_secondary', 'enrolled'],
            ['Daniel Eze', 'Chika Eze', 'chika.eze@example.com', '+234 806 100 3312', 'senior_secondary', 'declined'],
        ];
        foreach ($applications as $index => [$applicant, $guardian, $email, $phone, $level, $status]) {
            $insert(
                'INSERT INTO admission_applications
                    (uuid, applicant_name, guardian_name, email, phone, level, status, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    $applicant,
                    $guardian,
                    $email,
                    $phone,
                    $level,
                    $status,
                    $index < 3 ? 'Follow up with the family this week.' : null,
                    date('Y-m-d H:i:s', strtotime('-' . (2 + $index) . ' days')),
                ]
            );
        }

        /* ---------------------------------------------------------------
           Assignments
           --------------------------------------------------------------- */
        $addAssignment = static function (
            string $className,
            string $subjectCode,
            string $title,
            string $description,
            string $dueOn
        ) use ($insert, $classIds, $subjectIds, $currentTermId, $teacherIds, $subjectDefs): int {
            $teacherKey = $subjectDefs[$subjectCode][1];

            return $insert(
                'INSERT INTO assignments
                    (uuid, class_id, subject_id, term_id, title, description, due_on, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v4(),
                    $classIds[$className],
                    $subjectIds[$subjectCode],
                    $currentTermId,
                    $title,
                    $description,
                    $dueOn,
                    $teacherIds[$teacherKey],
                ]
            );
        };

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $threeDays = date('Y-m-d', strtotime('+3 days'));
        $fourDays = date('Y-m-d', strtotime('+4 days'));
        $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));

        $mathAssignment = $addAssignment('JSS 2B', 'MTH', 'Quadratic equations — exercise 4B', 'Complete questions 1–12 in exercise 4B. Show all working.', $tomorrow);
        $addAssignment('JSS 2B', 'ISL', 'Memorise Surah Al-Mulk (verses 1–15)', 'Recite verses 1–15 to the class teacher on Thursday.', $threeDays);
        $labAssignment = $addAssignment('JSS 2B', 'BSC', 'Water filtration lab report', 'Write up the filtration experiment, including your diagram and conclusion.', $twoDaysAgo);
        $addAssignment('Primary 5A', 'MTH', 'Fractions workbook, pages 24–26', 'Complete the fraction addition and subtraction exercises.', $tomorrow);
        $addAssignment('Primary 5A', 'ARB', 'Arabic handwriting practice', 'Copy the new vocabulary into your exercise book three times.', $fourDays);

        $insert(
            'INSERT INTO assignment_submissions (assignment_id, student_id, status, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$labAssignment, $aishaId, 'submitted', date('Y-m-d H:i:s', strtotime('-3 days'))]
        );

        $pdo->commit();

        $counts = [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
            'classes' => (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
            'subjects' => (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn(),
            'results' => $resultCount,
            'attendance_records' => $attendanceRecords,
            'attendance_registers' => $attendanceRegisters,
            'fee_invoices' => $feeInvoiceCount,
            'fee_payments' => $paymentCount,
            'announcements' => count($announcementDefs),
            'admissions' => count($applications),
        ];

        return $counts;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
};
