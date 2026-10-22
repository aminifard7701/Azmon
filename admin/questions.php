<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $text): string {
    return htmlspecialchars(
        $text,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectToExam(int $examId): never {
    header(
        'Location: questions.php?exam_id=' . $examId
    );
    exit;
}

$error = '';
$success = '';

$examId = filter_var(
    $_GET['exam_id'] ?? $_POST['exam_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$examId || $examId < 1) {
    $examId = 0;
}

$stmt = $pdo->query(
    "SELECT id, title, status
     FROM exams
     WHERE status != 'trash'
     ORDER BY id DESC"
);

$exams = $stmt->fetchAll();

$selectedExam = null;

if ($examId) {
    $stmt = $pdo->prepare(
        "SELECT * FROM exams WHERE id = ?"
    );

    $stmt->execute([$examId]);
    $selectedExam = $stmt->fetch();
}

$canEdit = $selectedExam &&
    $selectedExam['status'] === 'draft';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        http_response_code(403);
        exit('درخواست نامعتبر است.');
    }

    if (!$canEdit) {
        http_response_code(403);
        exit('ویرایش سوالات این آزمون مجاز نیست.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {

        $questionId = filter_var(
            $_POST['question_id'] ?? 0,
            FILTER_VALIDATE_INT
        );

        $questionId = $questionId ?: 0;

        $text = trim(
            $_POST['question_text'] ?? ''
        );

        $type = $_POST['question_type'] ?? '';

        $correct = trim(
            $_POST['correct_answer'] ?? ''
        );

        $hintEnabled = isset(
            $_POST['hint_enabled']
        );

        $hintText = trim(
            $_POST['hint_text'] ?? ''
        );

        $options = array_map(
            'trim',
            $_POST['options'] ?? []
        );

        $accepted = preg_split(
            '/\r\n|\r|\n/',
            $_POST['accepted_answers'] ?? ''
        );

        $accepted = array_values(
            array_unique(
                array_filter(
                    array_map('trim', $accepted),
                    fn($value) => $value !== ''
                )
            )
        );

        if ($text === '') {
            $error = 'متن سوال را وارد کنید.';
        } elseif (!in_array(
            $type,
            ['multiple', 'text'],
            true
        )) {
            $error = 'نوع سوال نامعتبر است.';
        } elseif ($correct === '') {
            $error = 'پاسخ صحیح را مشخص کنید.';
        } elseif (
            $hintEnabled &&
            $hintText === ''
        ) {
            $error = 'متن راهنمایی را وارد کنید.';
        } elseif (
            $type === 'multiple' &&
            (
                count($options) !== 4 ||
                in_array('', $options, true) ||
                count(array_unique($options)) !== 4 ||
                !in_array($correct, $options, true)
            )
        ) {
            $error = 'چهار گزینه متفاوت وارد کنید و پاسخ صحیح را از میان آن‌ها انتخاب کنید.';
        } elseif (
            $type === 'text' &&
            count($accepted) > 50
        ) {
            $error = 'حداکثر ۵۰ پاسخ قابل‌قبول ثبت کنید.';
        } else {

            try {

                $pdo->beginTransaction();

                if ($questionId > 0) {

                    $stmt = $pdo->prepare(
                        "SELECT id
                         FROM questions
                         WHERE id = ?
                         AND exam_id = ?
                         FOR UPDATE"
                    );

                    $stmt->execute([
                        $questionId,
                        $examId
                    ]);

                    if (!$stmt->fetch()) {
                        throw new RuntimeException(
                            'سوال موردنظر پیدا نشد.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE questions
                         SET question_text = ?,
                             question_type = ?,
                             correct_answer = ?,
                             hint_text = ?,
                             hint_enabled = ?
                         WHERE id = ?
                         AND exam_id = ?"
                    );

                    $stmt->execute([
                        $text,
                        $type,
                        $correct,
                        $hintEnabled ? $hintText : null,
                        $hintEnabled ? 1 : 0,
                        $questionId,
                        $examId
                    ]);

                    $stmt = $pdo->prepare(
                        "DELETE FROM question_options
                         WHERE question_id = ?"
                    );

                    $stmt->execute([$questionId]);

                    $stmt = $pdo->prepare(
                        "DELETE FROM accepted_answers
                         WHERE question_id = ?"
                    );

                    $stmt->execute([$questionId]);

                } else {

                    $stmt = $pdo->prepare(
                        "INSERT INTO questions
                        (
                            exam_id,
                            question_text,
                            question_type,
                            correct_answer,
                            hint_text,
                            hint_enabled
                        )
                        VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $examId,
                        $text,
                        $type,
                        $correct,
                        $hintEnabled ? $hintText : null,
                        $hintEnabled ? 1 : 0
                    ]);

                    $questionId = (int)
                        $pdo->lastInsertId();
                }

                if ($type === 'multiple') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO question_options
                        (question_id, option_text)
                        VALUES (?, ?)"
                    );

                    foreach ($options as $option) {
                        $stmt->execute([
                            $questionId,
                            $option
                        ]);
                    }

                } else {

                    $stmt = $pdo->prepare(
                        "INSERT INTO accepted_answers
                        (question_id, answer_text)
                        VALUES (?, ?)"
                    );

                    $allAnswers = array_unique(
                        array_merge(
                            [$correct],
                            $accepted
                        )
                    );

                    foreach ($allAnswers as $answer) {
                        $stmt->execute([
                            $questionId,
                            $answer
                        ]);
                    }
                }

                $pdo->commit();

                redirectToExam($examId);

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $error = 'خطا در ذخیره سوال.';
            }
        }
    }

    if ($action === 'delete') {

        $questionId = filter_var(
            $_POST['question_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($questionId) {

            $stmt = $pdo->prepare(
                "DELETE FROM questions
                 WHERE id = ?
                 AND exam_id = ?"
            );

            $stmt->execute([
                $questionId,
                $examId
            ]);
        }

        redirectToExam($examId);
    }
}

$questions = [];

if ($selectedExam) {

    $stmt = $pdo->prepare(
        "SELECT *
         FROM questions
         WHERE exam_id = ?
         ORDER BY id DESC"
    );

    $stmt->execute([$examId]);

    $questions = $stmt->fetchAll();

    foreach ($questions as &$question) {

        $stmt = $pdo->prepare(
            "SELECT option_text
             FROM question_options
             WHERE question_id = ?
             ORDER BY id"
        );

        $stmt->execute([$question['id']]);

        $question['options'] =
            $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare(
            "SELECT answer_text
             FROM accepted_answers
             WHERE question_id = ?
             ORDER BY id"
        );

        $stmt->execute([$question['id']]);

        $question['accepted'] =
            $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    unset($question);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>مدیریت سوالات</title>

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
    max-width: 900px;
    margin: auto;
    padding: 20px;
}

.card {
    background: #123b30;
    padding: 24px;
    margin-bottom: 22px;
    border: 1px solid #927a3b;
    border-radius: 17px;
}

label {
    display: block;
    margin-top: 17px;
}

input,
textarea,
select {
    width: 100%;
    padding: 14px;
    margin-top: 8px;
    background: #08271f;
    border: 1px solid #527367;
    border-radius: 10px;
    color: white;
    font-size: 15px;
    font-family: inherit;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

input[type="checkbox"] {
    width: auto;
}

button {
    padding: 13px 20px;
    margin-top: 12px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: bold;
    font-family: inherit;
}

.gold {
    background: #d6b45b;
    color: #08251c;
}

.red {
    background: #a92e38;
    color: white;
}

.green {
    background: #168348;
    color: white;
}

.hidden {
    display: none;
}

.question-item {
    padding: 18px;
    margin-top: 15px;
    border: 1px solid #456558;
    border-radius: 12px;
}

.question-item p {
    line-height: 2;
}

.error {
    padding: 15px;
    background: #642b30;
    border-radius: 10px;
    margin-bottom: 20px;
}

.info {
    color: #b8c9bf;
    line-height: 2;
}

.back {
    display: inline-block;
    color: #e8c56a;
    margin-bottom: 20px;
    text-decoration: none;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}

</style>

</head>

<body>

<header>

<h1>مدیریت سوالات</h1>

<p>سامانه آزمون پیامبران</p>

</header>

<div class="container">

<a class="back" href="dashboard.php">
    بازگشت به داشبورد
</a>

<div class="card">

<h2>انتخاب آزمون</h2>

<form method="GET">

<select name="exam_id" required>

<option value="">
    انتخاب کنید
</option>

<?php foreach ($exams as $exam): ?>

<option
    value="<?= (int)$exam['id'] ?>"
    <?= $examId === (int)$exam['id']
        ? 'selected'
        : '' ?>
>
    <?= escape($exam['title']) ?>
</option>

<?php endforeach; ?>

</select>

<button class="gold">
    مشاهده سوالات
</button>

</form>

</div>

<?php if ($error): ?>

<div class="error">
    <?= escape($error) ?>
</div>

<?php endif; ?>

<?php if ($selectedExam): ?>

<div class="card">

<h2>
    <?= escape($selectedExam['title']) ?>
</h2>

<p class="info">
    تعداد سوالات ثبت‌شده:
    <?= count($questions) ?>
</p>

<?php if (!$canEdit): ?>

<p class="info">
    این آزمون در وضعیت پیش‌نویس نیست.
    برای حفظ نتایج، ویرایش سوالات آن غیرفعال است.
</p>

<?php endif; ?>

</div>

<?php if ($canEdit): ?>

<div class="card">

<h2 id="formTitle">افزودن سوال جدید</h2>

<form method="POST" id="questionForm">

<input
    type="hidden"
    name="csrf_token"
    value="<?= escape($_SESSION['csrf_token']) ?>"
>

<input
    type="hidden"
    name="exam_id"
    value="<?= $examId ?>"
>

<input
    type="hidden"
    name="action"
    value="save"
>

<input
    type="hidden"
    name="question_id"
    id="questionId"
    value="0"
>

<label>متن سوال</label>

<textarea
    name="question_text"
    id="questionText"
    required
></textarea>

<label>نوع سوال</label>

<select
    name="question_type"
    id="questionType"
    onchange="changeQuestionType()"
>

<option value="multiple">
    چهارگزینه‌ای
</option>

<option value="text">
    پاسخ تایپی
</option>

</select>

<div id="multipleFields">

<h2>گزینه‌های پاسخ</h2>

<?php for ($i = 0; $i < 4; $i++): ?>

<label>
    گزینه <?= $i + 1 ?>
</label>

<input
    type="text"
    class="option-input"
    name="options[]"
    placeholder="متن گزینه"
>

<?php endfor; ?>

<p class="info">
    پس از واردکردن گزینه‌ها، متن گزینه صحیح
    را در کادر پاسخ صحیح وارد کنید.
</p>

</div>

<div id="textFields" class="hidden">

<label>پاسخ‌های قابل‌قبول</label>

<textarea
    name="accepted_answers"
    id="acceptedAnswers"
    placeholder="هر پاسخ را در یک خط بنویسید"
></textarea>

</div>

<label>پاسخ صحیح</label>

<input
    type="text"
    name="correct_answer"
    id="correctAnswer"
    required
>

<label>

<input
    type="checkbox"
    name="hint_enabled"
    id="hintEnabled"
    onchange="toggleHint()"
>

فعال‌کردن راهنمایی برای این سوال

</label>

<div id="hintFields" class="hidden">

<label>متن راهنمایی</label>

<textarea
    name="hint_text"
    id="hintText"
></textarea>

</div>

<button class="gold" type="submit">
    ذخیره سوال
</button>

<button
    class="green"
    type="button"
    onclick="resetForm()"
>
    سوال جدید
</button>

</form>

</div>

<?php endif; ?>

<div class="card">

<h2>سوالات ثبت‌شده</h2>

<?php foreach ($questions as $index => $question): ?>

<div class="question-item">

<h3>
    سوال <?= $index + 1 ?>
</h3>

<p>
    <?= nl2br(escape($question['question_text'])) ?>
</p>

<p>
    نوع سوال:
    <?= $question['question_type'] === 'multiple'
        ? 'چهارگزینه‌ای'
        : 'تایپی' ?>
</p>

<?php if ($question['question_type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>

<p>
    <?= escape($option) ?>
</p>

<?php endforeach; ?>

<?php endif; ?>

<p style="color:#52dd99">

    پاسخ صحیح:
    <?= escape($question['correct_answer']) ?>

</p>

<?php if ($question['hint_enabled']): ?>

<p style="color:#e8c56a">

    راهنمایی:
    <?= escape($question['hint_text'] ?? '') ?>

</p>

<?php endif; ?>

<?php if ($canEdit): ?>

<div class="actions">

<button
    class="gold"
    type="button"
    onclick="editQuestion(
        <?= (int)$question['id'] ?>
    )"
>
    ویرایش
</button>

<form
    method="POST"
    onsubmit="return confirm('این سوال حذف شود؟')"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= escape($_SESSION['csrf_token']) ?>"
>

<input
    type="hidden"
    name="exam_id"
    value="<?= $examId ?>"
>

<input
    type="hidden"
    name="question_id"
    value="<?= (int)$question['id'] ?>"
>

<input
    type="hidden"
    name="action"
    value="delete"
>

<button class="red">
    حذف سوال
</button>

</form>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php if (!$questions): ?>

<p class="info">
    هنوز سوالی برای این آزمون ثبت نشده است.
</p>

<?php endif; ?>

</div>

<?php endif; ?>

</div>

<script>

const questions = <?= json_encode(
    $questions,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

function changeQuestionType() {

    const type =
        document.getElementById('questionType').value;

    document.getElementById('multipleFields')
        .classList.toggle(
            'hidden',
            type !== 'multiple'
        );

    document.getElementById('textFields')
        .classList.toggle(
            'hidden',
            type !== 'text'
        );
}

function toggleHint() {

    const enabled =
        document.getElementById('hintEnabled').checked;

    document.getElementById('hintFields')
        .classList.toggle('hidden', !enabled);
}

function editQuestion(id) {

    const question = questions.find(
        q => Number(q.id) === Number(id)
    );

    if (!question) return;

    document.getElementById('questionId').value =
        question.id;

    document.getElementById('questionText').value =
        question.question_text;

    document.getElementById('questionType').value =
        question.question_type;

    document.getElementById('correctAnswer').value =
        question.correct_answer;

    document.getElementById('hintEnabled').checked =
        Number(question.hint_enabled) === 1;

    document.getElementById('hintText').value =
        question.hint_text || '';

    document.getElementById('acceptedAnswers').value =
        question.accepted.join('\n');

    const inputs =
        document.querySelectorAll('.option-input');

    inputs.forEach((input, index) => {
        input.value = question.options[index] || '';
    });

    changeQuestionType();
    toggleHint();

    document.getElementById('formTitle').textContent =
        'ویرایش سوال';

    document.getElementById('questionForm')
        .scrollIntoView({
            behavior: 'smooth'
        });
}

function resetForm() {

    document.getElementById('questionForm').reset();

    document.getElementById('questionId').value = 0;

    document.getElementById('formTitle').textContent =
        'افزودن سوال جدید';

    changeQuestionType();
    toggleHint();
}

changeQuestionType();
toggleHint();

</script>

</body>
</html>
