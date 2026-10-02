<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\VitrineCarte;
use App\Modules\Admin\Modeles\VitrineSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * La vitrine : la page publique, et l'écran qui la remplit.
 *
 * Le contenu n'est pas écrit dans le code. Une page de présentation change
 * plus souvent que l'application qui l'affiche, et chaque virgule aurait
 * demandé un déploiement. Il vit en base, saisi ici par le superadmin.
 *
 * **Rien n'est pré-rempli.** Le texte d'une vitrine engage l'entreprise
 * qu'elle présente : il vient de son propriétaire, jamais d'un exemple laissé
 * là qui finirait en production.
 */
class VitrineControleur
{
    // ══════════════ La page publique ══════════════

    /**
     * Ce que voit un visiteur.
     *
     * Seules les sections publiées sortent : une section en préparation reste
     * invisible tant que son auteur ne l'a pas ouverte.
     */
    public function accueil(): View
    {
        $sections = VitrineSection::publiees()
            ->with('cartesPubliees')
            ->get();

        return view('admin::vitrine.publique', compact('sections'));
    }

    /**
     * Servir une image de la vitrine, sans demander de compte.
     *
     * Ces images passaient par `admin.media`, qui vit derrière `auth` et
     * `role:admin`. Sur l'hébergement mutualisé — le seul où cette route sert,
     * puisque `public/storage` n'y est pas utilisable — **la page de
     * présentation s'affichait sans aucune de ses images pour un visiteur
     * anonyme**. C'est-à-dire pour exactement le public auquel elle s'adresse.
     *
     * Le dossier est imposé ici, et non lu dans l'adresse : une route publique
     * qui accepterait un nom de dossier laisserait demander `logos/…` ou
     * `avatars/…` sans être connecté.
     */
    public function servirUnMedia(string $fichier): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $chemin = 'vitrine/' . $fichier;

        abort_unless(\App\Modules\Admin\Services\FichierPublic::decouper($chemin)[0] === 'vitrine', 404);
        abort_unless(\App\Modules\Admin\Services\FichierPublic::existe($chemin), 404);

        return Storage::disk('public')->response($chemin, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // ══════════════ L'écran du superadmin ══════════════

    public function index(): View
    {
        $sections = VitrineSection::with('cartes')->orderBy('ordre')->get();

        return view('admin::superadmin.vitrine.index', [
            'sections' => $sections,
            'gabarits' => VitrineSection::GABARITS,
            'fonds'    => VitrineSection::FONDS,
            'medias'   => VitrineSection::MEDIAS,
        ]);
    }

    public function creerSection(Request $request): RedirectResponse
    {
        $champs = $this->validerSection($request, [
            'cle' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/', 'unique:vitrine_sections,cle'],
        ]);

        $champs['ordre']   = $champs['ordre'] ?? (VitrineSection::max('ordre') + 10);
        $champs['publiee'] = false;

        VitrineSection::create($champs);

        return back()->with('succes', 'Section créée. Elle reste hors ligne tant que vous ne l\'avez pas publiée.');
    }

    public function modifierSection(Request $request, VitrineSection $section): RedirectResponse
    {
        $champs = $this->validerSection($request);

        if ($request->hasFile('media')) {
            // L'ancien fichier n'a plus d'usage : le laisser remplirait le
            // disque d'illustrations que plus rien n'affiche.
            if ($section->media_path && Storage::disk('public')->exists($section->media_path)) {
                Storage::disk('public')->delete($section->media_path);
            }
            $champs['media_path'] = $request->file('media')->store('vitrine', 'public');
        }

        $section->update($champs);

        return back()->with('succes', 'Section mise à jour.');
    }

    /**
     * Ce qu'une section accepte.
     *
     * @param  array<string, array<int, mixed>>  $enPlus
     * @return array<string, mixed>
     */
    private function validerSection(Request $request, array $enPlus = []): array
    {
        return $request->validate($enPlus + [
            'titre'      => ['required', 'string', 'max:255'],
            'sous_titre' => ['nullable', 'string', 'max:255'],
            'texte'      => ['nullable', 'string', 'max:5000'],
            'gabarit'    => ['required', 'string', 'in:' . implode(',', array_keys(VitrineSection::GABARITS))],
            'fond'       => ['nullable', 'string', 'in:' . implode(',', array_keys(VitrineSection::FONDS))],
            'ordre'      => ['nullable', 'integer', 'min:0', 'max:999'],

            // L'illustration : un fichier déposé, ou une adresse. Une vidéo de
            // démonstration pèse trop pour le disque d'une application de
            // gestion — on accepte donc les deux.
            'media_type'    => ['nullable', 'string', 'in:' . implode(',', array_keys(VitrineSection::MEDIAS))],
            'media_url'     => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/)#i'],
            'media_legende' => ['nullable', 'string', 'max:255'],
            'media'         => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg,mp4,webm', 'max:20480'],

            'action_libelle' => ['nullable', 'string', 'max:64'],
            // Même règle que pour les cartes : un `javascript:` déposé ici
            // s'exécuterait chez chaque visiteur de la page publique.
            'action_url'     => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/|\#)#i'],
        ], [
            'cle.regex'       => 'La clé ne prend que des minuscules, des chiffres et des tirets : elle sert d\'ancre dans l\'adresse.',
            'cle.unique'      => 'Une section porte déjà cette clé.',
            'media_url.regex' => 'L\'adresse du média doit être un lien http(s) ou un chemin interne.',
            'action_url.regex'=> 'Le lien du bouton doit être une adresse http(s), un chemin interne, ou une ancre (#produits).',
            'media.mimes'     => 'Le média doit être une image (png, jpg, webp, svg) ou une vidéo (mp4, webm).',
            'media.max'       => 'Le fichier ne peut pas dépasser 20 Mo. Au-delà, hébergez la vidéo ailleurs et collez son adresse.',
        ]);
    }

