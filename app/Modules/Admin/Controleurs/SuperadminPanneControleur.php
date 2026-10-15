<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\PanneConsignee;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Le registre des pannes et les contacts de l'assistance — 15/10/2026.
 *
 * L'utilisateur ne voit plus l'erreur ; le superadministrateur la trouve ici :
 * un code et une adresse par ligne, le détail au clic. Une panne corrigée
 * quitte la liste — à la main, ou d'elle-même quand le fichier fautif a changé
 * depuis (un correctif déployé). Si elle revient, elle se rouvre.
 */
class SuperadminPanneControleur
{
    public function index(): View
    {
        PanneConsignee::fermerCellesCorrigeesParUnDeploiement();

        $pannes = PanneConsignee::ouvertes()
            ->with(['entreprise:id,nom', 'utilisateur:id,nom,prenom,email'])
            ->orderByDesc('derniere_le')
            ->get();

        $corrigees = PanneConsignee::whereNotNull('corrigee_le')->where('corrigee_le', '>=', now()->subDays(7))->count();

        $assistance = [
            'email'     => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_EMAIL),
            'telephone' => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_TELEPHONE),
        ];

        return view('admin::superadmin.pannes.index', compact('pannes', 'corrigees', 'assistance'));
    }

    public function corriger(PanneConsignee $panne): RedirectResponse
    {
        $utilisateur = Auth::user();
        $panne->update([
            'corrigee_le'  => now(),
            'corrigee_par' => trim(($utilisateur->prenom ?? '') . ' ' . ($utilisateur->nom ?? '')) ?: 'Superadministrateur',
        ]);

        return back()->with('succes', "La panne {$panne->code} est marquée corrigée. Elle reviendra dans la liste si elle se reproduit.");
    }

    public function assistance(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'assistance_email'     => ['nullable', 'email', 'max:191'],
            'assistance_telephone' => ['nullable', 'string', 'max:40'],
        ], [
            'assistance_email.email' => 'L\'adresse de l\'assistance n\'est pas valide.',
        ]);

        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_EMAIL, $donnees['assistance_email'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_TELEPHONE, $donnees['assistance_telephone'] ?? null);

        return back()->with('succes', 'Les contacts de l\'assistance sont enregistrés : ils s\'affichent sur la page de maintenance.');
    }
}
