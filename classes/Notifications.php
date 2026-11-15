<?php

class Notifications
{
    /**
     * Compter les notifications non lues d'un utilisateur.
     */
    public static function compterNonLues(PDO $pdo, int $idUtilisateur): int
    {
        $requete = $pdo->prepare("
            SELECT COUNT(*)
            FROM notification
            WHERE id_utilisateur = :id_utilisateur
              AND statut_lecture = 0
        ");

        $requete->execute([
            'id_utilisateur' => $idUtilisateur,
        ]);

        return (int) $requete->fetchColumn();
    }

    /**
     * Marquer comme lues toutes les notifications non lues d'un utilisateur.
     *
     * @return int Nombre de notifications qui viennent d'être marquées comme lues.
     */
    public static function marquerToutesLues(PDO $pdo, int $idUtilisateur): int
    {
        $requete = $pdo->prepare("
            UPDATE notification
            SET statut_lecture = 1,
                date_lecture = CURRENT_TIMESTAMP
            WHERE id_utilisateur = :id_utilisateur
              AND statut_lecture = 0
        ");

        $requete->execute([
            'id_utilisateur' => $idUtilisateur,
        ]);

        return $requete->rowCount();
    }

    /**
     * Créer une notification pour un utilisateur.
     */
    public static function creer(
        PDO $pdo,
        int $idUtilisateur,
        string $titre,
        string $contenu,
        string $type = 'systeme',
        ?int $idConversation = null
    ): void {
        $requete = $pdo->prepare("
            INSERT INTO notification (
                id_utilisateur,
                id_conversation,
                titre,
                contenu,
                type_notification
            ) VALUES (
                :id_utilisateur,
                :id_conversation,
                :titre,
                :contenu,
                :type_notification
            )
        ");

        $requete->execute([
            'id_utilisateur' => $idUtilisateur,
            'id_conversation' => $idConversation,
            'titre' => $titre,
            'contenu' => $contenu,
            'type_notification' => $type,
        ]);
    }
}
