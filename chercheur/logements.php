<?php
session_start();

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];
if (!is_array($roles) || !in_array('chercheur', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notifications.php';
require_once __DIR__ . '/../classes/Geolocalisation.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$ville = trim((string) ($_GET['ville'] ?? ''));
$quartier = trim((string) ($_GET['quartier'] ?? ''));
$duree = trim((string) ($_GET['duree'] ?? ''));
$budgetMax = trim((string) ($_GET['budget_max'] ?? ''));

$dureesAutorisees = ['nuit', 'semaine', 'mois', 'annee'];
if (!in_array($duree, $dureesAutorisees, true)) {
    $duree = '';
}
if ($budgetMax !== '' && (!is_numeric($budgetMax) || (float) $budgetMax < 0)) {
    $budgetMax = '';
}

// Recherche « autour de moi » (facultative, compatible avec tous les filtres).
$position = Geolocalisation::valider($_GET['lat'] ?? '', $_GET['lng'] ?? '');
$rayon = Geolocalisation::rayonValide($_GET['rayon'] ?? '');
$autourDeMoi = $position !== null;

try {
    $pdo = (new Database())->getConnection();
    $idUtilisateur = (int) $_SESSION['utilisateur_id'];
    $nombreNotificationsNonLues = Notifications::compterNonLues($pdo, $idUtilisateur);

    // Valeurs disponibles pour alimenter les filtres.
    $villes = $pdo->query("
        SELECT DISTINCT ville
        FROM logement
        WHERE statut = 'publie'
          AND disponibilite = 'disponible'
          AND date_suppression IS NULL
          AND ville IS NOT NULL
          AND ville <> ''
        ORDER BY ville
    ")->fetchAll(PDO::FETCH_COLUMN);

    $quartiers = $pdo->query("
        SELECT DISTINCT quartier
        FROM logement
        WHERE statut = 'publie'
          AND disponibilite = 'disponible'
          AND date_suppression IS NULL
          AND quartier IS NOT NULL
          AND quartier <> ''
        ORDER BY quartier
    ")->fetchAll(PDO::FETCH_COLUMN);

    $conditions = [
        "l.statut = 'publie'",
        "l.disponibilite = 'disponible'",
        "l.date_suppression IS NULL"
    ];
    $params = [];

    if ($ville !== '') {
        $conditions[] = "l.ville = :ville";
        $params['ville'] = $ville;
    }

    if ($quartier !== '') {
        $conditions[] = "l.quartier = :quartier";
        $params['quartier'] = $quartier;
    }

    if ($duree !== '') {
        $conditions[] = "EXISTS (
            SELECT 1 FROM tarif_location tf
            WHERE tf.id_logement = l.id_logement
              AND tf.duree_location = :duree
        )";
        $params['duree'] = $duree;
    }

    if ($budgetMax !== '') {
        $conditions[] = "EXISTS (
            SELECT 1 FROM tarif_location tb
            WHERE tb.id_logement = l.id_logement
              AND tb.prix <= :budget_max
              " . ($duree !== '' ? "AND tb.duree_location = :duree_budget" : "") . "
        )";
        $params['budget_max'] = (float) $budgetMax;
        if ($duree !== '') {
            $params['duree_budget'] = $duree;
        }
    }

    // Colonnes, filtre et tri liés à la position (si elle est fournie).
    $colonnesGeo = 'l.latitude, l.longitude, NULL AS distance_km,';
    $having = '';
    $ordre = 'l.date_creation DESC';

    if ($autourDeMoi) {
        $conditions[] = Geolocalisation::conditionBoite('l');
        $colonnesGeo = 'l.latitude, l.longitude, '
            . Geolocalisation::expressionDistance('l') . ' AS distance_km,';
        $having = 'HAVING distance_km <= :geo_rayon';
        $ordre = 'distance_km ASC, l.date_creation DESC';
        $params += Geolocalisation::parametres(
            $position[0],
            $position[1],
            (float) $rayon
        );
    }

    $sql = "
        SELECT
            l.id_logement,
            l.titre,
            l.description,
            l.ville,
            l.quartier,
            l.type_logement,
            " . $colonnesGeo . "
            (
                SELECT t.prix
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
                " . ($duree !== '' ? "AND t.duree_location = :duree_prix" : "") . "
                ORDER BY t.prix ASC
                LIMIT 1
            ) AS prix_affiche,
            (
                SELECT t.devise
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
                " . ($duree !== '' ? "AND t.duree_location = :duree_devise" : "") . "
                ORDER BY t.prix ASC
                LIMIT 1
            ) AS devise,
            (
                SELECT t.duree_location
                FROM tarif_location t
                WHERE t.id_logement = l.id_logement
                " . ($duree !== '' ? "AND t.duree_location = :duree_label" : "") . "
                ORDER BY t.prix ASC
                LIMIT 1
            ) AS duree_affichee,
            (
                SELECT p.id_photo
                FROM photo_logement p
                WHERE p.id_logement = l.id_logement
                ORDER BY p.est_principale DESC, p.ordre_affichage ASC, p.id_photo ASC
                LIMIT 1
            ) AS id_photo
        FROM logement l
        WHERE " . implode(" AND ", $conditions) . "
        " . $having . "
        ORDER BY " . $ordre . "
    ";

    // Les paramètres des sous-requêtes sont distincts pour PDO natif.
    if ($duree !== '') {
        $params['duree_prix'] = $duree;
        $params['duree_devise'] = $duree;
        $params['duree_label'] = $duree;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Erreur recherche logements MIGON : ' . $e->getMessage());
    http_response_code(500);
    exit('Impossible de charger les logements pour le moment.');
}

$libellesDuree = [
    'nuit' => 'Par nuit',
    'semaine' => 'Par semaine',
    'mois' => 'Par mois',
    'annee' => 'Par année'
];

// Données transmises à la carte (uniquement les logements géolocalisés).
$pointsCarte = [];

foreach ($logements as $l) {
    if ($l['latitude'] === null || $l['longitude'] === null) {
        continue;
    }

    $prixTexte = $l['prix_affiche'] !== null
        ? number_format((float) $l['prix_affiche'], 0, ',', ' ') . ' '
            . ($l['devise'] ?: 'FCFA')
            . (!empty($l['duree_affichee'])
                ? ' ' . mb_strtolower($libellesDuree[$l['duree_affichee']] ?? $l['duree_affichee'])
                : '')
        : 'Tarif non renseigné';

    $pointsCarte[] = [
        'id' => (int) $l['id_logement'],
        'titre' => (string) $l['titre'],
        'lieu' => trim(($l['quartier'] ?: 'Quartier non précisé') . ', ' . $l['ville']),
        'prix' => $prixTexte,
        'distance' => $l['distance_km'] !== null
            ? Geolocalisation::formaterDistance((float) $l['distance_km'])
            : null,
        'lat' => (float) $l['latitude'],
        'lng' => (float) $l['longitude'],
        'url' => '../details-logement.php?id=' . (int) $l['id_logement'],
    ];
}

$donneesCarte = [
    'points' => $pointsCarte,
    'utilisateur' => $autourDeMoi
        ? ['lat' => $position[0], 'lng' => $position[1], 'rayon' => $rayon]
        : null,
];

// Lien pour retirer la position tout en gardant les autres filtres.
$parametresSansPosition = array_filter(
    [
        'ville' => $ville,
        'quartier' => $quartier,
        'duree' => $duree,
        'budget_max' => $budgetMax,
    ],
    static fn($valeur): bool => $valeur !== ''
);
$lienSansPosition = 'logements.php'
    . ($parametresSansPosition ? '?' . http_build_query($parametresSansPosition) : '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rechercher un logement | MIGON</title>
<link rel="stylesheet" href="../public/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:#f5f7f8;color:#263238;font-family:Arial,sans-serif}
.page{width:min(1180px,94%);margin:30px auto}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:22px}
.topbar a{color:#087f5b;text-decoration:none;font-weight:bold}
.intro{background:#123b32;color:#fff;padding:28px;border-radius:16px;margin-bottom:22px}
.intro h1{margin:0 0 8px}
.filtres{background:#fff;padding:22px;border-radius:14px;border:1px solid #e3e8e5;margin-bottom:25px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
.champ label{display:block;font-weight:bold;font-size:14px;margin-bottom:7px}
.champ input,.champ select{width:100%;padding:12px;border:1px solid #ccd5d0;border-radius:8px;background:#fff}
.actions{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}
.btn{display:inline-block;padding:11px 17px;border:0;border-radius:8px;background:#087f5b;color:#fff;text-decoration:none;cursor:pointer;font-weight:bold}
.btn-second{background:#e8efeb;color:#174b3e}
.resultats{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:20px 0}
.grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}
.carte{background:#fff;border:1px solid #e3e8e5;border-radius:14px;overflow:hidden}
.photo{height:205px;background:#e9efeb;display:flex;align-items:center;justify-content:center;color:#667}
.photo img{width:100%;height:100%;object-fit:cover}
.contenu{padding:18px}
.contenu h2{font-size:19px;margin:0 0 9px;color:#123b32}
.localisation{color:#68756f;font-size:14px}
.prix{font-size:18px;font-weight:bold;color:#087f5b;margin:12px 0}
.description{line-height:1.5;color:#53615b}
.vide{background:#fff;padding:30px;border-radius:12px;text-align:center}
.geo{margin-top:16px;padding-top:16px;border-top:1px dashed #d5ddd8}
.geo-ligne{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.geo-ligne select{width:auto;min-width:120px;padding:11px}
.geo-info{margin:10px 0 0;color:#59645f;font-size:14px}
.geo-info.erreur{color:#9b1c1c}
.puce-geo{display:inline-flex;align-items:center;gap:8px;background:#e7f5ee;color:#174b3e;padding:7px 12px;border-radius:999px;font-weight:bold;font-size:14px}
.puce-geo a{color:#9b1c1c;font-weight:normal;text-decoration:underline}
.distance{display:inline-block;background:#e7f5ee;color:#174b3e;border-radius:999px;padding:3px 10px;font-size:13px;font-weight:bold;margin-left:6px}
.vues{display:flex;gap:8px}
.vues button{border:1px solid #ccd5d0;background:#fff;color:#174b3e;border-radius:8px;padding:8px 14px;cursor:pointer;font-weight:bold}
.vues button.actif{background:#123b32;border-color:#123b32;color:#fff}
#carte-resultats{height:460px;border-radius:14px;border:1px solid #e3e8e5;margin-bottom:25px;z-index:0}
@media(max-width:600px){.intro{padding:22px}.filtres{padding:16px}#carte-resultats{height:360px}}
</style>
</head>
<body>
<main class="page">
    <div class="topbar">
        <a href="index.php">← Mon espace chercheur</a>
        <a href="../notifications.php">Notifications<?= $nombreNotificationsNonLues > 0 ? ' (' . (int) $nombreNotificationsNonLues . ')' : '' ?></a>
    </div>

    <section class="intro">
        <h1>Trouvez votre prochain logement</h1>
        <p>Explorez les logements publiés sur MIGON et utilisez les filtres pour affiner votre recherche.</p>
    </section>

    <section class="filtres">
        <form method="get" action="logements.php">
            <div class="form-grid">
                <div class="champ">
                    <label for="ville">Ville</label>
                    <select name="ville" id="ville">
                        <option value="">Toutes les villes</option>
                        <?php foreach ($villes as $v): ?>
                            <option value="<?= h($v) ?>" <?= $ville === $v ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="champ">
                    <label for="quartier">Quartier</label>
                    <select name="quartier" id="quartier">
                        <option value="">Tous les quartiers</option>
                        <?php foreach ($quartiers as $q): ?>
                            <option value="<?= h($q) ?>" <?= $quartier === $q ? 'selected' : '' ?>><?= h($q) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="champ">
                    <label for="duree">Durée de location</label>
                    <select name="duree" id="duree">
                        <option value="">Toutes les durées</option>
                        <?php foreach ($libellesDuree as $cle => $libelle): ?>
                            <option value="<?= h($cle) ?>" <?= $duree === $cle ? 'selected' : '' ?>><?= h($libelle) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="champ">
                    <label for="budget_max">Budget maximum (FCFA)</label>
                    <input type="number" min="0" step="1" name="budget_max" id="budget_max"
                           value="<?= h($budgetMax) ?>" placeholder="Ex. 50000">
                </div>
            </div>
            <div class="geo">
                <input type="hidden" name="lat" id="lat" value="<?= $autourDeMoi ? h($position[0]) : '' ?>">
                <input type="hidden" name="lng" id="lng" value="<?= $autourDeMoi ? h($position[1]) : '' ?>">

                <div class="geo-ligne">
                    <button class="btn btn-second" type="button" id="btn-autour">
                        📍 Autour de moi
                    </button>

                    <label for="rayon" class="geo-rayon">Rayon :</label>
                    <select name="rayon" id="rayon">
                        <?php foreach (Geolocalisation::RAYONS_KM as $km): ?>
                            <option value="<?= (int) $km ?>" <?= $rayon === $km ? 'selected' : '' ?>>
                                <?= (int) $km ?> km
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($autourDeMoi): ?>
                        <span class="puce-geo">
                            Position utilisée
                            <a href="<?= h($lienSansPosition) ?>">Retirer</a>
                        </span>
                    <?php endif; ?>
                </div>

                <p class="geo-info" id="message-geo" aria-live="polite">
                    <?php if ($autourDeMoi): ?>
                        Résultats classés du plus proche au plus éloigné. Seuls les
                        logements dont la position est enregistrée sont proposés.
                    <?php else: ?>
                        Autorisez votre position pour trouver les logements proches de vous.
                    <?php endif; ?>
                </p>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Rechercher</button>
                <a class="btn btn-second" href="logements.php">Réinitialiser</a>
            </div>
        </form>
    </section>

    <div class="resultats">
        <strong>
            <?= count($logements) ?> logement(s) trouvé(s)<?= $autourDeMoi ? ' dans un rayon de ' . (int) $rayon . ' km' : '' ?>
        </strong>

        <?php if ($pointsCarte): ?>
            <div class="vues" role="group" aria-label="Mode d'affichage">
                <button type="button" id="vue-liste" class="actif">Liste</button>
                <button type="button" id="vue-carte">Carte</button>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pointsCarte): ?>
        <div id="carte-resultats" hidden></div>
        <script type="application/json" id="donnees-carte"><?= json_encode(
            $donneesCarte,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        ) ?></script>
    <?php endif; ?>

    <?php if (!$logements): ?>
        <div class="vide">
            <h2>Aucun logement trouvé</h2>
            <p>Essaie de modifier les filtres ou d'élargir ton budget.</p>
            <a class="btn" href="logements.php">Afficher tous les logements</a>
        </div>
    <?php else: ?>
        <section class="grille">
            <?php foreach ($logements as $l): ?>
                <article class="carte" id="logement-<?= (int) $l['id_logement'] ?>">
                    <div class="photo">
                        <?php if (!empty($l['id_photo'])): ?>
                            <img
                                src="../proprietaire/afficher-photo.php?id=<?= (int) $l['id_logement'] ?>&amp;photo=<?= (int) $l['id_photo'] ?>"
                                alt="Photo du logement : <?= h($l['titre']) ?>"
                                loading="lazy">
                        <?php else: ?>
                            Aucune photo disponible
                        <?php endif; ?>
                    </div>
                    <div class="contenu">
                        <h2><?= h($l['titre']) ?></h2>
                        <p class="localisation">
                            <?= h($l['quartier'] ?: 'Quartier non précisé') ?>,
                            <?= h($l['ville']) ?>
                            <?php if ($l['distance_km'] !== null): ?>
                                <span class="distance">📍 à <?= h(Geolocalisation::formaterDistance((float) $l['distance_km'])) ?></span>
                            <?php endif; ?>
                        </p>
                        <?php if ($l['prix_affiche'] !== null): ?>
                            <p class="prix">
                                <?= number_format((float) $l['prix_affiche'], 0, ',', ' ') ?>
                                <?= h($l['devise'] ?: 'FCFA') ?>
                                <?= !empty($l['duree_affichee']) ? ' ' . h($libellesDuree[$l['duree_affichee']] ?? $l['duree_affichee']) : '' ?>
                            </p>
                        <?php else: ?>
                            <p class="prix">Tarif non renseigné</p>
                        <?php endif; ?>
                        <p class="description">
                            <?= h(mb_strimwidth((string) $l['description'], 0, 125, '…', 'UTF-8')) ?>
                        </p>
                        <a class="btn" href="../details-logement.php?id=<?= (int) $l['id_logement'] ?>">
                            Voir les détails
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<script>
// ---- Recherche « autour de moi » ----
(function () {
    var bouton = document.getElementById('btn-autour');
    var message = document.getElementById('message-geo');
    var champLat = document.getElementById('lat');
    var champLng = document.getElementById('lng');

    if (!bouton || !champLat || !champLng) {
        return;
    }

    function erreur(texte) {
        message.textContent = texte;
        message.classList.add('erreur');
        bouton.disabled = false;
    }

    bouton.addEventListener('click', function () {
        message.classList.remove('erreur');

        if (!navigator.geolocation) {
            erreur("La géolocalisation n'est pas prise en charge par votre navigateur.");
            return;
        }

        if (window.isSecureContext === false) {
            erreur('La géolocalisation exige une connexion sécurisée (HTTPS) ou l’adresse localhost.');
            return;
        }

        bouton.disabled = true;
        message.textContent = 'Recherche de votre position…';

        navigator.geolocation.getCurrentPosition(
            function (position) {
                champLat.value = position.coords.latitude.toFixed(5);
                champLng.value = position.coords.longitude.toFixed(5);
                bouton.closest('form').submit();
            },
            function (e) {
                var texte = 'Impossible de récupérer votre position.';

                if (e.code === e.PERMISSION_DENIED) {
                    texte = 'Position refusée. Autorisez la géolocalisation dans votre navigateur, ou filtrez par ville et quartier.';
                } else if (e.code === e.POSITION_UNAVAILABLE) {
                    texte = 'Votre position est actuellement indisponible.';
                } else if (e.code === e.TIMEOUT) {
                    texte = 'La récupération de votre position a expiré. Réessayez.';
                }

                erreur(texte);
            },
            { enableHighAccuracy: false, timeout: 12000, maximumAge: 60000 }
        );
    });
})();
</script>

<?php if ($pointsCarte): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
// ---- Bascule Liste / Carte ----
(function () {
    var boutonListe = document.getElementById('vue-liste');
    var boutonCarte = document.getElementById('vue-carte');
    var conteneurCarte = document.getElementById('carte-resultats');
    var grille = document.querySelector('.grille');
    var donnees = JSON.parse(document.getElementById('donnees-carte').textContent);
    var carte = null;

    function ligne(parent, texte, classe) {
        var p = document.createElement('p');
        p.textContent = texte;
        p.style.margin = '4px 0';
        if (classe) { p.style.fontWeight = 'bold'; }
        parent.appendChild(p);
    }

    function construireCarte() {
        if (!window.L) {
            conteneurCarte.textContent = 'La carte n’a pas pu être chargée (connexion Internet requise).';
            return;
        }

        carte = L.map(conteneurCarte);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; contributeurs OpenStreetMap'
        }).addTo(carte);

        var limites = [];

        donnees.points.forEach(function (point) {
            var contenu = document.createElement('div');
            ligne(contenu, point.titre, true);
            ligne(contenu, point.lieu);
            ligne(contenu, point.prix, true);
            if (point.distance) { ligne(contenu, '📍 à ' + point.distance); }

            var lien = document.createElement('a');
            lien.href = point.url;
            lien.textContent = 'Voir les détails';
            contenu.appendChild(lien);

            L.marker([point.lat, point.lng]).addTo(carte).bindPopup(contenu);
            limites.push([point.lat, point.lng]);
        });

        if (donnees.utilisateur) {
            var moi = [donnees.utilisateur.lat, donnees.utilisateur.lng];

            L.circleMarker(moi, {
                radius: 8, color: '#087f5b', fillColor: '#087f5b', fillOpacity: 0.9
            }).addTo(carte).bindPopup('Votre position');

            var cercle = L.circle(moi, {
                radius: donnees.utilisateur.rayon * 1000,
                color: '#087f5b', weight: 1, fillOpacity: 0.05
            }).addTo(carte);

            carte.fitBounds(cercle.getBounds());
        } else if (limites.length === 1) {
            carte.setView(limites[0], 16);
        } else {
            carte.fitBounds(limites, { padding: [30, 30] });
        }
    }

    function afficher(vue) {
        var versCarte = vue === 'carte';

        boutonListe.classList.toggle('actif', !versCarte);
        boutonCarte.classList.toggle('actif', versCarte);
        conteneurCarte.hidden = !versCarte;

        if (grille) { grille.hidden = versCarte; }

        if (versCarte) {
            if (!carte) {
                construireCarte();
            } else {
                carte.invalidateSize();
            }
        }
    }

    boutonListe.addEventListener('click', function () { afficher('liste'); });
    boutonCarte.addEventListener('click', function () { afficher('carte'); });
})();
</script>
<?php endif; ?>
</body>
</html>
