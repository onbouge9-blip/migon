<?php

session_start();

if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    http_response_code(403);
    exit('Accès refusé : cette page est réservée à l’administration MIGON.');
}

$nom = $_SESSION['utilisateur_nom'] ?? 'Administrateur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administration | MIGON</title>

    <link rel="stylesheet" href="../public/css/style.css">
</head>

<body>

<main class="auth-container">
    <section class="auth-card">

        <a href="../index.php" class="logo">MIGON</a>

        <h1>Espace administration</h1>

        <p class="intro">
            Bienvenue,
            <?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') ?>.
        </p>

        <p>
            Votre compte administrateur est connecté.
        </p>

        <h2>Gestion des identités</h2>

        <p>
            <a class="btn-principal" href="identites.php">
                Consulter les documents d'identité
            </a>
        </p>

        <h2>Gestion des paiements</h2>

        <p>
            <a class="btn-principal" href="paiements.php">
                Valider les paiements d’abonnement
            </a>
        </p>

        <p>
            <a class="btn-principal" href="paiements-reactivation.php">
                Gérer les paiements de réactivation
            </a>
        </p>

        <h2>Notifications</h2>

        <p>
            <a class="btn-principal" href="../notifications.php">
                Consulter les notifications
            </a>
        </p>

        <p class="auth-footer">
            <a href="../index.php">Retour à l'accueil</a>
        </p>

    </section>
</main>

</body>
</html>