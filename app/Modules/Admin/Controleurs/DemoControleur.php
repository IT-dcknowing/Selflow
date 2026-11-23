<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\DemandeDemo;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Services\AlerteSuperadminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * « Voir une démo » (23/11/2026) : le visiteur laisse son adresse et son
 * numéro WhatsApp ; le superadministrateur dépose la démonstration et la
 * transmet, par courriel et sur WhatsApp.
 */
class DemoControleur extends Controller
{
    private const CLE_LIEN    = 'demo_lien';
    private const CLE_FICHIER = 'demo_fichier';
    private const CLE_MESSAGE = 'demo_message';

    /** Le formulaire de la page d'accueil. */
    public function demander(Request $request): JsonResponse
    {
        // La page d'accueil attend du JSON ; l'application ne rend ses erreurs
        // en JSON que sous `api/*` : la validation se fait donc ici.
        $validateur = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'email'      => ['required', 'email:rfc', 'max:190'],
            'whatsapp'   => ['required', 'string', 'max:40', 'regex:/^[0-9+ ().-]{8,40}$/'],
            'profils'    => ['required', 'array', 'min:1'],
            'profils.*'  => ['in:' . implode(',', array_keys(DemandeDemo::PROFILS))],
        ], [
            'whatsapp.regex' => 'Le numéro WhatsApp n’est pas valide.',
            'profils.required' => 'Cochez au moins une case.',
            'profils.min'      => 'Cochez au moins une case.',
        ]);

        if ($validateur->fails()) {
            return response()->json(['message' => $validateur->errors()->first()], 422);
        }

        $donnees = $validateur->validated();

        $demande = DemandeDemo::create([
            'email'    => mb_strtolower(trim($donnees['email'])),
            'whatsapp' => trim($donnees['whatsapp']),
            'profils'  => array_values(array_unique($donnees['profils'])),
            'ip'       => $request->ip(),
        ]);

        try {
            AlerteSuperadminService::alerter('demande_demo', 'Nouvelle demande de démo',
                "Demande de démo de {$demande->email} (WhatsApp {$demande->whatsapp}), {$demande->libellesProfils()}.");
        } catch (\Throwable) {
            // L'alerte est un confort : la demande est enregistrée.
        }

        return response()->json(['message' => 'Merci ! Votre demande est enregistrée : la démo vous sera envoyée par courriel et sur WhatsApp.']);
    }

    /** Le superadministrateur : les demandes rangées par client, et la démo à déposer. */
    public function index(): View
    {
        $parClient = DemandeDemo::query()->latest()->get()->groupBy('email');

        return view('admin::superadmin.demos.index', [
            'parClient' => $parClient,
            'lien'      => ParametrePlateforme::lire(self::CLE_LIEN),
            'fichier'   => ParametrePlateforme::lire(self::CLE_FICHIER),
            'texte'     => ParametrePlateforme::lire(self::CLE_MESSAGE),
        ]);
    }

    /** Déposer la démo : un fichier (vidéo, PDF), un lien, et le message d'accompagnement. */
    public function deposer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'fichier' => ['nullable', 'file', 'max:204800', 'mimes:mp4,webm,mov,pdf'],
            'lien'    => ['nullable', 'url:http,https', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->hasFile('fichier')) {
            $ancien = ParametrePlateforme::lire(self::CLE_FICHIER);
            $chemin = $request->file('fichier')->store('demo', 'local');
            ParametrePlateforme::ecrire(self::CLE_FICHIER, $chemin);
            if ($ancien && $ancien !== $chemin) {
                Storage::disk('local')->delete($ancien);
            }
        }

        ParametrePlateforme::ecrire(self::CLE_LIEN, $donnees['lien'] ?? null);
        ParametrePlateforme::ecrire(self::CLE_MESSAGE, $donnees['message'] ?? null);

        return back()->with('succes', 'Démo enregistrée.');
    }

    /** Transmettre la démo à un client : courriel, puis WhatsApp. */
    public function transmettre(DemandeDemo $demande): RedirectResponse
    {
        $lien = $this->lienDeLaDemo();

        if ($lien === null) {
            return back()->with('erreur', 'Déposez d’abord la démo (fichier ou lien).');
        }

        $texte = trim((string) ParametrePlateforme::lire(self::CLE_MESSAGE))
            ?: 'Bonjour, voici la démonstration de Selflow, la gestion commerciale qui fait certifier vos factures par la DGI.';
        $corps = $texte . "\n\nLa démo : {$lien}\n\nL'équipe Selflow, DC-Knowing. Assistance : " . config('selflow.assistance_telephone');

        $courriel = false;
        try {
            Mail::raw($corps, fn ($m) => $m->to($demande->email)->subject('Votre démo Selflow'));
            $courriel = true;
        } catch (\Throwable $e) {
            Log::warning('[démo] courriel non parti vers ' . $demande->email . ' : ' . $e->getMessage());
        }

        // WhatsApp : par le relais des alertes s'il est configuré ; sinon, le
        // lien wa.me ouvre la conversation avec le message déjà écrit.
        $whatsappEnvoye = false;
        $relais = ParametrePlateforme::lire(ParametrePlateforme::ALERTES_WHATSAPP_WEBHOOK);
        if (!blank($relais)) {
            try {
                Http::timeout(5)->post($relais, ['type' => 'demo', 'numeros' => [$demande->numeroWhatsappInternational()], 'message' => $corps]);
                $whatsappEnvoye = true;
            } catch (\Throwable $e) {
                Log::warning('[démo] relais WhatsApp injoignable : ' . $e->getMessage());
            }
        }

        $demande->update([
            'statut'        => 'transmise',
            'envois'        => $demande->envois + 1,
            'transmise_le'  => now(),
            'transmise_par' => Auth::id(),
        ]);

        $retour = back()->with('succes', 'Démo transmise' . ($courriel ? ' par courriel' : ' (le courriel n’est pas parti, voir le journal)')
            . ($whatsappEnvoye ? ' et sur WhatsApp.' : '. Terminez l’envoi WhatsApp dans l’onglet qui vient de s’ouvrir.'));

        return $whatsappEnvoye
            ? $retour
            : $retour->with('ouvrir_whatsapp', 'https://wa.me/' . $demande->numeroWhatsappInternational() . '?text=' . rawurlencode($corps));
    }

    /** Le fichier de démo, par un lien signé valable trente jours. */
    public function voir(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $fichier = ParametrePlateforme::lire(self::CLE_FICHIER);
        abort_unless($fichier && Storage::disk('local')->exists($fichier), 404);

        return Storage::disk('local')->response($fichier, 'demo-selflow.' . pathinfo($fichier, PATHINFO_EXTENSION));
    }

    private function lienDeLaDemo(): ?string
    {
        $fichier = ParametrePlateforme::lire(self::CLE_FICHIER);
        if ($fichier && Storage::disk('local')->exists($fichier)) {
            return URL::temporarySignedRoute('vitrine.demo.voir', now()->addDays(30));
        }

        $lien = trim((string) ParametrePlateforme::lire(self::CLE_LIEN));

        return $lien !== '' ? $lien : null;
    }
}
