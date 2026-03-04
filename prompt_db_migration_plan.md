# Curriculum総評プロンプトのDB移行設計

## 1) テーブル定義案

既存の `curriculum` テーブル（`id`, `curriculum_name`）を親として、プロンプト本文をバージョン管理できる子テーブルを追加します。

```sql
CREATE TABLE `curriculum_prompt_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11) NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `template_body` LONGTEXT NOT NULL,
  `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_curriculum_version` (`curriculum_id`, `version`),
  KEY `idx_curriculum_status_updated` (`curriculum_id`, `status`, `updated_at`),
  CONSTRAINT `fk_prompt_curriculum`
    FOREIGN KEY (`curriculum_id`) REFERENCES `curriculum` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 運用ルール

- `status = active` は **1 curriculumにつき1件のみ** にする。
  - アプリ側でトランザクション更新（旧active→archived、新版→active）
  - MariaDB 10.5では「部分ユニークインデックス」がないため、DB制約だけで完全担保しにくい。
- 更新は必ず新規 `version` を作る（上書きしない）。
- APIは `status='active'` の最新版を読む。

## 2) `prompt.txt` からDBへ移行する手順

`prompt.txt` の構造は「先頭行に curriculum_name、以降に本文」の繰り返しなので、そのまま初期データ投入に使えます。

### 手順A: 事前確認

1. `curriculum` に対象の `curriculum_name` が存在することを確認する。
2. `prompt.txt` の見出し（例: `week1_week2`）と `curriculum.curriculum_name` の一致を確認する。
3. 文字コードを UTF-8 に統一する。

確認SQL:

```sql
SELECT id, curriculum_name
FROM curriculum
ORDER BY id;
```

### 手順B: 新テーブル作成

上記DDLを実行して `curriculum_prompt_template` を作成。

### 手順C: 1回限りの投入スクリプトを実行

1. `prompt.txt` を「見出しごと」に分割
2. 見出し→`curriculum.id` を解決
3. `version=1`, `status='active'` でINSERT

投入時のINSERT例:

```sql
INSERT INTO curriculum_prompt_template
  (curriculum_id, version, template_body, status, notes, created_by, updated_by)
VALUES
  (?, 1, ?, 'active', 'initial import from prompt.txt', 'migration_script', 'migration_script');
```

### 手順D: 検証

```sql
-- curriculumごとのactive件数チェック（0 or 1が理想）
SELECT curriculum_id, COUNT(*) AS active_count
FROM curriculum_prompt_template
WHERE status = 'active'
GROUP BY curriculum_id;

-- 先頭100文字で投入確認
SELECT c.curriculum_name, p.version, p.status, LEFT(p.template_body, 100) AS preview
FROM curriculum_prompt_template p
JOIN curriculum c ON c.id = p.curriculum_id
ORDER BY c.id, p.version DESC;
```

### 手順E: ロールバック方針

- 誤投入時は対象 `curriculum_id` の `status='active'` を `archived` に更新するか、versionごと削除。
- 初回移行直後は `prompt.txt` をバックアップとして残し、安定後に読み込み元をDBへ一本化する。

## 3) API側の取得ロジック（擬似コード）

このリポジトリがPHP中心のため、PHP風の擬似コードで示します。

```php
function buildFeedbackPrompt(PDO $pdo, string $curriculumName, array $context): string {
    // 1) curriculumを解決
    $stmt = $pdo->prepare(
        'SELECT id FROM curriculum WHERE curriculum_name = :name LIMIT 1'
    );
    $stmt->execute([':name' => $curriculumName]);
    $curriculum = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curriculum) {
        throw new RuntimeException('Unknown curriculum: ' . $curriculumName);
    }

    // 2) activeテンプレート取得（最新版優先）
    $stmt = $pdo->prepare(
        "SELECT template_body, version
         FROM curriculum_prompt_template
         WHERE curriculum_id = :curriculum_id
           AND status = 'active'
         ORDER BY version DESC, updated_at DESC
         LIMIT 1"
    );
    $stmt->execute([':curriculum_id' => $curriculum['id']]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3) フォールバック（DB未登録時）
    if (!$template) {
        $templateBody = defaultPromptFromCode($curriculumName); // 最低限の保険
    } else {
        $templateBody = $template['template_body'];
    }

    // 4) 変数埋め込み前バリデーション
    validateTemplate($templateBody, ['{{submission}}']);

    // 5) 変数展開
    $finalPrompt = str_replace(
        ['{{submission}}', '{{student_name}}'],
        [$context['submission'] ?? '', $context['student_name'] ?? '受講生'],
        $templateBody
    );

    // 6) 監査ログ（どのversionを使ったか）
    auditLog([
        'curriculum_name' => $curriculumName,
        'prompt_version' => $template['version'] ?? 0,
        'used_fallback' => !$template,
    ]);

    return $finalPrompt;
}
```

## 4) 実装時の注意点（本番運用）

- プレースホルダ（例: `{{submission}}`）の必須チェックを実装する。
- プロンプト最大長を制限し、想定外の巨大入力を防ぐ。
- 管理画面を作る場合は、更新時に「即時active化」か「draft保存のみ」かを明確に分ける。
- まずは `version` 手動採番で十分。将来的に自動採番へ変更してもよい。
- API実行ログに `curriculum_name` と `prompt_version` を必ず残し、品質改善時に追跡可能にする。
