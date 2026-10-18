
<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (isset($_SESSION['utilisateur_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription | MIGON</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<main class="auth-container">
    <section class="auth-card">
        <a href="index.php" class="logo">MIGON</a>

        <h1>Créer un compte</h1>
        <p class="intro">
            Trouvez ou proposez un logement en toute simplicité.
        </p>
   <?php if (!empty($_SESSION['succes_inscription'])): ?>
    <p class="message succes">
        <?= htmlspecialchars(
            $_SESSION['succes_inscription'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
    <?php unset($_SESSION['succes_inscription']); ?>
<?php endif; ?><?php if (!empty($_SESSION['succes_inscription'])): ?>
    <p class="message succes">
        <?= htmlspecialchars(
            $_SESSION['succes_inscription'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
    <?php unset($_SESSION['succes_inscription']); ?>
<?php endif; ?>
        <?php if (!empty($_SESSION['erreur_inscription'])): ?>
            <p class="message erreur">
                <?= htmlspecialchars(
                    $_SESSION['erreur_inscription'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
            <?php unset($_SESSION['erreur_inscription']); ?>
        <?php endif; ?>

        <form action="traitement-inscription.php"
              method="POST"
              enctype="multipart/form-data">
              <input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
>
            <label for="nom">Nom complet</label>
            <input type="text" id="nom" name="nom"
                   maxlength="120" required autocomplete="name">

            <label for="telephone">Numéro de téléphone</label>
            <input type="tel" id="telephone" name="telephone"
                   maxlength="30" required autocomplete="tel">

            <label for="email">Adresse e-mail (facultative)</label>
            <input type="email" id="email" name="email"
                   maxlength="190" autocomplete="email">

            <label for="mot_de_passe">Mot de passe</label>
            <input type="password" id="mot_de_passe"
                   name="mot_de_passe" minlength="8"
                   required autocomplete="new-password">

            <label for="confirmation">Confirmer le mot de passe</label>
            <input type="password" id="confirmation"
                   name="confirmation" minlength="8"
                   required autocomplete="new-password">

            <label for="role">Je souhaite utiliser MIGON comme</label>
            <select id="role" name="role" required>
                <option value="">Choisir un profil</option>
                <option value="chercheur">Chercheur de logement</option>
                <option value="proprietaire">Propriétaire</option>
            </select>

           <label for="type_document">Type de pièce d'identité</label>
<select id="type_document" name="type_document" required>
    <option value="">Choisir un document</option>
    <option value="carte_identite">Carte d'identité</option>
    <option value="passeport">Passeport</option>
    <option value="permis_conduire">Permis de conduire</option>
</select>

<label for="piece_identite">Pièce d'identité</label>
<input
    type="file"
    id="piece_identite"
    name="piece_identite"
    accept=".jpg,.jpeg,.png,.pdf"
    required
>

<small>
    Formats acceptés : JPG, PNG ou PDF.
    Taille maximale : 5 Mo.
</small>

            <button type="submit" class="btn-principal">
                Créer mon compte
            </button>
        </form>

        <p class="auth-footer">
            Vous avez déjà un compte ?
            <a href="connexion.php">Se connecter</a>
        </p>
    </section>
</main>

</body>
</html>
