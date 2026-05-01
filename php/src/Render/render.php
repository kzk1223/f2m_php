<?php
declare(strict_types=1);

/**
 * 画面描画処理。
 */

namespace F2m\Render;

use RuntimeException;

/**
 * 画面種別に対応するSmartyテンプレートへ値を割り当てて表示。
 *
 * @param string $pageType 表示画面種別。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return void
 * @throws RuntimeException 画面種別またはSmarty利用に失敗した場合。
 */
function render(
    string $pageType,
    array $config,
    array $request,
    array $errors = [],
    array $uploadedFiles = []
): void {
    $templatePath = template_path($pageType, $config);

    // ---------------------------------------------
    // 入力画面表示
    // ---------------------------------------------
    if ($pageType === 'form') {
        display_form($templatePath, $config, $request, $errors);
        return;
    }

    // ---------------------------------------------
    // Smarty割当値構築
    // ---------------------------------------------
    $assignedValues = [
        'err' => $errors,
        'form' => form_rows($config, $request),
        'post' => $request['form_fields'] ?? [],
        'send' => send_values($request, $pageType === 'confirm'),
        'uploadedFiles' => $uploadedFiles,
    ];

    display_template($config, $templatePath, $assignedValues);
}

/**
 * 例外や設定不備を簡易HTMLとしてエスケープ出力。
 *
 * @param string $message エラーメッセージ。
 * @param bool $showDetail 詳細メッセージを表示する場合はtrue。
 * @return void
 */
