<?php
/**
 * Data Recovery System
 * Backups deleted records to filesystem for 24 hours.
 */

// RECOVERY_BACKUP_FILE is defined in config/panggil.php

/**
 * Backup a record to JSON before permanent deletion
 */
function backup_deleted_record($conn, $tableName, $id) {
    // Capture everything from the record
    $stmt = $conn->prepare("SELECT * FROM `$tableName` WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    if ($data) {
        $backup = [
            'id' => uniqid(), // Unique ID for the backup entry
            'table' => $tableName,
            'original_id' => $id,
            'deleted_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'data' => $data,
            'cascade' => []
        ];

        // Specific handling for cascading deletes (e.g., Scoreboard values)
        if ($tableName === 'score_boards') {
            $stmtScores = $conn->prepare("SELECT * FROM score WHERE score_board_id = ?");
            $stmtScores->bind_param("i", $id);
            $stmtScores->execute();
            $resultScores = $stmtScores->get_result();
            while ($sRow = $resultScores->fetch_assoc()) {
                $backup['cascade']['score'][] = $sRow;
            }
            $stmtScores->close();
        }

        // Deeper backup for participants (all their scores and referenced scoreboards)
        if ($tableName === 'peserta') {
            // 1. Fetch unique score_boards referenced by these participants (if any)
            // Wait, we need to find them via scores. Let's do a JOIN or two steps.
            $stmtSB = $conn->prepare("SELECT DISTINCT sb.* FROM score_boards sb 
                                     INNER JOIN score s ON s.score_board_id = sb.id 
                                     WHERE s.peserta_id = ?");
            $stmtSB->bind_param("i", $id);
            $stmtSB->execute();
            $resultSB = $stmtSB->get_result();
            while ($sbRow = $resultSB->fetch_assoc()) {
                $backup['cascade']['score_boards'][] = $sbRow;
            }
            $stmtSB->close();

            // 2. Fetch all scores for this participant
            $stmtS = $conn->prepare("SELECT * FROM score WHERE peserta_id = ?");
            $stmtS->bind_param("i", $id);
            $stmtS->execute();
            $resultS = $stmtS->get_result();
            while ($sRow = $resultS->fetch_assoc()) {
                $backup['cascade']['score'][] = $sRow;
            }
            $stmtS->close();
        }

        // Deeper backup for kegiatan (all participants and there scores)
        if ($tableName === 'kegiatan') {
            // 1. Fetch all participants
            $stmtP = $conn->prepare("SELECT * FROM peserta WHERE kegiatan_id = ?");
            $stmtP->bind_param("i", $id);
            $stmtP->execute();
            $resultP = $stmtP->get_result();
            while ($pRow = $resultP->fetch_assoc()) {
                $backup['cascade']['peserta'][] = $pRow;
                
                // 2. For each participant, fetch scores & scoreboards
                $pId = $pRow['id'];
                
                // Scoreboards
                $stmtSB = $conn->prepare("SELECT DISTINCT sb.* FROM score_boards sb 
                                         INNER JOIN score s ON s.score_board_id = sb.id 
                                         WHERE s.peserta_id = ?");
                $stmtSB->bind_param("i", $pId);
                $stmtSB->execute();
                $rsSB = $stmtSB->get_result();
                while ($sbRow = $rsSB->fetch_assoc()) {
                    $backup['cascade']['score_boards'][] = $sbRow; // Note: may contain duplicates, REPLACE INTO will handle it
                }
                $stmtSB->close();

                // Scores
                $stmtS = $conn->prepare("SELECT * FROM score WHERE peserta_id = ?");
                $stmtS->bind_param("i", $pId);
                $stmtS->execute();
                $rsS = $stmtS->get_result();
                while ($sRow = $rsS->fetch_assoc()) {
                    $backup['cascade']['score'][] = $sRow;
                }
                $stmtS->close();
            }
            $stmtP->close();
            
            // 3. Fetch categories linkage
            $stmtKK = $conn->prepare("SELECT * FROM kegiatan_kategori WHERE kegiatan_id = ?");
            $stmtKK->bind_param("i", $id);
            $stmtKK->execute();
            $resultKK = $stmtKK->get_result();
            while ($kkRow = $resultKK->fetch_assoc()) {
                $backup['cascade']['kegiatan_kategori'][] = $kkRow;
            }
            $stmtKK->close();
        }

        // Load existing backups
        $allBackups = [];
        if (file_exists(RECOVERY_BACKUP_FILE)) {
            $existing = json_decode(file_get_contents(RECOVERY_BACKUP_FILE), true);
            if (is_array($existing)) {
                $allBackups = $existing;
            }
        }

        // Append and save
        $allBackups[] = $backup;
        file_put_contents(RECOVERY_BACKUP_FILE, json_encode($allBackups, JSON_PRETTY_PRINT));
        return true;
    }
    return false;
}

/**
 * Restore a record from backup
 */
function restore_record($conn, $backup_id) {
    if (!file_exists(RECOVERY_BACKUP_FILE)) return false;
    
    $allBackups = json_decode(file_get_contents(RECOVERY_BACKUP_FILE), true);
    if (!is_array($allBackups)) return false;

    $backup = null;
    $remaining = [];
    foreach ($allBackups as $b) {
        if ($b['id'] === $backup_id) {
            $backup = $b;
        } else {
            $remaining[] = $b;
        }
    }

    if (!$backup) return false;

    $conn->begin_transaction();
    try {
        $table = $backup['table'];
        $data = $backup['data'];

        // 1. Restore Main Record (Use REPLACE INTO to update if exists)
        $columns = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $colString = '`' . implode('`,`', $columns) . '`';
        
        $sql = "REPLACE INTO `$table` ($colString) VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        
        $types = "";
        $values = [];
        foreach ($data as $val) {
            if (is_int($val)) $types .= "i";
            elseif (is_double($val)) $types .= "d";
            else $types .= "s";
            $values[] = $val;
        }
        
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        // 2. Restore Cascaded Data (In the order they were backed up)
        if (!empty($backup['cascade'])) {
            // Sort keys to ensure proper relational order (Parents first)
            $cascadeOrders = ['kegiatan_kategori', 'peserta', 'score_boards', 'score'];
            foreach ($cascadeOrders as $cTable) {
                if (isset($backup['cascade'][$cTable])) {
                    $rows = $backup['cascade'][$cTable];
                    foreach ($rows as $rowData) {
                        $cCols = array_keys($rowData);
                        $cPlaceholders = implode(',', array_fill(0, count($cCols), '?'));
                        $cColStr = '`' . implode('`,`', $cCols) . '`';
                        
                        $cSql = "REPLACE INTO `$cTable` ($cColStr) VALUES ($cPlaceholders)";
                        $cStmt = $conn->prepare($cSql);
                        
                        $cTypes = "";
                        $cValues = [];
                        foreach ($rowData as $cVal) {
                            if (is_int($cVal)) $cTypes .= "i";
                            elseif (is_double($cVal)) $cTypes .= "d";
                            else $cTypes .= "s";
                            $cValues[] = $cVal;
                        }
                        
                        $cStmt->bind_param($cTypes, ...$cValues);
                        $cStmt->execute();
                        $cStmt->close();
                    }
                }
            }
        }

        $conn->commit();
        
        // Remove from backup file after successful restore
        file_put_contents(RECOVERY_BACKUP_FILE, json_encode(array_values($remaining), JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['recovery_error'] = $e->getMessage();
        error_log("Restore failed: " . $e->getMessage());
        return false;
    }
}


/**
 * Clean up backups older than 24 hours
 */
function cleanup_old_backups() {
    if (!file_exists(RECOVERY_BACKUP_FILE)) return;

    $allBackups = json_decode(file_get_contents(RECOVERY_BACKUP_FILE), true);
    if (!is_array($allBackups)) return;

    $now = time();
    $retention = 24 * 3600; // 24 hours

    $filtered = array_filter($allBackups, function($b) use ($now, $retention) {
        $time = isset($b['timestamp']) ? $b['timestamp'] : strtotime($b['deleted_at']);
        return ($now - $time) <= $retention;
    });

    if (count($filtered) !== count($allBackups)) {
        file_put_contents(RECOVERY_BACKUP_FILE, json_encode(array_values($filtered), JSON_PRETTY_PRINT));
    }
}
