
<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    http_response_code(403);
    exit;
}

$idLogement = (int) ($_GET['id'] ?? 0);

$pdo = (new Database())->getConnection();

$sql = "
SELECT chemin_photo
FROM photo_logement
WHERE id_logement=?
ORDER BY est_principale DESC, ordre_affichage ASC
LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$idLogement]);

$photo = $stmt->fetchColumn();

if (!$photo || !is_file($photo)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($photo);

header('Content-Type: '.$mime);
readfile($photo);
