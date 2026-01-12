<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/panggil.php';

echo "<h1>Session Diagnosis</h1>";
echo "<pre>";
echo "Session Status: " . session_status() . " (1=None, 2=Active)\n";
echo "Session Name: " . session_name() . "\n";
echo "Session ID: " . session_id() . "\n";
echo "Cookie Domain: " . ini_get('session.cookie_domain') . "\n";
echo "Cookie Path: " . ini_get('session.cookie_path') . "\n";
echo "Secure Cookie: " . ini_get('session.cookie_secure') . "\n";
echo "HttpOnly: " . ini_get('session.cookie_httponly') . "\n";
echo "\n--- SESSION DATA ---\n";
print_r($_SESSION);
echo "</pre>";

echo '<p><a href="views/diagnose_view.php">Check Views Directory Session</a></p>';
echo '<p><a href="actions/logout.php">Logout (Clear Session)</a></p>';
?>
