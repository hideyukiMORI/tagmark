# Field Trial 12-A — tagmark: 多対多リレーション実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.x（`hideyukimori/nene2: ^1.4`）
- PHP 8.4（Docker: `php:8.4-cli` ベースイメージ）
- プロジェクト: **tagmark** — ブックマーク管理 JSON API
- エンティティ: `User` / `Bookmark` / `Tag`（M:N）
- テスト: PHPUnit・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

多対多リレーション（Bookmark ↔ Tag）を持つドメインが、
NENE2 のドキュメントだけを参照した Claude が迷わず実装できるかを検証する。

---

## Steps Taken

1. `CLAUDE.md` と参照ドキュメント（`add-jwt-authentication.md`・`add-database-endpoint.md`・`add-second-entity.md`）を読み込んだ。
2. `vendor/hideyukimori/nene2/src/` を参照してメソッドシグネチャを確認した（ドキュメントとの差異を複数発見）。
3. Auth ドメイン（`User`・`UserRepository`・`RegisterUseCase`・`LoginUseCase`・`AuthRouteRegistrar`）を実装した。
4. Tag ドメイン（`Tag`・`TagRepository`・`TagRouteRegistrar`）を実装した。
5. Bookmark ドメイン（`Bookmark`・`BookmarkRepository`・`BookmarkRouteRegistrar`）を実装した。JOIN クエリ・CASCADE 削除・tag フィルタリングを含む。
6. `BearerTokenMiddleware` が `excludedPaths`（除外リスト）ではなく `protectedPaths`（許可リスト）であることを発見し、`ExcludedPathsBearerMiddleware` ラッパーを実装した。
7. `RuntimeApplicationFactory` を使用せず、ミドルウェアパイプラインを手動で構築した（F-1 参照）。
8. `database/schema.sql` を作成し、フロントコントローラー内で自動初期化するパターン（Pattern B）を採用した。
9. PHPUnit テスト（19 件）を作成し、全エンドポイント・M:N 操作・カスケード削除・所有権チェックを網羅した。
10. `composer check` 全通過を確認した。

---

## Findings

### F-1: BearerTokenMiddleware の excludedPaths パラメータが実在しない [高]

**状況**: `/auth/register` と `/auth/login` を認証不要にしたかった。

**問題**: `add-jwt-authentication.md` は `BearerTokenMiddleware` のパラメータ名を `excludedPaths`（除外リスト）と記述しているが、v1.4 の実装は `protectedPaths`（許可リスト）である。ドキュメント通りに実装すると constructor でエラーになる。さらに、許可リストのセマンティクスでは「empty = protect all」なので `/auth/*` も 401 になる。

**解決**:
- `ExcludedPathsBearerMiddleware`（PSR-15 ラッパー）を自前で実装した。
- `BearerTokenMiddleware` を `protectedPaths: []`（全パス保護）で生成し、そのラッパーが `/auth/register` と `/auth/login` のみをスキップする。
- `RuntimeApplicationFactory` の `$bearerTokenMiddleware` パラメータは `?BearerTokenMiddleware` 型なので、ラッパーを直接注入できない。そのためファクトリーを使わずにパイプラインを手動構築した。

**提案**:
1. `BearerTokenMiddleware` に `excludedPaths` パラメータを追加する（またはパラメータを `protectedPaths` に統一してドキュメントを修正する）。
2. `RuntimeApplicationFactory` の `$bearerTokenMiddleware` パラメータの型を `?MiddlewareInterface` に緩和し、任意の認証ミドルウェアを受け入れられるようにする。

---

### F-2: add-jwt-authentication.md の TokenIssuerInterface が v1.4 に存在しない [高]

**状況**: `RegisterUseCase` と `LoginUseCase` に JWT 発行機能を注入したかった。

**問題**: `add-jwt-authentication.md` のサンプルコードは `Nene2\Auth\TokenIssuerInterface` を `use` しているが、v1.4 の `vendor/` に `TokenIssuerInterface.php` が存在しない。`LocalBearerTokenVerifier` は `issue()` メソッドを持つが、`TokenVerifierInterface` のみを `implements` している。

**解決**:
- `Tagmark\Auth\TokenIssuerInterface` を自前で定義した。
- `Tagmark\Auth\LocalTokenIssuer` アダプターを実装し、`LocalBearerTokenVerifier::issue()` を `TokenIssuerInterface` にブリッジした。

