<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
}

$idLogement = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idLogement || $idLogement < 1) {
    http_response_code(400);
    exit('Identifiant du logement invalide.');
}

$pdo = (new Database())->getConnection();
$idProprietaire = (int) $_SESSION['utilisateur_id'];

// Vérifier que le logement appartient au propriétaire connecté.
$stmt = $pdo->prepare(
    'SELECT * FROM logement
     WHERE id_logement = :id
       AND id_proprietaire = :proprietaire
       AND date_suppression IS NULL
     LIMIT 1'
);

$stmt->execute([
    'id' => $idLogement,
    'proprietaire' => $idProprietaire
]);

$logement = $stmt->fetch();

if (!$logement) {
    http_response_code(404);
    exit('Logement introuvable ou accès non autorisé.');
}

// Récupérer les tarifs.
$stmt = $pdo->prepare(
    'SELECT duree_location, prix
     FROM tarif_location
     WHERE id_logement = ?'
);
$stmt->execute([$idLogement]);

$tarifs = [];

foreach ($stmt->fetchAll() as $tarif) {
    $tarifs[$tarif['duree_location']] = $tarif['prix'];
}

// Récupérer les équipements associés.
$stmt = $pdo->prepare(
    'SELECT id_equipement
     FROM logement_equipement
     WHERE id_logement = ?'
);
$stmt->execute([$idLogement]);

$equipementsActuels = array_map(
    'intval',
    array_column($stmt->fetchAll(), 'id_equipement')
);

// Liste des équipements disponibles.
$equipementsDisponibles = $pdo->query(
    'SELECT id_equipement, nom_equipement
     FROM equipement
     ORDER BY nom_equipement'
)->fetchAll();

// Récupérer les photos actuelles.
$stmt = $pdo->prepare(
    'SELECT id_photo, est_principale, ordre_affichage
     FROM photo_logement
     WHERE id_logement = ?
     ORDER BY est_principale DESC, ordre_affichage ASC'
);
$stmt->execute([$idLogement]);

$photos = $stmt->fetchAll();

$message = $_SESSION['erreur_modification'] ?? '';
unset($_SESSION['erreur_modification']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Modifier un logement | MIGON</title>

    <link rel="stylesheet" href="../public/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">

    <style>
        .formulaire-page {
            max-width: 850px;
            margin: 35px auto;
            padding: 24px;
        }

        .formulaire-page h1 {
            margin-bottom: 10px;
        }

        .formulaire-page .intro {
            margin-bottom: 25px;
            color: #666;
        }

        .champ {
            margin-bottom: 20px;
        }

        .champ label,
        .champ legend {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .champ input:not([type="checkbox"]),
        .champ textarea,
        .champ select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5d0;
            border-radius: 8px;
            box-sizing: border-box;
        }

        .champ textarea {
            min-height: 130px;
            resize: vertical;
        }

        .tarifs,
        .equipements {
            border: 1px solid #e0e6e2;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .equipements label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 8px 15px 8px 0;
        }

        .photos-info {
            background: #f1f7f3;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .bouton-enregistrer {
            background: #0f4d3f;
            color: white;
            border: 0;
            padding: 13px 20px;
            border-radius: 8px;
            cursor: pointer;
        }

        .retour {
            display: inline-block;
            margin-bottom: 20px;
        }

        .erreur {
            background: #ffecec;
            color: #9d2020;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .position-logement {
            margin-bottom: 20px;
            padding: 16px;
            border: 1px solid #e1e7e3;
            border-radius: 10px;
        }

        .position-logement h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #123b32;
        }

        .position-logement .aide {
            color: #59645f;
            font-size: 14px;
        }

        .position-logement .alerte-position {
            background: #fff8e6;
            color: #7a5200;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 14px;
        }

        .position-logement .bouton-position {
            background: #123b32;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 11px 16px;
            cursor: pointer;
            font: inherit;
        }

        .carte-position {
            height: 300px;
            margin-top: 10px;
            border: 1px solid #cbd5d0;
            border-radius: 10px;
            z-index: 0;
        }

    </style>
</head>

