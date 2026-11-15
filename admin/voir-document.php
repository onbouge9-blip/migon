<?php

session_start();

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Stockage.php';

// 1. Vérifier la connexion
if (empty($_SESSION['utilisateur_id'])) {
    http_response_code(403);
    exit('Accès interdit.');
}

// 2. Vérifier le rôle administrateur
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    http_response_code(403);
    exit('Accès réservé à l’administration.');
}

// 3. Vérifier l'identifiant du document
$idDocument = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idDocument) {
    http_response_code(400);
    exit('Identifiant invalide.');
}

try {
    $pdo = (new Database())->getConnection();

    $requete = $pdo->prepare("
        SELECT chemin_fichier_prive
        FROM document_identite
        WHERE id_document = :id_document
    ");

    $requete->execute([
        'id_document' => $idDocument
    ]);

    $document = $requete->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        http_response_code(404);
        exit('Document introuvable.');
    }

    // 4. Vérifier le dossier privé
    $dossierPrive = realpath(Stockage::dossierPrive());

    if ($dossierPrive === false) {
        throw new RuntimeException('Dossier privé introuvable.');
    }

    // 5. Récupérer le chemin réel du fichier
    // Accepte l'ancien format (chemin complet) comme le nouveau (nom de fichier).
    $cheminFichier = realpath(Stockage::cheminPrive((string) $document['chemin_fichier_prive']));

    if ($cheminFichier === false || !is_file($cheminFichier)) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }

    // 6. Vérifier que le fichier est dans le dossier privé
    $prefixeAutorise = rtrim($dossierPrive, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;

    if (
        strpos(
            strtolower($cheminFichier),
            strtolower($prefixeAutorise)
        ) !== 0
    ) {
        http_response_code(403);
        exit('Accès au fichier interdit.');
    }

    // 7. Détecter le véritable format
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($cheminFichier);

    $typesAutorises = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf'
    ];

    if (!isset($typesAutorises[$mime])) {
        http_response_code(415);
        exit('Type de document non autorisé.');
    }

    if (!is_readable($cheminFichier)) {
        http_response_code(403);
        exit('Fichier non lisible.');
    }

    // 8. Nettoyer les tampons de sortie
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // 9. Envoyer le document au navigateur
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($cheminFichier));
    header('Content-Disposition: inline; filename="document.' . $typesAutorises[$mime] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($cheminFichier);
    exit;

} catch (Throwable $e) {

    error_log('Erreur affichage document MIGON : ' . $e->getMessage());

    http_response_code(500);
    exit('Impossible d’afficher ce document.');
}
