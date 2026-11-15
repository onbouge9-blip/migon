<?php

session_start();

require_once __DIR__ . '/classes/Database.php';

if (empty($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if (empty($_SESSION['csrf_renvoi_document'])) {
    $_SESSION['csrf_renvoi_document'] = bin2hex(random_bytes(32));
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

try {
    $pdo = (new Database())->getConnection();

    $requete = $pdo->prepare("
        SELECT statut_verification, motif_refus
        FROM document_identite
        WHERE id_utilisateur = :id_utilisateur
        ORDER BY date_soumission DESC, id_document DESC
        LIMIT 1
    ");

    $requete->execute([
        'id_utilisateur' => $idUtilisateur
    ]);

    $document = $requete->fetch(PDO::FETCH_ASSOC);

    if (!$document || $document['statut_verification'] !== 'refuse') {
        header('Location: notifications.php');
        exit;
    }

} catch (Throwable $e) {
    error_log('Erreur renvoi document MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de charger le formulaire.');
}

function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renvoyer une pièce d'identité | MIGON</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<main class="auth-container">
    <section class="auth-card">

        <a href="index.php" class="logo">MIGON</a>

        <h1>Soumettre un nouveau document</h1>

        <p class="intro">
            Votre précédent document a été refusé.
            Vous pouvez envoyer une nouvelle pièce d'identité.
        </p>

        <p class="message erreur">
            Motif du refus :
            <?= h($document['motif_refus'] ?: 'Motif non précisé.') ?>
        </p>

        <?php if (!empty($_SESSION['erreur_renvoi_document'])): ?>
            <p class="message erreur">
                <?= h($_SESSION['erreur_renvoi_document']) ?>
            </p>
            <?php unset($_SESSION['erreur_renvoi_document']); ?>
        <?php endif; ?>

        <form action="traitement-renvoi-document.php"
              method="POST"
              enctype="multipart/form-data">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= h($_SESSION['csrf_renvoi_document']) ?>">

            <label for="type_document">Type de pièce d'identité</label>

            <select id="type_document" name="type_document" required>
                <option value="">Choisir un document</option>
                <option value="carte_identite">Carte d'identité</option>
                <option value="passeport">Passeport</option>
                <option value="permis_conduire">Permis de conduire</option>
            </select>

            <label for="piece_identite">Nouvelle pièce d'identité</label>

            <input type="file"
                   id="piece_identite"
                   name="piece_identite"
                   accept=".jpg,.jpeg,.png,.pdf"
                   required>

            <small>
                Formats acceptés : JPG, PNG ou PDF.
                Taille maximale : 5 Mo.
            </small>

            <button type="submit" class="btn-principal">
                Envoyer le nouveau document
            </button>

        </form>

        <p class="auth-footer">
            <a href="notifications.php">Retour aux notifications</a>
        </p>

    </section>
</main>

</body>
</html>