<body>
<main class="formulaire-page">

    <a class="retour" href="logements.php">
        ← Retour à mes logements
    </a>

    <h1>Modifier mon logement</h1>

    <p class="intro">
        Modifie les informations ci-dessous, puis enregistre tes changements.
    </p>

    <?php if ($message !== ''): ?>
        <div class="erreur"><?= h($message) ?></div>
    <?php endif; ?>

    <form action="traitement-modification-logement.php"
          method="POST">

        <input type="hidden" name="csrf_token"
               value="<?= h($_SESSION['csrf_token']) ?>">

        <input type="hidden" name="id_logement"
               value="<?= (int) $logement['id_logement'] ?>">

        <div class="champ">
            <label for="titre">Titre du logement *</label>
            <input type="text" id="titre" name="titre"
                   maxlength="180" required
                   value="<?= h($logement['titre']) ?>">
        </div>

        <div class="champ">
            <label for="description">Description *</label>
            <textarea id="description" name="description"
                      required><?= h($logement['description']) ?></textarea>
        </div>

        <div class="champ">
            <label for="type_logement">Type de logement *</label>
            <select id="type_logement" name="type_logement" required>
                <option value="chambre"
                    <?= $logement['type_logement'] === 'chambre' ? 'selected' : '' ?>>
                    Chambre
                </option>
                <option value="appartement"
                    <?= $logement['type_logement'] === 'appartement' ? 'selected' : '' ?>>
                    Appartement
                </option>
            </select>
        </div>

        <div class="champ">
            <label for="ville">Ville *</label>
            <input type="text" id="ville" name="ville"
                   maxlength="120" required
                   value="<?= h($logement['ville']) ?>">
        </div>

        <div class="champ">
            <label for="quartier">Quartier</label>
            <input type="text" id="quartier" name="quartier"
                   maxlength="120"
                   value="<?= h($logement['quartier'] ?? '') ?>">
        </div>

        <div class="champ">
            <label for="adresse">Adresse *</label>
            <input type="text" id="adresse" name="adresse"
                   maxlength="255" required
                   value="<?= h($logement['adresse']) ?>">
        </div>

        <section class="position-logement">
            <h2>Position géographique</h2>

            <?php
            $aUnePosition = $logement['latitude'] !== null
                && $logement['longitude'] !== null;
            ?>

            <?php if (!$aUnePosition): ?>
                <p class="alerte-position">
                    La position de ce logement n'est pas encore renseignée :
                    il n'apparaît pas dans la recherche « autour de moi ».
                </p>
            <?php endif; ?>

            <p class="aide">
                Utilisez votre position actuelle (placez-vous au niveau du
                logement) ou cliquez sur la carte pour placer le repère, puis
                déplacez-le si besoin.
            </p>

            <button type="button" class="bouton-position" id="btn-position">
                📍 Utiliser ma position actuelle
            </button>

            <p id="message-position" class="aide" aria-live="polite"></p>

            <div id="carte-position" class="carte-position"></div>

            <input type="hidden" name="latitude" id="latitude"
                   value="<?= $aUnePosition ? h((string) $logement['latitude']) : '' ?>">
            <input type="hidden" name="longitude" id="longitude"
                   value="<?= $aUnePosition ? h((string) $logement['longitude']) : '' ?>">
        </section>

        <fieldset class="tarifs">
            <legend>Tarifs de location (FCFA)</legend>

            <?php
            $durees = [
                'nuit' => 'Par nuit',
                'semaine' => 'Par semaine',
                'mois' => 'Par mois',
                'annee' => 'Par année'
            ];
            ?>

            <?php foreach ($durees as $cle => $libelle): ?>
                <div class="champ">
                    <label for="prix_<?= h($cle) ?>">
                        <?= h($libelle) ?>
                    </label>

                    <input type="number"
                           id="prix_<?= h($cle) ?>"
                           name="prix_<?= h($cle) ?>"
                           min="0" step="1"
                           value="<?= isset($tarifs[$cle]) ? h((string) $tarifs[$cle]) : '' ?>">
                </div>
            <?php endforeach; ?>
        </fieldset>

        <fieldset class="equipements">
            <legend>Équipements disponibles</legend>

            <?php foreach ($equipementsDisponibles as $equipement): ?>
                <label>
                    <input type="checkbox"
                           name="equipements[]"
                           value="<?= (int) $equipement['id_equipement'] ?>"
                        <?= in_array(
                            (int) $equipement['id_equipement'],
                            $equipementsActuels,
                            true
                        ) ? 'checked' : '' ?>>

                    <?= h($equipement['nom_equipement']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="photos-info">
            <strong>Photos actuelles : <?= count($photos) ?></strong>
            <p>
                Les photos existantes seront conservées.
                La gestion du remplacement et de l'ajout de photos
                sera traitée séparément.
            </p>
        </div>

        <button class="bouton-enregistrer" type="submit">
            Enregistrer les modifications
        </button>

    </form>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="../public/js/position-logement.js"></script>
<script>
MigonPosition.init({
    boutonId: 'btn-position',
    messageId: 'message-position',
    latId: 'latitude',
    lngId: 'longitude',
    carteId: 'carte-position',
    obligatoire: false
});
</script>
</body>
</html>
