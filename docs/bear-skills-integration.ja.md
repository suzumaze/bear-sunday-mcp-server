# BEAR.Skills連携設計

## 目的

BEAR.Skillsが持つ判断手順をMCP serverへ複製するのではなく、Skillが必要とするBEAR固有の
事実をPhpactor Semantic APIから構造化して供給します。

調査対象は2026-09-16時点の
[`bearsunday/BEAR.Skills` 1.x](https://github.com/bearsunday/BEAR.Skills/tree/1.x)
（commit `0adb369acc07a720638374bd70a1d9d02966fd40`）です。

## 実装した共通Skill

MCP serverには、汎用の[`bear-semantic` Skill](../skills/bear-semantic/SKILL.md)を同梱します。
これは個別の設計Skillを置き換えず、次の共通処理だけを担当します。

- `bear_project_info`によるcapability確認
- `bear_project_diagnostics`によるproject全体の静的不整合と走査範囲の確認
- `bear_contract_coverage`によるJSON Schema・ALPS導入候補と解析不能箇所の分離
- BEAR identifierにはsemantic tool、source位置にはLSP toolを選ぶrouting
- status、ambiguity、truncation、provenanceの解釈
- 対象外だけをsource検索するfallback
- 編集後に同じsemantic queryを再実行する検証手順

将来BEAR.Skills側へ取り込む場合も、この共通Skillを各Skillへ複製せず、個別Skillから共通手順を
参照する形にします。MCP toolがないagentでは従来workflowへfallbackします。

## 責務の分離

```text
BEAR.Skills
  判断基準 / 手順 / approval / 編集 / command実行
                       ↓ bounded query
bear-sunday-mcp-server
  tool schema / safety boundary / response normalization
                       ↓ custom LSP
bear-phpactor-extension
  parser / index / BEAR Semantic Model / deterministic facts
```

MCPへ入れるもの:

- 保存済みsourceから決定的に取得できる事実
- 対応構文と規約が明示された関係
- exact path/rangeを伴う診断候補
- 欠落、曖昧、未対応を区別するstatus
- 判断根拠となるprovenance

Skillへ残すもの:

- 設計の良し悪し
- project固有のstyle選択
- 複数案のtrade-off
- ファイル編集、生成、migration
- Composer、test、scanner、applicationの実行
- user approvalを必要とする変更

## Skill別対応表

| Skill | MCPから提供すべき事実 | Skillに残す処理 | 優先度 |
|---|---|---|---|
| `bear-cacheable` | read method、cache attribute、Purge/Refresh、Link/Embed、static dependency | Cacheable/Donut/HTTP cacheの選択 | P0 |
| `bear-hypermedia` | Resource relation graph、ALPS relation、欠落・余分なedge候補 | rel設計、attribute追加、workflow test作成 | P0 |
| `bear-review` | method/parameter/return type、attribute、relation、schema/template coverage | grade、改善提案、PHPMD実行 | P1 |
| `bear-to-alps` | Resourceとmethodのinventory、relation、schema property | ALPS生成、semantic命名、文書編集 | P1 |
| `bear-from-alps` | ALPS descriptor graph、既存Resourceとの対応 | project/resource生成、package導入 | P1 |
| `bear-resource-gen` | namespace、既存Resource規約、schema/template配置、生成後の解決結果 | migration、interface、SQL、Resource、testの生成 | P1 |
| `bear-smoke-test` | Resource URI/method一覧、schema、relation、route | test code生成とapplication実行 | P1 |
| `bear-audit-fix` | ApiDoc operationとResource/method/schema/ALPSの対応 | audit生成、修正、再audit | P1 |
| `bear-preflight` | BEAR attribute/configurationの静的facts | compile、SAST、Composer audit、test実行と判定 | P2 |
| `bear-clean-style-consultant` | reachability、attribute、contract coverageの候補 | level選択とproject適合判断 | P2 |
| `bear-clean-style` | 同上、およびexact path/range | opinionatedな変更とbatch設計 | P2 |
| `bear-refactor` | 対象symbol、Named/Qualifier利用箇所、Resource method signature | source変換、format、test | P2 |
| `bear-documenter` | Resource/method/attributeの構造 | 自然言語の説明生成とPHPDoc編集 | P3 |
| `bear-web-form` | request schema、Resource method、route、template | architecture選択、validation/CSRF実装 | P3 |
| `bear-security-setup` | BEAR entrypoint/contextとsource location | package導入、scanner実行、finding修正 | 対象外 |
| `bear-migration` | 移行後BEAR側のinventoryとcontract | 旧application解析、移行設計、behavior preservation | 対象外 |

`bear-clean-style`のようなproject opinionを、BEAR全体の必須規則としてSemantic APIへ入れては
いけません。Semantic APIは観測可能なfactsを返し、Skillがproject方針と照合します。

## Semantic API実装状況

2026-09-21時点で、以下のResource attribute facts、contract comparison、project diagnostics、
contract coverageは
coreとMCP adapterに実装済みです。

### 1. Resource attribute facts

単一Resourceとbounded inventoryの2つを分けます。

```text
bear/resource/attributes
bear/resource/attributeIndex
```

最低限のdata:

- Resource identity (`uri`, `fqn`, `path`)
- public `on*` methods
- parameter/return type
- class/method attribute FQN
- 対応済みattributeのstatic argument
- attributeとmethodのsource range
- unsupported/dynamic argumentの明示

実装では重複を避け、methodとparameterは既存の`bear_resource_describe`、属性は
`bear_resource_attributes` / `bear_resource_attribute_index`から取得します。return typeの
構造化取得は未対応で、対応済みと見なしてはいけません。

初期対応attribute:

- `Cacheable`, `CacheableResponse`, `DonutCache`, `HttpCache`
- `Purge`, `Refresh`
- `Link`, `Embed`
- `JsonSchema`, `Alps`

生のattribute text全体は返しません。FQNと意味が確定するstatic argumentだけをDTOへ変換します。

### 2. Contract comparison

```text
bear/contract/compare
```

比較面:

- Resource method/parameter
- JSON Schema request/response property
- ALPS descriptor/transition
- ApiDoc operation（対応後）

最初はpresence-only比較に限定します。名前が同じことを型や意味の一致とは扱いません。

例:

```json
{
  "status": "ok",
  "data": {
    "resource": {"uri": "app://self/user"},
    "compared": ["resource", "schema", "alps"],
    "onlyInResource": [],
    "onlyInSchema": ["display_name"],
    "onlyInAlps": [],
    "common": ["id", "name"]
  },
  "candidates": [],
  "provenance": []
}
```

### 3. Project diagnostics

```text
bear/project/diagnostics
```

保存済みsourceから静的に証明できる不整合だけを、件数制限付きで返します。

```json
{
  "status": "ok",
  "data": {
    "items": [{
      "code": "relation_method_not_found",
      "status": "not_found",
      "subject": "app://self/user#onPatch",
      "path": "src/Resource/App/Dashboard.php",
      "byteRange": {"start": 282, "end": 341},
      "details": {"kind": "link", "rel": "edit"}
    }],
    "total": 1,
    "offset": 0,
    "truncated": false,
    "scannedFiles": 7,
    "scannedResources": 3,
    "resourceScanTruncated": false,
    "skippedChecks": []
  }
}
```

外側のqueryは個別fileや参照が壊れていても`ok`を保ち、その不整合をitemとして返します。
`truncated`、`resourceScanTruncated`、`skippedChecks`を確認せずに「問題なし」と判断してはいけません。
`truncated`がtrueなら、返却件数を現在の`offset`に加えて次pageを取得します。
cacheすべきか、設計が良いかといったheuristicやproject opinionは診断に含めず、Skillが別途判断します。

### 4. Contract adoption coverage

```text
bear/project/contractCoverage
```

保存済みResource methodごとにrequest Schema、response Schema、ALPS descriptorを調べ、
`available`、`absent`、`dynamic`、`unresolved`、`not_applicable`へ分類します。
`absent`は任意artifactの未導入候補でありerrorではありません。`dynamic`はsource確認が必要で、
`unresolved`は明示宣言が解決できない状態です。`covered`も品質scoreではなく、適用対象surfaceが
静的に利用可能という意味だけです。Skillは走査打ち切りを確認し、project方針に基づいて小さな
導入batchを提案しますが、Semantic API自身はartifactを生成しません。gap調査では`gapsOnly: true`を
使い、`truncated`がtrueの間は`offset`で全pageを取得します。

## Skillが使う基本workflow

1. `bear_project_info`でcapabilityとversionを確認する。
2. project全体のreviewでは`bear_project_diagnostics`を呼び、走査範囲とskipを確認する。
3. contract導入支援では`bear_contract_coverage`を呼び、未導入と解析不能を分ける。
4. BEAR identifierが分かる場合はGrepより先にsemantic toolを呼ぶ。
5. `status`、`available`、`truncated`を確認する。
6. provenanceにある最小限のファイルだけを読む。
7. semantic toolが対象外と明示した部分だけを検索する。
8. Skillの判断基準を適用する。
9. 編集後、同じqueryを再実行してsemantic resolutionを確認する。
10. test/static analysisは別のcommandとして実行する。

## 非目標

- Skillのpromptや長いreference文書をMCP responseへ埋め込むこと
- MCP serverからSkillを起動すること
- arbitrary shell command toolを追加すること
- opinionatedなstyleをBEAR Semantic APIのerrorにすること
- unsupported/dynamic codeを推測で補完すること

## Acceptance criteria

- 同じ保存済みworkspaceへの同じqueryは決定的な順序で同じ結果を返す。
- すべてのdiagnostic itemに安定したcode、semantic status、subject、workspace相対pathがある。
- responseは走査件数、診断itemの打ち切り、Resource走査の打ち切り、skipされた検査を区別する。
- path traversal、workspace外symlink、oversized inputを拒否する。
- MCP toolはread-onlyのままにする。
- Skillなしでもresponseの意味がtool descriptionと文書から分かる。
- SkillはMCPが利用できない場合も従来workflowへfallbackできる。
