# CLAUDE.md — tagmark

**Field Trial 12-A**: NENE2 多対多リレーション実地検証プロジェクト。
NENE2 Issue #453 / Phase 65 に対応。

---

## ミッション

`composer require hideyukimori/nene2:^1.4` を唯一の依存として、
多対多リレーション（Bookmark ↔ Tag）を持つブックマーク管理 API を**ゼロから**実装する。

実装中に詰まった点・回避が必要だった点を **F-N 形式**で `docs/field-trial-report.md` に記録する。

---

## 実装するエンドポイント

| Method | Path | 認証 | 備考 |
|---|---|---|---|
| POST | /auth/register | 不要 | |
| POST | /auth/login | 不要 | |
| GET | /bookmarks | Bearer | ページネーション + ?tag={id} フィルタ |
| POST | /bookmarks | Bearer | |
| GET | /bookmarks/{id} | Bearer | タグ一覧を含む |
| PUT | /bookmarks/{id} | Bearer | |
| DELETE | /bookmarks/{id} | Bearer | 中間テーブルもカスケード削除 |
| GET | /tags | Bearer | |
| POST | /tags | Bearer | |
| DELETE | /tags/{id} | Bearer | 中間テーブルもカスケード削除 |
| POST | /bookmarks/{id}/tags/{tagId} | Bearer | タグ付与（冪等） |
| DELETE | /bookmarks/{id}/tags/{tagId} | Bearer | タグ除去 |

---

## NENE2 参照先

- 設計パターン: `../NENE2/src/` — 最新の実装例
- **実際のメソッドシグネチャ**: `vendor/hideyukimori/nene2/` を優先（開発版と差異あり）
- JWT 認証パターン: `../NENE2/docs/howto/add-jwt-authentication.md`
- 多対多パターン: `../NENE2/docs/howto/add-database-endpoint.md`
- 安定 API 一覧: `../NENE2/docs/adr/0009-v1.0-public-api-scope.md`

---

## 摩擦記録テンプレート

`docs/field-trial-report.md` の Findings セクションに以下の形式で記録する:

```markdown
### F-N: タイトル [高/中/低]

**状況**: 何をしようとしていたか

**問題**: 何が起きたか（エラー・不明点・設計の曖昧さ）

**解決**: どう回避したか

**提案**: NENE2 に何を追加・変更すれば解決するか
```

---

## 完了条件

- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] 全エンドポイント動作確認
- [ ] M:N 操作（タグ付与・除去・フィルタリング・カスケード削除）動作確認
- [ ] `docs/field-trial-report.md` に摩擦記録あり
- [ ] PR を作成・マージ済み
