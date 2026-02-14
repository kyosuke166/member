<?php
session_start();

// セッション変数をすべて解除
$_SESSION = array();

// セッションクッキーも削除
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// 最終的にセッションを破棄
session_destroy();

// ログアウト完了画面へリダイレクト
header('Location: /logout-success');
exit;