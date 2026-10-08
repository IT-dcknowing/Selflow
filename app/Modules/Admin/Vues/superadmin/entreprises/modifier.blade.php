@extends('admin::gabarits.application')

@section('titre', 'Supervision - Modifier une entreprise')
@section('topbar_titre', 'Supervision — Modifier')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-gear"></i> Configurer {{ $entreprise->nom }}</h1>
        <p>Ajustez le contrat d'abonnement, le secteur et l'activation des modules</p>
    </div>
    <a href="{{ route('superadmin.entreprises') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<form method="POST" action="{{ route('superadmin.entreprises.modifier.enregistrer', $entreprise) }}">
    @csrf
    @method('PUT')
    <div style="display:grid; grid-template-columns: 1.6fr 1fr; gap:20px; align-items:start;">
        
        {{-- Informations et Contrat --}}
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-file-contract" style="color:var(--primary);"></i> Paramètres de facturation & d'abonnement
                </div>
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Nom de l'entreprise <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="nom" class="form-control" value="{{ old('nom', $entreprise->nom) }}" required>
                        @error('nom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
                    </div>

                    {{-- Informations Gérant --}}
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Nom du Gérant / Représentant</label>
                            <input type="text" name="gerant_nom" class="form-control" placeholder="Ex: Dupont" value="{{ old('gerant_nom', $entreprise->gerant_nom) }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom du Gérant</label>
                            <input type="text" name="gerant_prenom" class="form-control" placeholder="Ex: Jean" value="{{ old('gerant_prenom', $entreprise->gerant_prenom) }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fonction du Gérant</label>
                        <input type="text" name="gerant_fonction" class="form-control" placeholder="Ex: Directeur Général" value="{{ old('gerant_fonction', $entreprise->gerant_fonction) }}">
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Plan d'abonnement <span style="color:var(--danger)">*</span></label>
                            <select name="plan_abonnement" class="form-control" required>
                                <option value="Starter" {{ $entreprise->plan_abonnement === 'Starter' ? 'selected' : '' }}>Starter</option>
                                <option value="Pro" {{ $entreprise->plan_abonnement === 'Pro' ? 'selected' : '' }}>Pro</option>
                                <option value="Enterprise" {{ $entreprise->plan_abonnement === 'Enterprise' ? 'selected' : '' }}>Enterprise</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quota Points de Vente max <span style="color:var(--danger)">*</span></label>
                            <input type="number" name="quota_points_de_vente" class="form-control" value="{{ old('quota_points_de_vente', $entreprise->quota_points_de_vente) }}" required min="1">
                        </div>
                    </div>

                    <div style="padding: 12px; background: var(--bg3); border-radius: 8px; border: 1px solid var(--border); font-size: 12px; color: var(--text-2);">
                        <i class="fas fa-building" style="margin-right: 6px; color: var(--primary);"></i>
                        NCC : <strong>{{ $entreprise->ncc ?? '— Non renseigné —' }}</strong> &nbsp;·&nbsp;
                        RCCM : <strong>{{ $entreprise->rccm ?? '— Non renseigné —' }}</strong>
                    </div>
                </div>
            </div>
            
            <div class="card" style="padding: 24px; font-size: 13px; color: var(--text-2);">
                <div style="font-weight: 700; margin-bottom: 10px; color: var(--text);"><i class="fas fa-database"></i> Métriques de la structure :</div>
                <div>Nombre d'utilisateurs créés : <strong>{{ $entreprise->utilisateurs()->count() }}</strong></div>
                <div style="margin-top: 6px;">Nombre de points de vente créés : <strong>{{ $entreprise->pointsDeVente()->count() }} / {{ $entreprise->quota_points_de_vente }}</strong></div>
                <div style="margin-top: 6px;">Nombre d'articles au catalogue : <strong>{{ $entreprise->produits()->count() }}</strong></div>
            </div>
        </div>

        {{-- Configuration du Secteur & Modules --}}
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-gears" style="color:var(--primary);"></i> Secteur & Modules Actifs
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label">Secteurs d'activité <span style="color:var(--danger)">*</span></label>
                    <p style="font-size:11px;color:var(--text-3);margin-bottom:10px;">Sélectionnez tous les secteurs qui correspondent à l'activité de l'entreprise.</p>
                    @php
                        // Le référentiel fait foi : mêmes douze domaines qu'à
                        // l'inscription, aux paramètres et à la première étape
                        // de la souscription.
                        $secteursDispo = \App\Modules\Admin\Modeles\Referentiel\Categorie::domaines();
                        $secteurs = is_array($entreprise->secteur_activite) ? $entreprise->secteur_activite : (is_string($entreprise->secteur_activite) ? [$entreprise->secteur_activite] : []);
                        $secteurs = old('secteur_activite', $secteurs);
                    @endphp
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
                        @foreach($secteursDispo as $valeur)
                        <label style="display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--bg3);border-radius:8px;cursor:pointer;font-size:12.5px;border:1px solid var(--border);transition:all .15s;"
                            onmouseover="this.style.borderColor='var(--primary)';this.style.background='#EBF2FC'"
                            onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg3)'">
                            <input type="checkbox" name="secteur_activite[]" value="{{ $valeur }}"
                                {{ in_array($valeur, $secteurs) ? 'checked' : '' }}
                                style="width:15px;height:15px;cursor:pointer;accent-color:var(--primary);">
                            <span>{{ $valeur }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('secteur_activite') <small style="color:var(--danger);margin-top:6px;display:block;">{{ $message }}</small> @enderror
                </div>
                
                <details style="margin-top: 15px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg3); overflow: hidden;">
                    <summary style="font-weight: 700; color: var(--primary); cursor: pointer; padding: 14px 16px; background: var(--bg2); display: flex; align-items: center; justify-content: space-between; outline: none; list-style: none; user-select: none;">
                        <span><i class="fas fa-cubes"></i> Configuration des modules (cliquer pour déplier)</span>
                        <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
                    </summary>
                    <div style="padding: 16px; display:flex; flex-direction:column; gap:10px; border-top: 1px solid var(--border);">
                        @php
                            $modules = [
                                'principal'       => ['Tableaux de bord', 'Indicateurs d\'activité consolidés et personnels'],
                                'ventes'          => ['Ventes & POS', 'Caisse tactile et factures clients'],
                                'achats'          => ['Achats', 'Commandes et factures fournisseurs'],
                                'stock'           => ['Stock avancé', 'Inventaire multi-sites, rebuts et transferts'],
                                'production'      => ['Production / Usine', 'Fiches techniques et ordres de fabrication (Industriel)'],
                                'comptabilite'    => ['Comptabilité', 'SYSCOHADA, trésorerie, encaissements/décaissements et écritures automatiques'],
                                'points_de_vente' => ['Points de Vente', 'Gestion de l\'infrastructure multi-boutiques, personnels et habilitations'],
                                'produits'        => ['Produits / Catalogue', 'Catalogue des articles, unités, prix et codes-barres'],
                                'tiers'           => ['Tiers (Clients / Fournisseurs)', 'Répertoire complet des tiers'],
                                'rapports'        => ['Rapports & Analyses', 'Graphiques et statistiques d\'activité'],
                            ];
                            $actifs = $entreprise->modules_actifs ?? [];
                        @endphp
                        @foreach($modules as $key => [$title, $desc])
                        <label style="display:flex; align-items:flex-start; gap:10px; padding:10px; background:var(--bg2); border:1px solid var(--border); border-radius:8px; cursor:pointer;">
                            <input type="checkbox" name="modules_actifs[]" value="{{ $key }}" id="mod_{{ $key }}" {{ in_array($key, $actifs) ? 'checked' : '' }} style="margin-top: 3px;">
                            <div>
                                <div style="font-weight:600; font-size:12.5px; color:var(--text);">{{ $title }}</div>
                                <div style="font-size:11px; color:var(--text-3); margin-top:2px;">{{ $desc }}</div>
                            </div>
                        </label>
                        @endforeach

                        {{-- Services système intégrés d'office (B2B & FNE) --}}
                        <input type="hidden" name="modules_actifs[]" value="b2b">
                        <input type="hidden" name="modules_actifs[]" value="fne">
                        
                        <div style="margin-top:10px; padding:12px; background:rgba(0, 43, 92, 0.05); border:1px solid rgba(0, 43, 92, 0.15); border-radius:8px;">
                            <div style="font-size:11.5px; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:6px;">
                                <i class="fas fa-check-double"></i> Services système inclus
                            </div>
                            <p style="font-size:10.5px; color:var(--text-2); margin-top:4px; line-height:1.4;">
                                Les modules de communication inter-entreprises (B2B) et de facturation normalisée (FNE / DGI) sont automatiquement activés par défaut comme services d'infrastructure système.
                            </p>
                        </div>
                </details>
            </div>

            {{-- Attributions spécifiques SuperAdmin --}}
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders" style="color:var(--primary);"></i> Attributions comptables (SuperAdmin)
                </div>
                <p style="font-size:11.5px;color:var(--text-3);margin-bottom:14px;line-height:1.5;">
                    Par défaut, l'activation de la comptabilité n'ouvre que le socle de base (Solde &amp; journal, Codes Journaux, Créances &amp; règlements, Plan Comptable et Configuration). Cochez ici les fonctionnalités avancées que vous accordez spécifiquement à cette entreprise.
                </p>

                <input type="hidden" name="attributions_formulaire" value="1">
                <div style="display:flex;flex-direction:column;gap:10px;">
                    @php
                        $attributionsDispos = [
                            'encaissements'        => ['Encaissements', 'Gestion et suivi des encaissements de trésorerie'],
                            'decaissements'        => ['Décaissements', 'Gestion et suivi des décaissements de trésorerie'],
                            'comptabilite_globale' => ['Opération & écriture globale', 'Écran complet des écritures et opérations générales'],
                            'grand_livre'          => ['Grand livre', 'Consultation chronologique du grand livre des comptes'],
                            'lettrage'             => ['Lettrage', 'Rapprochement des créances et règlements'],
                            'balance'              => ['Balance de contrôle', 'Génération et consultation de la balance'],
                        ];
                    @endphp
                    @foreach($attributionsDispos as $attrCle => [$attrTitre, $attrDesc])
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;cursor:pointer;">
                        <input type="checkbox" name="attributions[]" value="{{ $attrCle }}" {{ $entreprise->aAttribution($attrCle) ? 'checked' : '' }} style="margin-top:3px;accent-color:var(--primary);">
                        <div>
                            <div style="font-weight:600;font-size:12.5px;color:var(--text);">{{ $attrTitre }}</div>
                            <div style="font-size:11px;color:var(--text-3);margin-top:2px;">{{ $attrDesc }}</div>
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%; padding:14px; font-weight:700; justify-content:center; gap:8px; border-radius:12px;">
                <i class="fas fa-save" style="font-size:16px;"></i> Enregistrer la configuration
            </button>
        </div>
    </div>
</form>

@endsection
