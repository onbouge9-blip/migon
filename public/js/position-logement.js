/*
 * MIGON – Saisie de la position d'un logement (propriétaire).
 *
 * - bouton « Utiliser ma position actuelle » (géolocalisation du navigateur) ;
 * - carte OpenStreetMap facultative : clic ou glisser-déposer du repère
 *   pour ajuster l'emplacement exact (utile si le GPS est approximatif) ;
 * - sans carte (Leaflet indisponible / hors-ligne), le bouton GPS
 *   continue de fonctionner seul.
 *
 * Utilisation :
 *   MigonPosition.init({
 *     boutonId: 'btn-position', messageId: 'message-position',
 *     latId: 'latitude', lngId: 'longitude',
 *     carteId: 'carte-position', obligatoire: true
 *   });
 */
(function () {
    'use strict';

    var CENTRE_PAR_DEFAUT = [6.3703, 2.3912]; // Cotonou–Godomey

    function init(options) {
        var bouton = document.getElementById(options.boutonId);
        var message = document.getElementById(options.messageId);
        var champLat = document.getElementById(options.latId);
        var champLng = document.getElementById(options.lngId);
        var conteneurCarte = options.carteId
            ? document.getElementById(options.carteId)
            : null;

        if (!bouton || !message || !champLat || !champLng) {
            return;
        }

        var carte = null;
        var repere = null;

        function aUnePosition() {
            return champLat.value !== '' && champLng.value !== '' &&
                !isNaN(parseFloat(champLat.value)) &&
                !isNaN(parseFloat(champLng.value));
        }

        function afficher(texte, etat) {
            message.textContent = texte;
            message.style.color =
                etat === 'ok' ? '#087f5b' : (etat === 'ko' ? '#9b1c1c' : '');
        }

        function enregistrer(lat, lng) {
            champLat.value = lat.toFixed(7);
            champLng.value = lng.toFixed(7);
        }

        function placerRepere(lat, lng, zoomer) {
            if (!carte) {
                return;
            }

            if (!repere) {
                repere = L.marker([lat, lng], { draggable: true }).addTo(carte);

                repere.on('dragend', function () {
                    var p = repere.getLatLng();
                    enregistrer(p.lat, p.lng);
                    afficher('✓ Position ajustée sur la carte.', 'ok');
                });
            } else {
                repere.setLatLng([lat, lng]);
            }

            if (zoomer) {
                carte.setView([lat, lng], Math.max(carte.getZoom(), 17));
            }
        }

        // ---- Carte (facultative) ----
        if (conteneurCarte && window.L) {
            var depart = aUnePosition()
                ? [parseFloat(champLat.value), parseFloat(champLng.value)]
                : null;

            carte = L.map(conteneurCarte).setView(
                depart || CENTRE_PAR_DEFAUT,
                depart ? 17 : 12
            );

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; contributeurs OpenStreetMap'
            }).addTo(carte);

            if (depart) {
                placerRepere(depart[0], depart[1], false);
                afficher('Position enregistrée. Vous pouvez la modifier.', 'ok');
            }

            carte.on('click', function (e) {
                enregistrer(e.latlng.lat, e.latlng.lng);
                placerRepere(e.latlng.lat, e.latlng.lng, false);
                afficher('✓ Position choisie sur la carte.', 'ok');
            });
        } else if (conteneurCarte) {
            // Leaflet n'a pas pu être chargé : on masque la carte.
            conteneurCarte.style.display = 'none';
        }

        // ---- Géolocalisation du navigateur ----
        bouton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                afficher(
                    "La géolocalisation n'est pas prise en charge par votre navigateur.",
                    'ko'
                );
                return;
            }

            if (window.isSecureContext === false) {
                afficher(
                    'La géolocalisation exige une connexion sécurisée (HTTPS) ou ' +
                    'l’adresse localhost. Utilisez la carte pour placer le repère.',
                    'ko'
                );
                return;
            }

            afficher('Recherche de votre position…', '');
            bouton.disabled = true;

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    var lat = position.coords.latitude;
                    var lng = position.coords.longitude;
                    var precision = Math.round(position.coords.accuracy || 0);

                    enregistrer(lat, lng);
                    placerRepere(lat, lng, true);

                    if (precision > 100) {
                        afficher(
                            '✓ Position récupérée (précision d’environ ' + precision +
                            ' m). Déplacez le repère sur la carte pour l’ajuster.',
                            'ok'
                        );
                    } else {
                        afficher('✓ Position du logement enregistrée.', 'ok');
                    }

                    bouton.disabled = false;
                },
                function (erreur) {
                    var texte = 'Impossible de récupérer votre position.';

                    if (erreur.code === erreur.PERMISSION_DENIED) {
                        texte = 'Vous avez refusé l’accès à votre position. ' +
                            'Autorisez-la dans le navigateur ou placez le repère sur la carte.';
                    } else if (erreur.code === erreur.POSITION_UNAVAILABLE) {
                        texte = 'Votre position est actuellement indisponible.';
                    } else if (erreur.code === erreur.TIMEOUT) {
                        texte = 'La récupération de votre position a expiré. Réessayez.';
                    }

                    afficher(texte, 'ko');
                    bouton.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        });

        // ---- Contrôle avant l'envoi du formulaire ----
        if (options.obligatoire && champLat.form) {
            champLat.form.addEventListener('submit', function (e) {
                if (!aUnePosition()) {
                    e.preventDefault();
                    afficher(
                        'Indiquez la position du logement (bouton GPS ou clic sur la carte) avant d’enregistrer.',
                        'ko'
                    );
                    bouton.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    }

    window.MigonPosition = { init: init };
})();
