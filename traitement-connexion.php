<?php

session_start();

require_once __DIR__ . '/classes/Database.php';

function echecConnexion(string $message): void
{
    $_SESSION['erreur_connexion'] = $message;
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: connexion.php');
    exit;
}

// Vérification du jeton CSRF.
$jetonSession = $_SESSION['csrf_token'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)
) {
    echecConnexion(
        'Le formulaire a expiré. Recharge la page puis réessaie.'
    );
}

$telephone = $_POST['telephone'] ?? '';
$motDePasse = $_POST['mot_de_passe'] ?? '';

if (
    !is_string($telephone) ||
    !is_string($motDePasse) ||
    trim($telephone) === '' ||
    $motDePasse === ''
) {
    echecConnexion('Renseigne ton numéro de téléphone et ton mot de passe.');
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    // Rechercher le compte avec son numéro de téléphone.
    $requete = $pdo->prepare(
        'SELECT id_utilisateur, nom, telephone, mot_de_passe, statut_compte
         FROM utilisateur
         WHERE telephone = :telephone
         LIMIT 1'
    );

    $requete->execute([
        'telephone' => trim($telephone),
    ]);

    $utilisateur = $requete->fetch();

    // Même message si le compte ou le mot de passe est incorrect.
    if (
        !$utilisateur ||
        !password_verify($motDePasse, $utilisateur['mot_de_passe'])
    ) {
        echecConnexion('Numéro de téléphone ou mot de passe incorrect.');
    }

    if ($utilisateur['statut_compte'] !== 'actif') {
        echecConnexion(
            'Ce compte n’est pas actif. Contacte l’administration de MIGON.'
        );
    }

    // Charger tous les rôles du compte.
    $requeteRoles = $pdo->prepare(
        'SELECT r.nom_role
         FROM utilisateur_role ur
         INNER JOIN role r ON r.id_role = ur.id_role
         WHERE ur.id_utilisateur = :id_utilisateur'
    );

    $requeteRoles->execute([
        'id_utilisateur' => $utilisateur['id_utilisateur'],
    ]);

    $roles = $requeteRoles->fetchAll(PDO::FETCH_COLUMN);

    if (!$roles) {
        echecConnexion(
            'Aucun rôle n’est associé à ce compte. Contacte l’administration.'
        );
    }

    // Renouveler l'identifiant de session après authentification.
    session_regenerate_id(true);

    $_SESSION['utilisateur_id'] = (int) $utilisateur['id_utilisateur'];
    $_SESSION['utilisateur_nom'] = $utilisateur['nom'];
    $_SESSION['utilisateur_telephone'] = $utilisateur['telephone'];
    $_SESSION['utilisateur_roles'] = $roles;

    unset($_SESSION['csrf_token']);

    // Choisir une destination selon les rôles.
    if (in_array('administrateur', $roles, true)) {
        header('Location: admin/index.php');
        exit;
    }

    if (in_array('proprietaire', $roles, true)) {
        header('Location: proprietaire/index.php');
        exit;
    }

    header('Location: index.php');
    exit;

} catch (Throwable $e) {
    error_log('Erreur de connexion MIGON : ' . $e->getMessage());

    echecConnexion(
        'Une erreur technique est survenue. Réessaie plus tard.'
    );
}
