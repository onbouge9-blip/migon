<?php
session_start();
if (empty($_SESSION['csrf_verification'])) {
    $_SESSION['csrf_verification'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../classes/Database.php';

// Vérifier la connexion
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier le rôle administrateur
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    http_response_code(403);
    exit('Accès refusé.');
}

// Connexion à la base de données
$pdo = (new Database())->getConnection();

// Récupérer les documents et les informations des utilisateurs
$sql = "
    SELECT
        d.id_document,
        d.id_utilisateur,
        d.type_document,
        d.statut_verification,
        d.motif_refus,
        d.date_soumission,
        u.nom,
        u.telephone
    FROM document_identite d
    INNER JOIN utilisateur u
        ON u.id_utilisateur = d.id_utilisateur
    ORDER BY
        CASE
            WHEN d.statut_verification = 'en_attente' THEN 0
            ELSE 1
        END,
        d.date_soumission DESC
";

$stmt = $pdo->query($sql);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification des identités | MIGON</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            margin-top: 20px;
        }

        .table-identites {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .table-identites th,
        .table-identites td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }

        .table-identites th {
            background: #f2f4f7;
        }

        .statut {
            font-weight: bold;
        }

        .en-attente {
            color: #a16207;
        }

        .verifie {
            color: #15803d;
        }

        .refuse {
            color: #b91c1c;
        }
    </style>
</head>
<body>
<main class="auth-container">
    <section class="auth-card" style="max-width: 1100px;">
        <a href="index.php" class="logo">MIGON</a>

        <h1>Gestion des identités</h1>
        <?php if (($_GET['resultat'] ?? '') === 'succes'): ?>
    <p style="color: green;">
        La décision a été enregistrée et l'utilisateur a été notifié.
    </p>
<?php endif; ?>
        <p>Consulte les documents d'identité soumis par les utilisateurs.</p>

        <p>
            <a href="index.php">Retour à l'administration</a>
        </p>

        <?php if (empty($documents)): ?>
            <p>Aucun document d'identité n'a été soumis.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table-identites">
                    <thead>
                        <tr>
                            
                            <th>N°</th>
                            <th>Utilisateur</th>
                            <th>Téléphone</th>
                            <th>Type de document</th>
                            <th>Date de soumission</th>
                            <th>Statut</th>
                            <th>Motif du refus</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td><?= h($document['id_document']) ?></td>
                                <td>
                                    <?= h($document['nom']) ?>
                                    <br>
                                    <small>
                                        Utilisateur n° <?= h($document['id_utilisateur']) ?>
                                    </small>
                                </td>
                                <td><?= h($document['telephone']) ?></td>
                                <td><?= h($document['type_document']) ?></td>
                                <td><?= h($document['date_soumission']) ?></td>
                                <td>
                                    <span class="statut <?= h($document['statut_verification']) ?>">
                                        <?= h($document['statut_verification']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h($document['motif_refus'] ?? '') ?: '—' ?>
                               <td>
<p>
    <a
        href="voir-document.php?id=<?= h($document['id_document']) ?>"
        target="_blank"
        rel="noopener noreferrer"
    >
        Consulter le document
    </a>
</p>
    <?php if ($document['statut_verification'] === 'en_attente'): ?>

        <form method="POST" action="traitement-verification.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_verification']) ?>"
            >

            <input
                type="hidden"
                name="id_document"
                value="<?= h($document['id_document']) ?>"
            >

            <button type="submit" name="action" value="verifier">
                Vérifier
            </button>
        </form>

        <form method="POST" action="traitement-verification.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_verification']) ?>"
            >

            <input
                type="hidden"
                name="id_document"
                value="<?= h($document['id_document']) ?>"
            >

            <label>Motif du refus :</label>

            <textarea
                name="motif_refus"
                required
                maxlength="5000"
                placeholder="Explique pourquoi le document est refusé."
            ></textarea>

            <button type="submit" name="action" value="refuser">
                Refuser
            </button>
        </form>

    <?php else: ?>
        Document déjà traité.
    <?php endif; ?>
</td>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
