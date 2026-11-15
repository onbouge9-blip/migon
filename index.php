<?php

session_start();

require_once __DIR__ . '/classes/Database.php';

$connecte = isset($_SESSION['utilisateur_id']);
$nomUtilisateur = $_SESSION['utilisateur_nom'] ?? '';
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles)) {
    $roles = [];
}

function h(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}

$logements = [];
$erreurChargement = false;

try {
    $pdo = (new Database())->getConnection();

    $sql = "
        SELECT
            l.id_logement,
            l.titre,
            l.description,
            l.ville,
            l.quartier,
            l.type_logement,

            (
                SELECT MIN(t.prix)
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
            ) AS prix_min,

            (
                SELECT t.devise
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
                ORDER BY t.prix ASC
                LIMIT 1
            ) AS devise,

            (
                SELECT t.duree_location
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
                ORDER BY t.prix ASC
                LIMIT 1
            ) AS duree_location,

          (
    SELECT p.id_photo
    FROM photo_logement p
    WHERE p.id_logement = l.id_logement
    ORDER BY p.est_principale DESC,
             p.ordre_affichage ASC,
             p.id_photo ASC
    LIMIT 1
) AS id_photo

        FROM logement l

        WHERE l.statut = 'publie'
          AND l.disponibilite = 'disponible'
          AND l.date_suppression IS NULL

        ORDER BY l.date_creation DESC
        LIMIT 12
    ";

    $stmt = $pdo->query($sql);
    $logements = $stmt->fetchAll();

} catch (Throwable $e) {
    error_log('Erreur chargement logements MIGON : ' . $e->getMessage());
    $erreurChargement = true;
}

$nombreNotificationsNonLues = 0;

