# はじめての導入（日本語）

BEAR.SundayのアプリについてAIに質問したとき、AIがgrepで推測するのではなく、ソースから導いた事実で答えられるようにするMCPサーバーです。MCP（Model Context Protocol）は、AIのクライアントに「呼べる道具」を足すための仕組みです。この手順では、Claude Codeから使えるようにするところまでを3ステップで説明します。

道具はすべて読み取りだけで、アプリのファイルを書き換えません。アプリのディレクトリにパッケージや設定ファイルを追加することもありません。

## 必要なもの

- PHP 8.2以上（拡張`mbstring`・`posix`・`tokenizer`）
- Composer
- Git（導入時に、同梱のPhpactorへ小さな修正パッチを当てるため）
- Claude Code（ほかのMCPクライアントの設定は[README](../README.md)を参照してください）
- 調べたいBEAR.Sundayアプリ（`composer install`済みのもの）

## 1. 入れる

アプリとは別の、専用のディレクトリに入れます。ここでは`~/.local/share/bear-sunday-mcp-server`にします。

```console
composer create-project --no-dev --prefer-dist \
  suzumaze/bear-sunday-mcp-server \
  ~/.local/share/bear-sunday-mcp-server \
  '^0.12'
```

MCPサーバー本体と、コード解析エンジンのPhpactor、BEAR.Sunday用のPhpactor拡張が、まとめてこのディレクトリに入ります。

## 2. Claude Codeに登録する

調べたいアプリのディレクトリで登録します。`--scope local`は「このプロジェクトの、自分だけの設定」という意味で、リポジトリには何も書き込みません。

```console
cd /path/to/your-bear-app
claude mcp add --scope local --transport stdio bear-sunday -- \
  ~/.local/share/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace="$PWD" --timeout=60
```

`--workspace`には調べるアプリの絶対パスを渡します。`--timeout`は1回の問い合わせを待つ秒数で、既定は20秒、上限は60秒です。大きなアプリでは60を勧めます。

登録できたかは、Claude Codeを起動して`/mcp`で確かめられます。`bear-sunday`が接続済みになっていれば準備完了です。

## 3. 聞いてみる

道具の名前を指定する必要はありません。ふつうの言葉で聞けば、AIが道具を選びます。

```text
このプロジェクトのResourceを一覧して
起動コードに書かれているコンテキストを、書かれている場所と一緒に教えて
prod-hal-appのとき、AuthInterfaceには何が束縛される？ どのModuleのどの行で決まる？
page://self/article のメソッドと、埋め込んでいるResourceを教えて
app://self/article を参照している場所を全部挙げて
```

[BEAR.Kata](https://github.com/bearsunday/BEAR.Kata)で試すと、Resourceは41個、コンテキストは9個見つかります。3つ目の質問には、次のような答えが返ります。

- 束縛先は環境変数`CMS_AUTH_PROVIDER`で分かれるので、ソースだけでは1つに決まらない（`branch_dependent`）
- 候補は`Auth0AuthProvider`（条件が真のとき）と`GoogleAuthProvider`（偽のとき）
- 分かれ目は`src/Module/AppModule.php`の110行目

Aura.Routerのルート定義があるアプリなら、「/article/1というURLはどのPage Resourceに届く？」のようにURLから引くこともできます（BEAR.Kataにはルート定義がないので、この質問には「対応していない」と返ります）。

ほかの質問の例と、答えの読み方は[使い方の例](use-cases.ja.md)にあります。道具の一覧は[ツール一覧](tools.ja.md)です。

## 知っておいてほしいこと

- **答えは保存されたソースから導いたものです。** PHPを実行した結果ではありません。ソースだけで決まらないもの（環境変数で分かれる束縛など）は、推測せずに「決まらない」と返し、分かる範囲で候補と場所を示します。
- **起動直後の1回目は時間切れになることがあります。** Phpactorは起動するとワークスペースの全ファイルを数え、初回は索引も作ります。数十万ファイルあるアプリでは、それが終わる前の問い合わせが時間切れになります。もう一度聞けば答えます。
- **編集したファイルは、保存してから聞いてください。** 保存していない変更は見えません。
- **新しい版に上げるとき**は、別のディレクトリに入れてから登録し直すと、古い版に戻しやすくなります。開いているClaude Codeのセッションは古いサーバーを使い続けるので、`/mcp`から再接続するか、開き直してください。

## AI向けの手引き（任意）

同梱の[`bear-semantic`スキル](../skills/bear-semantic/SKILL.md)は、grepよりこの道具を先に使うこと、答えに含まれる「未確定」や「打ち切り」の読み方をAIに教えます。入れなくても道具は使えます。入れる場合は、`skills/bear-semantic`ディレクトリを、お使いのAIクライアントのスキルの置き場所に置いてください。
