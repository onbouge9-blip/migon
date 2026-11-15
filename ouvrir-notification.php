<?php
session_start();

require_once __DIR__ . '/classes/Database.php';

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

$idNotification = filter_input(
    INPUT_POST,
    'id_notification',
    FILTER_VALIDATE_INT
);

if (!$idNotification || $idNotification < 1) {
    header('Location: notifications.php');
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    $pdo->beginTransaction();

    $requete = $pdo->prepare("
        SELECT id_conversation
        FROM notification
        WHERE id_notification = :id_notification
          AND id_utilisateur = :id_utilisateur
        LIMIT 1
        FOR UPDATE
    ");

    $requete->execute([
        'id_notification' => $idNotification,
        'id_utilisateur' => $idUtilisateur,
    ]);

    $idConversation = $requete->fetchColumn();

    if ($idConversation === false) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Notification introuvable.');
    }

    if ($idConversation !== null) {
        $requeteParticipant = $pdo->prepare("
            SELECT COUNT(*)
            FROM participant_conversation
            WHERE id_conversation = :id_conversation
              AND id_utilisateur = :id_utilisateur
        ");

        $requeteParticipant->execute([
            'id_conversation' => (int) $idConversation,
            'id_utilisateur' => $idUtilisateur,
        ]);

        if ((int) $requeteParticipant->fetchColumn() === 0) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Tu ne participes pas à cette conversation.');
        }
    }

    $requeteLecture = $pdo->prepare("
        UPDATE notification
        SET statut_lecture = 1,
            date_lecture = CURRENT_TIMESTAMP
        WHERE id_notification = :id_notification
          AND id_utilisateur = :id_utilisateur
    ");

    $requeteLecture->execute([
        'id_notification' => $idNotification,
        'id_utilisateur' => $idUtilisateur,
    ]);

    $pdo->commit();

    if ($idConversation !== null) {
        header(
            'Location: conversation.php?id=' . (int) $idConversation
        );
        exit;
    }

    header('Location: notifications.php');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur ouverture notification MIGON : ' . $e->getMessage());

    http_response_code(500);
    exit('Impossible d’ouvrir cette notification pour le moment.');
}
