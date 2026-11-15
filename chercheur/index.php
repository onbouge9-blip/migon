<?php

session_start();

// Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier le rôle chercheur
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('chercheur', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

// Fonction de protection HTML
function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

$nom = $_SESSION['utilisateur_nom'] ?? 'Chercheur';

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notifications.php';

try {
    $pdo = (new Database())->getConnection();

    $idUtilisateur = (int) $_SESSION['utilisateur_id'];

    // Compter les notifications non lues
    $nombreNotificationsNonLues = Notifications::compterNonLues(
        $pdo,
        $idUtilisateur
    );

    // Récupérer le dernier document d'identité
    $requete = $pdo->prepare("
        SELECT
            id_document,
            type_document,
            statut_verification,
            motif_refus,
            date_soumission
        FROM document_identite
        WHERE id_utilisateur = :id_utilisateur
        ORDER BY date_soumission DESC, id_document DESC
        LIMIT 1
    ");

    $requete->execute([
        'id_utilisateur' => $idUtilisateur
    ]);

    $document = $requete->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Erreur espace chercheur MIGON : ' . $e->getMessage());

    http_response_code(500);
    exit('Impossible de charger votre espace pour le moment.');
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Espace chercheur | MIGON</title>

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

        .badge-notifications {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            padding: 0 6px;
            margin-left: 6px;
            border-radius: 50px;
            background: #dc2626;
            color: white;
            font-size: 13px;
            font-weight: bold;
            vertical-align: middle;
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

        .bouton:hover {
            background: #1b5548;
        }

        .retour {
            display: inline-block;
            margin-bottom: 20px;
            background: white;
            color: #123b32;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
        }

        .statut {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .statut-attente {
            background: #fef3c7;
            color: #92400e;
        }

        .statut-verifie {
            background: #dcfce7;
            color: #166534;
        }

        .statut-refuse {
            background: #fee2e2;
            color: #991b1b;
        }

        .motif-refus {
            background: #fff1f2;
            border-left: 4px solid #dc2626;
            padding: 12px;
            margin: 12px 0;
            border-radius: 6px;
            color: #991b1b;
        }

        .date-document {
            font-size: 13px;
            color: #777;
        }

    </style>

</head>

<body>

<main class="espace">

    <a class="retour" href="../index.php">
        ← Retour à l'accueil
    </a>

    <!-- Bienvenue -->
    <section class="bienvenue">

        <h1>Bienvenue, <?= h($nom) ?> !</h1>

        <p>
            Retrouvez les logements disponibles autour de vous
            et échangez directement avec les propriétaires
            sur MIGON.
        </p>

    </section>


    <section class="actions">

        <!-- Recherche de logements -->
        <article class="action">

            <h2>Rechercher un logement</h2>

            <p>
                Découvrez les logements disponibles et trouvez
                celui qui correspond à vos besoins.
            </p>

            <a class="bouton" href="logements.php">
                Découvrir les logements
            </a>

        </article>


        <!-- Messagerie -->
        <article class="action">

            <h2>Ma messagerie</h2>

            <p>
                Échangez directement avec les propriétaires
                concernant les logements qui vous intéressent.
            </p>

            <a class="bouton" href="../messagerie.php">
                Ouvrir ma messagerie
            </a>

        </article>


        <!-- Notifications -->
        <article class="action">

            <h2>
                Mes notifications

                <?php if ($nombreNotificationsNonLues > 0): ?>

                    <span class="badge-notifications">
                        <?= (int) $nombreNotificationsNonLues ?>
                    </span>

                <?php endif; ?>

            </h2>

            <p>
                Consultez vos nouveaux messages et les notifications
                concernant votre compte.
            </p>

            <a class="bouton" href="../notifications.php">
                Voir mes notifications
            </a>

        </article>


        <!-- Vérification identité -->
        <article class="action">

            <h2>Vérification de mon identité</h2>

            <?php if (!$document): ?>

                <span class="statut statut-attente">
                    Aucun document
                </span>

                <p>
                    Vous n'avez pas encore soumis de pièce d'identité.
                    Veuillez contacter l'assistance pour connaître
                    la procédure de dépôt.
                </p>

            <?php elseif ($document['statut_verification'] === 'en_attente'): ?>

                <span class="statut statut-attente">
                    En attente
                </span>

                <p>
                    Votre pièce d'identité a bien été reçue.
                    Elle est actuellement en cours de vérification
                    par notre équipe.
                </p>

                <p class="date-document">
                    Soumis le :
                    <?= h($document['date_soumission']) ?>
                </p>

            <?php elseif ($document['statut_verification'] === 'verifie'): ?>

                <span class="statut statut-verifie">
                    Identité vérifiée
                </span>

                <p>
                    Félicitations ! Votre pièce d'identité a été
                    vérifiée avec succès.
                </p>

                <p class="date-document">
                    Document validé.
                </p>

            <?php elseif ($document['statut_verification'] === 'refuse'): ?>

                <span class="statut statut-refuse">
                    Document refusé
                </span>

                <p>
                    Votre pièce d'identité n'a pas été acceptée.
                </p>

                <div class="motif-refus">

                    <strong>Motif du refus :</strong>

                    <p>
                        <?= h($document['motif_refus'] ?: 'Motif non précisé.') ?>
                    </p>

                </div>

                <a class="bouton" href="../renvoyer-document.php">
                    Soumettre un nouveau document
                </a>

            <?php endif; ?>

        </article>

    </section>

</main>

</body>
</html>
