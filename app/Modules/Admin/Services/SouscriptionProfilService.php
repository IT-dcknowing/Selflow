<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Referentiel\Article;
use App\Modules\Admin\Modeles\Referentiel\Compte;
use App\Modules\Admin\Modeles\Referentiel\Famille;
use App\Modules\Admin\Modeles\Referentiel\Profil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Souscription d'une entreprise à un profil d'activité.
 *
 * C'est le moment où le secteur cesse d'être une étiquette. Choisir « Boutique
 * de quartier » ne décidait de rien : l'utilisateur repartait avec un catalogue
 * vide et un plan comptable de dix-neuf comptes. Il reçoit désormais les
 * familles, les articles et les comptes de son métier, et les modules
 * correspondants s'ouvrent.
 *
 * Trois principes :
 *
 * - **Le référentiel propose, il n'impose pas.** Tout ce qui est copié devient
 *   la propriété de l'entreprise : elle renomme, complète, archive. Une
 *   nouvelle version du classeur ne réécrira jamais son catalogue.
 * - **Une activité mixte cumule sans doubler.** Une quincaillerie qui livre des
 *   chantiers souscrit aux deux profils ; les familles communes ne sont créées
 *   qu'une fois.
 * - **Rien n'est jamais écrasé.** Souscrire deux fois au même profil ne crée
 *   rien de nouveau et ne touche pas à ce que l'utilisateur a modifié.
 */
class SouscriptionProfilService
{
    /**
     * Faire souscrire une entreprise aux profils désignés par leur code.
     *
     * Les codes viennent d'un formulaire : ils sont confrontés au référentiel
     * avant tout, et un code inconnu interrompt l'opération. Rien ne se crée
     * sur la foi d'une valeur envoyée par le navigateur.
     *
     * `$famillesRetenues` limite la copie aux familles désignées, par leur
     * code. Un tableau vide vaut « toutes » : l'utilisateur qui ne décoche rien
     * reçoit son métier entier. C'est ce qui permet à une boutique de refuser
     * le rayon cosmétiques sans renoncer au profil.
     *
     * @param  array<int, string>  $codesProfils
     * @param  array<int, string>  $famillesRetenues
     * @return array{profils: int, familles: int, articles: int, comptes: int, modules: array<int, string>}
     */
    public static function souscrire(Entreprise $entreprise, array $codesProfils, array $famillesRetenues = []): array
    {
        $codes = array_values(array_unique(array_filter($codesProfils)));

        $profils = Profil::whereIn('code', $codes)->get();

        if ($profils->count() !== count($codes)) {
            $inconnus = array_diff($codes, $profils->pluck('code')->all());

            throw new \InvalidArgumentException(
                'Profil inconnu du référentiel : ' . implode(', ', $inconnus)
            );
        }

        $retenues = array_values(array_unique(array_filter($famillesRetenues)));

        return DB::transaction(function () use ($entreprise, $profils, $retenues) {
            // L'entreprise doit avoir son plan et ses journaux avant de recevoir
            // des articles : un article sans compte au plan s'imputerait nulle
            // part. Le trousseau ne fait rien s'il est déjà posé.
            TrousseauEntrepriseService::doter($entreprise);

            $bilan = ['profils' => 0, 'familles' => 0, 'articles' => 0, 'comptes' => 0, 'modules' => []];

            foreach ($profils as $profil) {
                if ($entreprise->profils()->where('profil_id', $profil->id)->exists()) {
                    continue;
                }

                $resultat = self::copierProfil($entreprise, $profil, $retenues);

                $entreprise->profils()->attach($profil->id, [
                    'familles_creees' => $resultat['familles'],
                    'articles_crees'  => $resultat['articles'],
                    'souscrit_le'     => now(),
                ]);

                $bilan['profils']++;
                $bilan['familles'] += $resultat['familles'];
                $bilan['articles'] += $resultat['articles'];
                $bilan['comptes']  += $resultat['comptes'];
            }

            $bilan['modules'] = self::ouvrirLesModules($entreprise);

            // Le client de passage et le fournisseur occasionnel.
            //
            // Une vente de comptoir n'a pas de client nommé, et son écriture
            // tombait sur le seul collectif `411000` : le grand livre ne
            // distinguait plus les ventes de comptoir des créances d'un client
            // identifié. Une fiche unique par entreprise suffit à les séparer.
            \App\Modules\Admin\Modeles\Client::divers($entreprise);
            \App\Modules\Admin\Modeles\Fournisseur::divers($entreprise);

            return $bilan;
        });
    }

