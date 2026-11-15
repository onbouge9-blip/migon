<?php
session_start();

require_once __DIR__ . '/classes/Database.php';

function retournerConversation(int $idConversation, string $message): void
{
    $_SESSION['erreur_message'] = $message;
    header('Location: conversation.php?id=' . $idConversation);
    exit;
}

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: messagerie.php');
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
    $_SESSION['erreur_message'] =
        'Le formulaire a expiré. Recharge la conversation puis réessaie.';
    header('Location: messagerie.php');
    exit;
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

$idConversation = filter_input(
    INPUT_POST,
    'id_conversation',
    FILTER_VALIDATE_INT
);

$contenu = $_POST['contenu'] ?? '';

if (!$idConversation || $idConversation < 1) {
    header('Location: messagerie.php');
    exit;
}

if (!is_string($contenu)) {
    retournerConversation($idConversation, 'Le message est invalide.');
}

$contenu = trim($contenu);

if ($contenu === '') {
    retournerConversation(
        $idConversation,
        'Écris un message avant de l’envoyer.'
    );
}

if (mb_strlen($contenu, 'UTF-8') > 10000) {
    retournerConversation(
        $idConversation,
        'Le message ne peut pas dépasser 10 000 caractères.'
    );
}

try {
    $pdo = (new Database())->getConnection();

    // Vérifier que l'utilisateur participe à une conversation ouverte.
    $requete = $pdo->prepare("
        SELECT c.id_conversation
        FROM conversation AS c
        INNER JOIN participant_conversation AS pc
            ON pc.id_conversation = c.id_conversation
        WHERE c.id_conversation = :id_conversation
          AND pc.id_utilisateur = :id_utilisateur
          AND c.statut_conversation = 'ouverte'
        LIMIT 1
    ");

    $requete->execute([
        'id_conversation' => $idConversation,
        'id_utilisateur' => $idUtilisateur,
    ]);

    if (!$requete->fetch()) {
        http_response_code(403);
        exit('Tu ne peux pas envoyer de message dans cette conversation.');
    }

    $pdo->beginTransaction();

    // 1. Enregistrer le message.
    $requeteInsertion = $pdo->prepare("
        INSERT INTO message (
            id_conversation,
            id_expediteur,
            contenu
        ) VALUES (
            :id_conversation,
            :id_expediteur,
            :contenu
        )
    ");

    $requeteInsertion->execute([
        'id_conversation' => $idConversation,
        'id_expediteur' => $idUtilisateur,
        'contenu' => $contenu,
    ]);

    // 2. Identifier le logement et son propriétaire.
    $requeteLogement = $pdo->prepare("
        SELECT c.id_logement, l.id_proprietaire
        FROM conversation AS c
        INNER JOIN logement AS l
            ON l.id_logement = c.id_logement
        WHERE c.id_conversation = :id_conversation
        LIMIT 1
    ");

    $requeteLogement->execute([
        'id_conversation' => $idConversation,
    ]);

    $logement = $requeteLogement->fetch(PDO::FETCH_ASSOC);

    if (!$logement) {
        throw new RuntimeException('Logement de conversation introuvable.');
    }

    $idProprietaire = (int) $logement['id_proprietaire'];

    // 3. Déterminer le destinataire : l'autre participant.
    $requeteDestinataire = $pdo->prepare("
        SELECT id_utilisateur
        FROM participant_conversation
        WHERE id_conversation = :id_conversation
          AND id_utilisateur <> :expediteur
        LIMIT 1
    ");

    $requeteDestinataire->execute([
        'id_conversation' => $idConversation,
        'expediteur' => $idUtilisateur,
    ]);

    $idDestinataire = $requeteDestinataire->fetchColumn();

    if ($idDestinataire === false) {
        throw new RuntimeException('Destinataire de message introuvable.');
    }

    $idDestinataire = (int) $idDestinataire;

    // 4. Récupérer le nom de l'expéditeur pour le titre.
    $requeteNom = $pdo->prepare("
        SELECT nom
        FROM utilisateur
        WHERE id_utilisateur = :id_utilisateur
        LIMIT 1
    ");

    $requeteNom->execute([
        'id_utilisateur' => $idUtilisateur,
    ]);

    $nomExpediteur = $requeteNom->fetchColumn();

    if ($nomExpediteur === false) {
        $nomExpediteur = 'Un utilisateur';
    }

    // 5. Créer la notification pour l'autre participant.
    $titreNotification = 'Nouveau message de ' . $nomExpediteur;
    $contenuNotification = mb_substr($contenu, 0, 500, 'UTF-8');

    $requeteNotification = $pdo->prepare("
    INSERT INTO notification (
        id_utilisateur,
        id_conversation,
        titre,
        contenu,
        type_notification,
        statut_lecture
    ) VALUES (
        :id_utilisateur,
        :id_conversation,
        :titre,
        :contenu,
        'message',
        0
    )
");

   $requeteNotification->execute([
    'id_utilisateur' => $idDestinataire,
    'id_conversation' => $idConversation,
    'titre' => $titreNotification,
    'contenu' => $contenuNotification,
]);

    // 6. Mettre à jour la date de modification de la conversation.
    $requeteMaj = $pdo->prepare("
        UPDATE conversation
        SET date_modification = CURRENT_TIMESTAMP
        WHERE id_conversation = :id_conversation
    ");

    $requeteMaj->execute([
        'id_conversation' => $idConversation,
    ]);

    // Valider ensemble le message et la notification.
    $pdo->commit();

    header('Location: conversation.php?id=' . $idConversation);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur envoi message MIGON : ' . $e->getMessage());

    retournerConversation(
        $idConversation,
        'Une erreur technique a empêché l’envoi du message.'
    );
}