function error(string $message, bool $showDetail = false): void
{
    // ---------------------------------------------
    // 簡易エラーHTML出力
    // ---------------------------------------------
    $displayMessage = $showDetail
        ? 'ERROR:' . $message
        : '送信処理でエラーが発生しました。時間をおいて再度お試しください。';

    printf(
        '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><title>送信エラー</title></head><body><p>%s</p></body></html>',
        htmlspecialchars($displayMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );
}

/**
 * 詳細エラー表示設定を判定。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return bool 詳細エラー表示を行う場合はtrue。
 */
function debug_enabled(array $config): bool
{
    // ---------------------------------------------
    // デバッグ表示判定
    // ---------------------------------------------
    return isset($config['F2M_DEBUG'])
        && preg_match('/^(true|yes|1|on)$/i', (string)$config['F2M_DEBUG']) === 1;
}

/**
 * Smartyテンプレートへ指定値を割り当て、表示せず文字列として取得。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param string $templatePath テンプレートパス。
 * @param array<string, mixed> $assignedValues Smarty割当値。
 * @return string 展開済みテンプレート文字列。
 * @throws RuntimeException Smarty利用に失敗した場合。
 */
function fetch_template(array $config, string $templatePath, array $assignedValues): string
{
    $smarty = create_smarty($config);

    // ---------------------------------------------
    // Smarty変数割当
    // ---------------------------------------------
    foreach ($assignedValues as $assignName => $assignValue) {
        $smarty->assign($assignName, $assignValue);
    }

    return $smarty->fetch($templatePath);
}

/**
 * Smartyテンプレートへ指定値を割り当て、HTTPレスポンスとして表示。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param string $templatePath テンプレートパス。
 * @param array<string, mixed> $assignedValues Smarty割当値。
 * @return void
 * @throws RuntimeException Smarty利用に失敗した場合。
 */
function display_template(array $config, string $templatePath, array $assignedValues): void
{
    $smarty = create_smarty($config);

    // ---------------------------------------------
    // Smarty変数割当
    // ---------------------------------------------
    foreach ($assignedValues as $assignName => $assignValue) {
        $smarty->assign($assignName, $assignValue);
    }

    $smarty->display($templatePath);
}

/**
 * セッションに保持したフォーム値を入力画面向けに割り当てて表示。
 *
 * @param string $templatePath 入力画面テンプレートパス。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @return void
 * @throws RuntimeException Smarty利用に失敗した場合。
 */
function display_form(string $templatePath, array $config, array $request, array $errors = []): void
{
    // ---------------------------------------------
    // 入力画面HTML生成
    // ---------------------------------------------
    $html = fetch_template($config, $templatePath, [
        'form' => form_rows($config, $request),
        'post' => $request['form_fields'] ?? [],
        'send' => send_values($request),
    ]);

    echo apply_form_values($html, $request['form_fields'] ?? [], $errors, $config);
}

/**
 * Smarty 5または旧Smartyクラスを解決し、テンプレート設定を反映。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return object Smartyインスタンス。
 * @throws RuntimeException Smartyクラスが存在しない場合。
 */
function create_smarty(array $config): object
{
    // ---------------------------------------------
    // Smartyクラス解決
    // ---------------------------------------------
    $smartyClass = class_exists('\Smarty\Smarty')
        ? '\Smarty\Smarty'
        : (class_exists('\Smarty') ? '\Smarty' : null);

    if ($smartyClass === null) {
        throw new RuntimeException('Smarty not installed');
    }

    $smarty = new $smartyClass();
    $rootDirectory = (string)($config['_root_dir'] ?? dirname(__DIR__, 3));

    // ---------------------------------------------
    // Smartyテンプレート設定
    // ---------------------------------------------
    set_smarty_option($smarty, 'setTemplateDir', 'template_dir', $rootDirectory);
    set_smarty_option($smarty, 'setCompileDir', 'compile_dir', $rootDirectory . '/php/var/cache');
    set_smarty_option($smarty, 'setConfigDir', 'config_dir', $rootDirectory . '/php/templates');
    set_smarty_option($smarty, 'setLeftDelimiter', 'left_delimiter', '<!--{');
    set_smarty_option($smarty, 'setRightDelimiter', 'right_delimiter', '}-->');

    return $smarty;
}

/**
 * SmartyのSetterメソッドまたは旧プロパティへ指定値を反映。
 *
 * @param object $smarty Smartyインスタンス。
 * @param string $methodName Setterメソッド名。
 * @param string $propertyName 旧Smarty互換プロパティ名。
 * @param string $value 設定値。
 * @return void
 */
function set_smarty_option(object $smarty, string $methodName, string $propertyName, string $value): void
{
    // ---------------------------------------------
    // Setterメソッド優先設定
    // ---------------------------------------------
    if (method_exists($smarty, $methodName)) {
        $smarty->{$methodName}($value);
        return;
    }

    // ---------------------------------------------
    // 旧Smarty互換プロパティ設定
    // ---------------------------------------------
    $smarty->{$propertyName} = $value;
}

/**
 * 画面種別から旧設定キーに対応するテンプレートパスを取得。
 *
 * @param string $pageType 表示画面種別。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return string テンプレートパス。
 * @throws RuntimeException 未対応の画面種別が指定された場合。
 */
function template_path(string $pageType, array $config): string
{
    // ---------------------------------------------
    // 画面種別別テンプレート取得
    // ---------------------------------------------
    return match ($pageType) {
        'confirm' => (string)($config['F2M_CONFIRM'] ?? ''),
        'error' => (string)($config['F2M_FORMERR'] ?? ''),
        'thanks' => (string)($config['F2M_THANKS'] ?? ''),
        'form', 'input' => (string)($config['F2M_FORM'] ?? ''),
        default => throw new RuntimeException(sprintf('%s page type error', $pageType)),
    };
}

/**
 * F2M_JPNAMEの順序に従って、確認画面・エラー画面用の表示行を構築。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return array<int, array{fld: string, val: mixed}> 表示用フォーム行配列。
 */
function form_rows(array $config, array $request): array
{
    $formRows = [];
    $formFields = $request['form_fields'] ?? [];

    // ---------------------------------------------
    // 確認・エラー画面用行構築
    // ---------------------------------------------
    foreach (($config['F2M_JPNAME'] ?? []) as $fieldName => $fieldLabel) {
        $formRows[] = [
            'fld' => $fieldLabel,
            'val' => $formFields[$fieldName] ?? '',
        ];
    }

    return $formRows;
}

/**
 * 入力画面HTMLのフォーム要素へ入力値とエラー表示を反映。
 *
 * @param string $html 入力画面HTML。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return string フォーム値反映済みHTML。
 * @throws RuntimeException DOM拡張が利用できない場合。
 */
function apply_form_values(string $html, array $formFields, array $errors = [], array $config = []): string
{
    // ---------------------------------------------
    // 反映値存在判定
    // ---------------------------------------------
    if ($formFields === [] && $errors === []) {
        return $html;
    }

    if (!class_exists(\DOMDocument::class)) {
        throw new RuntimeException('DOM extension not installed');
    }

    // ---------------------------------------------
    // HTML DOM構築
    // ---------------------------------------------
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previousErrorMode = \libxml_use_internal_errors(true);

    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    remove_xml_encoding_node($document);

    \libxml_clear_errors();
    \libxml_use_internal_errors($previousErrorMode);

    // ---------------------------------------------
    // フォーム値反映
    // ---------------------------------------------
    apply_input_values($document, $formFields);
    apply_textarea_values($document, $formFields);
    apply_select_values($document, $formFields);
    apply_form_errors($document, $config, $errors);

    return $document->saveHTML();
}

/**
 * 入力画面HTMLへエラー概要と項目別エラーを反映。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @return void
 */
function apply_form_errors(\DOMDocument $document, array $config, array $errors): void
{
    // ---------------------------------------------
    // エラー存在判定
    // ---------------------------------------------
    if ($errors === []) {
        return;
    }

    $fieldErrorMessages = field_error_messages($config, $errors);

    // ---------------------------------------------
    // エラー表示反映
    // ---------------------------------------------
    inject_error_summary($document, $errors);
    inject_field_errors($document, $fieldErrorMessages);
}

/**
 * フォーム先頭へエラー概要を挿入。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @return void
 */
function inject_error_summary(\DOMDocument $document, array $errors): void
{
    $formElement = $document->getElementsByTagName('form')->item(0);

    if (!$formElement instanceof \DOMElement) {
        return;
    }

    // ---------------------------------------------
    // エラー概要DOM生成
    // ---------------------------------------------
    $summaryElement = $document->createElement('div');
    $summaryElement->setAttribute('class', 'f2m-error-summary');
    $summaryElement->setAttribute('role', 'alert');

    $messageElement = $document->createElement('p');
    $messageElement->appendChild($document->createTextNode('入力内容に不備があります。'));
    $summaryElement->appendChild($messageElement);

    $listElement = $document->createElement('ul');

    foreach ($errors as $error) {
        $fieldLabel = (string)($error['fld'] ?? '');
        $errorMessage = (string)($error['errmes'] ?? '');

        $itemElement = $document->createElement('li');
        $itemElement->appendChild($document->createTextNode(error_text($fieldLabel, $errorMessage)));
        $listElement->appendChild($itemElement);
    }

    $summaryElement->appendChild($listElement);
    $formElement->insertBefore($summaryElement, $formElement->firstChild);
}

/**
 * 入力要素の直後へ項目別エラーを挿入。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<string, array<int, string>> $fieldErrorMessages 項目別エラー配列。
 * @return void
 */
function inject_field_errors(\DOMDocument $document, array $fieldErrorMessages): void
{
    $displayedFieldNames = [];

    // ---------------------------------------------
    // 項目別エラーDOM生成
    // ---------------------------------------------
    foreach (form_control_elements($document) as $controlElement) {
        $fieldName = normalized_field_name($controlElement->getAttribute('name'));

        if ($fieldName === '' || isset($displayedFieldNames[$fieldName]) || !isset($fieldErrorMessages[$fieldName])) {
            continue;
        }

        $errorId = field_error_id($fieldName);
        $controlElement->setAttribute('aria-invalid', 'true');
        append_aria_describedby($controlElement, $errorId);

        $errorElement = $document->createElement('p');
        $errorElement->setAttribute('class', 'f2m-field-error');
        $errorElement->setAttribute('id', $errorId);
        $errorElement->appendChild($document->createTextNode(implode(' / ', $fieldErrorMessages[$fieldName])));

        insert_after($controlElement, $errorElement);
        $displayedFieldNames[$fieldName] = true;
    }
}

/**
 * DOM内のフォーム入力要素を取得。
 *
 * @param \DOMDocument $document HTML DOM。
 * @return array<int, \DOMElement> フォーム入力要素配列。
 */
function form_control_elements(\DOMDocument $document): array
{
    $controlElements = [];

    // ---------------------------------------------
    // 入力要素収集
    // ---------------------------------------------
    foreach (['input', 'textarea', 'select'] as $tagName) {
        foreach ($document->getElementsByTagName($tagName) as $controlElement) {
            if ($controlElement instanceof \DOMElement) {
                $controlElements[] = $controlElement;
            }
        }
    }

    return $controlElements;
}

/**
 * エラー配列をフォーム項目名単位へ変換。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<int, array<string, string>> $errors エラー表示用配列。
 * @return array<string, array<int, string>> 項目別エラー配列。
 */
function field_error_messages(array $config, array $errors): array
{
    $fieldErrorMessages = [];
    $fieldLabelMap = field_label_map($config);

    // ---------------------------------------------
    // 項目名別エラー変換
    // ---------------------------------------------
    foreach ($errors as $error) {
        $fieldLabels = array_map('trim', explode(',', (string)($error['fld'] ?? '')));
        $errorMessage = (string)($error['errmes'] ?? '');

        foreach ($fieldLabels as $fieldLabel) {
            $fieldName = (string)($fieldLabelMap[$fieldLabel] ?? '');

            if ($fieldName === '' || $errorMessage === '') {
                continue;
            }

            $fieldErrorMessages[$fieldName][] = $errorMessage;
        }
    }

    return $fieldErrorMessages;
}

/**
 * 表示用項目名からフォーム項目名へのマップを生成。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return array<string, string> 表示用項目名をキーにしたフォーム項目名配列。
 */
function field_label_map(array $config): array
{
    $fieldLabelMap = [];
    $fieldLabels = $config['F2M_JPNAME'] ?? [];

    // ---------------------------------------------
    // 表示用項目名マップ生成
    // ---------------------------------------------
    if (!is_array($fieldLabels)) {
        return $fieldLabelMap;
    }

    foreach ($fieldLabels as $fieldName => $fieldLabel) {
        if (!is_scalar($fieldLabel)) {
            continue;
        }

        $fieldLabelMap[(string)$fieldLabel] = (string)$fieldName;
    }

    return $fieldLabelMap;
}

/**
 * エラー概要表示用テキストを生成。
 *
 * @param string $fieldLabel 表示用項目名。
 * @param string $errorMessage エラーメッセージ。
 * @return string エラー概要表示用テキスト。
 */
function error_text(string $fieldLabel, string $errorMessage): string
{
    // ---------------------------------------------
    // エラーテキスト生成
    // ---------------------------------------------
    if ($fieldLabel === '') {
        return $errorMessage;
    }

    if ($errorMessage === '') {
        return $fieldLabel;
    }

    return $fieldLabel . ': ' . $errorMessage;
}

/**
 * name属性の配列表記を通常項目名へ変換。
 *
 * @param string $fieldName フォーム項目名。
 * @return string 正規化済みフォーム項目名。
 */
function normalized_field_name(string $fieldName): string
{
    // ---------------------------------------------
    // 配列表記除去
    // ---------------------------------------------
    return str_ends_with($fieldName, '[]')
        ? substr($fieldName, 0, -2)
        : $fieldName;
}

/**
 * 項目別エラー要素IDを生成。
 *
 * @param string $fieldName フォーム項目名。
 * @return string 項目別エラー要素ID。
 */
function field_error_id(string $fieldName): string
{
    // ---------------------------------------------
    // ID生成
    // ---------------------------------------------
    $safeFieldName = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $fieldName), '-');

    if ($safeFieldName === '') {
        $safeFieldName = md5($fieldName);
    }

    return 'f2m-error-' . $safeFieldName;
}

