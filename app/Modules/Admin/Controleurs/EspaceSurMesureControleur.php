<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\EspaceSurMesure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Selflow sur mesure (23/11/2026).
 *
 * Le superadministrateur donne à une entreprise sa propre adresse (un
 * sous-domaine de Selflow, un domaine à elle, ou les deux), son identité
 * visuelle, et active ses modules : page d'accueil, page de connexion,
 * couleurs dans l'application.
 */
class EspaceSurMesureControleur extends Controller
{
    private const IMAGES = ['logo', 'logo_blanc', 'icone', 'image_fond'];

    // ══════════════ Superadministrateur ══════════════

    public function index(): View
    {
        $entreprises = Entreprise::query()->orderBy('nom')->get(['id', 'uuid', 'nom', 'ncc', 'statut']);
        $espaces = EspaceSurMesure::query()->get()->keyBy('entreprise_id');

        return view('admin::superadmin.sur-mesure.index', compact('entreprises', 'espaces'));
    }

    public function modifier(Entreprise $entreprise): View
    {
        $espace = EspaceSurMesure::firstOrNew(['entreprise_id' => $entreprise->id]);

        return view('admin::superadmin.sur-mesure.modifier', [
            'entreprise' => $entreprise,
            'espace' => $espace,
            'racine' => EspaceSurMesure::domaineRacine(),
            'ipServeur' => config('selflow.sur_mesure.ip_serveur'),
            'cibleCname' => config('selflow.sur_mesure.cible_cname'),
        ]);
    }

    public function enregistrer(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $espace = EspaceSurMesure::firstOrNew(['entreprise_id' => $entreprise->id]);
        $racine = EspaceSurMesure::domaineRacine();

        $request->merge([
            'sous_domaine' => strtolower(trim((string) $request->input('sous_domaine'))) ?: null,
            'domaine' => preg_replace('#^(https?://)?(www\.)?#', '', strtolower(trim((string) $request->input('domaine'), " /"))) ?: null,
        ]);

        $donnees = $request->validate([
            'actif' => ['nullable', 'boolean'],
            'sous_domaine' => ['nullable', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/',
                Rule::notIn(['www', 'mail', 'api', 'admin', 'cpanel', 'webmail', 'ftp', 'app']),
                Rule::unique('espaces_sur_mesure', 'sous_domaine')->ignore($espace->id)],
            'domaine' => ['nullable', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique('espaces_sur_mesure', 'domaine')->ignore($espace->id),
                function ($attribut, $valeur, $echec) use ($racine) {
                    if ($valeur === $racine || str_ends_with($valeur, '.' . $racine)) {
                        $echec('Un domaine propre ne peut pas être une adresse de Selflow : utilisez le sous-domaine.');
                    }
                }],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::in(array_keys(EspaceSurMesure::MODULES))],
            'couleur_principale' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'couleur_secondaire' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'modele_accueil' => ['required', Rule::in(array_keys(EspaceSurMesure::MODELES_ACCUEIL))],
            'modele_connexion' => ['required', Rule::in(array_keys(EspaceSurMesure::MODELES_CONNEXION))],
            'titre_accueil' => ['nullable', 'string', 'max:255'],
            'sous_titre_accueil' => ['nullable', 'string', 'max:500'],
            'presentation' => ['nullable', 'string', 'max:5000'],
            'message_connexion' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'atouts' => ['nullable', 'array', 'max:6'],
            'atouts.*.titre' => ['nullable', 'string', 'max:80'],
            'atouts.*.texte' => ['nullable', 'string', 'max:300'],
            // Pas de SVG : servi depuis l'adresse de l'entreprise, il pourrait
            // porter du script.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'logo_blanc' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'icone' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'image_fond' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'retirer' => ['nullable', 'array'],
            'retirer.*' => [Rule::in(self::IMAGES)],
        ], [
            'sous_domaine.regex' => 'Le sous-domaine ne contient que des lettres minuscules, des chiffres et des tirets.',
            'sous_domaine.not_in' => 'Ce sous-domaine est réservé.',
            'sous_domaine.unique' => 'Ce sous-domaine est déjà attribué à une autre entreprise.',
            'domaine.regex' => 'Le domaine n\'est pas valide (exemple : gestion.entreprise.ci).',
            'domaine.unique' => 'Ce domaine est déjà attribué à une autre entreprise.',
        ]);

        $actif = $request->boolean('actif');
        if ($actif && empty($donnees['sous_domaine']) && empty($donnees['domaine'])) {
            return back()->withInput()->withErrors(['sous_domaine' => 'Un espace actif a besoin d\'au moins une adresse : un sous-domaine, un domaine, ou les deux.']);
        }

        // Un domaine qui change se vérifie à nouveau.
        if (($donnees['domaine'] ?? null) !== $espace->domaine) {
            $espace->domaine_verifie_le = null;
        }

