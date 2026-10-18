
<?php
session_start();

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

function h(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}

$nom = $_SESSION['utilisateur_nom'] ?? 'Propriétaire';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace propriétaire | MIGON</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .espace {
            max-width: 1100px;
            margin: 40px auto;
            padding: 20px;
        }
        .bienvenue {
            background: #123b32;
            color: white;
            padding: 35px;
            border-radius: 16px;
            margin-bottom: 25px;
        }
        .bienvenue h1 {
            margin-top: 0;
        }
        .actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }
        .action {
            background: white;
            border: 1px solid #e3e8e5;
            border-radius: 12px;
            padding: 24px;
        }
        .action h2 {
            color: #123b32;
            font-size: 20px;
        }
        .action p {
            color: #59645f;
            line-height: 1.6;
        }
        .bouton {
            display: inline-block;
            background: #123b32;
            color: white;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
        }
        .retour {
            display: inline-block;
            margin-bottom: 20px;
             background:white ;
            color: #123b32;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
        }
    </style>
</head>
<body>

<main class="espace">
    <a class="retour" href="../index.php">← Retour à l'accueil</a>

    <section class="bienvenue">
        <h1>Bienvenue, <?= h($nom) ?> !</h1>
        <p>
            Gérez vos logements et préparez vos annonces
            pour les chercheurs de logement sur MIGON.
        </p>
    </section>

    <section class="actions">
        <article class="action">
            <h2>Mes logements</h2>
            <p>Retrouvez et gérez les logements que vous avez enregistrés.</p>
            <a class="bouton" href="logements.php">Voir mes logements</a>
        </article>

        <article class="action">
            <h2>Publier un logement</h2>
            <p>Ajoutez un logement, ses caractéristiques, ses tarifs et ses photos.</p>
            <a class="bouton" href="creer-logement.php">Ajouter un logement</a>
        </article>

        <article class="action">
            <h2>Mon abonnement</h2>
            <p>Consultez les informations relatives à votre abonnement et à vos publications.</p>
            <a class="bouton" href="abonnement.php">Voir mon abonnement</a>
        </article>
    </section>
</main>

</body>
</html>
