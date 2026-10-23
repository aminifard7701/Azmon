<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(): never {
    header('Location: exams.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        http_response_code(403);
        exit('درخواست نامعتبر است.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {

        $title = trim($_POST['title'] ?? '');

        $time = filter_var(
            $_POST['time_per_question'] ?? null,
            FILTER_VALIDATE_INT
        );

        $attempts = filter_var(
            $_POST['attempts_limit'] ?? null,
            FILTER_VALIDATE_INT
        );

        $unlimited = isset($_POST['unlimited']);

        $startsAt = $_POST['starts_at'] ?? '';
        $endsAt = $_POST['ends_at'] ?? '';

        $hintEnabled = isset($_POST['hint_enabled']);

        $hintMode = $_POST['hint_mode'] ?? 'add';

        $hintSeconds = filter_var(
            $_POST['hint_extra_seconds'] ?? 3,
            FILTER_VALIDATE_INT
        );

        if ($title === '') {
            $error = 'نام آزمون الزامی است.';
        } elseif (mb_strlen($title) > 255) {
            $error = 'نام آزمون بیش از حد طولانی است.';
        } elseif ($time === false || $time < 1 || $time > 3600) {
            $error = 'زمان هر سوال باید بین ۱ تا ۳۶۰۰ ثانیه باشد.';
        } elseif (
            !$unlimited &&
            ($attempts === false || $attempts < 1)
        ) {
            $error = 'تعداد دفعات مجاز باید حداقل یک باشد.';
        } elseif (!in_array(
            $hintMode,
            ['continue', 'pause', 'add'],
            true
        )) {
            $error = 'حالت راهنمایی نامعتبر است.';
        } elseif (
            $hintSeconds === false ||
            $hintSeconds < 0 ||
            $hintSeconds > 3600
        ) {
            $error = 'زمان اضافه‌شده نامعتبر است.';
        } else {

            $start = $startsAt !== ''
                ? DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $startsAt,
                    new DateTimeZone('Asia/Tehran')
                )
                : null;

            $end = $endsAt !== ''
                ? DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $endsAt,
                    new DateTimeZone('Asia/Tehran')
                )
                : null;

            if (
                ($startsAt !== '' && !$start) ||
                ($endsAt !== '' && !$end)
            ) {
                $error = 'تاریخ واردشده معتبر نیست.';
            } elseif ($start && $end && $end <= $start) {
                $error = 'تاریخ پایان باید بعد از شروع باشد.';
            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO exams (
                        title,
                        time_per_question,
                        attempts_limit,
                        starts_at,
                        ends_at,
                        hint_enabled,
                        hint_mode,
                        hint_extra_seconds,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')
                ");

                $stmt->execute([
                    $title,
                    $time,
                    $unlimited ? null : $attempts,
                    $start?->format('Y-m-d H:i:s'),
                    $end?->format('Y-m-d H:i:s'),
                    $hintEnabled ? 1 : 0,
                    $hintMode,
                    $hintSeconds
                ]);

                redirect();
            }
        }
    }

    if ($action === 'archive') {

        $id = filter_var(
            $_POST['exam_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id && $id > 0) {

            $stmt = $pdo->prepare("
                UPDATE exams
                SET status = 'archived'
                WHERE id = ?
                AND status IN ('draft', 'active')
            ");

            $stmt->execute([$id]);
        }

        redirect();
    }

    if ($action === 'activate') {

        $id = filter_var(
            $_POST['exam_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id && $id > 0) {

            $stmt = $pdo->prepare("
                UPDATE exams
                SET status = 'active'
                WHERE id = ?
                AND status = 'draft'
            ");

            $stmt->execute([$id]);
        }

        redirect();
    }
}

$stmt = $pdo->query("
    SELECT *
    FROM exams
    WHERE status != 'trash'
    ORDER BY id DESC
");

$exams = $stmt->fetchAll();

function statusLabel(string $status): string {
    return match ($status) {
        'draft' => 'پیش‌نویس',
        'active' => 'فعال',
        'archived' => 'آرشیوشده',
        default => 'نامشخص'
    };
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>مدیریت آزمون‌ها</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #071e18;
    color: white;
    font-family: Tahoma, sans-serif;
}

header {
    background: #103b2e;
    padding: 25px;
    text-align: center;
    border-bottom: 2px solid #d6b45b;
}

h1, h2 {
    color: #e8c56a;
}

.container {
    max-width: 1100px;
    margin: auto;
    padding: 20px;
}

.card {
    background: #123b30;
    border: 1px solid #927a3b;
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 25px;
}

label {
    display: block;
    margin-top: 17px;
    color: #e9e9e9;
}

input, select {
    width: 100%;
    padding: 13px;
    margin-top: 8px;
    border: 1px solid #527367;
    background: #08271f;
    color: white;
    border-radius: 10px;
    font-size: 15px;
    font-family: inherit;
}

input[type="checkbox"] {
    width: auto;
    margin-left: 8px;
}

button {
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-family: inherit;
    font-weight: bold;
    margin-top: 12px;
}

.gold {
    background: #d6b45b;
    color: #08251c;
}

.green {
    background: #168348;
    color: white;
}

.red {
    background: #a92e38;
    color: white;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 13px;
    text-align: right;
    border-bottom: 1px solid #456558;
}

th {
    color: #e8c56a;
}

.table-container {
    overflow-x: auto;
}

.error {
    background: #582329;
    color: #ffd4d4;
    padding: 14px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.back {
    display: inline-block;
    margin-bottom: 20px;
    color: #e8c56a;
    text-decoration: none;
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}
</style>
</head>

<body>

<header>
    <h1>مدیریت آزمون‌ها</h1>
    <p>سامانه آزمون پیامبران</p>
</header>

<div class="container">

<a href="dashboard.php" class="back">
    بازگشت به داشبورد
</a>

<?php if ($error): ?>
    <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">

<h2>ایجاد آزمون جدید</h2>

<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($_SESSION['csrf_token']) ?>"
>

<input type="hidden" name="action" value="create">

<label>نام آزمون</label>

<input
    type="text"
    name="title"
    placeholder="مثلاً زندگی پیامبران"
    maxlength="255"
    required
>

<label>زمان هر سوال (ثانیه)</label>

<input
    type="number"
    name="time_per_question"
    value="10"
    min="1"
    max="3600"
    required
>

<label>تعداد دفعات مجاز شرکت</label>

<input
    type="number"
    name="attempts_limit"
    value="1"
    min="1"
>

<label>
    <input type="checkbox" name="unlimited">
    شرکت نامحدود
</label>

<label>تاریخ و ساعت شروع</label>

<input type="datetime-local" name="starts_at">

<label>تاریخ و ساعت پایان</label>

<input type="datetime-local" name="ends_at">

<label>
    <input type="checkbox" name="hint_enabled">
    فعال‌کردن قابلیت راهنمایی
</label>

<label>رفتار زمان‌سنج هنگام راهنمایی</label>

<select name="hint_mode">

    <option value="continue">
        ادامه زمان
    </option>

    <option value="pause">
        توقف زمان
    </option>

    <option value="add" selected>
        اضافه‌کردن زمان
    </option>

</select>

<label>مقدار زمان اضافه‌شده (ثانیه)</label>

<input
    type="number"
    name="hint_extra_seconds"
    value="3"
    min="0"
    max="3600"
>

<button type="submit" class="gold">
    ذخیره آزمون
</button>

</form>

</div>

<div class="card">

<h2>فهرست آزمون‌ها</h2>

<div class="table-container">

<table>

<thead>
<tr>
    <th>نام آزمون</th>
    <th>زمان هر سوال</th>
    <th>دفعات مجاز</th>
    <th>وضعیت</th>
    <th>عملیات</th>
</tr>
</thead>

<tbody>

<?php foreach ($exams as $exam): ?>

<tr>

<td><?= e($exam['title']) ?></td>

<td>
    <?= (int)$exam['time_per_question'] ?>
    ثانیه
</td>

<td>
    <?= $exam['attempts_limit'] === null
        ? 'نامحدود'
        : (int)$exam['attempts_limit'] ?>
</td>

<td><?= statusLabel($exam['status']) ?></td>

<td>

<div class="actions">

<?php if ($exam['status'] === 'draft'): ?>

<form method="POST">

<input type="hidden" name="action" value="activate">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($_SESSION['csrf_token']) ?>"
>

<input
    type="hidden"
    name="exam_id"
    value="<?= (int)$exam['id'] ?>"
>

<button class="green">
    فعال‌سازی
</button>

</form>

<?php endif; ?>

<?php if ($exam['status'] !== 'archived'): ?>

<form method="POST"
      onsubmit="return confirm('آزمون آرشیو شود؟')">

<input type="hidden" name="action" value="archive">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($_SESSION['csrf_token']) ?>"
>

<input
    type="hidden"
    name="exam_id"
    value="<?= (int)$exam['id'] ?>"
>

<button class="red">
    انتقال به آرشیو
</button>

</form>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php if (!$exams): ?>

<tr>
<td colspan="5">
    هنوز آزمونی ایجاد نشده است.
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

</body>
</html>
<?php

require_once __DIR__ . '/config.php';

$examId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$examId) {
    http_response_code(404);
    exit('آزمون پیدا نشد.');
}

$stmt = $pdo->prepare("
    SELECT
        id,
        title,
        theme_background,
        theme_primary,
        theme_accent
    FROM exams
    WHERE id = ?
    AND status = 'active'
");

$stmt->execute([$examId]);

$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    exit('آزمون موردنظر فعال نیست.');
}

function safeColor(
    string $color,
    string $fallback
): string {
    return preg_match(
        '/^#[0-9a-fA-F]{6}$/',
        $color
    ) ? $color : $fallback;
}

$background = safeColor(
    $exam['theme_background'],
    '#071e18'
);

$primary = safeColor(
    $exam['theme_primary'],
    '#d6b45b'
);

$accent = safeColor(
    $exam['theme_accent'],
    '#168348'
);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    <?= htmlspecialchars(
        $exam['title'],
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</title>

<style>
:root {
    --background: <?= $background ?>;
    --primary: <?= $primary ?>;
    --accent: <?= $accent ?>;
}

body {
    margin: 0;
    background: var(--background);
    color: white;
    font-family: Tahoma, sans-serif;
}

.container {
    max-width: 650px;
    margin: auto;
    padding: 20px;
}

.card {
    background: #123b30;
    border: 1px solid var(--primary);
    border-radius: 18px;
    padding: 25px;
    margin-top: 25px;
}

h1 {
    color: var(--primary);
}

button {
    background: var(--primary);
    color: #071e18;
    border: none;
    border-radius: 12px;
    padding: 15px;
    width: 100%;
    font-size: 16px;
    cursor: pointer;
}
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>
            <?= htmlspecialchars(
                $exam['title'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h1>

        <p>
            برای شرکت در آزمون، اطلاعات خود را ثبت کنید.
        </p>

        <form id="registrationForm">

            <input
                type="hidden"
                name="exam_id"
                value="<?= (int)$exam['id'] ?>"
            >

            <input
                type="text"
                name="first_name"
                placeholder="نام"
                required
            >

            <input
                type="text"
                name="last_name"
                placeholder="نام خانوادگی"
                required
            >

            <input
                type="tel"
                name="phone"
                placeholder="شماره تماس"
                required
            >

            <button type="submit">
                شروع آزمون
            </button>

        </form>

    </div>

</div>

</body>
</html>
