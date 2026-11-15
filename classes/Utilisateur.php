<?php

require_once __DIR__ . '/Auth.php';

class Utilisateur
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function inscrire(
        string $nom,
        string $telephone,
        ?string $email,
        string $motDePasse,
        string $role,
        string $typeDocument,
        string $cheminFichierPrive
    ): int {
        $nom = trim($nom);
        $telephone = trim($telephone);
        $email = $email !== null ? trim($email) : null;
        $role = trim($role);
        $typeDocument = trim($typeDocument);

        if ($nom === '' || $telephone === '' || $motDePasse === '') {
            throw new InvalidArgumentException(
                'Le nom, le téléphone et le mot de passe sont obligatoires.'
            );
        }

        if (strlen($motDePasse) < 8) {
            throw new InvalidArgumentException(
                'Le mot de passe doit contenir au moins 8 caractères.'
            );
        }

        if ($email === '') {
            $email = null;
        }

        if ($email !== null &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'Adresse e-mail invalide.'
            );
        }

        if (!in_array($role, ['proprietaire', 'chercheur'], true)) {
            throw new InvalidArgumentException('Rôle non autorisé.');
        }

        if ($typeDocument === '' || $cheminFichierPrive === '') {
            throw new InvalidArgumentException(
                'La pièce d’identité est obligatoire.'
            );
        }

        // Vérifier que le type de document existe dans l'ENUM MySQL.
        $requeteType = $this->pdo->query(
            "SHOW COLUMNS FROM document_identite LIKE 'type_document'"
        );

        $colonne = $requeteType->fetch();

        if (!$colonne ||
            !preg_match('/^enum\((.*)\)$/i', $colonne['Type'], $correspondance)) {
            throw new RuntimeException(
                'Impossible de vérifier les types de documents.'
            );
        }

        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            $correspondance[1],
            $valeurs
        );

        if (!in_array($typeDocument, $valeurs[1], true)) {
            throw new InvalidArgumentException(
                'Le type de pièce d’identité sélectionné est invalide.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $requeteRole = $this->pdo->prepare(
                'SELECT id_role
                 FROM role
                 WHERE nom_role = :nom_role
                 LIMIT 1'
            );

            $requeteRole->execute(['nom_role' => $role]);
            $idRole = $requeteRole->fetchColumn();

            if ($idRole === false) {
                throw new RuntimeException(
                    'Le rôle demandé est introuvable.'
                );
            }

            $requeteUtilisateur = $this->pdo->prepare(
                'INSERT INTO utilisateur
                    (nom, telephone, email, mot_de_passe)
                 VALUES
                    (:nom, :telephone, :email, :mot_de_passe)'
            );

            $requeteUtilisateur->execute([
                'nom' => $nom,
                'telephone' => $telephone,
                'email' => $email,
                'mot_de_passe' => Auth::hashPassword($motDePasse),
            ]);

            $idUtilisateur = (int) $this->pdo->lastInsertId();

            $requeteAssociation = $this->pdo->prepare(
                'INSERT INTO utilisateur_role
                    (id_utilisateur, id_role)
                 VALUES
                    (:id_utilisateur, :id_role)'
            );

            $requeteAssociation->execute([
                'id_utilisateur' => $idUtilisateur,
                'id_role' => (int) $idRole,
            ]);

            $requeteDocument = $this->pdo->prepare(
                'INSERT INTO document_identite
                    (id_utilisateur, type_document, chemin_fichier_prive)
                 VALUES
                    (:id_utilisateur, :type_document, :chemin_fichier_prive)'
            );

            $requeteDocument->execute([
                'id_utilisateur' => $idUtilisateur,
                'type_document' => $typeDocument,
                'chemin_fichier_prive' => $cheminFichierPrive,
            ]);

            $this->pdo->commit();

            return $idUtilisateur;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