**提案**: `Nene2\Auth\TokenIssuerInterface` を v1.4 に追加し、`LocalBearerTokenVerifier` が実装するようにする。ADR 0009 の公開 API 一覧にも追記する。

---

### F-3: add-jwt-authentication.md の DomainExceptionHandlerInterface シグネチャが実際と異なる [中]

**状況**: `AccessDeniedException` を 403 にマップするハンドラーを実装したかった。

**問題**: `add-jwt-authentication.md` は `DomainExceptionHandlerInterface` のメソッドを `handles(Throwable $e): bool` と記述しているが、v1.4 の実装は `supports(Throwable $exception): bool` である。ドキュメント通りに実装するとインターフェース未実装エラーになる。

**解決**: `vendor/` を直接確認して正しいメソッド名 `supports()` を使用した。

**提案**: `add-jwt-authentication.md` の DomainExceptionHandlerInterface サンプルコードを `handles()` → `supports()` に修正する。

---

### F-4: add-database-endpoint.md の「ハンドラーは配列を返せる」という記述が正確でない [中]

**状況**: ルートハンドラーの戻り値を確認したかった。

**問題**: `add-database-endpoint.md` は「ハンドラーは array を返せる — NENE2 が自動的に JSON に変換する」と説明しているが、v1.4 の `Router` 型定義は `callable(ServerRequestInterface): ResponseInterface` であり、配列を返すと型エラーになる可能性がある。実際には `JsonResponseFactory::create()` を使って `ResponseInterface` を返す必要がある。

**解決**: `vendor/hideyukimori/nene2/src/Example/Note/CreateNoteHandler.php` を参照し、`JsonResponseFactory::create()` で `ResponseInterface` を返すパターンに従った。

**提案**: `add-database-endpoint.md` のハンドラーサンプルを `JsonResponseFactory::create()` を使う形に修正するか、または配列を自動変換するレイヤーを追加する。

---

### F-5: add-jwt-authentication.md が getParsedBody() を推奨しているが JSON ボディには機能しない [中]

**状況**: POST/PUT リクエストのボディを読み取りたかった。

**問題**: `add-jwt-authentication.md` の Auth ハンドラーサンプルは `$request->getParsedBody()` を使っているが、NENE2 は JSON ボディを自動でパースしない。`getParsedBody()` は `application/x-www-form-urlencoded` 向けで、JSON リクエストでは `null` を返す。実際のコードは `JsonRequestBodyParser::parse($request)` を使う必要がある。

**解決**: `vendor/` 内の `CreateNoteHandler.php` を参照し、`JsonRequestBodyParser::parse()` を使用した。

**提案**: `add-jwt-authentication.md` のハンドラーサンプルを `getParsedBody()` から `JsonRequestBodyParser::parse()` に修正する。

---

### F-6: M:N カスケード削除はフレームワーク機能なし — アプリ側で手動管理が必要 [低]

**状況**: Bookmark 削除時に `bookmark_tags` の中間テーブル行も削除したかった。

**問題**: NENE2 は DB 操作のカスケード削除を提供しない（設計上正しい）。SQLite の `REFERENCES` 宣言だけでは `ON DELETE CASCADE` が効かないため（SQLite のデフォルトでは外部キー強制が無効）、アプリ層で明示的に中間テーブルを削除する必要がある。

**解決**: `PdoBookmarkRepository::delete()` で先に `bookmark_tags` を削除してから `bookmarks` を削除した。`PdoTagRepository::delete()` でも同様に `bookmark_tags` を先に削除した。

**提案**: `add-database-endpoint.md` または新しい howto に「SQLite で M:N を実装する場合のカスケード削除パターン」を追記する（`PRAGMA foreign_keys = ON` の有効化または手動削除の両方を案内する）。

---

### F-7: RuntimeApplicationFactory を使用できずパイプライン手動構築が必要だった [中]

**状況**: アプリケーションを `RuntimeApplicationFactory` 経由で起動したかった。

