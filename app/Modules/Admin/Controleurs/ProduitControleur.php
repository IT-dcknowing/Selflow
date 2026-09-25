<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\ProduitDetailLibre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProduitControleur
{
    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;
        $produits   = Produit::where('entreprise_id', $entreprise->id)
            ->actifs()
            ->with(['category', 'sousCategorieRelation', 'stocks', 'taxes'])
            ->orderBy('nom')
            ->paginate(24);

        $produits_archives = Produit::where('entreprise_id', $entreprise->id)
            ->archives()
            ->with(['category'])
            ->orderBy('nom')
            ->paginate(24, ['*'], 'page_archives');

        $comptes = \App\Modules\Admin\Modeles\PlanComptable::whereNull('entreprise_id')
            ->orWhere('entreprise_id', $entreprise->id)
            ->orderBy('numero')
            ->get();

        $categories = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
            ->with('sousCategories')
            ->orderBy('nom')
            ->get();

        $syscohadaKws = \App\Modules\Admin\Modeles\CategorieSyscohada::where(function($q) use ($entreprise) {
                $q->whereNull('entreprise_id')->orWhere('entreprise_id', $entreprise->id);
            })
            ->orderBy('libelle_affiche')
            ->get();

        return view('admin::produits.index', compact(
            'produits', 'produits_archives', 'comptes', 'categories', 'syscohadaKws'
        ));
    }

    public function creer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'nom'           => ['required', 'string', 'max:200'],
            'type'          => ['required', 'string', 'in:marchandise,matiere_premiere,produit_fini,consommable_stockable,consommable_non_stockable,service'],
            'categorie_id'  => ['nullable', 'string'],
            'sous_categorie_id' => ['nullable', 'string'],
            'nouvelle_categorie' => ['nullable', 'string', 'max:100'],
            'prefixe_categorie' => ['nullable', 'string', 'max:5'],
            'nouvelle_sous_categorie' => ['nullable', 'string', 'max:100'],
            'prix_achat'    => [$request->input('type') === 'service' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'prix_vente'    => ['required', 'numeric', 'min:0'],
            'taux_tva'      => ['required', 'numeric', 'min:0'],
            'compte_vente'  => ['required', 'string', 'max:20'],
            'compte_achat'  => ['required', 'string', 'max:20'],
            'stock_actuel'  => [in_array($request->input('type'), ['service', 'consommable_non_stockable']) ? 'nullable' : 'required', 'integer', 'min:0'],
            'stock_minimum' => [in_array($request->input('type'), ['service', 'consommable_non_stockable']) ? 'nullable' : 'required', 'integer', 'min:0'],
            'unite'         => ['nullable', 'string', 'max:20'],
            // Champs FNE (DGI)
            'remise_taux'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'code_tva_manuel'      => ['nullable', 'boolean'],
            'code_tva'             => ['nullable', 'string', 'in:TVA,TVAB,TVAC,TVAD,AUTRE'],
            'taxes_produit'        => ['nullable', 'array'],
            'taxes_produit.*.nom'  => ['required_with:taxes_produit.*.taux', 'string', 'max:100'],
            'taxes_produit.*.taux' => ['required_with:taxes_produit.*.nom', 'numeric', 'gt:0', 'max:100'],
        ], [
            'taxes_produit.*.taux.gt'  => 'Le taux d\'une taxe doit être strictement supérieur à 0 %.',
            'taxes_produit.*.taux.max' => 'Le taux d\'une taxe ne peut pas dépasser 100 %.',
            'taxes_produit.*.nom.required_with' => 'Chaque taxe doit avoir un nom.',
            'remise_taux.max' => 'La remise ne peut pas dépasser 100 %.',
        ]);

        $reference = null;
        if ($request->input('reference_auto') === '0' || $request->input('reference_auto') === 0 || $request->input('reference_auto') === 'false') {
            $request->validate([
                'reference' => [
                    'required',
                    'string',
                    'max:100',
                    \Illuminate\Validation\Rule::unique('produits')->where(function ($q) use ($entreprise) {
                        $q->where('entreprise_id', $entreprise->id);
                    })
                ]
            ]);
            $reference = trim($request->input('reference'));
        }

        $categorieId = $request->input('categorie_id');
        if ($categorieId === 'nouvelle' && $request->filled('nouvelle_categorie')) {
            $prefixe = strtoupper(trim($request->input('prefixe_categorie')));
            if (empty($prefixe)) {
                $prefixe = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', trim($request->input('nouvelle_categorie'))), 0, 4));
            }
            if (empty($prefixe)) {
                $prefixe = 'PROD';
            }
            
            // Unicité du préfixe
            $prefixeOriginal = $prefixe;
            $compteur = 1;
            while (\App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)->where('prefixe', $prefixe)->exists()) {
                $prefixe = substr($prefixeOriginal, 0, 3) . $compteur;
                $compteur++;
            }

            $categorie = \App\Modules\Admin\Modeles\Categorie::create([
                'entreprise_id' => $entreprise->id,
                'nom'           => trim($request->input('nouvelle_categorie')),
                'prefixe'       => $prefixe,
            ]);
            $categorieId = $categorie->id;
        }

        $sousCategorieId = $request->input('sous_categorie_id');
        if ($sousCategorieId === 'nouvelle' && $request->filled('nouvelle_sous_categorie') && $categorieId) {
            $sousCategorie = \App\Modules\Admin\Modeles\SousCategorie::create([
                'categorie_id' => $categorieId,
                'nom'          => trim($request->input('nouvelle_sous_categorie')),
            ]);
            $sousCategorieId = $sousCategorie->id;
        } elseif ($sousCategorieId === 'nouvelle' || empty($sousCategorieId)) {
            $sousCategorieId = null;
        }

        $produit = Produit::create([
            'entreprise_id'     => $entreprise->id,
            'reference'         => $reference,
            'nom'               => $request->nom,
            'type'              => $request->type,
            'categorie_id'      => $categorieId ?: null,
            'sous_categorie_id' => $sousCategorieId ?: null,
            'prix_achat'        => $request->input('type') === 'service' ? 0 : $request->prix_achat,
            'prix_vente'        => $request->prix_vente,
            'taux_tva'          => $request->taux_tva,
            'compte_vente'      => $request->compte_vente,
            'compte_achat'      => $request->compte_achat,
            'unite'             => $request->unite,
            'remise_taux'       => floatval($request->input('remise_taux', 0)),
            'code_tva_manuel'   => $request->boolean('code_tva_manuel'),
            // « AUTRE » signale un taux hors barème : le code DGI est alors déduit
            // du taux saisi plutôt que stocké tel quel.
            'code_tva'          => ($request->boolean('code_tva_manuel') && $request->input('code_tva') !== 'AUTRE')
                ? $request->input('code_tva')
                : null,
        ]);

        $this->enregistrerTaxesProduit($produit, $request->input('taxes_produit', []));

        // Initialisation des stocks par point de vente.
        //
        // Uniquement pour ce qui se compte. Une fiche etait creee pour tous les
        // types, services compris, avec un stock minimum de 5 : une prestation
        // restait donc a 0 pour un seuil de 5 et figurait en permanence dans
        // « Alertes stock ». Pour un cabinet comptable, dont tous les articles
        // sont des missions, le tableau de bord n'annoncait que des ruptures —
        // sur des choses qui ne s'epuisent pas.
        if (!$produit->estStockable()) {
            return back()->with('succes', 'Produit ajouté au catalogue avec succès. Référence générée : ' . $produit->reference);
        }

        $pdvs = $entreprise->pointsDeVente;
        $defaultPdvId = session('point_de_vente_actif_id') 
            ?? auth()->user()->point_de_vente_id 
            ?? ($pdvs->first()->id ?? null);

        /*
         * La fiche s'ouvre à zéro partout, et la quantité de départ entre par
         * la porte.
         *
         * Elle était écrite ici, directement dans `stocks` : aucun mouvement ne
         * la portait, le journal des mouvements ne la montrait pas, et le CUMP
         * (Coût Unitaire Moyen Pondéré) restait à zéro — si bien que la
         * première sortie valorisait la marchandise à rien, et que la
         * comptabilité de stock partait fausse dès le premier article.
         */
        foreach ($pdvs as $pdv) {
            $isDefault = ($pdv->id == $defaultPdvId);
            \App\Modules\Admin\Modeles\Stock::create([
                'produit_id'          => $produit->id,
                'point_de_vente_id'   => $pdv->id,
                'quantite_disponible' => 0,
                'stock_minimum'       => $isDefault ? $request->input('stock_minimum', 5) : 5,
                'stock_maximum'       => 100,
            ]);
        }

        $quantiteDeDepart = (float) $request->input('stock_actuel', 0);

        if ($defaultPdvId && $quantiteDeDepart > 0 && $produit->estStockable()) {
            \App\Modules\Admin\Services\StockService::entree(
                $produit,
                (int) $defaultPdvId,
                $quantiteDeDepart,
                \App\Modules\Admin\Modeles\MouvementStock::STOCK_INITIAL,
                // Le prix d'achat saisi vaut coût d'entrée : c'est la seule
                // valeur connue le jour où l'article naît, et elle devient le
                // premier CUMP.
                ['cout_unitaire' => (float) $produit->prix_achat, 'reference' => $produit->reference]
            );
        }

        return back()->with('succes', 'Produit ajouté au catalogue avec succès. Référence générée : ' . $produit->reference);
    }

    /**
     * Remplacer les taxes personnalisées d'un produit (champ `customTaxes` de
     * la FNE au niveau de l'article).
     *
     * Ces taxes sont recopiées sur les lignes de vente au moment de la
     * facturation : les modifier ici n'altère jamais une facture déjà émise.
     */
    private function enregistrerTaxesProduit(Produit $produit, $taxes): void
    {
        $produit->taxes()->delete();

        foreach ((array) $taxes as $taxe) {
            $nom  = trim((string) ($taxe['nom'] ?? ''));
            $taux = floatval($taxe['taux'] ?? 0);

            if ($nom === '' || $taux <= 0 || $taux > 100) {
                continue;
            }

            $produit->taxes()->create(['nom' => $nom, 'taux' => $taux]);
        }
    }

    /**
     * Basculer le statut d'un produit entre actif et archivé.
     */
    public function archiver(Produit $produit): RedirectResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $produit->update([
            'statut' => $produit->statut === 'actif' ? 'archive' : 'actif',
        ]);

        $msg = $produit->statut === 'archive'
            ? 'Produit archivé avec succès.'
            : 'Produit restauré dans le catalogue.'
        ;

        return back()->with('succes', $msg);
    }

    /**
     * Servir la photo d'un article quand `public/storage` n'est pas posé.
     *
     * Le chemin normal reste le lien de stockage, servi par le serveur web
     * sans passer par PHP. Mais une installation où `php artisan storage:link`
     * n'a pas été lancé rendait des adresses en 404 (Not Found — introuvable)
     * sans que rien ne le dise : les vignettes basculaient silencieusement sur
     * l'image d'attente, et les fonds de carte de l'écran de vente restaient
     * vides. Plutôt que d'exiger une commande dont personne ne se souvient,
     * l'application sait servir ses propres images.
     *
     * L'accès reste celui des données de l'entreprise : la photo d'un article
     * du voisin se refuse comme l'article lui-même.
     */
    public function voirPhoto(Produit $produit): StreamedResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $chemin = $produit->photo;

        // Le chemin vient de la base, mais il a été écrit par un formulaire :
        // on ne descend pas dans l'arborescence sur la foi d'une colonne.
        abort_if(!$chemin || str_contains($chemin, '..') || !Storage::disk('public')->exists($chemin), 404);

        return Storage::disk('public')->response($chemin);
    }

    /**
     * Upload photo depuis l'interface web ou l'API mobile.
     * Retourne JSON pour l'API, redirect pour le web.
     */
    public function uploaderPhoto(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        // Supprimer l'ancienne photo si elle existe
        if ($produit->photo) {
            Storage::disk('public')->delete($produit->photo);
        }

        $extension = $request->file('photo')->extension();
        $chemin    = 'produits/' . Str::uuid() . '.' . $extension;
        $request->file('photo')->storeAs('', $chemin, 'public');

        $produit->update(['photo' => $chemin]);

        return response()->json([
            'success'   => true,
            'photo_url' => $produit->photo_url,
        ]);
    }

    public function modifier(Request $request, Produit $produit): RedirectResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);
        $entreprise = Auth::user()->entreprise;

        $isService = ($request->input('type') === 'service');
        $isNoStock = ($isService || $request->input('type') === 'consommable_non_stockable');

        $request->validate([
            'nom'           => ['required', 'string', 'max:200'],
            'type'          => ['required', 'string', 'in:marchandise,matiere_premiere,produit_fini,consommable_stockable,consommable_non_stockable,service'],
            'categorie_id'  => ['nullable', 'string'],
            'sous_categorie_id' => ['nullable', 'string'],
            'nouvelle_categorie' => ['nullable', 'string', 'max:100'],
            'prefixe_categorie' => ['nullable', 'string', 'max:5'],
            'nouvelle_sous_categorie' => ['nullable', 'string', 'max:100'],
            'prix_achat'    => [$isService ? 'nullable' : 'required', 'numeric', 'min:0'],
            'prix_vente'    => ['required', 'numeric', 'min:0'],
            'taux_tva'      => ['required', 'numeric', 'min:0'],
            'compte_vente'  => ['required', 'string', 'max:20'],
            'compte_achat'  => ['required', 'string', 'max:20'],
            // La quantite n'est plus modifiable depuis le catalogue : le champ
            // est en lecture seule et rien ne l'envoie. La regle reste
            // permissive pour ne pas refuser une requete qui le porterait
            // encore -- elle sera simplement ignoree.
            'stock_actuel'  => ['nullable', 'integer'],
            'stock_minimum' => [$isNoStock ? 'nullable' : 'required', 'integer', 'min:0'],
            'unite'         => ['nullable', 'string', 'max:20'],
            // Champs FNE (DGI)
            'remise_taux'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'code_tva_manuel'      => ['nullable', 'boolean'],
            'code_tva'             => ['nullable', 'string', 'in:TVA,TVAB,TVAC,TVAD,AUTRE'],
            'taxes_produit'        => ['nullable', 'array'],
            'taxes_produit.*.nom'  => ['required_with:taxes_produit.*.taux', 'string', 'max:100'],
            'taxes_produit.*.taux' => ['required_with:taxes_produit.*.nom', 'numeric', 'gt:0', 'max:100'],
            // Le suivi par lot et son préavis. La colonne est un `smallint` :
            // sans borne, une valeur démesurée serait tronquée en base, et le
            // préavis vaudrait autre chose que ce qui a été saisi.
            'date_arrivee'       => ['nullable', 'date'],
            'date_peremption'    => ['nullable', 'date'],
            'suivi_par_lot'      => ['nullable', 'boolean'],
            'preavis_peremption' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ], [
            'taxes_produit.*.taux.gt'  => 'Le taux d\'une taxe doit être strictement supérieur à 0 %.',
            'taxes_produit.*.taux.max' => 'Le taux d\'une taxe ne peut pas dépasser 100 %.',
            'taxes_produit.*.nom.required_with' => 'Chaque taxe doit avoir un nom.',
            'remise_taux.max' => 'La remise ne peut pas dépasser 100 %.',
            'preavis_peremption.max' => 'Un préavis de péremption se compte en jours, dix ans au plus.',
        ]);

        $categorieId = $request->input('categorie_id');
        if ($categorieId === 'nouvelle' && $request->filled('nouvelle_categorie')) {
            $prefixe = strtoupper(trim($request->input('prefixe_categorie')));
            if (empty($prefixe)) {
                $prefixe = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', trim($request->input('nouvelle_categorie'))), 0, 4));
            }
            if (empty($prefixe)) {
                $prefixe = 'PROD';
            }
            
            // Unicité du préfixe
            $prefixeOriginal = $prefixe;
            $compteur = 1;
            while (\App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)->where('prefixe', $prefixe)->exists()) {
                $prefixe = substr($prefixeOriginal, 0, 3) . $compteur;
                $compteur++;
            }

            $categorie = \App\Modules\Admin\Modeles\Categorie::create([
                'entreprise_id' => $entreprise->id,
                'nom'           => trim($request->input('nouvelle_categorie')),
                'prefixe'       => $prefixe,
            ]);
            $categorieId = $categorie->id;
        }

        $sousCategorieId = $request->input('sous_categorie_id');
        if ($sousCategorieId === 'nouvelle' && $request->filled('nouvelle_sous_categorie') && $categorieId) {
            $sousCategorie = \App\Modules\Admin\Modeles\SousCategorie::create([
                'categorie_id' => $categorieId,
                'nom'          => trim($request->input('nouvelle_sous_categorie')),
            ]);
            $sousCategorieId = $sousCategorie->id;
        } elseif ($sousCategorieId === 'nouvelle' || empty($sousCategorieId)) {
            $sousCategorieId = null;
        }

        // Si la catégorie change et qu'aucune référence n'existe ou si on veut la recalculer
        // Notons que si le produit change de catégorie, on peut éventuellement générer une nouvelle référence, 
        // mais pour la traçabilité il vaut mieux garder l'originale à moins que l'utilisateur le souhaite.
        // La spécification demande "génération automatique et unique basée sur la catégorie" à la création.
        // Restons fidèles à la création pour garder la cohérence historique.

        $produit->update([
            'nom'               => $request->nom,
            'type'              => $request->type,
            'categorie_id'      => $categorieId ?: null,
            'sous_categorie_id' => $sousCategorieId ?: null,
            'prix_achat'        => $isService ? 0 : $request->prix_achat,
            'prix_vente'        => $request->prix_vente,
            'taux_tva'          => $request->taux_tva,
            'compte_vente'      => $request->compte_vente,
            'compte_achat'      => $request->compte_achat,
            'unite'             => $request->unite,
            'remise_taux'       => floatval($request->input('remise_taux', 0)),
            'code_tva_manuel'   => $request->boolean('code_tva_manuel'),
            // « AUTRE » signale un taux hors barème : le code DGI est alors déduit
            // du taux saisi plutôt que stocké tel quel.
            'code_tva'          => ($request->boolean('code_tva_manuel') && $request->input('code_tva') !== 'AUTRE')
                ? $request->input('code_tva')
                : null,
            // **Ces deux champs étaient au formulaire et n'étaient pas
            // enregistrés.** L'utilisateur saisissait une date de péremption,
            // l'écran la reprenait vide à la visite suivante, et l'alerte des
            // rebuts ne voyait jamais l'article.
            'date_arrivee'       => $request->input('date_arrivee') ?: null,
            'date_peremption'    => $request->input('date_peremption') ?: null,
            // Le suivi par lot : la péremption appartient à l'arrivage, pas à
            // l'article. Une pharmacie qui reçoit trois arrivages de
            // paracétamol n'enregistrait qu'une date, la saisie du troisième
            // écrasant les deux premiers.
            'suivi_par_lot'      => $request->boolean('suivi_par_lot'),
            'preavis_peremption' => (int) $request->input('preavis_peremption', 30),
        ]);

        $this->enregistrerTaxesProduit($produit, $request->input('taxes_produit', []));

        $pdvId = session('point_de_vente_actif_id')
            ?? auth()->user()->point_de_vente_id 
            ?? ($entreprise->pointsDeVente->first()->id ?? null);

        /*
         * **Le catalogue ne corrige plus la quantité.**
         *
         * Modifier un article réécrivait `quantite_disponible` avec ce que
         * portait le formulaire : la quantité montait ou descendait sans
         * mouvement, sans trace de qui l'avait fait ni pourquoi, et sans que le
         * CUMP en tienne compte. C'est une correction d'inventaire déguisée, et
         * l'inventaire a son écran — qui, lui, écrit l'écart, le date et le
         * signe.
         *
         * Le seuil d'alerte, lui, reste ici : c'est un réglage, pas une
         * quantité.
         */
        if ($pdvId && !$isNoStock) {
            \App\Modules\Admin\Modeles\Stock::updateOrCreate([
                'produit_id'        => $produit->id,
                'point_de_vente_id' => $pdvId,
            ], [
                'stock_minimum' => $request->input('stock_minimum', 5),
            ]);
        }

        return back()->with('succes', 'Produit mis à jour avec succès.');
    }

    /**
     * Page fiche détaillée d'un produit.
     */
    public function fiche(Produit $produit): View
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $produit->load(['category', 'sousCategorieRelation', 'stocks.pointDeVente', 'detailsLibres']);

        return view('admin::produits.fiche', compact('produit'));
    }

    /**
     * Modifier la description inventaire uniquement.
     */
    public function description(Request $request, Produit $produit): RedirectResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'description_inventaire' => ['nullable', 'string', 'max:5000'],
        ]);

        $produit->update(['description_inventaire' => $request->input('description_inventaire')]);

        return back()->with('succes', 'Description inventaire mise à jour.');
    }

    /**
     * Ajouter un ou plusieurs détails libres.
     */
    public function ajouterDetails(Request $request, Produit $produit): RedirectResponse
    {
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'details'             => ['required', 'array', 'min:1'],
            'details.*.titre'     => ['required', 'string', 'max:150'],
            'details.*.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $ordre = $produit->detailsLibres()->max('ordre') ?? 0;

        foreach ($request->input('details', []) as $d) {
            if (!empty($d['titre'])) {
                ProduitDetailLibre::create([
                    'produit_id'  => $produit->id,
                    'titre'       => $d['titre'],
                    'description' => $d['description'] ?? '',
                    'ordre'       => ++$ordre,
                ]);
            }
        }

        return back()->with('succes', 'Détail(s) libre(s) ajouté(s) avec succès.');
    }

    /**
     * Supprimer un détail libre.
     */
    public function supprimerDetail(ProduitDetailLibre $detail): RedirectResponse
    {
        abort_unless(
            Produit::where('id', $detail->produit_id)
                ->where('entreprise_id', Auth::user()->entreprise_id)
                ->exists(),
            404
        );

        $detail->delete();

        return back()->with('succes', 'Détail supprimé.');
    }

    public function calculerReference(Request $request): JsonResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $categorieId  = $request->input('categorie_id');
        $reference    = \App\Modules\Admin\Modeles\Produit::genererReference($entrepriseId, $categorieId);
        return response()->json(['reference' => $reference]);
    }
}
