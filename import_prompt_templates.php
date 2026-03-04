<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

main();

function main(): void
{
    $path = __DIR__ . '/prompt.txt';
    if (!is_file($path)) {
        throw new RuntimeException('prompt.txt が見つかりません。');
    }

    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('prompt.txt の読み込みに失敗しました。');
    }

    $templates = parsePromptFile($content);
    if ($templates === []) {
        throw new RuntimeException('prompt.txt からテンプレートを抽出できませんでした。');
    }

    $pdo = createPdo();
    $pdo->beginTransaction();

    try {
        $curriculumMap = fetchCurriculumMap($pdo);
        $inserted = 0;

        foreach ($templates as $curriculumName => $templateBody) {
            if (!isset($curriculumMap[$curriculumName])) {
                throw new RuntimeException('curriculum に存在しない名前です: ' . $curriculumName);
            }

            $curriculumId = (int)$curriculumMap[$curriculumName];

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM curriculum_prompt_template WHERE curriculum_id = :curriculum_id');
            $stmt->execute(['curriculum_id' => $curriculumId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                continue;
            }

            $insert = $pdo->prepare(
                'INSERT INTO curriculum_prompt_template '
                . '(curriculum_id, version, template_body, status, notes, created_by, updated_by) '
                . 'VALUES (:curriculum_id, 1, :template_body, :status, :notes, :created_by, :updated_by)'
            );
            $insert->execute([
                'curriculum_id' => $curriculumId,
                'template_body' => trim($templateBody),
                'status' => 'active',
                'notes' => 'initial import from prompt.txt',
                'created_by' => 'import_prompt_templates.php',
                'updated_by' => 'import_prompt_templates.php',
            ]);

            $inserted++;
        }

        $pdo->commit();
        fwrite(STDOUT, "完了: {$inserted} 件を登録しました。\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function createPdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * @return array<string, int>
 */
function fetchCurriculumMap(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, curriculum_name FROM curriculum');
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['curriculum_name'] ?? ''));
        $id = (int)($row['id'] ?? 0);
        if ($name === '' || $id <= 0) {
            continue;
        }

        $map[$name] = $id;
    }

    return $map;
}

/**
 * @return array<string, string>
 */
function parsePromptFile(string $content): array
{
    $lines = preg_split('/\R/u', $content);
    if (!is_array($lines)) {
        return [];
    }

    $result = [];
    $currentKey = null;
    $buffer = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^week\d+(_week\d+)?$/', $trimmed) === 1) {
            if ($currentKey !== null && $buffer !== []) {
                $result[$currentKey] = trim(implode("\n", $buffer));
            }

            $currentKey = $trimmed;
            $buffer = [];
            continue;
        }

        if ($currentKey !== null) {
            $buffer[] = $line;
        }
    }

    if ($currentKey !== null && $buffer !== []) {
        $result[$currentKey] = trim(implode("\n", $buffer));
    }

    return $result;
}