    /**
     * Publier ou retirer une section.
     *
     * Le geste est réversible et immédiat : c'est ce qui permet de préparer
     * une page tranquillement, puis de l'ouvrir d'un clic.
     */
    public function basculerSection(VitrineSection $section): RedirectResponse
    {
        $section->update(['publiee' => ! $section->publiee]);

        return back()->with('succes', $section->publiee
            ? 'La section est en ligne.'
            : 'La section est retirée de la page publique.');
    }

    public function supprimerSection(VitrineSection $section): RedirectResponse
    {
        // Les cartes partent avec : la contrainte de clé étrangère est en
        // cascade, et une carte orpheline ne s'afficherait nulle part.
        $section->delete();

        return back()->with('succes', 'Section supprimée, avec ses cartes.');
    }

    // ══════════════ Les cartes ══════════════

    public function creerCarte(Request $request, VitrineSection $section): RedirectResponse
    {
        $champs = $this->validerCarte($request);

        $champs['section_id'] = $section->id;
        $champs['ordre'] = $champs['ordre'] ?? (($section->cartes()->max('ordre') ?? 0) + 10);
        $champs['publiee'] = true;

        if ($request->hasFile('image')) {
            $champs['image_path'] = $request->file('image')->store('vitrine', 'public');
        }

        VitrineCarte::create($champs);

        return back()->with('succes', 'Carte ajoutée.');
    }

    public function modifierCarte(Request $request, VitrineCarte $carte): RedirectResponse
    {
        $champs = $this->validerCarte($request);

        if ($request->hasFile('image')) {
            // L'ancienne image n'a plus d'usage : la laisser remplirait le
            // disque d'illustrations que plus rien n'affiche.
            if ($carte->image_path && Storage::disk('public')->exists($carte->image_path)) {
                Storage::disk('public')->delete($carte->image_path);
            }
            $champs['image_path'] = $request->file('image')->store('vitrine', 'public');
        }

        $carte->update($champs);

        return back()->with('succes', 'Carte mise à jour.');
    }

    public function basculerCarte(VitrineCarte $carte): RedirectResponse
    {
        $carte->update(['publiee' => ! $carte->publiee]);

        return back()->with('succes', $carte->publiee ? 'Carte visible.' : 'Carte masquée.');
    }

    public function supprimerCarte(VitrineCarte $carte): RedirectResponse
    {
        if ($carte->image_path && Storage::disk('public')->exists($carte->image_path)) {
            Storage::disk('public')->delete($carte->image_path);
        }

        $carte->delete();

        return back()->with('succes', 'Carte supprimée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validerCarte(Request $request): array
    {
        return $request->validate([
            'titre'        => ['required', 'string', 'max:255'],
            'texte'        => ['nullable', 'string', 'max:2000'],
            // Ce que la carte est, en un mot : « Comptabilité », « Développeur ».
            'role'         => ['nullable', 'string', 'max:64'],
            'icone'        => ['nullable', 'string', 'max:64'],
            'lien_libelle' => ['nullable', 'string', 'max:64'],
            // Une adresse http(s), un chemin interne, ou une ancre de la page.
            // Rien d'autre : un `javascript:` déposé ici s'exécuterait chez
            // chaque visiteur de la page publique.
            'lien_url'     => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/|\#)#i'],
            'lien_secondaire_libelle' => ['nullable', 'string', 'max:64'],
            'lien_secondaire_url'     => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/|\#)#i'],
            'valeur'       => ['nullable', 'string', 'max:64'],
            'mention'      => ['nullable', 'string', 'max:255'],
            'ordre'        => ['nullable', 'integer', 'min:0', 'max:999'],
            'image'        => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);
    }
}
