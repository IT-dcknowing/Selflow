<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Combien de fois par minute chaque porte peut être poussée.
 *
 * ## Ce qui n'était borné nulle part
 *
 * La connexion comptait ses échecs — cinq essais par couple adresse/adresse IP,
 * et c'était juste. **Tout le reste était libre**, et quatre portes le
 * méritaient particulièrement :
 *
 * - **les cinquante et une routes d'API.** Le groupe `api` n'existait pas :
 *   `Route::middleware('api')` ne posait donc aucune limite, et l'authen-
 *   tification par jeton — une chaîne dans une colonne — pouvait être
 *   éprouvée sans compter ;
 * - **la réinitialisation de mot de passe.** Demander un lien envoie un
 *   courriel : sans borne, on inonde la boîte d'un utilisateur, on épuise le
 *   quota du serveur d'envoi, et l'on apprend au passage quelles adresses
 *   existent ;
 * - **l'import.** Un fichier de cinq mégaoctets, lu ligne à ligne, qui écrit
 *   des articles, du stock et des comptes. Le répéter suffit à occuper le
 *   serveur ;
 * - **la normalisation par lot.** Elle appelle la plateforme de la DGI. La
 *   marteler expose l'entreprise à voir **sa propre clé** ralentie ou coupée
 *   par la plateforme — la conséquence est chez elle, pas chez nous.
 *
 * ## Le principe des clés
 *
 * Une limite se compte par **acteur**, non par route : l'utilisateur
 * authentifié quand il y en a un, l'adresse IP sinon. Compter par route
 * laisserait un même acteur épuiser trente portes voisines l'une après l'autre.
 */
class LimitesDeDebit extends ServiceProvider
{
    public function boot(): void
    {
        // Les routes d'API, appelées par l'application mobile. Soixante appels
        // par minute couvrent largement un écran qui rafraîchit ses listes ;
        // au-delà, c'est une boucle ou un balayage.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($this->acteur($r)));

        // Envoyer un courriel de réinitialisation. Trois par quart d'heure
        // **pour une même adresse** : le temps de vérifier sa boîte, et pas de
        // quoi l'inonder.
        //
        // La borne était comptée par adresse IP, et partagée avec l'envoi du
        // nouveau mot de passe : derrière la box d'un bureau, trois essais de
        // toute l'équipe suffisaient à afficher une page « 429 Too Many
        // Requests » brute (retest de production, octobre 2026). Elle porte
        // désormais sur l'adresse saisie ; la borne par IP, plus large, reste
        // pour arrêter le balayage d'adresses. Au-delà, la page de demande
        // revient avec un message, et non une page d'erreur.
        RateLimiter::for('mot-de-passe', fn (Request $r) => [
            Limit::perMinutes(15, 3)->by('email:' . mb_strtolower(trim((string) $r->input('email'))))->response($this->refusPoli()),
            Limit::perMinutes(15, 10)->by('ip:' . $r->ip())->response($this->refusPoli()),
        ]);

        // Le nouveau mot de passe : le jeton est une chaîne, sans borne il
        // s'éprouve. Compté à part de la demande de lien.
        RateLimiter::for('reinitialisation', fn (Request $r) => Limit::perMinutes(15, 10)->by('ip:' . $r->ip())
            ->response(fn () => back()->withErrors([
                'email' => 'Trop d\'essais. Patientez un quart d\'heure, puis recommencez.',
            ])));

        // L'import : un fichier lourd, lu ligne à ligne, qui écrit en base.
        RateLimiter::for('import', fn (Request $r) => Limit::perMinute(6)->by($this->acteur($r)));

        // La normalisation par lot appelle la plateforme de la DGI : la
        // marteler expose l'entreprise à voir sa propre clé ralentie.
        RateLimiter::for('plateforme', fn (Request $r) => Limit::perMinute(10)->by($this->acteur($r)));

        // **Les routes de synchronisation externe, qui n'ont pas
        // d'authentification.** Un secret partagé les protège, comparé en
        // temps constant — mais un secret se devine, et `list-companies` rend
        // **toutes les entreprises de la plateforme avec leur
        // administrateur**. C'est la porte la plus précieuse de l'application,
        // et la seule que personne n'ouvre en étant connecté : elle mérite la
        // limite la plus stricte.
        RateLimiter::for('externe', fn (Request $r) => [
            Limit::perMinute(20)->by($r->ip()),
            Limit::perHour(100)->by($r->ip()),
        ]);

        // Les écrans ordinaires. Large — il ne s'agit pas de gêner un
        // utilisateur qui travaille vite — mais borné : une boucle dans une
        // page ne doit pas pouvoir occuper le serveur à elle seule.
        RateLimiter::for('web', fn (Request $r) => Limit::perMinute(300)->by($this->acteur($r)));
    }

    /**
     * Qui pousse la porte : l'utilisateur authentifié, l'adresse IP sinon.
     *
     * Compter par route plutôt que par acteur laisserait un même acteur épuiser
     * trente portes voisines l'une après l'autre sans jamais franchir une
     * limite.
     */
    /**
     * La page de demande, rendue avec un message, sous le statut 429.
     */
    private function refusPoli(): \Closure
    {
        return function (Request $requete, array $entetes) {
            $minutes = max(1, (int) ceil(((int) ($entetes['Retry-After'] ?? 900)) / 60));
            $erreurs = (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag([
                'email' => "Trop de demandes de lien pour cette adresse. Un lien vous a peut-être déjà été envoyé : "
                    . "regardez votre boîte (et les indésirables), ou réessayez dans {$minutes} minute" . ($minutes > 1 ? 's' : '') . '.',
            ]));

            return response()->view('authentification::mot_de_passe_oublie', ['errors' => $erreurs], 429, $entetes);
        };
    }

    private function acteur(Request $requete): string
    {
        return $requete->user()?->getAuthIdentifier()
            ? 'u:' . $requete->user()->getAuthIdentifier()
            : 'ip:' . $requete->ip();
    }
}
