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
    public function index(\Illuminate\Http\Request $request): View
    {
        PanneConsignee::fermerCellesCorrigeesParUnDeploiement();

        // Deux onglets (16/10/2026) : les pannes ouvertes, et celles corrigées —
        // une panne corrigée ne disparaît plus, elle passe de l'autre côté. Les
        // deux tableaux sont paginés.
        $onglet = $request->query('onglet') === 'corrigees' ? 'corrigees' : 'ouvertes';

        $pannes = PanneConsignee::query()
            ->when($onglet === 'ouvertes', fn ($q) => $q->whereNull('corrigee_le')->orderByDesc('derniere_le'))
            ->when($onglet === 'corrigees', fn ($q) => $q->whereNotNull('corrigee_le')->orderByDesc('corrigee_le'))
            ->with(['entreprise:id,nom', 'utilisateur:id,nom,prenom,email,role,fonction'])
            ->paginate(20)
            ->withQueryString();

        $nbOuvertes  = PanneConsignee::whereNull('corrigee_le')->count();
        $nbCorrigees = PanneConsignee::whereNotNull('corrigee_le')->count();
        $corrigees = PanneConsignee::whereNotNull('corrigee_le')->where('corrigee_le', '>=', now()->subDays(7))->count();

        $assistance = [
            'email'     => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_EMAIL),
            'telephone' => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_TELEPHONE),
            'commercial_email'     => ParametrePlateforme::lire(ParametrePlateforme::COMMERCIAL_EMAIL),
            'commercial_telephone' => ParametrePlateforme::lire(ParametrePlateforme::COMMERCIAL_TELEPHONE),
            'paiement_numeros'     => ParametrePlateforme::lire(\App\Modules\Admin\Regles\PaiementParReference::PARAMETRE_NUMEROS),
            'alertes_emails'       => ParametrePlateforme::lire(ParametrePlateforme::ALERTES_EMAILS),
            'alertes_whatsapp'     => ParametrePlateforme::lire(ParametrePlateforme::ALERTES_WHATSAPP),
            'alertes_whatsapp_webhook' => ParametrePlateforme::lire(ParametrePlateforme::ALERTES_WHATSAPP_WEBHOOK),
        ];

        $horsLigne = \App\Modules\Admin\Regles\MiseHorsLigne::active() ? \App\Modules\Admin\Regles\MiseHorsLigne::etat() : null;

        return view('admin::superadmin.pannes.index', compact('pannes', 'corrigees', 'assistance', 'horsLigne', 'onglet', 'nbOuvertes', 'nbCorrigees'));
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
            'commercial_email'     => ['nullable', 'email', 'max:191'],
            'commercial_telephone' => ['nullable', 'string', 'max:40'],
            'paiement_numeros'     => ['nullable', 'string', 'max:1000'],
            'alertes_emails'       => ['nullable', 'string', 'max:2000'],
            'alertes_whatsapp'     => ['nullable', 'string', 'max:1000'],
            'alertes_whatsapp_webhook' => ['nullable', 'url', 'max:500'],
        ], [
            'assistance_email.email' => 'L\'adresse de l\'assistance n\'est pas valide.',
            'alertes_whatsapp_webhook.url' => 'L\'adresse du relais WhatsApp doit être une URL valide.',
        ]);

        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_EMAIL, $donnees['assistance_email'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_TELEPHONE, $donnees['assistance_telephone'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::COMMERCIAL_EMAIL, $donnees['commercial_email'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::COMMERCIAL_TELEPHONE, $donnees['commercial_telephone'] ?? null);
        ParametrePlateforme::ecrire(\App\Modules\Admin\Regles\PaiementParReference::PARAMETRE_NUMEROS, $donnees['paiement_numeros'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_EMAILS, $donnees['alertes_emails'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_WHATSAPP, $donnees['alertes_whatsapp'] ?? null);
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_WHATSAPP_WEBHOOK, $donnees['alertes_whatsapp_webhook'] ?? null);

        return back()->with('succes', 'Les contacts et destinataires d\'alertes sont enregistrés.');
    }

    /**
     * Mettre la plateforme hors ligne, ou la rallumer (16/10/2026). Tout le
     * monde voit la page de maintenance évolutive, sauf les superadministrateurs.
     */
    public function horsLigne(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'action'  => ['required', 'in:couper,rallumer'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if ($donnees['action'] === 'rallumer') {
            \App\Modules\Admin\Regles\MiseHorsLigne::rallumer();

            return back()->with('succes', 'Selflow est de nouveau en ligne pour tous les utilisateurs.');
        }

        // Déjà hors ligne : on ne fait que mettre à jour le message affiché.
        $dejaHorsLigne = \App\Modules\Admin\Regles\MiseHorsLigne::active();

        $utilisateur = Auth::user();
        \App\Modules\Admin\Regles\MiseHorsLigne::couper(
            $donnees['message'] ?? null,
            trim(($utilisateur->prenom ?? '') . ' ' . ($utilisateur->nom ?? '')) ?: $utilisateur->email
        );

        return back()->with('succes', $dejaHorsLigne
            ? 'Message de maintenance mis à jour.'
            : 'Selflow est hors ligne : les utilisateurs voient la page de maintenance évolutive. Vous seul(e) et les superadministrateurs gardez l\'accès.');
    }
}
