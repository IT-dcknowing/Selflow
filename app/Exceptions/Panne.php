<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Distinguer une panne du cours normal des choses.
 *
 * Le gestionnaire d'exceptions interceptait **toutes** les exceptions et
 * renvoyait la page « 500 — panne détectée » pour chacune. En production, cela
 * voulait dire :
 *
 * - une adresse mal tapée affichait une panne au lieu d'une page introuvable ;
 * - un accès refusé affichait une panne au lieu du message d'interdiction ;
 * - une session expirée affichait une panne au lieu de la page de connexion,
 *   sans aucun moyen de se reconnecter ;
 * - **un formulaire mal rempli affichait une panne**, et la saisie était perdue.
 *
 * Chacune déclenchait en prime un courriel d'alerte à deux adresses : un robot
 * qui cherche `/wp-admin` inondait la boîte aux lettres.
 */
class Panne
{
    /**
     * Cette exception mérite-t-elle la page de panne et une alerte ?
     *
     * Ne le méritent que les erreurs serveur : tout ce qui porte un statut
     * inférieur à 500 suit son cours.
     */
    public static function estUne(\Throwable $e): bool
    {
        // Les exceptions HTTP portent leur propre statut : 403, 404, 405, 419…
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        foreach (self::PAS_DES_PANNES as $classe) {
            if ($e instanceof $classe) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le numéro sous lequel cette panne est consignée.
     *
     * L'utilisateur qui appelle le service informatique n'a rien d'autre à
     * donner que ce qu'il voit à l'écran : sans référence, la conversation
     * commence par « quelle page, à quelle heure, quel message ? », et le
     * journal du serveur contient mille lignes de la même minute.
     *
     * La référence se calcule à partir de l'endroit exact où la panne s'est
     * produite : **deux occurrences du même défaut portent le même numéro**, ce
     * qui permet de les regrouper, et un défaut nouveau se distingue tout de
     * suite d'un défaut connu. La date les sépare ensuite dans le journal.
     */
    public static function reference(\Throwable $e): string
    {
        $empreinte = strtoupper(substr(
            hash('crc32b', $e::class . '|' . $e->getFile() . '|' . $e->getLine()),
            0,
            6
        ));

        return 'SF-' . now()->format('ymd') . '-' . $empreinte;
    }

    /**
     * Exceptions dont Laravel sait déjà quoi faire.
     */
    private const PAS_DES_PANNES = [
        AuthenticationException::class,   // vers la page de connexion
        AuthorizationException::class,    // 403
        ValidationException::class,       // retour au formulaire, avec les erreurs
        TokenMismatchException::class,    // 419, jeton CSRF périmé
        ModelNotFoundException::class,    // 404
        // Une réponse déjà écrite, rendue telle quelle : la borne de la
        // demande de lien renvoie la page avec un message (statut 429). La
        // compter comme une panne la transformait en page d'erreur 500.
        \Illuminate\Http\Exceptions\HttpResponseException::class,
    ];
}