    /**
     * Copier chez l'entreprise les familles, articles et comptes d'un profil.
     *
     * @return array{familles: int, articles: int, comptes: int}
     */
    private static function copierProfil(Entreprise $entreprise, Profil $profil, array $retenues = []): array
    {
        $bilan = ['familles' => 0, 'articles' => 0, 'comptes' => 0];
        $categories = [];

        $familles = Famille::with('typeArticle')
            ->where('profil_id', $profil->id)
            ->when($retenues !== [], fn ($q) => $q->whereIn('code', $retenues))
            ->orderBy('code')
            ->get();

        foreach ($familles as $famille) {
            [$categorie, $creee] = self::categoriePour($entreprise, $famille);
            $categories[$famille->id] = $categorie;
            $bilan['familles'] += $creee ? 1 : 0;

            // Les quatre comptes de la famille rejoignent le plan de
            // l'entreprise, nommés depuis le type d'article : « Ventes de
            // marchandises — Vivres et alimentation » et non le libellé brut du
            // numéro, qui dirait « Dans la Région » sur des vivres.
            foreach (['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'] as $champ) {
                if ($famille->$champ && self::inscrireAuPlan($entreprise, $famille->$champ, $famille->intituleCompte($champ))) {
                    $bilan['comptes']++;
                }
            }
        }

        // Un article dont la famille n'a pas été retenue n'a nulle part où
        // aller : le créer sans catégorie le rendrait invisible dans les listes.
        foreach (Article::with('typeArticle')->where('profil_id', $profil->id)->orderBy('code')->get() as $article) {
            if (!isset($categories[$article->famille_id])) {
                continue;
            }

            if (self::creerProduit($entreprise, $article, $categories[$article->famille_id])) {
                $bilan['articles']++;
            }
        }

        return $bilan;
    }

    /**
     * Catégorie de l'entreprise correspondant à une famille du référentiel.
     *
     * Une famille du même nom existant déjà — cas d'une activité mixte — est
     * réutilisée telle quelle. Le préfixe, lui, doit rester unique dans
     * l'entreprise : deux profils peuvent porter le code `DIV`, et le second
     * reçoit alors un suffixe.
     *
     * @return array{0: Categorie, 1: bool} la catégorie, et si elle vient d'être créée
     */
    private static function categoriePour(Entreprise $entreprise, Famille $famille): array
    {
        $existante = Categorie::where('entreprise_id', $entreprise->id)
            ->where('nom', $famille->nom)
            ->first();

        if ($existante) {
            return [$existante, false];
        }

        // Les quatre comptes de la famille suivent le rayon, et non chaque
        // article : c'est le rayon qui porte la regle, et un article cree a la
        // main apres la souscription en herite sans qu'on ait rien a redire.
        return [Categorie::create([
            'entreprise_id'    => $entreprise->id,
            'nom'              => $famille->nom,
            'prefixe'          => self::prefixeLibre($entreprise, $famille->code),
            'compte_vente'     => $famille->compte_vente,
            'compte_achat'     => $famille->compte_achat,
            'compte_stock'     => $famille->compte_stock,
            'compte_variation' => $famille->compte_variation,
        ]), true];
    }

