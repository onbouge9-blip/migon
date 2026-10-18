
<?php

session_start();

if (isset($_SESSION['utilisateur_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion | MIGON</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<main class="auth-container">
    <section class="auth-card">
        <a href="index.php" class="logo">MIGON</a>

        <h1>Se connecter</h1>
        <p class="intro">
            Retrouvez votre espace MIGON.
        </p>

        <?php if (!empty($_SESSION['erreur_connexion'])): ?>
            <p class="message erreur">
                <?= htmlspecialchars(
                    $_SESSION['erreur_connexion'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
            <?php unset($_SESSION['erreur_connexion']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['succes_connexion'])): ?>
            <p class="message succes">
                <?= htmlspecialchars(
                    $_SESSION['succes_connexion'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
            <?php unset($_SESSION['succes_connexion']); ?>
        <?php endif; ?>

        <form action="traitement-connexion.php" method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="telephone">Numéro de téléphone</label>
            <input
                type="tel"
                id="telephone"
                name="telephone"
                maxlength="30"
                required
                autocomplete="tel"
            >

            <label for="mot_de_passe">Mot de passe</label>
            <input
                type="password"
                id="mot_de_passe"
                name="mot_de_passe"
                required
                autocomplete="current-password"
            >

            <button type="submit" class="btn-principal">
                Se connecter
            </button>
        </form>

        <p class="auth-footer">
            Vous n'avez pas encore de compte ?
            <a href="inscription.php">Créer un compte</a>
        </p>
    </section>
</main>

</body>
</html>
