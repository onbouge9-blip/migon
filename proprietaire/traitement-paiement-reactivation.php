<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

// Vérifier que l'utilisateur est connecté.
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier que l'utilisateur est un propriétaire.
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

// Accepter uniquement les requêtes POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logements.php');
    exit;
}

// Vérifier le jeton CSRF.
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_paiement_reactivation']) ||
    !hash_equals(
        $_SESSION['csrf_paiement_reactivation'],
        $_POST['csrf_token']
    )
) {
    $_SESSION['erreur_disponibilite'] =
        'La demande de paiement est invalide. Veuillez réessayer.';

    header('Location: logements.php');
    exit;
}

$idProprietaire = (int) $_SESSION['utilisateur_id'];
$idPaiement = isset($_POST['id_paiement'])
    ? (int) $_POST['id_paiement']
    : 0;

$methodePaiement = trim(
    (string) ($_POST['methode_paiement'] ?? '')
);

$referencePaiement = trim(
    (string) ($_POST['reference_paiement'] ?? '')
);

// Vérifier les données reçues.
$methodesAutorisees = [
    'mobile_money',
    'virement',
    'especes',
    'autre'
];

if (
    $idPaiement <= 0 ||
    !in_array($methodePaiement, $methodesAutorisees, true)
) {
    $_SESSION['erreur_disponibilite'] =
        'Veuillez sélectionner une méthode de paiement valide.';

    header(
        'Location: paiement-reactivation.php?id=' . $idPaiement
    );
    exit;
}

if (mb_strlen($referencePaiement) > 190) {
    $_SESSION['erreur_disponibilite'] =
        'La référence du paiement est trop longue.';

    header(
        'Location: paiement-reactivation.php?id=' . $idPaiement
    );
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    // Vérifier que cette demande appartient au propriétaire connecté.
    $stmt = $pdo->prepare("
        SELECT
            id_paiement_reactivation,
            statut_paiement,
            id_logement
        FROM paiement_reactivation
        WHERE id_paiement_reactivation = :id_paiement
          AND id_proprietaire = :id_proprietaire
        LIMIT 1
    ");

    $stmt->execute([
        'id_paiement' => $idPaiement,
        'id_proprietaire' => $idProprietaire
    ]);

    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        $_SESSION['erreur_disponibilite'] =
            'Cette demande de paiement est introuvable.';

        header('Location: logements.php');
        exit;
    }

    // Un paiement déjà confirmé ou refusé ne doit plus être modifié.
    if ($paiement['statut_paiement'] !== 'en_attente') {
        $_SESSION['erreur_disponibilite'] =
            'Cette demande de paiement a déjà été traitée.';

        header('Location: logements.php');
        exit;
    }

    // Enregistrer les informations fournies par le propriétaire.
    $stmt = $pdo->prepare("
        UPDATE paiement_reactivation
        SET
            methode_paiement = :methode_paiement,
            reference_paiement = :reference_paiement,
            date_paiement = CURRENT_TIMESTAMP
        WHERE id_paiement_reactivation = :id_paiement
          AND id_proprietaire = :id_proprietaire
          AND statut_paiement = 'en_attente'
    ");

    $stmt->execute([
        'methode_paiement' => $methodePaiement,
        'reference_paiement' =>
            $referencePaiement !== ''
                ? $referencePaiement
                : null,
        'id_paiement' => $idPaiement,
        'id_proprietaire' => $idProprietaire
    ]);

    // Le jeton a été utilisé : on le renouvelle.
    unset($_SESSION['csrf_paiement_reactivation']);

    $_SESSION['succes_disponibilite'] =
        'Votre demande de paiement de 500 FCFA a été enregistrée. '
        . 'Votre logement redeviendra disponible après confirmation du paiement.';

    header('Location: logements.php');
    exit;

} catch (Throwable $e) {

    error_log(
        'Erreur traitement paiement réactivation MIGON : '
        . $e->getMessage()
    );

    $_SESSION['erreur_disponibilite'] =
        'Une erreur est survenue lors de l’enregistrement du paiement.';

    header('Location: logements.php');
    exit;
}