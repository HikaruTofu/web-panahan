<?php 
include '../config/panggil.php'; 
enforceAdmin();

$kegiatan_id = intval($_GET['kegiatan_id'] ?? 0);
$category_id = intval($_GET['category_id'] ?? 0);
$scoreboard_id = intval($_GET['scoreboard'] ?? 0);

$stmtSb = $conn->prepare("SELECT * FROM `score_boards` WHERE id = ?");
$stmtSb->bind_param("i", $scoreboard_id);
$stmtSb->execute();
$scoreboard_fetch = $stmtSb->get_result()->fetch_assoc();
$stmtSb->close();

$peserta_query = "
    SELECT 
        p.id AS peserta_id,
        p.nama_peserta,
        p.jenis_kelamin,
        p.kegiatan_id,
        p.category_id,
        COALESCE(SUM(
            CASE 
                WHEN s.score = 'm' THEN 0
                WHEN s.score = 'x' THEN 10
                ELSE CAST(s.score AS UNSIGNED)
            END
        ), 0) AS total_score,
        COALESCE(SUM(CASE WHEN s.score = 'x' THEN 1 ELSE 0 END), 0) AS jumlah_x
    FROM peserta p
    LEFT JOIN score s 
        ON p.id = s.peserta_id 
        AND s.kegiatan_id = ?
        AND s.category_id = ?
        AND s.score_board_id = ?
    WHERE p.kegiatan_id = ? AND p.category_id = ?
    GROUP BY p.id, p.nama_peserta, p.jenis_kelamin, p.kegiatan_id, p.category_id
    ORDER BY total_score DESC, jumlah_x DESC;
";

$stmtPeserta = $conn->prepare($peserta_query);
$stmtPeserta->bind_param("iiiii", $kegiatan_id, $category_id, $scoreboard_id, $kegiatan_id, $category_id);
$stmtPeserta->execute();
$peserta_result = $stmtPeserta->get_result();

$peserta = [];
while($b = $peserta_result->fetch_assoc()) {
    $peserta[] = $b;
}
$stmtPeserta->close();

$total_score_peserta = [];
?>


<?php $no_rank = 1; $total_score_peserta_index = 0; foreach( $peserta as $p) { $total_score_peserta[] = ['nama' => $p['nama_peserta']];?>
    <h1>Rank#<?= $no_rank++ ?> <?= $p['nama_peserta'] ?></h1>
    <table>
        <thead>
            <tr>
                <th>Rambahan</th>
                <?php for($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) { ?>
                    <th>Shot <?= $a ?></th>
                <?php } ?>
                <th>Total</th>
                <th>End</th>
            </tr>
        </thead>
        <tbody>
            <?php $end_value_total = [] ?>
            <?php for($s = 1; $s <= $scoreboard_fetch['jumlah_sesi']; $s++) { ?>
                    <?php 
                        $total_score = 0;
                    ?>
                <tr>
                    <td><?= $s ?></td>
                    <?php for($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) { ?>
                        <?php 
                            $stmtS = $conn->prepare("SELECT * FROM score WHERE category_id=? AND kegiatan_id =? AND score_board_id=? AND peserta_id=? AND session=? AND arrow=?");
                            $stmtS->bind_param("iiiiii", $category_id, $kegiatan_id, $scoreboard_id, $p['peserta_id'], $s, $a);
                            $stmtS->execute();
                            $score_fetch = $stmtS->get_result()->fetch_assoc();
                            $stmtS->close();

                            $score_value = 0;
                            if(isset($score_fetch)) {
                                if($score_fetch['score'] == "x") {
                                    $score_value = 10;
                                } else if($score_fetch['score'] == "m") {
                                    $score_value = 0;
                                } else {
                                    $score_value = $score_fetch['score'] ?? 0;
                                }
                            } else {
                                $score_value = 0;
                            }
                            $total_score += $score_value;
                        ?>
                        <td><?= $score_fetch['score'] ?? "m" ?></td>
                    <?php } ?>
                    <td><?= $total_score ?></td>
                    <td>
                        <?php 
                            $total_score_peserta[$total_score_peserta_index] += ['rambahan_'.$s => $total_score];
                            $end_value = 0;
                            if(empty($end_value_total)) {
                                $end_value = $total_score;
                                $end_value_total[] = $total_score;
                            } else {
                                $end_value_total_loop = 0;
                                foreach($end_value_total as $all) {
                                    $end_value_total_loop = $end_value_total_loop + $all;
                                } 
                                $end_value = $end_value_total_loop + $total_score;
                                $end_value_total[] = $total_score;
                            }
                            echo $end_value;
                            
                        ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <br>
    <?php 
        $total_score_peserta_index = $total_score_peserta_index + 1;
    ?>
<?php } ?>
<table>
    <thead>
        <tr>
            <th>No</th>
            <th>Nama</th>
            <?php for($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) { ?>
                <th>Rambahan <?= $a ?></th>
            <?php } ?>
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($total_score_peserta as $i_tsp => $tsp) { ?>
            <tr>
                <td><?= $i_tsp + 1 ?></td>
                <td><?= $tsp['nama'] ?></td>
                <?php $total_tsp = 0; for($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) { ?>
                    <td><?= $tsp['rambahan_'.$a] ?></td>
                    <?php $total_tsp = $total_tsp + $tsp['rambahan_'.$a]; ?>
                <?php } ?>
                <td><?= $total_tsp ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>

