<?php

namespace App\Modules\Authentification\Middleware;

use App\Modules\Authentification\Regles\Habilitations;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'habilitation exigée par la route demandée.
 *
 * ## Deux défauts corrigés
 *
 * **La vérification laissait passer ce qu'elle ne connaissait pas.** Elle
 * s'écrivait `if (isset($correspondances[$route]))` : une route absente du
 * dictionnaire passait sans contrôle. Ce n'était pas une décision, c'était un
 * oubli qui s'aggravait à chaque lot — **quatre-vingt-huit routes** au moment
 * de l'audit, dont l'import, par lequel on crée des comptes utilisateurs. Le
 * sens est inversé : ce qui n'est pas classé est refusé, et `Habilitations`
 * porte le classement.
 *
 * **Une adresse électronique était écrite en dur** :
 *
 *     if ($utilisateur->email === 'superadmin@gmail.com') return $next($request);
 *
 * Elle n'était pas exploitable — `role:superadmin` s'exécute avant — mais elle
 * publiait dans le dépôt l'identité du compte le plus puissant de la
 * plateforme, ce qui suffit à en faire la cible de toute tentative. Le
 * privilège suit désormais le rôle et les habilitations, comme pour tout le
 * monde.
 */
class VerifierHabilitationRoute
{
    /**
     * Les habilitations d'administration de la plateforme, par route.
     *
     * @var array<string, string>
     */
    private const SUPERADMIN = [
        'superadmin.tableau_de_bord'                  => 'tableau_de_bord_superadmin',
        'superadmin.entreprises'                      => 'gestion_entreprises',
        'superadmin.entreprises.creer'                => 'gestion_entreprises',
        'superadmin.entreprises.creer.enregistrer'    => 'gestion_entreprises',
        'superadmin.entreprises.modifier'             => 'gestion_entreprises',
        'superadmin.entreprises.modifier.enregistrer' => 'gestion_entreprises',
        'superadmin.entreprises.toggle_status'        => 'gestion_entreprises',
        'superadmin.entreprises.supprimer'            => 'gestion_entreprises',
        'superadmin.utilisateurs'                     => 'gestion_entreprises',
        'superadmin.utilisateurs.modifier'            => 'gestion_entreprises',

        // Ce que la plateforme ouvre a une entreprise donnee, quel que soit
        // son statut -- la comptabilite, fermee par defaut depuis le
        // 02/10/2026, en fait partie. C'est la meme main que la gestion des
        // entreprises : decider de ce qu'un dossier comporte.
        'superadmin.attributions.index'    => 'gestion_entreprises',
        'superadmin.attributions.basculer' => 'gestion_entreprises',
        'superadmin.exercices_anterieurs.index'   => 'gestion_entreprises',
        'superadmin.exercices_anterieurs.valider' => 'gestion_entreprises',
        'superadmin.exercices_anterieurs.refuser' => 'gestion_entreprises',

        'superadmin.liaisons.index'            => 'gestion_comptaflow',
        // `lier` et `creerComptaflow` n'existent plus : la cle est delivree par
        // Comptaflow a la validation, elle ne se colle plus a la main.
        'superadmin.liaisons.valider'          => 'gestion_comptaflow',
        'superadmin.liaisons.refuser'          => 'gestion_comptaflow',
        'superadmin.liaisons.renouveler_cle'   => 'gestion_comptaflow',
        'superadmin.liaisons.delierEntreprise' => 'gestion_comptaflow',
        'superadmin.liaisons.verifier'         => 'gestion_comptaflow',

        'superadmin.fne.index'          => 'gestion_fne',
        'superadmin.fne.cle_test'       => 'gestion_fne',
        'superadmin.fne.cle_reelle'     => 'gestion_fne',
        'superadmin.fne.voir_cle'       => 'gestion_fne',
        'superadmin.fne.voir_acces'     => 'gestion_fne',
        'superadmin.fne.supprimer_cle'  => 'gestion_fne',
        'superadmin.fne.notes'          => 'gestion_fne',
        'superadmin.fne.timbre'         => 'gestion_fne',

        'superadmin.admins.index'         => 'administration_interne',
        'superadmin.admins.creer'         => 'administration_interne',
        'superadmin.admins.enregistrer'   => 'administration_interne',
        'superadmin.admins.modifier'      => 'administration_interne',
        'superadmin.admins.mettre_a_jour' => 'administration_interne',
        'superadmin.admins.supprimer'     => 'administration_interne',

        'superadmin.secteurs_modules.index'       => 'gestion_secteurs_modules',
        'superadmin.secteurs_modules.sauvegarder' => 'gestion_secteurs_modules',

        // La vitrine publique : ce que le monde entier lit de DC-Knowing.
        'superadmin.vitrine.index'             => 'gestion_vitrine',
        'superadmin.vitrine.sections.creer'    => 'gestion_vitrine',
        'superadmin.vitrine.sections.modifier' => 'gestion_vitrine',
        'superadmin.vitrine.sections.basculer' => 'gestion_vitrine',
        'superadmin.vitrine.sections.supprimer'=> 'gestion_vitrine',
        'superadmin.vitrine.cartes.creer'      => 'gestion_vitrine',
        'superadmin.vitrine.cartes.modifier'   => 'gestion_vitrine',
        'superadmin.vitrine.cartes.basculer'   => 'gestion_vitrine',
        'superadmin.vitrine.cartes.supprimer'  => 'gestion_vitrine',

        // Le référentiel des profils métier — les familles et les articles que
        // chaque secteur reçoit à la souscription. Il se lit et ne s'écrit pas
        // depuis l'écran ; il relève tout de même du paramétrage sectoriel.
        'superadmin.referentiel.index'  => 'gestion_secteurs_modules',
        'superadmin.referentiel.profil' => 'gestion_secteurs_modules',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('connexion');
        }

