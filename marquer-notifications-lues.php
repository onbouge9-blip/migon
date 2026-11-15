<?php
session_start();

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Notifications.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: notifications.php');
    exit;
}

$jetonSession = $_SESSION['csrf_token'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)
) {
    http_response_code(403);
    exit('Formulaire expiré. Recharge les notifications puis réessaie.');
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

try {
    $pdo = (new Database())->getConnection();

    // Seules les notifications de l'utilisateur connecté sont concernées.
    $nombre = Notifications::marquerToutesLues($pdo, $idUtilisateur);

    $_SESSION['succes_notifications'] = $nombre > 0
        ? $nombre . ' notification(s) marquée(s) comme lue(s).'
        : 'Toutes vos notifications sont déjà lues.';

} catch (Throwable $e) {
    error_log('Erreur marquage notifications MIGON : ' . $e->getMessage());

    $_SESSION['erreur_notifications'] =
        'Impossible de marquer les notifications comme lues. Réessayez plus tard.';
}

header('Location: notifications.php');
exit;
