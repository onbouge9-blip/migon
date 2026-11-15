<?php

session_start();

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notifications.php';

// 1. Vérifier la connexion
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// 2. Vérifier le rôle administrateur
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    http_response_code(403);
    exit('Accès refusé.');
}

// 3. Accepter uniquement les requêtes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

// 4. Vérifier le jeton CSRF
$jeton = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_verification']) ||
    !is_string($jeton) ||
    !hash_equals($_SESSION['csrf_verification'], $jeton)
) {
    http_response_code(403);
    exit('Formulaire expiré ou invalide. Recharge la page.');
}

// 5. Récupérer les données
$idDocument = filter_input(INPUT_POST, 'id_document', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
$motif = trim($_POST['motif_refus'] ?? '');

if (!$idDocument || !in_array($action, ['verifier', 'refuser'], true)) {
    exit('Informations invalides.');
}

if ($action === 'refuser' && $motif === '') {
    exit('Tu dois indiquer un motif de refus.');
}

if (strlen($motif) > 5000) {
    exit('Le motif de refus est trop long.');
}

try {
    $pdo = (new Database())->getConnection();

    $pdo->beginTransaction();

    // 6. Verrouiller le document pendant le traitement
    $requete = $pdo->prepare("
        SELECT id_utilisateur, statut_verification
        FROM document_identite
        WHERE id_document = :id_document
        FOR UPDATE
    ");

    $requete->execute([
        'id_document' => $idDocument,
    ]);

    $document = $requete->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        throw new RuntimeException('Document introuvable.');
    }

    if ($document['statut_verification'] !== 'en_attente') {
        throw new RuntimeException('Ce document a déjà été traité.');
    }

    $idUtilisateur = (int) $document['id_utilisateur'];

    // 7. Déterminer le nouveau statut
    if ($action === 'verifier') {
        $nouveauStatut = 'verifie';
        $nouveauMotif = null;

        $titre = 'Identité vérifiée';
        $contenu = 'Bonne nouvelle ! Votre document d’identité a été vérifié avec succès.';
    } else {
        $nouveauStatut = 'refuse';
        $nouveauMotif = $motif;

        $titre = 'Document d’identité refusé';
        $contenu = 'Votre document d’identité a été refusé. Motif : ' . $motif;
    }

    // 8. Mettre à jour le document
    $miseAJour = $pdo->prepare("
        UPDATE document_identite
        SET statut_verification = :statut,
            motif_refus = :motif
        WHERE id_document = :id_document
          AND statut_verification = 'en_attente'
    ");

    $miseAJour->execute([
        'statut' => $nouveauStatut,
        'motif' => $nouveauMotif,
        'id_document' => $idDocument,
    ]);

    if ($miseAJour->rowCount() !== 1) {
        throw new RuntimeException('La mise à jour du document a échoué.');
    }

    // 9. Envoyer une notification à l'utilisateur
    Notifications::creer(
        $pdo,
        $idUtilisateur,
        $titre,
        $contenu,
        'identite'
    );

    // 10. Confirmer les opérations
    $pdo->commit();

    // Renouveler le jeton CSRF
    $_SESSION['csrf_verification'] = bin2hex(random_bytes(32));

    header('Location: identites.php?resultat=succes');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur vérification identité MIGON : ' . $e->getMessage());

    http_response_code(400);
    exit('Une erreur est survenue. Vérifie les informations et réessaie.');
}