        $utilisateur = Auth::user();
        $nomRoute    = $request->route()?->getName();

        // Une route sans nom ne peut pas être classée. Elle ne devrait pas
        // exister dans ces espaces ; la refuser vaut mieux que de la laisser
        // passer sans qu'on sache ce qu'elle fait.
        if (!$nomRoute) {
            abort(403, 'Accès refusé : cette adresse n\'est rattachée à aucune fonctionnalité connue.');
        }

        if ($utilisateur->estSuperAdmin()) {
            return $this->superadmin($request, $next, $utilisateur, $nomRoute);
        }

        // Un administrateur d'entreprise possède toutes les habilitations de
        // son entreprise : c'est lui qui les distribue.
        if ($utilisateur->estAdmin()) {
            return $next($request);
        }

        // **Ce qui n'est pas classé est refusé.** Oublier une route ferme une
        // porte au lieu d'en ouvrir une.
        if (!Habilitations::estClassee($nomRoute)) {
            abort(403, 'Accès refusé : cette fonctionnalité n\'est ouverte à aucune habilitation.');
        }

        $cle = Habilitations::pour($nomRoute);

        if ($cle === null) {
            return $next($request);
        }

        // L'onglet des habilitations, dans l'écran du personnel, distribue les
        // droits : le voir n'est pas le même geste que gérer le personnel.
        if (Habilitations::normaliser($nomRoute) === 'admin.personnel.index'
            && $request->query('tab') === 'habilitations') {
            $cle = 'gestion_habilitations';
        }

        if (! $utilisateur->aHabilitation($cle)) {
            abort(403, 'Accès refusé. Vous ne disposez pas de l\'habilitation requise pour cette page.');
        }

        return $next($request);
    }

    /**
     * Les écrans d'administration de la plateforme.
     *
     * Même principe : une route `superadmin.` que le dictionnaire ne connaît
     * pas est refusée. Ce sont les écrans les plus puissants de
     * l'application ; y laisser passer l'inconnu serait le pire endroit.
     */
    private function superadmin(Request $request, Closure $next, $utilisateur, string $nomRoute): Response
    {
        if (! str_starts_with($nomRoute, 'superadmin.')) {
            return $next($request);
        }

        $cle = self::SUPERADMIN[$nomRoute] ?? null;

        if ($cle === null) {
            abort(403, 'Accès refusé : cette fonctionnalité d\'administration n\'est rattachée à aucune habilitation.');
        }

        $habilitations = is_array($utilisateur->habilitations) ? $utilisateur->habilitations : [];

        if (! in_array($cle, $habilitations, true)) {
            abort(403, "Accès refusé. Vous n'avez pas l'habilitation requise pour accéder à cette fonctionnalité d'administration.");
        }

        return $next($request);
    }
}
