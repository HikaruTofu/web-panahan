<?php 
    // Aktifkan error reporting untuk debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Mulai session jika belum
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    include '../config/panggil.php';



    $mysql_table_score_board = mysqli_query($conn, "SELECT * FROM score_boards WHERE kegiatan_id=".$_GET['kegiatan_id']." AND category_id=".$_GET['category_id']." ORDER BY created ASC");
    $data = [];
    $loop = 0;
    while($a = mysqli_fetch_assoc($mysql_table_score_board)) {
        $data[] = ['score' => $a, 'peserta' => []];
        $peserta_query = mysqli_query($conn, "SELECT * FROM peserta INNER JOIN peserta_rounds ON peserta_rounds.peserta_id = peserta.id WHERE peserta_rounds.score_board_id = ".$a['id']."  ");
        $loop_lawan = 0;
        $loop_isi = 0;
        while($b = mysqli_fetch_assoc($peserta_query)) {
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

        $loop = $loop + 1;
    }

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
