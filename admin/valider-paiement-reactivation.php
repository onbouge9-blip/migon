<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

// Vérifier que l'utilisateur est connecté.
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier que l'utilisateur est administrateur.
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

// Accepter uniquement les requêtes POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Vérification du jeton CSRF.
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_admin_paiement']) ||
    !hash_equals(
        $_SESSION['csrf_admin_paiement'],
        $_POST['csrf_token']
    )
) {
    $_SESSION['erreur_admin_paiement'] =
        'La demande est invalide. Veuillez réessayer.';

    header('Location: paiements-reactivation.php');
    exit;
}

$idAdministrateur = (int) $_SESSION['utilisateur_id'];

$idPaiement = isset($_POST['id_paiement'])
    ? (int) $_POST['id_paiement']
    : 0;

if ($idPaiement <= 0) {
    $_SESSION['erreur_admin_paiement'] =
        'Paiement invalide.';

    header('Location: paiements-reactivation.php');
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    // Démarrer une transaction : les deux opérations
    // doivent réussir ensemble.
    $pdo->beginTransaction();

    // Récupérer le paiement en attente.
    $stmt = $pdo->prepare("
        SELECT
            id_paiement_reactivation,
            id_logement,
            id_proprietaire,
            statut_paiement
        FROM paiement_reactivation
        WHERE id_paiement_reactivation = :id_paiement
        FOR UPDATE
    ");

    $stmt->execute([
        'id_paiement' => $idPaiement
    ]);

    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        throw new RuntimeException('Paiement introuvable.');
    }

    if ($paiement['statut_paiement'] !== 'en_attente') {
        throw new RuntimeException(
            'Ce paiement a déjà été traité.'
        );
    }

    // Confirmer le paiement.
    $stmt = $pdo->prepare("
        UPDATE paiement_reactivation
        SET
            statut_paiement = 'confirme',
            valide_par = :id_administrateur,
            date_validation = CURRENT_TIMESTAMP
        WHERE id_paiement_reactivation = :id_paiement
          AND statut_paiement = 'en_attente'
    ");

    $stmt->execute([
        'id_administrateur' => $idAdministrateur,
        'id_paiement' => $idPaiement
    ]);

    // Rendre le logement disponible.
    $stmt = $pdo->prepare("
        UPDATE logement
        SET
            disponibilite = 'disponible',
            date_modification = CURRENT_TIMESTAMP
        WHERE id_logement = :id_logement
          AND id_proprietaire = :id_proprietaire
          AND date_suppression IS NULL
    ");

    $stmt->execute([
        'id_logement' => $paiement['id_logement'],
        'id_proprietaire' => $paiement['id_proprietaire']
    ]);

    // Vérifier que le logement a bien été modifié.
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Impossible de mettre à jour le logement.'
        );
    }

    
   // Créer une notification pour le propriétaire.
$stmt = $pdo->prepare("
    INSERT INTO notification (
        id_utilisateur,
        id_conversation,
        titre,
        contenu,
        type_notification,
        statut_lecture,
        date_creation,
        date_lecture
    )
    VALUES (
        :id_utilisateur,
        NULL,
        :titre,
        :contenu,
        'paiement',
        0,
        CURRENT_TIMESTAMP,
        NULL
    )
");

$stmt->execute([
    'id_utilisateur' => $paiement['id_proprietaire'],
    'titre' => 'Paiement de réactivation confirmé',
    'contenu' => 'Bonne nouvelle ! Votre paiement de réactivation a été confirmé. Votre logement est maintenant disponible.'
]);

// Tout s'est bien passé.
$pdo->commit();

$_SESSION['succes_admin_paiement'] =
    'Le paiement a été confirmé et le logement est maintenant disponible.';
} catch (Throwable $e) {

    // Annuler toutes les modifications en cas d'erreur.
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Erreur validation paiement réactivation MIGON : '
        . $e->getMessage()
    );

    $_SESSION['erreur_admin_paiement'] =
        $e->getMessage();
}

header('Location: paiements-reactivation.php');
exit;