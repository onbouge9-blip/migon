
<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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

$erreur = $_SESSION['erreur_logement'] ?? '';
unset($_SESSION['erreur_logement']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un logement | MIGON</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .conteneur {
            max-width: 850px;
            margin: 35px auto;
            padding: 20px;
            scroll-behavior: smooth;
            
        }

        .formulaire {
            background: white;
            padding: 30px;
            border-radius: 14px;
            border: 1px solid #e1e7e3;
            box-shadow: inset 0 0 0 1px #e1e7e3, 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .formulaire h1 {
            color: #123b32;
            margin-top: 0;
        }

        .grille {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        .champ {
            margin-bottom: 18px;
        }

        .champ label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .champ input,
        .champ select,
        .champ textarea {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5d0;
            border-radius: 7px;
            font: inherit;
        }

        .champ textarea {
            min-height: 130px;
            resize: vertical;
        }

        .section {
            margin-top: 28px;
            padding-top: 15px;
            border-top: 1px solid #e1e7e3;
        }

        .bouton {
            display: inline-block;
            background: #123b32;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            cursor: pointer;
            text-decoration: none;
            font: inherit;
        }

        .erreur {
            background: #fff0f0;
            color: #9b1c1c;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .aide {
            color: #59645f;
            font-size: 14px;
        }
         .retour {
            display: inline-block;
            margin-bottom: 10px;
             background:#123b32;
            color:white  ;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
        }
    </style>
</head>
<body>

<main class="conteneur">
    <p>
        <a href="index.php" class="retour">← Retour à mon espace propriétaire</a>
    </p>

    <section class="formulaire">
        <h1>Ajouter un logement</h1>
        <p>Décrivez votre logement et indiquez ses tarifs de location.</p>

        <?php if ($erreur !== ''): ?>
            <div class="erreur"><?= h($erreur) ?></div>
        <?php endif; ?>

        <form action="traitement-logement.php"
              method="POST"
              enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_token'] ?? '') ?>"
            >

            <div class="section">
                <h2>Informations générales</h2>

                <div class="champ">
                    <label for="titre">Titre du logement *</label>
                    <input
                        type="text"
                        id="titre"
                        name="titre"
                        maxlength="180"
                        required
                        placeholder="Ex. Chambre moderne à Godomey"
                    >
                </div>

                <div class="champ">
                    <label for="description">Description *</label>
                    <textarea
                        id="description"
                        name="description"
                        required
                        placeholder="Décrivez le logement et ses avantages..."
                    ></textarea>
                </div>

                <div class="grille">
                    <div class="champ">
                        <label for="type_logement">Type de logement *</label>
                        <select id="type_logement" name="type_logement" required>
                            <option value="">Choisir</option>
                            <option value="chambre">Chambre</option>
                            <option value="appartement">Appartement</option>
                        </select>
                    </div>

                    <div class="champ">
                        <label for="ville">Ville *</label>
                        <input
                            type="text"
                            id="ville"
                            name="ville"
                            maxlength="120"
                            value="Cotonou"
                            required
                        >
                    </div>
                </div>

                <div class="grille">
                    <div class="champ">
                        <label for="quartier">Quartier</label>
                        <input
                            type="text"
                            id="quartier"
                            name="quartier"
                            maxlength="120"
                            placeholder="Ex. Godomey"
                        >
                    </div>

                    <div class="champ">
                        <label for="adresse">Adresse précise *</label>
                        <input
                            type="text"
                            id="adresse"
                            name="adresse"
                            maxlength="255"
                            required
                            placeholder="Rue, repère ou indication"
                        >
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>Tarifs de location</h2>
                <p class="aide">
                    Renseignez au moins un tarif. Les montants sont en francs CFA (XOF).
                </p>

                <div class="grille">
                    <div class="champ">
                        <label for="prix_nuit">Prix par nuit (FCFA)</label>
                        <input type="number" id="prix_nuit"
                               name="prix_nuit" min="1" step="1">
                    </div>

                    <div class="champ">
                        <label for="prix_semaine">Prix par semaine (FCFA)</label>
                        <input type="number" id="prix_semaine"
                               name="prix_semaine" min="1" step="1">
                    </div>

                    <div class="champ">
                        <label for="prix_mois">Prix par mois (FCFA)</label>
                        <input type="number" id="prix_mois"
                               name="prix_mois" min="1" step="1">
                    </div>

                    <div class="champ">
                        <label for="prix_annee">Prix par année (FCFA)</label>
                        <input type="number" id="prix_annee"
                               name="prix_annee" min="1" step="1">
                    </div>
                </div>
            </div>

<div class="section">
    <h2>Équipements disponibles</h2>
    <p class="aide">
        Cochez les équipements présents dans votre logement.
    </p>

    <div class="grille">
        <label><input type="checkbox" name="equipements[]" value="Eau courante"> Eau courante</label>
        <label><input type="checkbox" name="equipements[]" value="Électricité"> Électricité</label>
        <label><input type="checkbox" name="equipements[]" value="Douche"> Douche</label>
        <label><input type="checkbox" name="equipements[]" value="Toilettes"> Toilettes</label>
        <label><input type="checkbox" name="equipements[]" value="Cuisine"> Cuisine</label>
        <label><input type="checkbox" name="equipements[]" value="Parking"> Parking</label>
        <label><input type="checkbox" name="equipements[]" value="Climatisation"> Climatisation</label>
        <label><input type="checkbox" name="equipements[]" value="Wi-Fi"> Wi-Fi</label>
        <label><input type="checkbox" name="equipements[]" value="Groupe électrogène"> Groupe électrogène</label>
        <label><input type="checkbox" name="equipements[]" value="Sécurité"> Sécurité</label>
    </div>
</div>

            <div class="section">
                <h2>Photos du logement</h2>

                <div class="champ">
                    <label for="photos">Sélectionner les photos *</label>
                    <input
                        type="file"
                        id="photos"
                        name="photos[]"
                        accept=".jpg,.jpeg,.png,.webp"
                        multiple
                        required
                    >
                    <p class="aide">
                        Sélectionnez au moins 3 photos pour préparer une annonce publiable.
                        Les fichiers seront vérifiés côté serveur.
                    </p>
                </div>
            </div>

            <div class="section">
                <button type="submit" class="bouton">
                    Enregistrer le logement
                </button>
            </div>

        </form>
    </section>
</main>

</body>
</html>
