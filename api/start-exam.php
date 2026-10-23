<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config.php';

function respond(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'روش درخواست مجاز نیست.'
    ]);
}

$examId = filter_var(
    $_POST['exam_id'] ?? null,
    FILTER_VALIDATE_INT
);

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

$phone = strtr(
    $phone,
    '۰۱۲۳۴۵۶۷۸۹',
    '0123456789'
);

$phone = strtr(
    $phone,
    '٠١٢٣٤٥٦٧٨٩',
    '0123456789'
);

if (!$examId || $examId < 1) {
    respond(422, [
        'success' => false,
        'message' => 'آزمون معتبر نیست.'
    ]);
}

if (
    $firstName === '' ||
    $lastName === '' ||
    mb_strlen($firstName) > 100 ||
    mb_strlen($lastName) > 100
) {
    respond(422, [
        'success' => false,
        'message' => 'نام و نام خانوادگی معتبر وارد کنید.'
    ]);
}

if (!preg_match('/^09[0-9]{9}$/', $phone)) {
    respond(422, [
        'success' => false,
        'message' => 'شماره تماس معتبر وارد کنید.'
    ]);
}

try {

    $pdo->beginTransaction();

    /*
     * قفل‌کردن رکورد آزمون برای جلوگیری
     * از ثبت هم‌زمان نوبت‌های اضافی.
     */

    $stmt = $pdo->prepare("
        SELECT *
        FROM exams
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$examId]);

    $exam = $stmt->fetch();

    if (!$exam || $exam['status'] !== 'active') {

        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'این آزمون فعال نیست.'
        ]);
    }

    /*
     * زمان‌های آزمون بر اساس ساعت ایران
     * در پایگاه داده ثبت شده‌اند.
     */

    $now = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Tehran')
    );

    $currentTime = $now->format('Y-m-d H:i:s');

    if (
        $exam['starts_at'] !== null &&
        $currentTime < $exam['starts_at']
    ) {

        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'آزمون هنوز آغاز نشده است.'
        ]);
    }

    if (
        $exam['ends_at'] !== null &&
        $currentTime >= $exam['ends_at']
    ) {

        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'مهلت شرکت در آزمون پایان یافته است.'
        ]);
    }

    /*
     * پیدا کردن شرکت‌کننده
     */

    $stmt = $pdo->prepare("
        SELECT id
        FROM participants
        WHERE phone = ?
    ");

    $stmt->execute([$phone]);

    $participant = $stmt->fetch();

    if (!$participant) {

        $stmt = $pdo->prepare("
            INSERT INTO participants
            (first_name, last_name, phone)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id)
        ");

        $stmt->execute([
            $firstName,
            $lastName,
            $phone
        ]);

        $participantId = (int) $pdo->lastInsertId();

    } else {

        $participantId = (int) $participant['id'];
    }

    /*
     * بررسی تعداد دفعات شرکت
     */

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attempts
        WHERE exam_id = ?
        AND participant_id = ?
    ");

    $stmt->execute([
        $examId,
        $participantId
    ]);

    $usedAttempts = (int) $stmt->fetchColumn();

    $limit = $exam['attempts_limit'];

    if (
        $limit !== null &&
        $usedAttempts >= (int) $limit
    ) {

        $pdo->rollBack();

        respond(403, [
            'success' => false,
            'message' => 'تعداد دفعات مجاز شرکت شما در این آزمون به پایان رسیده است.'
        ]);
    }

    /*
     * دریافت سوالات آزمون
     */

    $stmt = $pdo->prepare("
        SELECT id
        FROM questions
        WHERE exam_id = ?
    ");

    $stmt->execute([$examId]);

    $questionIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    if (count($questionIds) === 0) {

        $pdo->rollBack();

        respond(422, [
            'success' => false,
            'message' => 'هنوز سوالی برای این آزمون ثبت نشده است.'
        ]);
    }

    /*
     * ترتیب تصادفی سوالات
     */

    shuffle($questionIds);

    /*
     * ایجاد نوبت شرکت
     */

    $stmt = $pdo->prepare("
        INSERT INTO attempts
        (
            exam_id,
            participant_id,
            status
        )
        VALUES (?, ?, 'in_progress')
    ");

    $stmt->execute([
        $examId,
        $participantId
    ]);

    $attemptId = (int) $pdo->lastInsertId();

    /*
     * ثبت ترتیب سوالات مخصوص این نوبت
     */

    $stmt = $pdo->prepare("
        INSERT INTO attempt_questions
        (
            attempt_id,
            question_id,
            question_order
        )
        VALUES (?, ?, ?)
    ");

    foreach ($questionIds as $index => $questionId) {

        $stmt->execute([
            $attemptId,
            $questionId,
            $index + 1
        ]);
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'ثبت‌نام با موفقیت انجام شد.',
        'attempt_id' => $attemptId,
        'exam_title' => $exam['title'],
        'total_questions' => count($questionIds),
        'time_per_question' => (int) $exam['time_per_question']
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'خطایی در ثبت‌نام رخ داده است.'
    ]);
}
