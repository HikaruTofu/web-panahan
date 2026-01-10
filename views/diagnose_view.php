<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/panggil.php';

echo "<h1>Session Diagnosis (Views)</h1>";
echo "<pre>";
echo "Session Status: " . session_status() . " (1=None, 2=Active)\n";
echo "Session Name: " . session_name() . "\n";
echo "Session ID: " . session_id() . "\n";
echo "\n--- SESSION DATA ---\n";
print_r($_SESSION);
echo "</pre>";

echo '<p><a href="../diagnose_session.php">Check Root Directory Session</a></p>';
?>
