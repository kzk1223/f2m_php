<?php
declare(strict_types=1);

/**
 * フォームメール入口処理。
 *
 * root直下のフォーム送信先として配置する薄い入口。
 * 処理本体はphp/src配下の名前空間付き関数へ委譲。
 *
 * 使用方法:
 * php/ディレクトリでcomposer installを実行。
 * HTMLフォームのactionにf2m.phpを指定。
 * hidden項目F2M_IDにf2m_conf.txt内の設定IDを指定。
 *
 * 配置前提:
 * f2m_conf.txtとフォームHTMLはroot直下に配置。
 * Composer依存関係と自作処理はphp/配下で管理。
 * 一時ファイルとキャッシュはphp/var配下に保存。
 * 個人情報保護のため、送信内容やエラー内容のログ保存は行わない。
 */

// ---------------------------------------------
// セッション開始
// ---------------------------------------------
session_start();

// ---------------------------------------------
// Composer autoload読込
// ---------------------------------------------
$autoloadPath = __DIR__ . '/php/vendor/autoload.php';

if (!is_file($autoloadPath)) {
    require __DIR__ . '/php/src/Render/render.php';

    \F2m\Render\error('Composer autoload not found. Run composer install in php directory.');
    exit;
}

require $autoloadPath;

$config = null;

try {
    // ---------------------------------------------
    // 設定読込
    // ---------------------------------------------
    $configs = \F2m\Config\load(__DIR__ . '/f2m_conf.txt');

    // ---------------------------------------------
    // リクエスト構築
    // ---------------------------------------------
    $request = \F2m\Request\build(
        postFields: $_POST,
        uploadedFiles: $_FILES,
        sessionValues: $_SESSION
    );

    $config = $configs[$request['f2m_id']] ?? null;

    // ---------------------------------------------
    // 設定存在検証
    // ---------------------------------------------
    if ($config === null) {
        \F2m\Render\error('F2M_ID config error');
        exit;
    }

    // ---------------------------------------------
    // 一時添付ファイル整理
    // ---------------------------------------------
    \F2m\File\refresh_uploads($config);

    // ---------------------------------------------
    // 入力画面復帰
    // ---------------------------------------------
    if ($request['return_requested'] || $request['mode'] === 'form') {
        \F2m\Render\render('form', $config, $request);
        exit;
    }

    // ---------------------------------------------
    // 入力値検証
    // ---------------------------------------------
    $errors = \F2m\Validation\validate($config, $request);

    if ($errors !== []) {
        \F2m\Render\render('error', $config, $request, $errors);
        exit;
    }

    // ---------------------------------------------
    // 送信処理
    // ---------------------------------------------
    if ($request['mode'] === 'send') {
        if (!\F2m\Request\consume_csrf_token($request)) {
            echo '送信エラー';
            exit;
        }

        if (!empty($config['F2M_TO'])) {
            \F2m\Mail\send_admin($config, $request);
        }

        if (isset($config['F2M_RESV_TO_FLD'])) {
            \F2m\Mail\send_reply($config, $request);
        }

        \F2m\Csv\write($config, $request);
        \F2m\Render\render('thanks', $config, $request);
        exit;
    }

    // ---------------------------------------------
    // 確認画面表示
    // ---------------------------------------------
    $uploadedFiles = \F2m\File\upload($config, $request);

    \F2m\Render\render('confirm', $config, $request, [], $uploadedFiles);
} catch (Throwable $exception) {
    // ---------------------------------------------
    // 例外表示
    // ---------------------------------------------
    \F2m\Render\error(
        $exception->getMessage(),
        is_array($config) && \F2m\Render\debug_enabled($config)
    );
}
