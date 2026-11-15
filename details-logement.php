<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/classes/Database.php';

/**
 * Échapper les valeurs avant leur affichage HTML.
 */
function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Récupérer l'identifiant du logement dans l'URL.
$idLogement = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$idLogement || $idLogement < 1) {
    http_response_code(404);
    exit('Logement introuvable.');
}

try {
    $pdo = (new Database())->getConnection();

    // Récupérer uniquement un logement publié et non supprimé.
    $sql = "
        SELECT
            l.id_logement,
            l.id_proprietaire,
            l.titre,
            l.description,
            l.adresse,
            l.ville,
            l.quartier,
            l.latitude,
            l.longitude,
            l.type_logement,
            l.disponibilite,
            l.date_creation
        FROM logement AS l
        WHERE l.id_logement = :id
          AND l.statut = 'publie'
          AND l.date_suppression IS NULL
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $idLogement]);

    $logement = $stmt->fetch();

    if (!$logement) {
        http_response_code(404);
        exit('Ce logement est introuvable ou n’est plus disponible.');
    }

    // Récupérer les tarifs proposés pour ce logement.
    $sqlTarifs = "
        SELECT duree_location, prix, devise
        FROM tarif_location
        WHERE id_logement = :id
        ORDER BY FIELD(
            duree_location,
            'nuit',
            'semaine',
            'mois',
            'annee'
        )
    ";

    $stmtTarifs = $pdo->prepare($sqlTarifs);
    $stmtTarifs->execute(['id' => $idLogement]);
    $tarifs = $stmtTarifs->fetchAll();

    // Récupérer les photos enregistrées pour ce logement.
    $sqlPhotos = "
        SELECT id_photo, est_principale, ordre_affichage
        FROM photo_logement
        WHERE id_logement = :id
        ORDER BY est_principale DESC, ordre_affichage ASC
    ";

    $stmtPhotos = $pdo->prepare($sqlPhotos);
    $stmtPhotos->execute(['id' => $idLogement]);
    $photos = $stmtPhotos->fetchAll();
    // Récupérer les équipements du logement.
    $sqlEquipements = "
        SELECT e.nom_equipement
        FROM logement_equipement AS le
        INNER JOIN equipement AS e
            ON e.id_equipement = le.id_equipement
        WHERE le.id_logement = :id
        ORDER BY e.nom_equipement ASC
    ";

    $stmtEquipements = $pdo->prepare($sqlEquipements);
    $stmtEquipements->execute(['id' => $idLogement]);
    $equipements = $stmtEquipements->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Une erreur est survenue lors du chargement du logement.');
}

$durees = [
    'nuit' => 'Par nuit',
    'semaine' => 'Par semaine',
    'mois' => 'Par mois',
    'annee' => 'Par année',
];

$typeLogement = [
    'chambre' => 'Chambre',
    'appartement' => 'Appartement',
][$logement['type_logement']] ?? $logement['type_logement'];

$estConnecte = isset($_SESSION['utilisateur_id']);
$estDisponible = ($logement['disponibilite'] ?? 'disponible') === 'disponible';

