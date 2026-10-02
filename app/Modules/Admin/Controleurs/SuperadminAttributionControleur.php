<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ce que le superadministrateur ouvre à une entreprise donnée.
 *
 * Demande du propriétaire, le 02/10/2026 : « pour éviter de perdre la main,
 * fais une section au niveau du superadmin que tu appelleras attribution —
 * dans cette page on va activer certaines choses pour certaines entreprises,
 * même quel que soit son statut, et la comptabilité en fait partie. »
 *
 * ## Pourquoi une seconde notion, et non la même case
 *
 * L'entreprise a la sienne, dans ses paramètres : « j'active la comptabilité ».
 * Celle-ci dit autre chose : « cette entreprise y a droit ».
 *
 * Les confondre en une seule colonne aurait fait qu'une entreprise décochant
 * son réglage annulerait ce qu'on lui a accordé — ou l'inverse, qu'elle
 * s'accorderait elle-même ce qui ne lui revient pas. C'est pourquoi
 * `attributions` n'est pas assignable en masse, et n'est jamais lue d'un
 * formulaire d'entreprise : le défaut exact de la clé de liaison Comptaflow,
 * corrigé au lot 15.
 *
 * L'attribution **prime** : elle ouvre les écrans quoi qu'en dise le réglage
 * de l'entreprise, et la case de ses paramètres est alors montrée cochée et
 * désactivée, avec la raison.
 */
class SuperadminAttributionControleur
{
    public function index(Request $request): View
    {
        $recherche = trim((string) $request->input('q', ''));

        $entreprises = Entreprise::query()
            ->when($recherche !== '', function ($requete) use ($recherche) {
                $requete->where(function ($q) use ($recherche) {
                    $q->where('nom', 'like', "%{$recherche}%")
                      ->orWhere('ncc', 'like', "%{$recherche}%");
                });
            })
            ->orderBy('nom')
            ->paginate(25)
            ->withQueryString();

        return view('admin::superadmin.attributions.index', [
            'entreprises' => $entreprises,
            'catalogue'   => Entreprise::ATTRIBUTIONS,
            'recherche'   => $recherche,
        ]);
    }

    /**
     * Accorder ou retirer une attribution.
     *
     * L'écriture est directe, et non par `update()` : `attributions` n'est pas
     * dans `$fillable`, précisément pour qu'aucune requête d'un autre
     * formulaire ne puisse l'y glisser.
     */
    public function basculer(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $valide = $request->validate([
            'attribution' => ['required', 'string', Rule::in(array_keys(Entreprise::ATTRIBUTIONS))],
            'accorder'    => ['required', 'in:0,1'],
        ]);

        $attribution = $valide['attribution'];
        $accorder    = $valide['accorder'] === '1';

        $accordees = $entreprise->attributions;
        if (is_string($accordees)) {
            $accordees = json_decode($accordees, true);
        }
        $accordees = is_array($accordees) ? array_values(array_unique($accordees)) : [];

        $accordees = $accorder
            ? array_values(array_unique([...$accordees, $attribution]))
            : array_values(array_diff($accordees, [$attribution]));

        $entreprise->attributions = $accordees;
        $entreprise->save();

        /*
         * Une attribution ouvre des écrans qui ne sont pas compris dans
         * l'offre de base : qui l'a accordée, à qui, et quand, doit pouvoir se
         * retrouver. Sans trace, un écran ouvert « on ne sait plus par qui »
         * ne se referme jamais, de peur de casser quelque chose.
         */
        Log::info('Attribution ' . ($accorder ? 'accordée' : 'retirée'), [
            'attribution'  => $attribution,
            'entreprise'   => $entreprise->nom,
            'entreprise_id'=> $entreprise->id,
            'par'          => Auth::user()?->email,
        ]);

        $libelle = Entreprise::ATTRIBUTIONS[$attribution] ?? $attribution;

        return back()->with('success', $accorder
            ? "« {$libelle} » est désormais ouverte à {$entreprise->nom}."
            : "« {$libelle} » n'est plus ouverte à {$entreprise->nom}. Son propre réglage, s'il est coché, continue de s'appliquer.");
    }
}
