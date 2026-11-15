<?php

session_start();

require_once __DIR__ . '/classes/Database.php';

function echouerRenvoi(string $message): void
{
    $_SESSION['erreur_renvoi_document'] = $message;
    header('Location: renvoyer-document.php');
    exit;
}

if (empty($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: notifications.php');
    exit;
}

// Vérification CSRF.
$jetonSession = $_SESSION['csrf_renvoi_document'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)
) {
    echouerRenvoi('Le formulaire a expiré. Recharge la page puis réessaie.');
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];
$typeDocument = $_POST['type_document'] ?? '';

$typesDocumentsAutorises = [
    'carte_identite',
    'passeport',
    'permis_conduire',
    'autre'
];

if (
    !is_string($typeDocument) ||
    !in_array($typeDocument, $typesDocumentsAutorises, true)
) {
    echouerRenvoi('Le type de document sélectionné est invalide.');
}

// Vérifier le fichier envoyé.
if (
    !isset($_FILES['piece_identite']) ||
    !is_array($_FILES['piece_identite'])
) {
    echouerRenvoi('La nouvelle pièce d’identité est obligatoire.');
}

$fichier = $_FILES['piece_identite'];

if (
    !isset($fichier['error'], $fichier['tmp_name'], $fichier['size']) ||
    $fichier['error'] !== UPLOAD_ERR_OK
) {
    echouerRenvoi('Le transfert de la pièce d’identité a échoué. Réessaie.');
}

if (!is_uploaded_file($fichier['tmp_name'])) {
    echouerRenvoi('Le fichier transmis est invalide.');
}

$tailleMaximale = 5 * 1024 * 1024;

if ($fichier['size'] <= 0 || $fichier['size'] > $tailleMaximale) {
    echouerRenvoi('La pièce d’identité doit peser au maximum 5 Mo.');
}

// Vérifier le véritable format du fichier.
$fin = new finfo(FILEINFO_MIME_TYPE);
$typeMime = $fin->file($fichier['tmp_name']);

$typesAutorises = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

if (!is_string($typeMime) || !isset($typesAutorises[$typeMime])) {
    echouerRenvoi('Format invalide. Envoie un document PDF, JPG ou PNG.');
}

// Vérifier le dossier privé.
$repertoirePrive = 'C:\\wamp64\\migon_prive';

if (!is_dir($repertoirePrive) || !is_writable($repertoirePrive)) {
    echouerRenvoi('Le stockage privé est indisponible. Contacte l’administrateur.');
}

// Créer un nom de fichier aléatoire.
$nomFichier = bin2hex(random_bytes(24))
    . '.'
    . $typesAutorises[$typeMime];

$cheminComplet = $repertoirePrive
    . DIRECTORY_SEPARATOR
    . $nomFichier;

$fichierEnregistre = false;

try {
    $pdo = (new Database())->getConnection();

    // Vérifier que le dernier document de l'utilisateur est refusé.
    $requete = $pdo->prepare("
        SELECT id_document, statut_verification
        FROM document_identite
        WHERE id_utilisateur = :id_utilisateur
        ORDER BY date_soumission DESC, id_document DESC
        LIMIT 1
    ");

    $requete->execute([
        'id_utilisateur' => $idUtilisateur
    ]);

    $dernierDocument = $requete->fetch(PDO::FETCH_ASSOC);

    if (
        !$dernierDocument ||
        $dernierDocument['statut_verification'] !== 'refuse'
    ) {
        echouerRenvoi(
            'Aucun document refusé ne nécessite actuellement un nouveau dépôt.'
        );
    }

    // Enregistrer le fichier dans le dossier privé.
    if (!move_uploaded_file($fichier['tmp_name'], $cheminComplet)) {
        echouerRenvoi('Impossible d’enregistrer le nouveau document.');
    }

    $fichierEnregistre = true;

    // Insérer un nouveau document sans supprimer l'ancien.
    $requete = $pdo->prepare("
        INSERT INTO document_identite (
            id_utilisateur,
            type_document,
            chemin_fichier_prive,
            statut_verification,
            motif_refus,
            date_soumission
        ) VALUES (
            :id_utilisateur,
            :type_document,
            :chemin_fichier_prive,
            'en_attente',
            NULL,
            CURRENT_TIMESTAMP
        )
    ");

    $requete->execute([
        'id_utilisateur' => $idUtilisateur,
        'type_document' => $typeDocument,
        'chemin_fichier_prive' => $cheminComplet
    ]);

    unset($_SESSION['csrf_renvoi_document']);

    $_SESSION['succes_renvoi_document'] =
        'Votre nouveau document a été envoyé. Il est en attente de vérification.';

   header('Location: /migon/notifications.php', true, 303);
exit;

} catch (Throwable $e) {

    if ($fichierEnregistre && is_file($cheminComplet)) {
        unlink($cheminComplet);
    }

    error_log('Erreur renvoi document MIGON : ' . $e->getMessage());

    echouerRenvoi(
        'Une erreur est survenue lors de l’envoi. Réessaie plus tard.'
    );
}
