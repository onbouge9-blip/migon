<?php
session_start();

require_once __DIR__ . '/classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

// Destination du bouton « Retour » : l'espace de l'utilisateur, pas l'accueil.
$retourUrl = 'index.php';
$retourTexte = '← Retour à l’accueil';
$rolesUtilisateur = $_SESSION['utilisateur_roles'] ?? [];

if (is_array($rolesUtilisateur)) {
    if (in_array('chercheur', $rolesUtilisateur, true)) {
        $retourUrl = 'chercheur/index.php';
        $retourTexte = '← Retour à mon espace chercheur';
    } elseif (in_array('proprietaire', $rolesUtilisateur, true)) {
        $retourUrl = 'proprietaire/index.php';
        $retourTexte = '← Retour à mon espace propriétaire';
    } elseif (in_array('administrateur', $rolesUtilisateur, true)) {
        $retourUrl = 'admin/index.php';
        $retourTexte = '← Retour à l’administration';
    }
}

try {
    $pdo = (new Database())->getConnection();

    $sql = "
        SELECT
            c.id_conversation,
            c.id_logement,
            c.statut_conversation,
            c.date_modification,
            l.titre AS titre_logement,
            (
                SELECT m.contenu
                FROM message AS m
                WHERE m.id_conversation = c.id_conversation
                ORDER BY m.date_envoi DESC, m.id_message DESC
                LIMIT 1
            ) AS dernier_message,
            (
                SELECT m.date_envoi
                FROM message AS m
                WHERE m.id_conversation = c.id_conversation
                ORDER BY m.date_envoi DESC, m.id_message DESC
                LIMIT 1
            ) AS date_dernier_message
        FROM participant_conversation AS pc
        INNER JOIN conversation AS c
            ON c.id_conversation = pc.id_conversation
        INNER JOIN logement AS l
            ON l.id_logement = c.id_logement
        WHERE pc.id_utilisateur = :id_utilisateur
          AND c.statut_conversation = 'ouverte'
        ORDER BY c.date_modification DESC
    ";

    $requete = $pdo->prepare($sql);
    $requete->execute([
        'id_utilisateur' => $idUtilisateur,
    ]);

    $conversations = $requete->fetchAll();

} catch (Throwable $e) {
    error_log('Erreur messagerie MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de charger la messagerie pour le moment.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Ma messagerie - MIGON</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fa;
            color: #263238;
        }

        header {
            padding: 18px 6%;
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }

        header a {
            color: #087f5b;
            font-size: 24px;
            font-weight: bold;
            text-decoration: none;
        }

        main {
            width: min(850px, 92%);
            margin: 35px auto;
        }

        .carte {
            padding: 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, .05);
        }

        .conversation {
            display: block;
            padding: 18px 0;
            color: inherit;
            text-decoration: none;
            border-bottom: 1px solid #e9ecef;
        }

        .conversation:last-child {
            border-bottom: none;
        }

        .conversation:hover h2 {
            color: #087f5b;
        }

        .conversation h2 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .conversation p {
            margin: 6px 0;
            color: #65717d;
            overflow-wrap: anywhere;
        }

        .date {
            font-size: 13px;
        }

        .vide {
            padding: 25px 0;
            color: #65717d;
            line-height: 1.7;
        }

        .retour {
            display: inline-block;
            margin-bottom: 18px;
            color: #087f5b;
            text-decoration: none;
        }

        @media (max-width: 600px) {
            .carte {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">MIGON</a>
</header>

<main>
    <a class="retour" href="<?= h($retourUrl) ?>"><?= h($retourTexte) ?></a>

    <section class="carte">
        <h1>Ma messagerie</h1>

        <p>
            Bonjour <?= h($_SESSION['utilisateur_nom'] ?? 'Utilisateur') ?>.
            Retrouve ici tes discussions concernant les logements.
        </p>

        <?php if (empty($conversations)): ?>

            <div class="vide">
                <h2>Aucune conversation pour le moment</h2>
                <p>
                    Lorsque tu contacteras un propriétaire ou qu'un chercheur
                    te contactera au sujet d'un logement, la conversation
                    apparaîtra ici.
                </p>
                <a href="index.php">Découvrir les logements</a>
            </div>

        <?php else: ?>

            <?php foreach ($conversations as $conversation): ?>
                <a
                    class="conversation"
                    href="conversation.php?id=<?= (int) $conversation['id_conversation'] ?>"
                >
                    <h2><?= h($conversation['titre_logement']) ?></h2>

                    <p>
                        <?php if ($conversation['dernier_message'] !== null): ?>
                            <?= h($conversation['dernier_message']) ?>
                        <?php else: ?>
                            Aucun message pour le moment.
                        <?php endif; ?>
                    </p>

                    <p class="date">
                        <?php if (!empty($conversation['date_dernier_message'])): ?>
                            Dernier message :
                            <?= h($conversation['date_dernier_message']) ?>
                        <?php else: ?>
                            Conversation créée le
                            <?= h($conversation['date_modification']) ?>
                        <?php endif; ?>
                    </p>
                </a>
            <?php endforeach; ?>

        <?php endif; ?>
    </section>
</main>

</body>
</html>
