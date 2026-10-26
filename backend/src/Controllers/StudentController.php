<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Validator;
use AlFalaah\Services\Academics;
use AlFalaah\Services\Assignments;
use AlFalaah\Services\Attendance;
use AlFalaah\Services\Fees;
use AlFalaah\Services\Results;
use AlFalaah\Services\Students;
use AlFalaah\Services\Timetable;

final class StudentController
{
    public static function index(): never
    {
        $user = Auth::requireUser();
        [$page, $perPage] = Validator::pagination(
            Request::queryScalar('page'),
            Request::queryScalar('per_page')
        );

        $search = Validator::string(Request::query('search'), 'search', 0, 100, false) ?? '';
        $status = Validator::enum(
            Request::query('status'),
            'status',
            ['active', 'graduated', 'withdrawn'],
            false
        );
        $classUuid = Validator::uuid(Request::query('class_id'), 'class_id', false);
        $restrict = null;

        if ($user['role'] === 'teacher') {
            $restrict = Students::teacherClassIds((int) $user['id']);
        }

        if ($classUuid !== null) {
            $class = Academics::classByUuid($classUuid);
            if ($restrict !== null && !in_array((int) $class['id'], $restrict, true)) {
                throw HttpException::notFound('That class was not found or is not available to your account.');
            }
        }

        $result = Students::paginate($search, $classUuid, $status, $page, $perPage, $restrict);
        $rows = array_map(
            static fn (array $row): array => Students::present($row, false),
            $result['rows']
        );

        Response::ok($rows, 200, [
            'pagination' => Database::paginate($result['total'], $page, $perPage),
        ]);
    }

    public static function show(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);

        Response::ok(Students::present($student));
    }

    public static function results(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $publishedOnly = in_array($user['role'], ['student', 'parent', 'teacher'], true);
        $term = self::resolveTerm($publishedOnly, (int) $student['id']);

        Response::ok(Results::summary((int) $student['id'], (int) $term['id'], (int) $student['class_id']));
    }

    public static function attendance(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $term = self::resolveTerm(false, (int) $student['id']);

        Response::ok([
            'term' => Academics::presentTerm($term),
            'student' => Students::present($student, false),
            'summary' => Attendance::summary((int) $student['id'], (int) $term['id']),
        ]);
    }

    public static function fees(array $params): never
    {
        $user = Auth::requireUser();
        if ($user['role'] === 'teacher') {
            throw HttpException::forbidden('Fee records are only available to the family and school administrators.');
        }

        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $term = self::resolveTerm(false, (int) $student['id']);

        Response::ok([
            'term' => Academics::presentTerm($term),
            'student' => Students::present($student, false),
            'summary' => Fees::summary((int) $student['id'], (int) $term['id']),
        ]);
    }

    public static function timetable(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $term = self::resolveTerm(false, (int) $student['id']);

        Response::ok([
            'term' => Academics::presentTerm($term),
            'class' => [
                'id' => $student['class_uuid'],
                'name' => $student['class_name'],
                'level' => $student['class_level'],
            ],
            'timetable' => Timetable::forClass((int) $student['class_id'], (int) $term['id']),
        ]);
    }

    public static function assignments(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $term = self::resolveTerm(false, (int) $student['id']);

        Response::ok([
            'term' => Academics::presentTerm($term),
            'assignments' => Assignments::forStudent(
                (int) $student['id'],
                (int) $student['class_id'],
                (int) $term['id'],
                50
            ),
        ]);
    }

    public static function report(array $params): never
    {
        $user = Auth::requireUser();
        $student = Students::requireViewable(Validator::uuid($params['id'] ?? null, 'id'), $user);
        $currentTerm = Academics::currentTerm();
        $published = Academics::latestPublishedTerm((int) $student['id']);

        $result = null;
        if ($published !== null) {
            $result = Results::summary((int) $student['id'], (int) $published['id'], (int) $student['class_id']);
        } elseif ($user['role'] === 'admin') {
            $result = Results::summary(
                (int) $student['id'],
                (int) $currentTerm['id'],
                (int) $student['class_id']
            );
        }

        Response::ok([
            'student' => Students::present($student),
            'current_term' => Academics::presentTerm($currentTerm),
            'results' => $result,
            'attendance' => Attendance::summary((int) $student['id'], (int) $currentTerm['id']),
            'fees' => $user['role'] === 'teacher'
                ? null
                : Fees::summary((int) $student['id'], (int) $currentTerm['id']),
            'assignments' => Assignments::forStudent(
                (int) $student['id'],
                (int) $student['class_id'],
                (int) $currentTerm['id']
            ),
        ]);
    }

    public static function children(): never
    {
        $user = Auth::requireRole('parent');
        $children = Students::childrenOf((int) $user['id']);

        if ($children === []) {
            throw HttpException::notFound('No student records are linked to this guardian account.');
        }

        Response::ok(array_map(
            static fn (array $child): array => Students::present($child),
            $children
        ));
    }

    private static function resolveTerm(bool $publishedOnly, int $studentId): array
    {
        $uuid = Validator::uuid(Request::query('term_id'), 'term_id', false);

        if ($uuid === null) {
            if ($publishedOnly) {
                $term = Academics::latestPublishedTerm($studentId);
                if ($term === null) {
                    throw HttpException::notFound('No published results are available for this student yet.');
                }

                return $term;
            }

            return Academics::currentTerm();
        }

        $term = Academics::termByUuid($uuid);
        if ($publishedOnly && $term['results_published_at'] === null) {
            throw HttpException::forbidden('Results for this term have not been published.');
        }

        return $term;
    }
}