    /**
     * Préfixe disponible, dérivé du code de la famille.
     */
    private static function prefixeLibre(Entreprise $entreprise, string $souhaite): string
    {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $souhaite) ?: 'FAM', 0, 8));
        $prefixe = $base;
        $suffixe = 2;

        while (Categorie::where('entreprise_id', $entreprise->id)->where('prefixe', $prefixe)->exists()) {
            $prefixe = Str::substr($base, 0, 8) . $suffixe;
            $suffixe++;
        }

        return $prefixe;
    }

    /**
     * Inscrire un compte au plan de l'entreprise.
     *
     * L'entreprise reçoit désormais le plan de l'acte uniforme **en entier** à
     * sa création : le compte d'une famille y figure donc déjà, sous son
     * intitulé générique — `311100` s'appelle « Marchandises A ».
     *
     * Le nom du métier prime, et il faut le poser même si la ligne existe :
     * « Vivres et alimentation » dit ce que le compte porte, « Marchandises A »
     * ne dit rien. On ne réécrit que ce que le référentiel a posé lui-même —
     * un libellé que l'entreprise a modifié à la main n'est jamais touché.
     *
     * @return bool vrai s'il vient d'être créé
     */
    private static function inscrireAuPlan(Entreprise $entreprise, string $numero, ?string $intitule): bool
    {
        $libelle = $intitule ?? Compte::nommer($numero) ?? "Compte {$numero}";

        $compte = PlanComptable::where('entreprise_id', $entreprise->id)
            ->where('numero', $numero)
            ->first();

        if ($compte) {
            if ($intitule && $compte->libelle === Compte::nommer($numero)) {
                $compte->update(['libelle' => $intitule]);
            }

            return false;
        }

        PlanComptable::create([
            'entreprise_id' => $entreprise->id,
            'numero'        => $numero,
            'libelle'       => $libelle,
        ]);

        return true;
    }

    /**
     * Créer l'article type dans le catalogue de l'entreprise.
     *
     * Les prix et le stock initial restent vides : le classeur les laisse
     * volontairement à saisir, ils varient selon la zone et la période.
     *
     * @return bool vrai s'il vient d'être créé
     */
    private static function creerProduit(Entreprise $entreprise, Article $article, ?Categorie $categorie): bool
    {
        $reference = $article->code;

        $existe = Produit::where('entreprise_id', $entreprise->id)
            ->where('reference', $reference)
            ->exists();

        if ($existe) {
            return false;
        }

        Produit::create([
            'entreprise_id' => $entreprise->id,
            'reference'     => $reference,
            'nom'           => $article->designation,
            'type'          => $article->typeArticle->typeProduit(),
            'categorie_id'  => $categorie?->id,
            'unite'         => $article->unite,
            'prix_achat'    => 0,
            'prix_vente'    => 0,
            // Rien ici : l'imputation se lit sur le rayon. La recopier sur
            // chaque article obligeait a rouvrir toutes les fiches pour
            // changer le compte d'un rayon, et le lien entre le rayon et son
            // imputation — la regle metier — disparaissait apres la copie.
            // `ImputationService` resout la chaine article -> rayon -> defaut.
        ]);

        return true;
    }

    /**
     * Ouvrir les modules des profils souscrits, dans la limite des droits.
     *
     * Le superadmin décide de ce que l'entreprise a le droit d'activer ; les
     * profils disent ce dont le métier a besoin. On ouvre l'intersection, et
     * on n'ôte jamais un module que l'utilisateur avait déjà.
     *
     * @return array<int, string>
     */
    private static function ouvrirLesModules(Entreprise $entreprise): array
    {
        $entreprise->refresh();

        $actifs = $entreprise->modules_actifs;
        if (is_string($actifs)) {
            $actifs = json_decode($actifs, true);
        }
        $actifs = is_array($actifs) ? $actifs : [];

        $demandes = Entreprise::MODULES_SOCLE;

        foreach ($entreprise->profils as $profil) {
            $demandes = array_merge($demandes, $profil->modulesOuverts());
        }

        $autorises = $entreprise->modulesAutorises();
        $ouverts = array_values(array_unique(array_intersect(
            array_merge($actifs, $demandes),
            $autorises
        )));

        $entreprise->update(['modules_actifs' => $ouverts]);

        return $ouverts;
    }

    /**
     * Le parcours déroulé dans la section « Domaine d'activité » de l'écran
     * de création du superadministrateur (15/10/2026) : domaine, métiers,
     * modules et rayons choisis sur place, appliqués à la création — comme les
     * étapes 1 à 4 du parcours de l'entreprise. Les prix et le stock (étape 5)
     * se règlent ensuite, sur les articles créés.
     *
     * @param  array{categorie_id?: int|string|null, profils?: array<int, string>, activite_autre?: ?string, modules?: array<int, string>, familles?: array<int, string>}  $parcours
     * @return int l'étape atteinte (0 : rien de choisi)
     */
    public static function appliquerLeParcoursDeCreation(Entreprise $entreprise, array $parcours): int
    {
        $categorie = filled($parcours['categorie_id'] ?? null)
            ? \App\Modules\Admin\Modeles\Referentiel\Categorie::find((int) $parcours['categorie_id'])
            : null;

        if (! $categorie) {
            return 0;
        }

        $entreprise->update([
            'secteur_activite'   => [$categorie->nom],
            'activite_autre'     => $parcours['activite_autre'] ?? null,
            'souscription_etape' => 1,
        ]);

        // Seuls les métiers du domaine choisi comptent.
        $profils = Profil::where('categorie_id', $categorie->id)
            ->whereIn('code', (array) ($parcours['profils'] ?? []))
            ->pluck('code')->all();

        if ($profils === []) {
            return 1;
        }

        $bilan = self::souscrire($entreprise, $profils, (array) ($parcours['familles'] ?? []));

        if (isset($parcours['modules'])) {
            $entreprise->update([
                'modules_actifs' => array_values(array_intersect(
                    $bilan['modules'],
                    array_merge((array) $parcours['modules'], Entreprise::MODULES_STRUCTURELS)
                )),
            ]);
        }

        VerrouConfigurationService::alignerLeSecteur($entreprise->refresh());
        $entreprise->update(['souscription_etape' => 4]);

        return 4;
    }
}