/**
 * aria-describedbyへ項目別エラーIDを追加。
 *
 * @param \DOMElement $controlElement フォーム入力要素。
 * @param string $errorId 項目別エラー要素ID。
 * @return void
 */
function append_aria_describedby(\DOMElement $controlElement, string $errorId): void
{
    $describedByValues = array_filter(
        preg_split('/\s+/', trim($controlElement->getAttribute('aria-describedby'))) ?: [],
        static fn (string $describedByValue): bool => $describedByValue !== ''
    );

    // ---------------------------------------------
    // 参照ID追加
    // ---------------------------------------------
    if (!in_array($errorId, $describedByValues, true)) {
        $describedByValues[] = $errorId;
    }

    $controlElement->setAttribute('aria-describedby', implode(' ', $describedByValues));
}

/**
 * 指定ノードの直後へノードを挿入。
 *
 * @param \DOMElement $targetElement 挿入基準要素。
 * @param \DOMElement $insertElement 挿入要素。
 * @return void
 */
function insert_after(\DOMElement $targetElement, \DOMElement $insertElement): void
{
    $parentElement = $targetElement->parentNode;

    if ($parentElement === null) {
        return;
    }

    // ---------------------------------------------
    // 直後挿入
    // ---------------------------------------------
    if ($targetElement->nextSibling !== null) {
        $parentElement->insertBefore($insertElement, $targetElement->nextSibling);
        return;
    }

    $parentElement->appendChild($insertElement);
}

