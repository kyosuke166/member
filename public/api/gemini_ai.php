<?php
// api/gemini_ai.php

function analyze_mail_with_ai($body, $api_key) {
    //$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . trim($api_key);
    //$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . trim($api_key);
    //$api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . trim($api_key);
    //$api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent?key=' . trim($api_key);
    $api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . trim($api_key);

    $prompt = "以下のメールから案件情報を抽出し、JSON形式で回答してください。
JSON以外の解説は一切禁止します。

【重要ルール：skills項目】
- 必須スキル、歓迎スキルを抽出し、「PHP, AWS, Java」のように必ず【半角カンマ区切り】の1行の文字列で出力してください。
- 箇条書き（・や-）は絶対に使わないでください。

【抽出項目】
- title: 案件名（短く）
- summary_text: 内容を300文字程度で要約
- term: 期間（例：「○月～」や「即日～」など）
- location: 勤務地
- remote: リモート状況（例：フルリモート、週3出社等。数字の1は禁止）
- reward: 単価（「スキル見合い」や「80万〜」など）
- skills: 上記のカンマ区切りルールを厳守

メール本文：
" . strip_tags($body);

    // generationConfig を削除し、最もシンプルなリクエストにする
    $post_data = [
        'contents' => [['parts' => [['text' => $prompt]]]]
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $res_arr = json_decode($response, true);
        $text = $res_arr['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // AIが ```json { ... } ``` のように返してくる場合があるため、
        // 余計な記号を削って純粋なJSON部分だけを抽出する
        $json_text = trim($text);
        $json_text = preg_replace('/^```json\s*/', '', $json_text);
        $json_text = preg_replace('/```$/', '', $json_text);
        
        $data = json_decode($json_text, true);
        if ($data) {
            return ['success' => true, 'data' => $data];
        }
    }

    // 400エラー等の場合に、何がダメだったのか詳細を表示するようにします
    return [
        'success' => false, 
        'code' => $http_code, 
        'error' => 'API回答: ' . $response 
    ];
}