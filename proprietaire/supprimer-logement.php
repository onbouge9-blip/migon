<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../classes/Database.php';

function retour(string $message, string $type = 'erreur_logement'): never
{
    $_SESSION[$type] = $message;
    header('Location: logements.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logements.php');
    exit;
}

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

$jeton = $_POST['csrf_token'] ?? '';

if (
    !is_string($jeton)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $jeton)
) {
    retour('La demande a expiré. Recharge la page et réessaie.');
}

$idLogement = filter_input(INPUT_POST, 'id_logement', FILTER_VALIDATE_INT);

if (!$idLogement || $idLogement < 1) {
    retour('Identifiant de logement invalide.');
}

try {
    $pdo = (new Database())->getConnection();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "UPDATE logement
         SET date_suppression = NOW(),
             statut = 'supprime'
         WHERE id_logement = ?
           AND id_proprietaire = ?
           AND date_suppression IS NULL"
    );

    $stmt->execute([
        $idLogement,
        (int) $_SESSION['utilisateur_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        retour('Logement introuvable ou déjà supprimé.');
    }

    $pdo->commit();

    retour(
        'Le logement a été supprimé de ta liste.',
        'succes_logement'
    );

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur suppression MIGON : ' . $e->getMessage());

    retour(
        'Une erreur est survenue pendant la suppression.'
    );
}
