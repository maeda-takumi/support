<?php

declare(strict_types=1);

const DEFAULT_REVIEW_PROMPT_TEMPLATE = "あなたは学習カリキュラムのメンターです。受講者の提出内容を読み、努力を認めつつ改善点を具体的に示す日本語の総評を120〜220文字で1つ作成してください。箇条書きは禁止です。\n\n{{submission}}";
const PROMPT_TEMPLATE_MAX_LENGTH = 20000;

function buildReviewPrompt(PDO $pdo, string $curriculumId, string $answer1, string $answer2): string
{
    $submission = buildSubmissionText($answer1, $answer2);
    $template = fetchActivePromptTemplate($pdo, $curriculumId);

    if ($template === null || trim($template) === '') {
        $template = DEFAULT_REVIEW_PROMPT_TEMPLATE;
    }

    validatePromptTemplate($template);

    if (strpos($template, '{{submission}}') !== false) {
        return str_replace('{{submission}}', $submission, $template);
    }

    return rtrim($template) . "\n\n【提出内容】\n" . $submission;
}

function buildSubmissionText(string $answer1, string $answer2): string
{
    $submission = "answer_1:\n" . $answer1;
    if (trim($answer2) !== '') {
        $submission .= "\n\nanswer_2:\n" . $answer2;
    }

    return $submission;
}

function fetchActivePromptTemplate(PDO $pdo, string $curriculumId): ?string
{
    $sql = "SELECT template_body
            FROM curriculum_prompt_template
            WHERE curriculum_id = :curriculum_id
              AND status = 'active'
            ORDER BY version DESC, updated_at DESC
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['curriculum_id' => $curriculumId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    $template = $row['template_body'] ?? null;
    return is_string($template) ? $template : null;
}

function validatePromptTemplate(string $template): void
{
    if (mb_strlen($template) > PROMPT_TEMPLATE_MAX_LENGTH) {
        throw new RuntimeException('プロンプトテンプレートが長すぎます。');
    }
}
