<?php
    // relies on panggil.php for session

    include '../config/panggil.php';
    enforceCanInputScore();

    if (!checkRateLimit('view_load', 60, 60)) {
        header('HTTP/1.1 429 Too Many Requests');
        die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
    }

    $_GET = cleanInput($_GET);

    $kegiatan_id = intval($_GET['kegiatan_id'] ?? 0);
    $category_id = intval($_GET['category_id'] ?? 0);

    $stmtSb = $conn->prepare("SELECT * FROM score_boards WHERE kegiatan_id = ? AND category_id = ? ORDER BY created ASC");
    $stmtSb->bind_param("ii", $kegiatan_id, $category_id);
    $stmtSb->execute();
    $mysql_table_score_board = $stmtSb->get_result();
    
    $data = [];
    $loop = 0;
    while($a = $mysql_table_score_board->fetch_assoc()) {
        $data[] = ['score' => $a, 'peserta' => []];
        
        $stmtPeserta = $conn->prepare("SELECT p.*, pr.* FROM peserta p INNER JOIN peserta_rounds pr ON pr.peserta_id = p.id WHERE pr.score_board_id = ?");
        $stmtPeserta->bind_param("i", $a['id']);
        $stmtPeserta->execute();
        $peserta_result = $stmtPeserta->get_result();
        
        $loop_lawan = 0;
        $loop_isi = 0;
        while($b = $peserta_result->fetch_assoc()) {
            if($loop_isi == 0) {
                $data[$loop]['peserta'][] = [[],[]];
            }
            $data[$loop]['peserta'][$loop_lawan][$loop_isi] = $b;
            $loop_isi += 1;

            if($loop_isi == 2) {
                $loop_lawan += 1;
                $loop_isi = 0;
            }
        }
        $stmtPeserta->close();
        $loop = $loop + 1;
    }
    $stmtSb->close();
?>






<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tournament Bracket</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f9f9f9;
      display: flex;
      justify-content: center;
      padding: 30px;
    }
    .bracket {
      display: flex;
      gap: 40px;
    }
    .round {
      display: flex;
      flex-direction: column;
      gap: 30px;
    }
    .match {
      display: flex;
      flex-direction: column;
      background: #e6e6e6;
      border-radius: 6px;
      overflow: hidden;
      width: 160px;
    }
    .team {
      padding: 8px 12px;
      border-bottom: 1px solid #ccc;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .team:last-child {
      border-bottom: none;
    }
    .winner {
      background: #b5e7b5;
      font-weight: bold;
    }
    .final {
      font-size: 18px;
      font-weight: bold;
      background: #d0ffd0;
    }
    .trophy {
      text-align: center;
      font-size: 32px;
      margin-top: 20px;
    }
  </style>
</head>
<body>
  <div class="bracket">
    <?php $no = 0; ?>
    <?php foreach($data as $a) { ?>
        
        <div class="round">
            <h1><?= $a['score']['nama'] ?></h1>
            <?php for($i = 0; $i < $no; $i++) { ?>
            <div class="match">

            </div>
            <div class="match">

            </div>
            <?php } ?>

            <?php foreach($a['peserta'] as $peserta) { ?>
                <div class="match">
                    <div class="team  <?= $peserta[0] ? ($peserta[0]['status'] == 1 ? "winner" : "") : '' ?>  "><?= $peserta[0]['nama_peserta'] ?? "-" ?></div>
                    <div class="team  <?= $peserta[1] ? ($peserta[1]['status'] == 1 ? "winner" : "") : '' ?>  "><?= $peserta[1]['nama_peserta'] ?? "-" ?></div>
                </div>
            <?php } ?>
            <?php $no += 1; ?>
        </div>
    <?php } ?>
  </div>
<script>
    const data = <?= json_encode($data) ?>
    // console.log(data);
    console.log(data);
</script>
</body>
</html>
