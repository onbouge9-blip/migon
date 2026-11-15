<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

/*
|--------------------------------------------------------------------------
| Vérifier que l'utilisateur est connecté
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérifier que l'utilisateur est propriétaire
|--------------------------------------------------------------------------
*/

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Accepter uniquement les requêtes POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification du jeton CSRF
|--------------------------------------------------------------------------
*/

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($csrfSession) ||
    !is_string($csrfFormulaire) ||
    $csrfSession === '' ||
    !hash_equals($csrfSession, $csrfFormulaire)
) {
    http_response_code(403);
    exit('La demande a expiré. Recharge la page et réessaie.');
}

/*
|--------------------------------------------------------------------------
| Récupération et validation des données
|--------------------------------------------------------------------------
*/

$idLogement = filter_input(
    INPUT_POST,
    'id_logement',
    FILTER_VALIDATE_INT
);

$action = $_POST['action'] ?? '';

if (!$idLogement || $idLogement < 1) {
    header('Location: logements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Connexion et vérification du logement
|--------------------------------------------------------------------------
*/

try {
    $pdo = (new Database())->getConnection();

    $idProprietaire = (int) $_SESSION['utilisateur_id'];

    /*
     * Vérifier que le logement appartient bien
     * au propriétaire connecté.
     */
    $requete = $pdo->prepare("
        SELECT id_logement, disponibilite
        FROM logement
        WHERE id_logement = :id_logement
          AND id_proprietaire = :id_proprietaire
          AND date_suppression IS NULL
        LIMIT 1
    ");

    $requete->execute([
        'id_logement' => $idLogement,
        'id_proprietaire' => $idProprietaire,
    ]);

    $logement = $requete->fetch(PDO::FETCH_ASSOC);

    if (!$logement) {
        $_SESSION['erreur_disponibilite'] =
            'Logement introuvable ou accès non autorisé.';

        header('Location: logements.php');
        exit;
    }

    /*
     * Rendre le logement indisponible.
     */
    if ($action === 'rendre_indisponible') {

        if ($logement['disponibilite'] === 'indisponible') {
            $_SESSION['erreur_disponibilite'] =
                'Ce logement est déjà indisponible.';

            header('Location: logements.php');
            exit;
        }

        $miseAJour = $pdo->prepare("
            UPDATE logement
            SET disponibilite = 'indisponible',
                date_modification = CURRENT_TIMESTAMP
            WHERE id_logement = :id_logement
              AND id_proprietaire = :id_proprietaire
              AND date_suppression IS NULL
        ");

        $miseAJour->execute([
            'id_logement' => $idLogement,
            'id_proprietaire' => $idProprietaire,
        ]);

        $_SESSION['succes_disponibilite'] =
            'Le logement est maintenant marqué comme indisponible.';

        header('Location: logements.php');
        exit;
    }

    /*
     * Action inconnue.
     */
    $_SESSION['erreur_disponibilite'] =
        'Action non autorisée.';

    header('Location: logements.php');
    exit;

} catch (Throwable $e) {

    error_log(
        'Erreur disponibilité logement MIGON : ' . $e->getMessage()
    );

    $_SESSION['erreur_disponibilite'] =
        'Une erreur est survenue. Réessaie plus tard.';

    header('Location: logements.php');
    exit;
}