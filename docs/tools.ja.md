# MCPツール一覧

BEAR.Sunday MCP Server 0.7.0は、BEAR Semantic API v1とPhpactorの標準LSPを
用途別の22個のread-only toolとして公開します。

- Resource URIなど、調べたいBEAR識別子が分かる場合は`bear_*` toolを使います。
- 保存済みファイルのcursor位置が分かる場合は`lsp_*` toolを使います。
- プロジェクトの対応機能やversionが不明な場合は、最初に`bear_project_info`を使います。

具体的な調査手順は[ユースケース](use-cases.ja.md)、実装・検証状況は
[プロジェクト現在地点](project-status.ja.md)を参照してください。

## BEAR Semantic tools

| MCP tool | Semantic API request | 主な入力 | 得られる事実 |
|---|---|---|---|
| `bear_project_info` | `bear/project/info` | optionalなcontext path | Semantic API version、capability、package version、PSR-4 root、Resource数 |
| `bear_resource_list` | `bear/resource/list` | scheme、prefix、limit | 正規化されたResource URI、FQN、workspace相対pathの決定的な一覧 |
| `bear_resource_describe` | `bear/resource/describe` | Resource URI | public `on*` method、parameter、Link/Embed、template、schema |
| `bear_resource_attributes` | `bear/resource/attributes` | Resource URI | allowlist済みclass/method属性、型付きstatic引数、動的引数marker |
| `bear_resource_attribute_index` | `bear/resource/attributeIndex` | scheme、prefix、limit | workspace内Resource属性のbounded inventoryとResourceごとのstatus |
| `bear_contract_compare` | `bear/contract/compare` | Resource URI、method、schema kind | Resource、JSON Schema、ALPS間の名前presence。型や意味の一致は主張しない |
| `bear_schema_lookup` | `bear/schema/describeForResource` | Resource URI、request/response | boundedなSchema factsとworkspace相対path。raw JSON全体は返さない |
| `bear_route_lookup` | `bear/route/resolve` | 明示的なRoute名 | Aura RouterのRouteからPage Resourceへの解決結果 |
| `bear_sql_lookup` | `bear/sql/resolve` | staticなquery ID | Ray.MediaQuery / Ray.QueryModuleのSQLファイル |
| `bear_template_lookup` | `bear/template/resolve` | engine、template名 | 明示的なTwig/Qiq template名からworkspace内ファイルへの解決結果 |
| `bear_template_for_resource` | `bear/template/forResource` | Resource URI、engine | BEARの標準規約に基づくResourceのTwig/Qiq template |
| `bear_alps_descriptor_lookup` | `bear/alps/describeDescriptor` | descriptor ID | ALPS descriptor factsと明示されたlocal relationship |
| `bear_resource_references` | `bear/resource/references` | Resource URI、limit | 同じcanonical Resourceへ解決されたstatic URI/Route参照とsource range |
| `bear_resource_incoming_relations` | `bear/resource/incomingRelations` | Resource URI | 対象Resourceを指すLink/Embed relation |

## 標準LSP tools

位置は0-basedです。`character`はLSPのUTF-16 code unit規約に従います。対象は保存済みの
workspace fileに限られます。

| MCP tool | LSP method | 主な入力 | 得られる事実 |
|---|---|---|---|
| `lsp_definition` | `textDocument/definition` | path、line、character | cursor位置の定義先 |
| `lsp_type_definition` | `textDocument/typeDefinition` | path、line、character | 型定義先。Resource規約のJSON Schemaを含む |
| `lsp_references` | `textDocument/references` | path、line、character、limit | cursor位置の参照箇所 |
| `lsp_hover` | `textDocument/hover` | path、line、character | boundedなHover内容 |
| `lsp_completion` | `textDocument/completion` | path、line、character、limit | boundedな補完候補 |
| `lsp_document_links` | `textDocument/documentLink` | path、limit | Resource URIやtemplate参照から解決されたworkspace内link |
| `lsp_document_symbols` | `textDocument/documentSymbol` | path、limit | 保存済みファイルのflatten済みsymbol一覧 |
| `lsp_workspace_symbols` | `workspace/symbol` | query、limit | workspace内に限定したsymbol検索結果 |

## 共通の結果と読み方

BEAR Semantic APIの結果は、coreの共通envelopeを保ちます。

```json
{
  "status": "ok",
  "data": {},
  "candidates": [],
  "provenance": []
}
```

- `status`は成功、欠落、曖昧、不正入力、parse error、engine unavailableなどを区別します。
- `candidates`は曖昧な候補を、推測で1件に絞らず返します。
- `provenance`は根拠となった保存済みfileとrangeを示します。
- `total`と`truncated`がある結果では、返された配列だけを全件と判断しません。
- pathはworkspace相対です。workspace外のtargetは公開しません。
- 空配列だけで「存在しない」と判断せず、`status`とcapabilityを併せて確認します。

## 安全境界

全22 toolはread-onlyです。MCP serverは次の操作を行いません。

- BEAR applicationや任意PHPの実行
- templateのrender
- source fileの編集や生成
- Composer、test、migration、deploymentの実行
- network access
- workspace外のfileの公開
- 任意コマンド、workspace root、実行ファイル、任意LSP methodのtool引数からの指定

workspace rootとPhpactor commandはserver起動時に固定されます。tool callは、保存済みsourceから
得られる事実だけを問い合わせます。