**問題**: F-1 の結果として `RuntimeApplicationFactory::$bearerTokenMiddleware` の型が `?BearerTokenMiddleware` に固定されており、`ExcludedPathsBearerMiddleware`（`MiddlewareInterface` 実装）を注入できなかった。そのため `MiddlewareDispatcher` を使ってパイプラインを手動構築した。これは `RequestLoggingMiddleware` / `SecurityHeadersMiddleware` / `CorsMiddleware` などを自分でリストアップする必要があることを意味する。

**解決**: `RuntimeApplicationFactory::create()` の実装を読み取り、同じ順序でミドルウェアスタックを手動組み立てた。

**提案**: F-1 と同じ — `$bearerTokenMiddleware` の型を `?MiddlewareInterface` に変更するか、`$excludedPaths` オプションを `BearerTokenMiddleware` に追加する。

---

## Test Results

```
PHPUnit 11.5.55

Bookmark Api (Tagmark\Tests\Http\BookmarkApi)
 ✔ Register
 ✔ Login
 ✔ Unauthorized without token
 ✔ Create and get bookmark
 ✔ Create and list tags
 ✔ Many to many tag operations
 ✔ Delete bookmark cascades bookmark tags
 ✔ Delete tag cascades bookmark tags
 ✔ Ownership check 403
 ✔ Not found 404
 ✔ Update bookmark
 ✔ Validation error 422

Login Use Case (Tagmark\Tests\Auth\LoginUseCase)
 ✔ Successful login
 ✔ Rejects wrong password
 ✔ Rejects unknown email

Register Use Case (Tagmark\Tests\Auth\RegisterUseCase)
 ✔ Successful registration
 ✔ Rejects invalid email
 ✔ Rejects short password
 ✔ Rejects duplicate email

OK (19 tests, 45 assertions)

PHPStan level 8: OK (No errors)
PHP-CS-Fixer: OK (0 files to fix)
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `BearerTokenMiddleware` が `excludedPaths` ではなく `protectedPaths`（許可リスト）であり、ドキュメントと実装が乖離 | 高 | ドキュメント誤り + API 設計 |
| F-2 | `TokenIssuerInterface` が v1.4 に存在しない | 高 | API 欠損 |
| F-3 | `DomainExceptionHandlerInterface::handles()` が正しくは `supports()` | 中 | ドキュメント誤り |
| F-4 | ハンドラーが配列を返せると書かれているが実際は `ResponseInterface` 必須 | 中 | ドキュメント誤り |
| F-5 | `getParsedBody()` で JSON が読めない（`JsonRequestBodyParser` 必須） | 中 | ドキュメント誤り |
| F-6 | M:N カスケード削除パターンのドキュメントなし | 低 | ドキュメント欠損 |
| F-7 | `RuntimeApplicationFactory` に任意の認証ミドルウェアを渡せない | 中 | API 設計 |

---

## Recommendations

1. **`BearerTokenMiddleware` に `excludedPaths` パラメータを追加する**（または `protectedPaths` を除外リストに変える）。これが F-1・F-7 を解消し、`RuntimeApplicationFactory` との組み合わせも自然になる。
2. **`TokenIssuerInterface` を v1.4 に追加する**。`LocalBearerTokenVerifier` が実装するようにして ADR 0009 に含める（F-2）。
3. **howto ドキュメントを v1.4 の実装と同期させる**。`supports()` / `JsonRequestBodyParser` / `ResponseInterface` 戻り値の点で複数の誤りがある（F-3・F-4・F-5）。
4. **M:N パターンの howto を追加する**。SQLite の外部キー制約の挙動（デフォルト無効）と手動カスケード削除パターンを含める（F-6）。

---

## Overall Impression

NENE2 のコアアーキテクチャ（Router・MiddlewareDispatcher・ProblemDetailsResponseFactory・ValidationException）は直感的で、M:N 実装自体は迷わず書けた。

最大の障害は **ドキュメントと v1.4 実装の乖離**（F-1〜F-5）であり、`vendor/` を直接読まなければ実装を完成できなかった。特に `BearerTokenMiddleware::$protectedPaths` と `TokenIssuerInterface` 欠損は深刻で、ドキュメントだけを信じると 401 ループか PHP エラーに終わる。

`composer check` が PHPStan level 8 まで全通過した点は品質保証として有効に機能した。テストを先に書いてから実装する TDD スタイルとも相性が良い。

ドキュメントを v1.4 に追いつかせることで、次の Field Trial ではドキュメントだけで迷わず実装できるようになるはずである。
