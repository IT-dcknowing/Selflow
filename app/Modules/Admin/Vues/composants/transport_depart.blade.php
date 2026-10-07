{{--
    Le départ d'une livraison (chantier 15.3) : où, qui, avec quoi, à quelle
    heure. Tout est obligatoire — tranché par le propriétaire le 07/10/2026 —
    et le bon imprimé le porte.

    Paramètres : `livreurs` (le personnel de l'entreprise), `adresse` (celle du
    client, proposée par défaut).
--}}
<div class="card" style="padding:18px; margin-bottom:16px;">
    <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">
        <i class="fas fa-truck" style="color:var(--primary);"></i> Le transport
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;margin-bottom:0;">
            <label class="form-label">Adresse de livraison <span style="color:var(--danger)">*</span></label>
            <input type="text" name="adresse_livraison" class="form-control" required maxlength="255"
                   value="{{ old('adresse_livraison', $adresse ?? '') }}" placeholder="Quartier, rue, repère">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Qui livre <span style="color:var(--danger)">*</span></label>
            <select name="livreur_type" class="form-control" required
                    onchange="var p=this.value==='personnel';var f=this.form;f.querySelector('[data-livreur=personnel]').style.display=p?'':'none';f.querySelector('[data-livreur=prestataire]').style.display=p?'none':'';">
                <option value="personnel" {{ old('livreur_type', 'personnel') === 'personnel' ? 'selected' : '' }}>Un membre du personnel</option>
                <option value="prestataire" {{ old('livreur_type') === 'prestataire' ? 'selected' : '' }}>Un prestataire</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;{{ old('livreur_type') === 'prestataire' ? 'display:none;' : '' }}" data-livreur="personnel">
            <label class="form-label">Livreur <span style="color:var(--danger)">*</span></label>
            <select name="livreur_utilisateur_id" class="form-control">
                <option value="">— Choisir —</option>
                @foreach($livreurs as $l)
                    <option value="{{ $l->id }}" {{ (string) old('livreur_utilisateur_id') === (string) $l->id ? 'selected' : '' }}>{{ trim($l->prenom . ' ' . $l->nom) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;{{ old('livreur_type') === 'prestataire' ? '' : 'display:none;' }}" data-livreur="prestataire">
            <label class="form-label">Prestataire <span style="color:var(--danger)">*</span></label>
            <input type="text" name="livreur_nom" class="form-control" maxlength="150" value="{{ old('livreur_nom') }}" placeholder="Nom du transporteur">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Véhicule / immatriculation <span style="color:var(--danger)">*</span></label>
            <input type="text" name="vehicule" class="form-control" required maxlength="60" value="{{ old('vehicule') }}" placeholder="Ex : 1234 AB 01">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Heure de départ <span style="color:var(--danger)">*</span></label>
            <input type="datetime-local" name="heure_depart" class="form-control" required
                   value="{{ old('heure_depart', now()->format('Y-m-d\TH:i')) }}">
        </div>
    </div>
</div>