/**
 * DOMDocument読込時のUTF-8指定用XML宣言を削除。
 *
 * @param \DOMDocument $document HTML DOM。
 * @return void
 */
function remove_xml_encoding_node(\DOMDocument $document): void
{
    // ---------------------------------------------
    // XML宣言削除
    // ---------------------------------------------
    foreach ($document->childNodes as $childNode) {
        if ($childNode->nodeType === XML_PI_NODE) {
            $document->removeChild($childNode);
            return;
        }
    }
}

/**
 * input要素へフォーム値を反映。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @return void
 */
function apply_input_values(\DOMDocument $document, array $formFields): void
{
    // ---------------------------------------------
    // input値反映
    // ---------------------------------------------
    foreach ($document->getElementsByTagName('input') as $inputElement) {
        $fieldName = $inputElement->getAttribute('name');

        if (!form_field_exists($fieldName, $formFields)) {
            continue;
        }

        $fieldValue = form_field_value($fieldName, $formFields);
        $inputType = strtolower($inputElement->getAttribute('type') ?: 'text');

        if ($inputType === 'file') {
            continue;
        }

        if ($inputType === 'checkbox' || $inputType === 'radio') {
            update_checked_attribute($inputElement, $fieldValue);
            continue;
        }

        $inputElement->setAttribute('value', (string)$fieldValue);
    }
}

