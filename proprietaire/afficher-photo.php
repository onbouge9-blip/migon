<?php
session_start();

$idUtilisateurConnecte = isset($_SESSION['utilisateur_id'])
    ? (int) $_SESSION['utilisateur_id']
    : 0;
$rolesConnecte = is_array($_SESSION['utilisateur_roles'] ?? null)
    ? $_SESSION['utilisateur_roles']
    : [];

// Libère le verrou de session : une page peut charger plusieurs photos.
session_write_close();

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Stockage.php';

$idLogement = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$idPhoto = filter_input(INPUT_GET, 'photo', FILTER_VALIDATE_INT);

if (!$idLogement || !$idPhoto || $idLogement < 1 || $idPhoto < 1) {
    http_response_code(404);
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    $sql = "
        SELECT p.chemin_photo, l.statut, l.id_proprietaire, l.date_suppression
        FROM photo_logement AS p
        INNER JOIN logement AS l
            ON l.id_logement = p.id_logement
        WHERE p.id_photo = :photo
          AND p.id_logement = :logement
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'photo' => $idPhoto,
        'logement' => $idLogement
    ]);

    $photo = $stmt->fetch();

    if (!$photo) {
        http_response_code(404);
        exit;
    }

    /*
     * Règles d'accès :
     * - tout le monde voit les photos d'un logement publié et non supprimé ;
     * - le propriétaire voit aussi celles de ses brouillons / logements masqués
     *   (nécessaire pour sa liste « Mes logements ») ;
     * - un administrateur peut tout voir.
     */
    $estPublic = $photo['statut'] === 'publie' && $photo['date_suppression'] === null;
    $estProprietaire = $idUtilisateurConnecte > 0
        && $idUtilisateurConnecte === (int) $photo['id_proprietaire']
        && $photo['date_suppression'] === null;
    $estAdmin = $idUtilisateurConnecte > 0
        && in_array('administrateur', $rolesConnecte, true);

    if (!$estPublic && !$estProprietaire && !$estAdmin) {
        http_response_code(404);
        exit;
    }

    // Accepte l'ancien format (chemin complet) comme le nouveau (nom de fichier).
    $cheminPhoto = Stockage::cheminPhoto((string) $photo['chemin_photo']);

    if (!$cheminPhoto || !is_file($cheminPhoto) || !is_readable($cheminPhoto)) {
        http_response_code(404);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($cheminPhoto);

    $typesAutorises = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!in_array($mime, $typesAutorises, true)) {
        http_response_code(415);
        exit;
    }

    // Aucun affichage HTML/var_dump ne doit précéder les en-têtes et les octets de l'image.
    if (ob_get_level()) {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($cheminPhoto));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');

    readfile($cheminPhoto);
    exit;

} catch (Throwable $e) {
    error_log('Erreur affichage photo MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit;
}
