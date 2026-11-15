<?php
session_start();

require_once __DIR__ . '/classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

$jetonSession = $_SESSION['csrf_token'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)
) {
    http_response_code(403);
    exit('Formulaire expiré. Recharge la fiche du logement puis réessaie.');
}

$idLogement = filter_input(
    INPUT_POST,
    'id_logement',
    FILTER_VALIDATE_INT
);

if (!$idLogement || $idLogement < 1) {
    http_response_code(404);
    exit('Logement introuvable.');
}

try {
    $pdo = (new Database())->getConnection();

    // Vérifier que le logement est publié et récupérer son propriétaire.
    $requete = $pdo->prepare("
        SELECT id_logement, id_proprietaire
        FROM logement
        WHERE id_logement = :id_logement
          AND statut = 'publie'
          AND date_suppression IS NULL
        LIMIT 1
    ");

    $requete->execute(['id_logement' => $idLogement]);
    $logement = $requete->fetch();

    if (!$logement) {
        http_response_code(404);
        exit('Ce logement est introuvable ou indisponible.');
    }

    $idProprietaire = (int) $logement['id_proprietaire'];

    // Empêcher le propriétaire de se contacter lui-même.
    if ($idUtilisateur === $idProprietaire) {
        http_response_code(403);
        exit('Tu ne peux pas te contacter toi-même.');
    }

    // Vérifier le rôle du compte connecté.
    $requeteRole = $pdo->prepare("
        SELECT COUNT(*)
        FROM utilisateur_role AS ur
        INNER JOIN role AS r ON r.id_role = ur.id_role
        WHERE ur.id_utilisateur = :id_utilisateur
          AND r.nom_role = 'chercheur'
    ");

    $requeteRole->execute(['id_utilisateur' => $idUtilisateur]);

    if ((int) $requeteRole->fetchColumn() === 0) {
        http_response_code(403);
        exit('Seul un chercheur peut démarrer une conversation.');
    }

    $pdo->beginTransaction();

    // Chercher une conversation ouverte pour ce logement
    // dans laquelle les deux personnes sont participantes.
    $requeteConversation = $pdo->prepare("
        SELECT c.id_conversation
        FROM conversation AS c
        INNER JOIN participant_conversation AS pc1
            ON pc1.id_conversation = c.id_conversation
           AND pc1.id_utilisateur = :chercheur
        INNER JOIN participant_conversation AS pc2
            ON pc2.id_conversation = c.id_conversation
           AND pc2.id_utilisateur = :proprietaire
        WHERE c.id_logement = :logement
          AND c.statut_conversation = 'ouverte'
        LIMIT 1
        FOR UPDATE
    ");

    $requeteConversation->execute([
        'chercheur' => $idUtilisateur,
        'proprietaire' => $idProprietaire,
        'logement' => $idLogement,
    ]);

    $idConversation = $requeteConversation->fetchColumn();

    if (!$idConversation) {
        // Créer la conversation.
        $requeteCreation = $pdo->prepare("
            INSERT INTO conversation (id_logement, statut_conversation)
            VALUES (:id_logement, 'ouverte')
        ");

        $requeteCreation->execute([
            'id_logement' => $idLogement,
        ]);

        $idConversation = (int) $pdo->lastInsertId();

        // Enregistrer les deux participants.
        $requeteParticipant = $pdo->prepare("
            INSERT INTO participant_conversation
                (id_conversation, id_utilisateur)
            VALUES
                (:id_conversation, :id_utilisateur)
        ");

        $requeteParticipant->execute([
            'id_conversation' => $idConversation,
            'id_utilisateur' => $idUtilisateur,
        ]);

        $requeteParticipant->execute([
            'id_conversation' => $idConversation,
            'id_utilisateur' => $idProprietaire,
        ]);
    }

    $pdo->commit();

    header(
        'Location: conversation.php?id=' . (int) $idConversation
    );
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur contact propriétaire MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de démarrer la conversation pour le moment.');
}
