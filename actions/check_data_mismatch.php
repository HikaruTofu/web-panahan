<?php
$conn = new mysqli("db", "root", "root", "panahan_turnament_new");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$fileContent = file_get_contents("databaru.txt");
$categories = explode("\n", $fileContent);

$currentCategory = "";
$fileData = [];

foreach ($categories as $line) {
    $line = trim($line);
    if (preg_match('/^\d+\.\s+Kategori\s+(.+)$/', $line, $matches)) {
        $currentCategory = $matches[1];
    } elseif (preg_match('/^(\d+),([^,]+),([^,]+),([^,]+),(\d+)$/', $line, $matches)) {
        if ($currentCategory) {
            $fileData[$currentCategory][] = [
                'ranking' => $matches[1],
                'nama' => trim($matches[2]),
                'club' => trim($matches[3]),
                'kota' => trim($matches[4]),
                'skor' => $matches[5]
            ];
        }
    }
}

echo "Category Comparison:\n";
foreach ($fileData as $cat => $players) {
    // Try to find matching category in DB
    $dbCat = "";
    $checkCat = $conn->query("SELECT category FROM rankings_source WHERE category LIKE '%$cat%' LIMIT 1");
    if ($checkCat && $row = $checkCat->fetch_assoc()) {
        $dbCat = $row['category'];
    }

    if (!$dbCat) {
        echo "Missing Category in DB: $cat\n";
        continue;
    }

    $dbCount = $conn->query("SELECT COUNT(*) as total FROM rankings_source WHERE category = '$dbCat'")->fetch_assoc()['total'];
    $fileCount = count($players);

    echo "Category: $cat\n";
    echo "  File count: $fileCount, DB count: $dbCount\n";

    if ($fileCount != $dbCount) {
        echo "  !!! COUNT MISMATCH !!!\n";
    }

    // Check top 3
    for ($i = 0; $i < min(3, $fileCount); $i++) {
        $fName = $players[$i]['nama'];
        $fRank = $players[$i]['ranking'];
        
        $dbPlayer = $conn->query("SELECT nama_peserta FROM rankings_source WHERE category = '$dbCat' AND ranking = $fRank")->fetch_assoc();
        $dbName = $dbPlayer ? $dbPlayer['nama_peserta'] : "NOT FOUND";

        if (strtolower($fName) != strtolower($dbName)) {
            echo "  Rank $fRank Mismatch: File='$fName' vs DB='$dbName'\n";
        }
    }
}
?>