        $espace->fill([
            'entreprise_id' => $entreprise->id,
            'actif' => $actif,
            'sous_domaine' => $donnees['sous_domaine'] ?? null,
            'domaine' => $donnees['domaine'] ?? null,
            'modules' => array_values($donnees['modules'] ?? []),
            'couleur_principale' => strtolower($donnees['couleur_principale']),
            'couleur_secondaire' => strtolower($donnees['couleur_secondaire']),
            'modele_accueil' => $donnees['modele_accueil'],
            'modele_connexion' => $donnees['modele_connexion'],
            'titre_accueil' => $donnees['titre_accueil'] ?? null,
            'sous_titre_accueil' => $donnees['sous_titre_accueil'] ?? null,
            'presentation' => $donnees['presentation'] ?? null,
            'message_connexion' => $donnees['message_connexion'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            'email' => $donnees['email'] ?? null,
            'adresse' => $donnees['adresse'] ?? null,
            'atouts' => collect($donnees['atouts'] ?? [])
                ->filter(fn ($a) => trim((string) ($a['titre'] ?? '')) !== '')
                ->map(fn ($a) => ['titre' => trim($a['titre']), 'texte' => trim((string) ($a['texte'] ?? ''))])
                ->values()->all(),
        ]);

        foreach (self::IMAGES as $champ) {
            $ancien = $espace->{$champ};
            if ($request->hasFile($champ)) {
                $fichier = $request->file($champ);
                $nom = $champ . '-' . now()->format('YmdHis') . '.' . strtolower($fichier->extension());
                $espace->{$champ} = $fichier->storeAs('sur-mesure/' . $entreprise->id, $nom, 'public');
            } elseif (in_array($champ, (array) $request->input('retirer', []), true)) {
                $espace->{$champ} = null;
            }
            if ($ancien && $ancien !== $espace->{$champ}) {
                Storage::disk('public')->delete($ancien);
            }
        }

        $espace->save();

        return redirect()->route('superadmin.sur_mesure.modifier', $entreprise)
            ->with('success', 'Espace sur mesure de ' . $entreprise->nom . ' enregistré.');
    }

    /**
     * Les adresses pointent-elles sur ce serveur ? Les DNS sont gérés par
     * nous ; ce bouton dit si l'enregistrement est en place.
     */
    public function verifier(Entreprise $entreprise): RedirectResponse
    {
        $espace = EspaceSurMesure::where('entreprise_id', $entreprise->id)->firstOrFail();
        $racine = EspaceSurMesure::domaineRacine();
        $ipsServeur = array_values(array_filter(array_unique(array_merge(
            (array) config('selflow.sur_mesure.ip_serveur'),
            $this->ips($racine)
        ))));

        $rapport = [];
        $toutBon = true;
        foreach ($espace->adresses() as $adresse) {
            $ips = $this->ips($adresse);
            $bon = $ips !== [] && array_intersect($ips, $ipsServeur) !== [];
            $toutBon = $toutBon && $bon;
            $rapport[] = $adresse . ' : ' . ($bon
                ? 'pointe sur le serveur (' . implode(', ', $ips) . ')'
                : ($ips ? 'pointe ailleurs (' . implode(', ', $ips) . ')' : 'aucun enregistrement DNS trouvé'));
        }

        if ($espace->adresses() === []) {
            return back()->withErrors(['domaine' => 'Aucune adresse à vérifier.']);
        }

        if ($toutBon && $espace->domaine) {
            $espace->forceFill(['domaine_verifie_le' => now()])->save();
        }

        return back()->with($toutBon ? 'success' : 'error', implode(' · ', $rapport));
    }

    /** Aperçu d'une page, même tant que l'espace n'est pas actif. */
    public function apercu(Entreprise $entreprise, string $page): View
    {
        $espace = EspaceSurMesure::where('entreprise_id', $entreprise->id)->firstOrFail();

        return $page === 'accueil'
            ? $this->accueil($espace, true)
            : view('admin::sur-mesure.connexion', ['espace' => $espace, 'apercu' => true]);
    }

    // ══════════════ Pages publiques ══════════════

    public function accueil(EspaceSurMesure $espace, bool $apercu = false): View
    {
        $vueDediee = 'admin::sur-mesure.espaces.entreprise-' . $espace->entreprise_id;
        if ($espace->modele_accueil === 'sur_mesure' && view()->exists($vueDediee)) {
            return view($vueDediee, ['espace' => $espace, 'apercu' => $apercu]);
        }

        return view('admin::sur-mesure.accueil', ['espace' => $espace, 'apercu' => $apercu]);
    }

    public function media(int $espace, string $fichier)
    {
        $chemin = 'sur-mesure/' . EspaceSurMesure::whereKey($espace)->value('entreprise_id') . '/' . basename($fichier);
        abort_unless(str_starts_with($chemin, 'sur-mesure/') && Storage::disk('public')->exists($chemin), 404);

        return Storage::disk('public')->response($chemin, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** @return string[] */
    private function ips(string $hote): array
    {
        $ips = @gethostbynamel($hote);

        return $ips ? array_values(array_unique($ips)) : [];
    }
}
