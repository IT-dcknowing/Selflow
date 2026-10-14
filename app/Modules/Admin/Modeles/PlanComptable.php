<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

class PlanComptable extends Model
{
    protected $table = 'plan_comptable';

    protected $fillable = [
        'entreprise_id',
        'numero',
        'numero_original',
        'libelle',
        'archive_le',
        'source',
    ];

    public static function obtenirComptesPrioritaires($entrepriseId)
    {
        $hasComptaflow = self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->exists();
        if ($hasComptaflow) {
            return self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->orderBy('numero')->get();
        }
        return self::where(function($q) use ($entrepriseId) {
            $q->whereNull('entreprise_id')
              ->orWhere('entreprise_id', $entrepriseId);
        })->orderBy('numero')->get();
    }

    /**
     * Ce qui ne sert pas s'archive, il ne se supprime pas : un compte ou un
     * journal efface apres avoir servi laisserait des ecritures orphelines.
     */
    public function scopeActifs($query)
    {
        return $query->whereNull('archive_le');
    }

    public function scopeArchives($query)
    {
        return $query->whereNotNull('archive_le');
    }

    public function estArchive(): bool
    {
        return $this->archive_le !== null;
    }

    /**
     * Un compte que l'entreprise a créé elle-même — ni du plan SYSCOHADA
     * livré par défaut, ni reçu de Comptaflow. C'est celui-là, et lui seul,
     * que l'écran laisse modifier et supprimer (retest d'octobre 2026) : le
     * plan livré est la référence, Comptaflow garde la main sur les siens.
     */
    public function estCreeParLEntreprise(): bool
    {
        return $this->entreprise_id !== null
            && $this->source !== 'comptaflow'
            && !\App\Modules\Admin\Modeles\Referentiel\Compte::where('numero', $this->numero)->exists();
    }

    /**
     * Où le compte sert déjà. Un compte employé ne change pas de numéro et ne
     * se supprime pas : les écritures, les journaux et les fiches qui le
     * portent resteraient orphelins.
     *
     * @return array<int, string> les usages trouvés, dits en clair
     */
    public function usages(): array
    {
        $e = $this->entreprise_id;
        $n = $this->numero;
        $usages = [];

        if (EcritureComptable::where('entreprise_id', $e)->where(fn ($q) => $q->where('compte_debit', $n)->orWhere('compte_credit', $n))->exists()) {
            $usages[] = 'des écritures comptables';
        }
        if (CodeJournal::where('entreprise_id', $e)->where('compte', $n)->exists()) {
            $usages[] = 'un journal';
        }
        $champs = ['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'];
        if (Categorie::where('entreprise_id', $e)->where(fn ($q) => collect($champs)->each(fn ($c) => $q->orWhere($c, $n)))->exists()) {
            $usages[] = 'une catégorie d\'articles';
        }
        if (Produit::where('entreprise_id', $e)->where(fn ($q) => collect($champs)->each(fn ($c) => $q->orWhere($c, $n)))->exists()) {
            $usages[] = 'un article';
        }
        if (ConfigurationCompte::where('entreprise_id', $e)->where(fn ($q) => $q->where('compte_vente', $n)->orWhere('compte_achat', $n))->exists()) {
            $usages[] = 'la configuration des comptes';
        }
        if (Client::where('entreprise_id', $e)->where('compte_comptable', $n)->exists()
            || Fournisseur::where('entreprise_id', $e)->where('compte_comptable', $n)->exists()) {
            $usages[] = 'un tiers';
        }
        if (Immobilisation::where('entreprise_id', $e)->where(fn ($q) => $q->where('compte_immobilisation', $n)->orWhere('compte_amortissement', $n)->orWhere('compte_dotation', $n))->exists()) {
            $usages[] = 'une immobilisation';
        }

        return $usages;
    }
}