/**
 * textarea要素へフォーム値を反映。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @return void
 */
function apply_textarea_values(\DOMDocument $document, array $formFields): void
{
    // ---------------------------------------------
    // textarea値反映
    // ---------------------------------------------
    foreach ($document->getElementsByTagName('textarea') as $textareaElement) {
        $fieldName = $textareaElement->getAttribute('name');

        if (!form_field_exists($fieldName, $formFields)) {
            continue;
        }

        while ($textareaElement->firstChild !== null) {
            $textareaElement->removeChild($textareaElement->firstChild);
        }

        $textareaElement->appendChild($document->createTextNode((string)form_field_value($fieldName, $formFields)));
    }
}

/**
 * select要素へフォーム値を反映。
 *
 * @param \DOMDocument $document HTML DOM。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @return void
 */
function apply_select_values(\DOMDocument $document, array $formFields): void
{
    // ---------------------------------------------
    // select値反映
    // ---------------------------------------------
    foreach ($document->getElementsByTagName('select') as $selectElement) {
        $fieldName = $selectElement->getAttribute('name');

        if (!form_field_exists($fieldName, $formFields)) {
            continue;
        }

        $fieldValues = comparable_values(form_field_value($fieldName, $formFields));

        foreach ($selectElement->getElementsByTagName('option') as $optionElement) {
            $optionValue = $optionElement->hasAttribute('value')
                ? $optionElement->getAttribute('value')
                : $optionElement->textContent;

            if (in_array((string)$optionValue, $fieldValues, true)) {
                $optionElement->setAttribute('selected', 'selected');
                continue;
            }

            $optionElement->removeAttribute('selected');
        }
    }
}

