<?php
session_start();

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Notifications.php';

// Vérifier que l'utilisateur est connecté
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

// Créer un jeton CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fonction de protection des sorties HTML
function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

try {
    $pdo = (new Database())->getConnection();

    // Récupérer les notifications de l'utilisateur connecté
    $requete = $pdo->prepare("
        SELECT
            id_notification,
            id_conversation,
            titre,
            contenu,
            type_notification,
            statut_lecture,
            date_creation
        FROM notification
        WHERE id_utilisateur = :id_utilisateur
        ORDER BY date_creation DESC, id_notification DESC
    ");

    $requete->execute([
        'id_utilisateur' => $idUtilisateur
    ]);

    $notifications = $requete->fetchAll(PDO::FETCH_ASSOC);

    // Compter les notifications non lues
    $nombreNonLues = Notifications::compterNonLues($pdo, $idUtilisateur);

} catch (Throwable $e) {
    error_log('Erreur chargement notifications MIGON : ' . $e->getMessage());

    http_response_code(500);

    echo '<h2>Erreur de chargement des notifications</h2>';
    echo '<p>Impossible de charger vos notifications pour le moment. Réessayez plus tard.</p>';

    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mes notifications | MIGON</title>

   <link rel="stylesheet" href="/migon/public/css/style.css?v=3">
</head>

<body>

    <?php if (!empty($_SESSION['succes_renvoi_document'])): ?>
        <p class="message succes">
            <?= h($_SESSION['succes_renvoi_document']) ?>
        </p>
        <?php unset($_SESSION['succes_renvoi_document']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['succes_notifications'])): ?>
        <p class="message succes">
            <?= h($_SESSION['succes_notifications']) ?>
        </p>
        <?php unset($_SESSION['succes_notifications']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['erreur_notifications'])): ?>
        <p class="message erreur">
            <?= h($_SESSION['erreur_notifications']) ?>
        </p>
        <?php unset($_SESSION['erreur_notifications']); ?>
    <?php endif; ?>

    <main class="container">
<?php
$retourUrl = 'index.php';
$retourTexte = '← Retour à l’accueil';

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (is_array($roles)) {
    if (in_array('proprietaire', $roles, true)) {
        $retourUrl = 'proprietaire/index.php';
        $retourTexte = '← Retour à mon espace propriétaire';
    } elseif (in_array('chercheur', $roles, true)) {
        $retourUrl = 'chercheur/index.php';
        $retourTexte = '← Retour à mon espace chercheur';
    } elseif (in_array('administrateur', $roles, true)) {
        $retourUrl = 'admin/index.php';
        $retourTexte = '← Retour à l’administration';
    }
}
?>

<a href="<?= h($retourUrl) ?>" class="bouton-retour">
    <?= h($retourTexte) ?>
</a>
        <section class="notifications-section">

            <h1>Mes notifications</h1>

            <div class="notifications-barre">
                <p>
                    Vous avez
                    <strong><?= (int) $nombreNonLues ?></strong>
                    notification(s) non lue(s).
                </p>

                <?php if ($nombreNonLues > 0): ?>
                    <form action="marquer-notifications-lues.php" method="post">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($_SESSION['csrf_token']) ?>"
                        >

                        <button class="bouton-tout-lire" type="submit">
                            ✓ Tout marquer comme lu
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>

                <p class="message">
                    Vous n'avez aucune notification pour le moment.
                </p>

            <?php else: ?>

                <div class="notifications-list">

                    <?php foreach ($notifications as $notification): ?>

                        <article class="notification-card <?= (int) $notification['statut_lecture'] === 0 ? 'notification-non-lue' : '' ?>">

                            <div class="notification-header">

                                <h2>
                                    <?= h($notification['titre']) ?>
                                </h2>

                                <span class="notification-type">
                                    <?= h($notification['type_notification']) ?>
                                </span>

                            </div>

                            <p class="notification-date">
                                <?= h($notification['date_creation']) ?>
                            </p>

                            <p class="notification-status">
                                <?php if ((int) $notification['statut_lecture'] === 0): ?>
                                    <strong>Non lue</strong>
                                <?php else: ?>
                                    <span>Déjà lue</span>
                                <?php endif; ?>
                            </p>

                            <div class="notification-content">
                                <?= nl2br(h($notification['contenu'])) ?>
                            </div>

                            <!-- Action pour les notifications de messages -->
                            <?php if (
                                $notification['type_notification'] === 'message'
                                && !empty($notification['id_conversation'])
                            ): ?>

                                <form
                                    class="notification-action"
                                    action="ouvrir-notification.php"
                                    method="post"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_notification"
                                        value="<?= (int) $notification['id_notification'] ?>"
                                    >

                                    <button class="bouton" type="submit">
                                        Ouvrir la conversation
                                    </button>

                                </form>

                            <?php endif; ?>


                            <!-- Action pour les notifications de refus d'identité -->
                            <?php if (
                                $notification['type_notification'] === 'identite'
                                && strpos(
                                    strtolower($notification['titre']),
                                    'refusé'
                                ) !== false
                            ): ?>

                                <div class="notification-action">

                                    <a
                                        href="renvoyer-document.php"
                                        class="bouton"
                                    >
                                        Soumettre un nouveau document
                                    </a>

                                </div>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    </main>

</body>
</html>
