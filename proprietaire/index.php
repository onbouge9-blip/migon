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

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notifications.php';

$pdo = (new Database())->getConnection();

$idUtilisateur = (int) $_SESSION['utilisateur_id'];

$nombreNotificationsNonLues = Notifications::compterNonLues(
    $pdo,
    $idUtilisateur
);

// Solde des publications gratuites, calculé à partir de l'historique.
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM publication p
     INNER JOIN logement l ON l.id_logement = p.id_logement
     WHERE l.id_proprietaire = ? AND p.type_quota = 'gratuit'"
);
$stmt->execute([$idUtilisateur]);
$publicationsGratuitesUtilisees = (int) $stmt->fetchColumn();
$publicationsGratuitesRestantes = max(0, 3 - $publicationsGratuitesUtilisees);

// Abonnement en cours, ou le plus récent si aucun n'est actif.
$stmt = $pdo->prepare(
    "SELECT a.*,
            (SELECT COUNT(*) FROM publication p
             WHERE p.id_abonnement = a.id_abonnement
               AND p.type_quota = 'abonnement') AS publications_utilisees
     FROM abonnement a
     WHERE a.id_proprietaire = ?
     ORDER BY
       CASE WHEN a.statut = 'actif'
                  AND a.date_debut <= NOW()
                  AND a.date_fin > NOW() THEN 0 ELSE 1 END,
       a.date_creation DESC,
       a.id_abonnement DESC
     LIMIT 1"
);
$stmt->execute([$idUtilisateur]);
$abonnement = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$abonnementValide = $abonnement
    && $abonnement['statut'] === 'actif'
    && !empty($abonnement['date_debut'])
    && !empty($abonnement['date_fin'])
    && strtotime($abonnement['date_debut']) <= time()
    && strtotime($abonnement['date_fin']) > time();

$quotaAbonnement = $abonnement ? (int) $abonnement['quota_publications'] : 0;
$publicationsPayantesUtilisees = $abonnement ? (int) $abonnement['publications_utilisees'] : 0;
$publicationsPayantesRestantes = $abonnementValide
    ? max(0, $quotaAbonnement - $publicationsPayantesUtilisees)
    : 0;

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
        .retour {
            display: inline-block;
            margin-bottom: 20px;
             background:white ;
            color: #123b32;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
        }
        .resume-quotas {
            background: #fff;
            border: 1px solid #e3e8e5;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 25px;
        }
        .resume-quotas h2 { color: #123b32; margin-top: 0; }
        .resume-grille {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
        }
        .quota-carte {
            background: #f5f8f6;
            border-radius: 10px;
            padding: 16px;
        }
        .quota-carte strong { display: block; color: #123b32; font-size: 25px; margin: 6px 0; }
        .etat-abonnement { font-weight: 700; }
        .etat-actif { color: #087443; }
        .etat-inactif { color: #9a5700; }
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

    <section class="resume-quotas">
        <h2>Mes droits de publication</h2>
        <div class="resume-grille">
            <article class="quota-carte">
                <span>Publications gratuites restantes</span>
                <strong><?= $publicationsGratuitesRestantes ?> / 3</strong>
                <small><?= $publicationsGratuitesUtilisees ?> publication(s) gratuite(s) déjà utilisée(s)</small>
            </article>
            <article class="quota-carte">
                <span>Abonnement</span>
                <?php if ($abonnementValide): ?>
                    <strong class="etat-abonnement etat-actif">Actif</strong>
                    <small>Expire le <?= h(date('d/m/Y à H:i', strtotime($abonnement['date_fin']))) ?></small>
                <?php elseif ($abonnement && $abonnement['statut'] === 'expire'): ?>
                    <strong class="etat-abonnement etat-inactif">Expiré</strong>
                    <small>Les logements déjà publiés restent visibles. Les nouvelles publications payantes sont bloquées.</small>
                <?php elseif ($abonnement): ?>
                    <strong class="etat-abonnement etat-inactif"><?= h(ucfirst(str_replace('_', ' ', $abonnement['statut']))) ?></strong>
                    <small>Aucun droit de publication payant actif pour le moment.</small>
                <?php else: ?>
                    <strong class="etat-abonnement etat-inactif">Aucun</strong>
                    <small>Aucun abonnement enregistré.</small>
                <?php endif; ?>
            </article>
            <article class="quota-carte">
                <span>Publications payantes restantes</span>
                <strong><?= $abonnementValide ? $publicationsPayantesRestantes . ' / ' . $quotaAbonnement : '0' ?></strong>
                <small>
                    <?php if ($abonnementValide): ?>
                        <?= $publicationsPayantesUtilisees ?> publication(s) utilisée(s) sur cet abonnement.
                    <?php elseif ($abonnement && $abonnement['statut'] === 'expire'): ?>
                        Les publications non utilisées de cet abonnement sont perdues.
                    <?php else: ?>
                        Un abonnement actif est nécessaire après les 3 publications gratuites.
                    <?php endif; ?>
                </small>
            </article>
        </div>
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

        
<article class="action">
    <h2>
        Mes notifications

        <?php if ($nombreNotificationsNonLues > 0): ?>
            <span class="badge-notifications">
                <?= $nombreNotificationsNonLues ?>
            </span>
        <?php endif; ?>
    </h2>

    <p>
        Consultez les nouveaux messages et les notifications de votre compte.
    </p>

    <a class="bouton" href="../notifications.php">
        Voir mes notifications
    </a>
</article>


    </section>
</main>

</body>
</html>