/**
 * checkboxまたはradioのchecked属性をフォーム値に合わせて更新。
 *
 * @param \DOMElement $inputElement input要素。
 * @param mixed $fieldValue フォーム復元値。
 * @return void
 */
function update_checked_attribute(\DOMElement $inputElement, mixed $fieldValue): void
{
    // ---------------------------------------------
    // checked属性更新
    // ---------------------------------------------
    $inputValue = $inputElement->getAttribute('value');

    if (in_array((string)$inputValue, comparable_values($fieldValue), true)) {
        $inputElement->setAttribute('checked', 'checked');
        return;
    }

    $inputElement->removeAttribute('checked');
}

/**
 * name属性に対応するフォーム値の存在を判定。
 *
 * @param string $fieldName フォーム項目名。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @return bool フォーム値が存在する場合はtrue。
 */
function form_field_exists(string $fieldName, array $formFields): bool
{
    // ---------------------------------------------
    // フォーム値存在判定
    // ---------------------------------------------
    if (array_key_exists($fieldName, $formFields)) {
        return true;
    }

    return str_ends_with($fieldName, '[]')
        && array_key_exists(substr($fieldName, 0, -2), $formFields);
}

/**
 * name属性に対応するフォーム値を取得。
 *
 * @param string $fieldName フォーム項目名。
 * @param array<string, mixed> $formFields フォーム復元値。
 * @return mixed フォーム復元値。
 */
function form_field_value(string $fieldName, array $formFields): mixed
{
    // ---------------------------------------------
    // フォーム値取得
    // ---------------------------------------------
    if (array_key_exists($fieldName, $formFields)) {
        return $formFields[$fieldName];
    }

    if (str_ends_with($fieldName, '[]')) {
        return $formFields[substr($fieldName, 0, -2)] ?? '';
    }

    return '';
}

/**
 * checkbox、radio、select照合用の文字列配列へ変換。
 *
 * @param mixed $fieldValue フォーム復元値。
 * @return array<int, string> 照合用文字列配列。
 */
function comparable_values(mixed $fieldValue): array
{
    // ---------------------------------------------
    // 照合値配列化
    // ---------------------------------------------
    if (is_array($fieldValue)) {
        return array_map('strval', $fieldValue);
    }

    return array_map('trim', explode(',', (string)$fieldValue));
}

/**
 * 確認画面から送信時に利用するCSRFトークンとF2M_IDを構築。
 *
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param bool $createCsrfToken CSRFトークンを生成する場合はtrue。
 * @return array{csrf_token: string, F2M_ID: mixed} 送信用隠し項目値。
 * @throws \Random\RandomException CSRFトークン生成に失敗した場合。
 */
function send_values(array $request, bool $createCsrfToken = false): array
{
    // ---------------------------------------------
    // 送信用隠し項目構築
    // ---------------------------------------------
    $sessionValues =& $request['session_values'];

    return [
        'csrf_token' => $createCsrfToken ? csrf_token($sessionValues) : '',
        'F2M_ID' => $request['f2m_id'] ?? '',
    ];
}

/**
 * セッションに保持するCSRFトークンを取得し、未生成の場合は新規生成。
 *
 * @param array<string, mixed> $sessionValues セッション値。CSRFトークンの保持先。
 * @return string CSRFトークン。
 * @throws \Random\RandomException CSRFトークン生成に失敗した場合。
 */
function csrf_token(array &$sessionValues): string
{
    // ---------------------------------------------
    // CSRFトークン生成
    // ---------------------------------------------
    if (empty($sessionValues['csrf_token']) || !is_string($sessionValues['csrf_token'])) {
        $sessionValues['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $sessionValues['csrf_token'];
}
