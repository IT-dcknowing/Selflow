{{--
    L'offre et ses limites, côté superadministrateur (15/10/2026).

    Choisir une offre pose ses contraintes par défaut ; le superadministrateur
    peut ensuite les relever sans changer d'offre.

    @param  ?Entreprise  $entreprise  absente à la création
--}}
@php
    use App\Modules\Admin\Regles\Abonnements;
    $entreprise = $entreprise ?? null;
    $planActuel = old('plan_abonnement', $entreprise?->plan_abonnement ?? Abonnements::BUSINESS);
    $offres = Abonnements::offres();
@endphp
<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
    <div class="form-group">
        <label class="form-label">Offre <span style="color:var(--danger)">*</span></label>
        <select name="plan_abonnement" id="plan_abonnement" class="form-control" required onchange="appliquerLOffre(this.value)">
            @if($planActuel && !Abonnements::existe($planActuel))
                <option value="{{ $planActuel }}" selected>{{ Abonnements::libelle($planActuel) }} (ancienne offre)</option>
            @endif
            @foreach($offres as $code => $offre)
                <option value="{{ $code }}" {{ $planActuel === $code ? 'selected' : '' }}
                        data-pdv="{{ $offre['points_de_vente'] }}" data-utilisateurs="{{ $offre['utilisateurs'] }}">
                    {{ $offre['nom'] }}{{ $offre['prix'] ? ' — ' . number_format($offre['prix'], 0, ',', ' ') . ' F/mois' : ($offre['prix'] === 0 ? ' — gratuit ' . Abonnements::DUREE_PASS_CADEAU_JOURS . ' jours' : ' — sur devis') }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Fin de l'offre / de l'essai</label>
        <input type="date" name="abonnement_expire_le" class="form-control"
               value="{{ old('abonnement_expire_le', $entreprise?->abonnement_expire_le?->format('Y-m-d')) }}">
        <small style="font-size:11px; color:var(--text-3);">Vide : sans échéance. Pass cadeau : {{ Abonnements::DUREE_PASS_CADEAU_JOURS }} jours si laissé vide.</small>
    </div>
    <div class="form-group">
        <label class="form-label">Points de vente max <span style="color:var(--danger)">*</span></label>
        <input type="number" name="quota_points_de_vente" id="quota_points_de_vente" class="form-control" required min="1"
               value="{{ old('quota_points_de_vente', $entreprise?->quota_points_de_vente ?? Abonnements::QUOTA_PAR_DEFAUT) }}">
        <small style="font-size:11px; color:var(--text-3);">{{ Abonnements::ILLIMITE }} = illimité.</small>
    </div>
    <div class="form-group">
        <label class="form-label">Utilisateurs max</label>
        <input type="number" name="quota_utilisateurs" id="quota_utilisateurs" class="form-control" min="1"
               value="{{ old('quota_utilisateurs', $entreprise ? $entreprise->quota_utilisateurs : Abonnements::QUOTA_PAR_DEFAUT) }}">
        <small style="font-size:11px; color:var(--text-3);">Vide : sans limite. Relevable sans changer d'offre.</small>
    </div>
</div>
<script>
function appliquerLOffre(code) {
    var option = document.querySelector('#plan_abonnement option[value="' + code + '"]');
    if (!option || !option.dataset.pdv) return;
    document.getElementById('quota_points_de_vente').value = option.dataset.pdv;
    document.getElementById('quota_utilisateurs').value = option.dataset.utilisateurs;
}
</script>
