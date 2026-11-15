<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

// Vérifier que l'utilisateur est connecté.
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier que l'utilisateur est un propriétaire.
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

$idProprietaire = (int) $_SESSION['utilisateur_id'];
$idPaiement = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($idPaiement <= 0) {
    $_SESSION['erreur_disponibilite'] = 'Demande de paiement invalide.';
    header('Location: logements.php');
    exit;
}

try {
    $pdo = (new Database())->getConnection();

    // Récupérer la demande de paiement et le logement associé.
    $stmt = $pdo->prepare("
        SELECT
            pr.id_paiement_reactivation,
            pr.id_logement,
            pr.montant,
            pr.devise,
            pr.statut_paiement,
            l.titre,
            l.ville,
            l.quartier
        FROM paiement_reactivation pr
        INNER JOIN logement l
            ON l.id_logement = pr.id_logement
        WHERE pr.id_paiement_reactivation = :id_paiement
          AND pr.id_proprietaire = :id_proprietaire
        LIMIT 1
    ");

    $stmt->execute([
        'id_paiement' => $idPaiement,
        'id_proprietaire' => $idProprietaire
    ]);

    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        $_SESSION['erreur_disponibilite'] =
            'Cette demande de paiement est introuvable.';

        header('Location: logements.php');
        exit;
    }

    // Si le paiement est déjà confirmé, il ne doit plus être modifié.
    if ($paiement['statut_paiement'] === 'confirme') {
        $_SESSION['succes_disponibilite'] =
            'Le paiement a déjà été confirmé.';

        header('Location: logements.php');
        exit;
    }

    // Création du jeton CSRF spécifique au paiement.
    if (empty($_SESSION['csrf_paiement_reactivation'])) {
        $_SESSION['csrf_paiement_reactivation'] =
            bin2hex(random_bytes(32));
    }

} catch (Throwable $e) {
    error_log(
        'Erreur paiement réactivation MIGON : ' . $e->getMessage()
    );

    $_SESSION['erreur_disponibilite'] =
        'Impossible de charger la demande de paiement.';

    header('Location: logements.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Paiement de réactivation | MIGON</title>

    <link rel="stylesheet" href="../public/css/style.css">

    <style>
        body {
            margin: 0;
            background: #f5f7f8;
            font-family: Arial, Helvetica, sans-serif;
            color: #263238;
        }

        .page {
            max-width: 720px;
            margin: 40px auto;
            padding: 20px;
        }

        .retour {
            display: inline-block;
            margin-bottom: 20px;
            color: #0f4d3f;
            text-decoration: none;
            font-weight: 600;
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8e4;
            border-radius: 14px;
            padding: 28px;
        }

        h1 {
            margin-top: 0;
            color: #123b32;
        }

        .resume {
            background: #e7f5ee;
            border-radius: 10px;
            padding: 18px;
            margin: 20px 0;
            line-height: 1.7;
        }

        .montant {
            font-size: 32px;
            font-weight: 800;
            color: #087f5b;
            margin: 20px 0;
        }

        .champ {
            margin-top: 18px;
        }

        .champ label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
        }

        .champ select,
        .champ input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd6d0;
            border-radius: 8px;
            box-sizing: border-box;
            font: inherit;
        }

        .bouton {
            margin-top: 22px;
            width: 100%;
            padding: 13px;
            border: 0;
            border-radius: 8px;
            background: #087f5b;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .bouton:hover {
            background: #066b4c;
        }

        .info {
            margin-top: 18px;
            padding: 14px;
            border-radius: 8px;
            background: #fff8df;
            color: #735b00;
            line-height: 1.6;
        }
    </style>
</head>

<body>

<main class="page">

    <a class="retour" href="logements.php">
        ← Retour à mes logements
    </a>

    <section class="card">

        <h1>Réactiver ce logement</h1>

        <p>
            Pour rendre ce logement à nouveau disponible sur MIGON,
            un paiement de réactivation est nécessaire.
        </p>

        <div class="resume">
            <strong><?= h($paiement['titre']) ?></strong><br>

            <?= h($paiement['quartier'] ?: '') ?>
            <?= !empty($paiement['quartier']) ? ' — ' : '' ?>
            <?= h($paiement['ville']) ?>
        </div>

        <div class="montant">
            <?= number_format(
                (float) $paiement['montant'],
                0,
                ',',
                ' '
            ) ?>
            <?= h($paiement['devise']) ?>
        </div>

        <form
            method="post"
            action="traitement-paiement-reactivation.php"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_paiement_reactivation']) ?>"
            >

            <input
                type="hidden"
                name="id_paiement"
                value="<?= (int) $paiement['id_paiement_reactivation'] ?>"
            >

            <div class="champ">
                <label for="methode">
                    Méthode de paiement
                </label>

                <select
                    name="methode_paiement"
                    id="methode"
                    required
                >
                    <option value="">
                        Choisir une méthode
                    </option>

                    <option value="mobile_money">
                        Mobile Money
                    </option>

                    <option value="virement">
                        Virement bancaire
                    </option>

                    <option value="especes">
                        Espèces
                    </option>

                    <option value="autre">
                        Autre
                    </option>
                </select>
            </div>

            <div class="champ">
                <label for="reference">
                    Référence du paiement (facultative)
                </label>

                <input
                    type="text"
                    name="reference_paiement"
                    id="reference"
                    maxlength="190"
                >
            </div>

            <button class="bouton" type="submit">
                Enregistrer ma demande de paiement
            </button>

        </form>

        <div class="info">
            Votre logement restera indisponible tant que le paiement
            de réactivation n'aura pas été confirmé.
        </div>

    </section>

</main>

</body>
</html>