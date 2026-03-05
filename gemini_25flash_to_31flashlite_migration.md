# gemini-2.5-flash → gemini-3.1-flash-lite 移行メモ（Gemini API）

> 目的：既存の `gemini-2.5-flash` 利用システムを **`gemini-3.1-flash-lite` 系**へ切り替えるために必要な情報（モデルID / URL / リクエスト形式 / SDK例）を整理。

---

## 1. モデルID（まずこれを使う）

現状は **プレビュー扱い**のため、基本は以下を指定します。

- `gemini-3.1-flash-lite-preview`

※ あなたの環境で “previewなし” が使えるかは **models API** で確認（後述）。

---

## 2. Gemini Developer API（AI StudioのAPIキーで叩く方）

### 2.1 エンドポイント（REST）
`generateContent` のURL（v1beta）は以下です：

- `POST https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent`

差し替え例：

- 旧：`.../models/gemini-2.5-flash:generateContent`
- 新：`.../models/gemini-3.1-flash-lite-preview:generateContent`

### 2.2 認証（APIキー）
ヘッダーで渡します：

- `x-goog-api-key: $GEMINI_API_KEY`

### 2.3 最小 cURL 例（テキスト入力）
```bash
curl "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite-preview:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{
    "contents": [{
      "parts": [{"text": "こんにちは。要点だけ3つでまとめて。"}]
    }]
  }'
```

---

## 3. thinking（思考レベル）を軽くして高速・低コスト寄りにする

Gemini 3系は thinking を指定できます。Flash-Lite は **`minimal`** がサポートされ、デフォルトも minimal です。

例（推奨：まず minimal から）：

```json
{
  "contents": [{"parts":[{"text":"次の文章を短く整えて"}]}],
  "generationConfig": {
    "thinkingConfig": { "thinkingLevel": "minimal" }
  }
}
```

※ thinking を上げると **出力トークン（thinking tokens 含む）**が増え、コスト/遅延に影響しやすいので注意。

---

## 4. SDK利用時（Python / JavaScript）

> 多くの場合、**model名を差し替えるだけ**で移行できます。

### 4.1 Python（google-genai）
```python
from google import genai

client = genai.Client()
resp = client.models.generate_content(
    model="gemini-3.1-flash-lite-preview",
    contents="この文章を要約して"
)
print(resp.text)
```

### 4.2 JavaScript（@google/genai）
```js
import { GoogleGenAI } from "@google/genai";

const ai = new GoogleGenAI({});
const resp = await ai.models.generateContent({
  model: "gemini-3.1-flash-lite-preview",
  contents: "この文章を要約して"
});
console.log(resp.text);
```

---

## 5. 使えるモデル名を確実に確認する（models API）

「preview が必要か」「そもそも利用可能か」を確実にするなら **models API** で確認するのが最短です。

- `models.list`（利用可能なモデル一覧を取得）
- `models.get`（指定モデルの詳細取得）

※ URL例（概念）：
- `GET https://generativelanguage.googleapis.com/v1beta/models`
- `GET https://generativelanguage.googleapis.com/v1beta/models/{MODEL}`

---

## 6. 移行時に詰まりやすい注意点（チェックリスト）

- [ ] モデルIDを `gemini-3.1-flash-lite-preview` に変更したか
- [ ] エンドポイントが `.../v1beta/models/{MODEL}:generateContent` になっているか
- [ ] APIキーが `x-goog-api-key` で渡せているか
- [ ] thinking を上げすぎていないか（まず minimal 推奨）
- [ ] 入力が画像/動画/PDFの場合、リクエスト `parts` の作り方が既存と一致しているか（基本は同じ枠組み）
- [ ] レート制限やクォータは **プロジェクト/ティア依存**なので、AI Studio側の表示も確認する

---

## 7. 差分だけで移行する最短手順（まとめ）

1. 既存の `model="gemini-2.5-flash"` を `model="gemini-3.1-flash-lite-preview"` に変更
2. 必要なら `thinkingLevel: minimal` を明示
3. models API で利用可否/正しいモデル名を確認
4. 本番前に少量で動作確認 → レート/出力品質の確認 → 切り替え
