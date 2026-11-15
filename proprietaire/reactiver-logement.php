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

// Vérifier que la demande utilise POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logements.php');
    exit;
}

// Vérifier le jeton CSRF.
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['erreur_disponibilite'] =
        'La demande est invalide. Veuillez réessayer.';

    header('Location: logements.php');
    exit;
}

$idProprietaire = (int) $_SESSION['utilisateur_id'];
$idLogement = isset($_POST['id_logement'])
    ? (int) $_POST['id_logement']
    : 0;

if ($idLogement <= 0) {
    $_SESSION['erreur_disponibilite'] =
        'Logement invalide.';

    header('Location: logements.php');
    exit;
}

try {
    $databaseClass = 'Database';
    $pdo = (new $databaseClass())->getConnection();

    // Vérifier que le logement appartient bien au propriétaire.
    $stmt = $pdo->prepare("
        SELECT id_logement, titre, disponibilite
        FROM logement
        WHERE id_logement = :id_logement
          AND id_proprietaire = :id_proprietaire
          AND date_suppression IS NULL
        LIMIT 1
    ");

    $stmt->execute([
        'id_logement' => $idLogement,
        'id_proprietaire' => $idProprietaire
    ]);

    $logement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logement) {
        $_SESSION['erreur_disponibilite'] =
            'Ce logement est introuvable ou ne vous appartient pas.';

        header('Location: logements.php');
        exit;
    }

    // Le paiement de réactivation concerne uniquement
    // un logement actuellement indisponible.
    if ($logement['disponibilite'] !== 'indisponible') {
        $_SESSION['erreur_disponibilite'] =
            'Ce logement est déjà disponible.';

        header('Location: logements.php');
        exit;
    }

    // Vérifier s'il existe déjà une demande de paiement en attente.
    $stmt = $pdo->prepare("
        SELECT id_paiement_reactivation
        FROM paiement_reactivation
        WHERE id_logement = :id_logement
          AND id_proprietaire = :id_proprietaire
          AND statut_paiement = 'en_attente'
        ORDER BY date_creation DESC
        LIMIT 1
    ");

    $stmt->execute([
        'id_logement' => $idLogement,
        'id_proprietaire' => $idProprietaire
    ]);

    $paiementEnAttente = $stmt->fetchColumn();

    if ($paiementEnAttente) {

    // Une demande existe déjà : retourner directement
    // vers sa page de paiement.
    header(
        'Location: paiement-reactivation.php?id=' . (int) $paiementEnAttente
    );
    exit;
}

    // Créer une nouvelle demande de paiement de 500 FCFA.
    $stmt = $pdo->prepare("
        INSERT INTO paiement_reactivation (
            id_logement,
            id_proprietaire,
            montant,
            devise,
            statut_paiement
        )
        VALUES (
            :id_logement,
            :id_proprietaire,
            500.00,
            'XOF',
            'en_attente'
        )
    ");

    $stmt->execute([
        'id_logement' => $idLogement,
        'id_proprietaire' => $idProprietaire
    ]);

    $idPaiement = (int) $pdo->lastInsertId();

    // Rediriger vers la page de paiement.
    header(
        'Location: paiement-reactivation.php?id=' . $idPaiement
    );
    exit;

} catch (Throwable $e) {

    error_log(
        'Erreur réactivation logement MIGON : ' . $e->getMessage()
    );

    $_SESSION['erreur_disponibilite'] =
        'Une erreur est survenue. Veuillez réessayer.';

    header('Location: logements.php');
    exit;
}