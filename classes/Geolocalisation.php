<?php

/**
 * Outils de géolocalisation pour MIGON.
 *
 * - validation des coordonnées reçues (propriétaire ou chercheur) ;
 * - calcul de distance (formule de Haversine) directement en SQL ;
 * - pré-filtre par « boîte englobante » pour profiter de l'index
 *   idx_logement_geo (latitude, longitude).
 *
 * Remarque : la connexion PDO de MIGON utilise les requêtes préparées
 * natives (ATTR_EMULATE_PREPARES = false). Un même paramètre nommé ne
 * peut donc pas être répété dans une requête : les paramètres
 * ci-dessous portent chacun un nom distinct.
 */
class Geolocalisation
{
    /** Centre de la zone Cotonou–Godomey (carte affichée par défaut). */
    public const LATITUDE_PAR_DEFAUT = 6.3703;
    public const LONGITUDE_PAR_DEFAUT = 2.3912;

    /** Rayons de recherche proposés au chercheur (en kilomètres). */
    public const RAYONS_KM = [1, 2, 5, 10, 20];
    public const RAYON_DEFAUT_KM = 5;

    private const RAYON_TERRE_KM = 6371.0;

    /** Marge de sécurité (0,1 %) pour ne jamais écarter un logement en bordure. */
    private const MARGE_BOITE = 1.001;

    /**
     * Valide un couple latitude / longitude.
     *
     * @return array{0: float, 1: float}|null  [latitude, longitude] ou null
     */
    public static function valider(mixed $latitude, mixed $longitude): ?array
    {
        if (!is_scalar($latitude) || !is_scalar($longitude)) {
            return null;
        }

        $latitude = trim((string) $latitude);
        $longitude = trim((string) $longitude);

        if (
            $latitude === '' ||
            $longitude === '' ||
            !is_numeric($latitude) ||
            !is_numeric($longitude)
        ) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if (
            !is_finite($lat) || !is_finite($lng) ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180
        ) {
            return null;
        }

        // (0, 0) correspond à une position non renseignée, pas à un logement.
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        // La colonne SQL est DECIMAL(10,7) : 7 décimales suffisent (~1 cm).
        return [round($lat, 7), round($lng, 7)];
    }

    /**
     * Retourne un rayon valide en kilomètres (valeur par défaut sinon).
     */
    public static function rayonValide(mixed $rayon): int
    {
        if (is_scalar($rayon) && ctype_digit((string) $rayon)) {
            $valeur = (int) $rayon;

            if (in_array($valeur, self::RAYONS_KM, true)) {
                return $valeur;
            }
        }

        return self::RAYON_DEFAUT_KM;
    }

    /**
     * Expression SQL de la distance en km entre le logement et le point
     * du chercheur. À utiliser avec parametresDistance().
     *
     * @param string $alias Alias de la table logement dans la requête.
     */
    public static function expressionDistance(string $alias = 'l'): string
    {
        $lat = $alias . '.latitude';
        $lng = $alias . '.longitude';

        return '(' . self::RAYON_TERRE_KM . ' * ACOS(LEAST(1, GREATEST(-1, '
            . 'COS(RADIANS(:geo_lat_cos)) * COS(RADIANS(' . $lat . ')) '
            . '* COS(RADIANS(' . $lng . ') - RADIANS(:geo_lng)) '
            . '+ SIN(RADIANS(:geo_lat_sin)) * SIN(RADIANS(' . $lat . '))'
            . '))))';
    }

    /**
     * Paramètres nommés requis par expressionDistance() et par le
     * pré-filtre conditionBoite().
     *
     * @return array<string, float>
     */
    public static function parametres(
        float $latitude,
        float $longitude,
        float $rayonKm
    ): array {
        [$latMin, $latMax, $lngMin, $lngMax] =
            self::boiteEnglobante($latitude, $longitude, $rayonKm);

        return [
            'geo_lat_cos' => $latitude,
            'geo_lat_sin' => $latitude,
            'geo_lng' => $longitude,
            'geo_lat_min' => $latMin,
            'geo_lat_max' => $latMax,
            'geo_lng_min' => $lngMin,
            'geo_lng_max' => $lngMax,
            'geo_rayon' => $rayonKm,
        ];
    }

    /**
     * Condition SQL de pré-filtre (utilise l'index géographique).
     */
    public static function conditionBoite(string $alias = 'l'): string
    {
        return $alias . '.latitude IS NOT NULL '
            . 'AND ' . $alias . '.longitude IS NOT NULL '
            . 'AND ' . $alias . '.latitude BETWEEN :geo_lat_min AND :geo_lat_max '
            . 'AND ' . $alias . '.longitude BETWEEN :geo_lng_min AND :geo_lng_max';
    }

    /**
     * Rectangle (en degrés) qui contient le cercle de rayon donné.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     *         [latMin, latMax, lngMin, lngMax]
     */
    public static function boiteEnglobante(
        float $latitude,
        float $longitude,
        float $rayonKm
    ): array {
        // Distance angulaire du rayon sur la sphère (même rayon terrestre
        // que la formule de Haversine, pour rester cohérent avec elle).
        $angle = $rayonKm / self::RAYON_TERRE_KM;

        $deltaLat = rad2deg($angle) * self::MARGE_BOITE;

        // Évite une division par zéro près des pôles.
        $cosinus = max(0.01, cos(deg2rad($latitude)));
        $deltaLng = rad2deg(asin(min(1.0, sin($angle) / $cosinus)))
            * self::MARGE_BOITE;

        return [
            max(-90.0, $latitude - $deltaLat),
            min(90.0, $latitude + $deltaLat),
            max(-180.0, $longitude - $deltaLng),
            min(180.0, $longitude + $deltaLng),
        ];
    }

    /**
     * Distance lisible : « 350 m » ou « 2,4 km ».
     */
    public static function formaterDistance(float $km): string
    {
        if ($km < 1) {
            return max(10, (int) (round($km * 100) * 10)) . ' m';
        }

        return number_format($km, 1, ',', ' ') . ' km';
    }
}
