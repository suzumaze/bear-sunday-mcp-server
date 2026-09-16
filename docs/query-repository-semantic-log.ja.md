# BEAR.QueryRepositoryセマンティックログ連携設計

## 目的

Phpactor Semantic APIが返す「保存済みsourceから分かる事実」に、BEAR.QueryRepositoryの
セマンティックログが返す「実行時に観測された証拠」を加えます。

調査対象は2026-09-16時点の
[`bearsunday/BEAR.QueryRepository` 1.x](https://github.com/bearsunday/BEAR.QueryRepository/tree/1.x)
（commit `5f9f83e477bdd682cb72d13023c3c1cbf430bd53`）です。

参照仕様:

- [このログは何を証明し、何を証明しないか](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/what-the-log-proves.ja.md)
- [ログの読み方](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/reading-the-log.ja.md)
- [context JSON Schemas](https://github.com/bearsunday/BEAR.QueryRepository/tree/1.x/docs/schemas/context)

## Architecture boundary

```text
MCP tools
  ├─ source facts
  │    └─ PhpactorSemanticProvider ── LSP ── bear-phpactor-extension
  └─ runtime evidence
       └─ QueryRepositoryLogProvider ── validated local log artifact
```

セマンティックログはtext documentの問い合わせではないため、標準LSPへ押し込みません。
Phpactor extensionにもQueryRepository runtimeの依存を追加しません。MCP serverがoptional providerを
構成し、ログの語彙・検証・claim生成は可能な限りBEAR.QueryRepository側のpublic reader APIを使います。

public reader APIが存在しない間は、MCP側で意味を重複実装せず、providerをexperimental capabilityとして
扱います。イベント語彙を固定文字列で大量に複製する前に、upstreamへstable reader/validator境界を提案します。

## Source factsとruntime evidenceの違い

| 種類 | 例 | freshness |
|---|---|---|
| source fact | `#[Cacheable]`が宣言されている | `saved` |
| source fact | `#[Refresh]`がResource URIを指している | `saved` |
| runtime evidence | `save_value.saved`がtrueだった | `observed` |
| runtime evidence | `invalidate.cdn`が`failed`だった | `observed` |
| runtime evidence | `cache_error{operation: read}`と`cache_miss`が同じscopeにあった | `observed` |

MCP responseはこの2種類を混同せず、provenanceの`source`と`freshness`で区別します。

## Configuration

ログproviderは明示的に有効化します。既定では無効です。

候補option:

```text
--query-log-dir=var/log/query-repository
--query-log-file=var/log/query-repository.jsonl
```

規則:

- 2つのoptionはmutually exclusive。
- pathはworkspace相対だけを受け付ける。
- 起動時にcanonicalizeし、workspace外とsymlink escapeを拒否する。
- 起動後にtool inputからlog rootを変更できない。
- directory modeは開発用のsession JSONと`latest.json`を読む。
- file modeは本番用JSON Linesを後方からboundedに読む。
- 未設定ならlog toolを登録しない、またはcapabilityをfalseとして報告する。
- ログが無いこととprovider未設定を別statusにする。

## MCP tools

### `bear_cache_log_list`

利用可能なsessionを新しい順に一覧します。

入力:

```json
{
  "limit": 20
}
```

出力data:

- opaque `sessionId`
- 記録時刻（ファイル名またはlog metadataから確定できる場合だけ）
- top-level scope kind
- Resource URI（存在する場合、query valueはredact）
- mutation/failure/diagnosticの有無
- schema validation status

absolute pathやraw logは返しません。

### `bear_cache_log_explain`

1つのsessionについて、仕様が認めるclaimと根拠eventを返します。

入力:

```json
{
  "sessionId": "opaque-id"
}
```

claim type:

- `cache_write`
- `cdn_instruction`
- `invalidation`
- `conditional_request`
- `miss_reason`
- `initiator`
- `cache_policy`
- `pool_health`
- `cost_observation`

claim例:

```json
{
  "type": "miss_reason",
  "subject": "app://self/user{?id}",
  "outcome": "degraded_read",
  "evidence": [
    {"context": "cache_error", "node": "0.2"},
    {"context": "cache_miss", "node": "0.close"}
  ],
  "limitations": []
}
```

`node`はsession内のopaqueな位置です。source file byte rangeではありません。

### `bear_cache_log_for_resource`

Resource URIに関係するbounded session summaryを返します。

入力:

```json
{
  "resourceUri": "app://self/user",
  "limit": 20
}
```

URI templateとquery valueの一致規則はupstream readerで定義されるまで推測しません。初期版はlogに記録された
URIのnormalized safe representationとのexact matchだけを扱います。

## 証明できるclaim

ログ仕様に従い、次の範囲だけをclaimにします。

1. responseの保存試行、要求TTL、key/tag、`saved`結果
2. responseへ設定されたCDN headerとsurrogate key
3. purgerへ渡したtagと報告された結果
4. conditional requestのETag layer hit/miss
5. cold、degraded read、write failure、policy skipの区別
6. framework interceptorまたはmanual operationという開始元
7. 宣言されたcache policyとresolved TTL
8. pool adapterが報告したread/write failure
9. 1 session内で観測されたhit/miss duration

## 証明しないこと

次はtool descriptionと各responseの`limitations`へ明記します。

- CDN edgeへpurgeが伝播したこと
- backendが要求TTLどおりにevictionしたこと
- 記録されていないsessionの状態
- production全体の正確なhit rate
- concurrent/long-lived runtimeで記録されなかったrequest
- custom CDN headerの意味
- source attributeだけから推測したruntime結果
- 1回のdurationから一般化したperformance結論

`cost_observation`は計測値で、benchmarkやSLO判定ではありません。

## Privacy and safety

QueryRepository logはrequest URIのquery string、client validator、exception textを含み得ます。MCPへrawで
渡すと、AI transcriptやremote modelへ機密情報が流れる可能性があります。

初期版の規則:

- query parameterは名前だけを残し、値を`<redacted>`へ置換する。
- exception message、pool key、validatorのraw valueを返さない。
- exception classとoperation typeだけを返す。
- allowlistされたcontext fieldだけをDTOへ写す。
- raw JSON取得toolを提供しない。
- 1 file 1 MiB、JSON depth 64、1 request最大100 sessionとする。
- malformed/unknown contextは`parse_error`または`unsupported`とし、黙って無視しない。
- schemaはnetworkから取得せず、installed packageにbundleされたものを使う。
- MCP serverはlogの生成、application実行、purge、cache操作を行わない。

## Reader boundary required upstream

望ましいpublic API:

```php
interface QueryRepositoryLogReaderInterface
{
    public function readSession(string $json): LogReadResult;
}
```

`LogReadResult`に必要な情報:

- schema validation resultとdiagnostics
- typed open/event/close tree
- stable context type
- child relationとnode identifier
- safe scalar access
- format/schema version

MCP固有のredaction、result bound、tool envelopeはMCP repositoryの責務です。cache eventの意味、
tree traversal invariant、schema version compatibilityはupstream readerの責務です。

## Failure statuses

| status | 意味 |
|---|---|
| `ok` | schema-validなsessionからbounded resultを生成した |
| `not_found` | sessionまたは対象Resourceの記録が無い |
| `invalid_input` | session ID、URI、limitが不正 |
| `parse_error` | JSONまたはsemantic log schemaが不正 |
| `unsupported` | 未対応format/schema version/context |
| `outside_workspace` | configured pathがworkspace境界を外れた |
| `engine_unavailable` | optional providerが構成されていない |

## Implementation stages

1. upstreamのreader/validator API有無とversion contractを確定する。
2. fixture logとredaction contractをMCP repositoryへ追加する。
3. startup optionとworkspace-bound log sourceを実装する。
4. `bear_cache_log_list`を実装する。
5. `bear_cache_log_explain`をclaim typeごとのtestと共に実装する。
6. `bear_cache_log_for_resource`をexact matchだけで実装する。
7. `bear_project_info`へoptional capabilityを追加する。
8. QueryRepository demo logに対するintegration testを追加する。
9. 実projectではdevelopment logだけで検証し、本番logを収集しない。

## Acceptance criteria

- provider未設定時に現在の22 toolと起動方法が変わらない。
- tool inputから任意のpathを指定できない。
- invalid JSON、oversized log、symlink escape、unknown contextをtestする。
- sensitive valueがtool resultとerror messageへ現れない。
- claimごとに根拠nodeとlimitationがある。
- upstream schema/version不一致を`unsupported`としてfail closedする。
- 同じlogへの同じqueryはbyte-identicalなJSONを返す。
- application、PHP script、`stree`、networkを実行しない。
- QueryRepositoryが証明しないことをMCPも証明したと表現しない。