// Position du logement (si le propriétaire l'a enregistrée).
$aUnePosition = $logement['latitude'] !== null && $logement['longitude'] !== null;
$latitudeLogement = $aUnePosition ? (float) $logement['latitude'] : null;
$longitudeLogement = $aUnePosition ? (float) $logement['longitude'] : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= h($logement['titre']) ?> - MIGON</title>

    <?php if ($aUnePosition): ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <?php endif; ?>

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
            background: #ffffff;
            padding: 18px 6%;
            border-bottom: 1px solid #e5e7eb;
        }

        header a {
            color: #087f5b;
            text-decoration: none;
            font-size: 24px;
            font-weight: bold;
        }

        main {
            width: min(1000px, 92%);
            margin: 30px auto;
        }

        .carte {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, 0.05);
        }

        h1 {
            margin-top: 0;
            font-size: 28px;
        }

        .localisation,
        .type {
            color: #65717d;
            line-height: 1.7;
        }

        .photos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .photos img {
            width: 100%;
            height: 210px;
            object-fit: cover;
            border-radius: 8px;
            background: #e9ecef;
        }

        .tarif {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eeeeee;
        }

        .tarif:last-child {
            border-bottom: none;
        }

        .prix {
            color: #087f5b;
            font-weight: bold;
        }

        .bouton {
            display: inline-block;
            padding: 13px 20px;
            border-radius: 7px;
            background: #087f5b;
            color: white;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .bouton:hover {
            background: #066849;
        }

        .avertissement {
            color: #765b16;
            background: #fff8db;
            padding: 12px;
            border-radius: 7px;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            .carte {
                padding: 18px;
            }

            h1 {
                font-size: 23px;
            }
        }
.liste-equipements {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    padding: 0;
    margin: 0;
    list-style: none;
}

.liste-equipements li {
    background: #edf7f3;
    color: #087f5b;
    padding: 9px 14px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
}

        .carte-logement {
            height: 320px;
            margin: 12px 0;
            border: 1px solid #e1e7e3;
            border-radius: 12px;
            z-index: 0;
        }

        .alerte-indisponible {
            background: #fff8e6;
            color: #7a5200;
            border: 1px solid #f2dfb0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

<header>
    <a href="index.php">MIGON</a>
</header>

<main>

    <?php if (!$estDisponible): ?>
        <div class="alerte-indisponible">
            Ce logement est actuellement <strong>indisponible</strong>.
            Le propriétaire pourra le remettre en location prochainement.
        </div>
    <?php endif; ?>

    <section class="carte">
        <h1><?= h($logement['titre']) ?></h1>

        <p class="type">
            <?= h($typeLogement) ?>
        </p>

        <p class="localisation">
            <strong>Adresse :</strong>
            <?= h($logement['adresse']) ?><br>

            <strong>Ville :</strong>
            <?= h($logement['ville']) ?>

            <?php if (!empty($logement['quartier'])): ?>
                <br>
                <strong>Quartier :</strong>
                <?= h($logement['quartier']) ?>
            <?php endif; ?>
        </p>
    </section>

    <section class="carte">
        <h2>Photos du logement</h2>

        <?php if (!empty($photos)): ?>
            <div class="photos">
                <?php foreach ($photos as $photo): ?>
                    <img
                        src="proprietaire/afficher-photo.php?id=<?= (int) $logement['id_logement'] ?>&amp;photo=<?= (int) $photo['id_photo'] ?>"
                        alt="Photo du logement"
                        loading="lazy"
                    >
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>Aucune photo n'est disponible pour le moment.</p>
        <?php endif; ?>
    </section>

    <section class="carte">
        <h2>Description</h2>

        <p>
            <?= nl2br(h($logement['description'] ?? 'Aucune description disponible.')) ?>
        </p>
    </section>
<section class="carte">
    <h2>Équipements</h2>

    <?php if (!empty($equipements)): ?>

        <ul class="liste-equipements">
            <?php foreach ($equipements as $equipement): ?>
                <li>
                    <?= h($equipement['nom_equipement']) ?>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php else: ?>

        <p>
            Aucun équipement particulier n'est renseigné
            pour ce logement.
        </p>

    <?php endif; ?>
</section>
    <section class="carte">
        <h2>Tarifs de location</h2>

        <?php if (!empty($tarifs)): ?>
            <?php foreach ($tarifs as $tarif): ?>
                <div class="tarif">
                    <span>
                        <?= h($durees[$tarif['duree_location']] ?? $tarif['duree_location']) ?>
                    </span>

                    <span class="prix">
                        <?= number_format((float) $tarif['prix'], 0, ',', ' ') ?>
                        <?= h($tarif['devise']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Aucun tarif n'est renseigné pour ce logement.</p>
        <?php endif; ?>
    </section>

    <?php if ($aUnePosition): ?>
        <section class="carte">
            <h2>Localisation</h2>

            <p>
                <?= h($logement['adresse']) ?>
                <?= !empty($logement['quartier']) ? ' — ' . h($logement['quartier']) : '' ?>,
                <?= h($logement['ville']) ?>
            </p>

            <div id="carte-logement" class="carte-logement"></div>

            <a class="bouton"
               target="_blank"
               rel="noopener noreferrer"
               href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= h($latitudeLogement . ',' . $longitudeLogement) ?>">
                Itinéraire
            </a>
        </section>
    <?php endif; ?>

    <section class="carte">
        <h2>Intéressé par ce logement ?</h2>

        <?php if ($estConnecte): ?>

            <?php if ((int) $_SESSION['utilisateur_id'] === (int) $logement['id_proprietaire']): ?>
                <p>Tu es le propriétaire de ce logement.</p>
            <?php elseif (!$estDisponible): ?>
                <p>
                    Ce logement est indisponible pour le moment : il n'est pas
                    possible de contacter le propriétaire.
                </p>
            <?php else: ?>
                <p>
                    Tu peux contacter le propriétaire pour obtenir plus
                    d'informations sur ce logement.
                </p>

               <form action="contacter-proprietaire.php" method="post">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= h($_SESSION['csrf_token'] ?? '') ?>"
    >

    <input
        type="hidden"
        name="id_logement"
        value="<?= (int) $logement['id_logement'] ?>"
    >

    <button class="bouton" type="submit">
        Contacter le propriétaire
    </button>
</form>
            <?php endif; ?>

        <?php else: ?>
            <p>Connecte-toi pour pouvoir contacter le propriétaire.</p>

            <a class="bouton" href="connexion.php">
                Se connecter
            </a>
        <?php endif; ?>
    </section>

</main>

<?php if ($aUnePosition): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
    var conteneur = document.getElementById('carte-logement');

    if (!window.L) {
        conteneur.textContent = 'La carte n’a pas pu être chargée (connexion Internet requise).';
        conteneur.style.padding = '14px';
        return;
    }

    var position = [<?= json_encode($latitudeLogement) ?>, <?= json_encode($longitudeLogement) ?>];
    var carte = L.map(conteneur, { scrollWheelZoom: false }).setView(position, 16);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; contributeurs OpenStreetMap'
    }).addTo(carte);

    L.marker(position).addTo(carte);
})();
</script>
<?php endif; ?>

</body>
</html>
