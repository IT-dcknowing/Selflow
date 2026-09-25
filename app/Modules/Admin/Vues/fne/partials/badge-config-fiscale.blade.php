{{-- État de la configuration fiscale, à droite de la barre de filtres. --}}
<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
    @php
        /*
         * Le badge reclamait une configuration qui n'avait rien a configurer.
         *
         * En CAS B — non-assujetti (TEE, TCE, RME) —, la TVA et la TSE sont
         * grisees par la reglementation elle-meme, et le timbre est decide par
         * la DGI a la normalisation. Il ne reste **aucun reglage a poser** :
         * l'ecran affichait pourtant « Config. fiscale non definie » en orange
         * tant que personne n'avait cliqué « Enregistrer » sur un formulaire
         * ou tout etait deja decide.
         *
         * La categorie se deduit du regime d'imposition, qui est renseigne dans
         * les parametres. Elle est donc toujours connue ; ce qui peut manquer,
         * c'est un choix — et il n'y en a qu'en CAS A.
         */
        $categorieFiscale = \App\Modules\Admin\Modeles\TaxConfiguration::categorieDepuisRegime(
            $taxConfig?->regime ?? auth()->user()->entreprise?->regime_imposition
        );
        $unChoixResteAFaire = $categorieFiscale === 'CAS_A' && !$taxConfig;
    @endphp
    @if($unChoixResteAFaire)
        <span class="config-badge not-configured">
            <i class="fas fa-exclamation-circle"></i> Config. fiscale à définir — CAS A
        </span>
    @else
        <span class="config-badge configured">
            <i class="fas fa-check-circle"></i>
            Config. fiscale active
            @if($categorieFiscale === 'CAS_A') — CAS A @else — CAS B @endif
        </span>
    @endif
    <button class="btn btn-outline" onclick="ouvrirModalConfig()" style="white-space:nowrap;">
        <i class="fas fa-cog"></i> Configuration Fiscale
    </button>
</div>
