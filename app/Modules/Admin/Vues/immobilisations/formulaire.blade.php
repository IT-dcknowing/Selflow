@extends('admin::gabarits.application')
@section('titre', 'Nouveau bien immobilisé')
@section('topbar_titre', 'Comptabilité · Nouveau bien immobilisé')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-building-columns"></i> Nouveau bien immobilisé</h1>
        <p>Le plan d'amortissement se calcule dès l'enregistrement : c'est lui que le comptable présente au contrôle.</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.immobilisations.enregistrer') }}">
    @csrf

    <div class="card" style="padding:22px; margin-bottom:20px;">
        <h2 style="font-size:15px; font-weight:700; margin-bottom:16px;">Le bien</h2>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div class="form-group">
                <label class="form-label">Code <span style="color:var(--danger);">*</span></label>
                <input type="text" name="code" class="form-control" value="{{ old('code') }}" required maxlength="50">
                <small style="color:var(--text-3); font-size:11px;">
                    Celui que porte l'étiquette collée dessus : c'est lui que l'inventaire physique cherche.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Désignation <span style="color:var(--danger);">*</span></label>
                <input type="text" name="libelle" class="form-control" value="{{ old('libelle') }}" required maxlength="200">
            </div>

            <div class="form-group">
                <label class="form-label">Site</label>
                <select name="point_de_vente_id" class="form-control">
                    <option value="">Choisir</option>
                    @foreach($pointsDeVente as $pdv)
                        <option value="{{ $pdv->id }}" @selected(old('point_de_vente_id') == $pdv->id)>{{ $pdv->nom }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Fournisseur</label>
                <select name="fournisseur_id" class="form-control">
                    <option value="">Choisir</option>
                    @foreach($fournisseurs as $f)
                        <option value="{{ $f->id }}" @selected(old('fournisseur_id') == $f->id)>{{ $f->nom }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Description</label>
            <textarea name="description" rows="3" class="form-control">{{ old('description') }}</textarea>
        </div>
    </div>

    {{-- Les trois comptes sont portés par la fiche et non déduits d'une table :
         un four et un camion s'amortissent tous deux sur 24x, mais un logiciel
         va sur 213x et sa dotation sur 681200. Deviner rendrait le bilan faux
         plutôt qu'imprécis. --}}
    <div class="card" style="padding:22px; margin-bottom:20px;">
        <h2 style="font-size:15px; font-weight:700; margin-bottom:6px;">Les comptes</h2>
        <p style="font-size:12.5px; color:var(--text-3); margin-bottom:16px;">
            Un four et un camion vont tous deux en 24x ; un logiciel va en 213x et sa dotation en 681200.
            Les numéros suivent le plan OHADA.
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
            <div class="form-group">
                <label class="form-label">Compte d'immobilisation (classe 2) <span style="color:var(--danger);">*</span></label>
                <input type="text" name="compte_immobilisation" class="form-control"
                       value="{{ old('compte_immobilisation') }}" required placeholder="245100" maxlength="20">
            </div>
            <div class="form-group">
                <label class="form-label">Compte d'amortissement (28x) <span style="color:var(--danger);">*</span></label>
                <input type="text" name="compte_amortissement" class="form-control"
                       value="{{ old('compte_amortissement') }}" required placeholder="284500" maxlength="20">
            </div>
            <div class="form-group">
                <label class="form-label">Compte de dotation (681x) <span style="color:var(--danger);">*</span></label>
                <input type="text" name="compte_dotation" class="form-control"
                       value="{{ old('compte_dotation', '681300') }}" required placeholder="681300" maxlength="20">
            </div>
        </div>
    </div>

    <div class="card" style="padding:22px; margin-bottom:20px;">
        <h2 style="font-size:15px; font-weight:700; margin-bottom:16px;">L'amortissement</h2>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
            <div class="form-group">
                <label class="form-label">Date d'acquisition <span style="color:var(--danger);">*</span></label>
                <input type="date" name="date_acquisition" class="form-control" value="{{ old('date_acquisition') }}" required>
            </div>

            <div class="form-group">
                <label class="form-label">Mise en service <span style="color:var(--danger);">*</span></label>
                <input type="date" name="date_mise_en_service" class="form-control" value="{{ old('date_mise_en_service') }}" required>
                <small style="color:var(--text-3); font-size:11px;">
                    C'est elle qui déclenche l'amortissement, non l'acquisition. Un matériel acheté en
                    novembre et installé en janvier ne s'amortit pas sur novembre et décembre.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Valeur d'acquisition <span style="color:var(--danger);">*</span></label>
                <input type="number" step="0.01" min="0" name="valeur_acquisition" class="form-control"
                       value="{{ old('valeur_acquisition') }}" required>
            </div>

            <div class="form-group">
                <label class="form-label">Valeur résiduelle</label>
                <input type="number" step="0.01" min="0" name="valeur_residuelle" class="form-control"
                       value="{{ old('valeur_residuelle', 0) }}">
                <small style="color:var(--text-3); font-size:11px;">
                    Ce que le bien vaudra au terme, et qui ne s'amortit pas. Nul le plus souvent ;
                    un véhicule revendu a pourtant une valeur.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Durée, en mois <span style="color:var(--danger);">*</span></label>
                <input type="number" min="0" max="1200" name="duree_mois" class="form-control"
                       value="{{ old('duree_mois', 60) }}" required>
                <small style="color:var(--text-3); font-size:11px;">
                    En mois, non en années : un plan de trente mois existe. Zéro pour un terrain, qui ne s'amortit pas.
                </small>
            </div>
        </div>

        {{-- Seul le linéaire est calculé : le dégressif suppose des coefficients
             fixés par un texte que le dépôt ne contient pas, et les inventer
             donnerait un plan faux que rien ne signalerait. --}}
        <div style="margin-top:16px; padding:12px 14px; background:var(--bg3); border-radius:8px; font-size:12.5px; color:var(--text-2);">
            <i class="fas fa-circle-info"></i>
            <strong>Amortissement linéaire</strong>, prorata temporis à partir de la mise en service,
            en jours sur une année commerciale de 360 jours. Le dégressif n'est pas calculé : ses
            coefficients relèvent d'un texte que l'application ne porte pas, et les supposer donnerait
            un plan faux sans que rien ne le signale.
        </div>
    </div>

    <div style="display:flex; gap:12px;">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Enregistrer et établir le plan
        </button>
        <a href="{{ route('admin.immobilisations.index') }}" class="btn">Annuler</a>
    </div>
</form>
@endsection
