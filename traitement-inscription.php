<?php

session_start();

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Utilisateur.php';
require_once __DIR__ . '/classes/Stockage.php';

$repertoirePrive = Stockage::dossierPrive();

$fichierEnregistre = null;

function echouerInscription(string $message): void
{
    $_SESSION['erreur_inscription'] = $message;
    header('Location: inscription.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inscription.php');
    exit;
}

// Vérifier le jeton CSRF.
$jetonSession = $_SESSION['csrf_token'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (!is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)) {
    echouerInscription(
        'Le formulaire a expiré. Recharge la page puis réessaie.'
    );
}

$nom = trim($_POST['nom'] ?? '');
$telephone = trim($_POST['telephone'] ?? '');
$email = trim($_POST['email'] ?? '');
$motDePasse = $_POST['mot_de_passe'] ?? '';
$confirmation = $_POST['confirmation'] ?? '';
$role = $_POST['role'] ?? '';
$typeDocument = $_POST['type_document'] ?? '';

if (!is_string($nom) ||
    !is_string($telephone) ||
    !is_string($email) ||
    !is_string($motDePasse) ||
    !is_string($confirmation) ||
    !is_string($role) ||
    !is_string($typeDocument)) {
    echouerInscription('Les données envoyées sont invalides.');
}

if ($motDePasse !== $confirmation) {
    echouerInscription('Les deux mots de passe ne correspondent pas.');
}

if (strlen($motDePasse) < 8) {
    echouerInscription(
        'Le mot de passe doit contenir au moins 8 caractères.'
    );
}

// Vérifier le fichier envoyé.
if (!isset($_FILES['piece_identite']) ||
    !is_array($_FILES['piece_identite'])) {
    echouerInscription('La pièce d’identité est obligatoire.');
}

$fichier = $_FILES['piece_identite'];

if ($fichier['error'] !== UPLOAD_ERR_OK) {
    echouerInscription(
        'Le transfert de la pièce d’identité a échoué. Réessaie.'
    );
}

if (!is_uploaded_file($fichier['tmp_name'])) {
    echouerInscription('Le fichier transmis est invalide.');
}

$tailleMaximale = 5 * 1024 * 1024;

if ($fichier['size'] <= 0 || $fichier['size'] > $tailleMaximale) {
    echouerInscription(
        'La pièce d’identité doit peser au maximum 5 Mo.'
    );
}

// Vérifier le contenu réel du fichier, et pas seulement son extension.
$fin = new finfo(FILEINFO_MIME_TYPE);
$typeMime = $fin->file($fichier['tmp_name']);

$typesAutorises = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if (!is_string($typeMime) ||
    !isset($typesAutorises[$typeMime])) {
    echouerInscription(
        'Format invalide. Envoie un document PDF, JPG ou PNG.'
    );
}

if (!is_dir($repertoirePrive)) {
    echouerInscription(
        'Le dossier privé des documents est introuvable. Contacte l’administrateur.'
    );
}

if (!is_writable($repertoirePrive)) {
    echouerInscription(
        'Le stockage privé ne permet pas l’enregistrement. Contacte l’administrateur.'
    );
}

// Nom de fichier aléatoire : on ne réutilise jamais le nom fourni.
$nomFichier = bin2hex(random_bytes(24))
    . '.'
    . $typesAutorises[$typeMime];

$cheminComplet = $repertoirePrive
    . DIRECTORY_SEPARATOR
    . $nomFichier;

try {
    if (!move_uploaded_file($fichier['tmp_name'], $cheminComplet)) {
        echouerInscription(
            'Impossible d’enregistrer la pièce d’identité. Réessaie.'
        );
    }

    $fichierEnregistre = $cheminComplet;

    $database = new Database();
    $pdo = $database->getConnection();

    $utilisateur = new Utilisateur($pdo);

    $id = $utilisateur->inscrire(
        $nom,
        $telephone,
        $email === '' ? null : $email,
        $motDePasse,
        $role,
        $typeDocument,
        $nomFichier
    );

    // Supprimer le jeton utilisé pour éviter sa réutilisation.
    unset($_SESSION['csrf_token']);

    $_SESSION['succes_inscription'] =
        'Votre compte a été créé avec succès. Vous pouvez maintenant vous connecter.';

    header('Location: inscription.php');
    exit;

} catch (InvalidArgumentException $e) {
    if ($fichierEnregistre !== null && is_file($fichierEnregistre)) {
        unlink($fichierEnregistre);
    }

    echouerInscription($e->getMessage());

} catch (PDOException $e) {
    if ($fichierEnregistre !== null && is_file($fichierEnregistre)) {
        unlink($fichierEnregistre);
    }

    // Détails techniques uniquement dans les journaux du serveur.
    error_log('Erreur PDO lors de l’inscription MIGON : ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        echouerInscription(
            'Ce numéro de téléphone ou cette adresse e-mail est déjà utilisé.'
        );
    }

    echouerInscription(
        'Une erreur est survenue lors de l’inscription. Réessaie plus tard.'
    );

} catch (Throwable $e) {
    if ($fichierEnregistre !== null && is_file($fichierEnregistre)) {
        unlink($fichierEnregistre);
    }

    error_log('Erreur lors de l’inscription MIGON : ' . $e->getMessage());

    echouerInscription(
        'Une erreur est survenue lors de l’inscription. Réessaie plus tard.'
    );
}
