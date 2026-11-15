<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

// Vérifier la connexion.
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier le rôle administrateur.
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

// Accepter uniquement POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: paiements-reactivation.php');
    exit;
}

// Vérifier le jeton CSRF.
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_admin_paiement']) ||
    !hash_equals(
        $_SESSION['csrf_admin_paiement'],
        $_POST['csrf_token']
    )
) {
    $_SESSION['erreur_admin_paiement'] =
        'La demande est invalide.';

    header('Location: paiements-reactivation.php');
    exit;
}

$idAdministrateur = (int) $_SESSION['utilisateur_id'];
$idPaiement = (int) ($_POST['id_paiement'] ?? 0);
$commentaire = trim((string) ($_POST['commentaire'] ?? ''));

if ($idPaiement <= 0) {
    $_SESSION['erreur_admin_paiement'] =
        'Paiement invalide.';

    header('Location: paiements-reactivation.php');
    exit;
}

if (mb_strlen($commentaire) > 500) {
    $_SESSION['erreur_admin_paiement'] =
        'Le commentaire est trop long.';

    header('Location: paiements-reactivation.php');
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    $pdo->beginTransaction();

    // Vérifier que le paiement existe et est toujours en attente.
    $stmt = $pdo->prepare("
        SELECT id_paiement_reactivation
        FROM paiement_reactivation
        WHERE id_paiement_reactivation = :id_paiement
          AND statut_paiement = 'en_attente'
        FOR UPDATE
    ");

    $stmt->execute([
        'id_paiement' => $idPaiement
    ]);

    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        throw new RuntimeException(
            'Ce paiement est introuvable ou a déjà été traité.'
        );
    }

    // Refuser le paiement.
    $stmt = $pdo->prepare("
        UPDATE paiement_reactivation
        SET
            statut_paiement = 'refuse',
            valide_par = :id_administrateur,
            date_validation = CURRENT_TIMESTAMP,
            commentaire_validation = :commentaire
        WHERE id_paiement_reactivation = :id_paiement
          AND statut_paiement = 'en_attente'
    ");

    $stmt->execute([
        'id_administrateur' => $idAdministrateur,
        'commentaire' => $commentaire !== '' ? $commentaire : null,
        'id_paiement' => $idPaiement
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Impossible de mettre à jour le paiement.'
        );
    }

    $pdo->commit();

    $_SESSION['succes_admin_paiement'] =
        'Le paiement a été refusé. Le logement reste indisponible.';

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Erreur refus paiement réactivation MIGON : '
        . $e->getMessage()
    );

    $_SESSION['erreur_admin_paiement'] =
        $e->getMessage();
}

header('Location: paiements-reactivation.php');
exit;