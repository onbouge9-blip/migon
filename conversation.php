<?php
session_start();

require_once __DIR__ . '/classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];
$idConversation = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idConversation || $idConversation < 1) {
    http_response_code(404);
    exit('Conversation introuvable.');
}

try {
    $pdo = (new Database())->getConnection();

    // Vérifier que l'utilisateur connecté participe à cette conversation.
    $sql = "
        SELECT
            c.id_conversation,
            c.id_logement,
            c.statut_conversation,
            l.titre AS titre_logement
        FROM conversation AS c
        INNER JOIN participant_conversation AS pc
            ON pc.id_conversation = c.id_conversation
        INNER JOIN logement AS l
            ON l.id_logement = c.id_logement
        WHERE c.id_conversation = :id_conversation
          AND pc.id_utilisateur = :id_utilisateur
        LIMIT 1
    ";

    $requete = $pdo->prepare($sql);
    $requete->execute([
        'id_conversation' => $idConversation,
        'id_utilisateur' => $idUtilisateur,
    ]);

    $conversation = $requete->fetch();

    if (!$conversation) {
        // Ne pas révéler si une conversation d'un autre utilisateur existe.
        http_response_code(404);
        exit('Conversation introuvable.');
    }

    // Charger uniquement les messages de cette conversation.
    $sqlMessages = "
        SELECT
            m.id_message,
            m.id_expediteur,
            m.contenu,
            m.date_envoi,
            u.nom AS nom_expediteur
        FROM message AS m
        INNER JOIN utilisateur AS u
            ON u.id_utilisateur = m.id_expediteur
        WHERE m.id_conversation = :id_conversation
        ORDER BY m.date_envoi ASC, m.id_message ASC
    ";

    $requeteMessages = $pdo->prepare($sqlMessages);
    $requeteMessages->execute([
        'id_conversation' => $idConversation,
    ]);

    $messages = $requeteMessages->fetchAll();

} catch (Throwable $e) {
    error_log('Erreur conversation MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de charger cette conversation.');
}

$conversationOuverte =
    $conversation['statut_conversation'] === 'ouverte';

$erreur = $_SESSION['erreur_message'] ?? '';
unset($_SESSION['erreur_message']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Conversation - MIGON</title>

    <style>
        * { box-sizing: border-box; }

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
            width: min(850px, 94%);
            margin: 25px auto;
        }

        .carte {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 14px rgba(0,0,0,.05);
        }

        .retour {
            display: inline-block;
            margin-bottom: 18px;
            color: #087f5b;
            text-decoration: none;
        }

        .messages {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 20px 0;
        }

        .message {
            max-width: 82%;
            padding: 12px 15px;
            border-radius: 12px;
            background: #edf0f3;
            overflow-wrap: anywhere;
            line-height: 1.5;
        }

        .message.mien {
            align-self: flex-end;
            background: #d9f5e8;
        }

        .auteur {
            display: block;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .date {
            display: block;
            margin-top: 7px;
            font-size: 11px;
            color: #65717d;
        }

        textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #ccd2d8;
            border-radius: 8px;
            resize: vertical;
            font: inherit;
        }

        button {
            margin-top: 10px;
            padding: 12px 20px;
            background: #087f5b;
            color: white;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover { background: #066849; }

        .alerte {
            background: #fff3cd;
            color: #765b16;
            padding: 12px;
            border-radius: 7px;
        }

        .vide { color: #65717d; }

        @media (max-width: 600px) {
            .carte { padding: 15px; }
            .message { max-width: 95%; }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">MIGON</a>
</header>

<main>
    <a class="retour" href="messagerie.php">← Retour à ma messagerie</a>

    <section class="carte">
        <h1><?= h($conversation['titre_logement']) ?></h1>
        <p>Échange au sujet de ce logement.</p>

        <?php if ($erreur !== ''): ?>
            <p class="alerte"><?= h($erreur) ?></p>
        <?php endif; ?>

        <div class="messages">
            <?php if (empty($messages)): ?>
                <p class="vide">Aucun message. Tu peux commencer la discussion.</p>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <article class="message <?= (int) $message['id_expediteur'] === $idUtilisateur ? 'mien' : '' ?>">
                        <span class="auteur">
                            <?= (int) $message['id_expediteur'] === $idUtilisateur
                                ? 'Moi'
                                : h($message['nom_expediteur']) ?>
                        </span>

                        <?= nl2br(h($message['contenu'])) ?>

                        <span class="date">
                            <?= h($message['date_envoi']) ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($conversationOuverte): ?>
            <form action="envoyer-message.php" method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="id_conversation"
                    value="<?= (int) $conversation['id_conversation'] ?>"
                >

                <label for="contenu"><strong>Ton message</strong></label>

                <textarea
                    id="contenu"
                    name="contenu"
                    maxlength="10000"
                    required
                    placeholder="Écris ton message ici..."
                ></textarea>

                <button type="submit">Envoyer le message</button>
            </form>
        <?php else: ?>
            <p class="alerte">Cette conversation est fermée ou archivée. L'envoi de messages est désactivé.</p>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