if (isset($_SESSION['utilisateur_id'])) {
    
    require_once __DIR__ . '/classes/Notifications.php';

    $pdo = (new Database())->getConnection();

    $nombreNotificationsNonLues = Notifications::compterNonLues(
        $pdo,
        (int) $_SESSION['utilisateur_id']
    );
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>MIGON — Trouvez votre prochain logement</title>

    <link rel="stylesheet" href="public/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #18302b;
            background: #f7f9f7;
        }

        a {
            text-decoration: none;
        }

        .migon-header {
            background: #fff;
            border-bottom: 1px solid #e7ece8;
        }

        .migon-nav {
            max-width: 1160px;
            margin: auto;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .migon-logo {
            color: #087f5b;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .migon-links {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
    
.migon-logements {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
}

.migon-logement {
    overflow: hidden;
    background: #fff;
    border: 1px solid #e8eee9;
    border-radius: 16px;
    box-shadow: 0 6px 20px rgba(24, 48, 43, 0.05);
}

.migon-logement-photo,
.migon-logement-sans-photo {
    display: block;
    width: 100%;
    height: 220px;
}

.migon-logement-photo {
    object-fit: cover;
}

.migon-logement-sans-photo {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #66766e;
    background: #e7f5ee;
}

.migon-logement-contenu {
    padding: 20px;
}

.migon-logement-type {
    color: #087f5b;
    font-size: 13px;
    font-weight: 700;
}

.migon-logement h3 {
    margin: 12px 0 8px;
    font-size: 19px;
}

.migon-logement-lieu {
    color: #66766e;
    font-size: 14px;
}

.migon-logement-prix {
    margin: 16px 0;
    color: #344b44;
}

.migon-logement-prix strong {
    color: #087f5b;
    font-size: 18px;
}

.migon-logement-description {
    color: #66766e;
    font-size: 14px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

@media (max-width: 900px) {
    .migon-logements {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 560px) {
    .migon-logements {
        grid-template-columns: 1fr;
    }
}

        .migon-links a {
            color: #344b44;
            font-size: 14px;
            font-weight: 600;
        }

        .migon-links a:hover {
            color: #087f5b;
        }

        .migon-button {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            border: 0;
            cursor: pointer;
        }

        .migon-button-primary {
            color: #fff !important;
            background: #087f5b;
        }

        .migon-button-primary:hover {
            background: #066b4c;
        }

        .migon-button-light {
            color: #087f5b !important;
            background: #e7f5ee;
        }

        .migon-hero {
            background: linear-gradient(135deg, #e9f7ef, #f6faf5);
            padding: 76px 24px 70px;
        }

        .migon-hero-content {
            max-width: 1160px;
            margin: auto;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            align-items: center;
            gap: 48px;
        }

        .migon-eyebrow {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 30px;
            color: #087f5b;
            background: #d5efe1;
            font-weight: 700;
            font-size: 13px;
        }

        .migon-hero h1 {
            max-width: 680px;
            margin: 20px 0;
            font-size: clamp(34px, 5vw, 55px);
            line-height: 1.12;
            letter-spacing: -1.5px;
        }

        .migon-hero h1 span {
            color: #087f5b;
        }

        .migon-hero p {
            max-width: 590px;
            color: #53665d;
            font-size: 17px;
            line-height: 1.8;
        }

        .migon-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .migon-visual {
            min-height: 300px;
            padding: 30px;
            border-radius: 24px;
            background: #087f5b;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 20px 45px rgba(8, 127, 91, 0.15);
        }

        .migon-visual-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }

        .migon-visual h2 {
            margin: 0 0 12px;
            font-size: 26px;
        }

        .migon-visual p {
            color: #e0f4e9;
            font-size: 15px;
        }

        .migon-section {
            max-width: 1160px;
            margin: auto;
            padding: 65px 24px;
        }

        .migon-section-heading {
            max-width: 680px;
            margin: 0 auto 35px;
            text-align: center;
        }

        .migon-section-heading h2 {
            margin: 0 0 12px;
            font-size: 32px;
        }

        .migon-section-heading p {
            color: #66766e;
            line-height: 1.7;
        }

        .migon-features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .migon-feature {
            padding: 27px;
            background: #fff;
            border: 1px solid #e8eee9;
            border-radius: 16px;
        }

        .migon-feature-icon {
            font-size: 32px;
        }

        .migon-feature h3 {
            margin: 18px 0 10px;
        }

        .migon-feature p {
            margin-bottom: 0;
            color: #66766e;
            font-size: 14px;
            line-height: 1.8;
        }

        .migon-cta {
            margin: 0 24px 65px;
            padding: 42px 24px;
            text-align: center;
            border-radius: 20px;
            background: #e7f5ee;
        }

        .migon-cta h2 {
            margin-top: 0;
            font-size: 30px;
        }

        .migon-cta p {
            color: #53665d;
            line-height: 1.7;
        }

        .migon-footer {
            padding: 25px 24px;
            text-align: center;
            color: #6a7972;
            background: #fff;
            border-top: 1px solid #e7ece8;
            font-size: 13px;
        }

        .migon-user {
            color: #087f5b;
            font-weight: 700;
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
        @media (max-width: 760px) {
            .migon-nav {
                align-items: flex-start;
                flex-direction: column;
            }

            .migon-links {
                gap: 14px;
            }

            .migon-hero {
                padding-top: 48px;
            }

            .migon-hero-content {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .migon-visual {
                min-height: 230px;
            }

            .migon-features {
                grid-template-columns: 1fr;
            }

            .migon-section-heading h2,
            .migon-cta h2 {
                font-size: 26px;
            }
        }
    </style>
</head>

<body>

<header class="migon-header">
    <nav class="migon-nav">
        <a class="migon-logo" href="index.php">MIGON</a>

        <div class="migon-links">
            <a href="#accueil">Accueil</a>
            <a href="#avantages">Nos avantages</a>

            <?php if ($connecte): ?>
<?php if (in_array('proprietaire', $roles, true)): ?>
    <a href="proprietaire/index.php">
        Mon espace propriétaire
    </a>
<?php endif; ?>

<?php if (in_array('chercheur', $roles, true)): ?>
    <a href="chercheur/index.php">
        Mon espace chercheur
    </a>
<?php endif; ?>

<?php if (in_array('administrateur', $roles, true)): ?>
    <a href="admin/index.php">
        Administration
    </a>
<?php endif; ?>

<a href="notifications.php">
    Mes notifications
    <?php if ($nombreNotificationsNonLues > 0): ?>
        <span class="badge-notifications">
            <?= $nombreNotificationsNonLues ?>
        </span>
    <?php endif; ?>
</a>

                <a class="migon-button migon-button-light"
                   href="deconnexion.php">Se déconnecter</a>
            <?php else: ?>
                <a href="connexion.php">Se connecter</a>
                <a class="migon-button migon-button-primary"
                   href="inscription.php">Créer un compte</a>
            <?php endif; ?>
        </div>

    </nav>
</header>

<main>
    <section class="migon-hero" id="accueil">
        <div class="migon-hero-content">
            <div>
                <span class="migon-eyebrow">
                    Votre logement, plus simplement
                </span>

                <h1>
                    Trouvez un logement qui vous <span>ressemble.</span>
                </h1>

                <p>
                    MIGON vous aide à trouver un logement ou à proposer
                    le vôtre. Découvrez une solution simple pour mettre
                    en relation chercheurs de logements et propriétaires,
                    notamment à Cotonou et à Godomey.
                </p>

                <div class="migon-actions">
                    <?php if ($connecte): ?>
                        <a class="migon-button migon-button-primary"
                           href="#avantages">
                            Découvrir MIGON
                        </a>
                    <?php else: ?>
                        <a class="migon-button migon-button-primary"
                           href="inscription.php">
                            Commencer maintenant
                        </a>

                        <a class="migon-button migon-button-light"
                           href="connexion.php">
                            J'ai déjà un compte
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="migon-visual">
                <div class="migon-visual-icon" aria-hidden="true">⌂</div>
                <h2>Un logement, de nouvelles possibilités.</h2>
                <p>
                    Que vous cherchiez un endroit où vivre ou que vous
                    souhaitiez proposer un logement, MIGON vous accompagne.
                </p>
            </div>
        </div>
    </section>

    <section class="migon-section" id="avantages">
        <div class="migon-section-heading">
            <h2>Comment MIGON vous aide</h2>
            <p>
                Une plateforme pensée pour faciliter la recherche
                de logements et la mise en relation avec les propriétaires.
            </p>
        </div>

        <div class="migon-features">
            <article class="migon-feature">
                <div class="migon-feature-icon" aria-hidden="true">⌕</div>
                <h3>Pour les chercheurs</h3>
                <p>
                    Préparez votre recherche et découvrez les logements
                    correspondant à vos besoins et à votre budget.
                </p>
            </article>

            <article class="migon-feature">
                <div class="migon-feature-icon" aria-hidden="true">⌂</div>
                <h3>Pour les propriétaires</h3>
                <p>
                    Préparez vos annonces, présentez vos logements
                    et facilitez les échanges avec les personnes intéressées.
                </p>
            </article>

            <article class="migon-feature">
                <div class="migon-feature-icon" aria-hidden="true">✓</div>
                <h3>Des comptes vérifiables</h3>
                <p>
                    Les pièces d'identité soumises peuvent être examinées
                    par l'administration afin de renforcer la confiance
                    sur la plateforme.
                </p>
            </article>
        </div>
    </section>
   
<section class="migon-section" id="logements">
    <div class="migon-section-heading">
        <h2>Les logements disponibles</h2>
        <p>
            Découvrez les logements publiés sur MIGON
            et trouvez celui qui correspond à vos besoins.
        </p>
    </div>

    <?php if ($erreurChargement): ?>

        <p>
            Impossible de charger les logements pour le moment.
            Réessaie plus tard.
        </p>

    <?php elseif (empty($logements)): ?>

        <p style="text-align:center;">
            Aucun logement n'est disponible pour le moment.
            Revenez bientôt découvrir les nouvelles annonces.
        </p>

    <?php else: ?>

        <div class="migon-logements">

            <?php foreach ($logements as $logement): ?>

                <article class="migon-logement">

                   <?php if (!empty($logement['id_photo'])): ?>
    <img
        class="migon-logement-photo"
        src="proprietaire/afficher-photo.php?id=<?= (int) $logement['id_logement'] ?>&amp;photo=<?= (int) $logement['id_photo'] ?>"
        alt="<?= h($logement['titre']) ?>"
        loading="lazy"
    >
<?php else: ?>
                        <div class="migon-logement-sans-photo">
                            Photo non disponible
                        </div>
                    <?php endif; ?>

                    <div class="migon-logement-contenu">

                        <span class="migon-logement-type">
                            <?= h(ucfirst($logement['type_logement'])) ?>
                        </span>

                        <h3><?= h($logement['titre']) ?></h3>

                        <p class="migon-logement-lieu">
                            <?= h($logement['quartier'] ?? '') ?>
                            <?= !empty($logement['quartier']) ? ' — ' : '' ?>
                            <?= h($logement['ville']) ?>
                        </p>

                        <?php if ($logement['prix_min'] !== null): ?>
                            <p class="migon-logement-prix">
                                À partir de
                                <strong>
                                    <?= number_format(
                                        (float) $logement['prix_min'],
                                        0,
                                        ',',
                                        ' '
                                    ) ?>
                                    <?= h($logement['devise'] ?? 'XOF') ?>
                                </strong>
                                <?php if (!empty($logement['duree_location'])): ?>
                                    / <?= h($logement['duree_location']) ?>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p>Prix à consulter</p>
                        <?php endif; ?>

                        <p class="migon-logement-description">
                            <?= h(mb_strimwidth(
                                (string) ($logement['description'] ?? ''),
                                0,
                                110,
                                '…',
                                'UTF-8'
                            )) ?>
                        </p>

                        <a
                            class="migon-button migon-button-primary"
                            href="details-logement.php?id=<?= (int) $logement['id_logement'] ?>"
                        >
                            Voir les détails
                        </a>

                    </div>
                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>
</section>

    <section class="migon-cta">
        <h2>Prêt à utiliser MIGON ?</h2>
        <p>
            Créez votre compte pour préparer votre recherche
            ou commencer à proposer vos logements.
        </p>

        <?php if ($connecte): ?>
            <p>
                Vous êtes connecté à MIGON.
                Vous pouvez poursuivre votre navigation.
            </p>
        <?php else: ?>
            <a class="migon-button migon-button-primary"
               href="inscription.php">
                Créer mon compte gratuitement
            </a>
        <?php endif; ?>
    </section>
</main>

<footer class="migon-footer">
    <p>
        &copy; <?= date('Y') ?> MIGON.
        Votre recherche de logement commence ici.
    </p>
</footer>

</body>
</html>
