# ユースケース

BEAR.Sunday MCP Serverは、保存済みの1つのworkspaceについて、AIクライアントへ構造化された
事実を提供します。テキスト検索を完全に置き換えるものではありません。対応済みのBEAR概念は
最初にSemantic toolで調べ、返されたファイルを読み、Semantic Model外のコードだけを検索します。

利用できる22 toolの入力と結果は[MCPツール一覧](tools.ja.md)、実装・検証状況は
[プロジェクト現在地点](project-status.ja.md)にまとめています。

## AIクライアント用Skill

同梱する[`bear-semantic` Skill](../skills/bear-semantic/SKILL.md)は、AIにこの文書の基本手順を
適用させます。既知のBEAR識別子にはsemantic toolを先に使い、statusとprovenanceを確認し、
対象外の部分だけを検索します。MCPを利用できない場合は、通常のLSP、`rg`、source確認へ
fallbackします。

SkillはMCP serverをinstall・起動・設定しません。また、BEAR applicationのComposer依存にも
なりません。MCP serverの接続とSkillの登録は、それぞれAIクライアント側で行います。

## Grepとの違い

| 質問 | テキスト検索 | Semantic MCPの結果 |
|---|---|---|
| Resource URIの実装は何か | 一致した文字列やクラス名の断片 | 正規化URI、FQN、workspace相対path |
| Resourceの公開APIは何か | `on*` methodを個別検索 | public `on*` methodと宣言されたparameter type |
| Resourceはどこで使われるか | 同じ文字列をすべて表示 | 同じcanonical Resourceへ解決された静的参照 |
| 誰がLink/Embedしているか | 属性テキストを人が解釈 | 種類付きのincoming/outgoing Link・Embed relation |
| Route、SQL ID、templateの実体は何か | 候補となる文字列一致 | 解決済みtarget、または明示的なsemantic failure status |
| ALPS descriptorは何と関係するか | JSON文字列の一致 | 明示されたlocal descriptor relationship |
| cache属性やResource属性はどこにあるか | short name、alias、comment、動的値が混在 | FQN allowlist済みのclass/method facts、型付きstatic引数、明示的な`dynamic` marker |
| Resource、Schema、ALPSの名前がずれていないか | 個別検索後に人が集合比較 | 面ごとのstatusと決定的なpresence matrix |

すべての検索問題をsemanticに扱うとは主張しません。コメント、任意の設定、未対応のframework拡張、
動的に組み立てられた値は、引き続きsource確認やテキスト検索が必要です。

## 1. 未知のプロジェクトを把握する

質問例:

```text
このBEAR.Sundayプロジェクトを要約してください。Resource数とPage/App Resourceの先頭を一覧し、
入口に見えるResourceを詳しく説明してください。
```

代表的なtool sequence:

1. `bear_project_info`
2. `scheme: page`を指定した`bear_resource_list`
3. `scheme: app`を指定した`bear_resource_list`
4. 選択したURIへの`bear_resource_describe`

アプリケーションを実行せずに、Semantic API version、project capability、Resource一覧、method、
relation、template、schemaを把握できます。

## 2. Resource変更の影響範囲を調べる

質問例:

```text
app://self/userを変更する前に、public method、外向きLink/Embed、incoming relation、
静的参照、template、schemaを表示してください。
```

代表的なtool sequence:

1. `bear_resource_describe`
2. `bear_resource_references`
3. `bear_resource_incoming_relations`

返されたpathとrangeから、関係するファイルだけを開けます。件数制限された結果には`total`と
`truncated`があります。`truncated`がtrueなら、返されたpageを全件と判断してはいけません。

## 3. Request surfaceを追跡する

一度に1つの具体的な質問をします。

```text
Route /article/detailをPage Resourceへ解決してください。
page://self/article/detailのTwig templateを探してください。
app://self/articleのresponse schemaを表示してください。
query ID article_detailのSQLファイルを探してください。
ALPS descriptor goArticleを説明してください。
```

対応するtoolは`bear_route_lookup`、`bear_template_for_resource`、`bear_schema_lookup`、
`bear_sql_lookup`、`bear_alps_descriptor_lookup`です。

解決は意図的に保守的です。動的な式、custom loader、外部ALPS link、曖昧な規約は推測しません。

## 4. Resource属性を監査する

質問例:

```text
App ResourceのCacheable、Purge、Refresh、Link、Embed、JsonSchema、Alps属性を監査し、
静的引数と動的式を分け、file単位の失敗も表示してください。
```

workspaceのbounded viewには`bear_resource_attribute_index`、単一Resourceの詳細には
`bear_resource_attributes`を使います。indexには`total`/`truncated`とResourceごとの独立した
`status`があるため、1つの壊れたfileで他のfactsを失いません。文書化されたFQNだけを認識し、
application PHPは実行しません。
両toolは引数policyを`explicit_only`として返します。属性で省略された引数はconstructorにdefaultが
無いことを意味せず、install済みpackageのdefault値を展開・推測しません。

## 5. Contract名のpresenceを比較する

質問例:

```text
app://self/userのonPostについて、Resource parameter、request JSON Schema、
ALPS operation descriptor間のrequest名presenceを比較してください。
```

`schemaKind: request`で`bear_contract_compare`を使います。各面は独立した`status`、`subject`、
名前を返し、2面以上が利用できる場合だけ比較を生成します。同名は綴りのpresenceの根拠にすぎず、
型、制約、意味、runtime互換性の一致を証明しません。response比較ではResource body面は現在
`unsupported`ですが、SchemaとALPSの`rt`先representationは比較できます。

## 6. 正確なsource位置から移動する

保存済みファイルとcursor位置が分かる場合は、標準LSP toolを使います。

- `lsp_definition`
- `lsp_type_definition`
- `lsp_references`
- `lsp_hover`
- `lsp_completion`
- `lsp_document_links`
- `lsp_document_symbols`
- `lsp_workspace_symbols`

lineとcharacterは0-basedで、characterはLSPのUTF-16規約です。Resource URIなどのBEAR識別子は
分かるが信頼できるcursor位置がない場合は、identifier-basedのBEAR toolを優先します。

`lsp_workspace_symbols`はdocument symbolより対象が狭く、Phpactor indexのclass、function、constant
recordだけを返し、methodは対象外です。index freshnessは`unknown`なので、空結果だけでは不存在を
証明できず、新規保存fileがまだindexに無い可能性もあります。既知fileには
`lsp_document_symbols`、method探索や結論不能な空結果にはsource検索を使います。

## 7. AIが生成した変更を検証する

サーバー自身はファイルを編集しませんが、保存した変更がIDEと同じsemantic layerから認識されるかを
確認できます。

1. 通常のcoding workflowでファイルを生成・編集する。
2. ファイルを保存する。
3. `bear_resource_list`にResourceが現れることを確認する。
4. `bear_resource_describe`でmethodとrelationを確認する。
5. `bear_resource_attributes`で対応属性を再取得する。
6. 変更したrequest/response面を`bear_contract_compare`で比較する。
7. schemaとtemplateを解決する。
8. 呼び出し元からreferenceまたはdocument linkを確認する。
9. projectのtestとstatic analysisは別に実行する。

これは規約や解決の誤りを検出します。runtime behaviorを証明するものではありません。

## 8. 結果を安全に解釈する

すべてのBEAR Semantic API resultは同じenvelopeを保ちます。

```json
{
  "status": "ok",
  "data": {},
  "candidates": [],
  "provenance": []
}
```

- `status`は成功、欠落、曖昧、不正入力、parse error、engine unavailableを区別します。
- `data`が存在して非nullになるのは`status: ok`だけで、null memberはstdio wire上で省略されます。
  失敗時の任意の`partial`は、失敗までに確定した
  狭い範囲の事実であり、成功として扱いません。Resource templateだけが無い場合は、解決済み
  Resourceと探索pathをこの形で保持できます。
- `candidates`は推測で1件を選ばず、件数制限された候補を保持します。
- `provenance`は結果の根拠となった保存済みworkspace fileとrangeを示します。
- pathはworkspace相対です。固定されたrootの外にあるファイルは公開しません。

空配列が常に「その概念は存在しない」を意味するとは限りません。結論を出す前に`status`、
capabilities、`available`、`truncated`を確認します。

## 境界

MCP serverはread-onlyで、保存済みファイルだけを扱います。次のことは行いません。

- BEAR applicationや任意PHPの実行
- templateのrender
- コードの編集・生成
- Composer、test、migration、deployment checkの実行
- 動的な式の推測による解決
- runtime cache behaviorの観測
- 未対応概念に対する一般的なsource searchの代替

これらはagent workflow、または専用のruntime evidence providerの責務です。
