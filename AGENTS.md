# f2m.php リファクタリング方針
  
  ## 目的
  
  既存の `f2m.php` は、仕様・挙動を可能な限り引き継ぎつつ、PHP 8.2+ / Composer 前提の構成へ整理する。
  
  ただし、全面的なオブジェクト指向化は行わない。  
  今回の規模では `new Xxx()` を多用する構成は迂遠になりやすいため、**名前空間付き関数による関数志向設計**を採用する。
  
  ## 基本方針
  
  - 既存仕様を優先する
  - 旧コードの処理順・画面遷移・入力仕様・メール仕様・CSV仕様を維持する
  - Composer を使用する
  - 外部ライブラリは `php/vendor/` に配置する
  - 自作コードは `php/src/` に配置する
  - `class static` による関数置き場化は行わない
  - グローバル関数は作らず、必ず名前空間付き関数にする
  - フレームワークは導入しない
  - 既存フォームHTMLと設定TXTは root 直下に残す
  - `f2m.php` は薄い入口として扱う
  
  ## ディレクトリ構成
  
  ```txt
  root/
    f2m.php
    f2m_conf.txt
    index.html
    confirm.html
    thanks.html
  
    php/
      composer.json
      composer.lock
      vendor/
      src/
        Config/load.php
        Request/build.php
        Validation/validate.php
        Mail/send.php
        Csv/write.php
        File/upload.php
        Render/render.php
      templates/
      var/
        cache/
        uploads/
  ~~~

  

 ## 旧f2m.php
 _old/以下に格納してあるので随時参照すること
 _old/はAI作業時のローカル参照資料であり、Git管理・配布対象外とする

  

  ## ファイル配置ルール

  

  ### root直下

  利用者・設置者が触るファイルのみ置く。

  ```txt
  f2m.php          メイン入口
  f2m_conf.txt     設定ファイル
  *.html           フォームHTML、確認画面、完了画面など
  ```

  ### php/配下

  内部実装・Composer管理・一時ファイル・テンプレート等をまとめる。

  ```txt
  php/src/         自作PHPコード
  php/vendor/      Composerライブラリ
  php/templates/   内部テンプレート
  php/var/         キャッシュ・一時アップロード
  ```

  ## Composer 方針

  `composer.json` は `php/` 配下に置く。
  `composer install` は `root/php/` で実行する。

  ```json
  {
    "require": {
      "php": "^8.2",
      "phpmailer/phpmailer": "^6.9",
      "smarty/smarty": "^5.0"
    },
    "autoload": {
      "files": [
        "src/Config/load.php",
        "src/Request/build.php",
        "src/Validation/validate.php",
        "src/Mail/send.php",
        "src/Csv/write.php",
        "src/File/upload.php",
        "src/Render/render.php"
      ]
    }
  }
  ```

  ## 名前空間・関数設計

  関数はすべて `F2m\...` 名前空間に置く。

  ```txt
  \F2m\Config\load()
  \F2m\Request\build()
  \F2m\Validation\validate()
  \F2m\Mail\send_admin()
  \F2m\Mail\send_reply()
  \F2m\Csv\write()
  \F2m\File\upload()
  \F2m\Render\render()
  ```

  ## f2m.php の役割

  `f2m.php` は処理本体を書き込まず、全体の流れだけを記述する。

  ```php
  <?php
  
  require __DIR__ . '/php/vendor/autoload.php';
  
  $config = \F2m\Config\load(__DIR__ . '/f2m_conf.txt');
  
  $request = \F2m\Request\build(
      postFields: $_POST,
      uploadedFiles: $_FILES,
      sessionValues: $_SESSION
  );
  
  $uploadedFiles = \F2m\File\upload($config, $request);
  
  $errors = \F2m\Validation\validate($config, $request, $uploadedFiles);
  
  if ($errors !== []) {
      \F2m\Render\render('input', $config, $request, $errors);
      exit;
  }
  
  if ($request['mode'] === 'confirm') {
      \F2m\Render\render('confirm', $config, $request, []);
      exit;
  }
  
  \F2m\Mail\send_admin($config, $request, $uploadedFiles);
  
  if (!empty($config['reply_mail_enabled'])) {
      \F2m\Mail\send_reply($config, $request, $uploadedFiles);
  }
  
  \F2m\Csv\write($config, $request, $uploadedFiles);
  
  \F2m\Render\render('thanks', $config, $request, []);
  ```

  ## 各ファイルの責務

  ### `Config/load.php`

  設定TXTを読み込み、アプリ内で扱いやすい配列へ変換する。

  ```php
  namespace F2m\Config;
  
  function load(string $configPath): array
  ```

  ### `Request/build.php`

  `$_POST`、`$_FILES`、`$_SESSION` を直接扱う範囲をここに集約する。

  ```php
  namespace F2m\Request;
  
  function build(array $postFields, array $uploadedFiles, array &$sessionValues): array
  ```

  ### `Validation/validate.php`

  入力値の検証を行い、エラー配列を返す。

  ```php
  namespace F2m\Validation;
  
  function validate(array $config, array $request, array $uploadedFiles = []): array
  ```

  ### `Mail/send.php`

  管理者宛メールと自動返信メールを送信する。
  PHPMailer を使用する。

  ```php
  namespace F2m\Mail;
  
  function send_admin(array $config, array $request, array $uploadedFiles = []): void
  
  function send_reply(array $config, array $request, array $uploadedFiles = []): void
  ```

  ### `Csv/write.php`

  送信内容をCSVへ追記する。

  ```php
  namespace F2m\Csv;
  
  function write(array $config, array $request, array $uploadedFiles = []): void
  ```

  ### `File/upload.php`

  添付ファイルの検証・一時保存・保存済みファイル情報の生成を行う。

  ```php
  namespace F2m\File;
  
  function upload(array $config, array $request): array
  ```

  ### `Render/render.php`

  入力画面・確認画面・完了画面の表示を担当する。
  既存Smarty資産を使う場合は Smarty をここに閉じ込める。

  ```php
  namespace F2m\Render;
  
  function render(
      string $pageType,
      array $config,
      array $request,
      array $errors = []
  ): void
  ```

  ## コーディング方針

  - 既存仕様を壊さない
  - まず処理分割を優先し、大規模な設計変更は避ける
  - 関数は副作用をできるだけ限定する
  - `$_POST` / `$_FILES` / `$_SESSION` の直接参照は `Request/build.php` と入口付近に限定する
  - メール送信、CSV書込、ファイル保存など副作用のある処理は専用ファイルに分離する
  - 個人情報保護のため、送信内容やエラー内容のログ保存は行わない
  - 古いPHP関数はPHP 8.2+で動作する形に置き換える
  - 文字コード変換が必要な場合は、既存仕様を確認してから変更する
  - `static class` は使わない
  - 実体のない抽象化や過剰なクラス分割はしない

  ## コメント方針

  クラスは原則作らない。
  関数には簡潔なヘッダーコメントを付ける。

  ```php
  /**
   * フォーム設定を読み込みます。
   */
  function load(string $configPath): array
  ```

  処理ブロックコメントは以下の形式に統一する。

  ```php
  // ---------------------------------------------
  // 設定ファイル読込
  // ---------------------------------------------
  ```

  番号付きコメントは使わない。

  ## 生成時の注意

  AI生成時は、旧 `f2m.php` の仕様を推測で補完しない。
  必ず既存コードの挙動を確認し、以下を維持する。

  - 設定TXTの記法
  - フォーム項目の扱い
  - 必須チェック
  - メール本文の生成仕様
  - 自動返信仕様
  - CSV保存仕様
  - 添付ファイル仕様
  - 確認画面・完了画面の遷移
  - セッション利用仕様
  - 文字コード仕様


## 進め方

1. 現行 f2m.php の主要挙動を確認
2. ディレクトリ構成・Composer構成を作成
3. 処理を名前空間付き関数へ分割
4. f2m.php を薄い入口へ変更
5. 旧版と新版で同じ入力を流して差分確認
